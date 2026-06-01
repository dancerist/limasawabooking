<?php
/**
 * Limasawa Booking — admin login experience (admin.limasawabooking.com)
 *
 *  - Branded wp-login screen (logo wordmark + brand colours).
 *  - Auth-aware root redirect: the admin subdomain home (`/`) sends logged-in
 *    users to /wp-admin, and logged-out visitors to the branded login.
 *  - After login → /wp-admin index.
 *
 * Front-end only on the headless WP backend; never touches /wp-json (REST),
 * wp-admin, or wp-login.php.
 */

if (defined('ABSPATH')) {

    /* ---- Root redirect: admin subdomain home → wp-admin or login --------- */
    if (!function_exists('sb_admin_root_redirect')) {
        function sb_admin_root_redirect() {
            if (is_admin()) return;
            if (defined('REST_REQUEST') && REST_REQUEST) return;
            if (defined('DOING_CRON') && DOING_CRON) return;
            // Only act on the site root ("/"), nothing deeper.
            $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            if ($path !== '') return;

            if (is_user_logged_in()) {
                wp_safe_redirect(admin_url('index.php'));
            } else {
                wp_safe_redirect(wp_login_url(admin_url('index.php')));
            }
            exit;
        }
        add_action('template_redirect', 'sb_admin_root_redirect');
    }

    /* ---- After a successful login → wp-admin index ----------------------- */
    add_filter('login_redirect', function ($redirect_to, $requested, $user) {
        if (is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        // Honor an explicit, safe redirect target; otherwise default to wp-admin.
        if (!empty($requested) && strpos($requested, 'wp-login.php') === false) return $redirect_to;
        return admin_url('index.php');
    }, 10, 3);

    /* ---- Brand the wp-login screen --------------------------------------- */
    add_filter('login_headerurl', function () { return 'https://limasawabooking.com/'; });
    add_filter('login_headertext', function () { return 'Limasawa Booking'; });

    add_action('login_enqueue_scripts', function () {
        ?>
        <style>
            body.login {
                background: linear-gradient(135deg, #0d1e2b 0%, #1a3a4a 50%, #0d3040 100%);
            }
            .login h1 a {
                background-image: none !important;
                width: auto; height: auto; text-indent: 0; font-size: 0; line-height: 1;
                pointer-events: auto;
            }
            .login h1 a:after {
                content: 'Limasawa\00a0Booking';
                font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
                font-size: 26px; font-weight: 800; letter-spacing: -0.02em; color: #ffffff;
            }
            .login form {
                border-radius: 14px; border: none;
                box-shadow: 0 24px 60px rgba(0,0,0,.35);
                padding: 26px 24px;
            }
            .login label { color: #1A2533; font-weight: 600; }
            .login input[type=text], .login input[type=password] {
                border-radius: 9px; border: 1.5px solid #E2EBF3; padding: 10px 12px;
            }
            .login input[type=text]:focus, .login input[type=password]:focus {
                border-color: #25CECE; box-shadow: 0 0 0 3px rgba(37,206,206,.18);
            }
            .wp-core-ui .button-primary {
                background: #25CECE; border-color: #1ab4b4;
                border-radius: 9px; text-shadow: none; box-shadow: none; font-weight: 700;
            }
            .wp-core-ui .button-primary:hover { background: #1ab4b4; border-color: #1ab4b4; }
            .login #nav a, .login #backtoblog a { color: #cfe9ee; }
            .login #nav a:hover, .login #backtoblog a:hover { color: #ffffff; }
            .login .message, .login .notice { border-left-color: #25CECE; border-radius: 8px; }
        </style>
        <?php
    });

    /* ---- Small brand touch inside wp-admin ------------------------------- */
    add_filter('admin_footer_text', function () {
        return 'Limasawa Booking — host & listings CMS';
    });
}
