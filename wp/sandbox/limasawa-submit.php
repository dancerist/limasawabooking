<?php
/**
 * Limasawa Listing Submission API  —  REST namespace: limasawa/v1
 *
 * Powers the conversational "List your property" form (ported from siargao's
 * list-your-home flow). Deployed as a Novamira sandbox file at
 *   wp-content/novamira-sandbox/limasawa-submit.php
 * Repo file is the source of truth; deploy via novamira/write-file.
 *
 * Routes (all limasawa/v1):
 *   POST /upload-media     multipart {file, image_type, property_name?} → {success, attachment_id, url}
 *   GET  /coupon/validate  ?code=&tier=&billing=&amount= → {valid, code, type, value, discount, new_total}
 *   POST /submit-listing   JSON form payload (Bearer host token) → {success, slug, id}
 *
 * Submissions are created as `pending` accommodations (admin approves → publish).
 * Manual e-wallet payment model (GCash/Maya + receipt upload) — no gateway.
 *
 * Depends on sb_jwt_current_user() from limasawa-auth.php (guarded).
 */

if (defined('ABSPATH')) {

    /* -------------------------------------------------------------------------
     * Tier model (mirrors siargao). Prices live in the frontend; the backend
     * needs the photo caps + the base price for coupon re-validation.
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_tiers')) {
        function sb_tiers() {
            return [
                'free'     => ['photos' => 5,    'monthly' => 0,   'yearly' => 0],
                'local'    => ['photos' => 15,   'monthly' => 399, 'yearly' => 3990],
                'prime'    => ['photos' => 9999, 'monthly' => 699, 'yearly' => 6990],
                'featured' => ['photos' => 9999, 'monthly' => 999, 'yearly' => 9990],
            ];
        }
    }

    /* -------------------------------------------------------------------------
     * Routes
     * ---------------------------------------------------------------------- */
    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/upload-media', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_upload_media',
        ]);
        register_rest_route($ns, '/coupon/validate', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_coupon_validate',
        ]);
        register_rest_route($ns, '/submit-listing', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_submit_listing',
        ]);
    });

    /* -------------------------------------------------------------------------
     * POST /upload-media  — image only, ≤ 8 MB. Returns the WP attachment.
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_upload_media')) {
        function sb_upload_media(WP_REST_Request $req) {
            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                return new WP_REST_Response(['success' => false, 'message' => 'No file received'], 400);
            }
            $f = $_FILES['file'];
            if (!empty($f['error'])) {
                return new WP_REST_Response(['success' => false, 'message' => 'Upload error'], 400);
            }
            if ((int) $f['size'] > 8 * 1024 * 1024) {
                return new WP_REST_Response(['success' => false, 'message' => 'Max file size is 8 MB'], 400);
            }
            $type = wp_check_filetype($f['name']);
            if (strpos((string) $type['type'], 'image/') !== 0) {
                return new WP_REST_Response(['success' => false, 'message' => 'Images only'], 400);
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attach_id = media_handle_upload('file', 0);
            if (is_wp_error($attach_id)) {
                return new WP_REST_Response(['success' => false, 'message' => $attach_id->get_error_message()], 500);
            }

            return new WP_REST_Response([
                'success'       => true,
                'attachment_id' => (int) $attach_id,
                'url'           => wp_get_attachment_url($attach_id),
                'folder_id'     => 0,
            ], 200);
        }
    }

    /* -------------------------------------------------------------------------
     * GET /coupon/validate  — codes live in the `sb_coupons` option:
     *   [ 'WELCOME10' => ['type'=>'percentage','value'=>10],
     *     'SAVE500'   => ['type'=>'fixed','value'=>500] ]
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_coupon_validate')) {
        function sb_coupon_validate(WP_REST_Request $req) {
            $code   = strtoupper(trim((string) $req->get_param('code')));
            $amount = (float) $req->get_param('amount');
            $coupons = get_option('sb_coupons', []);
            if (!is_array($coupons)) $coupons = [];
            // Normalise keys to uppercase for case-insensitive matching.
            $upper = [];
            foreach ($coupons as $k => $v) $upper[strtoupper((string) $k)] = $v;

            if ($code === '' || !isset($upper[$code])) {
                return new WP_REST_Response(['valid' => false, 'message' => 'Invalid or expired coupon.'], 200);
            }
            $c = $upper[$code];
            $type  = ($c['type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
            $value = (float) ($c['value'] ?? 0);
            if ($amount <= 0) {
                return new WP_REST_Response(['valid' => false, 'message' => 'Pick a paid plan first.'], 200);
            }
            $discount = $type === 'percentage'
                ? floor($amount * $value / 100)
                : min($value, $amount);
            return new WP_REST_Response([
                'valid'     => true,
                'code'      => $code,
                'type'      => $type,
                'value'     => $value,
                'discount'  => (float) $discount,
                'new_total' => (float) max(0, $amount - $discount),
            ], 200);
        }
    }

    /* -------------------------------------------------------------------------
     * POST /submit-listing  — create a pending accommodation from the form.
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_submit_field')) {
        // update_field if ACF present, else raw meta — keeps it resilient.
        function sb_submit_field($name, $value, $post_id) {
            if (function_exists('update_field')) {
                update_field($name, $value, $post_id);
            } else {
                update_post_meta($post_id, $name, $value);
            }
        }
    }

    if (!function_exists('sb_submit_listing')) {
        function sb_submit_listing(WP_REST_Request $req) {
            $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
            if (!$user) {
                return new WP_REST_Response(['success' => false, 'message' => 'Please sign in as a host first.'], 401);
            }

            $d = $req->get_json_params();
            if (!is_array($d)) $d = [];

            $title = sanitize_text_field($d['propertyName'] ?? '');
            if ($title === '') {
                return new WP_REST_Response(['success' => false, 'message' => 'Property name is required.'], 400);
            }

            $tiers = sb_tiers();
            $tier  = isset($d['tier'], $tiers[$d['tier']]) ? $d['tier'] : 'free';

            // Create the post as pending review (admin approves → publish).
            $post_id = wp_insert_post([
                'post_type'    => 'accommodation',
                'post_status'  => 'pending',
                'post_title'   => $title,
                'post_content' => wp_kses_post($d['longDescription'] ?? ''),
                'post_author'  => (int) $user->ID,
            ], true);
            if (is_wp_error($post_id)) {
                return new WP_REST_Response(['success' => false, 'message' => 'Could not create listing.'], 500);
            }

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
            sb_submit_field('address_1', sanitize_text_field($d['address1'] ?? ''), $post_id);

            // External links
            sb_submit_field('accommodation_booking_link', esc_url_raw($d['bookingLink'] ?? ''), $post_id);
            sb_submit_field('airbnb_booking_link', esc_url_raw($d['airbnbLink'] ?? ''), $post_id);
            sb_submit_field('accommodation_bookingcom_link', esc_url_raw($d['bookingComLink'] ?? ''), $post_id);
            sb_submit_field('accommodation_website_link', esc_url_raw($d['websiteLink'] ?? ''), $post_id);
            sb_submit_field('facebook_page_link', esc_url_raw($d['facebookLink'] ?? ''), $post_id);
            sb_submit_field('instagram_link', esc_url_raw($d['instagramLink'] ?? ''), $post_id);

            // ── Pin location (ACF google_map) ──
            if (!empty($d['mapLocation']) && is_array($d['mapLocation']) && isset($d['mapLocation']['lat'], $d['mapLocation']['lng'])) {
                sb_submit_field('accommodation_location', [
                    'lat'     => (float) $d['mapLocation']['lat'],
                    'lng'     => (float) $d['mapLocation']['lng'],
                    'address' => sanitize_text_field($d['mapLocation']['address'] ?? ($d['address1'] ?? '')),
                ], $post_id);
            }

            // ── FAQ amenities group (booleans) ──
            if (!empty($d['faqAmenities']) && is_array($d['faqAmenities'])) {
                $faq = [];
                foreach ($d['faqAmenities'] as $k => $v) {
                    $faq[sanitize_key($k)] = $v ? 1 : 0;
                }
                // Generator answer also lives in the FAQ group on limasawa.
                if (isset($d['hasGenerator'])) $faq['faq_generator'] = $d['hasGenerator'] ? 1 : 0;
                sb_submit_field('faq_amenities', $faq, $post_id);
            }
            if (!empty($d['generatorSchedule'])) {
                sb_submit_field('generator_schedule', wp_kses_post($d['generatorSchedule']), $post_id);
            }

            // ── Services repeater ──
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
            }

            // ── Host photo + gallery (attachment IDs from /upload-media) ──
            if (!empty($d['hostPhotoId'])) {
                sb_submit_field('accommodation_host_picture', (int) $d['hostPhotoId'], $post_id);
            }
            if (!empty($d['galleryIds']) && is_array($d['galleryIds'])) {
                $ids = array_values(array_filter(array_map('intval', $d['galleryIds'])));
                $cap = (int) ($tiers[$tier]['photos'] ?? 5);
                if (count($ids) > $cap) $ids = array_slice($ids, 0, $cap);
                if ($ids) {
                    sb_submit_field('accommodation_gallery', $ids, $post_id);
                    set_post_thumbnail($post_id, $ids[0]); // first photo = featured image
                }
            }

            // ── Taxonomies: category, barangay (location), amenities ──
            $cat = sanitize_title($d['accommodationCategory'] ?? '');
            if ($cat !== '') {
                $term = get_term_by('slug', $cat, 'accommodation-category') ?: get_term_by('name', $d['accommodationCategory'], 'accommodation-category');
                if ($term) wp_set_object_terms($post_id, [(int) $term->term_id], 'accommodation-category');
            }
            $barangayId = (int) ($d['barangayId'] ?? 0);
            if ($barangayId > 0) {
                wp_set_object_terms($post_id, [$barangayId], 'location');
            }
            if (!empty($d['extraAmenities']) && is_array($d['extraAmenities'])) {
                $amenIds = [];
                foreach ($d['extraAmenities'] as $a) {
                    $t = get_term_by('slug', sanitize_title($a), 'accommodation-amenity');
                    if ($t) $amenIds[] = (int) $t->term_id;
                }
                if ($amenIds) wp_set_object_terms($post_id, $amenIds, 'accommodation-amenity');
            }

            // ── Tier + payment record (meta) ──
            update_post_meta($post_id, 'listing_tier', $tier);
            update_post_meta($post_id, 'sb_payment', [
                'tier'          => $tier,
                'billingPeriod' => sanitize_text_field($d['billingPeriod'] ?? 'monthly'),
                'amount'        => (float) ($d['paymentAmount'] ?? 0),
                'method'        => sanitize_text_field($d['paymentMethod'] ?? ''),
                'reference'     => sanitize_text_field($d['paymentReference'] ?? ''),
                'receiptId'     => (int) ($d['paymentReceiptId'] ?? 0),
                'couponCode'    => sanitize_text_field($d['couponCode'] ?? ''),
                'status'        => $tier === 'free' ? 'not_required' : 'pending_review',
                'submittedAt'   => current_time('mysql'),
            ]);

            return new WP_REST_Response([
                'success' => true,
                'id'      => (int) $post_id,
                'slug'    => get_post_field('post_name', $post_id),
                'status'  => 'pending',
            ], 201);
        }
    }
}
