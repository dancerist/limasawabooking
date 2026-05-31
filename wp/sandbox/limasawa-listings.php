<?php
/**
 * Limasawa Listings API  —  REST namespace: limasawa/v1
 *
 * Deployed as a Novamira sandbox file at:
 *   wp-content/novamira-sandbox/limasawa-listings.php  (auto-loaded by Novamira)
 *
 * THIS repo file is the source of truth. To deploy, copy its contents to the
 * server path above via novamira/write-file. Roll back instantly with
 * novamira/disable-file if anything fatals.
 *
 * Data model is native ACF (post type `accommodation` + its field group, the
 * `accommodation-category`, `accommodation-amenity` and `location` taxonomies).
 * Response keys are the exact keys the Astro frontend reads in src/lib/api.ts
 * (reshapeListing) — do not rename them without updating that adapter.
 *
 * Routes:
 *   GET /listings            paginated card list  (?page, ?per_page, ?cat, ?loc, ?amenity, ?guests, ?q)
 *   GET /listings/{slug}     single listing, full detail fields
 *   GET /map-pins            lightweight lat/lng pins for the browse map
 */

if (defined('ABSPATH')) {

    /* -------------------------------------------------------------------------
     * Field helpers — read ACF safely whether or not get_field() is loaded
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_acf')) {
        function sb_acf($name, $post_id, $default = '') {
            if (function_exists('get_field')) {
                $v = get_field($name, $post_id);
                return $v === null || $v === false ? $default : $v;
            }
            $v = get_post_meta($post_id, $name, true);
            return $v === '' ? $default : $v;
        }
    }
    if (!function_exists('sb_img_url')) {
        // ACF image/gallery items return an array; pick a sized URL, falling back gracefully.
        function sb_img_url($img, $size = 'large') {
            if (!is_array($img)) {
                return '';
            }
            if (!empty($img['sizes'][$size])) {
                return $img['sizes'][$size];
            }
            return $img['url'] ?? '';
        }
    }
    if (!function_exists('sb_terms')) {
        function sb_terms($post_id, $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            if (is_wp_error($terms) || !is_array($terms)) {
                return ['slugs' => [], 'names' => []];
            }
            return [
                'slugs' => wp_list_pluck($terms, 'slug'),
                'names' => wp_list_pluck($terms, 'name'),
            ];
        }
    }

    /* -------------------------------------------------------------------------
     * Formatter — one accommodation post → the shape src/lib/api.ts expects.
     * $full=false keeps the card payload lean (cards never read detail fields);
     * $full=true adds everything a single-listing page needs.
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_listing_format')) {
        function sb_listing_format($post_id, $full = false) {
            $cats = sb_terms($post_id, 'accommodation-category');
            $amen = sb_terms($post_id, 'accommodation-amenity');
            $locs = sb_terms($post_id, 'location');

            $gallery = sb_acf('accommodation_gallery', $post_id, []);
            $gallery_urls = [];
            if (is_array($gallery)) {
                foreach ($gallery as $img) {
                    $u = sb_img_url($img, 'large');
                    if ($u !== '') {
                        $gallery_urls[] = $u;
                    }
                }
            }

            $map = sb_acf('accommodation_location', $post_id, null);
            $lat = is_array($map) && isset($map['lat']) ? (float) $map['lat'] : null;
            $lng = is_array($map) && isset($map['lng']) ? (float) $map['lng'] : null;

            $badges = sb_acf('accommodation_badges', $post_id, []);

            $out = [
                'id'        => (int) $post_id,
                'title'     => html_entity_decode(get_the_title($post_id), ENT_QUOTES),
                'slug'      => get_post_field('post_name', $post_id),
                'excerpt'   => wp_strip_all_tags(get_the_excerpt($post_id)),
                'tier'      => in_array('Featured', (array) $badges, true) ? 'featured' : 'free',
                'thumb'     => get_the_post_thumbnail_url($post_id, 'large') ?: '',
                'badges'    => array_values((array) $badges),
                'price'     => (float) sb_acf('accommodation_daily_rent', $post_id, 0),
                'monthly'   => (float) sb_acf('accommodation_monthly_rent', $post_id, 0),
                'bedrooms'  => (int) sb_acf('accommodation_bedrooms', $post_id, 0),
                'beds'      => (int) sb_acf('accommodation_beds', $post_id, 0),
                'baths'     => (int) sb_acf('accommodation_bathrooms', $post_id, 0),
                'guests'    => (int) sb_acf('accommodation_number_of_guests', $post_id, 0),
                'placeType' => (string) sb_acf('what_type_of_place_will_guests_have', $post_id, ''),
                'subHeading'=> (string) sb_acf('accommodation_sub_heading', $post_id, ''),
                'verified'  => (bool) sb_acf('accommodation_fully_verified', $post_id, false),
                'hostName'  => (string) sb_acf('accommodation_host_name', $post_id, ''),
                'hostPic'   => sb_img_url(sb_acf('accommodation_host_picture', $post_id, null), 'thumbnail'),
                'desc'      => (string) sb_acf('custom_accommodation_short_description', $post_id, ''),
                'gallery'   => $gallery_urls,
                // location taxonomy: term[0] treated as municipality, term[1] as barangay
                'muniName'  => $locs['names'][0] ?? '',
                'muniSlug'  => $locs['slugs'][0] ?? '',
                'barName'   => $locs['names'][1] ?? '',
                'cats'      => $cats['slugs'],
                'catNames'  => $cats['names'],
                'amen'      => $amen['slugs'],
                'amenNames' => $amen['names'],
                'lat'       => $lat,
                'lng'       => $lng,
            ];

            if (!$full) {
                return $out;
            }

            /* ---- detail-only fields (single-listing page) ---- */
            $faq_raw = sb_acf('faq_amenities', $post_id, []);
            $faq = [];
            if (is_array($faq_raw)) {
                foreach ($faq_raw as $k => $v) {
                    $faq[$k] = (bool) $v;
                }
            }

            $services_raw = sb_acf('services', $post_id, []);
            $services = [];
            if (sb_acf('add_services', $post_id, false) && is_array($services_raw)) {
                foreach ($services_raw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $services[] = [
                        'name'        => (string) ($row['service_name'] ?? ''),
                        'description' => (string) ($row['service_description'] ?? ''),
                        'icon'        => sb_img_url($row['service_icon'] ?? null, 'thumbnail'),
                    ];
                }
            }

            $custom_raw = sb_acf('accommodation_custom_info', $post_id, []);
            $custom = [];
            if (is_array($custom_raw)) {
                foreach ($custom_raw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $imgs = [];
                    if (!empty($row['custom_info_gallery']) && is_array($row['custom_info_gallery'])) {
                        foreach ($row['custom_info_gallery'] as $img) {
                            $u = sb_img_url($img, 'large');
                            if ($u !== '') {
                                $imgs[] = $u;
                            }
                        }
                    }
                    $custom[] = [
                        'title'   => (string) ($row['custom_info_title'] ?? ''),
                        'label'   => (string) ($row['custom_info_label'] ?? ''),
                        'gallery' => $imgs,
                    ];
                }
            }

            $out['detail'] = [
                'content'         => apply_filters('the_content', get_post_field('post_content', $post_id)),
                'address1'        => (string) sb_acf('address_1', $post_id, ''),
                'faqAmenities'    => $faq,
                'services'        => $services,
                'customInfo'      => $custom,
                'generatorSched'  => (string) sb_acf('generator_schedule', $post_id, ''),
                'host'            => [
                    'name'     => (string) sb_acf('accommodation_host_name', $post_id, ''),
                    'contact'  => (string) sb_acf('accommodation_host_contact_number', $post_id, ''),
                    'whatsapp' => (string) sb_acf('host_whatsapp', $post_id, ''),
                    'email'    => (string) sb_acf('accommodation_host_email', $post_id, ''),
                ],
                'links'           => [
                    'booking'   => (string) sb_acf('accommodation_booking_link', $post_id, ''),
                    'bookingcom'=> (string) sb_acf('accommodation_bookingcom_link', $post_id, ''),
                    'airbnb'    => (string) sb_acf('airbnb_booking_link', $post_id, ''),
                    'website'   => (string) sb_acf('accommodation_website_link', $post_id, ''),
                    'facebook'  => (string) sb_acf('facebook_page_link', $post_id, ''),
                    'instagram' => (string) sb_acf('instagram_link', $post_id, ''),
                ],
            ];

            return $out;
        }
    }

    /* -------------------------------------------------------------------------
     * REST routes — limasawa/v1
     * ---------------------------------------------------------------------- */
    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/listings', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_listings_index',
        ]);
        register_rest_route($ns, '/listings/(?P<slug>[a-zA-Z0-9-]+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_listing_single',
        ]);
        register_rest_route($ns, '/map-pins', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_map_pins',
        ]);
        register_rest_route($ns, '/taxonomies', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_taxonomies',
        ]);
    });

    if (!function_exists('sb_listings_query_args')) {
        /**
         * WP_Query args from the rich filter params StaysExperience sends.
         * Handles the clean taxonomy/numeric filters here; price/type/badge/bounds
         * are applied in sb_post_filter() because they need the formatted row.
         * Always fetches all matches (posts_per_page=-1) — limasawa has ~25
         * listings, so post-filter + manual paginate is cheap and robust.
         */
        function sb_listings_query_args(WP_REST_Request $req) {
            $args = [
                'post_type'      => 'accommodation',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ];

            // Category: `category` + legacy `cat` + `cats` (csv) → accommodation-category (OR).
            $catSlugs = [];
            foreach (['category', 'cat'] as $p) {
                $v = sanitize_title((string) $req->get_param($p));
                if ($v !== '') $catSlugs[] = $v;
            }
            foreach (explode(',', (string) $req->get_param('cats')) as $c) {
                $c = sanitize_title($c);
                if ($c !== '') $catSlugs[] = $c;
            }
            $catSlugs = array_values(array_unique(array_filter($catSlugs)));

            // Location: `location` (siargao) or `loc` (legacy).
            $loc = sanitize_title((string) $req->get_param('location'));
            if ($loc === '') $loc = sanitize_title((string) $req->get_param('loc'));

            // Amenities: `amenities` (csv) + `amenity` (single) → accommodation-amenity (AND).
            $amenSlugs = [];
            foreach (explode(',', (string) $req->get_param('amenities')) as $a) {
                $a = sanitize_title($a);
                if ($a !== '') $amenSlugs[] = $a;
            }
            $amOne = sanitize_title((string) $req->get_param('amenity'));
            if ($amOne !== '') $amenSlugs[] = $amOne;
            $amenSlugs = array_values(array_unique(array_filter($amenSlugs)));

            $tax = [];
            if (!empty($catSlugs)) {
                $tax[] = ['taxonomy' => 'accommodation-category', 'field' => 'slug', 'terms' => $catSlugs, 'operator' => 'IN'];
            }
            if ($loc !== '') {
                $tax[] = ['taxonomy' => 'location', 'field' => 'slug', 'terms' => [$loc], 'operator' => 'IN'];
            }
            foreach ($amenSlugs as $a) {
                $tax[] = ['taxonomy' => 'accommodation-amenity', 'field' => 'slug', 'terms' => [$a], 'operator' => 'IN'];
            }
            if (count($tax) > 1) $tax['relation'] = 'AND';
            if (!empty($tax)) $args['tax_query'] = $tax;

            // Numeric meta: guests / beds / baths >=.
            $meta = [];
            $guests = (int) $req->get_param('guests');
            if ($guests > 0) $meta[] = ['key' => 'accommodation_number_of_guests', 'value' => $guests, 'compare' => '>=', 'type' => 'NUMERIC'];
            $beds = (int) $req->get_param('beds');
            if ($beds > 0)   $meta[] = ['key' => 'accommodation_beds', 'value' => $beds, 'compare' => '>=', 'type' => 'NUMERIC'];
            $baths = (int) $req->get_param('baths');
            if ($baths > 0)  $meta[] = ['key' => 'accommodation_bathrooms', 'value' => $baths, 'compare' => '>=', 'type' => 'NUMERIC'];
            if (count($meta) > 1) $meta['relation'] = 'AND';
            if (!empty($meta)) $args['meta_query'] = $meta;

            $q = sanitize_text_field((string) $req->get_param('q'));
            if ($q !== '') $args['s'] = $q;

            return $args;
        }
    }

    if (!function_exists('sb_post_filter')) {
        // PHP-side filters that need the formatted row: price/type/badge/bounds.
        function sb_post_filter(array $rows, WP_REST_Request $req) {
            $rateMode = $req->get_param('rate_mode') === 'monthly' ? 'monthly' : 'daily';
            $priceMin = (float) $req->get_param('price_min');
            $priceMaxRaw = $req->get_param('price_max');
            $priceMax = ($priceMaxRaw !== null && $priceMaxRaw !== '') ? (float) $priceMaxRaw : 0;
            $type  = (string) $req->get_param('type');   // '' | 'room' | 'entire'
            $badge = sanitize_title((string) $req->get_param('badge'));

            $box = null;
            $bounds = (string) $req->get_param('bounds'); // swLat,swLng,neLat,neLng
            if ($bounds !== '') {
                $b = array_map('floatval', explode(',', $bounds));
                if (count($b) === 4) $box = $b;
            }

            $out = [];
            foreach ($rows as $r) {
                $rate = $rateMode === 'monthly' ? (float) $r['monthly'] : (float) $r['price'];
                if ($rateMode === 'monthly' && $rate <= 0) continue;
                if ($priceMin > 0 && $rate > 0 && $rate < $priceMin) continue;
                if ($priceMax > 0 && $rate > $priceMax) continue;

                if ($type === 'entire' && stripos((string) $r['placeType'], 'entire') === false) continue;
                if ($type === 'room'   && stripos((string) $r['placeType'], 'room')   === false) continue;

                if ($badge !== '') {
                    $has = false;
                    foreach ((array) $r['badges'] as $bd) {
                        if (strpos(sanitize_title((string) $bd), $badge) !== false) { $has = true; break; }
                    }
                    if (!$has) continue;
                }

                if ($box) {
                    if (!is_numeric($r['lat']) || !is_numeric($r['lng'])) continue;
                    if ($r['lat'] < $box[0] || $r['lat'] > $box[2] || $r['lng'] < $box[1] || $r['lng'] > $box[3]) continue;
                }

                $out[] = $r;
            }
            return $out;
        }
    }

    if (!function_exists('sb_listings_index')) {
        function sb_listings_index(WP_REST_Request $req) {
            $page = max(1, (int) $req->get_param('page'));
            $per  = (int) $req->get_param('per_page');
            $per  = $per > 0 ? min(48, $per) : 12;

            $query = new WP_Query(sb_listings_query_args($req));
            $rows = [];
            foreach ($query->posts as $post) {
                $rows[] = sb_listing_format($post->ID, false);
            }

            $rows  = sb_post_filter($rows, $req);
            $total = count($rows);
            $pages = $total > 0 ? (int) ceil($total / $per) : 1;
            $slice = array_slice($rows, ($page - 1) * $per, $per);

            return new WP_REST_Response([
                'listings'       => array_values($slice),
                'total'          => $total,
                'pages'          => max(1, $pages),
                'page'           => $page,
                'server_filters' => true,
            ], 200);
        }
    }

    if (!function_exists('sb_listing_single')) {
        function sb_listing_single(WP_REST_Request $req) {
            $slug = sanitize_title((string) $req->get_param('slug'));
            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => 'accommodation',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
            ]);
            if (empty($posts)) {
                return new WP_REST_Response(['error' => 'NOT_FOUND'], 404);
            }
            return new WP_REST_Response(['listing' => sb_listing_format($posts[0]->ID, true)], 200);
        }
    }

    if (!function_exists('sb_map_pins')) {
        function sb_map_pins(WP_REST_Request $req) {
            $query = new WP_Query(sb_listings_query_args($req));

            $pins = [];
            foreach ($query->posts as $post) {
                $map = sb_acf('accommodation_location', $post->ID, null);
                if (!is_array($map) || !isset($map['lat'], $map['lng'])) {
                    continue;
                }
                $locs = sb_terms($post->ID, 'location');
                $pins[] = [
                    'id'       => (int) $post->ID,
                    'slug'     => $post->post_name,
                    'title'    => html_entity_decode(get_the_title($post->ID), ENT_QUOTES),
                    'price'    => (float) sb_acf('accommodation_daily_rent', $post->ID, 0),
                    'lat'      => (float) $map['lat'],
                    'lng'      => (float) $map['lng'],
                    'muniSlug' => $locs['slugs'][0] ?? '',
                ];
            }

            // siargao's fetchPins() expects a bare JSON array.
            return new WP_REST_Response($pins, 200);
        }
    }

    if (!function_exists('sb_taxonomies')) {
        // Filter facets for StaysExperience: { categories:[{slug,name,count}], amenities:[...] }.
        function sb_taxonomies(WP_REST_Request $req) {
            $mapTerms = function ($taxonomy) {
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
                if (is_wp_error($terms) || !is_array($terms)) return [];
                $out = [];
                foreach ($terms as $t) {
                    $out[] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
                }
                return $out;
            };
            return new WP_REST_Response([
                'categories' => $mapTerms('accommodation-category'),
                'amenities'  => $mapTerms('accommodation-amenity'),
            ], 200);
        }
    }
}
