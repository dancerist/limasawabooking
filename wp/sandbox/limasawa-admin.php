<?php
/**
 * Limasawa Booking — WP-admin CMS side (Phase 6)
 *
 * Server-rendered admin UI (no Astro). Auto-loaded sandbox file.
 *  - Dashboard "Overview" widget: listing counts + pending-payment / pending-
 *    listing queues with one-click actions.
 *  - approve-payment → mark sb_payment paid + set paidAt/expiresAt + apply
 *    listing_tier + publish. THIS turns a pending paid submission into a live
 *    paid listing (completes the /renew-subscription + /submit-listing loop).
 *  - publish-listing → publish a pending free listing.
 *  - Admin columns on the accommodation list: tier / payment / host.
 *
 * Capability gate: edit_others_posts (editors + admins).
 */

if (defined('ABSPATH')) {

    /* ---- Core approve/publish logic (reused by handlers + tests) ---------- */
    if (!function_exists('sb_admin_do_approve')) {
        function sb_admin_do_approve($id) {
            $id = (int) $id;
            if (get_post_type($id) !== 'accommodation') return ['ok' => false, 'error' => 'not_accommodation'];
            $pay = get_post_meta($id, 'sb_payment', true);
            if (!is_array($pay)) $pay = [];
            $billing = ($pay['billingPeriod'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
            $days = $billing === 'yearly' ? 365 : 30;
            $pay['status']    = 'paid';
            $pay['paidAt']    = current_time('mysql');
            $pay['expiresAt'] = date('Y-m-d H:i:s', current_time('timestamp') + $days * DAY_IN_SECONDS);
            update_post_meta($id, 'sb_payment', $pay);
            if (!empty($pay['tier'])) update_post_meta($id, 'listing_tier', $pay['tier']);
            if (in_array(get_post_status($id), ['pending', 'draft'], true)) {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            }
            return ['ok' => true, 'tier' => $pay['tier'] ?? 'free', 'expiresAt' => $pay['expiresAt']];
        }
    }
    if (!function_exists('sb_admin_do_publish')) {
        function sb_admin_do_publish($id) {
            $id = (int) $id;
            if (get_post_type($id) !== 'accommodation') return ['ok' => false];
            if (in_array(get_post_status($id), ['pending', 'draft'], true)) {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            }
            return ['ok' => true];
        }
    }

    /* ---- admin-post handlers --------------------------------------------- */
    if (!function_exists('sb_admin_approve_payment_handler')) {
        function sb_admin_approve_payment_handler() {
            if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
            $id = (int) ($_POST['listing_id'] ?? 0);
            check_admin_referer('sb_approve_' . $id);
            sb_admin_do_approve($id);
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        }
        add_action('admin_post_sb_approve_payment', 'sb_admin_approve_payment_handler');
    }
    if (!function_exists('sb_admin_publish_listing_handler')) {
        function sb_admin_publish_listing_handler() {
            if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
            $id = (int) ($_POST['listing_id'] ?? 0);
            check_admin_referer('sb_publish_' . $id);
            sb_admin_do_publish($id);
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        }
        add_action('admin_post_sb_publish_listing', 'sb_admin_publish_listing_handler');
    }

    /* ---- helpers --------------------------------------------------------- */
    if (!function_exists('sb_admin_pay_status_count')) {
        function sb_admin_pay_status_count($status) {
            $q = new WP_Query([
                'post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
                'meta_query' => [['key' => 'sb_payment', 'value' => '"status";s:' . strlen($status) . ':"' . $status . '"', 'compare' => 'LIKE']],
            ]);
            return (int) $q->found_posts;
        }
    }
    if (!function_exists('sb_admin_views_window')) {
        function sb_admin_views_window($days) {
            $ids = get_posts(['post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 300, 'fields' => 'ids']);
            $start = strtotime('today', current_time('timestamp')) - ($days - 1) * DAY_IN_SECONDS;
            $total = 0;
            foreach ($ids as $pid) {
                $daily = get_post_meta($pid, 'sb_stats_daily', true);
                if (!is_array($daily)) continue;
                foreach ($daily as $d => $row) {
                    if (strtotime($d) >= $start && is_array($row)) $total += (int) ($row['views'] ?? 0);
                }
            }
            return $total;
        }
    }

    /* ---- Dashboard widget ------------------------------------------------ */
    if (!function_exists('sb_admin_dashboard_widget')) {
        function sb_admin_dashboard_widget() {
            $counts = wp_count_posts('accommodation');
            $live    = (int) ($counts->publish ?? 0);
            $pending = (int) ($counts->pending ?? 0);
            $draft   = (int) ($counts->draft ?? 0);
            $total   = $live + $pending + $draft;
            $payPend = sb_admin_pay_status_count('pending_review');
            $paid    = sb_admin_pay_status_count('paid');

            $stats = [
                ['Views (7d)', sb_admin_views_window(7)],
                ['Views (30d)', sb_admin_views_window(30)],
                ['Live', $live],
                ['Pending review', $pending],
                ['Pending payment', $payPend],
                ['Paid', $paid],
                ['Total listings', $total],
            ];
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:14px">';
            foreach ($stats as $s) {
                echo '<div style="background:#f6f7f7;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px">'
                   . '<div style="font-size:22px;font-weight:700;color:#1d2327">' . esc_html($s[1]) . '</div>'
                   . '<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.03em">' . esc_html($s[0]) . '</div></div>';
            }
            echo '</div>';

            // Pending payments queue (one-click approve → paid + publish).
            $payments = get_posts([
                'post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC',
                'meta_query' => [['key' => 'sb_payment', 'value' => '"status";s:14:"pending_review"', 'compare' => 'LIKE']],
            ]);
            echo '<h3 style="margin:.5em 0">Pending payments (' . count($payments) . ')</h3>';
            if (!$payments) {
                echo '<p style="color:#646970">No payments awaiting review.</p>';
            } else {
                $action = esc_url(admin_url('admin-post.php'));
                echo '<table class="widefat striped" style="margin-bottom:14px"><thead><tr><th>Listing</th><th>Tier</th><th>Amount</th><th>Method / Ref</th><th></th></tr></thead><tbody>';
                foreach ($payments as $p) {
                    $pay = get_post_meta($p->ID, 'sb_payment', true); if (!is_array($pay)) $pay = [];
                    echo '<tr><td><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></td>'
                       . '<td>' . esc_html($pay['tier'] ?? '') . '</td>'
                       . '<td>₱' . esc_html(number_format((float) ($pay['amount'] ?? 0))) . '</td>'
                       . '<td>' . esc_html(($pay['method'] ?? '') . ' / ' . ($pay['reference'] ?? '')) . '</td>'
                       . '<td><form method="post" action="' . $action . '" style="margin:0">'
                       . '<input type="hidden" name="action" value="sb_approve_payment"><input type="hidden" name="listing_id" value="' . (int) $p->ID . '">'
                       . wp_nonce_field('sb_approve_' . $p->ID, '_wpnonce', true, false)
                       . '<button class="button button-primary button-small">Approve &amp; publish</button></form></td></tr>';
                }
                echo '</tbody></table>';
            }

            // Pending free listings (publish).
            $freePend = get_posts(['post_type' => 'accommodation', 'post_status' => 'pending', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC']);
            $freePend = array_filter($freePend, function ($p) {
                $pay = get_post_meta($p->ID, 'sb_payment', true);
                return !is_array($pay) || ($pay['status'] ?? '') !== 'pending_review';
            });
            echo '<h3 style="margin:.5em 0">Pending listings (' . count($freePend) . ')</h3>';
            if (!$freePend) {
                echo '<p style="color:#646970">No listings awaiting publish.</p>';
            } else {
                $action = esc_url(admin_url('admin-post.php'));
                echo '<table class="widefat striped"><tbody>';
                foreach ($freePend as $p) {
                    echo '<tr><td><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></td>'
                       . '<td style="text-align:right"><form method="post" action="' . $action . '" style="margin:0">'
                       . '<input type="hidden" name="action" value="sb_publish_listing"><input type="hidden" name="listing_id" value="' . (int) $p->ID . '">'
                       . wp_nonce_field('sb_publish_' . $p->ID, '_wpnonce', true, false)
                       . '<button class="button button-small">Publish</button></form></td></tr>';
                }
                echo '</tbody></table>';
            }
        }
    }
    add_action('wp_dashboard_setup', function () {
        if (current_user_can('edit_others_posts')) {
            wp_add_dashboard_widget('sb_overview', 'Limasawa Booking — Overview', 'sb_admin_dashboard_widget');
        }
    });

    /* ---- Admin columns on the accommodation list ------------------------- */
    add_filter('manage_accommodation_posts_columns', function ($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['sb_tier']    = 'Tier';
                $new['sb_payment'] = 'Payment';
                $new['sb_host']    = 'Host';
            }
        }
        return $new;
    });
    add_action('manage_accommodation_posts_custom_column', function ($col, $id) {
        if ($col === 'sb_tier') {
            echo esc_html(get_post_meta($id, 'listing_tier', true) ?: 'free');
        } elseif ($col === 'sb_payment') {
            $pay = get_post_meta($id, 'sb_payment', true);
            echo esc_html(is_array($pay) ? ($pay['status'] ?? '—') : '—');
        } elseif ($col === 'sb_host') {
            $a = (int) get_post_field('post_author', $id);
            $u = $a ? get_userdata($a) : null;
            echo esc_html($u ? $u->display_name : '—');
        }
    }, 10, 2);
}
