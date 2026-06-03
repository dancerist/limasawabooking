<?php
/**
 * Limasawa Rentals API — REST namespace: limasawa/v1
 *
 * Public, read-only vertical for vehicle rentals (scooters, motorbikes, etc.).
 * Admin-listed for now (no host submission/dashboard yet). Data model is the
 * native ACF `rental` CPT + `rental-type` taxonomy + the shared `location`
 * taxonomy + the `group_lima_rental` field group (daily/hourly/deposit/
 * inclusions/provider/gallery/map/available).
 *
 * Reuses sb_acf()/sb_img_url() from limasawa-listings.php (global helpers).
 *
 * Routes (limasawa/v1, public):
 *   GET /rentals            cards + ?type, ?location, ?page, ?per_page
 *   GET /rental/{slug}      single (flat shape the frontend renders)
 *   GET /rental-types       facets [{slug,name,count}]
 *   GET /rental-pins        bare array [{id,slug,title,price,lat,lng}] for the map
 */
if (!defined('ABSPATH')) exit;

// Share the hierarchical `location` taxonomy (barangays) with rentals so admins
// can place a rental in a barangay just like an accommodation.
add_action('init', function () {
    if (taxonomy_exists('location')) register_taxonomy_for_object_type('location', 'rental');
}, 20);

if (!function_exists('sb_rental_format')) {
    function sb_rental_format($post, $full = false) {
        $id = $post->ID;
        $types = wp_get_post_terms($id, 'rental-type');
        $type  = (!is_wp_error($types) && $types) ? $types[0] : null;
        $locs  = wp_get_post_terms($id, 'location');
        $locNames = !is_wp_error($locs) ? wp_list_pluck($locs, 'name') : [];

        $map = sb_acf('rental_location', $id, null);
        $lat = is_array($map) && isset($map['lat']) ? (float) $map['lat'] : null;
        $lng = is_array($map) && isset($map['lng']) ? (float) $map['lng'] : null;

        $gallery = []; $galleryFull = [];
        foreach ((array) sb_acf('rental_gallery', $id, []) as $im) {
            $u = sb_img_url($im, 'large');
            if ($u !== '') { $gallery[] = $u; $galleryFull[] = ['src' => $u, 'alt' => get_the_title($id)]; }
        }
        $thumb = get_the_post_thumbnail_url($id, 'large') ?: ($gallery[0] ?? '');

        $out = [
            'id'        => (int) $id,
            'slug'      => $post->post_name,
            'title'     => html_entity_decode(get_the_title($id), ENT_QUOTES),
            'excerpt'   => wp_strip_all_tags(get_the_excerpt($id)),
            'type'      => $type ? $type->name : '',
            'typeSlug'  => $type ? $type->slug : '',
            'daily'     => (float) sb_acf('rental_daily_rate', $id, 0),
            'hourly'    => (float) sb_acf('rental_hourly_rate', $id, 0),
            'deposit'   => (float) sb_acf('rental_deposit', $id, 0),
            'available' => (bool) sb_acf('rental_available', $id, true),
            'tier'      => get_post_meta($id, 'listing_tier', true) ?: 'free',
            'providerName'    => (string) sb_acf('rental_provider_name', $id, ''),
            'providerContact' => (string) sb_acf('rental_provider_contact', $id, ''),
            'thumb'     => $thumb,
            'gallery'   => $gallery,
            'area'      => $locNames[0] ?? '',
            'lat'       => $lat,
            'lng'       => $lng,
        ];
        if ($full) {
            $out['content']     = apply_filters('the_content', $post->post_content);
            $out['inclusions']  = (string) sb_acf('rental_inclusions', $id, '');
            $out['galleryFull'] = $galleryFull;
            $out['areaFull']    = implode(', ', $locNames);
        }
        return $out;
    }
}

add_action('rest_api_init', function () {
    $ns = 'limasawa/v1';
    register_rest_route($ns, '/rentals', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_rentals_index',
    ]);
    register_rest_route($ns, '/rental/(?P<slug>[a-z0-9\-]+)', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_rental_single',
    ]);
    register_rest_route($ns, '/rental-types', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_rental_types',
    ]);
    register_rest_route($ns, '/rental-pins', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_rental_pins',
    ]);
});

if (!function_exists('sb_rentals_index')) {
    function sb_rentals_index(WP_REST_Request $req) {
        $page = max(1, (int) $req->get_param('page'));
        $per  = min(48, max(1, (int) ($req->get_param('per_page') ?: 24)));
        $args = [
            'post_type' => 'rental', 'post_status' => 'publish',
            'posts_per_page' => $per, 'paged' => $page,
            'orderby' => 'date', 'order' => 'DESC',
        ];
        $tax = [];
        if ($t = $req->get_param('type'))     $tax[] = ['taxonomy' => 'rental-type', 'field' => 'slug', 'terms' => sanitize_title($t)];
        if ($l = $req->get_param('location')) $tax[] = ['taxonomy' => 'location', 'field' => 'slug', 'terms' => sanitize_title($l)];
        if ($tax) { $tax['relation'] = 'AND'; $args['tax_query'] = $tax; }
        $q = new WP_Query($args);
        $rows = array_map(function ($p) { return sb_rental_format($p, false); }, $q->posts);
        // Featured first, then Pro, then Free (PHP 8 usort is stable → keeps date order within a tier).
        $rank = ['featured' => 2, 'pro' => 1, 'free' => 0];
        usort($rows, function ($a, $b) use ($rank) { return ($rank[$b['tier']] ?? 0) <=> ($rank[$a['tier']] ?? 0); });
        return new WP_REST_Response([
            'rentals' => $rows,
            'total'   => (int) $q->found_posts,
            'pages'   => (int) $q->max_num_pages,
            'page'    => $page,
        ], 200);
    }
}

if (!function_exists('sb_rental_single')) {
    function sb_rental_single(WP_REST_Request $req) {
        $slug = sanitize_title((string) $req->get_param('slug'));
        $post = get_page_by_path($slug, OBJECT, 'rental');
        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(['error' => 'NOT_FOUND'], 404);
        }
        return new WP_REST_Response(sb_rental_format($post, true), 200);
    }
}

if (!function_exists('sb_rental_types')) {
    function sb_rental_types(WP_REST_Request $req) {
        $terms = get_terms(['taxonomy' => 'rental-type', 'hide_empty' => true]);
        $out = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) $out[] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
        }
        return new WP_REST_Response($out, 200);
    }
}

if (!function_exists('sb_rental_pins')) {
    function sb_rental_pins(WP_REST_Request $req) {
        $q = new WP_Query([
            'post_type' => 'rental', 'post_status' => 'publish',
            'posts_per_page' => -1, 'no_found_rows' => true,
        ]);
        $pins = [];
        foreach ($q->posts as $post) {
            $map = sb_acf('rental_location', $post->ID, null);
            if (!is_array($map) || !isset($map['lat'], $map['lng'])) continue;
            $pins[] = [
                'id'    => (int) $post->ID,
                'slug'  => $post->post_name,
                'title' => html_entity_decode(get_the_title($post->ID), ENT_QUOTES),
                'price' => (float) sb_acf('rental_daily_rate', $post->ID, 0),
                'lat'   => (float) $map['lat'],
                'lng'   => (float) $map['lng'],
            ];
        }
        return new WP_REST_Response($pins, 200);
    }
}

/* =========================================================================
 * Host self-listing (JWT). Hosts submit rentals → pending → admin publishes
 * in wp-admin. Free to list; `listing_tier` carried for future paid tiers.
 * ====================================================================== */

// Shared form→ACF writer, used by submit + update.
if (!function_exists('sb_apply_rental_fields')) {
    function sb_apply_rental_fields($id, array $d) {
        if (isset($d['dailyRate']))  update_field('rental_daily_rate', (float) $d['dailyRate'], $id);
        if (isset($d['hourlyRate'])) update_field('rental_hourly_rate', (float) $d['hourlyRate'], $id);
        if (isset($d['deposit']))    update_field('rental_deposit', (float) $d['deposit'], $id);
        if (isset($d['inclusions'])) update_field('rental_inclusions', sanitize_textarea_field($d['inclusions']), $id);
        if (isset($d['providerName']))    update_field('rental_provider_name', sanitize_text_field($d['providerName']), $id);
        if (isset($d['providerContact'])) update_field('rental_provider_contact', sanitize_text_field($d['providerContact']), $id);
        if (isset($d['available']))  update_field('rental_available', $d['available'] ? 1 : 0, $id);
        if (!empty($d['mapLocation']) && isset($d['mapLocation']['lat'], $d['mapLocation']['lng'])) {
            update_field('rental_location', [
                'lat' => (float) $d['mapLocation']['lat'], 'lng' => (float) $d['mapLocation']['lng'],
                'address' => sanitize_text_field($d['mapLocation']['address'] ?? ''),
            ], $id);
        }
        if (!empty($d['type'])) {
            $t = get_term_by('slug', sanitize_title($d['type']), 'rental-type') ?: get_term_by('name', $d['type'], 'rental-type');
            if ($t) wp_set_object_terms($id, [(int) $t->term_id], 'rental-type');
        }
        if (!empty($d['barangayId'])) wp_set_object_terms($id, [(int) $d['barangayId']], 'location');
        if (isset($d['galleryIds']) && is_array($d['galleryIds'])) {
            $ids = array_values(array_filter(array_map('intval', $d['galleryIds'])));
            if ($ids) { update_field('rental_gallery', $ids, $id); set_post_thumbnail($id, $ids[0]); }
        }
    }
}

if (!function_exists('sb_rental_owner_guard')) {
    function sb_rental_owner_guard(WP_REST_Request $req) {
        $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        if (!$user) return ['err' => new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401)];
        $id = (int) $req['id'];
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'rental') return ['err' => new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404)];
        $isAdmin = (bool) array_intersect(['administrator', 'editor'], (array) $user->roles);
        if ((int) $post->post_author !== (int) $user->ID && !$isAdmin) {
            return ['err' => new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403)];
        }
        return ['user' => $user, 'post' => $post, 'id' => $id];
    }
}

add_action('rest_api_init', function () {
    $ns = 'limasawa/v1';
    register_rest_route($ns, '/submit-rental',  ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_submit_rental']);
    register_rest_route($ns, '/my-rentals',     ['methods' => 'GET',  'permission_callback' => '__return_true', 'callback' => 'sb_my_rentals']);
    register_rest_route($ns, '/rental-edit/(?P<id>\d+)',   ['methods' => 'GET',  'permission_callback' => '__return_true', 'callback' => 'sb_rental_edit_get']);
    register_rest_route($ns, '/update-rental/(?P<id>\d+)', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_update_rental']);
    register_rest_route($ns, '/rental-action',  ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_rental_action']);
});

if (!function_exists('sb_submit_rental')) {
    function sb_submit_rental(WP_REST_Request $req) {
        $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        if (!$user) return new WP_REST_Response(['success' => false, 'message' => 'Please sign in first.'], 401);
        $d = $req->get_json_params(); if (!is_array($d)) $d = [];
        $title = sanitize_text_field($d['title'] ?? '');
        if ($title === '') return new WP_REST_Response(['success' => false, 'message' => 'Title is required.'], 400);

        // Tier + fleet limits (free=1, pro=5, featured=unlimited). Cap is based
        // on the REQUESTED tier so a paying host can add their fleet immediately.
        $reqTier = in_array(($d['tier'] ?? 'free'), ['free', 'pro', 'featured'], true) ? $d['tier'] : 'free';
        $caps = sb_rental_fleet_caps();
        $cap  = $caps[$reqTier] ?? 1;
        $isAdmin = (bool) array_intersect(['administrator', 'editor'], (array) $user->roles);
        if ($cap > 0 && !$isAdmin) {
            $existing = (int) (new WP_Query([
                'post_type' => 'rental', 'author' => (int) $user->ID,
                'post_status' => ['publish', 'pending', 'draft'], 'fields' => 'ids',
                'posts_per_page' => -1, 'no_found_rows' => true,
            ]))->post_count;
            if ($existing + 1 > $cap) {
                return new WP_REST_Response([
                    'success' => false, 'error' => 'FLEET_LIMIT',
                    'message' => sprintf('Your plan allows %d rental%s. Upgrade to a higher plan to list more vehicles.', $cap, $cap === 1 ? '' : 's'),
                ], 403);
            }
        }

        $id = wp_insert_post([
            'post_type' => 'rental', 'post_status' => 'pending',
            'post_title' => $title, 'post_content' => wp_kses_post($d['description'] ?? ''),
            'post_author' => (int) $user->ID,
        ], true);
        if (is_wp_error($id)) return new WP_REST_Response(['success' => false, 'message' => 'Could not create rental.'], 500);
        sb_apply_rental_fields($id, $d);

        // Paid tiers only take effect once an admin verifies payment — keep the
        // live tier 'free' until then; record the requested tier + payment.
        $isPaid  = $reqTier !== 'free';
        $billing = ($d['billingPeriod'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $prices  = sb_rental_tier_prices();
        $amount  = $isPaid ? (float) ($prices[$reqTier][$billing] ?? 0) : 0;
        update_post_meta($id, 'listing_tier', 'free');
        update_post_meta($id, 'sb_payment', [
            'tier'          => $reqTier,
            'billingPeriod' => $billing,
            'amount'        => $amount,
            'method'        => sanitize_text_field($d['paymentMethod'] ?? ''),
            'reference'     => sanitize_text_field($d['paymentReference'] ?? ''),
            'receiptId'     => (int) ($d['paymentReceiptId'] ?? 0),
            'status'        => $isPaid ? 'pending_review' : 'not_required',
            'submittedAt'   => current_time('mysql'),
        ]);
        return new WP_REST_Response(['success' => true, 'id' => (int) $id, 'slug' => get_post_field('post_name', $id), 'status' => 'pending', 'paid' => $isPaid], 201);
    }
}

if (!function_exists('sb_rental_fleet_caps')) {
    function sb_rental_fleet_caps() { return ['free' => 1, 'pro' => 5, 'featured' => 0]; } // 0 = unlimited
}
if (!function_exists('sb_rental_tier_prices')) {
    function sb_rental_tier_prices() {
        return [
            'pro'      => ['monthly' => 399, 'yearly' => 3990],
            'featured' => ['monthly' => 999, 'yearly' => 9990],
        ];
    }
}

if (!function_exists('sb_my_rentals')) {
    function sb_my_rentals(WP_REST_Request $req) {
        $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
        $q = new WP_Query([
            'post_type' => 'rental', 'author' => (int) $user->ID,
            'post_status' => ['publish', 'pending', 'draft'], 'posts_per_page' => 100,
            'orderby' => 'modified', 'order' => 'DESC',
        ]);
        $rows = [];
        foreach ($q->posts as $p) {
            $types = wp_get_post_terms($p->ID, 'rental-type');
            $rows[] = [
                'id'     => (int) $p->ID,
                'title'  => html_entity_decode(get_the_title($p->ID), ENT_QUOTES) ?: '(untitled)',
                'slug'   => $p->post_name,
                'status' => $p->post_status === 'publish' ? ((bool) sb_acf('rental_available', $p->ID, true) ? 'live' : 'paused') : $p->post_status,
                'type'   => (!is_wp_error($types) && $types) ? $types[0]->name : '',
                'daily'  => (float) sb_acf('rental_daily_rate', $p->ID, 0),
                'thumb'  => get_the_post_thumbnail_url($p->ID, 'medium') ?: '',
            ];
        }
        return new WP_REST_Response(['success' => true, 'rentals' => $rows], 200);
    }
}

if (!function_exists('sb_rental_edit_get')) {
    function sb_rental_edit_get(WP_REST_Request $req) {
        $g = sb_rental_owner_guard($req); if (isset($g['err'])) return $g['err'];
        $id = $g['id']; $post = $g['post'];
        $types = wp_get_post_terms($id, 'rental-type'); $type = (!is_wp_error($types) && $types) ? $types[0] : null;
        $locs  = wp_get_post_terms($id, 'location'); $bar = (!is_wp_error($locs) && $locs) ? $locs[0] : null;
        $gIds = []; $gUrls = [];
        foreach ((array) sb_acf('rental_gallery', $id, []) as $im) {
            if (is_array($im)) { $gIds[] = (int) ($im['ID'] ?? $im['id'] ?? 0); $gUrls[] = sb_img_url($im, 'large'); }
            elseif (is_numeric($im)) { $gIds[] = (int) $im; $gUrls[] = wp_get_attachment_image_url((int) $im, 'large') ?: ''; }
        }
        $map = sb_acf('rental_location', $id, null);
        return new WP_REST_Response([
            'success' => true,
            'id' => $id, 'slug' => $post->post_name, 'status' => $post->post_status,
            'title' => html_entity_decode(get_the_title($id), ENT_QUOTES),
            'type' => $type ? $type->slug : '',
            'dailyRate' => (float) sb_acf('rental_daily_rate', $id, 0) ?: '',
            'hourlyRate' => (float) sb_acf('rental_hourly_rate', $id, 0) ?: '',
            'deposit' => (float) sb_acf('rental_deposit', $id, 0) ?: '',
            'inclusions' => (string) sb_acf('rental_inclusions', $id, ''),
            'providerName' => (string) sb_acf('rental_provider_name', $id, ''),
            'providerContact' => (string) sb_acf('rental_provider_contact', $id, ''),
            'available' => (bool) sb_acf('rental_available', $id, true),
            'description' => $post->post_content,
            'barangayId' => $bar ? (int) $bar->term_id : 0,
            'barangayName' => $bar ? $bar->name : '',
            'galleryIds' => array_values(array_filter($gIds)),
            'galleryUrls' => array_values(array_filter($gUrls)),
            'mapLocation' => (is_array($map) && isset($map['lat'])) ? ['lat' => (float) $map['lat'], 'lng' => (float) $map['lng'], 'address' => (string) ($map['address'] ?? '')] : null,
        ], 200);
    }
}

if (!function_exists('sb_update_rental')) {
    function sb_update_rental(WP_REST_Request $req) {
        $g = sb_rental_owner_guard($req); if (isset($g['err'])) return $g['err'];
        $id = $g['id']; $d = $req->get_json_params(); if (!is_array($d)) $d = [];
        $upd = ['ID' => $id];
        if (isset($d['title']) && trim((string) $d['title']) !== '') $upd['post_title'] = sanitize_text_field($d['title']);
        if (array_key_exists('description', $d)) $upd['post_content'] = wp_kses_post($d['description'] ?? '');
        if (count($upd) > 1) wp_update_post($upd);
        sb_apply_rental_fields($id, $d);
        return new WP_REST_Response(['success' => true, 'id' => $id, 'slug' => get_post_field('post_name', $id)], 200);
    }
}

if (!function_exists('sb_rental_action')) {
    function sb_rental_action(WP_REST_Request $req) {
        $g = sb_rental_owner_guard($req); if (isset($g['err'])) return $g['err'];
        $id = $g['id']; $action = sanitize_key($req->get_param('action'));
        if ($action === 'trash') { wp_trash_post($id); return new WP_REST_Response(['success' => true, 'status' => 'trashed'], 200); }
        if ($action === 'pause')   { update_field('rental_available', 0, $id); return new WP_REST_Response(['success' => true, 'status' => 'paused'], 200); }
        if ($action === 'unpause') { update_field('rental_available', 1, $id); return new WP_REST_Response(['success' => true, 'status' => 'live'], 200); }
        return new WP_REST_Response(['success' => false, 'error' => 'BAD_ACTION'], 400);
    }
}
