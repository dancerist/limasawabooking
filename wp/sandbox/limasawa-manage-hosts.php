<?php
/**
 * Limasawa Booking — Manage Hosts admin page
 *
 * Adapted from siargao's sb-manage-hosts.php. One-page wp-admin tool: a
 * searchable/filterable table of every host (any WP user who authors >=1
 * `accommodation`) plus a slide-in drawer with their listings, subscriptions,
 * claims, activity timeline and admin-only notes.
 *
 * Re-pointed to limasawa meta conventions:
 *   - listing_tier  (string free|pro|featured)
 *   - sb_payment    (array: status|billingPeriod|amount|method|reference|paidAt|expiresAt)
 *   - sb_stats_daily(array YYYY-MM-DD => {views, clicks, sources})
 *   - sb_rating_avg / sb_rating_count
 *   - claims table wp_sb_claims (same schema as siargao)
 *
 * DROPPED from siargao: "View as host" / impersonation (limasawa removed it),
 * and the deprecated v1 drawer JS. Suspend toggle and admin notes are kept.
 *
 * Every function is guarded with function_exists and uses the sb_hosts_* prefix
 * to avoid "Cannot redeclare" fatals with the other sandbox files.
 */
if (!defined('ABSPATH')) exit;

// ─ Admin menu ─
add_action('admin_menu', function () {
    add_menu_page(
        'Hosts',
        'Hosts',
        'manage_options',
        'limasawa-hosts',
        'sb_hosts_render_page',
        'dashicons-groups',
        26
    );
});

// ─ REST endpoints (drawer detail + notes + suspend) ─
add_action('rest_api_init', function () {
    register_rest_route('limasawa/v1', '/admin/host/(?P<id>\\d+)', [
        'methods'             => 'GET',
        'callback'            => 'sb_hosts_rest_detail',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
    register_rest_route('limasawa/v1', '/admin/host/(?P<id>\\d+)/notes', [
        'methods'             => 'POST',
        'callback'            => 'sb_hosts_rest_notes',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
    register_rest_route('limasawa/v1', '/admin/host/(?P<id>\\d+)/suspend', [
        'methods'             => 'POST',
        'callback'            => 'sb_hosts_rest_suspend',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
});

// ─ Tier ordering helper (local; does NOT redefine the shared sb_tiers) ─
if (!function_exists('sb_hosts_tier_rank')) {
    function sb_hosts_tier_rank($tier) {
        $order = ['free' => 0, 'pro' => 1, 'featured' => 2];
        return $order[$tier] ?? 0;
    }
}
if (!function_exists('sb_hosts_tier_labels')) {
    function sb_hosts_tier_labels() {
        return ['free' => 'Free', 'pro' => 'Pro', 'featured' => 'Featured'];
    }
}

// ─ Sum a listing's views over the trailing N days from sb_stats_daily ─
if (!function_exists('sb_hosts_views_for')) {
    function sb_hosts_views_for($listing_id, $days = 30) {
        $daily = get_post_meta($listing_id, 'sb_stats_daily', true);
        if (!is_array($daily)) return 0;
        $total = 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days", current_time('timestamp')));
            if (isset($daily[$d]) && is_array($daily[$d])) $total += (int) ($daily[$d]['views'] ?? 0);
        }
        return $total;
    }
}

// ─ Phone -> wa.me URL (local helper) ─
if (!function_exists('sb_hosts_wa_url')) {
    function sb_hosts_wa_url($phone) {
        $digits = preg_replace('/\\D+/', '', (string) $phone);
        if (strpos($digits, '0') === 0 && strlen($digits) === 11) $digits = '63' . substr($digits, 1);
        return strlen($digits) >= 7 ? ('https://wa.me/' . $digits) : '';
    }
}

// ─ Read admin notes for a user (stored as an array in user meta) ─
if (!function_exists('sb_hosts_read_notes')) {
    function sb_hosts_read_notes($uid) {
        $raw = get_user_meta($uid, 'sb_admin_notes', true);
        if (is_array($raw)) return array_values($raw);
        $decoded = json_decode(is_string($raw) ? $raw : '[]', true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}

// ─ Data: all hosts with computed fields ─
if (!function_exists('sb_hosts_get_all')) {
    function sb_hosts_get_all($filters = []) {
        global $wpdb;
        $author_ids = $wpdb->get_col(
            "SELECT DISTINCT post_author FROM {$wpdb->posts}
             WHERE post_type='accommodation' AND post_status IN ('publish','pending','draft','future','trash')"
        );
        if (empty($author_ids)) return [];
        $ids_csv = implode(',', array_map('intval', $author_ids));

        // Per-author listing counts grouped by status
        $listing_stats = [];
        $rows = $wpdb->get_results(
            "SELECT post_author, post_status, COUNT(*) as n FROM {$wpdb->posts}
             WHERE post_type='accommodation' AND post_author IN ($ids_csv)
             GROUP BY post_author, post_status"
        );
        foreach ($rows as $r) $listing_stats[$r->post_author][$r->post_status] = (int) $r->n;

        // Per-author max tier (limasawa: meta key listing_tier)
        $tier_rows = $wpdb->get_results(
            "SELECT p.post_author, pm.meta_value as tier
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'listing_tier'
             WHERE p.post_type = 'accommodation' AND p.post_author IN ($ids_csv)"
        );
        $max_tier_by = [];
        foreach ($tier_rows as $r) {
            $t = $r->tier ?: 'free';
            if (!in_array($t, ['free', 'pro', 'featured'], true)) $t = 'free';
            if (!isset($max_tier_by[$r->post_author]) || sb_hosts_tier_rank($t) > sb_hosts_tier_rank($max_tier_by[$r->post_author])) {
                $max_tier_by[$r->post_author] = $t;
            }
        }

        // Pending payments + paid MRR per author — sb_payment is a serialized
        // PHP array, so we read the listing meta per host (set is small).
        $pending_pay_by = [];
        $mrr_by = [];
        $listings_by_author = [];
        $all_rows = $wpdb->get_results(
            "SELECT ID, post_author FROM {$wpdb->posts}
             WHERE post_type='accommodation' AND post_author IN ($ids_csv)"
        );
        foreach ($all_rows as $r) {
            $listings_by_author[$r->post_author][] = (int) $r->ID;
            $pay = get_post_meta((int) $r->ID, 'sb_payment', true);
            if (!is_array($pay)) continue;
            $status = $pay['status'] ?? '';
            if ($status === 'pending_review') {
                $pending_pay_by[$r->post_author] = ($pending_pay_by[$r->post_author] ?? 0) + 1;
            } elseif ($status === 'paid') {
                $months = ($pay['billingPeriod'] ?? 'monthly') === 'yearly' ? 12 : 1;
                $mrr_by[$r->post_author] = ($mrr_by[$r->post_author] ?? 0) + ((float) ($pay['amount'] ?? 0) / max(1, $months));
            }
        }

        // Inbound pending claims per host (claimant = host id)
        $pending_claims = [];
        $claims_table = $wpdb->prefix . 'sb_claims';
        if ($wpdb->get_var("SHOW TABLES LIKE '$claims_table'") === $claims_table) {
            $cr = $wpdb->get_results(
                "SELECT claimant_user_id, COUNT(*) as n FROM $claims_table
                 WHERE status='pending' AND claimant_user_id IN ($ids_csv) GROUP BY claimant_user_id"
            );
            foreach ($cr as $r) $pending_claims[$r->claimant_user_id] = (int) $r->n;
        }

        $hosts = [];
        foreach ($author_ids as $uid) {
            $user = get_userdata((int) $uid);
            if (!$user) continue;
            $stats = $listing_stats[$uid] ?? [];

            // 30d views across this host's listings
            $views_30d = 0;
            foreach (($listings_by_author[$uid] ?? []) as $lid) $views_30d += sb_hosts_views_for($lid, 30);

            // Rating: average across listings that have reviews
            $rating_sum = 0; $rating_n = 0;
            foreach (($listings_by_author[$uid] ?? []) as $lid) {
                $rc = (int) get_post_meta($lid, 'sb_rating_count', true);
                if ($rc > 0) { $rating_sum += (float) get_post_meta($lid, 'sb_rating_avg', true); $rating_n++; }
            }

            $registered_ts = strtotime($user->user_registered);
            $last_login = (int) get_user_meta($uid, 'sb_last_login', true);
            $last_active_ts = $last_login ?: $registered_ts;

            $hosts[] = [
                'id'                     => (int) $uid,
                'display_name'           => $user->display_name,
                'user_login'             => $user->user_login,
                'user_email'             => $user->user_email,
                'roles'                  => $user->roles,
                'avatar'                 => get_avatar_url($uid, ['size' => 36]),
                'registered'             => $user->user_registered,
                'last_active_ts'         => $last_active_ts,
                'last_active_label'      => human_time_diff($last_active_ts) . ' ago',
                'listings_total'         => array_sum($stats),
                'listings_publish'       => $stats['publish'] ?? 0,
                'listings_draft'         => $stats['draft']   ?? 0,
                'listings_pending'       => $stats['pending'] ?? 0,
                'listings_trash'         => $stats['trash']   ?? 0,
                'max_tier'               => $max_tier_by[$uid] ?? 'free',
                'mrr'                    => (int) round($mrr_by[$uid] ?? 0),
                'views_30d'              => (int) $views_30d,
                'rating_avg'             => $rating_n ? round($rating_sum / $rating_n, 1) : 0,
                'pending_payments'       => (int) ($pending_pay_by[$uid] ?? 0),
                'pending_claims_inbound' => (int) ($pending_claims[$uid] ?? 0),
            ];
        }

        // Filters
        if (!empty($filters['search'])) {
            $q = strtolower($filters['search']);
            $hosts = array_filter($hosts, function ($h) use ($q) {
                return stripos($h['display_name'], $q) !== false
                    || stripos($h['user_email'],  $q) !== false
                    || stripos($h['user_login'],  $q) !== false;
            });
        }
        if (!empty($filters['tier'])) {
            $hosts = array_filter($hosts, fn($h) => $h['max_tier'] === $filters['tier']);
        }
        if (!empty($filters['status'])) {
            $s = $filters['status'];
            $hosts = array_filter($hosts, function ($h) use ($s) {
                if ($s === 'active')   return $h['listings_publish'] > 0;
                if ($s === 'inactive') return $h['listings_publish'] === 0;
                if ($s === 'flagged')  return $h['pending_payments'] > 0 || $h['pending_claims_inbound'] > 0;
                return true;
            });
        }

        usort($hosts, fn($a, $b) => $b['last_active_ts'] <=> $a['last_active_ts']);
        return array_values($hosts);
    }
}

// ─ Page CSS (limasawa cyan #25CECE / #3EC7CF accents) ─
if (!function_exists('sb_hosts_styles')) {
    function sb_hosts_styles() {
        return '<style>
          .sb-mh-wrap { font-size:.92rem; }
          .sb-mh-wrap h1 { display:flex; align-items:center; gap:.5rem; }
          .sb-mh-count { background:#e6fbfb; color:#0e7490; font-size:.7rem; font-weight:700; padding:3px 9px; border-radius:999px; letter-spacing:.04em; }
          .sb-mh-toolbar { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; background:#fff; padding:.7rem .9rem; margin:1rem 0; border:1px solid #e2e8f0; border-radius:10px; }
          .sb-mh-toolbar input[type=search] { flex:1; min-width:220px; padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:8px; }
          .sb-mh-toolbar select { padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
          .sb-mh-table th { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
          .sb-mh-table td { vertical-align:middle; padding:10px 12px !important; }
          .sb-mh-host { display:flex; align-items:center; gap:.55rem; }
          .sb-mh-host img { width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; }
          .sb-mh-host strong { display:block; font-weight:700; color:#1a2533; line-height:1.2; }
          .sb-mh-meta { display:block; font-size:.7rem; color:#94a3b8; }
          .sb-mh-pill { display:inline-block; padding:1.5px 8px; border-radius:999px; font-size:.65rem; font-weight:700; margin-right:.2rem; white-space:nowrap; }
          .sb-mh-pill.pill-ok   { background:#ecfdf5; color:#047857; border:1px solid #86efac; }
          .sb-mh-pill.pill-mute { background:#f8fafc; color:#64748b; border:1px solid #cbd5e1; }
          .sb-mh-pill.pill-warn { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
          .sb-mh-tier { display:inline-block; padding:2px 9px; border-radius:6px; font-size:.66rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
          .sb-mh-tier.tier-free     { background:#f1f5f9; color:#64748b; }
          .sb-mh-tier.tier-pro      { background:#cffafe; color:#0e7490; }
          .sb-mh-tier.tier-featured { background:#cffafe; color:#0e7490; box-shadow:inset 0 0 0 1.5px #25CECE; }
          .sb-mh-flag { display:inline-flex; align-items:center; gap:.2rem; margin-right:.3rem; background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; font-size:.65rem; font-weight:700; padding:1.5px 7px; border-radius:999px; white-space:nowrap; }
          .sb-mh-row { cursor:pointer; transition:background .12s; }
          .sb-mh-row:hover { background:#f0fdfd; }
          .sb-mh-drawer { position:fixed; inset:0; z-index:99999; display:flex; justify-content:flex-end; }
          .sb-mh-drawer[hidden] { display:none; }
          .sb-mh-drawer-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.4); }
          .sb-mh-drawer-panel { position:relative; background:#fff; width:min(640px, 100%); height:100%; box-shadow:-12px 0 40px rgba(0,0,0,.18); display:flex; flex-direction:column; animation: sb-mh-slidein .22s cubic-bezier(.32,.72,.32,1); }
          @keyframes sb-mh-slidein { from { transform:translateX(100%); } to { transform:translateX(0); } }
          .sb-mh-drawer-close { position:absolute; top:14px; right:14px; z-index:5; width:34px; height:34px; border-radius:50%; border:0; background:#f1f5f9; color:#1a2533; font-size:22px; line-height:1; cursor:pointer; }
          .sb-mh-drawer-close:hover { background:#e2e8f0; }
          .sb-mh-drawer-header { padding:1.6rem 1.6rem 1.2rem; border-bottom:1px solid #e2e8f0; }
          .sb-mh-drawer-header .sb-mh-host { gap:.85rem; margin-bottom:.65rem; }
          .sb-mh-drawer-header .sb-mh-host img { width:54px; height:54px; }
          .sb-mh-drawer-header .sb-mh-host strong { font-size:1.15rem; }
          .sb-mh-drawer-meta { color:#64748b; font-size:.78rem; line-height:1.55; }
          .sb-mh-drawer-meta a { color:#0e7490; text-decoration:none; font-weight:600; }
          .sb-mh-drawer-actions { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:1rem; }
          .sb-mh-drawer-actions a { display:inline-flex; align-items:center; gap:.3rem; background:#fff; border:1px solid #cbd5e1; color:#1a2533; font-size:.76rem; font-weight:700; padding:.4rem .8rem; border-radius:8px; text-decoration:none; }
          .sb-mh-drawer-actions a:hover { background:#f1f5f9; }
          .sb-mh-drawer-actions a.primary { background:#25CECE; color:#063b3b; border-color:#25CECE; }
          .sb-mh-drawer-actions a.primary:hover { background:#3EC7CF; }
          .sb-mh-drawer-actions a.danger { color:#b91c1c; border-color:#fca5a5; }
          .sb-mh-drawer-actions a.danger:hover { background:#fef2f2; }
          .sb-mh-drawer-tabs { display:flex; gap:.4rem; padding:0 1.6rem; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
          .sb-mh-drawer-tab { background:none; border:0; cursor:pointer; padding:.85rem .4rem; font-size:.82rem; font-weight:700; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-1px; }
          .sb-mh-drawer-tab.active { color:#0e7490; border-bottom-color:#25CECE; }
          .sb-mh-drawer-body { flex:1; overflow-y:auto; padding:1.4rem 1.6rem 2rem; }
          .sb-mh-listing-row { display:flex; gap:1rem; padding:.9rem 0; border-bottom:1px solid #f1f5f9; }
          .sb-mh-listing-row:last-child { border-bottom:0; }
          .sb-mh-listing-row img { width:64px; height:64px; border-radius:8px; object-fit:cover; flex-shrink:0; background:#e2e8f0; }
          .sb-mh-listing-row .meta-line { font-size:.74rem; color:#64748b; margin-top:.2rem; display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
          .sb-mh-listing-row a.title-link { font-weight:700; color:#1a2533; text-decoration:none; font-size:.92rem; }
          .sb-mh-listing-row a.title-link:hover { color:#0e7490; }
          .sb-mh-listing-row .row-actions-inline { display:inline-flex; gap:.3rem; margin-top:.45rem; }
          .sb-mh-listing-row .row-actions-inline a { font-size:.7rem; color:#475569; text-decoration:none; padding:1px 7px; border:1px solid #cbd5e1; border-radius:5px; font-weight:700; }
          .sb-mh-listing-row .row-actions-inline a:hover { background:#f1f5f9; color:#1a2533; }
          .sb-mh-empty { color:#94a3b8; text-align:center; padding:2rem; font-size:.82rem; }
          .sb-mh-sub-row, .sb-mh-claim-row { padding:.85rem 0; border-bottom:1px solid #f1f5f9; }
          .sb-mh-sub-row:last-child, .sb-mh-claim-row:last-child { border-bottom:0; }
          .sb-mh-sub-head { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:.4rem; font-size:.9rem; color:#1a2533; }
          .sb-mh-sub-body { font-size:.78rem; color:#475569; line-height:1.65; display:grid; grid-template-columns:1fr 1fr; gap:.15rem .8rem; }
          .sb-mh-sub-body span { color:#94a3b8; font-weight:600; margin-right:.25rem; }
          .sb-mh-sub-body code { background:#f1f5f9; padding:1px 5px; border-radius:4px; font-size:.72rem; }
          .sb-mh-timeline { list-style:none; padding:0; margin:0; }
          .sb-mh-timeline li { display:flex; gap:.7rem; align-items:center; padding:.55rem 0; border-bottom:1px solid #f1f5f9; font-size:.83rem; color:#1a2533; }
          .sb-mh-timeline li:last-child { border-bottom:0; }
          .sb-mh-tl-icon { width:24px; height:24px; border-radius:50%; flex-shrink:0; background:#e6fbfb; color:#0e7490; font-size:.7rem; font-weight:800; display:grid; place-items:center; }
          .sb-mh-tl-icon.icon-user::before  { content:"U"; }
          .sb-mh-tl-icon.icon-home::before  { content:"H"; }
          .sb-mh-tl-icon.icon-pause::before { content:"||"; font-size:.6rem; }
          .sb-mh-tl-icon.icon-card::before  { content:"P"; }
          .sb-mh-tl-icon.icon-mail::before  { content:"@"; font-size:.7rem; }
          .sb-mh-tl-icon.icon-check::before { content:"OK"; color:#047857; font-size:.6rem; }
          .sb-mh-tl-icon.icon-x::before     { content:"X"; color:#b91c1c; font-size:.7rem; }
          .sb-mh-tl-label { flex:1; }
          .sb-mh-tl-when  { color:#94a3b8; font-size:.7rem; white-space:nowrap; }
          .sb-mh-note { background:#f0fdfd; border:1px solid #a5e9e9; border-radius:8px; padding:.7rem .9rem; margin-bottom:.7rem; }
          .sb-mh-note-head { display:flex; gap:.6rem; align-items:center; margin-bottom:.3rem; font-size:.78rem; color:#1a2533; }
          .sb-mh-note-del { margin-left:auto; background:none; border:0; color:#94a3b8; cursor:pointer; font-size:1.1rem; line-height:1; padding:0 4px; }
          .sb-mh-note-del:hover { color:#b91c1c; }
          .sb-mh-note-body { font-size:.83rem; color:#475569; line-height:1.55; }
          .sb-mh-note-form textarea { width:100%; padding:.7rem .9rem; border:1px solid #cbd5e1; border-radius:8px; font:inherit; font-size:.85rem; resize:vertical; min-height:70px; box-sizing:border-box; }
          .sb-mh-note-form-actions { margin-top:.55rem; text-align:right; }
          .sb-mh-suspend-banner { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:.55rem .85rem; border-radius:8px; font-size:.78rem; font-weight:600; margin-bottom:.85rem; }
        </style>';
    }
}

// ─ Render the admin page ─
if (!function_exists('sb_hosts_render_page')) {
    function sb_hosts_render_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorised');
        $filters = [
            'search' => sanitize_text_field(wp_unslash($_GET['search'] ?? '')),
            'tier'   => sanitize_key($_GET['tier']   ?? ''),
            'status' => sanitize_key($_GET['status'] ?? ''),
        ];
        $hosts = sb_hosts_get_all($filters);
        $total = count($hosts);
        $tier_labels = sb_hosts_tier_labels();
        $nonce = wp_create_nonce('wp_rest');

        echo sb_hosts_styles();
        echo '<div class="wrap sb-mh-wrap">';
        echo '<h1>Hosts <span class="sb-mh-count">' . number_format($total) . '</span></h1>';

        $sel = function ($v, $cur) { return $v === $cur ? ' selected' : ''; };
        echo '<form class="sb-mh-toolbar" method="get">'
          . '<input type="hidden" name="page" value="limasawa-hosts" />'
          . '<input type="search" name="search" value="' . esc_attr($filters['search']) . '" placeholder="Search by name, email, or login..." />'
          . '<select name="tier">'
          .   '<option value="">All tiers</option>'
          .   '<option value="free"'     . $sel('free',     $filters['tier']) . '>Free</option>'
          .   '<option value="pro"'      . $sel('pro',      $filters['tier']) . '>Pro</option>'
          .   '<option value="featured"' . $sel('featured', $filters['tier']) . '>Featured</option>'
          . '</select>'
          . '<select name="status">'
          .   '<option value="">All status</option>'
          .   '<option value="active"'   . $sel('active',   $filters['status']) . '>Active (1+ published)</option>'
          .   '<option value="inactive"' . $sel('inactive', $filters['status']) . '>Inactive</option>'
          .   '<option value="flagged"'  . $sel('flagged',  $filters['status']) . '>Has pending</option>'
          . '</select>'
          . '<button type="submit" class="button button-primary">Filter</button>'
          . '<a href="?page=limasawa-hosts" class="button">Reset</a>'
          . '</form>';

        echo '<table class="wp-list-table widefat fixed striped sb-mh-table"><thead><tr>'
          . '<th style="width:20%">Host</th>'
          . '<th style="width:16%">Email</th>'
          . '<th style="width:14%">Listings</th>'
          . '<th style="width:8%">Tier</th>'
          . '<th style="width:8%">MRR</th>'
          . '<th style="width:8%">Views 30d</th>'
          . '<th style="width:8%">Rating</th>'
          . '<th style="width:8%">Flags</th>'
          . '<th style="width:10%">Actions</th>'
          . '</tr></thead><tbody>';

        if (empty($hosts)) {
            echo '<tr><td colspan="9" class="sb-mh-empty">No hosts match your filters.</td></tr>';
        } else {
            foreach ($hosts as $h) {
                $listings_html = '';
                if ($h['listings_publish']) $listings_html .= '<span class="sb-mh-pill pill-ok">'   . (int) $h['listings_publish'] . ' live</span>';
                if ($h['listings_draft'])   $listings_html .= '<span class="sb-mh-pill pill-mute">' . (int) $h['listings_draft']   . ' paused</span>';
                if ($h['listings_pending']) $listings_html .= '<span class="sb-mh-pill pill-warn">' . (int) $h['listings_pending'] . ' pending</span>';
                if ($h['listings_trash'])   $listings_html .= '<span class="sb-mh-pill pill-mute">' . (int) $h['listings_trash']   . ' trash</span>';
                if (!$h['listings_total'])  $listings_html  = '<span style="color:#94a3b8;font-size:.7rem">-</span>';

                $flags_html = '';
                if ($h['pending_claims_inbound']) $flags_html .= '<span class="sb-mh-flag" title="Pending claim(s)">' . (int) $h['pending_claims_inbound'] . ' claim</span>';
                if ($h['pending_payments'])       $flags_html .= '<span class="sb-mh-flag" title="Pending payment">' . (int) $h['pending_payments'] . ' pay</span>';
                if (!$flags_html)                 $flags_html  = '<span style="color:#94a3b8;font-size:.7rem">-</span>';

                $tier = $h['max_tier'];
                $tier_label = $tier_labels[$tier] ?? $tier;
                $mrr = $h['mrr'] > 0 ? 'PHP ' . number_format($h['mrr']) : '-';
                $rating = $h['rating_avg'] > 0 ? esc_html($h['rating_avg']) : '-';
                $edit_user = esc_url(admin_url('user-edit.php?user_id=' . (int) $h['id']));

                echo '<tr class="sb-mh-row" data-host-id="' . (int) $h['id'] . '">'
                  . '<td><div class="sb-mh-host"><img src="' . esc_url($h['avatar']) . '" alt="" /><div>'
                  .   '<strong>' . esc_html($h['display_name']) . '</strong>'
                  .   '<span class="sb-mh-meta">@' . esc_html($h['user_login']) . '</span>'
                  . '</div></div></td>'
                  . '<td><span style="color:#475569;font-size:.78rem">' . esc_html($h['user_email']) . '</span></td>'
                  . '<td>' . $listings_html . '</td>'
                  . '<td><span class="sb-mh-tier tier-' . esc_attr($tier) . '">' . esc_html($tier_label) . '</span></td>'
                  . '<td>' . $mrr . '</td>'
                  . '<td style="color:#475569">' . (int) $h['views_30d'] . '</td>'
                  . '<td style="color:#475569">' . $rating . '</td>'
                  . '<td>' . $flags_html . '</td>'
                  . '<td>'
                  .   '<button type="button" class="button button-small sb-mh-view" data-host-id="' . (int) $h['id'] . '">View</button> '
                  .   '<a href="' . $edit_user . '" class="button button-small">Edit</a>'
                  . '</td>'
                  . '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<div id="sb-mh-drawer" class="sb-mh-drawer" hidden>'
          . '<div class="sb-mh-drawer-backdrop"></div>'
          . '<div class="sb-mh-drawer-panel">'
          .   '<button type="button" class="sb-mh-drawer-close" aria-label="Close">&times;</button>'
          .   '<div id="sb-mh-drawer-content"><div class="sb-mh-empty">Loading...</div></div>'
          . '</div>'
          . '</div>';
        echo '</div>';

        echo sb_hosts_drawer_js($nonce);
    }
}

// ─ REST: per-host detail (drawer payload) ─
if (!function_exists('sb_hosts_rest_detail')) {
    function sb_hosts_rest_detail(WP_REST_Request $req) {
        $uid = (int) $req['id'];
        $user = $uid ? get_userdata($uid) : null;
        if (!$user) return new WP_Error('not_found', 'Host not found', ['status' => 404]);

        global $wpdb;
        $listing_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='accommodation' AND post_author=%d ORDER BY post_date DESC",
            $uid
        ));

        // Phone from the first listing that has the ACF host contact number
        $phone = '';
        foreach ($listing_ids as $id) {
            $p = (string) (function_exists('get_field')
                ? get_field('accommodation_host_contact_number', $id)
                : get_post_meta($id, 'accommodation_host_contact_number', true));
            if ($p) { $phone = $p; break; }
        }
        $whatsapp_url = sb_hosts_wa_url($phone);

        $last_login = (int) get_user_meta($uid, 'sb_last_login', true);
        $last_active_ts = $last_login ?: strtotime($user->user_registered);

        $tier_labels   = sb_hosts_tier_labels();
        $status_labels = ['publish' => 'Live', 'pending' => 'Pending', 'draft' => 'Paused', 'trash' => 'Trashed', 'future' => 'Scheduled'];

        $listings = [];
        $subscriptions = [];
        $max_tier = 'free';
        foreach ($listing_ids as $id) {
            $p = get_post($id);
            if (!$p) continue;

            $tier = get_post_meta($id, 'listing_tier', true) ?: 'free';
            if (!isset($tier_labels[$tier])) $tier = 'free';
            if (sb_hosts_tier_rank($tier) > sb_hosts_tier_rank($max_tier)) $max_tier = $tier;

            // Thumbnail: first gallery image, fall back to featured image
            $thumb = '';
            if (function_exists('get_field')) {
                $gal = get_field('accommodation_gallery', $id);
                if (is_array($gal) && !empty($gal)) {
                    $first = $gal[0];
                    $thumb = is_array($first) ? ($first['sizes']['thumbnail'] ?? $first['sizes']['medium'] ?? $first['url'] ?? '') : '';
                }
            }
            if (!$thumb) $thumb = (string) get_the_post_thumbnail_url($id, 'thumbnail');

            $listings[] = [
                'id'           => (int) $id,
                'title'        => $p->post_title,
                'status'       => $p->post_status,
                'status_label' => $status_labels[$p->post_status] ?? ucfirst($p->post_status),
                'tier'         => $tier,
                'views_30d'    => sb_hosts_views_for($id, 30),
                'thumb'        => $thumb,
                'edit_url'     => get_edit_post_link($id, 'raw'),
                'public_url'   => $p->post_status === 'publish' ? get_permalink($id) : '',
            ];

            // Subscription snapshot from sb_payment array
            $pay = get_post_meta($id, 'sb_payment', true);
            if (!is_array($pay)) $pay = [];
            $status  = $pay['status'] ?? ($tier === 'free' ? 'not_required' : '');
            $exp_raw = $pay['expiresAt'] ?? '';
            $exp_ts  = $exp_raw ? strtotime($exp_raw) : 0;
            $now_ts  = current_time('timestamp');
            $remaining_days = $exp_ts ? max(0, (int) ceil(($exp_ts - $now_ts) / DAY_IN_SECONDS)) : null;
            $paid_raw = $pay['paidAt'] ?? '';

            $subscriptions[] = [
                'listing_id'   => (int) $id,
                'title'        => $p->post_title,
                'tier'         => $tier,
                'status'       => $status,
                'status_label' => [
                    'paid'           => 'Paid',
                    'pending_review' => 'Pending review',
                    'not_required'   => 'Free plan',
                    'expired'        => 'Expired (downgraded)',
                ][$status] ?? ($status ?: '-'),
                'billing'        => $pay['billingPeriod'] ?? 'monthly',
                'amount'         => (int) ($pay['amount'] ?? 0),
                'method'         => $pay['method'] ?? '',
                'reference'      => $pay['reference'] ?? '',
                'coupon'         => $pay['couponCode'] ?? '',
                'paid_at'        => $paid_raw ? date_i18n('M j, Y', strtotime($paid_raw)) : '',
                'expires_at'     => $exp_raw ? date_i18n('M j, Y', $exp_ts) : '',
                'days_remaining' => $remaining_days,
            ];
        }

        // Claims this host submitted
        $claims = [];
        $claims_table = $wpdb->prefix . 'sb_claims';
        if ($wpdb->get_var("SHOW TABLES LIKE '$claims_table'") === $claims_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, p.post_title FROM $claims_table c
                 LEFT JOIN {$wpdb->posts} p ON p.ID = c.listing_id
                 WHERE c.claimant_user_id = %d
                 ORDER BY c.created_at DESC LIMIT 50",
                $uid
            ));
            foreach ($rows as $r) {
                $stored_phone = (string) (function_exists('get_field')
                    ? get_field('accommodation_host_contact_number', $r->listing_id)
                    : get_post_meta($r->listing_id, 'accommodation_host_contact_number', true));
                $phone_match = function_exists('sb_claims_phones_match') && sb_claims_phones_match($r->claimant_phone, $stored_phone);
                $claims[] = [
                    'id'            => (int) $r->id,
                    'listing_id'    => (int) $r->listing_id,
                    'listing_title' => $r->post_title ?: ('#' . $r->listing_id),
                    'status'        => $r->status,
                    'phone'         => $r->claimant_phone,
                    'phone_match'   => (bool) $phone_match,
                    'message'       => $r->message,
                    'decision_note' => $r->decision_note,
                    'created_at'    => date_i18n('M j, Y H:i', strtotime($r->created_at)),
                    'reviewed_at'   => $r->reviewed_at ? date_i18n('M j, Y H:i', strtotime($r->reviewed_at)) : '',
                ];
            }
        }

        // Activity log aggregated from existing data
        $events = [];
        $events[] = ['ts' => strtotime($user->user_registered), 'icon' => 'user', 'label' => 'Joined Limasawa Booking'];
        foreach ($listing_ids as $id) {
            $p = get_post($id);
            if (!$p) continue;
            $events[] = ['ts' => strtotime($p->post_date), 'icon' => 'home', 'label' => 'Created listing "' . $p->post_title . '"', 'listing_id' => (int) $id];
            if ($p->post_status === 'draft' && strtotime($p->post_modified) > strtotime($p->post_date)) {
                $events[] = ['ts' => strtotime($p->post_modified), 'icon' => 'pause', 'label' => 'Paused listing "' . $p->post_title . '"', 'listing_id' => (int) $id];
            }
            $pay = get_post_meta($id, 'sb_payment', true);
            if (is_array($pay) && !empty($pay['paidAt'])) {
                $events[] = ['ts' => strtotime($pay['paidAt']), 'icon' => 'card', 'label' => 'Payment activated for "' . $p->post_title . '"', 'listing_id' => (int) $id];
            }
        }
        foreach ($claims as $c) {
            $events[] = ['ts' => strtotime($c['created_at']), 'icon' => 'mail', 'label' => 'Submitted claim for "' . $c['listing_title'] . '"'];
            if ($c['reviewed_at']) {
                $events[] = ['ts' => strtotime($c['reviewed_at']), 'icon' => $c['status'] === 'approved' ? 'check' : 'x', 'label' => 'Claim ' . $c['status'] . ' for "' . $c['listing_title'] . '"'];
            }
        }
        usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
        $activity = array_map(function ($e) {
            return [
                'when'       => human_time_diff($e['ts']) . ' ago',
                'date'       => date_i18n('M j, Y', $e['ts']),
                'icon'       => $e['icon'],
                'label'      => $e['label'],
                'listing_id' => $e['listing_id'] ?? null,
            ];
        }, array_slice($events, 0, 50));

        $notes = sb_hosts_read_notes($uid);
        foreach ($notes as &$n) {
            $n['date_label'] = isset($n['ts']) ? date_i18n('M j, Y H:i', $n['ts']) : '';
        }
        unset($n);

        $suspended = (bool) get_user_meta($uid, 'sb_user_suspended', true);

        return rest_ensure_response([
            'id'                => (int) $uid,
            'display_name'      => $user->display_name,
            'user_login'        => $user->user_login,
            'user_email'        => $user->user_email,
            'avatar'            => get_avatar_url($uid, ['size' => 96]),
            'roles'             => array_values((array) $user->roles),
            'phone'             => $phone,
            'whatsapp_url'      => $whatsapp_url,
            'registered_label'  => date_i18n('M j, Y', strtotime($user->user_registered)),
            'last_active_label' => human_time_diff($last_active_ts) . ' ago',
            'max_tier'          => $max_tier,
            'suspended'         => $suspended,
            'listings'          => $listings,
            'subscriptions'     => $subscriptions,
            'claims'            => $claims,
            'activity'          => $activity,
            'notes'             => array_values($notes),
        ]);
    }
}

// ─ REST: add/delete an admin note (user meta sb_admin_notes) ─
if (!function_exists('sb_hosts_rest_notes')) {
    function sb_hosts_rest_notes(WP_REST_Request $req) {
        $uid = (int) $req['id'];
        if (!$uid || !get_userdata($uid)) return new WP_Error('not_found', 'Host not found', ['status' => 404]);
        $body   = $req->get_json_params() ?: [];
        $action = sanitize_key($body['action'] ?? '');
        $notes  = sb_hosts_read_notes($uid);

        if ($action === 'add') {
            $text = trim((string) ($body['body'] ?? ''));
            if ($text === '') return new WP_Error('empty', 'Note body is empty', ['status' => 400]);
            $author = wp_get_current_user();
            $notes[] = [
                'id'          => uniqid('n_', true),
                'ts'          => current_time('timestamp'),
                'author_id'   => (int) $author->ID,
                'author_name' => $author->display_name,
                'body'        => sanitize_textarea_field($text),
            ];
        } elseif ($action === 'delete') {
            $note_id = sanitize_text_field((string) ($body['note_id'] ?? ''));
            if (!$note_id) return new WP_Error('bad_request', 'Missing note_id', ['status' => 400]);
            $notes = array_values(array_filter($notes, fn($n) => ($n['id'] ?? '') !== $note_id));
        } else {
            return new WP_Error('bad_request', 'action must be add or delete', ['status' => 400]);
        }

        update_user_meta($uid, 'sb_admin_notes', $notes);
        foreach ($notes as &$n) {
            $n['date_label'] = isset($n['ts']) ? date_i18n('M j, Y H:i', $n['ts']) : '';
        }
        unset($n);
        return rest_ensure_response(['success' => true, 'notes' => array_values($notes)]);
    }
}

// ─ REST: suspend toggle (user meta sb_user_suspended) ─
if (!function_exists('sb_hosts_rest_suspend')) {
    function sb_hosts_rest_suspend(WP_REST_Request $req) {
        $uid = (int) $req['id'];
        if (!$uid || !get_userdata($uid)) return new WP_Error('not_found', 'Host not found', ['status' => 404]);
        if ($uid === get_current_user_id()) return new WP_Error('cannot_suspend_self', 'You cannot suspend yourself.', ['status' => 400]);
        $body = $req->get_json_params() ?: [];
        $on = !empty($body['suspend']);
        if ($on) update_user_meta($uid, 'sb_user_suspended', '1');
        else     delete_user_meta($uid, 'sb_user_suspended');
        return rest_ensure_response(['success' => true, 'suspended' => $on]);
    }
}

// ─ Drawer JS (5 tabs + notes write + suspend; no impersonation) ─
if (!function_exists('sb_hosts_drawer_js')) {
    function sb_hosts_drawer_js($nonce) {
        $n = esc_js($nonce);
        return <<<JS
<script>
(function () {
  const drawer   = document.getElementById('sb-mh-drawer');
  const content  = document.getElementById('sb-mh-drawer-content');
  const closeBtn = drawer.querySelector('.sb-mh-drawer-close');
  const backdrop = drawer.querySelector('.sb-mh-drawer-backdrop');
  const NONCE = '{$n}';
  let currentHost = null;

  function close() { drawer.hidden = true; currentHost = null; }
  closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !drawer.hidden) close(); });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  const TIER_LABELS = { free:'Free', pro:'Pro', featured:'Featured' };
  const STATUS_PILLS = { publish:'pill-ok', pending:'pill-warn', draft:'pill-mute', trash:'pill-mute', future:'pill-mute' };

  async function open(hostId) {
    drawer.hidden = false;
    content.innerHTML = '<div class="sb-mh-empty">Loading...</div>';
    try {
      const res = await fetch('/wp-json/limasawa/v1/admin/host/' + hostId, {
        credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      currentHost = await res.json();
      content.innerHTML = renderDrawer(currentHost);
      wireTabs(); wireNotesForm(); wireSuspendBtn();
    } catch (e) {
      content.innerHTML = '<div class="sb-mh-empty">Could not load. ' + (e.message || '') + '</div>';
    }
  }

  document.querySelectorAll('.sb-mh-view').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault(); e.stopPropagation();
      const id = btn.dataset.hostId;
      if (id) open(id);
    });
  });
  document.querySelectorAll('.sb-mh-row').forEach(row => {
    row.addEventListener('click', e => {
      if (e.target.closest('a, button')) return;
      const id = row.dataset.hostId;
      if (id) open(id);
    });
  });

  function renderDrawer(d) {
    const tierClass = 'tier-' + esc(d.max_tier);
    const tierLabel = TIER_LABELS[d.max_tier] || d.max_tier;
    const phone = d.phone ? '&middot; <a href="tel:' + esc(d.phone) + '">' + esc(d.phone) + '</a>' : '';
    const wa    = d.whatsapp_url ? '&middot; <a href="' + esc(d.whatsapp_url) + '" target="_blank" rel="noopener">WhatsApp</a>' : '';
    const mailto = 'mailto:' + esc(d.user_email);
    const waBtn = d.whatsapp_url ? '<a href="' + esc(d.whatsapp_url) + '" target="_blank" rel="noopener">WhatsApp</a>' : '';
    const userEditUrl = '/wp-admin/user-edit.php?user_id=' + d.id;
    const suspendBtn = d.suspended
      ? '<a class="danger" href="#" id="sb-mh-unsuspend">Unsuspend</a>'
      : '<a class="danger" href="#" id="sb-mh-suspend">Suspend</a>';
    const suspendBanner = d.suspended
      ? '<div class="sb-mh-suspend-banner">This host is suspended.</div>'
      : '';

    return '' +
      '<div class="sb-mh-drawer-header">' +
        suspendBanner +
        '<div class="sb-mh-host">' +
          '<img src="' + esc(d.avatar) + '" alt="" />' +
          '<div>' +
            '<strong>' + esc(d.display_name) + '</strong>' +
            '<span class="sb-mh-meta">@' + esc(d.user_login) + ' &middot; ' + esc((d.roles||[]).join(', ')) + '</span>' +
          '</div>' +
          '<span class="sb-mh-tier ' + tierClass + '" style="margin-left:auto">' + esc(tierLabel) + '</span>' +
        '</div>' +
        '<div class="sb-mh-drawer-meta">' +
          '<a href="' + mailto + '">' + esc(d.user_email) + '</a> ' + phone + ' ' + wa +
          '<br>Joined ' + esc(d.registered_label) + ' &middot; last seen ' + esc(d.last_active_label) +
        '</div>' +
        '<div class="sb-mh-drawer-actions">' +
          '<a class="primary" href="' + userEditUrl + '">Edit user</a>' +
          '<a href="' + mailto + '">Email</a>' +
          waBtn +
          suspendBtn +
        '</div>' +
      '</div>' +
      '<div class="sb-mh-drawer-tabs">' +
        '<button type="button" class="sb-mh-drawer-tab active" data-tab="listings">Listings (' + (d.listings || []).length + ')</button>' +
        '<button type="button" class="sb-mh-drawer-tab" data-tab="subs">Subscriptions</button>' +
        '<button type="button" class="sb-mh-drawer-tab" data-tab="claims">Claims (' + (d.claims || []).length + ')</button>' +
        '<button type="button" class="sb-mh-drawer-tab" data-tab="activity">Activity</button>' +
        '<button type="button" class="sb-mh-drawer-tab" data-tab="notes">Notes (' + (d.notes || []).length + ')</button>' +
      '</div>' +
      '<div class="sb-mh-drawer-body">' +
        '<div data-tab-pane="listings">' + renderListings(d) + '</div>' +
        '<div data-tab-pane="subs" hidden>' + renderSubs(d) + '</div>' +
        '<div data-tab-pane="claims" hidden>' + renderClaims(d) + '</div>' +
        '<div data-tab-pane="activity" hidden>' + renderActivity(d) + '</div>' +
        '<div data-tab-pane="notes" hidden>' + renderNotes(d) + '</div>' +
      '</div>';
  }

  function renderListings(d) {
    if (!(d.listings || []).length) return '<div class="sb-mh-empty">No listings yet.</div>';
    return d.listings.map(l => {
      const tierName = TIER_LABELS[l.tier] || l.tier;
      const statusCls = STATUS_PILLS[l.status] || 'pill-mute';
      const thumb = l.thumb
        ? '<img src="' + esc(l.thumb) + '" alt="" />'
        : '<div style="width:64px;height:64px;border-radius:8px;background:#e2e8f0"></div>';
      const viewLink = l.public_url ? '<a href="' + esc(l.public_url) + '" target="_blank" rel="noopener">View</a>' : '';
      return '<div class="sb-mh-listing-row">' + thumb +
        '<div style="flex:1;min-width:0">' +
          '<a class="title-link" href="' + esc(l.edit_url) + '">' + esc(l.title) + '</a>' +
          '<div class="meta-line">' +
            '<span class="sb-mh-pill ' + statusCls + '">' + esc(l.status_label) + '</span>' +
            '<span class="sb-mh-tier tier-' + esc(l.tier) + '">' + esc(tierName) + '</span>' +
            '<span>' + (l.views_30d || 0) + ' views (30d)</span>' +
          '</div>' +
          '<div class="row-actions-inline">' +
            '<a href="' + esc(l.edit_url) + '">Edit</a>' + viewLink +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function renderSubs(d) {
    if (!(d.subscriptions || []).length) return '<div class="sb-mh-empty">No listings, no subscriptions.</div>';
    return d.subscriptions.map(s => {
      const tier = TIER_LABELS[s.tier] || s.tier;
      const statusCls = s.status === 'paid' ? 'pill-ok' : ((s.status === 'pending_review' || s.status === 'expired') ? 'pill-warn' : 'pill-mute');
      const expCls = (s.days_remaining != null && s.days_remaining <= 7) ? 'pill-warn' : 'pill-mute';
      const expPill = s.expires_at ? '<span class="sb-mh-pill ' + expCls + '" title="Subscription expiry">Expires ' + esc(s.expires_at) + (s.days_remaining != null ? ' (' + s.days_remaining + 'd)' : '') + '</span>' : '';
      const amount = s.amount > 0 ? 'PHP ' + Number(s.amount).toLocaleString() + ' (' + s.billing + ')' : '';
      return '<div class="sb-mh-sub-row">' +
        '<div class="sb-mh-sub-head">' +
          '<strong>' + esc(s.title) + '</strong>' +
          '<span class="sb-mh-tier tier-' + esc(s.tier) + '">' + esc(tier) + '</span>' +
          '<span class="sb-mh-pill ' + statusCls + '">' + esc(s.status_label) + '</span>' +
          expPill +
        '</div>' +
        '<div class="sb-mh-sub-body">' +
          (amount ? '<div><span>Amount:</span> ' + amount + '</div>' : '') +
          (s.method ? '<div><span>Method:</span> ' + esc(s.method) + '</div>' : '') +
          (s.reference ? '<div><span>Ref:</span> <code>' + esc(s.reference) + '</code></div>' : '') +
          (s.coupon ? '<div><span>Coupon:</span> <code>' + esc(s.coupon) + '</code></div>' : '') +
          (s.paid_at ? '<div><span>Paid:</span> ' + esc(s.paid_at) + '</div>' : '') +
        '</div>' +
      '</div>';
    }).join('');
  }

  function renderClaims(d) {
    if (!(d.claims || []).length) return '<div class="sb-mh-empty">No claims submitted.</div>';
    return d.claims.map(c => {
      const statusCls = c.status === 'approved' ? 'pill-ok' : (c.status === 'rejected' ? 'pill-warn' : 'pill-mute');
      const phoneIcon = c.phone_match ? 'OK ' : '';
      const reviewed = c.reviewed_at ? ' &middot; reviewed ' + esc(c.reviewed_at) : '';
      return '<div class="sb-mh-claim-row">' +
        '<div class="sb-mh-sub-head">' +
          '<strong>' + esc(c.listing_title) + '</strong>' +
          '<span class="sb-mh-pill ' + statusCls + '">' + esc(c.status) + '</span>' +
        '</div>' +
        '<div class="sb-mh-sub-body">' +
          '<div><span>Phone:</span> ' + phoneIcon + esc(c.phone) + (c.phone_match ? ' <em style="color:#047857">(matches stored)</em>' : '') + '</div>' +
          (c.message ? '<div><span>Message:</span> ' + esc(c.message) + '</div>' : '') +
          (c.decision_note ? '<div><span>Decision note:</span> ' + esc(c.decision_note) + '</div>' : '') +
          '<div style="color:#94a3b8;font-size:.7rem">Submitted ' + esc(c.created_at) + reviewed + '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function renderActivity(d) {
    if (!(d.activity || []).length) return '<div class="sb-mh-empty">No activity yet.</div>';
    return '<ul class="sb-mh-timeline">' + d.activity.map(e => {
      return '<li>' +
        '<span class="sb-mh-tl-icon icon-' + esc(e.icon) + '"></span>' +
        '<span class="sb-mh-tl-label">' + esc(e.label) + '</span>' +
        '<span class="sb-mh-tl-when" title="' + esc(e.date) + '">' + esc(e.when) + '</span>' +
      '</li>';
    }).join('') + '</ul>';
  }

  function renderNotes(d) {
    const list = (d.notes || []).slice().reverse().map(nn => {
      return '<div class="sb-mh-note" data-note-id="' + esc(nn.id) + '">' +
        '<div class="sb-mh-note-head">' +
          '<strong>' + esc(nn.author_name) + '</strong>' +
          '<span style="color:#94a3b8;font-size:.7rem">' + esc(nn.date_label) + '</span>' +
          '<button type="button" class="sb-mh-note-del" data-note-id="' + esc(nn.id) + '" title="Delete">&times;</button>' +
        '</div>' +
        '<div class="sb-mh-note-body">' + esc(nn.body).replace(/\\n/g, '<br>') + '</div>' +
      '</div>';
    }).join('');
    return list +
      '<form id="sb-mh-note-form" class="sb-mh-note-form">' +
        '<textarea id="sb-mh-note-body" rows="3" placeholder="Add an admin-only note (host never sees this)..."></textarea>' +
        '<div class="sb-mh-note-form-actions">' +
          '<button type="submit" class="button button-primary">Add note</button>' +
        '</div>' +
      '</form>';
  }

  function wireTabs() {
    document.querySelectorAll('.sb-mh-drawer-tab').forEach(btn => {
      if (btn.disabled) return;
      btn.addEventListener('click', () => {
        document.querySelectorAll('.sb-mh-drawer-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const tab = btn.dataset.tab;
        document.querySelectorAll('[data-tab-pane]').forEach(p => p.hidden = p.dataset.tabPane !== tab);
      });
    });
  }

  function wireNotesForm() {
    const form = document.getElementById('sb-mh-note-form');
    if (form) {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const body = (document.getElementById('sb-mh-note-body').value || '').trim();
        if (!body) return;
        const res = await fetch('/wp-json/limasawa/v1/admin/host/' + currentHost.id + '/notes', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
          body: JSON.stringify({ action: 'add', body })
        });
        if (!res.ok) { alert('Could not add note.'); return; }
        const data = await res.json();
        currentHost.notes = data.notes;
        const pane = document.querySelector('[data-tab-pane="notes"]');
        if (pane) { pane.innerHTML = renderNotes(currentHost); wireNotesForm(); }
        const tabBtn = document.querySelector('.sb-mh-drawer-tab[data-tab="notes"]');
        if (tabBtn) tabBtn.textContent = 'Notes (' + currentHost.notes.length + ')';
      });
    }
    document.querySelectorAll('.sb-mh-note-del').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this note?')) return;
        const noteId = btn.dataset.noteId;
        const res = await fetch('/wp-json/limasawa/v1/admin/host/' + currentHost.id + '/notes', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
          body: JSON.stringify({ action: 'delete', note_id: noteId })
        });
        if (!res.ok) { alert('Could not delete note.'); return; }
        const data = await res.json();
        currentHost.notes = data.notes;
        const pane = document.querySelector('[data-tab-pane="notes"]');
        if (pane) { pane.innerHTML = renderNotes(currentHost); wireNotesForm(); }
        const tabBtn = document.querySelector('.sb-mh-drawer-tab[data-tab="notes"]');
        if (tabBtn) tabBtn.textContent = 'Notes (' + currentHost.notes.length + ')';
      });
    });
  }

  function wireSuspendBtn() {
    const sus = document.getElementById('sb-mh-suspend');
    const uns = document.getElementById('sb-mh-unsuspend');
    const btn = sus || uns;
    if (!btn) return;
    btn.addEventListener('click', async e => {
      e.preventDefault();
      const wantSuspend = !!sus;
      const verb = wantSuspend ? 'suspend' : 'unsuspend';
      if (!confirm('Are you sure you want to ' + verb + ' this host?')) return;
      const res = await fetch('/wp-json/limasawa/v1/admin/host/' + currentHost.id + '/suspend', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
        body: JSON.stringify({ suspend: wantSuspend })
      });
      const data = await res.json();
      if (!res.ok || !data.success) { alert(data.message || 'Could not update.'); return; }
      currentHost.suspended = data.suspended;
      content.innerHTML = renderDrawer(currentHost);
      wireTabs(); wireNotesForm(); wireSuspendBtn();
    });
  }
})();
</script>
JS;
    }
}
