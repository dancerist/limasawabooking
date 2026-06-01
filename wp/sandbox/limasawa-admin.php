<?php
/**
 * Limasawa Booking — WP-admin CMS (ported from siargao's sb-dashboard-widget.php,
 * adapted to limasawa's meta model).
 *
 * Siargao stores flat _-prefixed meta (_payment_status / _sb_views_daily_<date>
 * / _listing_tier / _sb_rating_*); limasawa stores sb_payment (array, status
 * 'pending_review'), sb_stats_daily (array per listing), listing_tier,
 * sb_rating_avg/count. This file reads limasawa's keys but reproduces siargao's
 * dashboard UX. Host "View as" impersonation is intentionally omitted.
 *
 * Pieces: rich Overview dashboard widget + full-page CMS overview; approve
 * payment → paid + publish + tier + expiry; approve/reject pending posts;
 * accommodation list columns + tier/payment filters; per-listing meta box.
 * Cap gate: edit_others_posts.
 */

if (defined('ABSPATH')) {

    /* ===================== Core approve/publish logic ===================== */
    if (!function_exists('sb_admin_do_approve')) {
        function sb_admin_do_approve($id) {
            $id = (int) $id;
            if (get_post_type($id) !== 'accommodation') return ['ok' => false];
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
            return ['ok' => true];
        }
    }
    if (!function_exists('sb_admin_do_publish')) {
        function sb_admin_do_publish($id) {
            $id = (int) $id;
            if (get_post_type($id) !== 'accommodation') return ['ok' => false];
            if (in_array(get_post_status($id), ['pending', 'draft'], true)) wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            return ['ok' => true];
        }
    }

    /* ===================== admin-post handlers =========================== */
    add_action('admin_post_sb_approve_payment', function () {
        if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
        $id = (int) ($_GET['post_id'] ?? $_POST['listing_id'] ?? 0);
        check_admin_referer('sb_approve_' . $id);
        sb_admin_do_approve($id);
        wp_safe_redirect(add_query_arg('sb_msg', 'payment_approved', wp_get_referer() ?: admin_url('index.php')));
        exit;
    });
    add_action('admin_post_sb_publish_listing', function () {
        if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
        $id = (int) ($_GET['post_id'] ?? $_POST['listing_id'] ?? 0);
        check_admin_referer('sb_publish_' . $id);
        sb_admin_do_publish($id);
        wp_safe_redirect(add_query_arg('sb_msg', 'listing_published', wp_get_referer() ?: admin_url('index.php')));
        exit;
    });
    add_action('admin_post_sb_approve_post', function () {
        if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
        $id = (int) ($_GET['post_id'] ?? 0);
        check_admin_referer('sb_approve_post_' . $id);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        wp_safe_redirect(add_query_arg('sb_msg', 'post_approved', wp_get_referer() ?: admin_url('index.php')));
        exit;
    });
    add_action('admin_post_sb_reject_post', function () {
        if (!current_user_can('edit_others_posts')) wp_die('Forbidden');
        $id = (int) ($_GET['post_id'] ?? 0);
        check_admin_referer('sb_reject_post_' . $id);
        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        wp_safe_redirect(add_query_arg('sb_msg', 'post_rejected', wp_get_referer() ?: admin_url('index.php')));
        exit;
    });

    /* ===================== Inline SVG icon (from siargao) ================= */
    if (!function_exists('sb_admin_icon')) {
        function sb_admin_icon($name, $size = 14) {
            static $paths = [
                'eye'     => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
                'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'home'    => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
                'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
                'card'    => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
                'clock'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                'pencil'  => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
                'trend'   => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
                'star'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
                'warn'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
                'check'   => '<polyline points="20 6 9 17 4 12"/>',
                'x'       => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            ];
            $p = $paths[$name] ?? '';
            return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;flex-shrink:0">' . $p . '</svg>';
        }
    }

    /* ===================== limasawa-adapted data helpers ================= */
    // Booking/click channels (mirror limasawa-dashboard.php).
    if (!function_exists('sb_admin_channels')) {
        function sb_admin_channels() {
            return function_exists('sb_track_channels') ? sb_track_channels()
                : ['whatsapp', 'phone', 'location', 'airbnb', 'bookingcom', 'website', 'facebook', 'instagram', 'booking'];
        }
    }
    // 30-day site-wide daily views series from each listing's sb_stats_daily array.
    if (!function_exists('sb_admin_views_series')) {
        function sb_admin_views_series($days = 30) {
            $ids = get_posts(['post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 300, 'fields' => 'ids']);
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) $series[date('Y-m-d', strtotime("-{$i} days", current_time('timestamp')))] = 0;
            foreach ($ids as $id) {
                $daily = get_post_meta($id, 'sb_stats_daily', true);
                if (!is_array($daily)) continue;
                foreach ($daily as $d => $row) {
                    if (isset($series[$d]) && is_array($row)) $series[$d] += (int) ($row['views'] ?? 0);
                }
            }
            return $series;
        }
    }
    if (!function_exists('sb_admin_active_now')) {
        function sb_admin_active_now() {
            global $wpdb;
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_sb_v_%'");
        }
    }
    if (!function_exists('sb_admin_pay_count')) {
        function sb_admin_pay_count($status) {
            $q = new WP_Query(['post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
                'meta_query' => [['key' => 'sb_payment', 'value' => '"status";s:' . strlen($status) . ':"' . $status . '"', 'compare' => 'LIKE']]]);
            return (int) $q->found_posts;
        }
    }
    if (!function_exists('sb_admin_mrr')) {
        function sb_admin_mrr() {
            $ids = get_posts(['post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 300, 'fields' => 'ids']);
            $mrr = 0;
            foreach ($ids as $id) {
                $pay = get_post_meta($id, 'sb_payment', true);
                if (!is_array($pay) || ($pay['status'] ?? '') !== 'paid') continue;
                $months = ($pay['billingPeriod'] ?? 'monthly') === 'yearly' ? 12 : 1;
                $mrr += (float) ($pay['amount'] ?? 0) / $months;
            }
            return (int) round($mrr);
        }
    }
    if (!function_exists('sb_admin_top_composite')) {
        function sb_admin_top_composite($days = 30, $limit = 5) {
            $ids = get_posts(['post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
            $channels = sb_admin_channels();
            $booking_ch = ['airbnb', 'bookingcom', 'booking', 'website'];
            $start = strtotime('today', current_time('timestamp')) - ($days - 1) * DAY_IN_SECONDS;
            $rows = [];
            foreach ($ids as $id) {
                $daily = get_post_meta($id, 'sb_stats_daily', true);
                $views = 0; $clicks = 0; $booking = 0;
                if (is_array($daily)) {
                    foreach ($daily as $d => $row) {
                        if (strtotime($d) < $start || !is_array($row)) continue;
                        $views += (int) ($row['views'] ?? 0);
                        $cl = is_array($row['clicks'] ?? null) ? $row['clicks'] : [];
                        foreach ($channels as $ch) { $n = (int) ($cl[$ch] ?? 0); $clicks += $n; if (in_array($ch, $booking_ch, true)) $booking += $n; }
                    }
                }
                if ($views === 0 && $clicks === 0) continue;
                $rcount = (int) get_post_meta($id, 'sb_rating_count', true);
                $ravg   = (float) get_post_meta($id, 'sb_rating_avg', true);
                $score  = 0.5 * $views + 1.0 * $clicks + 5.0 * $booking + 50.0 * $ravg + 3.0 * $rcount;
                $post = get_post($id);
                $author = $post ? get_userdata($post->post_author) : null;
                $rows[] = ['id' => $id, 'title' => $post ? $post->post_title : '', 'host' => $author ? $author->display_name : '', 'views' => $views, 'clicks' => $clicks, 'booking' => $booking, 'rating_avg' => $ravg, 'rating_n' => $rcount, 'score' => $score];
            }
            usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice($rows, 0, $limit);
        }
    }
    if (!function_exists('sb_admin_views_chart')) {
        function sb_admin_views_chart($series) {
            $values = array_values($series); $dates = array_keys($series);
            $max = max($values) ?: 1; $w = 1000; $h = 110; $pad_b = 22; $bar_w = $w / max(1, count($values));
            $body = '';
            foreach ($values as $i => $v) {
                $bh = ($v / $max) * ($h - 6 - $pad_b); $x = $i * $bar_w + 2; $y = $h - $pad_b - $bh; $bw = max(1, $bar_w - 4);
                $body .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max(0, $bh) . '" fill="#3EC7CF" rx="3"><title>' . esc_attr($dates[$i] . ': ' . $v . ' views') . '</title></rect>';
            }
            $labels = '';
            for ($i = 0; $i < count($dates); $i += 5) {
                $x = $i * $bar_w + $bar_w / 2;
                $labels .= '<text x="' . $x . '" y="' . ($h - 6) . '" font-size="10" fill="#94A3B8" text-anchor="middle">' . esc_html(date('M j', strtotime($dates[$i]))) . '</text>';
            }
            return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="width:100%;height:130px;display:block">' . $body . $labels . '<text x="4" y="14" font-size="10" fill="#94A3B8">' . number_format($max) . '</text></svg>';
        }
    }

    /* ===================== Rich dashboard widget ========================= */
    if (!function_exists('sb_admin_dashboard_widget')) {
        function sb_admin_dashboard_widget() {
            global $wpdb;
            $c = wp_count_posts('accommodation');
            $live = (int) ($c->publish ?? 0); $pend_list = (int) ($c->pending ?? 0);
            $active_now = sb_admin_active_now();
            $reviews_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type='sb_review' AND comment_approved='0'");
            $signups_30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s", date('Y-m-d 00:00:00', strtotime('-30 days', current_time('timestamp')))));
            $views_series = sb_admin_views_series(30);
            $v30 = array_sum($views_series); $v7 = array_sum(array_slice($views_series, -7));
            $pend_pay = sb_admin_pay_count('pending_review');
            $mrr = sb_admin_mrr();
            $tier_labels = ['free' => 'Island', 'pro' => 'Pro', 'featured' => 'Featured'];

            echo '<style>
#sb_overview{font-size:.82rem;color:#1a2533}
#sb_overview .sb-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-bottom:16px}
#sb_overview .sb-stat{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:11px 12px}
#sb_overview .sb-stat .l{display:flex;align-items:center;gap:6px;font-size:.62rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-bottom:6px}
#sb_overview .sb-stat .n{font-size:1.5rem;font-weight:800;line-height:1}
#sb_overview .sb-stat .sub{font-size:.66rem;color:#94a3b8;margin-top:3px}
#sb_overview .sb-stat.warn{background:#fff7ed;border-color:#fed7aa}
#sb_overview .sb-stat.live{background:#ecfdf5;border-color:#86efac}
#sb_overview .sb-stat.live .dot{display:inline-block;width:8px;height:8px;background:#10b981;border-radius:999px;margin-right:6px;animation:sbp 1.4s ease-in-out infinite}
@keyframes sbp{0%,100%{opacity:1}50%{opacity:.4}}
#sb_overview .sb-section{margin-bottom:16px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px}
#sb_overview .sb-sh{display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:800;margin:0 0 10px}
#sb_overview .sb-sh .count{background:#f1f5f9;color:#475569;font-size:.65rem;padding:2px 8px;border-radius:999px}
#sb_overview .sb-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px}
#sb_overview table{width:100%;border-collapse:collapse}
#sb_overview th{text-align:left;font-size:.6rem;color:#94a3b8;text-transform:uppercase;font-weight:700;padding:0 8px 7px 0;border-bottom:1px solid #f1f5f9}
#sb_overview td{padding:7px 8px 7px 0;border-bottom:1px solid #f5f8fb;font-size:.78rem}
#sb_overview .pill{display:inline-block;padding:1.5px 7px;border-radius:999px;font-size:.62rem;font-weight:700}
#sb_overview .p-warn{background:#fff7ed;color:#9a3412}#sb_overview .p-ok{background:#ecfdf5;color:#047857}
#sb_overview .p-blue{background:#eff6ff;color:#1d4ed8}#sb_overview .p-gray{background:#f8fafc;color:#64748b}
#sb_overview a.lnk{color:#0e7490;text-decoration:none;font-weight:600}#sb_overview a.lnk:hover{text-decoration:underline}
</style>';

            echo '<div id="sb_overview">';
            $stats = [
                ['live', '<span class="dot"></span>' . number_format($active_now), 'Active now', 'Last 30 min', 'eye'],
                ['', number_format($v7), 'Views (7d)', number_format($v30) . ' in 30d', 'trend'],
                ['', number_format($signups_30), 'Signups (30d)', 'New accounts', 'users'],
                ['', number_format($live), 'Listings live', $pend_list . ' pending', 'home'],
                [$reviews_pending > 0 ? 'warn' : '', number_format($reviews_pending), 'Reviews waiting', 'Need approval', 'message'],
                [$pend_pay > 0 ? 'warn' : '', $pend_pay > 0 ? number_format($pend_pay) : '₱' . number_format($mrr), $pend_pay > 0 ? 'Payments to verify' : 'Est. MRR', $pend_pay > 0 ? 'See below' : 'Paid subs', 'card'],
            ];
            echo '<div class="sb-grid">';
            foreach ($stats as $s) {
                echo '<div class="sb-stat ' . $s[0] . '"><div class="l">' . sb_admin_icon($s[4]) . esc_html($s[2]) . '</div><div class="n">' . wp_kses_post($s[1]) . '</div><div class="sub">' . esc_html($s[3]) . '</div></div>';
            }
            echo '</div>';

            echo '<div class="sb-section"><div class="sb-sh">' . sb_admin_icon('trend') . 'Site views — last 30 days</div>' . sb_admin_views_chart($views_series) . '</div>';

            // Listing claims to verify (from limasawa-claims.php).
            if (function_exists('sb_claims_render_pending_section')) sb_claims_render_pending_section();

            // Payments to verify
            $payments = get_posts(['post_type' => 'accommodation', 'post_status' => 'any', 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC',
                'meta_query' => [['key' => 'sb_payment', 'value' => '"status";s:14:"pending_review"', 'compare' => 'LIKE']]]);
            if ($payments) {
                echo '<div class="sb-section"><div class="sb-sh">' . sb_admin_icon('warn') . 'Payments to verify <span class="count">' . count($payments) . '</span></div><table><thead><tr><th>Listing</th><th>Host</th><th>Tier</th><th>Amount</th><th>Ref</th><th>Receipt</th><th></th></tr></thead><tbody>';
                foreach ($payments as $p) {
                    $pay = get_post_meta($p->ID, 'sb_payment', true); if (!is_array($pay)) $pay = [];
                    $host = get_userdata($p->post_author);
                    $rid = (int) ($pay['receiptId'] ?? 0); $rurl = $rid ? wp_get_attachment_url($rid) : '';
                    $apr = wp_nonce_url(admin_url('admin-post.php?action=sb_approve_payment&post_id=' . $p->ID), 'sb_approve_' . $p->ID);
                    echo '<tr><td><a class="lnk" href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html(wp_trim_words(get_the_title($p->ID), 4)) . '</a></td>'
                       . '<td>' . esc_html($host ? $host->display_name : '—') . '</td>'
                       . '<td><span class="pill p-blue">' . esc_html($tier_labels[$pay['tier'] ?? 'free'] ?? ($pay['tier'] ?? '')) . '</span></td>'
                       . '<td>₱' . number_format((float) ($pay['amount'] ?? 0)) . '</td>'
                       . '<td style="font-family:monospace;font-size:.7rem">' . esc_html($pay['reference'] ?? '—') . '</td>'
                       . '<td>' . ($rurl ? '<a class="lnk" target="_blank" href="' . esc_url($rurl) . '">View</a>' : '—') . '</td>'
                       . '<td><a class="button button-small" href="' . esc_url($apr) . '" onclick="return confirm(&#39;Approve payment and publish?&#39;)">Approve</a></td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // Expiring within 14 days
            $expiring = [];
            foreach (get_posts(['post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 300, 'fields' => 'ids']) as $id) {
                $pay = get_post_meta($id, 'sb_payment', true);
                if (!is_array($pay) || ($pay['status'] ?? '') !== 'paid' || empty($pay['expiresAt'])) continue;
                $ts = strtotime($pay['expiresAt']);
                if ($ts > current_time('timestamp') && $ts < strtotime('+14 days', current_time('timestamp'))) $expiring[] = ['id' => $id, 'ts' => $ts];
            }
            if ($expiring) {
                usort($expiring, fn($a, $b) => $a['ts'] <=> $b['ts']);
                echo '<div class="sb-section"><div class="sb-sh" style="color:#B45309">' . sb_admin_icon('clock') . 'Expiring within 14 days <span class="count">' . count($expiring) . '</span></div><table><thead><tr><th>Listing</th><th>Expires</th><th>Days</th></tr></thead><tbody>';
                foreach ($expiring as $e) {
                    $days = max(0, (int) ceil(($e['ts'] - current_time('timestamp')) / DAY_IN_SECONDS));
                    echo '<tr><td><a class="lnk" href="' . esc_url(get_edit_post_link($e['id'])) . '">' . esc_html(wp_trim_words(get_the_title($e['id']), 5)) . '</a></td><td>' . esc_html(date('M j, Y', $e['ts'])) . '</td><td><span class="pill ' . ($days <= 3 ? 'p-warn' : 'p-blue') . '">' . $days . 'd</span></td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // Recent listings + new signups
            echo '<div class="sb-cols">';
            $recent = get_posts(['post_type' => 'accommodation', 'post_status' => ['publish', 'pending', 'draft'], 'posts_per_page' => 7, 'orderby' => 'date', 'order' => 'DESC']);
            echo '<div class="sb-section" style="margin:0"><div class="sb-sh">' . sb_admin_icon('home') . 'Recent listings</div><table><thead><tr><th>Property</th><th>Host</th><th>Status</th></tr></thead><tbody>';
            foreach ($recent as $p) {
                $tier = get_post_meta($p->ID, 'listing_tier', true) ?: 'free';
                $scls = $p->post_status === 'publish' ? 'p-ok' : ($p->post_status === 'pending' ? 'p-warn' : 'p-gray');
                $slbl = $p->post_status === 'publish' ? 'Live' : ($p->post_status === 'pending' ? 'Pending' : 'Draft');
                $host = get_userdata($p->post_author);
                echo '<tr><td><a class="lnk" href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html(wp_trim_words(get_the_title($p->ID), 5)) . '</a> <span class="pill p-blue">' . esc_html($tier_labels[$tier] ?? $tier) . '</span></td><td style="color:#475569">' . esc_html($host ? $host->display_name : '—') . '</td><td><span class="pill ' . $scls . '">' . $slbl . '</span></td></tr>';
            }
            if (!$recent) echo '<tr><td colspan="3" style="color:#94a3b8">No listings yet.</td></tr>';
            echo '</tbody></table></div>';

            $signups = $wpdb->get_results($wpdb->prepare("SELECT ID,display_name,user_login,user_email,user_registered FROM {$wpdb->users} WHERE user_registered >= %s ORDER BY user_registered DESC LIMIT 7", date('Y-m-d 00:00:00', strtotime('-30 days', current_time('timestamp')))));
            echo '<div class="sb-section" style="margin:0"><div class="sb-sh">' . sb_admin_icon('users') . 'New signups (30d)</div><table><thead><tr><th>User</th><th>Joined</th></tr></thead><tbody>';
            foreach ($signups as $u) {
                echo '<tr><td><a class="lnk" href="' . esc_url(admin_url('user-edit.php?user_id=' . (int) $u->ID)) . '">' . esc_html($u->display_name ?: $u->user_login) . '</a><div style="color:#94a3b8;font-size:.7rem">' . esc_html($u->user_email) . '</div></td><td style="color:#94a3b8;white-space:nowrap">' . esc_html(human_time_diff(strtotime($u->user_registered), current_time('timestamp')) . ' ago') . '</td></tr>';
            }
            if (!$signups) echo '<tr><td colspan="2" style="color:#94a3b8">No new signups.</td></tr>';
            echo '</tbody></table></div></div>';

            // Posts to approve
            $posts_pend = get_posts(['post_type' => 'post', 'post_status' => 'pending', 'posts_per_page' => 6, 'orderby' => 'date', 'order' => 'DESC']);
            if ($posts_pend) {
                echo '<div class="sb-section"><div class="sb-sh">' . sb_admin_icon('pencil') . 'Posts to approve <span class="count">' . count($posts_pend) . '</span></div><table><thead><tr><th>Title</th><th>Author</th><th></th></tr></thead><tbody>';
                foreach ($posts_pend as $p) {
                    $a = get_userdata($p->post_author);
                    $appr = wp_nonce_url(admin_url('admin-post.php?action=sb_approve_post&post_id=' . $p->ID), 'sb_approve_post_' . $p->ID);
                    $rej = wp_nonce_url(admin_url('admin-post.php?action=sb_reject_post&post_id=' . $p->ID), 'sb_reject_post_' . $p->ID);
                    echo '<tr><td><a class="lnk" href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title ?: '(Untitled)') . '</a></td><td style="color:#475569">' . esc_html($a ? $a->display_name : '—') . '</td>'
                       . '<td><a class="button button-small button-primary" href="' . esc_url($appr) . '">Approve</a> <a class="button button-small" href="' . esc_url($rej) . '">Reject</a></td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // Top listings composite
            $top = sb_admin_top_composite(30, 5);
            echo '<div class="sb-section"><div class="sb-sh">' . sb_admin_icon('star') . 'Top listings — 30 days <span class="count" title="0.5×views + 1×clicks + 5×booking + 50×avg-rating + 3×review-count">composite</span></div><table><thead><tr><th>#</th><th>Property</th><th>Host</th><th style="text-align:right">Views</th><th style="text-align:right">Clicks</th><th style="text-align:right">Book</th><th style="text-align:right">Rating</th><th style="text-align:right">Score</th></tr></thead><tbody>';
            if ($top) {
                foreach ($top as $i => $r) {
                    $rating = $r['rating_n'] > 0 ? number_format($r['rating_avg'], 1) . ' (' . $r['rating_n'] . ')' : '—';
                    echo '<tr><td style="color:#94a3b8;font-weight:700">' . ($i + 1) . '</td><td><a class="lnk" href="' . esc_url(get_edit_post_link($r['id'])) . '">' . esc_html(wp_trim_words($r['title'], 5)) . '</a></td><td style="color:#475569">' . esc_html($r['host']) . '</td>'
                       . '<td style="text-align:right;font-weight:700">' . number_format($r['views']) . '</td><td style="text-align:right">' . number_format($r['clicks']) . '</td><td style="text-align:right;color:#0e7490;font-weight:700">' . number_format($r['booking']) . '</td><td style="text-align:right">' . esc_html($rating) . '</td><td style="text-align:right;font-weight:800">' . number_format($r['score'], 1) . '</td></tr>';
                }
            } else {
                echo '<tr><td colspan="8" style="color:#94a3b8;text-align:center;padding:18px">No tracked activity in the last 30 days yet.</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
    }

    add_action('wp_dashboard_setup', function () {
        if (!current_user_can('edit_others_posts')) return;
        wp_add_dashboard_widget('sb_overview_widget', 'Limasawa Booking — Analytics', 'sb_admin_dashboard_widget');
    });
    // Full-width dashboard widget.
    add_action('admin_head', function () {
        $s = get_current_screen();
        if (!$s || $s->id !== 'dashboard') return;
        echo '<style>#sb_overview_widget{grid-column:1/-1}#dashboard-widgets #sb_overview_widget.postbox{width:100%}</style>';
    });

    /* ===================== Admin notices ================================= */
    add_action('admin_notices', function () {
        if (empty($_GET['sb_msg'])) return;
        $m = ['payment_approved' => ['success', 'Payment approved — listing is live.'], 'listing_published' => ['success', 'Listing published.'], 'post_approved' => ['success', 'Post published.'], 'post_rejected' => ['info', 'Post moved to draft.'], 'claim_approved' => ['success', 'Claim approved — ownership transferred to the host.'], 'claim_rejected' => ['info', 'Claim rejected; the claimant was notified.'], 'claim_already' => ['info', 'That claim was already reviewed.'], 'claim_listing_gone' => ['error', 'The claimed listing no longer exists.']];
        $k = sanitize_key($_GET['sb_msg']);
        if (!isset($m[$k])) return;
        echo '<div class="notice notice-' . esc_attr($m[$k][0]) . ' is-dismissible"><p><strong>' . esc_html($m[$k][1]) . '</strong></p></div>';
    });

    /* ===================== Brand accent + toolbar ======================== */
    add_action('admin_enqueue_scripts', function () {
        wp_add_inline_style('common', '.wp-core-ui .button-primary{background:#25CECE;border-color:#1ab4b4;text-shadow:none}.wp-core-ui .button-primary:hover{background:#1ab4b4;border-color:#1ab4b4}');
    });
    add_action('admin_bar_menu', function ($bar) {
        if (current_user_can('edit_others_posts')) $bar->add_node(['id' => 'sb-brand', 'title' => '🌴 Limasawa', 'href' => admin_url('admin.php?page=limasawa-cms')]);
    }, 8);

    /* ===================== Listings list filters ========================= */
    add_action('restrict_manage_posts', function ($pt) {
        if ($pt !== 'accommodation') return;
        $tier = sanitize_text_field($_GET['sb_tier_f'] ?? ''); $pay = sanitize_text_field($_GET['sb_pay_f'] ?? '');
        echo '<select name="sb_tier_f"><option value="">All tiers</option>';
        foreach (['free', 'pro', 'featured'] as $t) echo '<option value="' . $t . '"' . selected($tier, $t, false) . '>' . ucfirst($t) . '</option>';
        echo '</select> <select name="sb_pay_f"><option value="">All payments</option>';
        foreach (['pending_review' => 'Pending payment', 'paid' => 'Paid', 'not_required' => 'Free'] as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected($pay, $k, false) . '>' . esc_html($l) . '</option>';
        echo '</select>';
    });
    add_action('pre_get_posts', function ($q) {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== 'accommodation') return;
        $meta = [];
        if (!empty($_GET['sb_tier_f'])) $meta[] = ['key' => 'listing_tier', 'value' => sanitize_text_field($_GET['sb_tier_f'])];
        if (!empty($_GET['sb_pay_f'])) { $s = sanitize_text_field($_GET['sb_pay_f']); $meta[] = ['key' => 'sb_payment', 'value' => '"status";s:' . strlen($s) . ':"' . $s . '"', 'compare' => 'LIKE']; }
        if ($meta) { if (count($meta) > 1) $meta['relation'] = 'AND'; $q->set('meta_query', $meta); }
    });

    /* ===================== Per-listing meta box ========================== */
    add_action('add_meta_boxes', function () {
        add_meta_box('sb_listing_cms', 'Limasawa — Subscription & Stats', 'sb_admin_listing_metabox', 'accommodation', 'side', 'high');
    });
    if (!function_exists('sb_admin_listing_metabox')) {
        function sb_admin_listing_metabox($post) {
            $pay = get_post_meta($post->ID, 'sb_payment', true); if (!is_array($pay)) $pay = [];
            $tier = get_post_meta($post->ID, 'listing_tier', true) ?: 'free';
            $views = 0; $daily = get_post_meta($post->ID, 'sb_stats_daily', true);
            if (is_array($daily)) foreach ($daily as $row) if (is_array($row)) $views += (int) ($row['views'] ?? 0);
            echo '<p><strong>Tier:</strong> ' . esc_html($tier) . '<br><strong>Payment:</strong> ' . esc_html($pay['status'] ?? '—');
            if (!empty($pay['amount'])) echo ' (₱' . esc_html(number_format((float) $pay['amount'])) . ' / ' . esc_html($pay['billingPeriod'] ?? '') . ')';
            echo '<br><strong>Expires:</strong> ' . esc_html($pay['expiresAt'] ?: '—') . '<br><strong>Views (all-time):</strong> ' . esc_html($views) . '<br>'
               . '<strong>Rating:</strong> ' . esc_html((get_post_meta($post->ID, 'sb_rating_count', true) ?: 0)) . ' reviews</p>';
            if (($pay['status'] ?? '') === 'pending_review') {
                $apr = wp_nonce_url(admin_url('admin-post.php?action=sb_approve_payment&post_id=' . $post->ID), 'sb_approve_' . $post->ID);
                echo '<a class="button button-primary" style="width:100%;text-align:center" href="' . esc_url($apr) . '" onclick="return confirm(&#39;Approve payment and publish?&#39;)">Approve payment &amp; publish</a>';
            }
        }
    }

    /* ===================== Admin columns ================================= */
    add_filter('manage_accommodation_posts_columns', function ($cols) {
        $new = [];
        foreach ($cols as $k => $v) { $new[$k] = $v; if ($k === 'title') { $new['sb_tier'] = 'Tier'; $new['sb_payment'] = 'Payment'; $new['sb_host'] = 'Host'; } }
        return $new;
    });
    add_action('manage_accommodation_posts_custom_column', function ($col, $id) {
        if ($col === 'sb_tier') echo esc_html(get_post_meta($id, 'listing_tier', true) ?: 'free');
        elseif ($col === 'sb_payment') { $p = get_post_meta($id, 'sb_payment', true); echo esc_html(is_array($p) ? ($p['status'] ?? '—') : '—'); }
        elseif ($col === 'sb_host') { $a = (int) get_post_field('post_author', $id); $u = $a ? get_userdata($a) : null; echo esc_html($u ? $u->display_name : '—'); }
    }, 10, 2);

    /* ===================== Top-level "Limasawa" CMS page ================= */
    add_action('admin_menu', function () {
        add_menu_page('Limasawa CMS', 'Limasawa', 'edit_others_posts', 'limasawa-cms', function () {
            echo '<div class="wrap"><h1>Limasawa Booking — CMS Overview</h1><div style="max-width:1100px;margin-top:14px">';
            if (function_exists('sb_admin_dashboard_widget')) sb_admin_dashboard_widget();
            echo '</div></div>';
        }, 'dashicons-palmtree', 3);
    });
}
