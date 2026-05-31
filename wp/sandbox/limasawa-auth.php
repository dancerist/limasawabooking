<?php
/**
 * Limasawa Auth + Roles API  —  REST namespace: limasawa/v1
 *
 * Deployed as a Novamira sandbox file at:
 *   wp-content/novamira-sandbox/limasawa-auth.php  (auto-loaded by Novamira)
 *
 * THIS repo file is the source of truth. To deploy, copy its contents to the
 * server path above via novamira/write-file. Roll back instantly with
 * novamira/disable-file if anything fatals.
 *
 * JWT secret comes from SB_JWT_SECRET in wp-config.php — never hardcoded here,
 * never committed to git.
 */

if (defined('ABSPATH')) {

    /* -------------------------------------------------------------------------
     * Roles: guest + host  (WordPress-native, persisted to wp_options once)
     * ---------------------------------------------------------------------- */
    add_action('init', function () {
        if (!get_role('guest')) {
            add_role('guest', 'Guest', ['read' => true]);
        }
        if (!get_role('host')) {
            add_role('host', 'Host', ['read' => true]);
        }
    });

    /* -------------------------------------------------------------------------
     * JWT (HS256) — secret from wp-config.php
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_jwt_b64url')) {
        function sb_jwt_b64url($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }
    }
    if (!function_exists('sb_jwt_b64url_decode')) {
        function sb_jwt_b64url_decode($data) {
            return base64_decode(strtr($data, '-_', '+/'));
        }
    }
    if (!function_exists('sb_jwt_secret')) {
        function sb_jwt_secret() {
            return defined('SB_JWT_SECRET') ? SB_JWT_SECRET : '';
        }
    }
    if (!function_exists('sb_jwt_encode')) {
        function sb_jwt_encode(array $claims) {
            $secret = sb_jwt_secret();
            if ($secret === '') {
                return null;
            }
            $now = time();
            $ttl = defined('SB_JWT_ACCESS_TTL') ? SB_JWT_ACCESS_TTL : 604800;
            $claims = array_merge(['iat' => $now, 'exp' => $now + $ttl], $claims);
            $seg = [
                sb_jwt_b64url(wp_json_encode(['typ' => 'JWT', 'alg' => 'HS256'])),
                sb_jwt_b64url(wp_json_encode($claims)),
            ];
            $sig = hash_hmac('sha256', implode('.', $seg), $secret, true);
            $seg[] = sb_jwt_b64url($sig);
            return implode('.', $seg);
        }
    }
    if (!function_exists('sb_jwt_decode')) {
        function sb_jwt_decode($jwt) {
            $secret = sb_jwt_secret();
            if ($secret === '' || !is_string($jwt)) {
                return null;
            }
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return null;
            }
            list($h64, $p64, $s64) = $parts;
            $expected = sb_jwt_b64url(hash_hmac('sha256', $h64 . '.' . $p64, $secret, true));
            if (!hash_equals($expected, $s64)) {
                return null;
            }
            $payload = json_decode(sb_jwt_b64url_decode($p64), true);
            if (!is_array($payload)) {
                return null;
            }
            if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
                return null;
            }
            if (isset($payload['nbf']) && time() < (int) $payload['nbf']) {
                return null;
            }
            return $payload;
        }
    }

    /* -------------------------------------------------------------------------
     * Identity + helpers
     * ---------------------------------------------------------------------- */
    if (!function_exists('sb_jwt_current_user')) {
        function sb_jwt_current_user(WP_REST_Request $req) {
            $auth = $req->get_header('authorization');
            if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = $_SERVER['HTTP_AUTHORIZATION'];
            }
            if (!$auth || stripos($auth, 'Bearer ') !== 0) {
                return null;
            }
            $payload = sb_jwt_decode(trim(substr($auth, 7)));
            if (!$payload || empty($payload['uid'])) {
                return null;
            }
            $user = get_user_by('id', (int) $payload['uid']);
            return $user ?: null;
        }
    }
    if (!function_exists('sb_user_role')) {
        function sb_user_role(WP_User $user) {
            if (in_array('host', (array) $user->roles, true)) {
                return 'host';
            }
            if (in_array('guest', (array) $user->roles, true)) {
                return 'guest';
            }
            return $user->roles[0] ?? 'guest';
        }
    }
    if (!function_exists('sb_user_payload')) {
        function sb_user_payload(WP_User $user) {
            $uid   = (int) $user->ID;
            $saved = get_user_meta($uid, 'sb_saved', true);
            $saved = is_array($saved) ? $saved : [];
            $listings = new WP_Query([
                'post_type'      => 'accommodation',
                'post_status'    => ['publish', 'pending', 'draft'],
                'author'         => $uid,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            $pic_id = (int) get_user_meta($uid, 'sb_profile_picture_id', true);
            $avatar = $pic_id ? wp_get_attachment_image_url($pic_id, 'thumbnail') : get_avatar_url($uid, ['size' => 96]);
            return [
                'id'               => $uid,
                'name'             => $user->display_name,
                'username'         => $user->user_login,
                'email'            => $user->user_email,
                'contact'          => (string) get_user_meta($uid, 'sb_contact', true),
                'role'             => sb_user_role($user),
                'avatar'           => $avatar,
                'profilePictureId' => $pic_id,
                'savedCount'       => count($saved),
                'listingCount'     => (int) $listings->found_posts,
                'reviewCount'      => (int) get_user_meta($uid, 'sb_review_count', true),
            ];
        }
    }
    if (!function_exists('sb_rate_limit')) {
        function sb_rate_limit($bucket, $max, $window) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $key = 'sb_rl_' . $bucket . '_' . md5($ip);
            $count = (int) get_transient($key);
            if ($count >= $max) {
                return false;
            }
            set_transient($key, $count + 1, $window);
            return true;
        }
    }

    /* -------------------------------------------------------------------------
     * REST routes — limasawa/v1
     * ---------------------------------------------------------------------- */
    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/auth/register', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_auth_register',
        ]);
        register_rest_route($ns, '/auth/login', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_auth_login',
        ]);
        register_rest_route($ns, '/me', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'sb_auth_me',
        ]);
    });

    if (!function_exists('sb_auth_register')) {
        function sb_auth_register(WP_REST_Request $req) {
            if (!sb_rate_limit('register', 5, 300)) {
                return new WP_REST_Response(['error' => 'RATE_LIMITED'], 429);
            }
            $email    = sanitize_email((string) $req->get_param('email'));
            $password = (string) $req->get_param('password');
            $name     = sanitize_text_field((string) $req->get_param('name'));
            $role     = $req->get_param('role') === 'host' ? 'host' : 'guest';

            if (!is_email($email)) {
                return new WP_REST_Response(['error' => 'INVALID_EMAIL'], 400);
            }
            if (strlen($password) < 8) {
                return new WP_REST_Response(['error' => 'WEAK_PASSWORD'], 400);
            }
            if (email_exists($email) || username_exists($email)) {
                return new WP_REST_Response(['error' => 'EMAIL_EXISTS'], 409);
            }

            $uid = wp_insert_user([
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => $password,
                'display_name' => $name !== '' ? $name : $email,
                'role'         => $role,
            ]);
            if (is_wp_error($uid)) {
                return new WP_REST_Response(['error' => 'CREATE_FAILED'], 500);
            }

            $user  = get_user_by('id', $uid);
            $token = sb_jwt_encode(['uid' => (int) $uid, 'role' => $role]);
            return new WP_REST_Response([
                'status'    => 'ok',
                'authToken' => $token,
                'user'      => sb_user_payload($user),
            ], 201);
        }
    }

    if (!function_exists('sb_auth_login')) {
        function sb_auth_login(WP_REST_Request $req) {
            if (!sb_rate_limit('login', 10, 60)) {
                return new WP_REST_Response(['error' => 'RATE_LIMITED'], 429);
            }
            $email    = sanitize_email((string) $req->get_param('email'));
            $password = (string) $req->get_param('password');
            if (!is_email($email)) {
                return new WP_REST_Response(['error' => 'INVALID_EMAIL'], 400);
            }
            $user = get_user_by('email', $email);
            if (!$user || !wp_check_password($password, $user->user_pass, $user->ID)) {
                return new WP_REST_Response(['error' => 'INVALID_CREDENTIALS'], 401);
            }
            $token = sb_jwt_encode(['uid' => (int) $user->ID, 'role' => sb_user_role($user)]);
            return new WP_REST_Response([
                'status'    => 'ok',
                'authToken' => $token,
                'user'      => sb_user_payload($user),
            ], 200);
        }
    }

    if (!function_exists('sb_auth_me')) {
        function sb_auth_me(WP_REST_Request $req) {
            $user = sb_jwt_current_user($req);
            if (!$user) {
                return new WP_REST_Response(['error' => 'UNAUTHORIZED'], 401);
            }
            return new WP_REST_Response(['status' => 'ok', 'user' => sb_user_payload($user)], 200);
        }
    }

    /* -------------------------------------------------------------------------
     * CORS for the Astro frontend
     * ---------------------------------------------------------------------- */
    add_action('rest_api_init', function () {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
            $allowed = [
                'http://localhost:4321',
                'http://localhost:3000',
                'https://limasawabooking.com',
                'https://www.limasawabooking.com',
            ];
            if (in_array($origin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Authorization, Content-Type');
                header('Access-Control-Allow-Credentials: true');
            }
            return $value;
        }, 15);
    }, 15);
}
