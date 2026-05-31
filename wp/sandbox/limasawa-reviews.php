<?php
/**
 * Limasawa Reviews / Ratings API  —  REST namespace: limasawa/v1
 *
 * Reviews are stored as WP comments (comment_type = 'sb_review') on the
 * accommodation post. Approval = comment status (hold → pending, approve →
 * live). Per-listing sb_rating_avg + sb_rating_count meta is recomputed on any
 * change and feeds the card rating pill + the analytics rating KPI. Author's
 * sb_review_count user-meta feeds /me.
 *
 * Moderation is host-approval (no email-verify step). JWT via sb_jwt_current_user.
 *
 * Routes (limasawa/v1):
 *   GET  /reviews?slug=&page=&per_page=     public — approved reviews + rating
 *   POST /reviews                           submit {slug,author,email,rating,content,stayMonth} → pending
 *   GET  /reviews/pending   (Bearer host)   host's listings' pending reviews
 *   POST /reviews/{id}/approve|reject (Bearer host)
 *   POST /reviews/{id}/reply (Bearer host)  {reply}
 *   GET  /my-reviews        (Bearer)        reviews the user authored
 *   POST /reviews/{id}      (Bearer author) edit own review {rating,content,stayMonth}
 */

if (defined('ABSPATH')) {

    if (!function_exists('sb_rev_user')) {
        function sb_rev_user(WP_REST_Request $req) {
            return function_exists('sb_jwt_current_user') ? sb_jwt_current_user($req) : null;
        }
    }

    if (!function_exists('sb_review_recalc')) {
        function sb_review_recalc($post_id) {
            $comments = get_comments(['post_id' => $post_id, 'type' => 'sb_review', 'status' => 'approve', 'number' => 0]);
            $count = 0; $sum = 0;
            foreach ($comments as $c) {
                $r = (int) get_comment_meta($c->comment_ID, 'sb_rating', true);
                if ($r > 0) { $count++; $sum += $r; }
            }
            $avg = $count ? round($sum / $count, 2) : 0;
            update_post_meta($post_id, 'sb_rating_avg', $avg);
            update_post_meta($post_id, 'sb_rating_count', $count);
            return ['avg' => $avg, 'count' => $count];
        }
    }

    if (!function_exists('sb_author_review_count_sync')) {
        function sb_author_review_count_sync($uid) {
            if (!$uid) return;
            $n = get_comments(['user_id' => $uid, 'type' => 'sb_review', 'status' => 'all', 'count' => true]);
            update_user_meta($uid, 'sb_review_count', (int) $n);
        }
    }

    if (!function_exists('sb_review_format')) {
        function sb_review_format($c, $opts = []) {
            $pid = (int) $c->comment_post_ID;
            $out = [
                'id'        => (int) $c->comment_ID,
                'rating'    => (int) get_comment_meta($c->comment_ID, 'sb_rating', true),
                'author'    => $c->comment_author ?: 'Guest',
                'content'   => $c->comment_content,
                'date'      => date_i18n('M j, Y', strtotime($c->comment_date)),
                'source'    => get_comment_meta($c->comment_ID, 'sb_source', true) ?: 'direct',
                'stayMonth' => get_comment_meta($c->comment_ID, 'sb_stay_month', true),
                'hostReply' => get_comment_meta($c->comment_ID, 'sb_host_reply', true),
            ];
            if (!empty($opts['listing'])) {
                $out['listingTitle'] = html_entity_decode(get_the_title($pid), ENT_QUOTES);
                $out['listingSlug']  = get_post_field('post_name', $pid);
                $out['listingThumb'] = get_the_post_thumbnail_url($pid, 'thumbnail') ?: '';
            }
            if (!empty($opts['status'])) {
                $out['status'] = ($c->comment_approved === '1' || $c->comment_approved === 1) ? 'approved' : 'pending';
            }
            if (isset($opts['canEdit'])) {
                $out['canEdit'] = (bool) $opts['canEdit'];
            }
            return $out;
        }
    }

    add_action('rest_api_init', function () {
        $ns = 'limasawa/v1';
        register_rest_route($ns, '/reviews', [
            ['methods' => 'GET',  'permission_callback' => '__return_true', 'callback' => 'sb_reviews_list'],
            ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_submit'],
        ]);
        register_rest_route($ns, '/reviews/pending', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_pending',
        ]);
        register_rest_route($ns, '/my-reviews', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_mine',
        ]);
        register_rest_route($ns, '/reviews/(?P<id>\\d+)/(?P<action>approve|reject)', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_moderate',
        ]);
        register_rest_route($ns, '/reviews/(?P<id>\\d+)/reply', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_reply',
        ]);
        register_rest_route($ns, '/reviews/(?P<id>\\d+)', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_reviews_edit',
        ]);
    });

    if (!function_exists('sb_reviews_list')) {
        function sb_reviews_list(WP_REST_Request $req) {
            $slug = sanitize_title((string) $req->get_param('slug'));
            $posts = $slug ? get_posts(['name' => $slug, 'post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']) : [];
            if (empty($posts)) return new WP_REST_Response(['ratingAvg' => 0, 'ratingCount' => 0, 'reviews' => [], 'total' => 0], 200);
            $pid  = (int) $posts[0];
            $page = max(1, (int) $req->get_param('page'));
            $per  = (int) $req->get_param('per_page'); $per = $per > 0 ? min(50, $per) : 10;

            $all = get_comments(['post_id' => $pid, 'type' => 'sb_review', 'status' => 'approve', 'number' => 0, 'orderby' => 'comment_date', 'order' => 'DESC']);
            $total = count($all);
            $slice = array_slice($all, ($page - 1) * $per, $per);
            $reviews = array_map(function ($c) { return sb_review_format($c); }, $slice);

            $avg = (float) get_post_meta($pid, 'sb_rating_avg', true);
            $cnt = (int) get_post_meta($pid, 'sb_rating_count', true);
            return new WP_REST_Response(['ratingAvg' => $avg, 'ratingCount' => $cnt, 'reviews' => $reviews, 'total' => $total], 200);
        }
    }

    if (!function_exists('sb_reviews_submit')) {
        function sb_reviews_submit(WP_REST_Request $req) {
            $slug = sanitize_title((string) $req->get_param('slug'));
            $posts = $slug ? get_posts(['name' => $slug, 'post_type' => 'accommodation', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids']) : [];
            if (empty($posts)) return new WP_REST_Response(['success' => false, 'message' => 'Listing not found.'], 404);
            $pid = (int) $posts[0];

            $rating = (int) $req->get_param('rating');
            $content = trim((string) $req->get_param('content'));
            if ($rating < 1 || $rating > 5) return new WP_REST_Response(['success' => false, 'message' => 'Please choose a 1–5 star rating.'], 400);
            if (strlen($content) < 10) return new WP_REST_Response(['success' => false, 'message' => 'Please write at least a sentence.'], 400);

            $user  = sb_rev_user($req);
            $author = sanitize_text_field($req->get_param('author')) ?: ($user ? $user->display_name : 'Guest');
            $email  = sanitize_email((string) $req->get_param('email')) ?: ($user ? $user->user_email : '');

            $cid = wp_insert_comment([
                'comment_post_ID'      => $pid,
                'comment_content'      => wp_kses_post($content),
                'comment_author'       => $author,
                'comment_author_email' => $email,
                'comment_type'         => 'sb_review',
                'comment_approved'     => 0,
                'user_id'              => $user ? (int) $user->ID : 0,
            ]);
            if (!$cid) return new WP_REST_Response(['success' => false, 'message' => 'Could not save your review.'], 500);
            add_comment_meta($cid, 'sb_rating', $rating);
            add_comment_meta($cid, 'sb_stay_month', sanitize_text_field($req->get_param('stayMonth')));
            add_comment_meta($cid, 'sb_source', 'direct');
            if ($user) sb_author_review_count_sync((int) $user->ID);

            return new WP_REST_Response(['success' => true, 'status' => 'pending', 'message' => 'Thanks! Your review is awaiting the host\'s approval.'], 201);
        }
    }

    // Resolve {id} → comment, enforcing that the JWT user owns the listing.
    if (!function_exists('sb_review_owner_guard')) {
        function sb_review_owner_guard(WP_REST_Request $req) {
            $user = sb_rev_user($req);
            if (!$user) return ['err' => new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401)];
            $c = get_comment((int) $req->get_param('id'));
            if (!$c || $c->comment_type !== 'sb_review') return ['err' => new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404)];
            $post = get_post((int) $c->comment_post_ID);
            if (!$post || (int) $post->post_author !== (int) $user->ID) return ['err' => new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403)];
            return ['user' => $user, 'comment' => $c, 'post' => $post];
        }
    }

    if (!function_exists('sb_reviews_pending')) {
        function sb_reviews_pending(WP_REST_Request $req) {
            $user = sb_rev_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $ids = get_posts(['post_type' => 'accommodation', 'author' => (int) $user->ID, 'post_status' => ['publish', 'pending', 'draft'], 'posts_per_page' => 200, 'fields' => 'ids']);
            $pending = [];
            if ($ids) {
                $comments = get_comments(['post__in' => $ids, 'type' => 'sb_review', 'status' => 'hold', 'number' => 0, 'orderby' => 'comment_date', 'order' => 'DESC']);
                foreach ($comments as $c) $pending[] = sb_review_format($c, ['listing' => true]);
            }
            return new WP_REST_Response(['success' => true, 'pending' => $pending], 200);
        }
    }

    if (!function_exists('sb_reviews_moderate')) {
        function sb_reviews_moderate(WP_REST_Request $req) {
            $g = sb_review_owner_guard($req);
            if (isset($g['err'])) return $g['err'];
            $action = $req->get_param('action');
            if ($action === 'approve') {
                wp_set_comment_status($g['comment']->comment_ID, 'approve');
            } else {
                wp_set_comment_status($g['comment']->comment_ID, 'spam');
            }
            sb_review_recalc((int) $g['post']->ID);
            sb_author_review_count_sync((int) $g['comment']->user_id);
            return new WP_REST_Response(['success' => true, 'action' => $action], 200);
        }
    }

    if (!function_exists('sb_reviews_reply')) {
        function sb_reviews_reply(WP_REST_Request $req) {
            $g = sb_review_owner_guard($req);
            if (isset($g['err'])) return $g['err'];
            $reply = wp_kses_post(trim((string) $req->get_param('reply')));
            update_comment_meta($g['comment']->comment_ID, 'sb_host_reply', $reply);
            return new WP_REST_Response(['success' => true, 'hostReply' => $reply], 200);
        }
    }

    if (!function_exists('sb_reviews_mine')) {
        function sb_reviews_mine(WP_REST_Request $req) {
            $user = sb_rev_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $comments = get_comments(['user_id' => (int) $user->ID, 'type' => 'sb_review', 'status' => 'all', 'number' => 0, 'orderby' => 'comment_date', 'order' => 'DESC']);
            $reviews = [];
            foreach ($comments as $c) {
                $reviews[] = sb_review_format($c, ['listing' => true, 'status' => true, 'canEdit' => true]);
            }
            return new WP_REST_Response(['success' => true, 'reviews' => $reviews], 200);
        }
    }

    if (!function_exists('sb_reviews_edit')) {
        function sb_reviews_edit(WP_REST_Request $req) {
            $user = sb_rev_user($req);
            if (!$user) return new WP_REST_Response(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
            $c = get_comment((int) $req->get_param('id'));
            if (!$c || $c->comment_type !== 'sb_review') return new WP_REST_Response(['success' => false, 'error' => 'NOT_FOUND'], 404);
            if ((int) $c->user_id !== (int) $user->ID) return new WP_REST_Response(['success' => false, 'error' => 'FORBIDDEN'], 403);

            $rating = (int) $req->get_param('rating');
            $content = trim((string) $req->get_param('content'));
            if ($rating < 1 || $rating > 5) return new WP_REST_Response(['success' => false, 'message' => 'Rating must be 1–5.'], 400);
            if (strlen($content) < 10) return new WP_REST_Response(['success' => false, 'message' => 'Review too short.'], 400);

            wp_update_comment(['comment_ID' => $c->comment_ID, 'comment_content' => wp_kses_post($content), 'comment_approved' => 0]);
            update_comment_meta($c->comment_ID, 'sb_rating', $rating);
            if ($req->get_param('stayMonth') !== null) update_comment_meta($c->comment_ID, 'sb_stay_month', sanitize_text_field($req->get_param('stayMonth')));
            sb_review_recalc((int) $c->comment_post_ID);
            return new WP_REST_Response(['success' => true, 'status' => 'pending', 'message' => 'Updated — awaiting host approval again.'], 200);
        }
    }
}
