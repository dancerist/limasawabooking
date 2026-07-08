<?php
/**
 * Limasawa Host Blog API  —  REST namespace: limasawa/v1
 *
 * Backs the dashboard "Posts" panel (Pro-gated in the UI). Host blog entries
 * are standard WP posts (post_type 'post') authored by the host. The EditorJS
 * frontend sends BOTH rendered `content` (HTML → post_content) and the raw
 * `contentBlocks` JSON (→ sb_content_blocks meta for re-editing), so no
 * server-side block conversion is needed.
 *
 * Routes (limasawa/v1, all JWT):
 *   GET    /my-posts            list the host's posts
 *   POST   /create-post         {title,status,content,contentBlocks,featuredImageId}
 *   GET    /post/{id}           load one for editing (owner)
 *   POST   /post/{id}           update (owner)
 *   DELETE /post/{id}           trash (owner)
 */

if (defined('ABSPATH')) {

    if (!function_exists('sb_posts_user')) {
        function sb_posts_user(WP_REST_Request $req) {
            return function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        }
    }

    if (!function_exists('sb_post_status_in')) {
        function sb_post_status_in($s) {
            return $s === 'publish' ? 'publish' : 'draft';
        }
    }

    /* ── Tier gating ─────────────────────────────────────────────────────
     * Blog writing is a Pro+ feature. A host's "tier" is the best tier across
     * their listings (mirrors the dashboard maxTier). Admins/editors bypass.
     * -------------------------------------------------------------------- */
    if (!function_exists('sb_host_max_tier')) {
        function sb_host_max_tier($user_id) {
            $q = new WP_Query([
                'post_type' => 'accommodation', 'author' => (int) $user_id,
                'post_status' => ['publish', 'pending', 'draft'],
                'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            ]);
            $rank = ['free' => 0, 'pro' => 1, 'featured' => 2];
            $best = 'free';
            foreach ($q->posts as $pid) {
                $t = get_post_meta($pid, 'listing_tier', true) ?: 'free';
                if (($rank[$t] ?? 0) > ($rank[$best] ?? 0)) $best = $t;
            }
            return $best;
        }
    }
    if (!function_exists('sb_can_blog')) {
        function sb_can_blog(WP_User $user) {
            if (array_intersect(['administrator', 'editor'], (array) $user->roles)) return true;
            return in_array(sb_host_max_tier($user->ID), ['pro', 'featured'], true);
        }
    }

    /* ── Public blog card formatter ──────────────────────────────────────
     * One published WP post → the shape src/scripts/blog-card-template.js
     * (ported from siargao) expects.
     * -------------------------------------------------------------------- */
    if (!function_exists('sb_blog_card')) {
        function sb_blog_card($p) {
            $id      = $p->ID;
            $author  = get_userdata($p->post_author);
            $picId   = (int) get_user_meta($p->post_author, 'sb_profile_picture_id', true);
            $avatar  = $picId ? wp_get_attachment_image_url($picId, 'thumbnail') : get_avatar_url($p->post_author, ['size' => 48]);
            $cats    = get_the_category($id);
            $primary = (!empty($cats) && strtolower($cats[0]->slug) !== 'uncategorized')
                ? ['name' => $cats[0]->name, 'slug' => $cats[0]->slug] : null;
            $excerpt = has_excerpt($id) ? get_the_excerpt($id) : wp_trim_words(wp_strip_all_tags($p->post_content), 28);
            $words   = str_word_count(wp_strip_all_tags($p->post_content));
            return [
                'slug'      => $p->post_name,
                'title'     => html_entity_decode(get_the_title($id), ENT_QUOTES),
                'excerpt'   => html_entity_decode($excerpt, ENT_QUOTES),
                'dateLabel' => get_the_date('M j, Y', $id),
                'readMin'   => max(1, (int) ceil($words / 200)),
                'thumb'     => get_the_post_thumbnail_url($id, 'large') ?: '',
                'category'  => $primary,
                'author'    => ['name' => $author ? $author->display_name : 'Host', 'avatar' => $avatar ?: ''],
            ];
        }
    }

    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/my-posts', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_my_posts',
        ]);
        register_rest_route($ns, '/create-post', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_create_post',
        ]);
        register_rest_route($ns, '/post/(?P<id>\\d+)', [
            ['methods' => 'GET',    'permission_callback' => '__return_true', 'callback' => 'sb_get_post'],
            ['methods' => 'POST',   'permission_callback' => '__return_true', 'callback' => 'sb_update_post'],
            ['methods' => 'DELETE', 'permission_callback' => '__return_true', 'callback' => 'sb_delete_post'],
        ]);
        // ── Public blog (no auth, published only) ──
        register_rest_route($ns, '/blog/posts', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_blog_posts',
        ]);
        register_rest_route($ns, '/blog/categories', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_blog_categories',
        ]);
        register_rest_route($ns, '/blog/post/(?P<slug>[a-z0-9\\-]+)', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_blog_single',
        ]);
    });

    if (!function_exists('sb_my_posts')) {
        function sb_my_posts(WP_REST_Request $req) {
            $user = sb_posts_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $q = new WP_Query([
                'post_type' => 'post', 'author' => (int) $user->ID,
                'post_status' => ['publish', 'draft', 'pending'], 'posts_per_page' => 100,
                'orderby' => 'modified', 'order' => 'DESC',
            ]);
            $posts = [];
            foreach ($q->posts as $p) {
                $posts[] = [
                    'id'      => (int) $p->ID,
                    'title'   => html_entity_decode(get_the_title($p->ID), ENT_QUOTES) ?: '(untitled)',
                    'slug'    => $p->post_name,
                    'status'  => $p->post_status,
                    'date'    => get_the_date('M j, Y', $p->ID),
                    'excerpt' => wp_trim_words(wp_strip_all_tags($p->post_content), 24),
                ];
            }
            return new WP_REST_Response(['success' => true, 'posts' => $posts], 200);
        }
    }

    if (!function_exists('sb_post_apply_body')) {
        function sb_post_apply_body($post_id, WP_REST_Request $req) {
            $blocks = $req->get_param('contentBlocks');
            update_post_meta($post_id, 'sb_content_blocks', wp_json_encode($blocks));
            $fid = (int) $req->get_param('featuredImageId');
            if ($fid > 0) set_post_thumbnail($post_id, $fid);
            else delete_post_thumbnail($post_id);
        }
    }

    if (!function_exists('sb_create_post')) {
        function sb_create_post(WP_REST_Request $req) {
            $user = sb_posts_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            if (!sb_can_blog($user)) return new WP_REST_Response(['success' => false, 'error' => 'TIER_LOCKED', 'message' => 'Writing posts is a Pro Host feature. Upgrade a listing to publish blog posts.'], 403);
            $title = sanitize_text_field((string) $req->get_param('title'));
            if ($title === '') return new WP_REST_Response(['success' => false, 'message' => 'Title is required.'], 400);

            $id = wp_insert_post([
                'post_type'    => 'post',
                'post_status'  => sb_post_status_in($req->get_param('status')),
                'post_title'   => $title,
                'post_content' => wp_kses_post((string) $req->get_param('content')),
                'post_author'  => (int) $user->ID,
            ], true);
            if (is_wp_error($id)) return new WP_REST_Response(['success' => false, 'message' => 'Could not create post.'], 500);
            sb_post_apply_body($id, $req);
            return new WP_REST_Response(['success' => true, 'id' => (int) $id, 'slug' => get_post_field('post_name', $id)], 201);
        }
    }

    // Resolve {id} → post, enforcing the JWT user is the author.
    if (!function_exists('sb_post_owner_guard')) {
        function sb_post_owner_guard(WP_REST_Request $req) {
            $user = sb_posts_user($req);
            if (!$user) return ['err' => new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401)];
            $p = get_post((int) $req->get_param('id'));
            if (!$p || $p->post_type !== 'post') return ['err' => new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404)];
            if ((int) $p->post_author !== (int) $user->ID) return ['err' => new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403)];
            return ['user' => $user, 'post' => $p];
        }
    }

    if (!function_exists('sb_get_post')) {
        function sb_get_post(WP_REST_Request $req) {
            $g = sb_post_owner_guard($req);
            if (isset($g['err'])) return $g['err'];
            $p = $g['post'];
            $blocks = get_post_meta($p->ID, 'sb_content_blocks', true);
            $decoded = $blocks ? json_decode($blocks, true) : null;
            $fid = (int) get_post_thumbnail_id($p->ID);
            return new WP_REST_Response([
                'success'         => true,
                'id'              => (int) $p->ID,
                'title'           => html_entity_decode(get_the_title($p->ID), ENT_QUOTES),
                'slug'            => $p->post_name,
                'status'          => $p->post_status,
                'content'         => $p->post_content,
                'contentBlocks'   => $decoded,
                'featuredImageId' => $fid,
                'featuredImageUrl' => $fid ? wp_get_attachment_url($fid) : '',
            ], 200);
        }
    }

    if (!function_exists('sb_update_post')) {
        function sb_update_post(WP_REST_Request $req) {
            $g = sb_post_owner_guard($req);
            if (isset($g['err'])) return $g['err'];
            if (!sb_can_blog($g['user'])) return new WP_REST_Response(['success' => false, 'error' => 'TIER_LOCKED', 'message' => 'Writing posts is a Pro Host feature.'], 403);
            $id = (int) $g['post']->ID;
            $title = sanitize_text_field((string) $req->get_param('title'));
            wp_update_post([
                'ID'           => $id,
                'post_title'   => $title !== '' ? $title : $g['post']->post_title,
                'post_content' => wp_kses_post((string) $req->get_param('content')),
                'post_status'  => sb_post_status_in($req->get_param('status')),
            ]);
            sb_post_apply_body($id, $req);
            return new WP_REST_Response(['success' => true, 'id' => $id, 'slug' => get_post_field('post_name', $id)], 200);
        }
    }

    if (!function_exists('sb_delete_post')) {
        function sb_delete_post(WP_REST_Request $req) {
            $g = sb_post_owner_guard($req);
            if (isset($g['err'])) return $g['err'];
            wp_trash_post((int) $g['post']->ID);
            return new WP_REST_Response(['success' => true], 200);
        }
    }

    /* ── Public blog read endpoints ─────────────────────────────────────── */
    if (!function_exists('sb_blog_posts')) {
        function sb_blog_posts(WP_REST_Request $req) {
            $page = max(1, (int) $req->get_param('page'));
            $per  = min(48, max(1, (int) ($req->get_param('per_page') ?: 12)));
            $args = [
                'post_type' => 'post', 'post_status' => 'publish',
                'posts_per_page' => $per, 'paged' => $page,
                'orderby' => 'date', 'order' => 'DESC',
            ];
            if ($cat = $req->get_param('category')) $args['category_name'] = sanitize_title($cat);
            if ($s = $req->get_param('search'))     $args['s'] = sanitize_text_field($s);
            $q = new WP_Query($args);
            return new WP_REST_Response([
                'posts' => array_map('sb_blog_card', $q->posts),
                'total' => (int) $q->found_posts,
                'pages' => (int) $q->max_num_pages,
                'page'  => $page,
            ], 200);
        }
    }

    if (!function_exists('sb_blog_categories')) {
        function sb_blog_categories(WP_REST_Request $req) {
            $terms = get_categories(['hide_empty' => true]);
            $out = [];
            foreach ($terms as $t) {
                if (strtolower($t->slug) === 'uncategorized') continue;
                $out[] = ['name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count];
            }
            return new WP_REST_Response($out, 200);
        }
    }

    if (!function_exists('sb_blog_single')) {
        function sb_blog_single(WP_REST_Request $req) {
            $slug = sanitize_title((string) $req->get_param('slug'));
            $post = get_page_by_path($slug, OBJECT, 'post');
            if (!$post || $post->post_status !== 'publish') {
                return new WP_REST_Response(['error' => 'NOT_FOUND'], 404);
            }
            $card = sb_blog_card($post);
            $catTerms = get_the_category($post->ID);
            $categories = [];
            foreach ($catTerms as $t) {
                if (strtolower($t->slug) === 'uncategorized') continue;
                $categories[] = ['name' => $t->name, 'slug' => $t->slug];
            }
            // Related: recent published posts, preferring the same primary category.
            $relArgs = [
                'post_type' => 'post', 'post_status' => 'publish',
                'posts_per_page' => 3, 'post__not_in' => [$post->ID],
                'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
            ];
            if (!empty($catTerms)) $relArgs['category__in'] = [(int) $catTerms[0]->term_id];
            $rel = new WP_Query($relArgs);
            $related = array_map('sb_blog_card', $rel->posts);
            // Top up to 3 with most-recent if same-category came up short.
            if (count($related) < 3) {
                $have = array_map(function ($r) { return $r['slug']; }, $related);
                $fill = new WP_Query([
                    'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 6,
                    'post__not_in' => [$post->ID], 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
                ]);
                foreach ($fill->posts as $fp) {
                    if (count($related) >= 3) break;
                    if (in_array($fp->post_name, $have, true)) continue;
                    $related[] = sb_blog_card($fp);
                }
            }
            $out = array_merge($card, [
                'content'    => apply_filters('the_content', $post->post_content),
                'categories' => $categories,
                'related'    => $related,
                // ISO 8601 dates for Article JSON-LD on the static frontend.
                'dateIso'     => get_the_date('c', $post->ID),
                'modifiedIso' => get_the_modified_date('c', $post->ID),
            ]);
            return new WP_REST_Response($out, 200);
        }
    }
}
