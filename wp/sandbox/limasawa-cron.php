<?php
/**
 * Limasawa Booking — Subscription lifecycle cron (ported from siargao's
 * sb-dashboard-widget.php lifecycle block, adapted to limasawa's sb_payment array).
 *
 * Daily `sb_subscription_check`:
 *   1. Paid listings past their expiry + 3-day grace → downgrade to free
 *      (sb_payment.status = 'expired', listing_tier = 'free'); listing stays
 *      published. Sends a lapse email.
 *   2. Paid listings expiring within 7 days → one warning email (transient-guarded).
 *
 * Manual e-wallet model: nothing is charged; downgrade just removes paid-tier
 * perks. A renewal (/renew-subscription) + admin approval (limasawa-admin.php)
 * sets sb_payment.status back to 'paid' with a fresh expiresAt.
 */
if (!defined('ABSPATH')) exit;

if (!wp_next_scheduled('sb_subscription_check')) {
    wp_schedule_event(time(), 'daily', 'sb_subscription_check');
}
add_action('sb_subscription_check', 'sb_run_subscription_check');

if (!function_exists('sb_run_subscription_check')) {
    /** @param bool $dry  When true, only report what WOULD change (no writes, no emails). */
    function sb_run_subscription_check($dry = false) {
        $ids = get_posts(['post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true]);
        $now = current_time('timestamp');
        $grace = 3 * DAY_IN_SECONDS;
        $warn_window = 7 * DAY_IN_SECONDS;
        $report = ['checked' => 0, 'expired' => [], 'warned' => []];

        foreach ($ids as $id) {
            $pay = get_post_meta($id, 'sb_payment', true);
            if (!is_array($pay) || ($pay['status'] ?? '') !== 'paid' || empty($pay['expiresAt'])) continue;
            $report['checked']++;
            $exp = strtotime($pay['expiresAt']);
            if (!$exp) continue;

            if ($exp < ($now - $grace)) {
                // Past expiry + grace → downgrade to free (stays published).
                $report['expired'][] = $id;
                if (!$dry) {
                    $pay['status'] = 'expired';
                    update_post_meta($id, 'sb_payment', $pay);
                    update_post_meta($id, 'listing_tier', 'free');
                    sb_cron_email_expired($id, $pay['expiresAt']);
                }
            } elseif ($exp > $now && $exp < ($now + $warn_window)) {
                // Expiring soon → one warning per ~8 days.
                $flag = 'sb_warn_sent_' . $id;
                if (!get_transient($flag)) {
                    $report['warned'][] = $id;
                    if (!$dry) {
                        sb_cron_email_warning($id, $pay['expiresAt']);
                        set_transient($flag, 1, 8 * DAY_IN_SECONDS);
                    }
                }
            }
        }
        return $report;
    }
}

if (!function_exists('sb_cron_email_warning')) {
    function sb_cron_email_warning($listing_id, $expires_at) {
        $author = (int) get_post_field('post_author', $listing_id);
        $user = $author ? get_userdata($author) : null;
        if (!$user || !$user->user_email) return;
        $site = get_bloginfo('name');
        $title = get_the_title($listing_id);
        $dash = home_url('/dashboard');
        $days = max(1, (int) ceil((strtotime($expires_at) - current_time('timestamp')) / DAY_IN_SECONDS));
        $expires_fmt = date('F j, Y', strtotime($expires_at));
        $plural = $days === 1 ? '' : 's';
        $subject = "Heads up — your {$site} subscription renews in {$days} day{$plural}";
        $body  = "Hi {$user->display_name},\n\n";
        $body .= "Your subscription for\n\n    {$title}\n\n";
        $body .= "is due for renewal on {$expires_fmt} ({$days} day{$plural} from now).\n\n";
        $body .= "No panic — if you don't renew, your listing stays visible; it simply moves to the free plan.\n\n";
        $body .= "To keep your current features (WhatsApp clicks, platform links, advanced stats), log in and submit a renewal before {$expires_fmt}:\n\n    {$dash}\n\n";
        $body .= "Payment via GCash, Maya, or BPI — we verify within 24 hours.\n\nWarm regards,\nThe {$site} team";
        wp_mail($user->user_email, $subject, $body);
    }
}

if (!function_exists('sb_cron_email_expired')) {
    function sb_cron_email_expired($listing_id, $expires_at) {
        $author = (int) get_post_field('post_author', $listing_id);
        $user = $author ? get_userdata($author) : null;
        $site = get_bloginfo('name');
        $title = get_the_title($listing_id);
        $dash = home_url('/dashboard');
        $expires_fmt = date('F j, Y', strtotime($expires_at));
        if ($user && $user->user_email) {
            $subject = "Your listing is still live — a note about your subscription";
            $body  = "Hi {$user->display_name},\n\n";
            $body .= "Your paid subscription for\n\n    {$title}\n\n";
            $body .= "expired on {$expires_fmt}, so your listing has moved to the free plan.\n\n";
            $body .= "The good news: it's still live and visible in our directory — travelers can still find you and reach out.\n\n";
            $body .= "Whenever you're ready to upgrade again, just head to your dashboard and pick a plan. Your full stats and links are restored as soon as payment clears:\n\n    {$dash}\n\nWarm regards,\nThe {$site} team";
            wp_mail($user->user_email, $subject, $body);
        }
        // Quiet admin note (expected, not an alarm).
        wp_mail(get_option('admin_email'), "[{$site}] Subscription lapsed → free: {$title}", "Listing '{$title}' (ID {$listing_id}) lapsed on {$expires_fmt}. Still published on the free tier; host notified.");
    }
}
