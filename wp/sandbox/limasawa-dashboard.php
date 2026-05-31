<?php
/**
 * Limasawa Dashboard API  —  REST namespace: limasawa/v1
 *
 * Owner-scoped endpoints behind the host/guest dashboard. JWT-guarded via
 * sb_jwt_current_user() (limasawa-auth.php). Reuses sb_acf/sb_img_url from
 * limasawa-listings.php where available.
 *
 * Routes:
 *   GET    /my-listings    (Bearer)            host's own listings + aggregate stats
 *   POST   /listing-action (Bearer)            {listing_id, action: pause|unpause|trash}
 *   POST   /save-listing   (Bearer)            {slug} or {slugs:[...]}  → add to saved
 *   DELETE /save-listing   (Bearer)            {slug}                   → remove from saved
 *   GET    /my-saved       (Bearer)            saved slugs + hydrated cards
 *
 * Per-listing analytics counters are returned as 0 until Phase 3 (tracking)
 * populates the `sb_stats_daily` meta.
 */

if (defined('ABSPATH')) {

    if (!function_exists('sb_dash_user')) {
        function sb_dash_user(WP_REST_Request $req) {
            return function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        }
    }

    /* Per-listing counters — read the daily-stats meta written in Phase 3. */
    if (!function_exists('sb_listing_counters')) {
        function sb_listing_counters($post_id) {
            $daily = get_post_meta($post_id, 'sb_stats_daily', true);
            $out = [
                'views' => 0, 'whatsapp_clicks' => 0, 'location_clicks' => 0,
                'airbnb_clicks' => 0, 'bookingcom_clicks' => 0,
                'facebook_clicks' => 0, 'instagram_clicks' => 0,
            ];
            if (is_array($daily)) {
                foreach ($daily as $day) {
                    if (!is_array($day)) continue;
                    $out['views'] += (int) ($day['views'] ?? 0);
                    foreach (['whatsapp', 'location', 'airbnb', 'bookingcom', 'facebook', 'instagram'] as $ch) {
                        $out[$ch . '_clicks'] += (int) ($day['clicks'][$ch] ?? 0);
                    }
                }
            }
            return $out;
        }
    }

    if (!function_exists('sb_listing_status_label')) {
        function sb_listing_status_label($post) {
            if ($post->post_status === 'publish') return 'live';
            if ($post->post_status === 'pending') return 'pending';
            if ($post->post_status === 'draft') {
                return get_post_meta($post->ID, 'sb_paused', true) ? 'paused' : 'draft';
            }
            if ($post->post_status === 'trash') return 'trashed';
            return $post->post_status;
        }
    }

    if (!function_exists('sb_my_listing_row')) {
        function sb_my_listing_row($post) {
            $id   = $post->ID;
            $pay  = get_post_meta($id, 'sb_payment', true);
            if (!is_array($pay)) $pay = [];
            $thumb = get_the_post_thumbnail_url($id, 'medium');
            if (!$thumb) {
                $gal = function_exists('sb_acf') ? sb_acf('accommodation_gallery', $id, []) : [];
                if (is_array($gal) && !empty($gal[0])) {
                    $thumb = function_exists('sb_img_url') ? sb_img_url($gal[0], 'medium') : ($gal[0]['url'] ?? '');
                }
            }
            $get = function ($k, $d = 0) use ($id) {
                return function_exists('sb_acf') ? sb_acf($k, $id, $d) : get_post_meta($id, $k, true);
            };
            return array_merge([
                'id'             => (int) $id,
                'slug'           => $post->post_name,
                'title'          => html_entity_decode(get_the_title($id), ENT_QUOTES),
                'status'         => sb_listing_status_label($post),
                'thumbnail'      => $thumb ?: '',
                'guests'         => (int) $get('accommodation_number_of_guests'),
                'beds'           => (int) $get('accommodation_beds'),
                'baths'          => (int) $get('accommodation_bathrooms'),
                'daily_rate'     => (float) $get('accommodation_daily_rent'),
                'monthly_rate'   => (float) $get('accommodation_monthly_rent'),
                'tier'           => get_post_meta($id, 'listing_tier', true) ?: 'free',
                'payment_status' => $pay['status'] ?? 'not_required',
                'billing_period' => $pay['billingPeriod'] ?? 'monthly',
                'payment_amount' => (float) ($pay['amount'] ?? 0),
                'payment_method' => $pay['method'] ?? '',
                'paid_at'        => $pay['paidAt'] ?? '',
                'expires_at'     => $pay['expiresAt'] ?? '',
            ], sb_listing_counters($id));
        }
    }

    /* -------------------------------------------------------------------------
     * Routes
     * ---------------------------------------------------------------------- */
    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/my-listings', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_my_listings',
        ]);
        register_rest_route($ns, '/listing-action', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_listing_action',
        ]);
        register_rest_route($ns, '/save-listing', [
            ['methods' => 'POST',   'permission_callback' => '__return_true', 'callback' => 'sb_save_listing'],
            ['methods' => 'DELETE', 'permission_callback' => '__return_true', 'callback' => 'sb_unsave_listing'],
        ]);
        register_rest_route($ns, '/my-saved', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_my_saved',
        ]);
    });

    if (!function_exists('sb_my_listings')) {
        function sb_my_listings(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);

            $q = new WP_Query([
                'post_type'      => 'accommodation',
                'post_status'    => ['publish', 'pending', 'draft'],
                'author'         => (int) $user->ID,
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            $listings = [];
            $stats = ['total' => 0, 'live' => 0, 'pending' => 0, 'draft' => 0, 'views_total' => 0, 'clicks_total' => 0];
            foreach ($q->posts as $post) {
                $row = sb_my_listing_row($post);
                $listings[] = $row;
                $stats['total']++;
                if ($row['status'] === 'live') $stats['live']++;
                elseif ($row['status'] === 'pending') $stats['pending']++;
                else $stats['draft']++;
                $stats['views_total']  += (int) $row['views'];
                $stats['clicks_total'] += (int) ($row['whatsapp_clicks'] + $row['location_clicks'] + $row['airbnb_clicks'] + $row['bookingcom_clicks'] + $row['facebook_clicks'] + $row['instagram_clicks']);
            }
            return new WP_REST_Response(['success' => true, 'listings' => $listings, 'stats' => $stats], 200);
        }
    }

    if (!function_exists('sb_listing_action')) {
        function sb_listing_action(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $id     = (int) $req->get_param('listing_id');
            $action = sanitize_key((string) $req->get_param('action'));
            $post   = $id ? get_post($id) : null;
            if (!$post || $post->post_type !== 'accommodation') {
                return new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404);
            }
            if ((int) $post->post_author !== (int) $user->ID) {
                return new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403);
            }
            switch ($action) {
                case 'pause':
                    wp_update_post(['ID' => $id, 'post_status' => 'draft']);
                    update_post_meta($id, 'sb_paused', 1);
                    break;
                case 'unpause':
                    delete_post_meta($id, 'sb_paused');
                    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
                    break;
                case 'trash':
                    wp_trash_post($id);
                    break;
                default:
                    return new WP_REST_Response(['success' => false, 'error' => 'BAD_ACTION'], 400);
            }
            $fresh = get_post($id);
            return new WP_REST_Response([
                'success' => true,
                'status'  => $fresh ? sb_listing_status_label($fresh) : 'trashed',
            ], 200);
        }
    }

    /* -------------------------------------------------------------------------
     * Saved listings — sb_saved user-meta (array of slugs)
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_saved_get')) {
        function sb_saved_get($uid) {
            $v = get_user_meta($uid, 'sb_saved', true);
            return is_array($v) ? array_values(array_unique(array_filter($v))) : [];
        }
    }
    if (!function_exists('sb_saved_set')) {
        function sb_saved_set($uid, $slugs) {
            $clean = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
            update_user_meta($uid, 'sb_saved', $clean);
            return $clean;
        }
    }

    if (!function_exists('sb_save_listing')) {
        function sb_save_listing(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['error' => 'UNAUTHORIZED'], 401);
            $saved = sb_saved_get($user->ID);
            $slugs = $req->get_param('slugs');
            $slug  = sanitize_title((string) $req->get_param('slug'));
            if (is_array($slugs)) {
                foreach ($slugs as $s) { $s = sanitize_title($s); if ($s !== '') $saved[] = $s; }
            } elseif ($slug !== '') {
                $saved[] = $slug;
            } else {
                return new WP_REST_Response(['error' => 'NO_SLUG'], 400);
            }
            $clean = sb_saved_set($user->ID, $saved);
            return new WP_REST_Response(['status' => 'ok', 'slugs' => $clean], 200);
        }
    }

    if (!function_exists('sb_unsave_listing')) {
        function sb_unsave_listing(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['error' => 'UNAUTHORIZED'], 401);
            $slug = sanitize_title((string) $req->get_param('slug'));
            if ($slug === '') return new WP_REST_Response(['error' => 'NO_SLUG'], 400);
            $saved = array_values(array_filter(sb_saved_get($user->ID), function ($s) use ($slug) { return $s !== $slug; }));
            sb_saved_set($user->ID, $saved);
            return new WP_REST_Response(['status' => 'ok', 'slugs' => $saved], 200);
        }
    }

    if (!function_exists('sb_my_saved')) {
        function sb_my_saved(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['error' => 'UNAUTHORIZED'], 401);
            $slugs = sb_saved_get($user->ID);
            $cards = [];
            foreach ($slugs as $slug) {
                $posts = get_posts(['name' => $slug, 'post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 1]);
                if (empty($posts)) continue;
                $p = $posts[0];
                $get = function ($k, $d = 0) use ($p) {
                    return function_exists('sb_acf') ? sb_acf($k, $p->ID, $d) : get_post_meta($p->ID, $k, true);
                };
                $cards[] = [
                    'slug'    => $slug,
                    'title'   => html_entity_decode(get_the_title($p->ID), ENT_QUOTES),
                    'thumb'   => get_the_post_thumbnail_url($p->ID, 'medium') ?: '',
                    'price'   => (float) $get('accommodation_daily_rent'),
                    'monthly' => (float) $get('accommodation_monthly_rent'),
                ];
            }
            return new WP_REST_Response(['slugs' => $slugs, 'listings' => $cards], 200);
        }
    }
}
