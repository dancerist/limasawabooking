<?php
/**
 * Limasawa Booking — Coupons admin (ported from siargao sb-coupons.php).
 *
 * Table-backed discount codes (wp_sb_coupons) for paid tier subscriptions.
 * Replaces limasawa's earlier simple `sb_coupons` option map (its demo codes
 * WELCOME10 / SAVE500 are migrated into the table on first load).
 *
 * Admin: /wp-admin/admin.php?page=sb-coupons + REST CRUD under
 * limasawa/v1/admin/coupons. The public live-preview endpoint
 * limasawa/v1/coupon/validate stays owned by limasawa-submit.php, which now
 * delegates to sb_validate_and_apply_coupon() defined here.
 */
if (!defined('ABSPATH')) exit;

/* ---- Table create + one-time migrate from the sb_coupons option -------- */
if (!function_exists('sb_coupons_ensure_table')) {
    function sb_coupons_ensure_table() {
        if (get_option('sb_coupons_table_v1')) return;
        global $wpdb;
        $t = $wpdb->prefix . 'sb_coupons';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            type VARCHAR(20) NOT NULL DEFAULT 'percentage',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            usage_limit INT NULL,
            times_used INT NOT NULL DEFAULT 0,
            applies_to VARCHAR(40) NOT NULL DEFAULT 'any',
            billing_periods VARCHAR(20) NOT NULL DEFAULT 'any',
            bonus_months INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) {$charset};");

        // Migrate any demo coupons from the old option map.
        $opt = get_option('sb_coupons', []);
        if (is_array($opt)) {
            foreach ($opt as $code => $c) {
                $code = strtoupper(trim((string) $code));
                if ($code === '') continue;
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE UPPER(code)=%s", $code))) continue;
                $wpdb->insert($t, [
                    'code' => $code, 'description' => 'Migrated demo coupon',
                    'type' => ($c['type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage',
                    'value' => (float) ($c['value'] ?? 0), 'active' => 1,
                    'applies_to' => 'any', 'billing_periods' => 'any', 'bonus_months' => 0,
                    'created_at' => current_time('mysql'),
                ]);
            }
        }
        update_option('sb_coupons_table_v1', 1, false);
    }
    add_action('init', 'sb_coupons_ensure_table');
}

/* ---- Canonical validator (used by the public /coupon/validate in submit.php
 *      and by renew-subscription). Returns valid/type/value/discount/new_total. */
if (!function_exists('sb_validate_and_apply_coupon')) {
    function sb_validate_and_apply_coupon($code, $tier, $billing, $base_amount) {
        global $wpdb;
        $t = $wpdb->prefix . 'sb_coupons';
        $code = strtoupper(trim((string) $code));
        $base = (float) $base_amount;
        if ($code === '') return ['valid' => false, 'message' => 'Enter a coupon code.'];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return ['valid' => false, 'message' => 'Coupon not found.'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE UPPER(code) = %s LIMIT 1", $code));
        if (!$row) return ['valid' => false, 'message' => 'Coupon not found.'];
        if (!(int) $row->active) return ['valid' => false, 'message' => 'This coupon is no longer active.'];
        if ($row->expires_at && strtotime($row->expires_at) < current_time('timestamp')) return ['valid' => false, 'message' => 'This coupon has expired.'];
        if ($row->usage_limit !== null && (int) $row->times_used >= (int) $row->usage_limit) return ['valid' => false, 'message' => 'This coupon has reached its usage limit.'];
        if ($row->applies_to && $row->applies_to !== 'any') {
            $allowed = array_map('trim', explode(',', $row->applies_to));
            if (!in_array($tier, $allowed, true)) return ['valid' => false, 'message' => 'This coupon does not apply to the selected plan.'];
        }
        $bp = (string) ($row->billing_periods ?? 'any');
        if ($bp && $bp !== 'any' && $bp !== $billing) return ['valid' => false, 'message' => "This coupon only applies to {$bp} billing."];
        if ($base <= 0) return ['valid' => false, 'message' => 'No amount to discount (free plan).'];

        $discount = $row->type === 'percentage' ? floor($base * ((float) $row->value / 100)) : min((float) $row->value, $base);
        $bonus = (int) ($row->bonus_months ?? 0);
        return [
            'valid' => true, 'id' => (int) $row->id, 'code' => $row->code, 'type' => $row->type,
            'value' => (float) $row->value, 'discount' => (float) $discount, 'new_total' => (float) max(0, $base - $discount),
            'bonus_months' => $bonus,
            'message' => $bonus > 0 ? ('Coupon applied! +' . $bonus . ' free month' . ($bonus === 1 ? '' : 's')) : 'Coupon applied!',
        ];
    }
}
if (!function_exists('sb_coupons_increment_usage')) {
    function sb_coupons_increment_usage($coupon_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'sb_coupons';
        if ($coupon_id) $wpdb->query($wpdb->prepare("UPDATE {$t} SET times_used = times_used + 1 WHERE id = %d", (int) $coupon_id));
    }
}

/* ---- Admin REST CRUD (manage_options) -------------------------------- */
add_action('rest_api_init', function () {
    $ns = 'limasawa/v1';
    $cap = function () { return current_user_can('manage_options'); };
    register_rest_route($ns, '/admin/coupons', ['methods' => 'GET', 'permission_callback' => $cap, 'callback' => 'sb_coupons_rest_list']);
    register_rest_route($ns, '/admin/coupons', ['methods' => 'POST', 'permission_callback' => $cap, 'callback' => 'sb_coupons_rest_create']);
    register_rest_route($ns, '/admin/coupons/(?P<id>\\d+)', ['methods' => 'POST', 'permission_callback' => $cap, 'callback' => 'sb_coupons_rest_update']);
    register_rest_route($ns, '/admin/coupons/(?P<id>\\d+)', ['methods' => 'DELETE', 'permission_callback' => $cap, 'callback' => 'sb_coupons_rest_delete']);
});

if (!function_exists('sb_coupons_rest_list')) {
    function sb_coupons_rest_list() {
        global $wpdb; $t = $wpdb->prefix . 'sb_coupons';
        return rest_ensure_response(['coupons' => $wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC", ARRAY_A) ?: []]);
    }
}
if (!function_exists('sb_coupons_rest_create')) {
    function sb_coupons_rest_create(WP_REST_Request $req) {
        global $wpdb; $t = $wpdb->prefix . 'sb_coupons';
        $b = $req->get_json_params() ?: [];
        $code = strtoupper(sanitize_text_field($b['code'] ?? ''));
        if (!$code || !preg_match('/^[A-Z0-9_-]{2,64}$/', $code)) return new WP_Error('bad_code', 'Code must be 2–64 chars (A-Z, 0-9, dash, underscore).', ['status' => 400]);
        $type = in_array($b['type'] ?? '', ['percentage', 'fixed'], true) ? $b['type'] : 'percentage';
        $value = (float) ($b['value'] ?? 0);
        if ($value <= 0) return new WP_Error('bad_value', 'Value must be greater than zero.', ['status' => 400]);
        if ($type === 'percentage' && $value > 100) return new WP_Error('bad_value', 'Percentage cannot exceed 100.', ['status' => 400]);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE UPPER(code) = %s", $code))) return new WP_Error('duplicate', 'A coupon with this code already exists.', ['status' => 409]);

        $exp = sanitize_text_field($b['expires_at'] ?? '');
        $applies = in_array($b['applies_to'] ?? 'any', ['any', 'pro', 'featured', 'pro,featured'], true) ? $b['applies_to'] : 'any';
        $bp = in_array($b['billing_periods'] ?? 'any', ['any', 'monthly', 'yearly'], true) ? $b['billing_periods'] : 'any';
        $wpdb->insert($t, [
            'code' => $code, 'description' => sanitize_text_field($b['description'] ?? ''),
            'type' => $type, 'value' => $value, 'active' => 1,
            'expires_at' => ($exp && strtotime($exp)) ? date('Y-m-d H:i:s', strtotime($exp)) : null,
            'usage_limit' => (isset($b['usage_limit']) && $b['usage_limit'] !== '') ? (int) $b['usage_limit'] : null,
            'times_used' => 0, 'applies_to' => $applies, 'billing_periods' => $bp,
            'bonus_months' => isset($b['bonus_months']) ? max(0, min(60, (int) $b['bonus_months'])) : 0,
            'created_at' => current_time('mysql'), 'created_by' => get_current_user_id(),
        ]);
        return rest_ensure_response(['success' => true, 'id' => (int) $wpdb->insert_id]);
    }
}
if (!function_exists('sb_coupons_rest_update')) {
    function sb_coupons_rest_update(WP_REST_Request $req) {
        global $wpdb; $t = $wpdb->prefix . 'sb_coupons';
        $id = (int) $req['id'];
        $row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)) : null;
        if (!$row) return new WP_Error('not_found', 'Coupon not found.', ['status' => 404]);
        $b = $req->get_json_params() ?: [];
        $u = [];
        if (isset($b['description'])) $u['description'] = sanitize_text_field($b['description']);
        if (isset($b['active'])) $u['active'] = $b['active'] ? 1 : 0;
        if (isset($b['type']) && in_array($b['type'], ['percentage', 'fixed'], true)) $u['type'] = $b['type'];
        if (isset($b['value'])) { $v = (float) $b['value']; if ($v <= 0) return new WP_Error('bad_value', 'Value must be > 0.', ['status' => 400]); $u['value'] = $v; }
        if (array_key_exists('expires_at', $b)) { $e = sanitize_text_field($b['expires_at']); $u['expires_at'] = ($e && strtotime($e)) ? date('Y-m-d H:i:s', strtotime($e)) : null; }
        if (array_key_exists('usage_limit', $b)) $u['usage_limit'] = ($b['usage_limit'] === '' || $b['usage_limit'] === null) ? null : (int) $b['usage_limit'];
        if (isset($b['applies_to']) && in_array($b['applies_to'], ['any', 'pro', 'featured', 'pro,featured'], true)) $u['applies_to'] = $b['applies_to'];
        if (isset($b['billing_periods']) && in_array($b['billing_periods'], ['any', 'monthly', 'yearly'], true)) $u['billing_periods'] = $b['billing_periods'];
        if (isset($b['bonus_months'])) $u['bonus_months'] = max(0, min(60, (int) $b['bonus_months']));
        if (empty($u)) return rest_ensure_response(['success' => true, 'message' => 'No changes.']);
        $wpdb->update($t, $u, ['id' => $id]);
        return rest_ensure_response(['success' => true]);
    }
}
if (!function_exists('sb_coupons_rest_delete')) {
    function sb_coupons_rest_delete(WP_REST_Request $req) {
        global $wpdb; $t = $wpdb->prefix . 'sb_coupons';
        $wpdb->delete($t, ['id' => (int) $req['id']]);
        return rest_ensure_response(['success' => true]);
    }
}

/* ---- Admin menu page -------------------------------------------------- */
add_action('admin_menu', function () {
    add_menu_page('Coupons', 'Coupons', 'manage_options', 'sb-coupons', 'sb_coupons_render_page', 'dashicons-tickets-alt', 26);
});

if (!function_exists('sb_coupons_render_page')) {
    function sb_coupons_render_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorised');
        global $wpdb; $t = $wpdb->prefix . 'sb_coupons';
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC", ARRAY_A);
        $nonce = wp_create_nonce('wp_rest');
        echo '<style>
          .sb-cp-wrap{font-size:.92rem}.sb-cp-wrap h1{display:flex;align-items:center;gap:.5rem}
          .sb-cp-count{background:#f1f5f9;color:#475569;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:999px}
          .sb-cp-grid{display:grid;grid-template-columns:360px 1fr;gap:1rem;align-items:flex-start;margin-top:1rem}
          @media(max-width:900px){.sb-cp-grid{grid-template-columns:1fr}}
          .sb-cp-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.1rem 1.2rem}
          .sb-cp-card h2{margin-top:0;font-size:1rem;font-weight:800}
          .sb-cp-form label{display:block;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:.7rem 0 .2rem}
          .sb-cp-form input,.sb-cp-form select{width:100%;padding:.45rem .7rem;border:1px solid #cbd5e1;border-radius:8px;font:inherit;font-size:.85rem;box-sizing:border-box;background:#fff}
          .sb-cp-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
          .sb-cp-form .actions{margin-top:1rem}.sb-cp-form .helper{font-size:.7rem;color:#94a3b8;margin-top:.2rem}
          .sb-cp-form .err{color:#b91c1c;font-size:.78rem;font-weight:600;margin-top:.6rem;display:none}
          .sb-cp-form .ok{color:#047857;font-size:.78rem;font-weight:600;margin-top:.6rem;display:none}
          .sb-cp-table{background:#fff;border-collapse:collapse;overflow-x:auto}
          .sb-cp-code{font-family:Menlo,Consolas,monospace;font-weight:700;color:#0e7490;background:#ecfeff;padding:2px 8px;border-radius:5px;font-size:.78rem}
          .sb-cp-pill{display:inline-block;padding:1.5px 8px;border-radius:999px;font-size:.65rem;font-weight:700;margin-right:.2rem;border:1px solid}
          .sb-cp-pill.ok{background:#ecfdf5;color:#047857;border-color:#86efac}.sb-cp-pill.off{background:#f8fafc;color:#64748b;border-color:#cbd5e1}.sb-cp-pill.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
          .sb-cp-mini-btn{font-size:.72rem;padding:3px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#475569;cursor:pointer;font-weight:700}
          .sb-cp-mini-btn.danger{color:#b91c1c;border-color:#fca5a5}
        </style>';
        echo '<div class="wrap sb-cp-wrap"><h1>Coupons <span class="sb-cp-count">' . count($rows) . '</span></h1>';
        echo '<p style="color:#64748b;max-width:720px">Discount codes for Pro and Featured subscriptions. Hosts apply a code on the billing step (new listings) or the renewal modal. Percentage and fixed (₱) types supported.</p>';
        echo '<div class="sb-cp-grid"><div class="sb-cp-card"><h2>Create coupon</h2><form id="sb-cp-create-form" class="sb-cp-form">';
        echo '<label>Code</label><input type="text" name="code" required placeholder="WELCOME20" pattern="[A-Za-z0-9_-]{2,64}" />';
        echo '<div class="helper">Letters, numbers, dash, underscore. Case-insensitive.</div>';
        echo '<label>Description (admin-only)</label><input type="text" name="description" placeholder="Launch promo" />';
        echo '<div class="row2"><div><label>Type</label><select name="type"><option value="percentage">% off</option><option value="fixed">₱ fixed off</option></select></div><div><label>Value</label><input type="number" name="value" required step="0.01" min="0.01" placeholder="20" /></div></div>';
        echo '<div class="row2"><div><label>Applies to</label><select name="applies_to"><option value="any">Any plan</option><option value="pro">Pro only</option><option value="featured">Featured only</option><option value="pro,featured">Pro &amp; Featured</option></select></div><div><label>Billing</label><select name="billing_periods"><option value="any">Monthly &amp; yearly</option><option value="monthly">Monthly only</option><option value="yearly">Yearly only</option></select></div></div>';
        echo '<div class="row2"><div><label>Expires (optional)</label><input type="date" name="expires_at" /></div><div><label>Usage limit (optional)</label><input type="number" name="usage_limit" min="1" placeholder="unlimited" /></div></div>';
        echo '<label>Bonus months (free extension)</label><input type="number" name="bonus_months" min="0" max="60" value="0" />';
        echo '<div class="helper">Adds N free months on top of the billing period when applied. 0 = discount-only.</div>';
        echo '<div class="actions"><button type="submit" class="button button-primary">Create coupon</button></div><div class="err" id="sb-cp-err"></div><div class="ok" id="sb-cp-ok"></div></form></div>';
        echo '<div><table class="wp-list-table widefat fixed striped sb-cp-table"><thead><tr><th>Code</th><th>Description</th><th>Discount</th><th>Applies</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:1.5rem">No coupons yet. Create one on the left.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $val = (float) $r['value'];
                $disc = $r['type'] === 'percentage' ? rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '% off' : '₱' . number_format($val) . ' off';
                $bm = (int) ($r['bonus_months'] ?? 0);
                if ($bm > 0) $disc .= ' <span class="sb-cp-pill ok" style="font-size:.6rem">+' . $bm . ' mo</span>';
                $applies = $r['applies_to'] === 'any' ? 'Any' : ucwords(str_replace(',', ' + ', $r['applies_to']));
                $bp = $r['billing_periods'] ?? 'any';
                if ($bp !== 'any') $applies .= ' <span class="sb-cp-pill warn" style="font-size:.6rem">' . ucfirst($bp) . '</span>';
                $used = (int) $r['times_used'] . '/' . ($r['usage_limit'] !== null ? (int) $r['usage_limit'] : '∞');
                $expires = $r['expires_at'] ? esc_html(date_i18n('M j, Y', strtotime($r['expires_at']))) : '—';
                $is_exp = $r['expires_at'] && strtotime($r['expires_at']) < current_time('timestamp');
                $is_full = $r['usage_limit'] !== null && (int) $r['times_used'] >= (int) $r['usage_limit'];
                $pill = (int) $r['active'] ? ($is_exp ? '<span class="sb-cp-pill warn">Expired</span>' : ($is_full ? '<span class="sb-cp-pill warn">Used up</span>' : '<span class="sb-cp-pill ok">Active</span>')) : '<span class="sb-cp-pill off">Disabled</span>';
                echo '<tr><td><span class="sb-cp-code">' . esc_html($r['code']) . '</span></td><td style="color:#475569">' . esc_html($r['description']) . '</td><td>' . $disc . '</td><td style="color:#64748b;font-size:.78rem">' . $applies . '</td><td style="color:#64748b">' . esc_html($used) . '</td><td style="color:#64748b">' . $expires . '</td><td>' . $pill . '</td>'
                   . '<td><button type="button" class="sb-cp-mini-btn" data-toggle="' . (int) $r['id'] . '" data-active="' . (int) $r['active'] . '">' . ((int) $r['active'] ? 'Disable' : 'Enable') . '</button> <button type="button" class="sb-cp-mini-btn danger" data-delete="' . (int) $r['id'] . '">Delete</button></td></tr>';
            }
        }
        echo '</tbody></table></div></div></div>';
        $n = esc_js($nonce);
        echo "<script>(function(){const NONCE='{$n}';const B='/wp-json/limasawa/v1/admin/coupons';const e=document.getElementById('sb-cp-err'),o=document.getElementById('sb-cp-ok');
        document.getElementById('sb-cp-create-form').addEventListener('submit',async ev=>{ev.preventDefault();const f=ev.target;const d={code:f.code.value.trim(),description:f.description.value.trim(),type:f.type.value,value:parseFloat(f.value.value||'0'),applies_to:f.applies_to.value,billing_periods:f.billing_periods.value,bonus_months:parseInt(f.bonus_months.value||'0',10),expires_at:f.expires_at.value||null,usage_limit:f.usage_limit.value||null};try{const r=await fetch(B,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},body:JSON.stringify(d)});const j=await r.json();if(!r.ok){e.textContent=j.message||'Could not create coupon.';e.style.display='block';o.style.display='none';return}o.textContent='Created. Reloading…';o.style.display='block';e.style.display='none';setTimeout(()=>location.reload(),600)}catch(_){e.textContent='Network error.';e.style.display='block'}});
        document.querySelectorAll('[data-toggle]').forEach(b=>b.addEventListener('click',async()=>{const r=await fetch(B+'/'+b.dataset.toggle,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},body:JSON.stringify({active:b.dataset.active==='1'?0:1})});if(r.ok)location.reload();else alert('Could not update.')}));
        document.querySelectorAll('[data-delete]').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Delete this coupon?'))return;const r=await fetch(B+'/'+b.dataset.delete,{method:'DELETE',credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}});if(r.ok)location.reload();else alert('Could not delete.')}));
        })();</script>";
    }
}
