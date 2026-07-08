<?php
/**
 * Limasawa Booking — Listing Claims (ported from siargao sb-claims.php).
 *
 * Hosts claim an existing admin-imported listing; admin approves → post_author
 * is transferred to the claimant. Custom table wp_sb_claims. Uses limasawa's
 * JWT (sb_jwt_current_user) instead of siargao's Firebase JWT, and limasawa
 * meta keys (sb_profile_picture_id).
 *
 * Routes (limasawa/v1): POST /claim-listing, GET /my-claims.
 * Admin: admin_post sb_approve_claim / sb_reject_claim.
 * sb_claims_render_pending_section() is rendered inside the CMS dashboard widget.
 */
if (!defined('ABSPATH')) exit;

/* ---- Table (created once, flagged by an option) ---------------------- */
if (!function_exists('sb_claims_ensure_table')) {
    function sb_claims_ensure_table() {
        if (get_option('sb_claims_table_v1')) return;
        global $wpdb;
        $t = $wpdb->prefix . 'sb_claims';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT UNSIGNED NOT NULL,
            claimant_user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            claimant_phone VARCHAR(40) NOT NULL DEFAULT '',
            proof_url TEXT NULL,
            message TEXT NULL,
            decision_note TEXT NULL,
            reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY claimant (claimant_user_id),
            KEY listing (listing_id),
            KEY status (status)
        ) {$charset};");
        update_option('sb_claims_table_v1', 1, false);
    }
    add_action('init', 'sb_claims_ensure_table');
}

if (!function_exists('sb_claims_uid')) {
    function sb_claims_uid(WP_REST_Request $req) {
        $u = function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        return $u ? (int) $u->ID : 0;
    }
}

add_action('rest_api_init', function () {
    register_rest_route('limasawa/v1', '/claim-listing', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_rest_claim_listing']);
    register_rest_route('limasawa/v1', '/my-claims', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_rest_my_claims']);
});

if (!function_exists('sb_rest_my_claims')) {
    function sb_rest_my_claims(WP_REST_Request $req) {
        global $wpdb;
        $uid = sb_claims_uid($req);
        if (!$uid) return new WP_Error('unauthorized', 'Sign in required.', ['status' => 401]);
        $t = $wpdb->prefix . 'sb_claims';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, listing_id, status, created_at FROM {$t} WHERE claimant_user_id = %d ORDER BY created_at DESC LIMIT 50", $uid), ARRAY_A);
        return rest_ensure_response(['claims' => $rows ?: []]);
    }
}

if (!function_exists('sb_claims_phones_match')) {
    function sb_claims_phones_match($a, $b) {
        $norm = function ($s) {
            $d = preg_replace('/\D+/', '', (string) $s);
            if (strpos($d, '63') === 0) $d = substr($d, 2);
            if (strpos($d, '0') === 0) $d = substr($d, 1);
            return $d;
        };
        $na = $norm($a); $nb = $norm($b);
        return $na && $nb && $na === $nb;
    }
}

if (!function_exists('sb_rest_claim_listing')) {
    function sb_rest_claim_listing(WP_REST_Request $req) {
        global $wpdb;
        $uid = sb_claims_uid($req);
        if (!$uid) return new WP_Error('unauthorized', 'Please sign in before claiming a listing.', ['status' => 401]);

        $b = $req->get_json_params(); if (!is_array($b)) $b = [];
        $listing_id = (int) ($b['listing_id'] ?? 0);
        $phone = sanitize_text_field($b['phone'] ?? '');
        $message = sanitize_textarea_field($b['message'] ?? '');

        $listing = $listing_id ? get_post($listing_id) : null;
        if (!$listing || $listing->post_type !== 'accommodation') return new WP_Error('not_found', 'Listing not found.', ['status' => 404]);
        if ((int) $listing->post_author === $uid) return new WP_Error('already_owner', 'You are already the host of this property.', ['status' => 409]);
        $author = get_userdata((int) $listing->post_author);
        $admin_owned = $author && array_intersect(['administrator', 'editor'], (array) $author->roles);
        if (!$admin_owned) return new WP_Error('not_claimable', 'This listing is already managed by a host. If that is wrong, email us.', ['status' => 409]);
        if (!$phone || strlen($phone) < 7) return new WP_Error('missing_phone', 'A WhatsApp or phone number is required.', ['status' => 400]);

        $t = $wpdb->prefix . 'sb_claims';
        $dupe = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE listing_id = %d AND claimant_user_id = %d AND status = 'pending'", $listing_id, $uid));
        if ($dupe) return new WP_Error('duplicate', 'You already have a pending claim on this listing. We review within 24 hours.', ['status' => 409]);

        $ok = $wpdb->insert($t, [
            'listing_id' => $listing_id, 'claimant_user_id' => $uid, 'status' => 'pending',
            'claimant_phone' => $phone, 'proof_url' => '', 'message' => $message, 'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
        if (!$ok) return new WP_Error('db_error', 'Could not save your claim. Please try again.', ['status' => 500]);
        $cid = (int) $wpdb->insert_id;

        $stored = (string) (function_exists('get_field') ? get_field('accommodation_host_contact_number', $listing_id) : get_post_meta($listing_id, 'accommodation_host_contact_number', true));
        $match = sb_claims_phones_match($phone, $stored);
        sb_claim_email_admin_new($cid, $listing, $uid, $phone, $message, $match);
        sb_claim_email_claimant_received($listing, $uid);

        return rest_ensure_response(['success' => true, 'claim_id' => $cid, 'message' => 'Claim submitted — we will review within 24 hours.', 'phone_match' => $match]);
    }
}

/* ---- Emails (best-effort) -------------------------------------------- */
if (!function_exists('sb_claim_email_admin_new')) {
    function sb_claim_email_admin_new($cid, $listing, $uid, $phone, $message, $match) {
        $admin = get_option('admin_email'); if (!$admin) return;
        $u = get_userdata($uid); $name = $u ? $u->display_name : 'Unknown'; $email = $u ? $u->user_email : '';
        $site = get_bloginfo('name');
        $body = "A host submitted a claim for an existing listing.\n\nListing: {$listing->post_title}\n" . get_permalink($listing->ID) . "\nClaimant: {$name} <{$email}> (user #{$uid})\nPhone: {$phone}" . ($match ? " (matches stored host phone)" : " (no match)") . "\n";
        if ($message) $body .= "\nMessage:\n{$message}\n";
        $body .= "\nReview in the Limasawa CMS dashboard.\n";
        wp_mail($admin, "[{$site}] New listing claim: {$listing->post_title}", $body);
    }
}
if (!function_exists('sb_claim_email_claimant_received')) {
    function sb_claim_email_claimant_received($listing, $uid) {
        $u = get_userdata($uid); if (!$u || !$u->user_email) return;
        $site = get_bloginfo('name');
        wp_mail($u->user_email, "We received your claim for {$listing->post_title}", "Hi {$u->display_name},\n\nThanks — we got your claim for:\n\n    {$listing->post_title}\n\nWe'll review within 24 hours.\n\nWarm regards,\nThe {$site} team");
    }
}
if (!function_exists('sb_claim_email_claimant_approved')) {
    function sb_claim_email_claimant_approved($listing, $uid) {
        $u = get_userdata($uid); if (!$u || !$u->user_email) return;
        $site = get_bloginfo('name'); $dash = home_url('/dashboard');
        wp_mail($u->user_email, "Your listing is yours: {$listing->post_title}", "Hi {$u->display_name},\n\nWe've verified your claim — {$listing->post_title} is now linked to your account. Manage it any time:\n\n    {$dash}\n\nWarm regards,\nThe {$site} team");
    }
}
if (!function_exists('sb_claim_email_claimant_rejected')) {
    function sb_claim_email_claimant_rejected($listing, $uid, $note) {
        $u = get_userdata($uid); if (!$u || !$u->user_email) return;
        $site = get_bloginfo('name'); $reply = get_option('admin_email');
        $body = "Hi {$u->display_name},\n\nThanks for your claim for {$listing->post_title}. We couldn't verify it with the info provided.\n";
        if ($note) $body .= "\nNotes:\n{$note}\n";
        $body .= "\nReply to this email with a platform link (Airbnb/Booking/Facebook) showing the same phone, or property paperwork, and we'll try again.\n\nReach us at: {$reply}\n\nWarm regards,\nThe {$site} team";
        wp_mail($u->user_email, "About your claim for {$listing->post_title}", $body);
    }
}

/* ---- Admin approve / reject ------------------------------------------ */
add_action('admin_post_sb_approve_claim', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorised');
    $cid = (int) ($_GET['claim_id'] ?? 0);
    if (!$cid || !check_admin_referer('sb_approve_claim_' . $cid)) wp_die('Bad request');
    global $wpdb; $t = $wpdb->prefix . 'sb_claims';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $cid));
    if (!$row || $row->status !== 'pending') { wp_safe_redirect(add_query_arg('sb_msg', 'claim_already', admin_url('index.php'))); exit; }
    $listing = get_post((int) $row->listing_id);
    if (!$listing) { wp_safe_redirect(add_query_arg('sb_msg', 'claim_listing_gone', admin_url('index.php'))); exit; }
    $claimant = (int) $row->claimant_user_id;
    update_post_meta($listing->ID, 'sb_listing_original_author', (int) $listing->post_author);
    wp_update_post(['ID' => $listing->ID, 'post_author' => $claimant]);
    // Promote the claimant to host so they get the host dashboard (My Listings,
    // edit, analytics). sb_user_role() reads the WP role and never derives host
    // from ownership, so a guest who claims a listing stays a guest without this.
    // Leave admins/editors/existing hosts untouched.
    $cu = get_userdata($claimant);
    if ($cu && !array_intersect(['host', 'administrator', 'editor'], (array) $cu->roles)) {
        $cu->set_role('host');
    }
    // Seed claimant avatar from the listing host picture if they have none.
    if (!(int) get_user_meta($claimant, 'sb_profile_picture_id', true)) {
        $hp = function_exists('get_field') ? get_field('accommodation_host_picture', $listing->ID) : 0;
        $att = is_array($hp) ? (int) ($hp['ID'] ?? $hp['id'] ?? 0) : (int) $hp;
        if ($att && get_post_type($att) === 'attachment') update_user_meta($claimant, 'sb_profile_picture_id', $att);
    }
    $wpdb->update($t, ['status' => 'approved', 'reviewed_at' => current_time('mysql'), 'reviewed_by' => get_current_user_id()], ['id' => $cid], ['%s', '%s', '%d'], ['%d']);
    sb_claim_email_claimant_approved($listing, $claimant);
    wp_safe_redirect(add_query_arg('sb_msg', 'claim_approved', admin_url('index.php'))); exit;
});
add_action('admin_post_sb_reject_claim', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorised');
    $cid = (int) ($_GET['claim_id'] ?? 0);
    if (!$cid || !check_admin_referer('sb_reject_claim_' . $cid)) wp_die('Bad request');
    global $wpdb; $t = $wpdb->prefix . 'sb_claims';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $cid));
    if (!$row || $row->status !== 'pending') { wp_safe_redirect(add_query_arg('sb_msg', 'claim_already', admin_url('index.php'))); exit; }
    $listing = get_post((int) $row->listing_id);
    $note = isset($_GET['note']) ? sanitize_text_field($_GET['note']) : '';
    $wpdb->update($t, ['status' => 'rejected', 'decision_note' => $note, 'reviewed_at' => current_time('mysql'), 'reviewed_by' => get_current_user_id()], ['id' => $cid], ['%s', '%s', '%s', '%d'], ['%d']);
    if ($listing) sb_claim_email_claimant_rejected($listing, (int) $row->claimant_user_id, $note);
    wp_safe_redirect(add_query_arg('sb_msg', 'claim_rejected', admin_url('index.php'))); exit;
});

/* ---- Pending-claims table (rendered inside the CMS dashboard widget) -- */
if (!function_exists('sb_claims_render_pending_section')) {
    function sb_claims_render_pending_section() {
        global $wpdb; $t = $wpdb->prefix . 'sb_claims';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return;
        $rows = $wpdb->get_results("SELECT c.*, p.post_title, u.display_name AS claimant_name, u.user_email AS claimant_email FROM {$t} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.listing_id LEFT JOIN {$wpdb->users} u ON u.ID = c.claimant_user_id WHERE c.status = 'pending' ORDER BY c.created_at DESC LIMIT 12");
        if (!$rows) return;
        $icon = function_exists('sb_admin_icon') ? 'sb_admin_icon' : null;
        echo '<div class="sb-section"><div class="sb-sh">' . ($icon ? sb_admin_icon('users') : '') . 'Listing claims to verify <span class="count">' . count($rows) . '</span></div>';
        echo '<table><thead><tr><th>Listing</th><th>Claimant</th><th>Phone</th><th>Submitted</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $stored = (string) (function_exists('get_field') ? get_field('accommodation_host_contact_number', $r->listing_id) : '');
            $ok = sb_claims_phones_match($r->claimant_phone, $stored);
            $approve = wp_nonce_url(admin_url('admin-post.php?action=sb_approve_claim&claim_id=' . (int) $r->id), 'sb_approve_claim_' . $r->id);
            $reject = wp_nonce_url(admin_url('admin-post.php?action=sb_reject_claim&claim_id=' . (int) $r->id), 'sb_reject_claim_' . $r->id);
            echo '<tr><td><a class="lnk" href="' . esc_url(get_edit_post_link($r->listing_id)) . '">' . esc_html($r->post_title ?: ('#' . $r->listing_id)) . '</a></td>'
               . '<td>' . esc_html($r->claimant_name ?: ('user #' . $r->claimant_user_id)) . '<div style="color:#94a3b8;font-size:.7rem">' . esc_html($r->claimant_email) . '</div></td>'
               . '<td><span class="pill ' . ($ok ? 'p-ok' : 'p-warn') . '">' . esc_html($r->claimant_phone) . '</span>' . ($ok ? ' match' : '') . '</td>'
               . '<td style="color:#94a3b8;white-space:nowrap">' . esc_html(human_time_diff(strtotime($r->created_at), current_time('timestamp')) . ' ago') . '</td>'
               . '<td><a class="button button-small button-primary" href="' . esc_url($approve) . '" onclick="return confirm(&#39;Transfer ownership to claimant?&#39;)">Approve</a> <a class="button button-small" href="' . esc_url($reject) . '" onclick="return confirm(&#39;Reject this claim?&#39;)">Reject</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
