<?php
/**
 * Limasawa Booking — host listing EDIT flow.
 *
 *   GET  /listing-edit/{id}     JWT owner → returns the listing in the exact
 *                               shape list-your-property.astro's `data` model
 *                               consumes (so ?edit=ID prefills every step).
 *   POST /update-listing/{id}   JWT owner → updates title/content + ACF fields.
 *                               Does NOT touch tier / sb_payment (edits are free
 *                               and go live immediately — no re-approval).
 *
 * The ACF write mapping is shared with create via sb_apply_listing_fields()
 * (defined here, also called by limasawa-submit.php's sb_submit_listing()).
 * Read helpers (sb_acf, sb_img_url, sb_rate_summary) come from limasawa-listings.php;
 * sb_submit_field()/sb_tiers() from limasawa-submit.php — all resolved at request time.
 */
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Shared ACF/taxonomy writer — the single source of truth for mapping the
 * conversational-form payload onto an accommodation post. Used by BOTH
 * create (submit-listing) and edit (update-listing). Does not write
 * tier/payment meta or touch post_status/title/content — callers own those.
 * ---------------------------------------------------------------------- */
if (!function_exists('sb_apply_listing_fields')) {
    function sb_apply_listing_fields($post_id, array $d, $tier = 'free') {
        // ── Scalar ACF fields ──
        sb_submit_field('accommodation_host_name', sanitize_text_field($d['hostName'] ?? ''), $post_id);
        sb_submit_field('accommodation_host_contact_number', sanitize_text_field($d['hostPhone'] ?? ''), $post_id);
        sb_submit_field('what_type_of_place_will_guests_have', sanitize_text_field($d['placeType'] ?? ''), $post_id);
        sb_submit_field('accommodation_sub_heading', sanitize_text_field($d['description'] ?? ''), $post_id);
        sb_submit_field('custom_accommodation_short_description', sanitize_text_field($d['description'] ?? ''), $post_id);
        sb_submit_field('accommodation_number_of_guests', (int) ($d['guests'] ?? 0), $post_id);
        sb_submit_field('accommodation_bedrooms', (int) ($d['bedrooms'] ?? 0), $post_id);
        sb_submit_field('accommodation_beds', (int) ($d['beds'] ?? 0), $post_id);
        sb_submit_field('accommodation_bathrooms', (int) ($d['bathrooms'] ?? 0), $post_id);
        sb_submit_field('accommodation_daily_rent', (float) ($d['dailyRate'] ?? 0), $post_id);
        sb_submit_field('accommodation_monthly_rent', (float) ($d['monthlyRate'] ?? 0), $post_id);

        // ── Pricing mode (flat | rooms | per_pax) ──
        $pricingMode = in_array(($d['pricingMode'] ?? 'flat'), ['flat', 'rooms', 'per_pax'], true) ? $d['pricingMode'] : 'flat';
        sb_submit_field('pricing_mode', $pricingMode, $post_id);
        if ($pricingMode === 'rooms' && !empty($d['roomTypes']) && is_array($d['roomTypes'])) {
            $roomRows = [];
            foreach ($d['roomTypes'] as $rt) {
                if (!is_array($rt)) continue;
                $nm = sanitize_text_field($rt['name'] ?? '');
                $rt_rate = (float) ($rt['rate'] ?? 0);
                if ($nm === '' && $rt_rate <= 0) continue;
                $roomRows[] = [
                    'room_name'     => $nm,
                    'room_capacity' => (int) ($rt['capacity'] ?? 0),
                    'room_rate'     => $rt_rate,
                    'room_note'     => sanitize_text_field($rt['note'] ?? ''),
                ];
            }
            sb_submit_field('accommodation_room_types', $roomRows, $post_id);
            sb_submit_field('additional_bed_rate', (float) ($d['additionalBedRate'] ?? 0), $post_id);
        } elseif ($pricingMode === 'per_pax' && !empty($d['perPax']) && is_array($d['perPax'])) {
            $pp = $d['perPax'];
            sb_submit_field('per_pax_base_rate', (float) ($pp['baseRate'] ?? 0), $post_id);
            sb_submit_field('per_pax_base_pax', (int) ($pp['basePax'] ?? 0), $post_id);
            sb_submit_field('per_pax_extra_rate', (float) ($pp['extraPerPax'] ?? 0), $post_id);
            sb_submit_field('per_pax_max_pax', (int) ($pp['maxPax'] ?? 0), $post_id);
        }

        // Generic extra charges (any mode)
        if (isset($d['pricingExtras']) && is_array($d['pricingExtras'])) {
            $exRows = [];
            foreach ($d['pricingExtras'] as $ex) {
                if (!is_array($ex)) continue;
                $lbl = sanitize_text_field($ex['label'] ?? '');
                $amt = (float) ($ex['amount'] ?? 0);
                if ($lbl === '' && $amt <= 0) continue;
                $exRows[] = [
                    'extra_label'  => $lbl,
                    'extra_amount' => $amt,
                    'extra_note'   => sanitize_text_field($ex['note'] ?? ''),
                ];
            }
            sb_submit_field('pricing_extras', $exRows, $post_id);
        }

        sb_submit_field('address_1', sanitize_text_field($d['address1'] ?? ''), $post_id);

        // External links
        sb_submit_field('accommodation_booking_link', esc_url_raw($d['bookingLink'] ?? ''), $post_id);
        sb_submit_field('airbnb_booking_link', esc_url_raw($d['airbnbLink'] ?? ''), $post_id);
        sb_submit_field('accommodation_bookingcom_link', esc_url_raw($d['bookingComLink'] ?? ''), $post_id);
        sb_submit_field('accommodation_website_link', esc_url_raw($d['websiteLink'] ?? ''), $post_id);
        sb_submit_field('facebook_page_link', esc_url_raw($d['facebookLink'] ?? ''), $post_id);
        sb_submit_field('instagram_link', esc_url_raw($d['instagramLink'] ?? ''), $post_id);

        // Pin location (ACF google_map)
        if (!empty($d['mapLocation']) && is_array($d['mapLocation']) && isset($d['mapLocation']['lat'], $d['mapLocation']['lng'])) {
            sb_submit_field('accommodation_location', [
                'lat'     => (float) $d['mapLocation']['lat'],
                'lng'     => (float) $d['mapLocation']['lng'],
                'address' => sanitize_text_field($d['mapLocation']['address'] ?? ($d['address1'] ?? '')),
            ], $post_id);
        }

        // FAQ amenities group (booleans) + generator answer
        if (!empty($d['faqAmenities']) && is_array($d['faqAmenities'])) {
            $faq = [];
            foreach ($d['faqAmenities'] as $k => $v) {
                $faq[sanitize_key($k)] = $v ? 1 : 0;
            }
            if (isset($d['hasGenerator'])) $faq['faq_generator'] = $d['hasGenerator'] ? 1 : 0;
            sb_submit_field('faq_amenities', $faq, $post_id);
        }
        if (isset($d['generatorSchedule'])) {
            sb_submit_field('generator_schedule', wp_kses_post($d['generatorSchedule']), $post_id);
        }

        // Services repeater (gated by add_services)
        if (!empty($d['addServices']) && !empty($d['services']) && is_array($d['services'])) {
            $rows = [];
            foreach ($d['services'] as $s) {
                if (!is_array($s)) continue;
                $rows[] = [
                    'service_name'        => sanitize_text_field($s['name'] ?? ''),
                    'service_description' => sanitize_text_field($s['description'] ?? ''),
                ];
            }
            sb_submit_field('add_services', 1, $post_id);
            sb_submit_field('services', $rows, $post_id);
        } elseif (isset($d['addServices']) && !$d['addServices']) {
            sb_submit_field('add_services', 0, $post_id);
        }

        // Host photo + gallery (attachment IDs from /upload-media)
        if (!empty($d['hostPhotoId'])) {
            sb_submit_field('accommodation_host_picture', (int) $d['hostPhotoId'], $post_id);
        }
        if (!empty($d['galleryIds']) && is_array($d['galleryIds'])) {
            $ids = array_values(array_filter(array_map('intval', $d['galleryIds'])));
            $tiers = function_exists('sb_tiers') ? sb_tiers() : [];
            $cap = (int) ($tiers[$tier]['photos'] ?? 5);
            if ($cap > 0 && count($ids) > $cap) $ids = array_slice($ids, 0, $cap);
            if ($ids) {
                sb_submit_field('accommodation_gallery', $ids, $post_id);
                set_post_thumbnail($post_id, $ids[0]); // first photo = featured image
            }
        }

        // Taxonomies: category, barangay (location), amenities
        $cat = sanitize_title($d['accommodationCategory'] ?? '');
        if ($cat !== '') {
            $term = get_term_by('slug', $cat, 'accommodation-category') ?: get_term_by('name', $d['accommodationCategory'], 'accommodation-category');
            if ($term) wp_set_object_terms($post_id, [(int) $term->term_id], 'accommodation-category');
        }
        $barangayId = (int) ($d['barangayId'] ?? 0);
        if ($barangayId > 0) {
            wp_set_object_terms($post_id, [$barangayId], 'location');
        }
        if (isset($d['extraAmenities']) && is_array($d['extraAmenities'])) {
            $amenIds = [];
            foreach ($d['extraAmenities'] as $a) {
                $t = get_term_by('slug', sanitize_title($a), 'accommodation-amenity') ?: get_term_by('name', $a, 'accommodation-amenity');
                if ($t) $amenIds[] = (int) $t->term_id;
            }
            wp_set_object_terms($post_id, $amenIds, 'accommodation-amenity');
        }
    }
}

/* -------------------------------------------------------------------------
 * Owner guard — resolves JWT user + the target accommodation, enforcing
 * ownership (or admin). Returns ['err'=>WP_REST_Response] on failure.
 * ---------------------------------------------------------------------- */
if (!function_exists('sb_edit_guard')) {
    function sb_edit_guard(WP_REST_Request $req) {
        $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        if (!$user) return ['err' => new WP_REST_Response(['success' => false, 'message' => 'Please sign in.'], 401)];
        $id   = (int) $req['id'];
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'accommodation') {
            return ['err' => new WP_REST_Response(['success' => false, 'message' => 'Listing not found.'], 404)];
        }
        $isOwner = (int) $post->post_author === (int) $user->ID;
        $isAdmin = (bool) array_intersect(['administrator', 'editor'], (array) $user->roles);
        if (!$isOwner && !$isAdmin) {
            return ['err' => new WP_REST_Response(['success' => false, 'message' => 'You can only edit your own listings.'], 403)];
        }
        return ['user' => $user, 'post' => $post, 'id' => $id];
    }
}

add_action('rest_api_init', function () {
    register_rest_route('limasawa/v1', '/listing-edit/(?P<id>\d+)', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_listing_edit_get',
    ]);
    register_rest_route('limasawa/v1', '/update-listing/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_update_listing',
    ]);
});

if (!function_exists('sb_listing_edit_get')) {
    function sb_listing_edit_get(WP_REST_Request $req) {
        $g = sb_edit_guard($req);
        if (isset($g['err'])) return $g['err'];
        $id = $g['id']; $post = $g['post'];

        // location terms → municipality (parent) + barangay (child)
        $muni = null; $bar = null;
        $locTerms = wp_get_post_terms($id, 'location');
        if (!is_wp_error($locTerms)) {
            foreach ($locTerms as $t) { if ($t->parent) { $bar = $t; } else { $muni = $t; } }
            if ($bar && !$muni) { $p = get_term($bar->parent, 'location'); if ($p && !is_wp_error($p)) $muni = $p; }
        }

        $catTerms  = wp_get_post_terms($id, 'accommodation-category');
        $catName   = (!is_wp_error($catTerms) && $catTerms) ? $catTerms[0]->name : '';
        $amenTerms = wp_get_post_terms($id, 'accommodation-amenity');
        $amenNames = !is_wp_error($amenTerms) ? array_values(wp_list_pluck($amenTerms, 'name')) : [];

        // gallery → ids + urls
        $gIds = []; $gUrls = [];
        foreach ((array) sb_acf('accommodation_gallery', $id, []) as $im) {
            if (is_array($im)) { $gIds[] = (int) ($im['ID'] ?? $im['id'] ?? 0); $gUrls[] = sb_img_url($im, 'large'); }
            elseif (is_numeric($im)) { $gIds[] = (int) $im; $gUrls[] = wp_get_attachment_image_url((int) $im, 'large') ?: ''; }
        }
        $gIds  = array_values(array_filter($gIds));
        $gUrls = array_values(array_filter($gUrls));

        // host photo
        $hp = sb_acf('accommodation_host_picture', $id, null);
        $hpId  = is_array($hp) ? (int) ($hp['ID'] ?? $hp['id'] ?? 0) : (is_numeric($hp) ? (int) $hp : 0);
        $hpUrl = is_array($hp) ? sb_img_url($hp, 'thumbnail') : ($hpId ? (wp_get_attachment_image_url($hpId, 'thumbnail') ?: '') : '');

        // faq amenities (raw faq_* booleans) + generator
        $faqRaw = sb_acf('faq_amenities', $id, []);
        $faq = []; if (is_array($faqRaw)) foreach ($faqRaw as $k => $v) { $faq[$k] = (bool) $v; }
        $hasGen = !empty($faqRaw['faq_generator']);

        // services
        $services = [];
        if (sb_acf('add_services', $id, false)) {
            foreach ((array) sb_acf('services', $id, []) as $s) {
                if (is_array($s)) $services[] = ['name' => (string) ($s['service_name'] ?? ''), 'description' => (string) ($s['service_description'] ?? '')];
            }
        }

        // map pin
        $map = sb_acf('accommodation_location', $id, null);
        $mapOut = (is_array($map) && isset($map['lat'])) ? ['lat' => (float) $map['lat'], 'lng' => (float) $map['lng'], 'address' => (string) ($map['address'] ?? '')] : null;

        $rate = function_exists('sb_rate_summary') ? sb_rate_summary($id) : null;

        $out = [
            'slug'                  => $post->post_name,
            'tier'                  => get_post_meta($id, 'listing_tier', true) ?: 'free',
            'hostName'              => (string) sb_acf('accommodation_host_name', $id, ''),
            'hostPhone'             => (string) sb_acf('accommodation_host_contact_number', $id, ''),
            'propertyName'          => html_entity_decode(get_the_title($id), ENT_QUOTES),
            'accommodationCategory' => $catName,
            'placeType'             => (string) sb_acf('what_type_of_place_will_guests_have', $id, ''),
            'municipalityId'        => $muni ? (int) $muni->term_id : 0,
            'municipalityName'      => $muni ? $muni->name : '',
            'barangayId'            => $bar ? (int) $bar->term_id : 0,
            'barangayName'          => $bar ? $bar->name : '',
            'guests'                => (int) sb_acf('accommodation_number_of_guests', $id, 0),
            'bedrooms'              => (int) sb_acf('accommodation_bedrooms', $id, 0),
            'beds'                  => (int) sb_acf('accommodation_beds', $id, 0),
            'bathrooms'             => (int) sb_acf('accommodation_bathrooms', $id, 0),
            'dailyRate'             => $rate && $rate['daily'] ? (string) (0 + $rate['daily']) : '',
            'monthlyRate'           => $rate && $rate['monthly'] ? (string) (0 + $rate['monthly']) : '',
            'pricingMode'           => $rate ? $rate['mode'] : 'flat',
            'roomTypes'             => $rate ? $rate['rooms'] : [],
            'additionalBedRate'     => $rate && $rate['addBed'] ? (string) (0 + $rate['addBed']) : '',
            'perPax'                => $rate ? $rate['perPax'] : null,
            'pricingExtras'         => $rate ? $rate['extras'] : [],
            'description'           => (string) sb_acf('accommodation_sub_heading', $id, ''),
            'longDescription'       => $post->post_content,
            'bookingLink'           => (string) sb_acf('accommodation_booking_link', $id, ''),
            'airbnbLink'            => (string) sb_acf('airbnb_booking_link', $id, ''),
            'bookingComLink'        => (string) sb_acf('accommodation_bookingcom_link', $id, ''),
            'websiteLink'           => (string) sb_acf('accommodation_website_link', $id, ''),
            'facebookLink'          => (string) sb_acf('facebook_page_link', $id, ''),
            'instagramLink'         => (string) sb_acf('instagram_link', $id, ''),
            'addServices'           => (bool) sb_acf('add_services', $id, false),
            'services'              => $services,
            'faqAmenities'          => $faq,
            'extraAmenities'        => $amenNames,
            'hostPhotoId'           => $hpId,
            'hostPhotoUrl'          => $hpUrl ?: '',
            'galleryIds'            => $gIds,
            'galleryUrls'           => $gUrls,
            'address1'              => (string) sb_acf('address_1', $id, ''),
            'mapLocation'           => $mapOut,
            'hasGenerator'          => $hasGen,
            'generatorSchedule'     => (string) sb_acf('generator_schedule', $id, ''),
        ];
        return new WP_REST_Response($out, 200);
    }
}

if (!function_exists('sb_update_listing')) {
    function sb_update_listing(WP_REST_Request $req) {
        $g = sb_edit_guard($req);
        if (isset($g['err'])) return $g['err'];
        $id = $g['id'];
        $d  = $req->get_json_params();
        if (!is_array($d)) $d = [];

        // Title + long description (post fields).
        $upd = ['ID' => $id];
        if (isset($d['propertyName']) && trim((string) $d['propertyName']) !== '') {
            $upd['post_title'] = sanitize_text_field($d['propertyName']);
        }
        if (array_key_exists('longDescription', $d)) {
            $upd['post_content'] = wp_kses_post($d['longDescription'] ?? '');
        }
        if (count($upd) > 1) wp_update_post($upd);

        // ACF + taxonomies (shared writer). Tier drives only the photo cap;
        // we never change tier / sb_payment on an edit.
        $tier = get_post_meta($id, 'listing_tier', true) ?: 'free';
        sb_apply_listing_fields($id, $d, $tier);

        return new WP_REST_Response([
            'success' => true,
            'id'      => $id,
            'slug'    => get_post_field('post_name', $id),
        ], 200);
    }
}
