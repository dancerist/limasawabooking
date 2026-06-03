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
            // Mirrors the form's TIER_PRICES (free / pro / featured).
            return [
                'free'     => ['photos' => 5,    'monthly' => 0,   'yearly' => 0],
                'pro'      => ['photos' => 15,   'monthly' => 399, 'yearly' => 3990],
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
            $code    = (string) $req->get_param('code');
            $tier    = (string) ($req->get_param('tier') ?: 'pro');
            $billing = (string) ($req->get_param('billing') ?: 'monthly');
            $amount  = (float) $req->get_param('amount');
            // Prefer the table-backed validator (limasawa-coupons.php).
            if (function_exists('sb_validate_and_apply_coupon')) {
                return new WP_REST_Response(sb_validate_and_apply_coupon($code, $tier, $billing, $amount), 200);
            }
            // Fallback: legacy sb_coupons option map.
            $code = strtoupper(trim($code));
            $coupons = get_option('sb_coupons', []);
            if (!is_array($coupons)) $coupons = [];
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
            $discount = $type === 'percentage' ? floor($amount * $value / 100) : min($value, $amount);
            return new WP_REST_Response(['valid' => true, 'code' => $code, 'type' => $type, 'value' => $value, 'discount' => (float) $discount, 'new_total' => (float) max(0, $amount - $discount)], 200);
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

            // ── ACF + taxonomy fields ──
            // Shared writer (limasawa-edit.php) — single source of truth for the
            // form→ACF mapping; also used by POST /update-listing. Tier here only
            // bounds the gallery photo cap.
            sb_apply_listing_fields($post_id, $d, $tier);

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

    /* =========================================================================
     * Subscription renewal / upgrade (Phase 4) — manual e-wallet model.
     * Paid upgrades are recorded as pending_review; the listing's tier only
     * changes once an admin approves the payment (Phase 6). Free is immediate.
     * ====================================================================== */
    add_action('rest_api_init', function () {
        register_rest_route('limasawa/v1', '/renew-subscription', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_renew_subscription',
        ]);
    });

    if (!function_exists('sb_coupon_discount')) {
        function sb_coupon_discount($code, $amount, $tier = 'pro', $billing = 'monthly') {
            // Prefer the table-backed validator (limasawa-coupons.php).
            if (function_exists('sb_validate_and_apply_coupon')) {
                $r = sb_validate_and_apply_coupon($code, $tier, $billing, $amount);
                if (!empty($r['valid'])) {
                    return ['code' => $r['code'], 'discount' => (float) $r['discount'], 'id' => (int) ($r['id'] ?? 0), 'bonus_months' => (int) ($r['bonus_months'] ?? 0)];
                }
                return ['code' => '', 'discount' => 0.0, 'id' => 0, 'bonus_months' => 0];
            }
            // Fallback: legacy option map.
            $code = strtoupper(trim((string) $code));
            if ($code === '' || $amount <= 0) return ['code' => '', 'discount' => 0.0, 'id' => 0, 'bonus_months' => 0];
            $coupons = get_option('sb_coupons', []);
            if (!is_array($coupons)) return ['code' => '', 'discount' => 0.0, 'id' => 0, 'bonus_months' => 0];
            $upper = [];
            foreach ($coupons as $k => $v) $upper[strtoupper((string) $k)] = $v;
            if (!isset($upper[$code])) return ['code' => '', 'discount' => 0.0, 'id' => 0, 'bonus_months' => 0];
            $c    = $upper[$code];
            $type = ($c['type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
            $val  = (float) ($c['value'] ?? 0);
            $disc = $type === 'percentage' ? floor($amount * $val / 100) : min($val, $amount);
            return ['code' => $code, 'discount' => (float) $disc, 'id' => 0, 'bonus_months' => 0];
        }
    }

    if (!function_exists('sb_renew_subscription')) {
        function sb_renew_subscription(WP_REST_Request $req) {
            $user = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
            if (!$user) return new WP_REST_Response(['success' => false, 'message' => 'Please sign in.'], 401);

            $id   = (int) $req->get_param('listing_id');
            $post = $id ? get_post($id) : null;
            if (!$post || $post->post_type !== 'accommodation') {
                return new WP_REST_Response(['success' => false, 'message' => 'Listing not found.'], 404);
            }
            if ((int) $post->post_author !== (int) $user->ID) {
                return new WP_REST_Response(['success' => false, 'message' => 'Not your listing.'], 403);
            }

            $tiers = sb_tiers();
            $tier  = (string) $req->get_param('tier');
            if (!isset($tiers[$tier])) {
                return new WP_REST_Response(['success' => false, 'message' => 'Invalid plan.'], 400);
            }
            $billing = $req->get_param('billing_period') === 'yearly' ? 'yearly' : 'monthly';

            if ($tier === 'free') {
                update_post_meta($id, 'listing_tier', 'free');
                update_post_meta($id, 'sb_payment', [
                    'tier' => 'free', 'billingPeriod' => $billing, 'amount' => 0, 'method' => '',
                    'reference' => '', 'receiptId' => 0, 'couponCode' => '', 'status' => 'not_required',
                    'paidAt' => '', 'expiresAt' => '', 'submittedAt' => current_time('mysql'),
                ]);
                return new WP_REST_Response(['success' => true, 'message' => 'Switched to the free plan.'], 200);
            }

            // Paid: server computes the base price from tier config (anti-tamper),
            // re-validates the coupon, and records the payment for admin review.
            $base   = (float) ($tiers[$tier][$billing] ?? 0);
            $coupon = sb_coupon_discount($req->get_param('coupon_code'), $base, $tier, $billing);
            $final  = max(0, $base - $coupon['discount']);

            update_post_meta($id, 'sb_payment', [
                'tier'          => $tier,
                'billingPeriod' => $billing,
                'amount'        => $final,
                'method'        => sanitize_text_field($req->get_param('payment_method')),
                'reference'     => sanitize_text_field($req->get_param('payment_reference')),
                'receiptId'     => (int) $req->get_param('payment_receipt_id'),
                'couponCode'    => $coupon['code'],
                'couponId'      => (int) ($coupon['id'] ?? 0),
                'bonusMonths'   => (int) ($coupon['bonus_months'] ?? 0),
                'status'        => 'pending_review',
                'paidAt'        => '',
                'expiresAt'     => '',
                'submittedAt'   => current_time('mysql'),
            ]);
            // listing_tier is NOT upgraded until an admin approves the payment (Phase 6).
            return new WP_REST_Response(['success' => true, 'message' => 'Payment submitted — your upgrade goes live once we confirm it.'], 200);
        }
    }
}
