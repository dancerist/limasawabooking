<?php
/**
 * Limasawa Booking — Frontend auto-rebuild trigger.
 *
 * The Astro frontend is a STATIC build (no SSR): every /stay/{slug} and
 * /rental/{slug} page is prebuilt at deploy time, and the archive cards read
 * the live REST API. So a newly published listing shows as a card immediately
 * but its detail page 404s until the site is rebuilt — and edits to a rental
 * detail page don't show at all until a rebuild.
 *
 * This file closes that gap: whenever an `accommodation` or `rental` is
 * published, edited-while-published, unpublished, or trashed, it pings a Vercel
 * Deploy Hook which rebuilds production from GitHub `main`. Changes go live
 * ~1–2 min later (the build time), fully hands-off.
 *
 * The hook URL is a build-trigger secret (it can start builds, nothing else).
 * It is read from the `SB_DEPLOY_HOOK_URL` constant (wp-config.php) if defined,
 * else from the `sb_deploy_hook_url` wp_option — never hard-coded here / in git.
 *
 * Debounce: a burst of saves collapses into at most one build per cooldown
 * window (first save fires immediately; later saves in the window schedule one
 * trailing build), so editing several fields doesn't spawn a build storm.
 *
 * THIS repo file is the source of truth. To deploy, copy its contents to the
 * server at wp-content/novamira-sandbox/limasawa-deploy-hook.php via
 * novamira/write-file. Roll back instantly with novamira/disable-file.
 */
if (!defined('ABSPATH')) exit;

if (!defined('SB_DEPLOY_COOLDOWN')) {
    // Min seconds between builds; saves inside the window collapse into one.
    define('SB_DEPLOY_COOLDOWN', 60);
}

/** Resolve the Vercel deploy-hook URL (constant wins over option). */
if (!function_exists('sb_deploy_hook_url')) {
    function sb_deploy_hook_url() {
        if (defined('SB_DEPLOY_HOOK_URL') && SB_DEPLOY_HOOK_URL) {
            return SB_DEPLOY_HOOK_URL;
        }
        $url = get_option('sb_deploy_hook_url', '');
        return is_string($url) ? trim($url) : '';
    }
}

/**
 * Fire a rebuild, debounced. First call fires immediately and opens a cooldown;
 * calls during the cooldown set a "dirty" flag and a trailing build is flushed
 * once the cooldown lapses (so the last edit is never lost).
 */
if (!function_exists('sb_trigger_frontend_rebuild')) {
    function sb_trigger_frontend_rebuild() {
        $url = sb_deploy_hook_url();
        if (!$url) return;

        if (get_transient('sb_deploy_cooldown')) {
            set_transient('sb_deploy_dirty', 1, 15 * MINUTE_IN_SECONDS);
            if (!wp_next_scheduled('sb_deploy_flush')) {
                wp_schedule_single_event(time() + SB_DEPLOY_COOLDOWN + 10, 'sb_deploy_flush');
            }
            return;
        }

        set_transient('sb_deploy_cooldown', 1, SB_DEPLOY_COOLDOWN);
        wp_remote_post($url, array(
            'blocking'  => false,
            'timeout'   => 5,
            'headers'   => array('Content-Type' => 'application/json'),
            'body'      => wp_json_encode(array('source' => 'wp-cms')),
        ));
    }
}

/** Trailing flush: if anything changed during the cooldown, build once more. */
if (!function_exists('sb_deploy_flush_cb')) {
    function sb_deploy_flush_cb() {
        if (!get_transient('sb_deploy_dirty')) return;
        delete_transient('sb_deploy_dirty');
        delete_transient('sb_deploy_cooldown'); // allow the trailing build through
        sb_trigger_frontend_rebuild();
    }
}
add_action('sb_deploy_flush', 'sb_deploy_flush_cb');

/**
 * Decide whether a status transition affects the public site and, if so, rebuild.
 * Fires only when the post is/was published — ignores draft↔pending churn and
 * the auto-draft/revision noise that save_post would otherwise produce.
 */
if (!function_exists('sb_deploy_on_transition')) {
    function sb_deploy_on_transition($new_status, $old_status, $post) {
        if (empty($post) || !in_array($post->post_type, array('accommodation', 'rental'), true)) {
            return;
        }
        if ($new_status === 'inherit' || $new_status === 'auto-draft') return; // revisions / blank new
        // Only publish-affecting changes matter: new publish, edit-while-published,
        // unpublish, or trash of a published post.
        if ($new_status !== 'publish' && $old_status !== 'publish') return;
        sb_trigger_frontend_rebuild();
    }
}
add_action('transition_post_status', 'sb_deploy_on_transition', 10, 3);

/**
 * Permanent deletion of a published listing also needs a rebuild (delete bypasses
 * a publish→trash transition when force-deleted from trash).
 */
if (!function_exists('sb_deploy_on_delete')) {
    function sb_deploy_on_delete($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, array('accommodation', 'rental'), true)) return;
        if ($post->post_status === 'publish') sb_trigger_frontend_rebuild();
    }
}
add_action('before_delete_post', 'sb_deploy_on_delete', 10, 1);
