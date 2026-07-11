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
    add_filter('login_headertext', function () {
        // HTML logo: teal pin mark + two-tone wordmark (echoed unescaped inside the <a>).
        return '<span class="sb-login-mark" aria-hidden="true">'
             .   '<svg viewBox="0 0 24 24" fill="none"><path d="M12 4.6c-2.6 0-4.7 2.1-4.7 4.7 0 3.3 4.7 7.1 4.7 7.1s4.7-3.8 4.7-7.1c0-2.6-2.1-4.7-4.7-4.7z" fill="#fff"/><circle cx="12" cy="9.3" r="1.9" fill="#157E84"/></svg>'
             . '</span>'
             . '<span class="sb-login-word">Limasawa<strong>Booking</strong></span>';
    });

    // Priority 99 on login_head so this prints AFTER core's login stylesheet —
    // WP 7 prints enqueued styles after login_enqueue_scripts, which silently
    // defeated equal-specificity overrides (button stayed WP-blue).
    add_action('login_head', function () {
        $bg = 'https://admin.limasawabooking.com/wp-content/uploads/limasawa/login-bg.webp';
        ?>
        <style>
            body.login {
                background: #0d1e2b url('<?php echo esc_url($bg); ?>') center / cover no-repeat fixed;
            }
            /* Dark navy overlay so the photo stays subtle */
            body.login::before {
                content: ''; position: fixed; inset: 0; z-index: 0;
                background: linear-gradient(160deg, rgba(13,30,43,.93) 0%, rgba(13,48,64,.86) 55%, rgba(13,30,43,.95) 100%);
            }
            #login, #language-switcher { position: relative; z-index: 1; }
            .login .wp-login-logo a {
                background-image: none !important;
                width: auto !important; height: auto !important;
                text-indent: 0 !important; font-size: 0; line-height: 1;
                display: inline-flex; align-items: center; gap: 11px;
                pointer-events: auto; text-decoration: none;
            }
            .sb-login-mark {
                width: 46px; height: 46px; border-radius: 50%; background: #157E84; flex-shrink: 0;
                display: inline-flex; align-items: center; justify-content: center;
                box-shadow: 0 6px 18px rgba(0,0,0,.35);
            }
            .sb-login-mark svg { width: 28px; height: 28px; display: block; }
            .sb-login-word {
                font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
                font-size: 27px; font-weight: 800; letter-spacing: -0.02em; color: #ffffff;
            }
            .sb-login-word strong { color: #3EC7CF; font-weight: 800; }
            .login form {
                border-radius: 16px; border: none;
                box-shadow: 0 24px 60px rgba(0,0,0,.45);
                padding: 28px 26px;
            }
            .login label { color: #1A2533; font-weight: 600; }
            .login input[type=text], .login input[type=password] {
                border-radius: 9px; border: 1.5px solid #E2EBF3; padding: 10px 12px;
            }
            .login input[type=text]:focus, .login input[type=password]:focus {
                border-color: #157E84 !important; box-shadow: 0 0 0 3px rgba(21,126,132,.18) !important;
            }
            .login input[type=checkbox]:checked { background: #157E84; border-color: #157E84; }
            .login input[type=checkbox]:focus { border-color: #157E84; box-shadow: 0 0 0 2px rgba(21,126,132,.25); }
            .wp-core-ui .button-primary {
                background: #157E84 !important; border-color: #116A70 !important; color: #fff !important;
                border-radius: 9px; text-shadow: none; box-shadow: none; font-weight: 700;
            }
            .wp-core-ui .button-primary:hover,
            .wp-core-ui .button-primary:focus { background: #116A70 !important; border-color: #0E565B !important; }
            .wp-core-ui .button-primary:focus { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #3EC7CF !important; }
            .login #nav a, .login #backtoblog a { color: rgba(255,255,255,.82); }
            .login #nav a:hover, .login #backtoblog a:hover { color: #3EC7CF; }
            .login .message, .login .notice { border-left-color: #157E84; border-radius: 8px; }
            .login .button.wp-hide-pw { color: #157E84; }
            .login .button.wp-hide-pw:focus { border-color: #157E84; box-shadow: 0 0 0 2px rgba(21,126,132,.25); }
        </style>
        <?php
    }, 99);

    /* ---- Small brand touch inside wp-admin ------------------------------- */
    add_filter('admin_footer_text', function () {
        return 'Limasawa Booking — host & listings CMS';
    });
}
