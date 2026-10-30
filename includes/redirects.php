<?php
/**
 * Brandix PowerKit — URL Redirects & 404 Log module.
 *
 * - Redirect ANY old URL (including ones that never existed in WordPress, e.g. /services.html
 *   from a Framer/Squarespace/HTML site) to a new URL: 301 / 302 / 307 / 410 Gone.
 * - Wildcards: "/gallery/*" matches everything under /gallery/. A target ending in "*"
 *   receives the matched remainder ("/blog/*" -> "/news/*").
 * - Bulk import for site migrations.
 * - 404 log with one-click "Create redirect".
 *
 * Rules are compiled into one non-autoloaded option, so a normal page view costs a single
 * cached option read, not a table scan.
 */
if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin keeps its logs and redirects in its own custom tables; there is no WordPress API for them and results must be live, so they are queried directly.
// phpcs:disable WordPress.Security.NonceVerification -- every admin_post handler calls bx_redir_guard() (capability + check_admin_referer); GET values on the admin screen are display-only (tab, search, notices).

function bx_redir_enabled() {
    return !empty(get_option('sb_options', [])['enable_url_redirects']);
}

function bx_redir_table() { global $wpdb; return $wpdb->prefix . 'bx_redirects'; }
function bx_404_table()   { global $wpdb; return $wpdb->prefix . 'bx_404_log'; }

/**
 * Normalise a URL or path for matching: path (+ optional query), decoded, lower-case,
 * relative to the WordPress install folder, no trailing slash.
 */
function bx_redir_normalize($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) {
        $parts = wp_parse_url($url);
        $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $query = '';
    $q = strpos($url, '?');
    if ($q !== false) {
        $query = substr($url, $q + 1);
        $url = substr($url, 0, $q);
    }
    $path = rawurldecode($url);
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;

    // Make rules site-relative when WordPress lives in a sub-folder (e.g. buildwithbs.com/client).
    $home_path = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH));
    if ($home_path !== '') {
        if (stripos($path, $home_path . '/') === 0) $path = substr($path, strlen($home_path));
        elseif (strcasecmp($path, $home_path) === 0) $path = '/';
    }

    $path = strtolower($path);
    if ($path !== '/') $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    return $query !== '' ? $path . '?' . $query : $path;
}

/** Compiled rules: ['exact' => [source => rule], 'wild' => [rule + 'p' prefix, longest first]]. */
function bx_redir_rules() {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = get_option('bx_redirects_compiled', null);
    if (!is_array($rules) || !isset($rules['exact'], $rules['wild'])) $rules = bx_redir_rebuild_cache();
    return $rules;
}

function bx_redir_rebuild_cache() {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, source, target, code FROM %i WHERE enabled = 1", bx_redir_table()), ARRAY_A);
    $compiled = ['exact' => [], 'wild' => []];
    foreach ((array) $rows as $r) {
        $item = ['id' => (int) $r['id'], 't' => (string) $r['target'], 'c' => (int) $r['code'], 's' => (string) $r['source']];
        if (substr($r['source'], -1) === '*') {
            $compiled['wild'][] = ['p' => substr($r['source'], 0, -1)] + $item;
        } else {
            $compiled['exact'][$r['source']] = $item;
        }
    }
    // Most specific wildcard wins.
    usort($compiled['wild'], function ($a, $b) { return strlen($b['p']) - strlen($a['p']); });
    update_option('bx_redirects_compiled', $compiled, false);
    return $compiled;
}

/**
 * Frontend: run before WordPress' own redirects and before the per-page redirect feature,
 * so explicit URL rules always win.
 */
add_action('template_redirect', 'bx_redir_handle_request', 0);
function bx_redir_handle_request() {
    if (!bx_redir_enabled() || is_admin()) return;
    $method = bx_request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) return;

    $uri  = bx_request_uri() ?: '/';
    $full = bx_redir_normalize($uri);
    $path = explode('?', $full, 2)[0];
    $rules = bx_redir_rules();
    if (empty($rules['exact']) && empty($rules['wild'])) return;

    $match = null;
    $rest  = '';
    if (isset($rules['exact'][$full])) {
        $match = $rules['exact'][$full];
    } elseif (isset($rules['exact'][$path])) {
        $match = $rules['exact'][$path];
    } else {
        foreach ($rules['wild'] as $w) {
            $prefix = $w['p'];
            if (strpos($path, $prefix) === 0) { $match = $w; $rest = substr($path, strlen($prefix)); break; }
            // "/gallery/*" should also catch "/gallery" itself.
            if (substr($prefix, -1) === '/' && $path === rtrim($prefix, '/')) { $match = $w; break; }
        }
    }
    if (!$match) return;

    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d", bx_redir_table(), current_time('mysql'), $match['id']));

    if ($match['c'] === 410) {
        // Tell search engines the page is gone for good; render the theme's 404 template.
        global $wp_query;
        $GLOBALS['bx_redir_gone'] = true;
        $wp_query->set_404();
        status_header(410);
        nocache_headers();
        return;
    }

    $target = $match['t'];
    if (substr($target, -1) === '*') $target = substr($target, 0, -1) . ltrim($rest, '/');
    if ($target === '' ) return;
    if ($target[0] === '/') $target = home_url($target);

    // Keep tracking parameters (utm_*, gclid) when the rule itself did not match on a query string.
    $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
    if ($query !== '' && strpos($match['s'], '?') === false && strpos($target, '?') === false) {
        $target .= '?' . $query;
    }

    // Loop guard: never redirect a URL to itself.
    $home_host   = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $target_host = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
    if ($target_host === '' || $target_host === $home_host) {
        $t_norm = explode('?', bx_redir_normalize($target), 2)[0];
        if ($t_norm === $path) return;
    }

    wp_redirect(esc_url_raw($target), $match['c'], 'Brandix PowerKit'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-defined target may be on another domain.
    exit;
}

/**
 * 404 -> homepage fallback.
 * Runs at priority 100, after URL rules (0), per-page redirects (1), WordPress' own guesses (10)
 * and the 404 logger (99), so a specific redirect always wins and the broken URL is still logged.
 * Uses 302 so browsers don't cache it: a specific redirect added later works straight away.
 */
add_action('template_redirect', 'bx_404_to_home', 100);
function bx_404_to_home() {
    if (empty(get_option('sb_options', [])['redirect_404_home'])) return;
    if (is_admin() || !is_404() || !empty($GLOBALS['bx_redir_gone'])) return;
    if (!in_array(bx_request_method(), ['GET', 'HEAD'], true)) return;

    $path = strtolower((string) wp_parse_url(bx_request_uri(), PHP_URL_PATH));

    // Missing files (images, CSS, JS, fonts, PDFs...) keep a real 404; old .html pages are still redirected.
    if (preg_match('~\.[a-z0-9]{2,5}$~', $path) && !preg_match('~\.html?$~', $path)) return;
    // Bot probes and WordPress system paths keep a real 404.
    if (preg_match('~/(wp-admin|wp-includes|wp-content|wp-json|cgi-bin|\.git|\.well-known)(/|$)|wp-config|xmlrpc~', $path)) return;

    $home = home_url('/');
    if (untrailingslashit($path) === untrailingslashit((string) wp_parse_url($home, PHP_URL_PATH))) return; // never loop on home
    wp_safe_redirect($home, 302, 'Brandix PowerKit');
    exit;
}

/** 404 logger: runs late so every redirect (ours, WordPress', per-page) has had its chance first. */
add_action('template_redirect', 'bx_404_log_request', 99);
function bx_404_log_request() {
    if (!bx_redir_enabled() || !is_404() || !empty($GLOBALS['bx_redir_gone'])) return;
    if (empty(get_option('sb_options', [])['log_404'])) return;

    $uri  = substr(bx_request_uri(), 0, 1000);
    $path = explode('?', bx_redir_normalize($uri), 2)[0];
    if ($path === '' || strlen($path) > 255) return;

    // Skip hacker probes and asset requests: they are noise, not broken links.
    if (preg_match('~\.(php\d?|env|asp|aspx|jsp|cgi|git|sql|bak|old|zip|tar|gz|ini|log|ya?ml|map)$|/(wp-admin|wp-includes|cgi-bin|\.git|\.well-known)(/|$)|wp-config|xmlrpc~i', $path)) return;
    if (empty($_SERVER['HTTP_USER_AGENT'])) return;

    global $wpdb;
    $ref = substr(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'] ?? '')), 0, 500);
    $now = current_time('mysql');
    $wpdb->query($wpdb->prepare(
        "INSERT INTO %i (url, url_hash, hits, last_referrer, first_seen, last_seen)
         VALUES (%s, %s, 1, %s, %s, %s)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen),
         last_referrer = IF(VALUES(last_referrer) <> '', VALUES(last_referrer), last_referrer)",
        bx_404_table(), $path, md5($path), $ref, $now, $now
    ));
}

/**
 * Validate one rule. Returns ['source','target','code'] or WP_Error.
 */
function bx_redir_validate($source, $target, $code) {
    $code = (int) $code;
    if (!in_array($code, [301, 302, 307, 410], true)) $code = 301;

    $source = bx_redir_normalize(sanitize_text_field($source));
    if ($source === '' || $source === '/' || $source === '/*') {
        return new WP_Error('bx_src', 'The homepage cannot be redirected from here.');
    }
    if (preg_match('~^/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes)~', $source)) {
        return new WP_Error('bx_src', 'WordPress system paths cannot be redirected.');
    }
    if (strlen($source) > 255) return new WP_Error('bx_src', 'Source URL is too long (max 255 characters).');

    $target = trim(sanitize_text_field($target));
    if ($code === 410) {
        $target = '';
    } else {
        if ($target === '') return new WP_Error('bx_tgt', 'Enter a target URL.');
        if ($target[0] === '/') {
            // Site-relative path: stored as typed (so it survives domain changes), but must form a valid URL.
            if (esc_url_raw(home_url($target)) === '' || preg_match('/\s/', $target)) {
                return new WP_Error('bx_tgt', 'Target path is not a valid URL.');
            }
        } else {
            // Require an explicit scheme; esc_url_raw() would otherwise turn "some text" into "http://some%20text".
            $target = preg_match('#^https?://[^\s/]+#i', $target) ? esc_url_raw($target, ['http', 'https']) : '';
            if ($target === '') return new WP_Error('bx_tgt', 'Target must be a path like /new-page or a full https:// URL.');
        }
        // Loop guard at save time.
        $home_host   = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $target_host = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
        if (substr($source, -1) !== '*' && ($target_host === '' || $target_host === $home_host)
            && rtrim(explode('?', bx_redir_normalize($target), 2)[0], '*') === explode('?', $source, 2)[0]) {
            return new WP_Error('bx_loop', 'Source and target are the same URL.');
        }
    }
    return ['source' => $source, 'target' => $target, 'code' => $code];
}

/** Insert or update (same source = update) a rule. */
function bx_redir_upsert($rule) {
    global $wpdb;
    $hash = md5($rule['source']);
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE source_hash = %s", bx_redir_table(), $hash));
    if ($existing) {
        $wpdb->update(bx_redir_table(), ['target' => $rule['target'], 'code' => $rule['code'], 'enabled' => 1], ['id' => (int) $existing], ['%s', '%d', '%d'], ['%d']);
        return 'updated';
    }
    $wpdb->insert(bx_redir_table(), [
        'source' => $rule['source'], 'source_hash' => $hash, 'target' => $rule['target'],
        'code' => $rule['code'], 'enabled' => 1, 'hits' => 0, 'created_at' => current_time('mysql'),
    ], ['%s', '%s', '%s', '%d', '%d', '%d', '%s']);
    // A fixed 404 should leave the log.
    $wpdb->delete(bx_404_table(), ['url_hash' => md5(explode('?', $rule['source'], 2)[0])], ['%s']);
    return 'added';
}

/* ------------------------------------------------------------------------- *
 * Admin actions (admin-post.php) — all require manage_options + nonce.
 * ------------------------------------------------------------------------- */
function bx_redir_back($args = []) {
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=brandix-url-redirects')));
    exit;
}
function bx_redir_guard($nonce_action) {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer($nonce_action);
}

add_action('admin_post_bx_redir_add', function () {
    bx_redir_guard('bx_redir_add');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and sanitised in bx_redir_validate().
    $rule = bx_redir_validate(wp_unslash($_POST['source'] ?? ''), wp_unslash($_POST['target'] ?? ''), absint($_POST['code'] ?? 301));
    if (is_wp_error($rule)) bx_redir_back(['bx_msg' => rawurlencode($rule->get_error_message()), 'bx_err' => 1]);
    $result = bx_redir_upsert($rule);
    bx_redir_rebuild_cache();
    bx_redir_back(['bx_msg' => rawurlencode($result === 'added' ? 'Redirect added.' : 'Existing redirect updated.')]);
});

add_action('admin_post_bx_redir_bulk', function () {
    bx_redir_guard('bx_redir_bulk');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each line is validated in bx_redir_validate().
    $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['bulk'] ?? ''));
    $added = $updated = 0;
    $errors = [];
    foreach ($lines as $n => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        // Format: old-url  new-url  [code]   (space, tab or comma separated)
        $parts = preg_split('/[\s,]+/', $line);
        $code  = (isset($parts[2]) && ctype_digit($parts[2])) ? (int) $parts[2] : ((isset($parts[1]) && $parts[1] === '410') ? 410 : 301);
        $tgt   = ($code === 410 && (($parts[1] ?? '') === '410')) ? '' : ($parts[1] ?? '');
        $rule  = bx_redir_validate($parts[0], $tgt, $code);
        if (is_wp_error($rule)) { $errors[] = 'Line ' . ($n + 1) . ': ' . $rule->get_error_message(); continue; }
        bx_redir_upsert($rule) === 'added' ? $added++ : $updated++;
    }
    bx_redir_rebuild_cache();
    $msg = sprintf('%d added, %d updated.', $added, $updated);
    if ($errors) $msg .= ' ' . count($errors) . ' skipped — ' . implode(' | ', array_slice($errors, 0, 5));
    bx_redir_back(['bx_msg' => rawurlencode($msg), 'bx_err' => $errors ? 1 : 0]);
});

add_action('admin_post_bx_redir_toggle', function () {
    $id = absint($_GET['id'] ?? 0);
    bx_redir_guard('bx_redir_toggle_' . $id);
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE %i SET enabled = 1 - enabled WHERE id = %d", bx_redir_table(), $id));
    bx_redir_rebuild_cache();
    bx_redir_back();
});

add_action('admin_post_bx_redir_delete', function () {
    $id = absint($_GET['id'] ?? 0);
    bx_redir_guard('bx_redir_delete_' . $id);
    global $wpdb;
    $wpdb->delete(bx_redir_table(), ['id' => $id], ['%d']);
    bx_redir_rebuild_cache();
    bx_redir_back(['bx_msg' => rawurlencode('Redirect deleted.')]);
});

add_action('admin_post_bx_404_delete', function () {
    $id = absint($_GET['id'] ?? 0);
    bx_redir_guard('bx_404_delete_' . $id);
    global $wpdb;
    $wpdb->delete(bx_404_table(), ['id' => $id], ['%d']);
    bx_redir_back(['tab' => '404']);
});

add_action('admin_post_bx_404_clear', function () {
    bx_redir_guard('bx_404_clear');
    global $wpdb;
    $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", bx_404_table()));
    bx_redir_back(['tab' => '404', 'bx_msg' => rawurlencode('404 log cleared.')]);
});

/* ------------------------------------------------------------------------- *
 * Admin page
 * ------------------------------------------------------------------------- */
add_action('admin_menu', function () {
    if (!bx_redir_enabled()) return; // hidden while the module is off
    add_submenu_page('brandix-powerkit', 'URL Redirects & 404s', 'URL Redirects & 404s', 'manage_options', 'brandix-url-redirects', 'bx_redir_admin_page');
}, 20);

function bx_redir_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    global $wpdb;
    $log_on  = !empty(get_option('sb_options', [])['log_404']);
    // 404 tab is shown only while "Log 404 errors" is on.
    $tab = ($log_on && sanitize_key(wp_unslash($_GET['tab'] ?? '')) === '404') ? '404' : 'rules';
    $base = admin_url('admin.php?page=brandix-url-redirects');
    $prefill = isset($_GET['prefill']) ? sanitize_text_field(wp_unslash($_GET['prefill'])) : '';
    $enabled = bx_redir_enabled();
    ?>
    <style>
        .bx-r-wrap{max-width:1100px}.bx-r-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin:16px 0}
        .bx-r-grid{display:grid;grid-template-columns:2fr 2fr 1fr auto;gap:10px;align-items:end}
        .bx-r-grid label{display:block;font-weight:600;margin-bottom:4px}.bx-r-grid input,.bx-r-grid select{width:100%}
        .bx-r-code{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:#e0f2fe;color:#0369a1}
        .bx-r-code.c410{background:#fee2e2;color:#991b1b}.bx-r-off td{opacity:.5}
        .bx-r-help{color:#64748b;font-size:13px;margin:6px 0 0}
        @media(max-width:782px){.bx-r-grid{grid-template-columns:1fr}}
    </style>
    <div class="wrap bx-r-wrap">
        <h1>URL Redirects &amp; 404 Log</h1>
        <?php if (!$enabled): ?>
            <div class="notice notice-warning"><p>The URL Redirects module is <strong>off</strong>. Turn it on in <a href="<?php echo esc_url(admin_url('admin.php?page=brandix-powerkit')); ?>">Brandix Kit → Master Utility Modules</a>. Rules can still be prepared here.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bx_msg'])): ?>
            <div class="notice <?php echo !empty($_GET['bx_err']) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['bx_msg']))); ?></p></div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url($base); ?>" class="nav-tab <?php echo $tab === 'rules' ? 'nav-tab-active' : ''; ?>">Redirect Rules</a>
            <?php if ($log_on): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', '404', $base)); ?>" class="nav-tab <?php echo $tab === '404' ? 'nav-tab-active' : ''; ?>">404 Log<?php
                $c404 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", bx_404_table()));
                if ($c404) echo ' <span class="awaiting-mod">' . esc_html($c404) . '</span>';
            ?></a>
            <?php endif; ?>
        </h2>

        <?php if ($tab === 'rules'): ?>
            <div class="bx-r-card">
                <h2 style="margin-top:0">Add redirect</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bx_redir_add">
                    <?php wp_nonce_field('bx_redir_add'); ?>
                    <div class="bx-r-grid">
                        <div><label for="bx-src">Old URL (source)</label><input id="bx-src" type="text" name="source" required placeholder="/services.html or /gallery/*" value="<?php echo esc_attr($prefill); ?>"></div>
                        <div><label for="bx-tgt">New URL (target)</label><input id="bx-tgt" type="text" name="target" placeholder="/services or https://example.com/page"></div>
                        <div><label for="bx-code">Type</label><select id="bx-code" name="code">
                            <option value="301">301 Permanent</option><option value="302">302 Temporary</option>
                            <option value="307">307 Temporary</option><option value="410">410 Gone</option>
                        </select></div>
                        <div><?php submit_button('Add', 'primary', 'submit', false); ?></div>
                    </div>
                    <p class="bx-r-help">Use <code>*</code> at the end for a whole folder: <code>/blog/*</code> → <code>/news/*</code> keeps the rest of the path; <code>/gallery/*</code> → <code>/projects</code> sends everything to one page. 410 needs no target. Same old URL again = rule is updated.</p>
                </form>
            </div>

            <div class="bx-r-card">
                <h2 style="margin-top:0">Bulk import (site migrations)</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bx_redir_bulk">
                    <?php wp_nonce_field('bx_redir_bulk'); ?>
                    <textarea name="bulk" rows="6" style="width:100%;font-family:monospace" placeholder="/about-us.html  /about&#10;/services/plumbing.html  /plumbing  301&#10;/old-promo  410&#10;https://oldsite.com/contact.php  /contact"></textarea>
                    <p class="bx-r-help">One rule per line: <code>old-url new-url [301|302|307]</code>, or <code>old-url 410</code>. Spaces, tabs or commas. Lines starting with # are ignored. You can paste two columns straight from Google Sheets.</p>
                    <?php submit_button('Import rules', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <?php
            $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            if ($s !== '') {
                $like = '%' . $wpdb->esc_like($s) . '%';
                $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE source LIKE %s OR target LIKE %s ORDER BY id DESC LIMIT 500", bx_redir_table(), $like, $like), ARRAY_A);
            } else {
                $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY id DESC LIMIT 500", bx_redir_table()), ARRAY_A);
            }
            ?>
            <form method="get" style="margin:10px 0;display:flex;gap:8px">
                <input type="hidden" name="page" value="brandix-url-redirects">
                <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="Search rules">
                <?php submit_button('Search', '', '', false); ?>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Old URL</th><th>New URL</th><th style="width:90px">Type</th><th style="width:70px">Hits</th><th style="width:150px">Last hit</th><th style="width:150px">Actions</th></tr></thead>
                <tbody>
                <?php if ($rules): foreach ($rules as $r):
                    $tog = wp_nonce_url(admin_url('admin-post.php?action=bx_redir_toggle&id=' . $r['id']), 'bx_redir_toggle_' . $r['id']);
                    $del = wp_nonce_url(admin_url('admin-post.php?action=bx_redir_delete&id=' . $r['id']), 'bx_redir_delete_' . $r['id']);
                    $code = (int) $r['code'];
                ?>
                    <tr class="<?php echo $r['enabled'] ? '' : 'bx-r-off'; ?>">
                        <td><code><?php echo esc_html($r['source']); ?></code></td>
                        <td><?php echo $code === 410 ? '<em>— gone —</em>' : '<code>' . esc_html($r['target']) . '</code>'; ?></td>
                        <td><span class="bx-r-code <?php echo $code === 410 ? 'c410' : ''; ?>"><?php echo esc_html($code); ?></span></td>
                        <td><?php echo esc_html($r['hits']); ?></td>
                        <td><?php echo $r['last_hit'] ? esc_html($r['last_hit']) : '—'; ?></td>
                        <td>
                            <a href="<?php echo esc_url($tog); ?>"><?php echo $r['enabled'] ? 'Disable' : 'Enable'; ?></a> |
                            <a href="<?php echo esc_url($del); ?>" style="color:#b91c1c" onclick="return confirm('Delete this redirect?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:20px">No URL redirects yet. Per-page redirects set from the Pages list are under <a href="<?php echo esc_url(admin_url('admin.php?page=brandix-redirections')); ?>">Page Redirects</a>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

        <?php else: // 404 tab ?>
            <div class="bx-r-card">
                <p style="margin-top:0">Broken URLs visitors and search engines are hitting, most frequent first. Bot probes (.php, .env, wp-admin…) are ignored.
                    <?php if (!$log_on): ?><br><strong>404 logging is off</strong> — turn on "Log 404 errors" in Brandix Kit → Master Utility Modules.<?php endif; ?></p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bx_404_clear'), 'bx_404_clear')); ?>" onclick="return confirm('Clear the whole 404 log?');">Clear All</a>
            </div>
            <?php $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY hits DESC, last_seen DESC LIMIT 300", bx_404_table()), ARRAY_A); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Broken URL</th><th style="width:70px">Hits</th><th>Last referrer</th><th style="width:150px">Last seen</th><th style="width:170px">Actions</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r):
                    $fix = add_query_arg('prefill', rawurlencode($r['url']), $base);
                    $del = wp_nonce_url(admin_url('admin-post.php?action=bx_404_delete&id=' . $r['id']), 'bx_404_delete_' . $r['id']);
                ?>
                    <tr>
                        <td><code><?php echo esc_html($r['url']); ?></code></td>
                        <td><strong><?php echo esc_html($r['hits']); ?></strong></td>
                        <td style="word-break:break-all"><?php echo $r['last_referrer'] ? esc_html($r['last_referrer']) : '—'; ?></td>
                        <td><?php echo esc_html($r['last_seen']); ?></td>
                        <td><a class="button button-small button-primary" href="<?php echo esc_url($fix); ?>">Create redirect</a> <a class="button button-small" style="color:#b91c1c" href="<?php echo esc_url($del); ?>" onclick="return confirm('Delete this 404 entry?');">Delete</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px">No 404s logged.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
