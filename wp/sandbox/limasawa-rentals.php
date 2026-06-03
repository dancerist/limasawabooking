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
