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

    /* =========================================================================
     * Analytics — tracking + host-stats (Phase 3)
     * ====================================================================== */
    if (!function_exists('sb_track_channels')) {
        function sb_track_channels() {
            return ['whatsapp', 'phone', 'location', 'airbnb', 'bookingcom', 'website', 'facebook', 'instagram', 'booking'];
        }
    }
    if (!function_exists('sb_track_source_bucket')) {
        function sb_track_source_bucket($ref) {
            $ref = strtolower((string) $ref);
            if ($ref === '') return 'direct';
            if (strpos($ref, 'limasawabooking.com') !== false) return 'direct';
            if (strpos($ref, 'google') !== false) return 'google';
            if (strpos($ref, 'facebook') !== false || strpos($ref, 'fb.') !== false) return 'facebook';
            if (strpos($ref, 'instagram') !== false) return 'instagram';
            if (strpos($ref, 'bing') !== false || strpos($ref, 'duckduckgo') !== false || strpos($ref, 'yahoo') !== false) return 'search';
            return 'other';
        }
    }
    if (!function_exists('sb_track_is_bot')) {
        function sb_track_is_bot() {
            $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($ua === '') return true;
            foreach (['bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit', 'headless'] as $b) {
                if (strpos($ua, $b) !== false) return true;
            }
            return false;
        }
    }
    if (!function_exists('sb_track_post_by_slug')) {
        function sb_track_post_by_slug($slug) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '') return 0;
            $posts = get_posts(['name' => $slug, 'post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']);
            return empty($posts) ? 0 : (int) $posts[0];
        }
    }
    if (!function_exists('sb_track_apply')) {
        function sb_track_apply($post_id, $kind, $channel, $source) {
            $daily = get_post_meta($post_id, 'sb_stats_daily', true);
            if (!is_array($daily)) $daily = [];
            $today = current_time('Y-m-d');
            if (!isset($daily[$today]) || !is_array($daily[$today])) {
                $daily[$today] = ['views' => 0, 'clicks' => [], 'sources' => []];
            }
            if ($kind === 'view') {
                $daily[$today]['views'] = (int) ($daily[$today]['views'] ?? 0) + 1;
                if ($source) {
                    $daily[$today]['sources'][$source] = (int) ($daily[$today]['sources'][$source] ?? 0) + 1;
                }
            } else {
                $daily[$today]['clicks'][$channel] = (int) ($daily[$today]['clicks'][$channel] ?? 0) + 1;
            }
            if (count($daily) > 400) {
                ksort($daily);
                $daily = array_slice($daily, -400, null, true);
            }
            update_post_meta($post_id, 'sb_stats_daily', $daily);
        }
    }

    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/track/view', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_track_view']);
        register_rest_route($ns, '/track/click', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_track_click']);
        register_rest_route($ns, '/host-stats', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_host_stats']);
        register_rest_route($ns, '/listing-stats', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_listing_stats']);
    });

    if (!function_exists('sb_track_view')) {
        function sb_track_view(WP_REST_Request $req) {
            if (sb_track_is_bot()) return new WP_REST_Response(['ok' => true, 'skipped' => 'bot'], 200);
            $pid = sb_track_post_by_slug($req->get_param('slug'));
            if (!$pid) return new WP_REST_Response(['ok' => true, 'skipped' => 'no_listing'], 200);
            $ip  = $_SERVER['REMOTE_ADDR'] ?? 'x';
            $key = 'sb_v_' . md5($ip . '|' . $pid);
            if (get_transient($key)) return new WP_REST_Response(['ok' => true, 'skipped' => 'dedup'], 200);
            set_transient($key, 1, 30 * MINUTE_IN_SECONDS);
            $utm = (string) $req->get_param('utmSource');
            $ref = (string) $req->get_param('referrer');
            $src = sb_track_source_bucket($utm !== '' ? $utm : ($ref !== '' ? $ref : ($_SERVER['HTTP_REFERER'] ?? '')));
            sb_track_apply($pid, 'view', '', $src);
            return new WP_REST_Response(['ok' => true], 200);
        }
    }
    if (!function_exists('sb_track_click')) {
        function sb_track_click(WP_REST_Request $req) {
            if (sb_track_is_bot()) return new WP_REST_Response(['ok' => true, 'skipped' => 'bot'], 200);
            $pid = sb_track_post_by_slug($req->get_param('slug'));
            if (!$pid) return new WP_REST_Response(['ok' => true, 'skipped' => 'no_listing'], 200);
            $type = sanitize_key((string) $req->get_param('type'));
            if (!in_array($type, sb_track_channels(), true)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'BAD_TYPE'], 400);
            }
            sb_track_apply($pid, 'click', $type, '');
            return new WP_REST_Response(['ok' => true], 200);
        }
    }

    if (!function_exists('sb_stats_window')) {
        function sb_stats_window($post_id, $start_ts, $days) {
            $daily = get_post_meta($post_id, 'sb_stats_daily', true);
            if (!is_array($daily)) $daily = [];
            $acc = ['views' => 0, 'clicks' => 0, 'bookings' => 0, 'map' => 0,
                    'breakdown' => array_fill_keys(sb_track_channels(), 0), 'sources' => [], 'series' => []];
            $booking_ch = ['airbnb', 'bookingcom', 'booking', 'website'];
            for ($i = 0; $i < $days; $i++) {
                $d   = date('Y-m-d', $start_ts + $i * DAY_IN_SECONDS);
                $row = isset($daily[$d]) && is_array($daily[$d]) ? $daily[$d] : ['views' => 0, 'clicks' => [], 'sources' => []];
                $v   = (int) ($row['views'] ?? 0);
                $clicks = is_array($row['clicks'] ?? null) ? $row['clicks'] : [];
                $cTotal = 0; $book = 0; $map = 0;
                foreach (sb_track_channels() as $ch) {
                    $cn = (int) ($clicks[$ch] ?? 0);
                    $cTotal += $cn;
                    $acc['breakdown'][$ch] += $cn;
                    if (in_array($ch, $booking_ch, true)) $book += $cn;
                    if ($ch === 'location') $map += $cn;
                }
                $acc['views'] += $v; $acc['clicks'] += $cTotal; $acc['bookings'] += $book; $acc['map'] += $map;
                if (!empty($row['sources']) && is_array($row['sources'])) {
                    foreach ($row['sources'] as $sk => $sv) {
                        $acc['sources'][$sk] = (int) ($acc['sources'][$sk] ?? 0) + (int) $sv;
                    }
                }
                $acc['series'][] = ['date' => $d, 'views' => $v, 'clicks' => $cTotal, 'bookings' => $book, 'map' => $map];
            }
            return $acc;
        }
    }

    if (!function_exists('sb_host_stats')) {
        function sb_host_stats(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $period = (int) $req->get_param('period_days');
            if ($period <= 0) $period = 7;
            $period = min(365, $period);

            $ids = get_posts(['post_type' => 'accommodation', 'post_status' => ['publish', 'pending', 'draft'], 'author' => (int) $user->ID, 'posts_per_page' => 200, 'fields' => 'ids']);
            $now        = current_time('timestamp');
            $cur_start  = strtotime('today', $now) - ($period - 1) * DAY_IN_SECONDS;
            $prev_start = $cur_start - $period * DAY_IN_SECONDS;

            $current   = ['views' => 0, 'clicks' => 0, 'bookings' => 0, 'map' => 0];
            $previous  = ['views' => 0, 'clicks' => 0, 'bookings' => 0, 'map' => 0];
            $breakdown = array_fill_keys(sb_track_channels(), 0);
            $sources   = [];
            $series    = [];
            $top       = [];
            $ratings   = [];
            $order     = ['free' => 0, 'pro' => 1, 'featured' => 2];
            $maxTier   = 'free';

            foreach ($ids as $pid) {
                $c = sb_stats_window($pid, $cur_start, $period);
                $p = sb_stats_window($pid, $prev_start, $period);
                foreach (['views', 'clicks', 'bookings', 'map'] as $k) { $current[$k] += $c[$k]; $previous[$k] += $p[$k]; }
                foreach ($breakdown as $ch => $_) { $breakdown[$ch] += $c['breakdown'][$ch]; }
                foreach ($c['sources'] as $sk => $sv) { $sources[$sk] = (int) ($sources[$sk] ?? 0) + $sv; }
                foreach ($c['series'] as $i => $day) {
                    if (!isset($series[$i])) $series[$i] = ['date' => $day['date'], 'views' => 0, 'clicks' => 0, 'bookings' => 0, 'map' => 0];
                    $series[$i]['views'] += $day['views']; $series[$i]['clicks'] += $day['clicks'];
                    $series[$i]['bookings'] += $day['bookings']; $series[$i]['map'] += $day['map'];
                }
                $avg = (float) get_post_meta($pid, 'sb_rating_avg', true);
                if ($avg > 0) $ratings[] = $avg;
                $t = get_post_meta($pid, 'listing_tier', true) ?: 'free';
                if (($order[$t] ?? 0) > ($order[$maxTier] ?? 0)) $maxTier = $t;
                $top[] = [
                    'slug'      => get_post_field('post_name', $pid),
                    'title'     => html_entity_decode(get_the_title($pid), ENT_QUOTES),
                    'thumb'     => get_the_post_thumbnail_url($pid, 'thumbnail') ?: '',
                    'views'     => $c['views'],
                    'clicks'    => $c['clicks'],
                    'sparkline' => array_map(function ($d) { return $d['views']; }, $c['series']),
                ];
            }
            $rating = $ratings ? round(array_sum($ratings) / count($ratings), 2) : 0;
            $current['rating'] = $rating; $previous['rating'] = $rating;
            usort($top, function ($a, $b) { return $b['views'] <=> $a['views']; });
            $top = array_slice($top, 0, 5);

            return new WP_REST_Response([
                'success'      => true,
                'tier'         => $maxTier,
                'current'      => $current,
                'previous'     => $previous,
                'series'       => array_values($series),
                'breakdown'    => $breakdown,
                'sources'      => $sources,
                'top_listings' => $top,
            ], 200);
        }
    }

    if (!function_exists('sb_listing_stats')) {
        function sb_listing_stats(WP_REST_Request $req) {
            $user = sb_dash_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $slug  = sanitize_title((string) $req->get_param('slug'));
            $posts = $slug ? get_posts(['name' => $slug, 'post_type' => 'accommodation', 'post_status' => ['publish', 'pending', 'draft'], 'posts_per_page' => 1, 'fields' => 'ids']) : [];
            $pid   = empty($posts) ? 0 : (int) $posts[0];
            if (!$pid) return new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404);
            $post = get_post($pid);
            if (!$post || (int) $post->post_author !== (int) $user->ID) return new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403);
            return new WP_REST_Response(array_merge(['success' => true, 'tier' => get_post_meta($pid, 'listing_tier', true) ?: 'free'], sb_listing_counters($pid)), 200);
        }
    }
}
