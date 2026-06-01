<?php
/**
 * Limasawa Booking — keep admin.limasawabooking.com out of search results
 * (ported from siargao sb-no-public-access.php).
 *
 * The static frontend at limasawabooking.com is canonical. The WP subdomain is
 * a headless backend (REST + wp-admin). This file:
 *   1. X-Robots-Tag: noindex on every public (non-REST) response.
 *   2. 301-redirects public WP-rendered URLs to their canonical static equivalents:
 *        admin···/{accommodation-slug}  → limasawabooking.com/stay/{slug}/
 *        admin···/{post-slug}, archives → limasawabooking.com/ (no public /blog yet)
 *        accommodation archive          → limasawabooking.com/stays/
 *   3. robots.txt → Disallow: / (allow /wp-json/).
 *
 * Coexists with limasawa-admin-login.php: the ROOT path "/" is intentionally
 * skipped here so that file's auth-aware root redirect (→ /wp-admin or branded
 * login) stays in control. Backend paths (wp-json/wp-admin/wp-login/cron/etc.)
 * are never touched, so the headless REST API + admin keep working.
 */
if (!defined('ABSPATH')) exit;

add_action('send_headers', function () {
    if (is_admin()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    header('X-Robots-Tag: noindex, nofollow, noarchive');
});

add_action('template_redirect', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    foreach (['/wp-admin', '/wp-login', '/wp-json', '/wp-content', '/wp-includes', '/xmlrpc.php', '/wp-cron.php'] as $p) {
        if (strpos($uri, $p) === 0) return;
    }
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || wp_doing_cron()) return;

    // Root "/" is owned by limasawa-admin-login.php (→ wp-admin / branded login).
    // Path-based matching (deterministic on a headless backend — WP front-end
    // rewrites/conditionals aren't reliable here since the front-end is unused).
    $path = trim((string) parse_url($uri, PHP_URL_PATH), '/');
    if ($path === '') return;

    $front = 'https://limasawabooking.com';
    if (preg_match('#^accommodation/([^/]+)/?$#', $path, $m)) {
        $target = $front . '/stay/' . sanitize_title($m[1]) . '/';   // single listing → static single
    } elseif (preg_match('#^place/?$#', $path)) {
        $target = $front . '/stays/';                                 // accommodation archive
    } else {
        $target = $front . '/';                                       // everything else → homepage
    }
    wp_redirect($target, 301); // external host → wp_redirect (not wp_safe_redirect)
    exit;
}, 1);

add_filter('robots_txt', function ($output, $public) {
    return "User-agent: *\nDisallow: /\nAllow: /wp-json/\n";
}, 99, 2);
