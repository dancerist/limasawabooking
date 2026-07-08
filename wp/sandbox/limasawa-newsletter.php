<?php
/**
 * limasawa-newsletter.php — newsletter signups for limasawabooking.com
 *
 * - wp_sb_subscribers table (email unique, status, source, created_at)
 * - POST limasawa/v1/subscribe  {email, source?, website?(honeypot)}
 *   Public, idempotent (re-subscribing an existing email → success),
 *   IP-throttled via transient. CORS comes from limasawa-auth.php.
 * - wp-admin "Subscribers" page (manage_options): table + CSV export.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('sb_newsletter_table')) {
    function sb_newsletter_table() {
        global $wpdb;
        return $wpdb->prefix . 'sb_subscribers';
    }
}

if (!function_exists('sb_newsletter_install')) {
    function sb_newsletter_install() {
        global $wpdb;
        if (get_option('sb_newsletter_db_version') === '1') return;
        $table = sb_newsletter_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            source VARCHAR(60) NOT NULL DEFAULT 'footer',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) {$charset};");
        update_option('sb_newsletter_db_version', '1');
    }
    add_action('init', 'sb_newsletter_install');
}

if (!function_exists('sb_newsletter_routes')) {
    function sb_newsletter_routes() {
        register_rest_route('limasawa/v1', '/subscribe', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_newsletter_subscribe',
        ]);
    }
    add_action('rest_api_init', 'sb_newsletter_routes');
}

if (!function_exists('sb_newsletter_subscribe')) {
    function sb_newsletter_subscribe(WP_REST_Request $req) {
        global $wpdb;

        // Honeypot: real users never fill this hidden field.
        if (trim((string) $req->get_param('website')) !== '') {
            return new WP_REST_Response(['status' => 'ok'], 200); // silently accept
        }

        $email = sanitize_email((string) $req->get_param('email'));
        if (!$email || !is_email($email)) {
            return new WP_REST_Response(['status' => 'error', 'code' => 'INVALID_EMAIL', 'message' => 'Please enter a valid email address.'], 400);
        }

        // Light throttle: max 5 signups per IP per 10 minutes.
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR']) : 'unknown';
        $key = 'sb_nl_' . md5($ip);
        $n   = (int) get_transient($key);
        if ($n >= 5) {
            return new WP_REST_Response(['status' => 'error', 'code' => 'RATE_LIMITED', 'message' => 'Too many attempts — please try again later.'], 429);
        }
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

        $source = substr(sanitize_key((string) ($req->get_param('source') ?: 'footer')), 0, 60);
        $table  = sb_newsletter_table();
        $exists = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE email = %s", $email));

        if ($exists) {
            if ($exists->status !== 'active') {
                $wpdb->update($table, ['status' => 'active'], ['id' => (int) $exists->id]);
            }
            return new WP_REST_Response(['status' => 'ok', 'message' => "You're on the list!"], 200);
        }

        $wpdb->insert($table, [
            'email'      => $email,
            'status'     => 'active',
            'source'     => $source,
            'created_at' => current_time('mysql'),
        ]);

        return new WP_REST_Response(['status' => 'ok', 'message' => "You're on the list!"], 200);
    }
}

/* ── wp-admin: Subscribers page + CSV export ───────────────────── */

if (!function_exists('sb_newsletter_admin_menu')) {
    function sb_newsletter_admin_menu() {
        add_menu_page('Subscribers', 'Subscribers', 'manage_options', 'sb-subscribers', 'sb_newsletter_render_page', 'dashicons-email-alt2', 27);
    }
    add_action('admin_menu', 'sb_newsletter_admin_menu');
}

if (!function_exists('sb_newsletter_csv_export')) {
    function sb_newsletter_csv_export() {
        if (!isset($_GET['page'], $_GET['sb_nl_export']) || $_GET['page'] !== 'sb-subscribers') return;
        if (!current_user_can('manage_options') || !check_admin_referer('sb_nl_export')) return;
        global $wpdb;
        $rows = $wpdb->get_results('SELECT email, status, source, created_at FROM ' . sb_newsletter_table() . ' ORDER BY created_at DESC', ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=limasawa-subscribers-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'status', 'source', 'created_at']);
        foreach ($rows as $r) fputcsv($out, $r);
        exit;
    }
    add_action('admin_init', 'sb_newsletter_csv_export');
}

if (!function_exists('sb_newsletter_render_page')) {
    function sb_newsletter_render_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = sb_newsletter_table();

        // Unsubscribe / delete action.
        if (isset($_POST['sb_nl_action'], $_POST['sb_nl_id']) && check_admin_referer('sb_nl_row')) {
            $id = (int) $_POST['sb_nl_id'];
            if ($_POST['sb_nl_action'] === 'delete') $wpdb->delete($table, ['id' => $id]);
            if ($_POST['sb_nl_action'] === 'unsub')  $wpdb->update($table, ['status' => 'unsubscribed'], ['id' => $id]);
            echo '<div class="notice notice-success"><p>Updated.</p></div>';
        }

        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
        $export = wp_nonce_url(admin_url('admin.php?page=sb-subscribers&sb_nl_export=1'), 'sb_nl_export');

        echo '<div class="wrap"><h1>Newsletter subscribers</h1>';
        echo '<p>' . esc_html($active) . ' active / ' . esc_html($total) . ' total &nbsp;·&nbsp; <a href="' . esc_url($export) . '" class="button">Export CSV</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Status</th><th>Source</th><th>Date</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->email) . '</td><td>' . esc_html($r->status) . '</td><td>' . esc_html($r->source) . '</td><td>' . esc_html($r->created_at) . '</td><td>';
            echo '<form method="post" style="display:inline">';
            wp_nonce_field('sb_nl_row');
            echo '<input type="hidden" name="sb_nl_id" value="' . (int) $r->id . '" />';
            if ($r->status === 'active') {
                echo '<button class="button button-small" name="sb_nl_action" value="unsub">Unsubscribe</button> ';
            }
            echo '<button class="button button-small" name="sb_nl_action" value="delete" onclick="return confirm(\'Delete this subscriber?\')">Delete</button>';
            echo '</form></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">No subscribers yet.</td></tr>';
        echo '</tbody></table></div>';
    }
}
