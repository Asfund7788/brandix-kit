<?php
/**
 * Plugin Name: Brandix PowerKit - Enterprise Agency Engine
 * Description: All-in-one professional agency utility: URL redirects & 404 log, cookie consent (Consent Mode v2), image optimizer, Sticky action buttons with detached labels, high-performance duplicator, 301 redirection manager with hit tracking, SEO alt tags, maintenance mode, advanced malware security lockdown, global email outcome logging, Google and Microsoft OAuth APIs, SMTP2GO API, Other SMTP, and multiple dynamic search & replace engine.
 * Version: 11.31
 * Update URI: https://github.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Brandix Soft
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin keeps its logs and redirects in its own custom tables; there is no WordPress API for them and results must be live, so they are queried directly.

/** Raw request URI (unslashed). Used only for URL matching; escaped before output, prepared before SQL. */
function bx_request_uri() {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- exact redirect matching needs the raw URI.
    return isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
}
/** Request method, e.g. GET or POST. */
function bx_request_method() {
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
}
/** Visitor IP address as reported by the server. */
function bx_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

/**
 * 1. Database Initialization & Schema Setup
 * Runs only on activation/version upgrade, not on every frontend request.
 */
define('BX_POWERKIT_DB_VERSION', '11.31');
define('BX_POWERKIT_FILE', __FILE__);

// Feature modules (each one checks its own on/off switch).
require_once __DIR__ . '/includes/redirects.php';
require_once __DIR__ . '/includes/cookie-consent.php';
require_once __DIR__ . '/includes/image-optimizer.php';
require_once __DIR__ . '/includes/bottom-nav.php';
require_once __DIR__ . '/includes/page-404.php';
require_once __DIR__ . '/includes/updater.php';

function bx_powerkit_install_db() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $email = $wpdb->prefix . 'bx_email_logs';
    $activity = $wpdb->prefix . 'bx_user_activity';
    $audit = $wpdb->prefix . 'bx_audit_activity';

    dbDelta("CREATE TABLE {$email} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        to_email text NOT NULL,
        subject text NOT NULL,
        message longtext NOT NULL,
        headers text DEFAULT '',
        status varchar(20) DEFAULT 'Sent',
        sent_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY sent_at (sent_at)
    ) {$charset_collate};");

    dbDelta("CREATE TABLE {$activity} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_login varchar(60) NOT NULL,
        user_ip varchar(100) NOT NULL,
        country varchar(100) DEFAULT 'Unknown',
        city varchar(100) DEFAULT 'Unknown',
        login_time datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY login_time (login_time)
    ) {$charset_collate};");

    dbDelta("CREATE TABLE {$audit} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_login varchar(100) NOT NULL DEFAULT '',
        action_type varchar(100) NOT NULL DEFAULT '',
        object_type varchar(50) NOT NULL DEFAULT '',
        object_id bigint(20) unsigned NOT NULL DEFAULT 0,
        object_name text NOT NULL,
        object_url text NOT NULL,
        user_ip varchar(100) NOT NULL DEFAULT '',
        action_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY action_type (action_type),
        KEY object_type (object_type),
        KEY user_id (user_id),
        KEY action_time (action_time)
    ) {$charset_collate};");

    // URL redirect rules (source_hash keeps the unique index short on older MySQL).
    dbDelta("CREATE TABLE {$wpdb->prefix}bx_redirects (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source varchar(255) NOT NULL DEFAULT '',
        source_hash char(32) NOT NULL DEFAULT '',
        target text NOT NULL,
        code smallint(3) unsigned NOT NULL DEFAULT 301,
        enabled tinyint(1) NOT NULL DEFAULT 1,
        hits bigint(20) unsigned NOT NULL DEFAULT 0,
        last_hit datetime NULL DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY source_hash (source_hash)
    ) {$charset_collate};");

    dbDelta("CREATE TABLE {$wpdb->prefix}bx_404_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url varchar(255) NOT NULL DEFAULT '',
        url_hash char(32) NOT NULL DEFAULT '',
        hits bigint(20) unsigned NOT NULL DEFAULT 1,
        last_referrer varchar(500) NOT NULL DEFAULT '',
        first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY last_seen (last_seen)
    ) {$charset_collate};");

    dbDelta("CREATE TABLE {$wpdb->prefix}bx_consent_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        consent_id varchar(40) NOT NULL DEFAULT '',
        analytics tinyint(1) NOT NULL DEFAULT 0,
        marketing tinyint(1) NOT NULL DEFAULT 0,
        version int(10) unsigned NOT NULL DEFAULT 1,
        page varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY created_at (created_at)
    ) {$charset_collate};");

    if (function_exists('bx_redir_rebuild_cache')) bx_redir_rebuild_cache();

    // First install: save the same defaults the settings screen shows, so UI and behaviour match.
    if (get_option('sb_options', null) === null) {
        add_option('sb_options', bx_powerkit_default_options());
    }

    update_option('bx_powerkit_db_version', BX_POWERKIT_DB_VERSION, false);
    bx_sync_uploads_htaccess(!empty(get_option('sb_options', [])['block_uploads_php']));
}
register_activation_hook(__FILE__, 'bx_powerkit_install_db');

/** Defaults that match the "checked" state shown on the settings screen. */
function bx_powerkit_default_options() {
    return [
        'enable_sticky_buttons' => 1,
        'enable_duplicator'     => 1,
        'enable_redirection'    => 1,
        'enable_email_logging'  => 1,
        'enable_activity_log'   => 1,
        'enable_audit_log'      => 1,
        'force_from_email'      => 1,
        'force_from_name'       => 1,
        'smtp_auth'             => 1,
        'mail_provider'         => 'smtp',
        'smtp_host'             => 'mail.smtp2go.com',
        'smtp_port'             => 2525,
        'smtp_secure'           => 'tls',
        'log_retention_days'    => 90,
        'position'              => 'right',
        'icon_size'             => 52,
        'label_font_size'       => 14,
        'enable_url_redirects'  => 1,
        'log_404'               => 1,
        'redirect_404_home'     => 0,
        'enable_cookie_consent' => 0, // needs the client's policy link first
        'enable_bottom_nav'     => 0,
        'enable_image_optimizer'=> 1,
        'img_max_dimension'     => 2048,
        'img_quality'           => 82,
        'img_webp'              => function_exists('bx_img_webp_supported') && bx_img_webp_supported() ? 1 : 0,
    ];
}
add_action('plugins_loaded', function() {
    if (get_option('bx_powerkit_db_version') !== BX_POWERKIT_DB_VERSION) bx_powerkit_install_db();
});

/**
 * 2. Core Security & File Lockdown Enforcement
 */
add_action('init', function() {
    $options = get_option('sb_options', []);
    if (!empty($options['file_lockdown'])) {
        if (!defined('DISALLOW_FILE_MODS')) define('DISALLOW_FILE_MODS', true);
        if (!defined('DISALLOW_FILE_EDIT')) define('DISALLOW_FILE_EDIT', true);
    }
});

/**
 * 2.1 Advanced Malware & Uploads Hardening Protection
 */
function bx_sync_uploads_htaccess($enabled) {
    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error']) || empty($upload_dir['basedir'])) return;
    $path = trailingslashit($upload_dir['basedir']) . '.htaccess';
    $begin = '# BEGIN Brandix PowerKit';
    $end = '# END Brandix PowerKit';
    $existing = file_exists($path) ? (string) @file_get_contents($path) : '';
    $existing = preg_replace('/\n?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s', "\n", $existing);
    $existing = trim($existing);
    if ($enabled) {
        // Cover common PHP handler extensions used by malware droppers (.php5, .php7, .phtml, .phar, etc.).
        $rule = $begin . "\n<FilesMatch \"\\.(php[0-9]?|pht|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n" . $end;
        $existing = $existing === '' ? $rule . "\n" : $existing . "\n\n" . $rule . "\n";
    } elseif ($existing !== '') {
        $existing .= "\n";
    }
    if (!$enabled && !file_exists($path)) return;
    @file_put_contents($path, $existing, LOCK_EX);
}

add_action('update_option_sb_options', function($old, $new) {
    $old_enabled = !empty($old['block_uploads_php']);
    $new_enabled = !empty($new['block_uploads_php']);
    if ($old_enabled !== $new_enabled) bx_sync_uploads_htaccess($new_enabled);
}, 10, 2);

add_action('init', function() {
    $options = get_option('sb_options', []);
    if (!empty($options['block_xmlrpc'])) {
        add_filter('xmlrpc_enabled', '__return_false');
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            wp_die('XML-RPC is disabled on this website.', 'XML-RPC Disabled', ['response' => 403]);
        }
    }
}, 0);

// Detect Unauthorized Admin Creation OR Promotion & Log to Global Email Logs
function bx_admin_alert_send($user_id, $reason) {
    static $sent = [];
    if (isset($sent[$user_id])) return; // One alert per user per request.
    $options = get_option('sb_options', []);
    if (empty($options['admin_creation_alert'])) return;

    $user = get_userdata($user_id);
    if (!$user || !in_array('administrator', (array) $user->roles, true)) return;
    $sent[$user_id] = true;

    $actor = wp_get_current_user();
    $subject = 'SECURITY ALERT: ' . $reason . ' on ' . home_url();
    $message = $reason . ".\n\nUsername: " . $user->user_login
        . "\nEmail: " . $user->user_email
        . "\nPerformed by: " . (($actor && $actor->exists()) ? $actor->user_login : 'System / unknown')
        . "\nIP: " . bx_client_ip()
        . "\nTime: " . current_time('mysql');
    wp_mail(get_option('admin_email'), $subject, $message);
}
add_action('user_register', function($user_id) {
    bx_admin_alert_send($user_id, 'New Administrator account created');
});
// Existing user promoted to administrator (role change or extra role added).
add_action('set_user_role', function($user_id, $role, $old_roles) {
    if ($role !== 'administrator' || empty($old_roles) || in_array('administrator', (array) $old_roles, true)) return;
    bx_admin_alert_send($user_id, 'Existing user promoted to Administrator');
}, 10, 3);
add_action('add_user_role', function($user_id, $role) {
    if ($role !== 'administrator') return;
    if (doing_action('user_register')) return;
    bx_admin_alert_send($user_id, 'Administrator role added to existing user');
}, 10, 2);

/**
 * 3. Dynamic Auto-Copyright Year Shortcode
 */
add_shortcode('bux_current_year', function() {
    return wp_date('Y');
});

/**
 * 4. Global Text, Number & Link Replacer Engine (Multiple Dynamic Rules)
 */
add_action('template_redirect', function() {
    if (is_admin()) return;

    $options = get_option('sb_options', []);
    if (empty($options['enable_global_replace']) || empty($options['replace_rules']) || !is_array($options['replace_rules'])) return;

    $search_texts = [];
    $replace_texts = [];

    foreach ($options['replace_rules'] as $rule) {
        $s = $rule['search'] ?? '';
        $r = $rule['replace'] ?? '';
        if (!empty($s)) {
            $search_texts[] = $s;
            $replace_texts[] = $r;
            // Rendered HTML encodes "&" as "&amp;" — also match the encoded form.
            if (strpos($s, '&') !== false) {
                $search_texts[] = str_replace('&', '&amp;', $s);
                $replace_texts[] = str_replace('&', '&amp;', $r);
            }
        }
    }

    if (empty($search_texts)) return;

    ob_start(function($buffer) use ($search_texts, $replace_texts) {
        if (!is_string($buffer) || empty($buffer)) return $buffer;
        // Avoid corrupting JavaScript, CSS and code-like blocks while still allowing visible HTML/attributes to be updated.
        $parts = preg_split('~(<(?:script|style|pre|textarea)\b[^>]*>.*?</(?:script|style|pre|textarea)>)~is', $buffer, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return str_replace($search_texts, $replace_texts, $buffer);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) $parts[$i] = str_replace($search_texts, $replace_texts, $part);
        }
        return implode('', $parts);
    });
});

/**
 * 5. User Login Security & Geolocation Tracker
 */
add_action('wp_login', function($user_login, $user) {
    $options = get_option('sb_options', []);
    if (empty($options['enable_activity_log'])) return;

    global $wpdb;
    $ip = bx_client_ip();
    $country = 'Unknown';
    $city = 'Unknown';

    if (!empty($options['enable_geolocation']) && $ip && $ip !== '127.0.0.1' && $ip !== '::1') {
        $response = wp_remote_get("https://ipapi.co/{$ip}/json/", ['timeout' => 3]);
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['country_name'])) $country = $data['country_name'];
            if (!empty($data['city'])) $city = $data['city'];
        }
    } elseif ($ip === '127.0.0.1' || $ip === '::1') {
        $country = 'Localhost';
        $city = 'Localhost';
    }

    $wpdb->insert(
        $wpdb->prefix . 'bx_user_activity',
        [
            'user_login' => $user_login,
            'user_ip'    => $ip,
            'country'    => $country,
            'city'       => $city,
            'login_time' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );
}, 10, 2);

/**
 * 6. WordPress Email Outcome Logger (provider acceptance is not delivery).
 */
function bx_mail_log_outcome($args, $status) {
    if (empty(get_option('sb_options', [])['enable_email_logging'])) return;
    global $wpdb;
    $to = $args['to'] ?? '';
    $headers = $args['headers'] ?? '';
    $o = get_option('sb_options', []);
    $store_content = !empty($o['store_email_content']);
    $wpdb->insert($wpdb->prefix . 'bx_email_logs', [
        'to_email'=>sanitize_text_field(is_array($to) ? implode(', ', $to) : $to),
        'subject'=>sanitize_text_field($args['subject'] ?? ''),
        'message'=>$store_content ? maybe_serialize($args['message'] ?? '') : '',
        'headers'=>$store_content ? sanitize_textarea_field(is_array($headers) ? implode("\n", $headers) : $headers) : '',
        'status'=>$status, 'sent_at'=>current_time('mysql')
    ], ['%s','%s','%s','%s','%s','%s']);
}
add_action('wp_mail_succeeded', function($args) { bx_mail_log_outcome($args, 'Accepted'); });
add_action('wp_mail_failed', function($error) {
    $args = $error->get_error_data();
    bx_mail_log_outcome(is_array($args) ? $args : [], 'Failed');
    $GLOBALS['bx_mail_last_error'] = $error->get_error_message();
});

/**
 * 7. Provider Mailer: Gmail API, Microsoft Graph, SMTP2GO API and Other SMTP.
 * OAuth credentials/tokens are site-local; no third-party connection broker.
 */
function bx_mail_provider($options = null) {
    $options = is_array($options) ? $options : get_option('sb_options', []);
    return in_array($options['mail_provider'] ?? '', ['google','outlook','smtp2go','smtp'], true) ? $options['mail_provider'] : 'smtp';
}
function bx_mail_b64url($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function bx_mail_secret($value, $decrypt = false) {
    if ($value === '') return '';
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL is required for mailer secrets.');
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    if ($decrypt) {
        if (strpos($value, 'bxenc:') !== 0) return $value; // Legacy SMTP password migration.
        $raw = base64_decode(substr($value, 6), true);
        if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Stored mailer secret is invalid. Save credentials again.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Cannot decrypt mailer secret. Re-enter credentials after changing WordPress salts.');
        return $plain;
    }
    // Settings API may sanitize twice when creating an option.
    if (strpos($value, 'bxenc:') === 0) {
        try { bx_mail_secret($value, true); return $value; } catch (Throwable $ignored) {}
    }
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new RuntimeException('Cannot encrypt mailer secret.');
    return 'bxenc:' . base64_encode($iv . $tag . $encrypted);
}
function bx_mail_oauth_config($provider, $o = null) {
    $o = is_array($o) ? $o : get_option('sb_options', []);
    if ($provider === 'google') return [
        'authorize'=>'https://accounts.google.com/o/oauth2/v2/auth',
        'token'=>'https://oauth2.googleapis.com/token',
        'scope'=>'https://www.googleapis.com/auth/gmail.send',
        'id'=>$o['google_client_id'] ?? '', 'secret'=>$o['google_client_secret'] ?? ''
    ];
    $tenant = $o['outlook_tenant'] ?? 'common';
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $tenant)) $tenant = 'common';
    return [
        'authorize'=>'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize',
        'token'=>'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
        'scope'=>'offline_access https://graph.microsoft.com/Mail.Send',
        'id'=>$o['outlook_client_id'] ?? '', 'secret'=>$o['outlook_client_secret'] ?? ''
    ];
}
function bx_mail_callback_uri() { return admin_url('admin-post.php?action=bx_mail_oauth_callback'); }
function bx_mail_fingerprint($provider, $o = null) {
    $c = bx_mail_oauth_config($provider, $o);
    return hash('sha256', $c['id'] . '|' . $c['secret'] . '|' . $c['token']);
}
function bx_mail_token_request($provider, $params) {
    $c = bx_mail_oauth_config($provider);
    $params['client_id'] = $c['id'];
    $params['client_secret'] = bx_mail_secret($c['secret'], true);
    $response = wp_remote_post($c['token'], ['timeout'=>30, 'redirection'=>0, 'body'=>$params]);
    if (is_wp_error($response)) throw new RuntimeException('OAuth token request failed. Check server connectivity.');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) !== 200 || empty($data['access_token'])) {
        $code = is_array($data) && is_string($data['error'] ?? null) ? sanitize_key($data['error']) : 'token_error';
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is logged, and escaped wherever it is displayed.
        throw new RuntimeException('OAuth authorization failed (' . $code . '). Check app credentials, consent and redirect URI; reconnect if necessary.');
    }
    return $data;
}
function bx_mail_save_tokens($provider, $data, $old_refresh = '') {
    $refresh = $data['refresh_token'] ?? $old_refresh;
    if (!$refresh) throw new RuntimeException('No refresh token received. Revoke the app grant at the provider, then connect again with offline access.');
    update_option('bx_mail_tokens_' . $provider, [
        'access'=>bx_mail_secret($data['access_token']), 'refresh'=>bx_mail_secret($refresh),
        'expires'=>time() + max(0, (int)($data['expires_in'] ?? 3600)),
        'fingerprint'=>bx_mail_fingerprint($provider)
    ], false);
}
function bx_mail_access_token($provider, $force = false) {
    $tokens = get_option('bx_mail_tokens_' . $provider, []);
    if (empty($tokens['refresh']) || !hash_equals(bx_mail_fingerprint($provider), $tokens['fingerprint'] ?? '')) {
        throw new RuntimeException('Connect the selected mail provider in Brandix Kit first.');
    }
    if (!$force && !empty($tokens['access']) && ($tokens['expires'] ?? 0) > time() + 90) return bx_mail_secret($tokens['access'], true);
    // Prevent simultaneous requests from rotating the same refresh token.
    // Instead of failing immediately, wait up to ~10s for the other request to finish
    // so concurrent form submissions are not lost.
    $lock = 'bx_mail_refresh_lock_' . $provider;
    $old_access = $tokens['access'] ?? '';
    $deadline = microtime(true) + 10;
    while (!bx_mail_try_lock($lock)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Mail authorization is refreshing in another request. Please retry shortly.');
        }
        usleep(500000);
        // Another request may have finished refreshing; reload tokens from the database.
        $fresh = bx_mail_reload_tokens($provider);
        if (!empty($fresh['access']) && $fresh['access'] !== $old_access && ($fresh['expires'] ?? 0) > time() + 90) {
            return bx_mail_secret($fresh['access'], true);
        }
    }
    try {
        // Re-check after acquiring the lock in case a refresh completed just before it.
        $tokens = bx_mail_reload_tokens($provider);
        if (!empty($tokens['access']) && $tokens['access'] !== $old_access && ($tokens['expires'] ?? 0) > time() + 90) {
            return bx_mail_secret($tokens['access'], true);
        }
        if (empty($tokens['refresh'])) throw new RuntimeException('Connect the selected mail provider in Brandix Kit first.');
        $refresh = bx_mail_secret($tokens['refresh'], true);
        $data = bx_mail_token_request($provider, ['grant_type'=>'refresh_token', 'refresh_token'=>$refresh]);
        bx_mail_save_tokens($provider, $data, $refresh);
        return $data['access_token'];
    } finally { bx_mail_release_lock($lock); }
}

/**
 * Atomic lock using INSERT IGNORE on the options table.
 * add_option() is not atomic (it uses ON DUPLICATE KEY UPDATE), so two requests could both "win".
 */
function bx_mail_try_lock($lock) {
    global $wpdb;
    $insert = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
    if ($wpdb->query($wpdb->prepare($insert, $lock, (string) time()))) return true; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $insert is a constant SQL string with placeholders.
    // Clear a stale lock left by a crashed request (older than 60 seconds), then try once more.
    $ts = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock));
    if ($ts && $ts < time() - 60) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, (string) $ts));
        return (bool) $wpdb->query($wpdb->prepare($insert, $lock, (string) time())); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $insert is a constant SQL string with placeholders.
    }
    return false;
}
function bx_mail_release_lock($lock) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock));
    wp_cache_delete($lock, 'options');
}
function bx_mail_reload_tokens($provider) {
    // Bypass the per-request object cache so we see tokens saved by other requests.
    wp_cache_delete('bx_mail_tokens_' . $provider, 'options');
    $tokens = get_option('bx_mail_tokens_' . $provider, []);
    return is_array($tokens) ? $tokens : [];
}
add_action('admin_post_bx_mail_connect', function() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('bx_mail_connect');
    $provider = sanitize_key($_GET['provider'] ?? '');
    if (!in_array($provider, ['google','outlook'], true)) wp_die('Invalid mail provider.');
    $c = bx_mail_oauth_config($provider);
    if (!$c['id'] || !$c['secret']) wp_die('Save the Client ID and Client Secret before connecting.');
    if (strpos(bx_mail_callback_uri(), 'https://') !== 0) wp_die('Use HTTPS for this site before connecting an OAuth mail provider.');
    $state = bin2hex(random_bytes(32));
    $verifier = bx_mail_b64url(random_bytes(48));
    set_transient('bx_mail_state_' . hash('sha256', $state), [
        'user'=>get_current_user_id(), 'session'=>hash('sha256', wp_get_session_token()),
        'provider'=>$provider, 'verifier'=>$verifier, 'fingerprint'=>bx_mail_fingerprint($provider)
    ], 10 * MINUTE_IN_SECONDS);
    $params = ['client_id'=>$c['id'], 'redirect_uri'=>bx_mail_callback_uri(), 'response_type'=>'code',
        'scope'=>$c['scope'], 'state'=>$state, 'code_challenge'=>bx_mail_b64url(hash('sha256', $verifier, true)), 'code_challenge_method'=>'S256'];
    if ($provider === 'google') { $params['access_type']='offline'; $params['prompt']='consent'; }
    else { $params['response_mode']='query'; $params['prompt']='select_account'; }
    // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- sends the admin to the OAuth provider (external host).
    wp_redirect($c['authorize'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
});
add_action('admin_post_bx_mail_oauth_callback', function() {
    if (!current_user_can('manage_options')) wp_die('Sign in as the administrator who started the connection.');
    $state = sanitize_text_field(wp_unslash($_GET['state'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, protected by the single-use state token.
    if (!preg_match('/^[a-f0-9]{64}$/D', $state)) wp_die('Invalid OAuth state.');
    $key = 'bx_mail_state_' . hash('sha256', $state);
    $saved = get_transient($key);
    if (!$saved || $saved['user'] !== get_current_user_id() || !hash_equals($saved['session'], hash('sha256', wp_get_session_token()))) wp_die('Connection expired or session changed. Start again from Brandix Kit.');
    delete_transient($key);
    if (!hash_equals($saved['fingerprint'], bx_mail_fingerprint($saved['provider']))) wp_die('Credentials changed during authorization. Start again.');
    if (!empty($_GET['error'])) wp_die('Provider authorization was declined or failed. Start again from Brandix Kit.'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, protected by the single-use state token.
    $code = sanitize_text_field(wp_unslash($_GET['code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, protected by the single-use state token.
    if (!$code) wp_die('Missing authorization code.');
    try {
        $data = bx_mail_token_request($saved['provider'], ['grant_type'=>'authorization_code', 'code'=>$code, 'redirect_uri'=>bx_mail_callback_uri(), 'code_verifier'=>$saved['verifier']]);
        bx_mail_save_tokens($saved['provider'], $data);
    } catch (Throwable $e) { wp_die(esc_html($e->getMessage())); }
    wp_safe_redirect(admin_url('admin.php?page=brandix-powerkit&bx_mail_connected=1')); exit;
});
add_action('admin_post_bx_mail_disconnect', function() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('bx_mail_disconnect');
    $provider = sanitize_key($_GET['provider'] ?? '');
    if (!in_array($provider, ['google','outlook'], true)) wp_die('Invalid provider.');
    delete_option('bx_mail_tokens_' . $provider);
    wp_safe_redirect(admin_url('admin.php?page=brandix-powerkit')); exit;
});

// Let WordPress prepare recipients, headers, HTML, attachments and wp_mail hooks.
// Only replace the final transport; API messages use PHPMailer's complete MIME.
add_filter('pre_wp_mail', function($short_circuit, $atts) {
    if ($short_circuit !== null) return $short_circuit;
    $o = get_option('sb_options', []);
    global $phpmailer;
    if (empty($o['enable_smtp'])) {
        if ($phpmailer instanceof BX_Provider_PHPMailer) $phpmailer = null;
        return null;
    }
    if (!class_exists('BX_Provider_PHPMailer', false)) {
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        class BX_Provider_PHPMailer extends \PHPMailer\PHPMailer\PHPMailer {
            public $bx_config_error = '';
            public function preSend() {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal PHPMailer error, escaped when displayed.
                if ($this->bx_config_error !== '') throw new \PHPMailer\PHPMailer\Exception($this->bx_config_error);
                return parent::preSend();
            }
            public function postSend() {
                try {
                    if (bx_mail_provider() === 'smtp') return parent::postSend();
                    return bx_mail_send_mime($this->getSentMIMEMessage());
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal PHPMailer error, escaped when displayed.
                catch (Throwable $e) { throw new \PHPMailer\PHPMailer\Exception($e->getMessage()); }
            }
        }
    }
    if (!($phpmailer instanceof BX_Provider_PHPMailer)) $phpmailer = new BX_Provider_PHPMailer(true);
    \PHPMailer\PHPMailer\PHPMailer::$validator = static function($email) { return (bool)is_email($email); };
    return null;
}, 999, 2);

add_action('phpmailer_init', function($m) {
    $o = get_option('sb_options', []);
    if (empty($o['enable_smtp'])) return;
    if ($m instanceof BX_Provider_PHPMailer) $m->bx_config_error = '';
    try {
        $provider = bx_mail_provider($o);
        $email = sanitize_email($o['smtp_from_email'] ?? '');
        $name = sanitize_text_field($o['smtp_from_name'] ?? '');
        $default_email = 'wordpress@' . preg_replace('/^www\./', '', wp_parse_url(network_home_url(), PHP_URL_HOST));
        if ($email && (!empty($o['force_from_email']) || !isset($o['force_from_email']) || !$m->From || $m->From === $default_email)) $m->setFrom($email, $m->FromName, false);
        if ($name && (!empty($o['force_from_name']) || !isset($o['force_from_name']) || !$m->FromName || $m->FromName === 'WordPress')) $m->FromName = $name;
        $m->Sender = !empty($o['mail_return_path']) && $provider === 'smtp' ? $m->From : '';
        if ($provider === 'smtp') {
            $m->isSMTP();
            $m->Host = $o['smtp_host'] ?? 'mail.smtp2go.com';
            $m->Port = (int)($o['smtp_port'] ?? 2525);
            $m->SMTPAuth = !isset($o['smtp_auth']) || !empty($o['smtp_auth']);
            $m->Username = $o['smtp_user'] ?? '';
            $m->Password = $m->SMTPAuth ? bx_mail_secret($o['smtp_pass'] ?? '', true) : '';
            $m->SMTPSecure = $o['smtp_secure'] ?? 'tls';
            $m->SMTPAutoTLS = $m->SMTPSecure !== '';
            $m->Timeout = 30;
        } else {
            if (!($m instanceof BX_Provider_PHPMailer)) throw new RuntimeException('Another mail plugin replaced Brandix mail transport. Enable only one mailer plugin.');
            $m->isMail(); // Includes Bcc in the MIME handed to the API.
        }
    } catch (Throwable $e) {
        if ($m instanceof BX_Provider_PHPMailer) $m->bx_config_error = $e->getMessage();
        else $m->Mailer = 'bx_transport_conflict'; // Fail closed if another mailer replaces the transport.
    }
}, 999);

function bx_mail_send_mime($mime) {
    $o = get_option('sb_options', []);
    $provider = bx_mail_provider($o);
    if ($provider === 'smtp2go') {
        $key = bx_mail_secret($o['smtp2go_api_key'] ?? '', true);
        if (!$key) throw new RuntimeException('Save an SMTP2GO API key first.');
        $url = 'https://api.smtp2go.com/v3/email/mime';
        $args = ['headers'=>['Content-Type'=>'application/json', 'X-Smtp2go-Api-Key'=>$key], 'body'=>wp_json_encode(['mime_email'=>base64_encode($mime), 'fastaccept'=>false])];
    } elseif (in_array($provider, ['google','outlook'], true)) {
        $token = bx_mail_access_token($provider);
        $url = $provider === 'google' ? 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' : 'https://graph.microsoft.com/v1.0/me/sendMail';
        $args = ['headers'=>['Authorization'=>'Bearer ' . $token, 'Content-Type'=>$provider === 'google' ? 'application/json' : 'text/plain'],
            'body'=>$provider === 'google' ? wp_json_encode(['raw'=>bx_mail_b64url($mime)]) : base64_encode($mime)];
    } else { throw new RuntimeException('Invalid API provider.'); }
    $args['timeout']=30; $args['redirection']=0;
    $response = wp_remote_post($url, $args);
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401 && in_array($provider, ['google','outlook'], true)) {
        $args['headers']['Authorization'] = 'Bearer ' . bx_mail_access_token($provider, true);
        $response = wp_remote_post($url, $args);
    }
    if (is_wp_error($response)) throw new RuntimeException('Mail API connection failed. Delivery is unconfirmed; check the provider log before retrying.');
    $status = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $ok = $provider === 'google' ? ($status === 200 && !empty($data['id'])) : ($provider === 'outlook' && $status === 202);
    if ($provider === 'smtp2go') {
        $d = $data['data'] ?? [];
        $ok = $status === 200 && empty($d['error']) && empty($d['error_code']) && empty($d['failed']) && empty($d['failures']) && !empty($d['succeeded']);
    }
    if (!$ok) {
        $code = $data['error']['code'] ?? $data['data']['error_code'] ?? '';
        $code = is_scalar($code) ? sanitize_text_field((string)$code) : '';
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is logged, and escaped wherever it is displayed.
        throw new RuntimeException(ucfirst($provider) . ' rejected or did not confirm the message (HTTP ' . $status . ($code ? ', ' . $code : '') . '). Check credentials, account permissions, verified sender and provider limits.');
    }
    return true;
}

function bx_mail_text_field($key, $label, $options, $default = '') {
    echo '<div style="margin:16px 0"><label class="bx-label-title" for="bx-' . esc_attr($key) . '">' . esc_html($label) . '</label><input id="bx-' . esc_attr($key) . '" type="text" class="bx-input" style="width:100%;margin-top:6px" name="sb_options[' . esc_attr($key) . ']" value="' . esc_attr($options[$key] ?? $default) . '"></div>';
}
function bx_mail_password_field($key, $label, $options) {
    $exists = !empty($options[$key]);
    echo '<div style="margin:16px 0"><label class="bx-label-title" for="bx-' . esc_attr($key) . '">' . esc_html($label) . '</label><input id="bx-' . esc_attr($key) . '" type="password" autocomplete="new-password" class="bx-input" style="width:100%;margin-top:6px" name="sb_options[' . esc_attr($key) . ']" value="" placeholder="' . esc_attr($exists ? 'Saved — leave blank to keep' : 'Enter secret') . '">';
    if ($exists) echo '<label style="display:block;margin-top:8px"><input type="checkbox" name="sb_options[clear_' . esc_attr($key) . ']" value="1"> Remove saved secret</label>';
    echo '</div>';
}

/**
 * 8. Instant Professional Maintenance Mode (503 Header)
 */
add_action('template_redirect', function() {
    $options = get_option('sb_options', []);
    if (empty($options['maintenance_mode']) || is_admin()) return;

    if (current_user_can('manage_options')) return;
    if (in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php', 'wp-register.php'])) return;

    status_header(503);
    nocache_headers();
    header('Retry-After: 3600'); // Tells Google this is temporary so rankings are kept.
    $site_name = get_bloginfo('name');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Under Maintenance | <?php echo esc_html($site_name); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { background: #0b0f19; color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; }
            .container { max-width: 600px; }
            .badge { display: inline-flex; align-items: center; gap: 8px; background: rgba(0,116,253,0.12); border: 1px solid rgba(0,116,253,0.35); color: #38bdf8; font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; padding: 8px 18px; border-radius: 30px; margin-bottom: 24px; }
            h1 { font-size: 38px; font-weight: 800; line-height: 1.2; margin-bottom: 16px; }
            h1 span { color: #0074FD; }
            p { color: #94a3b8; font-size: 16px; line-height: 1.6; margin-bottom: 30px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="badge">Scheduled Maintenance</div>
            <h1>Website Under <span>Construction</span></h1>
            <p>We are currently upgrading our platform for better performance and will be back shortly.</p>
            <div style="font-size:13px; color:#64748b;">&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html($site_name); ?>. All rights reserved.</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}, 2);

/**
 * 9. SEO Trailing Slash Sanitizer & Canonical Sync
 */
add_action('init', function() {
    $options = get_option('sb_options', []);
    if (empty($options['remove_trailing_slash']) || is_admin()) return;

    add_action('template_redirect', function() {
        if (is_admin() || is_front_page() || is_home()) return;
        // Never 301 a form POST — browsers would drop the submitted data.
        $method = bx_request_method();
        if (!in_array($method, ['GET', 'HEAD'], true)) return;

        $request_uri = bx_request_uri();
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        $query = wp_parse_url($request_uri, PHP_URL_QUERY);

        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        $trimmed_home = untrailingslashit($home_path);

        if ($path !== '/' && $path !== $trimmed_home . '/' && substr($path, -1) === '/') {
            $new_path = rtrim($path, '/');
            // Build from the configured home URL (not the Host header) so proxies/CDNs can't send users to wp-admin.
            $home = wp_parse_url(home_url());
            $redirect_url = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . $home['port'] : '') . $new_path;

            if (!empty($query)) $redirect_url .= '?' . $query;
            wp_safe_redirect($redirect_url, 301);
            exit;
        }
    }, 1);

    add_filter('user_trailingslashit', function($url, $type_of_url) {
        if ($url === home_url('/') || $url === home_url()) return $url;
        return untrailingslashit($url);
    }, 99, 2);

    // Keep SEO plugin/core canonicals in sync with the no-trailing-slash policy.
    add_filter('wpseo_canonical', function($url) {
        if ($url === home_url('/') || $url === home_url()) return $url;
        return untrailingslashit($url);
    }, 99);

    add_filter('rank_math/frontend/canonical', function($url) {
        if ($url === home_url('/') || $url === home_url()) return $url;
        return untrailingslashit($url);
    }, 99);

    add_filter('get_canonical_url', function($url) {
        if ($url === home_url('/') || $url === home_url()) return $url;
        return untrailingslashit($url);
    }, 99);
});

/**
 * 10. Automated Image SEO Alt Text Generator
 */
add_action('add_attachment', function($post_ID) {
    $options = get_option('sb_options', []);
    if (empty($options['auto_alt_tag'])) return;
    if (!wp_attachment_is_image($post_ID)) return;

    $file_name = get_post_field('post_title', $post_ID);
    $clean_title = preg_replace('%\s*[-_\s]+\s*%', ' ', $file_name);
    $clean_title = ucwords(trim($clean_title));

    update_post_meta($post_ID, '_wp_attachment_image_alt', $clean_title);
    wp_update_post(['ID' => $post_ID, 'post_title' => $clean_title]);
});

/**
 * 11. Universal 301 Redirection Manager with Hit Counter
 */
add_action('template_redirect', function() {
    $options = get_option('sb_options', []);
    if (empty($options['enable_redirection']) || is_admin()) return;

    $target_post_id = 0;
    if (is_singular()) {
        $target_post_id = get_queried_object_id();
    } elseif (is_404()) {
        // Only look up by slug on 404s, so real archives (e.g. /category/services) are never hijacked.
        $request_uri = bx_request_uri();
        $path = trim(wp_parse_url($request_uri, PHP_URL_PATH), '/');
        $home_path = trim(wp_parse_url(home_url(), PHP_URL_PATH) ?: '', '/');
        if (!empty($home_path) && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        if (!empty($path)) {
            $slug_parts = explode('/', $path);
            $slug = end($slug_parts);
            global $wpdb;
            // WordPress renames trashed slugs to "slug__trashed", so match both forms.
            // Only consider posts that actually have a redirect, so a same-slug page elsewhere can't be matched by mistake.
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_bx_redirect_url' AND pm.meta_value <> ''
                 WHERE p.post_name IN (%s, %s) AND p.post_type IN ('page', 'post')
                 AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'trash', 'private')
                 ORDER BY FIELD(p.post_status, 'publish', 'private', 'future', 'pending', 'draft', 'trash') LIMIT 1",
                $slug, $slug . '__trashed'
            ));
            if ($post) $target_post_id = $post->ID;
        }
    }

    if ($target_post_id) {
        $target_url = get_post_meta($target_post_id, '_bx_redirect_url', true);
        if (!empty($target_url)) {
            $source_url = get_permalink($target_post_id);
            $target_abs = strpos($target_url, '/') === 0 ? home_url($target_url) : $target_url;
            if ($source_url && untrailingslashit($target_abs) === untrailingslashit($source_url)) return;
            $hits = (int) get_post_meta($target_post_id, '_bx_redirect_hits', true);
            update_post_meta($target_post_id, '_bx_redirect_hits', $hits + 1);

            wp_redirect(esc_url_raw($target_url), 301, 'Brandix PowerKit'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-defined target may be on another domain.
            exit;
        }
    }
}, 1);

/**
 * 12. Smart Regional Phone Field Validation Helper
 */
function bds_get_current_form_region() {
    $locale = get_locale();
    $config = ['country_code' => 'us', 'dial_code' => '1', 'min_digits' => 10];
    if ($locale === 'en_GB') {
        $config = ['country_code' => 'gb', 'dial_code' => '44', 'min_digits' => 10];
    }
    return $config;
}

add_filter('elementor_pro/forms/validation/tel', function($field, $record, $ajax_handler) {
    $options = get_option('sb_options', []);
    if (empty($options['phone_validation'])) return;

    $value = $field['value'];
    $clean_value = preg_replace('/[^0-9]/', '', $value);
    $region = bds_get_current_form_region();
    // Empty optional field: let Elementor's own "required" rule decide.
    if ($clean_value === '') return;

    if (strlen($clean_value) < $region['min_digits']) {
        $error_msg = ($region['country_code'] === 'gb') ? __('Please enter a valid UK phone number.', 'brandix-sticky') : __('Please enter a valid US phone number.', 'brandix-sticky');
        $ajax_handler->add_error($field['id'], $error_msg);
    }
}, 10, 3);

add_action('wp_footer', function() {
    $options = get_option('sb_options', []);
    if (empty($options['phone_validation'])) return;
    if (!did_action('elementor_pro/init') || (class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode())) return;

    $region = bds_get_current_form_region();
    ?>
    <script>
    (function() {
        var CDN = "<?php echo esc_js(plugins_url('assets/intl-tel-input/', BX_POWERKIT_FILE)); ?>";
        var targetCountry = "<?php echo esc_js($region['country_code']); ?>";
        var errorMsg = targetCountry === 'gb' ? "Please enter a valid UK phone number." : "Please enter a valid US phone number.";

        function initInputs() {
            document.querySelectorAll("input[type='tel']").forEach(function(phoneInput) {
                var form = phoneInput.closest("form");
                if (!form || phoneInput.hasAttribute('data-intl-initialized')) return;
                phoneInput.setAttribute('data-intl-initialized', 'true');
                var iti = window.intlTelInput(phoneInput, {
                    initialCountry: targetCountry,
                    onlyCountries: [targetCountry],
                    separateDialCode: true,
                    allowDropdown: false,
                    utilsScript: CDN + "js/utils.js"
                });
                // Optional fields may be left empty; required-ness is handled by the form itself.
                var isOk = function() { return phoneInput.value.trim() === '' || iti.isValidNumber(); };
                var validatePhone = function() { phoneInput.setCustomValidity(isOk() ? "" : errorMsg); };
                phoneInput.addEventListener('input', validatePhone);
                phoneInput.addEventListener('blur', validatePhone);
                form.addEventListener("submit", function(e) {
                    validatePhone();
                    if (!isOk()) {
                        e.preventDefault();
                        e.stopPropagation();
                        phoneInput.reportValidity();
                    } else if (phoneInput.value.trim() !== '') {
                        phoneInput.value = iti.getNumber();
                    }
                }, true);
            });
        }

        // Load the bundled library only when the page actually has a phone field.
        function boot() {
            if (!document.querySelector("form input[type='tel']")) return;
            if (window.intlTelInput) { initInputs(); return; }
            if (boot.loading) return; // Library already requested; onload will init all fields.
            boot.loading = true;
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = CDN + 'css/intlTelInput.min.css';
            document.head.appendChild(css);
            var js = document.createElement('script');
            js.src = CDN + 'js/intlTelInput.min.js';
            js.onload = initInputs;
            document.body.appendChild(js);
        }

        if (document.readyState === 'loading') document.addEventListener("DOMContentLoaded", boot); else boot();
        // Elementor popups inject forms later; initialise them when a popup opens.
        if (window.jQuery) jQuery(document).on('elementor/popup/show', function() { setTimeout(boot, 50); });
    })();
    </script>
    <style>
    .iti { width: 100% !important; display: inline-flex !important; align-items: center !important; position: relative !important; }
    .iti__selected-flag { pointer-events: none !important; cursor: default !important; background-color: #ffffff !important; padding: 4px 8px !important; margin-left: 6px !important; border-radius: 4px !important; height: 28px !important; display: flex !important; align-items: center !important; justify-content: center !important; position: absolute !important; top: 50% !important; transform: translateY(-50%) !important; z-index: 5 !important; }
    .elementor-field-type-tel input[type='tel'] { padding-left: 80px !important; }
    .iti__selected-dial-code { color: #111111 !important; font-weight: 600 !important; margin-left: 5px !important; font-size: 13px !important; }
    .iti__arrow { display: none !important; }
    </style>
    <?php
});

/**
 * 13. Scalable Native Icon Library
 */
function sb_get_native_icons() {
    return [
        'whatsapp' => [
            'name' => 'WhatsApp (Official)',
            'svg'  => '<svg viewBox="0 0 32 32" width="28" height="28" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M16 3C8.82 3 3 8.82 3 16c0 2.47.69 4.88 2 6.98L3 30l7.25-1.9c2.04 1.19 4.38 1.9 6.75 1.9 7.18 0 13-5.82 13-13S24.18 3 16 3zm0 23.63c-2.14 0-4.22-.61-6-1.76l-.43-.27-4.4 1.15 1.17-4.28-.28-.45A10.59 10.59 0 0 1 5.37 16c0-5.87 4.77-10.63 10.63-10.63 5.87 0 10.63 4.77 10.63 10.63 0 5.87-4.76 10.63-10.63 10.63zm5.83-7.97c-.32-.16-1.9-.94-2.19-1.04-.3-.11-.51-.16-.73.16-.21.32-.83 1.04-1.01 1.26-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.58-1.59-.95-.85-1.6-1.9-1.78-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.75-1-2.4-.26-.63-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.85.4-.3.32-1.12 1.1-1.12 2.68 0 1.59 1.15 3.12 1.31 3.33.16.21 2.27 3.47 5.5 4.86.77.33 1.37.53 1.83.68.77.25 1.47.21 2.03.13.62-.09 1.9-.78 2.17-1.53.27-.75.27-1.39.19-1.53-.08-.13-.29-.21-.61-.37z"/></svg>'
        ],
        'phone' => [
            'name' => 'Phone / Call',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>'
        ],
        'email' => [
            'name' => 'Email',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>'
        ],
        'location' => [
            'name' => 'Location',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>'
        ],
        'sms' => [
            'name' => 'SMS / Text Message',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 11H7V9h2v2zm4 0h-2V9h2v2zm4 0h-2V9h2v2z"/></svg>'
        ],
        'chat' => [
            'name' => 'Live Chat',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>'
        ],
        'calendar' => [
            'name' => 'Book / Calendar',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>'
        ],
        'quote' => [
            'name' => 'Get a Quote / Form',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>'
        ],
        'directions' => [
            'name' => 'Directions',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M21.71 11.29l-9-9a.996.996 0 0 0-1.41 0l-9 9a.996.996 0 0 0 0 1.41l9 9c.39.39 1.02.39 1.41 0l9-9a.996.996 0 0 0 0-1.41zM14 14.5V12h-4v3H8v-4c0-.55.45-1 1-1h5V7.5l3.5 3.5-3.5 3.5z"/></svg>'
        ],
        'globe' => [
            'name' => 'Website',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm6.93 6h-2.95a15.65 15.65 0 0 0-1.38-3.56A8.03 8.03 0 0 1 18.93 8zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38c-.08.66-.14 1.32-.14 2s.06 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56A7.99 7.99 0 0 1 5.08 16zm2.95-8H5.08a7.99 7.99 0 0 1 4.33-3.56A15.65 15.65 0 0 0 8.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.09-.66-.16-1.32-.16-2s.07-1.35.16-2h4.68c.09.65.16 1.32.16 2s-.07 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95a8.03 8.03 0 0 1-4.33 3.56zM16.36 14c.08-.66.14-1.32.14-2s-.06-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z"/></svg>'
        ],
        'star' => [
            'name' => 'Reviews / Star',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>'
        ],
        'support' => [
            'name' => 'Support / Headset',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 1c-4.97 0-9 4.03-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7c0-4.97-4.03-9-9-9z"/></svg>'
        ],
        'cart' => [
            'name' => 'Shop / Cart',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1.003 1.003 0 0 0 20 4H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>'
        ],
        'user' => [
            'name' => 'Account / Person',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>'
        ],
        'clock' => [
            'name' => 'Opening Hours',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>'
        ],
        'info' => [
            'name' => 'Info',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>'
        ],
        'download' => [
            'name' => 'Download / Brochure',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>'
        ],
        'home' => [
            'name' => 'Home',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>'
        ],
        'arrow-up' => [
            'name' => 'Back to Top',
            'svg'  => '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M4 12l1.41 1.41L11 7.83V20h2V7.83l5.58 5.59L20 12l-8-8-8 8z"/></svg>'
        ]
    ];
}

/**
 * 13.1 Sticky buttons: launcher (collapsed mode) options, shapes, icons and animations.
 */
function bx_sticky_defaults() {
    return [
        'sticky_mode'     => 'stack',      // stack = always visible, toggle = hidden behind a launcher
        'collapse_on'     => ['d', 't', 'm'],
        'launcher_shape'  => 'circle',
        'launcher_icon'   => 'chat-dots',
        'launcher_text'   => 'Contact Us',
        'launcher_size'   => 0,            // 0 = same as button size
        'launcher_bg'     => '#2f3a8f',
        'launcher_color'  => '#ffffff',
        'open_anim'       => 'fade-up',
        'stagger'         => 1,
        'attention'       => 'pulse',
        'open_on'         => 'click',
        'start_state'     => 'closed',
        'auto_open_delay' => 5,
        'dim_bg'          => 0,
        'badge_text'      => '',
        'greeting'        => '',
        'greeting_delay'  => 3,
        'item_style'      => 'detached',
        'card_bg'         => '#ffffff',
        'card_text'       => '#1e293b',
        'btn_shape'       => 'circle',
        'offset_bottom'   => 25,
        'offset_side'     => 20,
        'sticky_layout'   => 'vertical',
    ];
}

/** Saved options merged over defaults (only sticky keys). */
function bx_sticky_opts($options) {
    $d = bx_sticky_defaults();
    return array_merge($d, array_intersect_key((array) $options, $d));
}

function bx_launcher_shapes() {
    return [
        'circle'  => 'Circle',
        'rounded' => 'Rounded Square',
        'square'  => 'Square',
        'pill'    => 'Pill (icon + text)',
        'bubble'  => 'Chat Bubble',
        'hexagon' => 'Hexagon',
        'diamond' => 'Diamond',
        'blob'    => 'Blob (animated)',
    ];
}

function bx_sticky_anims() {
    return [
        'fade-up' => 'Fade Up',
        'pop'     => 'Pop / Zoom',
        'slide'   => 'Slide From Side',
        'rise'    => 'Rise From Launcher',
        'flip'    => 'Flip Up',
        'fade'    => 'Simple Fade',
    ];
}

function bx_sticky_attention() {
    return [
        'none'      => 'None',
        'pulse'     => 'Pulse Ring',
        'bounce'    => 'Bounce',
        'wiggle'    => 'Wiggle',
        'heartbeat' => 'Heartbeat',
        'glow'      => 'Glow',
    ];
}

/** Launcher icons (24x24 paths). */
function bx_launcher_icons() {
    $p = function ($d) { return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>'; };
    $native = sb_get_native_icons();
    return [
        'chat-dots' => ['name' => 'Chat (dots)',  'svg' => $p('M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 11H7V9h2v2zm4 0h-2V9h2v2zm4 0h-2V9h2v2z')],
        'chat'      => ['name' => 'Chat Bubble',  'svg' => $p('M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z')],
        'plus'      => ['name' => 'Plus',         'svg' => $p('M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z')],
        'headset'   => ['name' => 'Headset / Support', 'svg' => $p('M12 1c-4.97 0-9 4.03-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7c0-4.97-4.03-9-9-9z')],
        'menu'      => ['name' => 'Menu (lines)', 'svg' => $p('M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z')],
        'apps'      => ['name' => 'Grid',         'svg' => $p('M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z')],
        'phone'     => ['name' => 'Phone',        'svg' => $native['phone']['svg']],
        'email'     => ['name' => 'Email',        'svg' => $native['email']['svg']],
        'whatsapp'  => ['name' => 'WhatsApp',     'svg' => $native['whatsapp']['svg']],
    ];
}

function bx_hex_rgba($hex, $alpha) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6) return 'rgba(0,0,0,' . $alpha . ')';
    return 'rgba(' . hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2)) . ',' . $alpha . ')';
}

/**
 * 14. Register Settings with Advanced Security Sanitization
 */
add_action('admin_init', function() {
    register_setting('sb_settings_group', 'sb_options', ['sanitize_callback' => 'sb_sanitize_all_options']);
});

function sb_sanitize_all_options($input) {
    $clean = [];
    $clean['enable_sticky_buttons'] = !empty($input['enable_sticky_buttons']) ? 1 : 0;
    $clean['enable_duplicator']     = !empty($input['enable_duplicator']) ? 1 : 0;
    $clean['enable_redirection']    = !empty($input['enable_redirection']) ? 1 : 0;
    $clean['remove_trailing_slash'] = !empty($input['remove_trailing_slash']) ? 1 : 0;
    $clean['auto_alt_tag']          = !empty($input['auto_alt_tag']) ? 1 : 0;
    $clean['phone_validation']      = !empty($input['phone_validation']) ? 1 : 0;
    $clean['maintenance_mode']      = !empty($input['maintenance_mode']) ? 1 : 0;
    $clean['file_lockdown']         = !empty($input['file_lockdown']) ? 1 : 0;
    
    // New Security Options
    $clean['block_xmlrpc']          = !empty($input['block_xmlrpc']) ? 1 : 0;
    $clean['block_uploads_php']     = !empty($input['block_uploads_php']) ? 1 : 0;
    $clean['admin_creation_alert']  = !empty($input['admin_creation_alert']) ? 1 : 0;

    // Global Multiple Search & Replace Settings
    // Modules: URL redirects, cookie consent, image optimizer
    $clean['enable_url_redirects']   = !empty($input['enable_url_redirects']) ? 1 : 0;
    $clean['log_404']                = !empty($input['log_404']) ? 1 : 0;
    $clean['redirect_404_home']      = !empty($input['redirect_404_home']) ? 1 : 0;
    $clean['enable_cookie_consent']  = !empty($input['enable_cookie_consent']) ? 1 : 0;
    $clean['enable_bottom_nav']      = !empty($input['enable_bottom_nav']) ? 1 : 0;
    $clean['enable_image_optimizer'] = !empty($input['enable_image_optimizer']) ? 1 : 0;
    $clean['img_max_dimension']      = min(6000, absint($input['img_max_dimension'] ?? 2048));
    if ($clean['img_max_dimension'] > 0 && $clean['img_max_dimension'] < 600) $clean['img_max_dimension'] = 600; // avoid accidental tiny images
    $clean['img_quality']            = max(40, min(100, absint($input['img_quality'] ?? 82)));
    $clean['img_webp']               = !empty($input['img_webp']) ? 1 : 0;

    $clean['enable_global_replace'] = !empty($input['enable_global_replace']) ? 1 : 0;
    $clean['replace_rules'] = [];
    if (!empty($input['replace_rules']) && is_array($input['replace_rules'])) {
        foreach ($input['replace_rules'] as $rule) {
            $s = trim($rule['search'] ?? '');
            if (empty($s)) continue;
            $clean['replace_rules'][] = [
                'search'  => sanitize_text_field($s),
                'replace' => sanitize_text_field($rule['replace'] ?? '')
            ];
        }
    }

    // Logs & Activities
    $clean['enable_email_logging']  = !empty($input['enable_email_logging']) ? 1 : 0;
    $clean['enable_activity_log']   = !empty($input['enable_activity_log']) ? 1 : 0;
    $clean['enable_geolocation']    = !empty($input['enable_geolocation']) ? 1 : 0;
    $clean['enable_audit_log']      = !empty($input['enable_audit_log']) ? 1 : 0;
    $clean['store_email_content']   = !empty($input['store_email_content']) ? 1 : 0;
    $clean['log_retention_days']    = in_array((int)($input['log_retention_days'] ?? 90), [30,60,90], true) ? (int)$input['log_retention_days'] : 90;

    // Provider settings. Keep legacy SMTP keys to allow seamless upgrades.
    $old = get_option('sb_options', []);
    $clean['enable_smtp'] = !empty($input['enable_smtp']) ? 1 : 0;
    $clean['mail_provider'] = bx_mail_provider($input);
    foreach (['force_from_email','force_from_name','mail_return_path','smtp_auth'] as $key) $clean[$key] = !empty($input[$key]) ? 1 : 0;
    $clean['smtp_host'] = sanitize_text_field($input['smtp_host'] ?? 'mail.smtp2go.com');
    $clean['smtp_port'] = max(1, min(65535, absint($input['smtp_port'] ?? 2525)));
    $clean['smtp_secure'] = in_array($input['smtp_secure'] ?? 'tls', ['', 'ssl','tls'], true) ? ($input['smtp_secure'] ?? 'tls') : 'tls';
    $clean['smtp_user'] = sanitize_text_field($input['smtp_user'] ?? '');
    $clean['smtp_from_email'] = sanitize_email($input['smtp_from_email'] ?? '');
    $clean['smtp_from_name'] = sanitize_text_field($input['smtp_from_name'] ?? '');
    foreach (['google_client_id','outlook_client_id'] as $key) $clean[$key] = sanitize_text_field($input[$key] ?? '');
    $tenant = trim($input['outlook_tenant'] ?? 'common');
    $clean['outlook_tenant'] = preg_match('/^[a-zA-Z0-9.-]+$/D', $tenant) ? $tenant : 'common';
    foreach (['smtp_pass','google_client_secret','outlook_client_secret','smtp2go_api_key'] as $key) {
        $value = is_string($input[$key] ?? null) ? $input[$key] : '';
        if (!empty($input['clear_' . $key])) { $clean[$key] = ''; continue; }
        try {
            $clean[$key] = $value !== '' ? bx_mail_secret($value) : ($old[$key] ?? '');
            if ($clean[$key] !== '' && strpos($clean[$key], 'bxenc:') !== 0) $clean[$key] = bx_mail_secret($clean[$key]);
        } catch (Throwable $e) {
            $clean[$key] = $old[$key] ?? '';
            add_settings_error('sb_options', 'bx_secret_' . $key, $e->getMessage());
        }
    }

    // Buttons Settings
    $clean['position']        = in_array($input['position'] ?? '', ['right', 'left', 'middle-right', 'middle-left', 'top-right', 'top-left'], true) ? $input['position'] : 'right';
    $clean['sticky_layout']   = in_array($input['sticky_layout'] ?? '', ['vertical', 'horizontal'], true) ? $input['sticky_layout'] : 'vertical';
    // Custom 404 page
    $clean['enable_404_page'] = !empty($input['enable_404_page']) ? 1 : 0;
    // GitHub repo used for plugin updates (owner/repo)
    $repo = trim((string) ($input['update_repo'] ?? ''));
    $repo = preg_replace('#^https?://github\.com/#i', '', rtrim($repo, '/'));
    $clean['update_repo'] = preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : '';
    $clean['p404_title']      = sanitize_text_field($input['p404_title'] ?? 'Page not found');
    $clean['p404_text']       = sanitize_textarea_field($input['p404_text'] ?? '');
    $clean['p404_button']     = sanitize_text_field($input['p404_button'] ?? 'Back to Home');
    $clean['p404_color']      = sanitize_hex_color($input['p404_color'] ?? '') ?: '#0074FD';
    $clean['p404_layout']     = ($input['p404_layout'] ?? '') === 'standalone' ? 'standalone' : 'theme';
    $clean['p404_search']     = !empty($input['p404_search']) ? 1 : 0;
    $clean['icon_size']       = absint($input['icon_size'] ?? 52);
    $clean['label_font_size'] = absint($input['label_font_size'] ?? 14);

    // Sticky launcher / collapsed mode
    $sd = bx_sticky_defaults();
    $pick = function ($k, $allowed) use ($input, $sd) {
        $v = $input[$k] ?? '';
        return in_array($v, $allowed, true) ? $v : $sd[$k];
    };
    $clean['sticky_mode']     = $pick('sticky_mode', ['stack', 'toggle']);
    $clean['collapse_on']     = array_values(array_intersect((array) ($input['collapse_on'] ?? []), ['d', 't', 'm']));
    $clean['launcher_shape']  = $pick('launcher_shape', array_keys(bx_launcher_shapes()));
    $clean['launcher_icon']   = $pick('launcher_icon', array_keys(bx_launcher_icons()));
    $clean['launcher_text']   = sanitize_text_field($input['launcher_text'] ?? '');
    $clean['launcher_size']   = min(120, absint($input['launcher_size'] ?? 0));
    $clean['launcher_bg']     = sanitize_hex_color($input['launcher_bg'] ?? '') ?: $sd['launcher_bg'];
    $clean['launcher_color']  = sanitize_hex_color($input['launcher_color'] ?? '') ?: $sd['launcher_color'];
    $clean['open_anim']       = $pick('open_anim', array_keys(bx_sticky_anims()));
    $clean['stagger']         = !empty($input['stagger']) ? 1 : 0;
    $clean['attention']       = $pick('attention', array_keys(bx_sticky_attention()));
    $clean['open_on']         = $pick('open_on', ['click', 'hover']);
    $clean['start_state']     = $pick('start_state', ['closed', 'open', 'auto']);
    $clean['auto_open_delay'] = max(1, min(120, absint($input['auto_open_delay'] ?? 5)));
    $clean['dim_bg']          = !empty($input['dim_bg']) ? 1 : 0;
    $clean['badge_text']      = substr(sanitize_text_field($input['badge_text'] ?? ''), 0, 4);
    $clean['greeting']        = substr(sanitize_text_field($input['greeting'] ?? ''), 0, 160);
    $clean['greeting_delay']  = max(1, min(120, absint($input['greeting_delay'] ?? 3)));
    $clean['item_style']      = $pick('item_style', ['detached', 'card']);
    $clean['card_bg']         = sanitize_hex_color($input['card_bg'] ?? '') ?: $sd['card_bg'];
    $clean['card_text']       = sanitize_hex_color($input['card_text'] ?? '') ?: $sd['card_text'];
    $clean['btn_shape']       = $pick('btn_shape', ['circle', 'rounded', 'square', 'hexagon']);
    $clean['offset_bottom']   = min(300, absint($input['offset_bottom'] ?? 25));
    $clean['offset_side']     = min(300, absint($input['offset_side'] ?? 20));

    if (!empty($input['buttons']) && is_array($input['buttons'])) {
        foreach ($input['buttons'] as $btn) {
            if (empty($btn['link'])) continue;
            $vis = isset($btn['vis']) && is_array($btn['vis']) ? array_intersect($btn['vis'], ['d', 't', 'm']) : ['d', 't', 'm'];
            $label_vis = isset($btn['label_vis']) && is_array($btn['label_vis']) ? array_intersect($btn['label_vis'], ['d', 'm']) : ['d', 'm'];

            $clean['buttons'][] = [
                'link'      => esc_url_raw($btn['link']),
                'icon'      => sanitize_key($btn['icon']),
                'label'     => sanitize_text_field($btn['label'] ?? ''),
                'icon_bg'   => sanitize_hex_color($btn['icon_bg']) ?: '#0074FD',
                'label_bg'  => sanitize_hex_color($btn['label_bg']) ?: '#18181b',
                'custom_icon' => esc_url_raw(trim($btn['custom_icon'] ?? '')),
                'vis'       => array_values($vis),
                'label_vis' => array_values($label_vis)
            ];
        }
    }
    return $clean;
}

/**
 * 15. Agency Admin Menu Registration
 */
add_action('admin_menu', function() {
    $o = get_option('sb_options', []);
    add_menu_page('Brandix PowerKit', 'Brandix Kit', 'manage_options', 'brandix-powerkit', 'sb_admin_dashboard_page', 'dashicons-shield');
    // Each page is registered only while its module toggle is on, so disabled modules disappear from the admin menu
    // (and their URLs return WordPress' normal "not allowed" screen).
    if (!empty($o['enable_email_logging'])) {
        add_submenu_page('brandix-powerkit', 'Email Logs', 'Email Logs (Global)', 'manage_options', 'brandix-email-logs', 'bx_admin_email_logs_page');
    }
    if (!empty($o['enable_activity_log']) || !empty($o['enable_audit_log'])) {
        add_submenu_page('brandix-powerkit', 'User Activity', 'User Login Activity', 'manage_options', 'brandix-activity-logs', 'bx_admin_activity_page');
    }
    if (!empty($o['enable_redirection'])) {
        add_submenu_page('brandix-powerkit', 'Page Redirects', 'Page Redirects', 'manage_options', 'brandix-redirections', 'bx_admin_redirections_page');
    }
});

/**
 * 16. Professional Dashboard UI Hub
 */
function sb_admin_dashboard_page() {
    $options = get_option('sb_options', []);
    $buttons = $options['buttons'] ?? [];
    $icons = sb_get_native_icons();
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_media();
    ?>
    <style>
        /* Brandix Kit admin: clean, minimal layout with a left tab menu */
        body, #wpwrap, #wpcontent { background: #f6f7f9; }
        .bx-wrap { max-width: 1180px; margin: 20px 20px 40px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #0f172a; }
        .bx-wrap * { box-sizing: border-box; }
        .bx-topbar { position: sticky; top: 32px; z-index: 50; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 20px; margin-bottom: 20px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .bx-brand { display: flex; align-items: center; gap: 12px; }
        .bx-logo { display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 10px; background: #0f172a; color: #ffffff; font-weight: 800; font-size: 18px; }
        .bx-brand strong { display: block; font-size: 16px; line-height: 1.2; }
        .bx-brand small { color: #64748b; font-size: 12px; }
        .bx-save { border: 0; border-radius: 10px; padding: 10px 22px; background: #0074FD; color: #ffffff; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .2s ease; }
        .bx-save:hover { background: #005fd1; }
        .bx-shell { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 20px; align-items: start; }
        .bx-nav { position: sticky; top: 112px; display: flex; flex-direction: column; gap: 2px; padding: 10px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; }
        .bx-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 9px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: background .15s ease, color .15s ease; }
        .bx-nav a .dashicons { font-size: 17px; width: 17px; height: 17px; color: #94a3b8; }
        .bx-nav a:hover { background: #f1f5f9; color: #0f172a; }
        .bx-nav a.is-active { background: #eff6ff; color: #0074FD; }
        .bx-nav a.is-active .dashicons { color: #0074FD; }
        .bx-nav a:focus { box-shadow: none; outline: 2px solid #bfdbfe; }

        .bx-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .bx-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid #f1f5f9; background: #ffffff; }
        .bx-header h2 { margin: 0; font-size: 16px; font-weight: 650; color: #0f172a; }
        .bx-badge { padding: 4px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .bx-body { padding: 8px 22px 22px; }
        .bx-grid-field { display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
        .bx-grid-field:last-child { border-bottom: none; }
        .bx-grid-field > div:first-child { flex: 1 1 auto; min-width: 0; }
        .bx-label-title { margin-bottom: 3px; font-size: 14px; font-weight: 600; color: #0f172a; }
        .bx-label-desc { font-size: 12.5px; line-height: 1.5; color: #64748b; }
        .bx-label-desc a { color: #0074FD; text-decoration: none; }

        .bx-switch { position: relative; display: inline-block; flex: 0 0 40px; width: 40px; min-width: 40px; height: 22px; }
        .bx-switch input { opacity: 0; width: 0; height: 0; }
        .bx-slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; border-radius: 22px; background: #cbd5e1; cursor: pointer; transition: .25s; }
        .bx-slider:before { position: absolute; content: ""; left: 3px; bottom: 3px; width: 16px; height: 16px; border-radius: 50%; background: #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,.2); transition: .25s; }
        input:checked + .bx-slider { background: #0074FD; }
        input:checked + .bx-slider:before { transform: translateX(18px); }
        .bx-input, .bx-wrap select.bx-input { min-height: 36px; padding: 7px 11px; border: 1px solid #d7dde5; border-radius: 9px; background: #ffffff; font-size: 13px; color: #0f172a; outline: none; box-shadow: none; }
        .bx-input:focus { border-color: #0074FD; box-shadow: 0 0 0 3px rgba(0,116,253,.12); }
        .bx-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 15px; }
        .bx-add-btn { margin-top: 4px; padding: 9px 16px; border: 1px dashed #cbd5e1; border-radius: 10px; background: #ffffff; color: #0f172a; font-weight: 600; cursor: pointer; }
        .bx-add-btn:hover { border-color: #0074FD; color: #0074FD; }
        .bx-color-wrap { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #475569; }
        .bx-color-input { width: 34px; height: 34px; padding: 0; border: 1px solid #d7dde5; border-radius: 9px; background: transparent; cursor: pointer; }

        /* Module tiles */
        .bx-group { margin-top: 18px; }
        .bx-group-title { margin: 0 0 10px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; }
        .bx-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .bx-tiles .bx-grid-field { align-items: flex-start; padding: 14px 16px; border: 1px solid #eef1f5; border-radius: 12px; background: #fbfcfd; }
        .bx-tiles .bx-grid-field:last-child { border-bottom: 1px solid #eef1f5; }

        /* Collapsible sections */
        .bx-section { margin-top: 14px; border: 1px solid #eef1f5; border-radius: 12px; background: #ffffff; }
        .bx-section > summary { display: flex; align-items: center; justify-content: flex-start; gap: 10px; padding: 14px 16px; cursor: pointer; list-style: none; font-size: 14px; font-weight: 600; }
        .bx-section > summary::-webkit-details-marker { display: none; }
        .bx-section > summary small { margin-left: 8px; font-weight: 500; color: #94a3b8; }
        .bx-section > summary:after { content: ""; margin-left: auto; width: 8px; height: 8px; border-right: 2px solid #94a3b8; border-bottom: 2px solid #94a3b8; transform: rotate(45deg); transition: transform .2s ease; }
        .bx-section[open] > summary:after { transform: rotate(-135deg); }
        .bx-section-body { padding: 0 16px 14px; border-top: 1px solid #f1f5f9; }
        .bx-notice-inline { margin: 14px 0 4px; padding: 10px 14px; border-radius: 10px; background: #fff7ed; color: #9a3412; font-size: 13px; }

        /* Sticky button rows */
        .sb-btn-box { margin: 12px 0; padding: 12px; border: 1px solid #eef1f5; border-radius: 12px; background: #fbfcfd; }
        .sb-btn-main { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .sb-drag { cursor: grab; color: #94a3b8; }
        .sb-ico-prev { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 36px; width: 36px; height: 36px; border-radius: 50%; color: #ffffff; }
        .sb-ico-prev svg, .sb-ico-prev img { width: 18px; height: 18px; }
        .sb-ico-prev img { object-fit: contain; }
        .sb-btn-main .sb-icon-pick { width: 170px; }
        .sb-btn-main .sb-label { width: 130px; }
        .sb-btn-main .sb-link { flex: 1 1 200px; min-width: 160px; }
        .sb-chip { position: relative; display: inline-flex; width: 34px; height: 34px; border: 1px solid #d7dde5; border-radius: 9px; overflow: hidden; cursor: pointer; }
        .sb-chip input { position: absolute; top: -6px; left: -6px; width: 46px; height: 46px; padding: 0; border: 0; cursor: pointer; }
        .sb-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: 1px solid #d7dde5; border-radius: 9px; background: #ffffff; color: #475569; cursor: pointer; }
        .sb-icon-btn:hover { border-color: #0074FD; color: #0074FD; }
        .sb-icon-btn.sb-remove:hover { border-color: #ef4444; color: #ef4444; }
        .sb-btn-more { display: grid; gap: 10px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; font-size: 12.5px; color: #475569; }
        .sb-btn-more[hidden] { display: none; }
        .sb-btn-more label { margin-right: 10px; }
        .sb-more-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .sb-more-row strong { min-width: 110px; color: #0f172a; }

        @media (max-width: 960px) {
            .bx-shell { grid-template-columns: 1fr; }
            .bx-nav { position: static; flex-direction: row; flex-wrap: wrap; }
            .bx-tiles { grid-template-columns: 1fr; }
            .bx-topbar { top: 46px; }
        }
    </style>

    <div class="bx-wrap">
        <form method="post" action="options.php">
            <?php settings_errors('sb_options'); settings_fields('sb_settings_group'); ?>

            <div class="bx-topbar">
                <div class="bx-brand"><span class="bx-logo">B</span><div><strong>Brandix Kit</strong><small>Version <?php echo esc_html(BX_POWERKIT_DB_VERSION); ?></small></div></div>
                <button type="submit" name="submit" class="bx-save">Save changes</button>
            </div>
            <div class="bx-shell">
                <nav class="bx-nav" aria-label="Brandix Kit sections">
                    <a href="#modules" data-tab="modules"><span class="dashicons dashicons-screenoptions"></span>Modules</a>
                    <a href="#sticky" data-tab="sticky"><span class="dashicons dashicons-format-chat"></span>Sticky Buttons</a>
                    <a href="#page404" data-tab="page404"><span class="dashicons dashicons-warning"></span>404 Page</a>
                    <a href="#replace" data-tab="replace"><span class="dashicons dashicons-randomize"></span>Search &amp; Replace</a>
                    <a href="#images" data-tab="images"><span class="dashicons dashicons-format-image"></span>Images</a>
                    <a href="#security" data-tab="security"><span class="dashicons dashicons-shield"></span>Security</a>
                    <a href="#logs" data-tab="logs"><span class="dashicons dashicons-list-view"></span>Logs &amp; Activity</a>
                    <a href="#email" data-tab="email"><span class="dashicons dashicons-email-alt"></span>Email Delivery</a>
                    <a href="#updates" data-tab="updates"><span class="dashicons dashicons-update"></span>Updates</a>
                </nav>
                <div class="bx-panels">

            <!-- 1. Master Feature Toggles -->
            <div class="bx-card" data-tab="modules">
                <div class="bx-header">
                    <h2>Modules</h2>
                    <span class="bx-badge">Turn features on or off</span>
                </div>
                <div class="bx-body">
                    <div class="bx-group"><div class="bx-group-title">Frontend</div><div class="bx-tiles">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Sticky Action Buttons (WhatsApp / Call / Custom)</div>
                            <div class="bx-label-desc">Display dynamic detached action capsules on frontend.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" id="bx-sticky-toggle" name="sb_options[enable_sticky_buttons]" value="1" <?php checked($options['enable_sticky_buttons'] ?? 1, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Mobile Bottom Menu</div>
                            <div class="bx-label-desc">App-style bottom bar on phones: centre circle + 2 links each side, ready-made designs, scroll show/hide. <?php if (!empty($options['enable_bottom_nav'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page=brandix-bottom-nav')); ?>">Menu settings →</a><?php else: ?><em>Turn on and save to open menu settings.</em><?php endif; ?></div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_bottom_nav]" value="1" <?php checked($options['enable_bottom_nav'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Cookie Consent Banner (UK GDPR / PECR)</div>
                            <div class="bx-label-desc">Banner with Accept / Reject / Manage, Google Consent Mode v2 and tracker blocking. <?php if (!empty($options['enable_cookie_consent'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page=brandix-cookie-consent')); ?>">Banner settings →</a><?php else: ?><em>Turn on and save to open banner settings.</em><?php endif; ?></div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_cookie_consent]" value="1" <?php checked($options['enable_cookie_consent'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">1-Click Under Construction Screen</div>
                            <div class="bx-label-desc">Locks frontend into a 503 maintenance page for all visitors.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[maintenance_mode]" value="1" <?php checked($options['maintenance_mode'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Smart Regional Phone Field Validation</div>
                            <div class="bx-label-desc">Elementor Pro form phone badge, flag, and digit length control (US/UK).</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[phone_validation]" value="1" <?php checked($options['phone_validation'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    </div></div>
                    <div class="bx-group"><div class="bx-group-title">SEO & URLs</div><div class="bx-tiles">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Page & Post Redirection System</div>
                            <div class="bx-label-desc">Enables 301 redirection triggers even for drafts and trashed pages.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_redirection]" value="1" <?php checked($options['enable_redirection'] ?? 1, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">URL Redirects (any old URL)</div>
                            <div class="bx-label-desc">Redirect URLs from old sites (e.g. <code>/services.html</code>), whole folders with <code>*</code>, or mark pages as 410 Gone. <?php if (!empty($options['enable_url_redirects'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page=brandix-url-redirects')); ?>">Manage rules →</a><?php endif; ?></div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_url_redirects]" value="1" <?php checked($options['enable_url_redirects'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Log 404 errors</div>
                            <div class="bx-label-desc">Records broken URLs visitors and Google hit, so you can redirect them in one click. Needs URL Redirects on.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[log_404]" value="1" <?php checked($options['log_404'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">SEO Trailing Slash "/" Stripper</div>
                            <div class="bx-label-desc">301 enforces clean URLs while safeguarding homepages & subfolders.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[remove_trailing_slash]" value="1" <?php checked($options['remove_trailing_slash'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Auto Image Alt Text & Title Generator</div>
                            <div class="bx-label-desc">Automatically cleans uploaded image filenames and sets them as SEO Alt text.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[auto_alt_tag]" value="1" <?php checked($options['auto_alt_tag'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    </div></div>
                    <div class="bx-group"><div class="bx-group-title">Productivity</div><div class="bx-tiles">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">1-Click Elementor & Post Duplicator</div>
                            <div class="bx-label-desc">Enables 'Duplicate' action row on all pages/posts.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_duplicator]" value="1" <?php checked($options['enable_duplicator'] ?? 1, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    </div></div>
                </div>
            </div>


            <!-- 404 Page -->
            <div class="bx-card" data-tab="page404">
                <div class="bx-header">
                    <h2>404 Page</h2>
                    <span class="bx-badge">Not found handling</span>
                </div>
                <div class="bx-body">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Redirect 404s to Homepage</div>
                            <div class="bx-label-desc">Any page that doesn't exist goes to the homepage (302), unless you've set a specific redirect for it. Missing images/files and bot probes still return a normal 404.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[redirect_404_home]" value="1" <?php checked($options['redirect_404_home'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Custom 404 Page</div>
                            <div class="bx-label-desc">Shows a clean "Page not found" page with a Back to Home button. Used when "Redirect 404s to Homepage" is off.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" id="bx-404-toggle" name="sb_options[enable_404_page]" value="1" <?php checked($options['enable_404_page'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div id="bx-404-settings" style="<?php echo empty($options['enable_404_page']) ? 'display:none;' : ''; ?>">
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Heading</div></div>
                            <input type="text" name="sb_options[p404_title]" class="bx-input" style="width:320px" value="<?php echo esc_attr($options['p404_title'] ?? 'Page not found'); ?>">
                        </div>
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Message</div></div>
                            <textarea name="sb_options[p404_text]" class="bx-input" rows="3" style="width:320px"><?php echo esc_textarea($options['p404_text'] ?? "Sorry, the page you're looking for doesn't exist or has been moved."); ?></textarea>
                        </div>
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Button text</div><div class="bx-label-desc">Links to the homepage.</div></div>
                            <input type="text" name="sb_options[p404_button]" class="bx-input" style="width:320px" value="<?php echo esc_attr($options['p404_button'] ?? 'Back to Home'); ?>">
                        </div>
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Accent colour</div><div class="bx-label-desc">Used for "404" and the button.</div></div>
                            <input type="color" name="sb_options[p404_color]" class="bx-color-input" value="<?php echo esc_attr($options['p404_color'] ?? '#0074FD'); ?>">
                        </div>
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Layout</div><div class="bx-label-desc">Block themes always use the standalone page.</div></div>
                            <select name="sb_options[p404_layout]" class="bx-input">
                                <option value="theme" <?php selected($options['p404_layout'] ?? 'theme', 'theme'); ?>>Inside the site header & footer</option>
                                <option value="standalone" <?php selected($options['p404_layout'] ?? 'theme', 'standalone'); ?>>Standalone clean page</option>
                            </select>
                        </div>
                        <div class="bx-grid-field">
                            <div><div class="bx-label-title">Show search box</div></div>
                            <label class="bx-switch"><input type="checkbox" name="sb_options[p404_search]" value="1" <?php checked($options['p404_search'] ?? 0, 1); ?>><span class="bx-slider"></span></label>
                        </div>
                        <p style="margin:14px 0 0"><a class="button" href="<?php echo esc_url(home_url('/?p=' . wp_rand(900000000, 999999999))); ?>" target="_blank" rel="noopener">Preview 404 page ↗</a> <span class="bx-label-desc">Save first to see changes.</span></p>
                    </div>
                </div>
            </div>

            <?php bx_img_settings_card($options); ?>

            <!-- 2. Global Search & Replace Engine (Multiple Dynamic Rules) -->
            <div class="bx-card" data-tab="replace">
                <div class="bx-header">
                    <h2>Global Search, Replace & Auto-Copyright</h2>
                    <span class="bx-badge">Dynamic Text Engine</span>
                </div>
                <div class="bx-body">
                    <div style="margin-bottom: 15px; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534;">
                        <strong>Tip for Copyright Year:</strong> Use this shortcode in your footer for the current year: <code>[bux_current_year]</code>
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Enable Multiple Global Search & Replace</div>
                            <div class="bx-label-desc">Instantly replace multiple words, phone numbers, or links across the entire frontend site.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" id="bx-replace-toggle" name="sb_options[enable_global_replace]" value="1" <?php checked($options['enable_global_replace'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div id="bx-replace-settings" style="<?php echo empty($options['enable_global_replace']) ? 'display:none;' : ''; ?> margin-top: 15px;">
                        <h4 style="margin: 15px 0 10px; color: var(--bx-dark);">Search & Replace Rules</h4>
                        <div id="bx-replace-rules-container">
                            <?php 
                            $rules = $options['replace_rules'] ?? [];
                            if (!empty($rules)) {
                                foreach ($rules as $i => $rule) {
                                    ?>
                                    <div class="sb-replace-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                                        <input type="text" name="sb_options[replace_rules][<?php echo (int) $i; ?>][search]" class="bx-input" style="flex:1;" value="<?php echo esc_attr($rule['search'] ?? ''); ?>" placeholder="Search Text / Link (e.g. 01202 080 151)">
                                        <input type="text" name="sb_options[replace_rules][<?php echo (int) $i; ?>][replace]" class="bx-input" style="flex:1;" value="<?php echo esc_attr($rule['replace'] ?? ''); ?>" placeholder="Replace With (e.g. 01202 080 152)">
                                        <button type="button" class="button remove-replace-btn" style="color:#ef4444;">&times;</button>
                                    </div>
                                    <?php
                                }
                            } else {
                                ?>
                                <div class="sb-replace-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                                    <input type="text" name="sb_options[replace_rules][0][search]" class="bx-input" style="flex:1;" placeholder="Search Text / Link">
                                    <input type="text" name="sb_options[replace_rules][0][replace]" class="bx-input" style="flex:1;" placeholder="Replace With">
                                    <button type="button" class="button remove-replace-btn" style="color:#ef4444;">&times;</button>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <button type="button" class="bx-add-btn" id="add-more-replace" style="margin-top: 5px;">+ Add Rule</button>
                    </div>
                </div>
            </div>

            <!-- 3. Sticky Action Buttons Configuration -->
            <div class="bx-card" data-tab="sticky" id="bx-sticky-builder-card">
                <div class="bx-header">
                    <h2>Sticky Contact Buttons</h2>
                    <span class="bx-badge">WhatsApp · Call · SMS · Custom</span>
                </div>
                <div class="bx-body">
                    <div class="bx-notice-inline" id="bx-sticky-off" style="<?php echo empty($options['enable_sticky_buttons']) ? '' : 'display:none;'; ?>">Sticky buttons are off. Turn them on in <a href="#modules" class="bx-go-tab" data-tab="modules">Modules</a> and save.</div>
                    <?php $bxs = bx_sticky_opts($options); ?>
                    <style>
                        .bx-sub-title{margin:28px 0 6px;font-size:15px;font-weight:700;color:#0f172a}
                        .bx-sub-desc{margin:0 0 12px;color:#64748b;font-size:13px}
                        .bx-shape-tiles{display:flex;flex-wrap:wrap;gap:10px}
                        .bx-shape-tile{display:flex;flex-direction:column;align-items:center;gap:8px;width:92px;padding:12px 6px;border:1px solid #cbd5e1;border-radius:10px;cursor:pointer;font-size:11px;font-weight:600;color:#334155;text-align:center;background:#fff}
                        .bx-shape-tile input{position:absolute;opacity:0;pointer-events:none}
                        .bx-shape-tile:has(input:checked){border-color:#0074FD;background:#eff6ff;box-shadow:0 0 0 1px #0074FD}
                        .bx-sp{display:block;width:34px;height:34px;background:#2f3a8f}
                        .bx-sp-circle{border-radius:50%}
                        .bx-sp-rounded{border-radius:10px}
                        .bx-sp-square{border-radius:3px}
                        .bx-sp-pill{width:58px;height:26px;margin:4px 0;border-radius:999px}
                        .bx-sp-bubble{border-radius:50% 50% 6px 50%}
                        .bx-sp-hexagon{clip-path:polygon(25% 6%,75% 6%,100% 50%,75% 94%,25% 94%,0 50%)}
                        .bx-sp-diamond{width:26px;height:26px;margin:4px;border-radius:6px;transform:rotate(45deg)}
                        .bx-sp-blob{border-radius:42% 58% 55% 45% / 48% 42% 58% 52%}
                        .bx-inline-fields{display:flex;flex-wrap:wrap;gap:14px;align-items:center}
                        .bx-inline-fields label{font-size:13px;color:#334155}
                    </style>

                    <details class="bx-section" open>
                        <summary>Buttons <small>Drag to reorder · gear icon for more options</small></summary>
                        <div class="bx-section-body">
                            <div id="sb-buttons-container">
                                <?php
                                if (!empty($buttons)) {
                                    foreach ($buttons as $i => $btn) { sb_render_row_html($i, $btn, $icons); }
                                } else {
                                    sb_render_row_html(0, ['link' => 'https://wa.me/1234567890', 'icon' => 'whatsapp', 'label' => 'WhatsApp', 'icon_bg' => '#25D366', 'label_bg' => '#111827', 'vis' => ['d', 't', 'm'], 'label_vis' => ['d']], $icons);
                                }
                                ?>
                            </div>
                            <button type="button" class="bx-add-btn" id="add-more-btns">+ Add button</button>
                            <script type="text/html" id="sb-row-template"><?php sb_render_row_html('__i__', ['icon' => 'phone', 'icon_bg' => '#0074FD', 'label_bg' => '#1e293b', 'vis' => ['d', 't', 'm'], 'label_vis' => ['d']], $icons); ?></script>
                        </div>
                    </details>

                    <details class="bx-section">
                        <summary>Position &amp; Size <small>Corner, direction, distance, button size</small></summary>
                        <div class="bx-section-body">
                            <div class="bx-grid-field">
                                <div>
                                    <div class="bx-label-title">Position</div>
                                    <div class="bx-label-desc">Where the buttons sit on the screen.</div>
                                </div>
                                <select name="sb_options[position]" class="bx-input">
                                    <?php foreach (['right' => 'Bottom Right', 'left' => 'Bottom Left', 'middle-right' => 'Middle Right', 'middle-left' => 'Middle Left', 'top-right' => 'Top Right', 'top-left' => 'Top Left'] as $pk => $pv): ?>
                                        <option value="<?php echo esc_attr($pk); ?>" <?php selected($options['position'] ?? 'right', $pk); ?>><?php echo esc_html($pv); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bx-grid-field">
                                <div>
                                    <div class="bx-label-title">Direction</div>
                                    <div class="bx-label-desc">Stack the buttons vertically, or in a horizontal row (labels then show on hover).</div>
                                </div>
                                <select name="sb_options[sticky_layout]" class="bx-input">
                                    <option value="vertical" <?php selected($bxs['sticky_layout'], 'vertical'); ?>>Vertical</option>
                                    <option value="horizontal" <?php selected($bxs['sticky_layout'], 'horizontal'); ?>>Horizontal</option>
                                </select>
                            </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Distance From Screen Edge</div>
                            <div class="bx-label-desc">Gap from the top or bottom edge and from the side, in pixels.</div>
                        </div>
                        <div class="bx-inline-fields">
                            <label>Top / Bottom <input type="number" name="sb_options[offset_bottom]" class="bx-input" value="<?php echo esc_attr($bxs['offset_bottom']); ?>" style="width:80px;" min="0" max="300"></label>
                            <label>Side <input type="number" name="sb_options[offset_side]" class="bx-input" value="<?php echo esc_attr($bxs['offset_side']); ?>" style="width:80px;" min="0" max="300"></label>
                        </div>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Button Size (Diameter)</div>
                            <div class="bx-label-desc">Circular icon button diameter in pixels (e.g. 52).</div>
                        </div>
                        <input type="number" name="sb_options[icon_size]" class="bx-input" value="<?php echo esc_attr($options['icon_size'] ?? 52); ?>" style="width: 90px;">
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Label Font Size</div>
                            <div class="bx-label-desc">Font size of detached text capsule in pixels (e.g. 14).</div>
                        </div>
                        <input type="number" name="sb_options[label_font_size]" class="bx-input" value="<?php echo esc_attr($options['label_font_size'] ?? 14); ?>" style="width: 90px;" min="10" max="24">
                    </div>
                        </div>
                    </details>

                    <details class="bx-section">
                        <summary>Style <small>Detached or card, icon shape, colours</small></summary>
                        <div class="bx-section-body">

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Button Style</div>
                            <div class="bx-label-desc">Detached = icon + separate label capsule. Card = icon and label inside one card.</div>
                        </div>
                        <select name="sb_options[item_style]" id="bx-item-style" class="bx-input">
                            <option value="detached" <?php selected($bxs['item_style'], 'detached'); ?>>Detached (icon + label capsule)</option>
                            <option value="card" <?php selected($bxs['item_style'], 'card'); ?>>Card (icon + label together)</option>
                        </select>
                    </div>

                    <div class="bx-grid-field" id="bx-card-colors" style="<?php echo $bxs['item_style'] === 'card' ? '' : 'display:none;'; ?>">
                        <div>
                            <div class="bx-label-title">Card Colours</div>
                            <div class="bx-label-desc">Icons use each button's "Icon BG" colour.</div>
                        </div>
                        <div class="bx-inline-fields">
                            <div class="bx-color-wrap"><span>Card BG:</span><input type="color" name="sb_options[card_bg]" value="<?php echo esc_attr($bxs['card_bg']); ?>" class="bx-color-input"></div>
                            <div class="bx-color-wrap"><span>Text:</span><input type="color" name="sb_options[card_text]" value="<?php echo esc_attr($bxs['card_text']); ?>" class="bx-color-input"></div>
                        </div>
                    </div>

                    <div class="bx-grid-field" id="bx-btn-shape-row" style="<?php echo $bxs['item_style'] === 'card' ? 'display:none;' : ''; ?>">
                        <div>
                            <div class="bx-label-title">Icon Button Shape</div>
                            <div class="bx-label-desc">Shape of each action icon.</div>
                        </div>
                        <select name="sb_options[btn_shape]" class="bx-input">
                            <?php foreach (['circle' => 'Circle', 'rounded' => 'Rounded Square', 'square' => 'Square', 'hexagon' => 'Hexagon'] as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($bxs['btn_shape'], $k); ?>><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                        </div>
                    </details>

                    <details class="bx-section">
                        <summary>Launcher <small>Hide buttons behind one shape</small></summary>
                        <div class="bx-section-body">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Display Mode</div>
                            <div class="bx-label-desc">Always visible, or collapsed behind a launcher.</div>
                        </div>
                        <select name="sb_options[sticky_mode]" id="bx-sticky-mode" class="bx-input">
                            <option value="stack" <?php selected($bxs['sticky_mode'], 'stack'); ?>>Always visible</option>
                            <option value="toggle" <?php selected($bxs['sticky_mode'], 'toggle'); ?>>Collapsed behind a launcher</option>
                        </select>
                    </div>
                    <div id="bx-launcher-settings" style="<?php echo $bxs['sticky_mode'] === 'toggle' ? '' : 'display:none;'; ?>">
                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Collapse On</div>
                                <div class="bx-label-desc">Devices where buttons hide behind the launcher. Unticked devices show all buttons as normal.</div>
                            </div>
                            <div class="bx-inline-fields">
                                <?php foreach (['d' => 'Desktop', 't' => 'Tablet', 'm' => 'Mobile'] as $k => $v): ?>
                                    <label><input type="checkbox" name="sb_options[collapse_on][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, (array) $bxs['collapse_on'], true)); ?>> <?php echo esc_html($v); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="padding:14px 0;border-bottom:1px solid #f1f5f9;">
                            <div class="bx-label-title" style="margin-bottom:10px;">Launcher Shape</div>
                            <div class="bx-shape-tiles">
                                <?php foreach (bx_launcher_shapes() as $k => $v): ?>
                                    <label class="bx-shape-tile">
                                        <input type="radio" name="sb_options[launcher_shape]" value="<?php echo esc_attr($k); ?>" <?php checked($bxs['launcher_shape'], $k); ?>>
                                        <span class="bx-sp bx-sp-<?php echo esc_attr($k); ?>"></span>
                                        <?php echo esc_html($v); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Launcher Icon</div>
                                <div class="bx-label-desc">Turns into a close (×) icon when open.</div>
                            </div>
                            <select name="sb_options[launcher_icon]" class="bx-input">
                                <?php foreach (bx_launcher_icons() as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($bxs['launcher_icon'], $k); ?>><?php echo esc_html($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Pill Text</div>
                                <div class="bx-label-desc">Used only with the "Pill" shape (e.g. Contact Us).</div>
                            </div>
                            <input type="text" name="sb_options[launcher_text]" class="bx-input" value="<?php echo esc_attr($bxs['launcher_text']); ?>" style="width:200px;">
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Launcher Size & Colours</div>
                                <div class="bx-label-desc">Size in pixels (0 = same as Button Size).</div>
                            </div>
                            <div class="bx-inline-fields">
                                <input type="number" name="sb_options[launcher_size]" class="bx-input" value="<?php echo esc_attr($bxs['launcher_size']); ?>" style="width:80px;" min="0" max="120">
                                <div class="bx-color-wrap"><span>BG:</span><input type="color" name="sb_options[launcher_bg]" value="<?php echo esc_attr($bxs['launcher_bg']); ?>" class="bx-color-input"></div>
                                <div class="bx-color-wrap"><span>Icon:</span><input type="color" name="sb_options[launcher_color]" value="<?php echo esc_attr($bxs['launcher_color']); ?>" class="bx-color-input"></div>
                            </div>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Open Animation</div>
                                <div class="bx-label-desc">How the buttons appear. "One by one" opens them in sequence.</div>
                            </div>
                            <div class="bx-inline-fields">
                                <select name="sb_options[open_anim]" class="bx-input">
                                    <?php foreach (bx_sticky_anims() as $k => $v): ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($bxs['open_anim'], $k); ?>><?php echo esc_html($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label><input type="checkbox" name="sb_options[stagger]" value="1" <?php checked($bxs['stagger'], 1); ?>> One by one</label>
                            </div>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Attention Effect</div>
                                <div class="bx-label-desc">Subtle animation on the launcher while closed.</div>
                            </div>
                            <select name="sb_options[attention]" class="bx-input">
                                <?php foreach (bx_sticky_attention() as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($bxs['attention'], $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Open On</div>
                                <div class="bx-label-desc">Hover works on desktop only; touch screens always use tap.</div>
                            </div>
                            <select name="sb_options[open_on]" class="bx-input">
                                <option value="click" <?php selected($bxs['open_on'], 'click'); ?>>Click / Tap</option>
                                <option value="hover" <?php selected($bxs['open_on'], 'hover'); ?>>Hover (desktop)</option>
                            </select>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Start State</div>
                                <div class="bx-label-desc">Auto open runs once per visit.</div>
                            </div>
                            <div class="bx-inline-fields">
                                <select name="sb_options[start_state]" id="bx-start-state" class="bx-input">
                                    <option value="closed" <?php selected($bxs['start_state'], 'closed'); ?>>Closed</option>
                                    <option value="open" <?php selected($bxs['start_state'], 'open'); ?>>Open</option>
                                    <option value="auto" <?php selected($bxs['start_state'], 'auto'); ?>>Auto open after delay</option>
                                </select>
                                <label id="bx-auto-delay-wrap" style="<?php echo $bxs['start_state'] === 'auto' ? '' : 'display:none;'; ?>">after <input type="number" name="sb_options[auto_open_delay]" class="bx-input" value="<?php echo esc_attr($bxs['auto_open_delay']); ?>" style="width:70px;" min="1" max="120"> sec</label>
                            </div>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Dim Page When Open</div>
                                <div class="bx-label-desc">Dark overlay behind the open buttons. Click it to close.</div>
                            </div>
                            <label class="bx-switch"><input type="checkbox" name="sb_options[dim_bg]" value="1" <?php checked($bxs['dim_bg'], 1); ?>><span class="bx-slider"></span></label>
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Notification Badge</div>
                                <div class="bx-label-desc">Small red badge on the launcher (e.g. 1). Hidden after the first open. Leave empty for none.</div>
                            </div>
                            <input type="text" name="sb_options[badge_text]" class="bx-input" value="<?php echo esc_attr($bxs['badge_text']); ?>" style="width:70px;" maxlength="4">
                        </div>

                        <div class="bx-grid-field">
                            <div>
                                <div class="bx-label-title">Greeting Message</div>
                                <div class="bx-label-desc">Small speech bubble next to the launcher (e.g. "Need a quote? Message us"). Leave empty for none.</div>
                            </div>
                            <div class="bx-inline-fields">
                                <input type="text" name="sb_options[greeting]" class="bx-input" value="<?php echo esc_attr($bxs['greeting']); ?>" style="width:260px;" maxlength="160">
                                <label>after <input type="number" name="sb_options[greeting_delay]" class="bx-input" value="<?php echo esc_attr($bxs['greeting_delay']); ?>" style="width:70px;" min="1" max="120"> sec</label>
                            </div>
                        </div>
                    </div>
                        </div>
                    </details>
                </div>
            </div>

            <!-- 4. Advanced Security & Malware Lockdown Hub -->
            <div class="bx-card" data-tab="security">
                <div class="bx-header">
                    <h2>Advanced Security Protection & Malware Shield</h2>
                    <span class="bx-badge">Agency Hardening</span>
                </div>
                <div class="bx-body">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Core Malware & File Lockdown</div>
                            <div class="bx-label-desc">Disables the theme/plugin file editor and all plugin/theme installs &amp; updates from wp-admin (`DISALLOW_FILE_MODS`). Note: this also stops security updates — turn it off temporarily to update. It hardens against casual tampering, not against an attacker who already has admin access.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[file_lockdown]" value="1" <?php checked($options['file_lockdown'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Block Uploads Folder PHP Execution</div>
                            <div class="bx-label-desc">Adds/removes a managed `.htaccess` rule in `/wp-content/uploads/` to block PHP execution on Apache/LiteSpeed.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[block_uploads_php]" value="1" <?php checked($options['block_uploads_php'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Block XML-RPC Protocol</div>
                            <div class="bx-label-desc">Blocks requests to the XML-RPC endpoint while this option is enabled.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[block_xmlrpc]" value="1" <?php checked($options['block_xmlrpc'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>

                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Unauthorized Admin Account Creation Alert</div>
                            <div class="bx-label-desc">Sends an emergency email notification (and records to Global Email Logs) if a new administrator is registered.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[admin_creation_alert]" value="1" <?php checked($options['admin_creation_alert'] ?? 0, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 5. Global Email Logging & User Tracking Toggles -->
            <div class="bx-card" data-tab="logs">
                <div class="bx-header">
                    <h2>Auditing: Global Email Logger & User Activity</h2>
                    <span class="bx-badge">Zero Lead Loss</span>
                </div>
                <div class="bx-body">
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Global WordPress Email Logger</div>
                            <div class="bx-label-desc">Logs recipient, subject, status and time for outgoing WordPress emails.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_email_logging]" value="1" <?php checked($options['enable_email_logging'] ?? 1, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div><div class="bx-label-title">Store Full Email Content</div><div class="bx-label-desc">Optional: store message body and headers. Keep off unless needed for debugging.</div></div>
                        <label class="bx-switch"><input type="checkbox" name="sb_options[store_email_content]" value="1" <?php checked($options['store_email_content'] ?? 0, 1); ?>><span class="bx-slider"></span></label>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">User Login Activity</div>
                            <div class="bx-label-desc">Tracks username, IP address and login timestamp.</div>
                        </div>
                        <label class="bx-switch">
                            <input type="checkbox" name="sb_options[enable_activity_log]" value="1" <?php checked($options['enable_activity_log'] ?? 1, 1); ?>>
                            <span class="bx-slider"></span>
                        </label>
                    </div>
                    <div class="bx-grid-field">
                        <div><div class="bx-label-title">Login Geolocation Lookup</div><div class="bx-label-desc">Optional: sends login IP to ipapi.co to resolve city/country.</div></div>
                        <label class="bx-switch"><input type="checkbox" name="sb_options[enable_geolocation]" value="1" <?php checked($options['enable_geolocation'] ?? 0, 1); ?>><span class="bx-slider"></span></label>
                    </div>
                    <div class="bx-grid-field">
                        <div><div class="bx-label-title">Content & Plugin Audit Log</div><div class="bx-label-desc">Tracks page/post changes and plugin install/update/activation events.</div></div>
                        <label class="bx-switch"><input type="checkbox" name="sb_options[enable_audit_log]" value="1" <?php checked($options['enable_audit_log'] ?? 1, 1); ?>><span class="bx-slider"></span></label>
                    </div>
                    <div class="bx-grid-field">
                        <div><div class="bx-label-title">Log Retention</div><div class="bx-label-desc">Automatically removes old email, login and audit records.</div></div>
                        <select name="sb_options[log_retention_days]" class="bx-input">
                            <?php foreach ([30,60,90] as $days): ?><option value="<?php echo esc_attr($days); ?>" <?php selected((int)($options['log_retention_days'] ?? 90), $days); ?>><?php echo esc_html($days); ?> days</option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 6. Provider-based Mailer -->
            <div class="bx-card" data-tab="email">
                <div class="bx-header"><h2>Email Delivery Settings</h2><span class="bx-badge">Google / Outlook / SMTP2GO / SMTP</span></div>
                <div class="bx-body">
                    <div class="bx-grid-field"><div><div class="bx-label-title">Enable Brandix Mailer</div><div class="bx-label-desc">Send WordPress emails through your selected provider.</div></div><label class="bx-switch"><input type="checkbox" id="bx-smtp-toggle" name="sb_options[enable_smtp]" value="1" <?php checked($options['enable_smtp'] ?? 0, 1); ?>><span class="bx-slider"></span></label></div>
                    <div id="bx-smtp-settings" style="<?php echo empty($options['enable_smtp']) ? 'display:none;' : ''; ?>">
                        <div class="bx-form-row">
                            <div><label class="bx-label-title" for="bx-from-email">From Email</label><input id="bx-from-email" type="email" name="sb_options[smtp_from_email]" class="bx-input" style="width:100%" value="<?php echo esc_attr($options['smtp_from_email'] ?? ''); ?>" placeholder="info@example.com"></div>
                            <div><label class="bx-label-title" for="bx-from-name">From Name</label><input id="bx-from-name" type="text" name="sb_options[smtp_from_name]" class="bx-input" style="width:100%" value="<?php echo esc_attr($options['smtp_from_name'] ?? get_bloginfo('name')); ?>"></div>
                        </div>
                        <p class="bx-label-desc">These sender settings are shared by every mailer. Use a sender address or alias authorized by your provider.</p>
                        <?php foreach (['force_from_email'=>'Force From Email', 'force_from_name'=>'Force From Name'] as $key=>$label): ?>
                            <div class="bx-grid-field"><div><div class="bx-label-title"><?php echo esc_html($label); ?></div><div class="bx-label-desc">Use this value even when another plugin supplies a different sender.</div></div><label class="bx-switch"><input type="checkbox" name="sb_options[<?php echo esc_attr($key); ?>]" value="1" <?php checked($options[$key] ?? 1, 1); ?>><span class="bx-slider"></span></label></div>
                        <?php endforeach; ?>
                        <fieldset style="margin-top:24px"><legend class="bx-label-title">Select Mailer</legend><div class="bx-mailer-choices">
                            <?php $selected_provider = bx_mail_provider($options); foreach (['google'=>'Google / Gmail / Workspace', 'outlook'=>'Outlook / Microsoft 365', 'smtp2go'=>'SMTP2GO', 'smtp'=>'Other SMTP'] as $key=>$label): ?>
                            <label class="bx-mailer-choice"><input type="radio" name="sb_options[mail_provider]" value="<?php echo esc_attr($key); ?>" <?php checked($selected_provider, $key); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </div></fieldset>
                        <?php foreach (['google'=>'Google / Gmail / Workspace API', 'outlook'=>'Outlook / Microsoft 365 API'] as $provider=>$title): ?>
                        <section class="bx-provider-panel" data-provider="<?php echo esc_attr($provider); ?>" <?php if ($selected_provider !== $provider) echo 'hidden'; ?>>
                            <h3><?php echo esc_html($title); ?></h3>
                            <p class="bx-label-desc"><?php echo $provider === 'google' ? 'Enable Gmail API in Google Cloud and create an OAuth Web application. Save its Client ID and Client Secret, then connect your Google account.' : 'Register a Web application in Microsoft Entra. Add delegated Microsoft Graph Mail.Send permission and create a client secret. Save the credentials, then connect your Microsoft account.'; ?></p>
                            <?php bx_mail_text_field($provider . '_client_id', 'Client ID', $options); bx_mail_password_field($provider . '_client_secret', 'Client Secret', $options); ?>
                            <?php if ($provider === 'outlook') bx_mail_text_field('outlook_tenant', 'Tenant ID / Tenant domain', $options, 'common'); ?>
                            <?php if ($provider === 'outlook'): ?><p class="bx-label-desc">Use common for supported personal and work accounts, or your tenant ID for a single-tenant app. The app registration must allow the chosen account type.</p><?php endif; ?>
                            <label class="bx-label-title">Authorized Redirect URI</label><input type="text" readonly class="bx-input" style="width:100%;margin:8px 0" value="<?php echo esc_attr(bx_mail_callback_uri()); ?>" onclick="this.select()">
                            <p class="bx-label-desc">Copy this exact HTTPS URI into your provider's Web redirect settings. Save changes before connecting.</p>
                            <?php $tokens = get_option('bx_mail_tokens_' . $provider, []); $connected = !empty($tokens['refresh']) && hash_equals(bx_mail_fingerprint($provider), $tokens['fingerprint'] ?? ''); ?>
                            <p><strong><?php echo $connected ? 'Connection saved' : 'Not connected'; ?></strong> <span class="bx-label-desc">(Send a test email to verify delivery acceptance.)</span></p>
                            <a class="button button-primary bx-connect-link" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bx_mail_connect&provider=' . $provider), 'bx_mail_connect')); ?>"><?php echo $connected ? 'Reconnect Account' : 'Connect Account'; ?></a>
                            <?php if (!empty($tokens)): ?><a class="button bx-connect-link" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bx_mail_disconnect&provider=' . $provider), 'bx_mail_disconnect')); ?>">Disconnect</a><?php endif; ?>
                            <p class="bx-label-desc">Disconnect removes this site's saved tokens. To revoke the app grant completely, remove its access in your Google/Microsoft account.</p>
                        </section>
                        <?php endforeach; ?>
                        <section class="bx-provider-panel" data-provider="smtp2go" <?php if ($selected_provider !== 'smtp2go') echo 'hidden'; ?>>
                            <h3>SMTP2GO API</h3><p class="bx-label-desc">Create an API key in SMTP2GO → Sending → API Keys. Verify your sender domain or sender address in SMTP2GO.</p>
                            <?php bx_mail_password_field('smtp2go_api_key', 'API Key', $options); ?>
                        </section>
                        <section class="bx-provider-panel" data-provider="smtp" <?php if ($selected_provider !== 'smtp') echo 'hidden'; ?>>
                            <h3>Other SMTP</h3>
                            <?php bx_mail_text_field('smtp_host', 'SMTP Host', $options, 'mail.smtp2go.com'); ?>
                            <div class="bx-form-row"><div><label class="bx-label-title" for="bx-smtp-secure">Encryption</label><select id="bx-smtp-secure" name="sb_options[smtp_secure]" class="bx-input" style="width:100%">
                                <?php foreach ([''=>'None', 'ssl'=>'SSL', 'tls'=>'TLS / STARTTLS'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($options['smtp_secure'] ?? 'tls', $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                            </select></div><div><label class="bx-label-title" for="bx-smtp-port">SMTP Port</label><input id="bx-smtp-port" type="number" min="1" max="65535" name="sb_options[smtp_port]" class="bx-input" style="width:100%" value="<?php echo esc_attr($options['smtp_port'] ?? 2525); ?>"></div></div>
                            <div class="bx-grid-field"><div class="bx-label-title">Authentication</div><label class="bx-switch"><input type="checkbox" id="bx-smtp-auth" name="sb_options[smtp_auth]" value="1" <?php checked($options['smtp_auth'] ?? 1, 1); ?>><span class="bx-slider"></span></label></div>
                            <div id="bx-smtp-credentials" <?php if (isset($options['smtp_auth']) && empty($options['smtp_auth'])) echo 'hidden'; ?>><?php bx_mail_text_field('smtp_user', 'SMTP Username', $options); bx_mail_password_field('smtp_pass', 'SMTP Password', $options); ?></div>
                            <div class="bx-grid-field"><div><div class="bx-label-title">Return Path</div><div class="bx-label-desc">Set the SMTP envelope sender to From Email for bounce messages.</div></div><label class="bx-switch"><input type="checkbox" name="sb_options[mail_return_path]" value="1" <?php checked($options['mail_return_path'] ?? 0, 1); ?>><span class="bx-slider"></span></label></div>
                        </section>
                        <p class="bx-label-desc">Google, Microsoft and SMTP2GO manage their own Return Path. API acceptance does not guarantee inbox delivery.</p>
                        <button type="button" class="button" id="bx-test-mail-btn">Send Test Email</button><span id="bx-mail-test-status" role="status" style="margin-left:10px"></span>
                        <p class="bx-label-desc">Save All Settings before connecting an account or sending a test. Existing SMTP settings continue under Other SMTP.</p>
                    </div>
                </div>
            </div>
            <style>.bx-mailer-choices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}.bx-mailer-choice{padding:16px;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer}.bx-mailer-choice:has(input:checked){border-color:#0074FD;background:#eff6ff}.bx-provider-panel{margin-top:20px;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}.bx-provider-panel[hidden],#bx-smtp-credentials[hidden]{display:none!important}@media(max-width:600px){.bx-mailer-choices,.bx-form-row{grid-template-columns:1fr}}</style>


            <!-- Updates from GitHub -->
            <div class="bx-card" data-tab="updates">
                <div class="bx-header">
                    <h2>Plugin Updates</h2>
                    <span class="bx-badge">From GitHub</span>
                </div>
                <div class="bx-body">
                    <?php $bx_last = get_option('bx_upd_last_check', []); $bx_const = defined('BX_POWERKIT_REPO'); ?>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">GitHub repository</div>
                            <div class="bx-label-desc">Default: <code>Asfund7788/brandix-kit</code>. Leave empty to use it, or enter another <code>owner/repo</code>. New releases there show up as normal plugin updates on this site.<?php if ($bx_const): ?> <strong>Set in wp-config.php (BX_POWERKIT_REPO).</strong><?php endif; ?></div>
                        </div>
                        <input type="text" name="sb_options[update_repo]" class="bx-input" style="width:280px" placeholder="Asfund7788/brandix-kit" value="<?php echo esc_attr($bx_const ? BX_POWERKIT_REPO : ($options['update_repo'] ?? '')); ?>" <?php disabled($bx_const); ?>>
                    </div>
                    <div class="bx-grid-field">
                        <div>
                            <div class="bx-label-title">Status</div>
                            <div class="bx-label-desc">
                                Installed: <strong><?php echo esc_html(BX_POWERKIT_DB_VERSION); ?></strong>
                                <?php if (!empty($bx_last['time'])): ?>
                                    · Latest on GitHub: <strong><?php echo esc_html($bx_last['version'] ?: '—'); ?></strong>
                                    · Checked <?php echo esc_html(human_time_diff($bx_last['time']) . ' ago'); ?>
                                    <?php if (!empty($bx_last['error'])): ?><br><span style="color:#b91c1c"><?php echo esc_html($bx_last['error']); ?></span><?php endif; ?>
                                <?php else: ?>
                                    · Not checked yet.
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (bx_upd_repo()): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bx_upd_check'), 'bx_upd_check')); ?>">Check now</a><?php endif; ?>
                    </div>
                </div>
            </div>
                <p><button type="submit" name="submit" class="bx-save">Save changes</button></p>
                </div>
            </div>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#bx-smtp-toggle').change(function() { $('#bx-smtp-settings').toggle(this.checked); });
        $('#bx-replace-toggle').change(function() { $('#bx-replace-settings').slideToggle(this.checked); });
        $('#bx-sticky-toggle').change(function() { $('#bx-sticky-off').toggle(!this.checked); });
        $('#bx-sticky-mode').on('change', function() { $('#bx-launcher-settings').toggle(this.value === 'toggle'); });
        $('#bx-start-state').on('change', function() { $('#bx-auto-delay-wrap').toggle(this.value === 'auto'); });
        $('#bx-item-style').on('change', function() { $('#bx-card-colors').toggle(this.value === 'card'); $('#bx-btn-shape-row').toggle(this.value !== 'card'); });

        var bxMailDirty = false;
        $('#bx-smtp-settings :input, #bx-smtp-toggle').on('change input', function() { bxMailDirty = true; });
        $('input[name="sb_options[mail_provider]"]').on('change', function() {
            var provider = this.value;
            $('.bx-provider-panel').each(function() { this.hidden = this.dataset.provider !== provider; });
        });
        $('#bx-smtp-auth').on('change', function() { $('#bx-smtp-credentials').prop('hidden', !this.checked); });
        $('.bx-connect-link').on('click', function(e) {
            if (bxMailDirty) { e.preventDefault(); alert('Save All Settings before connecting or disconnecting.'); }
        });
        $('#bx-test-mail-btn').click(function() {
            if (bxMailDirty) { alert('Save All Settings before sending a test.'); return; }
            var email = prompt('Enter recipient for test email:', <?php echo wp_json_encode(get_option('admin_email'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
            if (!email) return;
            var $btn = $(this), $status = $('#bx-mail-test-status');
            $btn.text('Sending...').prop('disabled', true); $status.text('');
            $.post(ajaxurl, {action:'bx_send_smtp_test_mail', test_email:email, nonce:'<?php echo esc_js(wp_create_nonce("bx_test_mail_nonce")); ?>'})
            .done(function(res) { $status.text(res.success ? 'Accepted by mail provider. Check the recipient inbox.' : 'Failed: ' + (res.data || 'Unknown error')); })
            .fail(function() { $status.text('Request failed. Check the provider log before retrying.'); })
            .always(function() { $btn.text('Send Test Email').prop('disabled', false); });
        });

        // Dynamic Multiple Search & Replace Repeater JS
        $('#add-more-replace').click(function() {
            var i = 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2,7);
            var html = `<div class="sb-replace-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                <input type="text" name="sb_options[replace_rules][${i}][search]" class="bx-input" style="flex:1;" placeholder="Search Text / Link">
                <input type="text" name="sb_options[replace_rules][${i}][replace]" class="bx-input" style="flex:1;" placeholder="Replace With">
                <button type="button" class="button remove-replace-btn" style="color:#ef4444;">&times;</button>
            </div>`;
            $('#bx-replace-rules-container').append(html);
        });
        $(document).on('click', '.remove-replace-btn', function() {
            $(this).closest('.sb-replace-row').remove();
        });

        // Sticky buttons: reorder, add, remove, more options, live icon preview, custom icon upload
        var bxIcons = <?php $bx_icon_svgs = []; foreach ($icons as $k => $v) { $bx_icon_svgs[$k] = $v['svg']; } echo wp_json_encode($bx_icon_svgs); ?>;
        $("#sb-buttons-container").sortable({ axis: "y", handle: ".sb-drag" });
        $('#add-more-btns').on('click', function() {
            var i = 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
            $('#sb-buttons-container').append($('#sb-row-template').html().split('__i__').join(i));
        });
        $(document).on('click', '.remove-btn', function() { $(this).closest('.sb-btn-box').remove(); });
        $(document).on('click', '.sb-more-toggle', function() {
            var $more = $(this).closest('.sb-btn-box').find('.sb-btn-more');
            $more.prop('hidden', !$more.prop('hidden'));
            $(this).attr('aria-expanded', $more.prop('hidden') ? 'false' : 'true');
        });
        function bxRowPreview($box) {
            var custom = $.trim($box.find('.sb-custom-icon').val());
            var $p = $box.find('.sb-ico-prev').css('background', $box.find('.sb-icon-bg').val());
            if (custom) { $p.empty().append($('<img alt="">').attr('src', custom)); } else { $p.html(bxIcons[$box.find('.sb-icon-pick').val()] || ''); }
        }
        $(document).on('change input', '.sb-icon-pick, .sb-icon-bg, .sb-custom-icon', function() { bxRowPreview($(this).closest('.sb-btn-box')); });
        $(document).on('click', '.sb-upload-icon', function(e) {
            e.preventDefault();
            var $box = $(this).closest('.sb-btn-box');
            var frame = wp.media({ title: 'Choose icon (SVG or PNG)', button: { text: 'Use this icon' }, multiple: false });
            frame.on('select', function() { $box.find('.sb-custom-icon').val(frame.state().get('selection').first().toJSON().url); bxRowPreview($box); });
            frame.open();
        });

        // 404 page settings
        $('#bx-404-toggle').on('change', function() { $('#bx-404-settings').toggle(this.checked); });

        // Tabs
        function bxShowTab(tab) {
            if (!$('.bx-card[data-tab="' + tab + '"]').length) tab = 'modules';
            $('.bx-card[data-tab]').hide().filter('[data-tab="' + tab + '"]').show();
            $('.bx-nav a').removeClass('is-active').filter('[data-tab="' + tab + '"]').addClass('is-active');
            try { localStorage.setItem('bxKitTab', tab); } catch (e) {}
            if (history.replaceState) history.replaceState(null, '', '#' + tab);
        }
        $('.bx-nav a, .bx-go-tab').on('click', function(e) { e.preventDefault(); bxShowTab($(this).data('tab')); window.scrollTo(0, 0); });
        var bxStart = (location.hash || '').replace('#', '');
        if (!bxStart) { try { bxStart = localStorage.getItem('bxKitTab') || ''; } catch (e) {} }
        bxShowTab(bxStart || 'modules');
    });
    </script>
    <?php
}

function sb_render_row_html($i, $data, $icons) {
    $vis       = (array) ($data['vis'] ?? ['d', 't', 'm']);
    $label_vis = (array) ($data['label_vis'] ?? ['d']);
    $icon_bg   = $data['icon_bg'] ?? ($data['col'] ?? '#0074FD');
    $label_bg  = $data['label_bg'] ?? '#1e293b';
    $icon      = $data['icon'] ?? 'phone';
    $custom    = $data['custom_icon'] ?? '';
    $n         = 'sb_options[buttons][' . $i . ']';
    $preview   = $custom ? '<img src="' . esc_url($custom) . '" alt="">' : ($icons[$icon]['svg'] ?? $icons['phone']['svg']);
    ?>
    <div class="sb-btn-box">
        <div class="sb-btn-main">
            <span class="dashicons dashicons-menu sb-drag" title="Drag to reorder"></span>
            <span class="sb-ico-prev" style="background:<?php echo esc_attr($icon_bg); ?>"><?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from the plugin icon list or an esc_url() image. ?></span>
            <select name="<?php echo esc_attr($n); ?>[icon]" class="bx-input sb-icon-pick" aria-label="Icon">
                <?php foreach ($icons as $key => $ic): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($icon, $key); ?>><?php echo esc_html($ic['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="<?php echo esc_attr($n); ?>[label]" class="bx-input sb-label" placeholder="Label" value="<?php echo esc_attr($data['label'] ?? ''); ?>">
            <input type="text" name="<?php echo esc_attr($n); ?>[link]" class="bx-input sb-link" placeholder="tel:, sms:, mailto:, https://wa.me/…" value="<?php echo esc_attr($data['link'] ?? ''); ?>">
            <label class="sb-chip" title="Icon background"><input type="color" name="<?php echo esc_attr($n); ?>[icon_bg]" value="<?php echo esc_attr($icon_bg); ?>" class="sb-icon-bg"></label>
            <label class="sb-chip" title="Label background"><input type="color" name="<?php echo esc_attr($n); ?>[label_bg]" value="<?php echo esc_attr($label_bg); ?>"></label>
            <button type="button" class="sb-icon-btn sb-more-toggle" title="More options" aria-expanded="false"><span class="dashicons dashicons-admin-generic"></span></button>
            <button type="button" class="sb-icon-btn sb-remove remove-btn" title="Remove"><span class="dashicons dashicons-trash"></span></button>
        </div>
        <div class="sb-btn-more" hidden>
            <div class="sb-more-row">
                <strong>Show button on</strong>
                <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[vis][]" value="d" <?php checked(in_array('d', $vis)); ?>> Desktop</label>
                <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[vis][]" value="t" <?php checked(in_array('t', $vis)); ?>> Tablet</label>
                <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[vis][]" value="m" <?php checked(in_array('m', $vis)); ?>> Mobile</label>
            </div>
            <div class="sb-more-row">
                <strong>Show label on</strong>
                <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[label_vis][]" value="d" <?php checked(in_array('d', $label_vis)); ?>> Desktop</label>
                <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[label_vis][]" value="m" <?php checked(in_array('m', $label_vis)); ?>> Mobile</label>
            </div>
            <div class="sb-more-row">
                <strong>Custom icon</strong>
                <input type="text" name="<?php echo esc_attr($n); ?>[custom_icon]" class="bx-input sb-custom-icon" style="flex:1;min-width:200px" placeholder="SVG or PNG URL (replaces the icon above)" value="<?php echo esc_attr($custom); ?>">
                <button type="button" class="button sb-upload-icon">Upload</button>
            </div>
        </div>
    </div>
    <?php
}

/**
 * 17. Secure AJAX Test Email Handler
 */
add_action('wp_ajax_bx_send_smtp_test_mail', function() {
    check_ajax_referer('bx_test_mail_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Access denied');

    $target = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
    if (!is_email($target)) wp_send_json_error('Invalid email format');

    $o = get_option('sb_options', []);
    if (empty($o['enable_smtp'])) wp_send_json_error('Enable and save the Brandix Mailer first.');
    $subject = 'Brandix PowerKit: Mailer Test';
    $message = "This is a test email from " . home_url() . " using " . bx_mail_provider($o) . ".\n\nBrandix Soft";
    $GLOBALS['bx_mail_last_error'] = '';
    if (wp_mail($target, $subject, $message)) wp_send_json_success();
    wp_send_json_error($GLOBALS['bx_mail_last_error'] ?: 'Mail was not accepted. Check saved settings and provider connection.');
});

/**
 * 17.1 Admin destructive actions (clear logs / remove redirect).
 * Runs on admin_init so redirects happen before any admin HTML is sent.
 */
add_action('admin_init', 'bx_powerkit_handle_admin_actions');
function bx_powerkit_handle_admin_actions() {
    $page = sanitize_key($_GET['page'] ?? '');
    if (!in_array($page, ['brandix-email-logs', 'brandix-activity-logs', 'brandix-redirections'], true)) return;
    global $wpdb;

    if ($page === 'brandix-email-logs' && isset($_GET['bx_clear_logs'])) {
        check_admin_referer('bx_clear_email_logs');
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bx_email_logs");
        wp_safe_redirect(admin_url('admin.php?page=brandix-email-logs'));
        exit;
    }

    if ($page === 'brandix-activity-logs' && isset($_GET['bx_clear_audit'])) {
        check_admin_referer('bx_clear_audit_logs');
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bx_audit_activity");
        wp_safe_redirect(admin_url('admin.php?page=brandix-activity-logs'));
        exit;
    }

    if ($page === 'brandix-redirections' && isset($_GET['bx_remove_redirection'])) {
        $post_id = absint($_GET['bx_remove_redirection']);
        check_admin_referer('bx_remove_redir_' . $post_id);
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        delete_post_meta($post_id, '_bx_redirect_url');
        delete_post_meta($post_id, '_bx_redirect_hits');
        wp_safe_redirect(admin_url('admin.php?page=brandix-redirections'));
        exit;
    }
}

/**
 * 17.2 Delete single / selected / all records from any Brandix log table.
 */
function bx_log_tables() {
    global $wpdb;
    return [
        'email'   => ['table' => $wpdb->prefix . 'bx_email_logs',     'page' => 'brandix-email-logs'],
        'login'   => ['table' => $wpdb->prefix . 'bx_user_activity',  'page' => 'brandix-activity-logs'],
        'audit'   => ['table' => $wpdb->prefix . 'bx_audit_activity', 'page' => 'brandix-activity-logs'],
        'consent' => ['table' => $wpdb->prefix . 'bx_consent_log',    'page' => 'brandix-cookie-consent'],
    ];
}

add_action('admin_post_bx_log_delete', function() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    $log = sanitize_key($_REQUEST['log'] ?? '');
    $map = bx_log_tables();
    if (!isset($map[$log])) wp_die('Unknown log.');
    check_admin_referer('bx_log_delete_' . $log);
    global $wpdb;
    $table = $map[$log]['table'];

    if (!empty($_REQUEST['clear_all'])) {
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", $table));
        $result = 'all';
    } else {
        $ids = array_values(array_filter(array_map('absint', (array) ($_REQUEST['ids'] ?? []))));
        $result = 0;
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a list of %d.
            $result = (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ($placeholders)", array_merge([$table], $ids)));
        }
    }
    wp_safe_redirect(add_query_arg('bx_deleted', $result, admin_url('admin.php?page=' . $map[$log]['page'])));
    exit;
});

/** Single-row delete link. */
function bx_log_delete_url($log, $id) {
    return wp_nonce_url(admin_url('admin-post.php?action=bx_log_delete&log=' . rawurlencode($log) . '&ids%5B%5D=' . absint($id)), 'bx_log_delete_' . $log);
}

/** "Clear all" link for a log. */
function bx_log_clear_url($log) {
    return wp_nonce_url(admin_url('admin-post.php?action=bx_log_delete&log=' . rawurlencode($log) . '&clear_all=1'), 'bx_log_delete_' . $log);
}

/** Opening <form> + hidden fields for bulk delete. */
function bx_log_bulk_form_open($log) {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bx-bulk-form">';
    echo '<input type="hidden" name="action" value="bx_log_delete"><input type="hidden" name="log" value="' . esc_attr($log) . '">';
    wp_nonce_field('bx_log_delete_' . $log);
}

/** "Delete selected" button. */
function bx_log_bulk_button() {
    echo '<button type="submit" class="button bx-bulk-delete" style="color:#b91c1c;border-color:#fca5a5;">Delete Selected</button>';
}

/** Success notice after a delete. */
function bx_log_deleted_notice() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag.
    if (!isset($_GET['bx_deleted'])) return;
    $v = sanitize_text_field(wp_unslash($_GET['bx_deleted'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag.
    $msg = $v === 'all' ? 'All records cleared.' : sprintf('%d record(s) deleted.', absint($v));
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}

/** Select-all checkbox + confirm for bulk delete (printed once per page). */
function bx_log_bulk_script() {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <script>
    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('bx-check-all')) return;
        e.target.closest('table').querySelectorAll('input[name="ids[]"]').forEach(function(c) { c.checked = e.target.checked; });
    });
    document.addEventListener('submit', function(e) {
        if (!e.target.classList.contains('bx-bulk-form')) return;
        var n = e.target.querySelectorAll('input[name="ids[]"]:checked').length;
        if (!n) { e.preventDefault(); alert('Select at least one record first.'); return; }
        if (!confirm('Delete ' + n + ' selected record(s)? This cannot be undone.')) e.preventDefault();
    });
    </script>
    <?php
}

/**
 * 18. Admin Page: Global WordPress Email Logs
 */
function bx_admin_email_logs_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bx_email_logs';
    // Clear action is handled in admin_init (bx_powerkit_handle_admin_actions) before any output.

    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY sent_at DESC LIMIT 100", $table), ARRAY_A);
    ?>
    <div class="wrap">
        <div style="display:flex; justify-content:space-between; align-items:center; margin:15px 0;">
            <h1 style="font-size:22px; font-weight:700; margin:0;">Global Outgoing Email Logs</h1>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=brandix-email-logs&bx_clear_logs=1'), 'bx_clear_email_logs')); ?>" class="button" onclick="return confirm('Clear all logs?');">Clear All Logs</a>
        </div>
        <?php bx_log_deleted_notice(); ?>
        <p style="color:#64748b;">Captures all outgoing emails across Elementor, WooCommerce, WPForms, Contact Form 7, and Core WordPress.</p>
        <?php bx_log_bulk_form_open('email'); ?>
        <p><?php bx_log_bulk_button(); ?></p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" class="bx-check-all"></th>
                    <th style="width:60px;">ID</th>
                    <th style="width:220px;">Recipient (To)</th>
                    <th>Subject</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:160px;">Sent At</th>
                    <th style="width:180px; text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): foreach ($logs as $row): 
                    $raw_message = maybe_unserialize($row['message']);
                    if ($raw_message === '' || $raw_message === null || $raw_message === false) {
                        // Body was not stored (Store Full Email Content was off when this email was sent).
                        $formatted_message = '<em style="color:#64748b;">Message content was not stored for this email. Turn on <strong>Store Full Email Content</strong> in Brandix Kit &rarr; Auditing to capture message bodies for future emails.</em>';
                    } elseif (is_array($raw_message)) {
                        $formatted_message = '<pre>' . esc_html(print_r($raw_message, true)) . '</pre>'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- shows a stored array message in the admin log viewer.
                    } elseif (preg_match('/<[a-z][^>]*>/i', (string) $raw_message)) {
                        // HTML email: keep safe markup.
                        $formatted_message = wp_kses_post($raw_message);
                    } else {
                        // Plain-text email: escape and keep line breaks.
                        $formatted_message = nl2br(esc_html($raw_message));
                    }
                ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo esc_attr($row['id']); ?>"></td>
                        <td>#<?php echo esc_html($row['id']); ?></td>
                        <td><strong><?php echo esc_html($row['to_email']); ?></strong></td>
                        <td><?php echo esc_html($row['subject']); ?></td>
                        <td><span style="color:<?php echo $row['status'] === 'Failed' ? '#dc2626' : '#15803d'; ?>; font-weight:700;"><?php echo esc_html($row['status']); ?></span></td>
                        <td><?php echo esc_html($row['sent_at']); ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="button button-small bx-view-mail-btn" data-message="<?php echo esc_attr($formatted_message); ?>" data-subject="<?php echo esc_attr($row['subject']); ?>">View Message</button>
                            <a href="<?php echo esc_url(bx_log_delete_url('email', $row['id'])); ?>" class="button button-small" style="color:#b91c1c;" onclick="return confirm('Delete this email record?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px;">No outgoing email records logged yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </form>
        <?php bx_log_bulk_script(); ?>
    </div>

    <!-- Modal Popup for Email Content -->
    <div id="bx-mail-modal" style="display:none; position:fixed; z-index:100000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:#fff; width:650px; max-width:90%; padding:24px; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
            <h3 id="bx-modal-subject" style="margin-top:0; font-size:18px; border-bottom:1px solid #eee; padding-bottom:10px;"></h3>
            <div id="bx-modal-body" style="background:#f8fafc; padding:15px; border:1px solid #e2e8f0; border-radius:6px; max-height:350px; overflow-y:auto; font-size:13px; line-height:1.6; margin-bottom:20px; color:#1e293b;"></div>
            <div style="text-align:right;">
                <button type="button" class="button button-primary" id="bx-close-modal">Close</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#bx-mail-modal').hide();
        $('.bx-view-mail-btn').click(function() {
            var subject = $(this).data('subject');
            var message = $(this).attr('data-message');
            $('#bx-modal-subject').text('Subject: ' + subject);
            $('#bx-modal-body').html(message);
            $('#bx-mail-modal').css('display', 'flex');
        });
        $('#bx-close-modal, #bx-mail-modal').click(function(e) {
            if (e.target.id === 'bx-mail-modal' || e.target.id === 'bx-close-modal') {
                $('#bx-mail-modal').hide();
            }
        });
    });
    </script>
    <?php
}

/**
 * 19. Admin Page: User Login Activity Tracker
 */
function bx_admin_activity_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    global $wpdb;
    $o = get_option('sb_options', []);
    $show_audit = !empty($o['enable_audit_log']);
    $show_login = !empty($o['enable_activity_log']);
    $login_table = $wpdb->prefix . 'bx_user_activity';
    $audit_table = $wpdb->prefix . 'bx_audit_activity';

    $records = $show_login ? $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY login_time DESC LIMIT 100", $login_table), ARRAY_A) : [];
    $audit_records = $show_audit ? $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY action_time DESC LIMIT 300", $audit_table), ARRAY_A) : [];
    ?>
    <div class="wrap">
        <h1 style="font-size:22px;font-weight:700;margin:15px 0 5px;">User Activity & Audit Log</h1>
        <p style="color:#64748b;">Tracks user logins, IP/location, content changes, and plugin activity.</p>
        <?php bx_log_deleted_notice(); ?>

        <?php if ($show_audit): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:20px 0;">
            <h2 style="margin:0 0 6px;font-size:18px;">Content & Plugin Activity</h2>
            <p style="color:#64748b;margin-top:0;">Shows who created, updated, deleted, published, trashed, or restored content and who installed, updated, activated, deactivated, or deleted plugins.</p>
            <?php bx_log_bulk_form_open('audit'); ?>
            <p style="display:flex;gap:8px;">
                <?php bx_log_bulk_button(); ?>
                <a class="button button-secondary" href="<?php echo esc_url(bx_log_clear_url('audit')); ?>" onclick="return confirm('Clear all content and plugin audit logs?');">Clear All Audit Logs</a>
            </p>
            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" class="bx-check-all"></th>
                        <th style="width:55px;">#</th>
                        <th style="width:130px;">User</th>
                        <th style="width:150px;">Action</th>
                        <th>Item / Plugin</th>
                        <th style="width:90px;">Type</th>
                        <th style="width:130px;">IP Address</th>
                        <th style="width:170px;">Date & Time</th>
                        <th style="width:80px;text-align:center;">Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($audit_records)): foreach ($audit_records as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo esc_attr($r['id']); ?>"></td>
                        <td><?php echo esc_html($r['id']); ?></td>
                        <td><strong><?php echo esc_html($r['user_login']); ?></strong></td>
                        <td><span style="display:inline-block;padding:4px 8px;border-radius:4px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:700;"><?php echo esc_html($r['action_type']); ?></span></td>
                        <td>
                            <strong><?php echo esc_html($r['object_name'] ?: '(No title)'); ?></strong>
                            <?php if (!empty($r['object_id'])): ?><div style="font-size:11px;color:#64748b;margin-top:3px;">ID: <?php echo esc_html($r['object_id']); ?></div><?php endif; ?>
                            <?php if (!empty($r['object_url'])): ?><div style="font-size:11px;margin-top:3px;"><a href="<?php echo esc_url($r['object_url']); ?>" target="_blank" rel="noopener noreferrer">View / Edit</a></div><?php endif; ?>
                        </td>
                        <td><?php echo esc_html(ucfirst($r['object_type'])); ?></td>
                        <td><code><?php echo esc_html($r['user_ip']); ?></code></td>
                        <td><?php echo esc_html($r['action_time']); ?></td>
                        <td style="text-align:center;"><a href="<?php echo esc_url(bx_log_delete_url('audit', $r['id'])); ?>" class="button button-small" style="color:#b91c1c;" onclick="return confirm('Delete this record?');">Delete</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" style="text-align:center;padding:20px;">No content or plugin activity recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($show_login): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:20px 0;">
            <h2 style="margin:0 0 6px;font-size:18px;">Login Activity & Geolocation</h2>
            <p style="color:#64748b;margin-top:0;">Tracks username, IP address, country, city and login timestamp.</p>
            <?php bx_log_bulk_form_open('login'); ?>
            <p style="display:flex;gap:8px;">
                <?php bx_log_bulk_button(); ?>
                <a class="button button-secondary" href="<?php echo esc_url(bx_log_clear_url('login')); ?>" onclick="return confirm('Clear all login activity logs?');">Clear All Login Logs</a>
            </p>
            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" class="bx-check-all"></th>
                        <th style="width:60px;">#</th>
                        <th style="width:160px;">Username</th>
                        <th style="width:160px;">IP Address</th>
                        <th>Location (Country & City)</th>
                        <th style="width:200px;">Timestamp</th>
                        <th style="width:80px;text-align:center;">Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($records)): foreach ($records as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo esc_attr($r['id']); ?>"></td>
                        <td><?php echo esc_html($r['id']); ?></td>
                        <td><strong><?php echo esc_html($r['user_login']); ?></strong></td>
                        <td><code><?php echo esc_html($r['user_ip']); ?></code></td>
                        <td><?php echo esc_html($r['city'] . ', ' . $r['country']); ?></td>
                        <td><?php echo esc_html($r['login_time']); ?></td>
                        <td style="text-align:center;"><a href="<?php echo esc_url(bx_log_delete_url('login', $r['id'])); ?>" class="button button-small" style="color:#b91c1c;" onclick="return confirm('Delete this record?');">Delete</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:20px;">No user login activity recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </form>
        </div>
        <?php endif; ?>
        <?php bx_log_bulk_script(); ?>
    </div>
    <?php
}

/**
 * 19.1 Content & Plugin Audit Database
 * Schema is installed by bx_powerkit_install_db().
 */
function bx_save_audit_activity($args = []) {
    if (empty(get_option('sb_options', [])['enable_audit_log'])) return;
    global $wpdb;
    $args = wp_parse_args($args, [
        'action_type' => '', 'object_type' => '', 'object_id' => 0,
        'object_name' => '', 'object_url' => ''
    ]);
    $user = wp_get_current_user();
    $wpdb->insert($wpdb->prefix . 'bx_audit_activity', [
        'user_id' => get_current_user_id(),
        'user_login' => ($user && $user->exists()) ? $user->user_login : 'System',
        'action_type' => sanitize_text_field($args['action_type']),
        'object_type' => sanitize_key($args['object_type']),
        'object_id' => absint($args['object_id']),
        'object_name' => sanitize_text_field($args['object_name']),
        'object_url' => esc_url_raw((string) $args['object_url']),
        'user_ip' => bx_client_ip(),
        'action_time' => current_time('mysql')
    ], ['%d','%s','%s','%s','%d','%s','%s','%s','%s']);
}

/** Log page/post create and update. */
add_action('wp_after_insert_post', function($post_id, $post, $update) {
    if (!$post instanceof WP_Post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!in_array($post->post_type, ['page','post'], true)) return;
    if ($post->post_status === 'auto-draft') return;
    bx_save_audit_activity([
        'action_type' => $update ? 'Updated ' . ucfirst($post->post_type) : 'Created ' . ucfirst($post->post_type),
        'object_type' => $post->post_type,
        'object_id' => $post_id,
        'object_name' => get_the_title($post_id),
        'object_url' => get_edit_post_link($post_id, '')
    ]);
}, 10, 3);

/** Log deletion before the object disappears. */
add_action('before_delete_post', function($post_id, $post) {
    if (!$post instanceof WP_Post) $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['page','post'], true)) return;
    bx_save_audit_activity([
        'action_type' => 'Deleted ' . ucfirst($post->post_type),
        'object_type' => $post->post_type,
        'object_id' => $post_id,
        'object_name' => $post->post_title
    ]);
}, 10, 2);

/** Log plugin activation/deactivation. */
add_action('activated_plugin', function($plugin_file) {
    $name = $plugin_file;
    $path = WP_PLUGIN_DIR . '/' . $plugin_file;
    if (file_exists($path)) {
        $data = get_plugin_data($path, false, false);
        if (!empty($data['Name'])) $name = $data['Name'];
    }
    bx_save_audit_activity(['action_type'=>'Activated Plugin','object_type'=>'plugin','object_name'=>$name]);
});

add_action('deactivated_plugin', function($plugin_file) {
    $name = $plugin_file;
    $path = WP_PLUGIN_DIR . '/' . $plugin_file;
    if (file_exists($path)) {
        $data = get_plugin_data($path, false, false);
        if (!empty($data['Name'])) $name = $data['Name'];
    }
    bx_save_audit_activity(['action_type'=>'Deactivated Plugin','object_type'=>'plugin','object_name'=>$name]);
});

/** Log plugin install/update. */
add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') return;
    $action = $hook_extra['action'] ?? '';
    if (!in_array($action, ['install','update'], true)) return;
    $plugins = [];
    if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) $plugins = $hook_extra['plugins'];
    if (!empty($hook_extra['plugin'])) $plugins[] = $hook_extra['plugin'];
    foreach (array_unique($plugins) as $plugin_file) {
        $name = $plugin_file;
        $path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (file_exists($path)) {
            $data = get_plugin_data($path, false, false);
            if (!empty($data['Name'])) $name = $data['Name'];
        }
        bx_save_audit_activity(['action_type'=>($action === 'install' ? 'Installed Plugin' : 'Updated Plugin'),'object_type'=>'plugin','object_name'=>$name]);
    }
}, 10, 2);

/** Log plugin deletion. */
add_action('deleted_plugin', function($plugin_file) {
    bx_save_audit_activity(['action_type'=>'Deleted Plugin','object_type'=>'plugin','object_name'=>$plugin_file]);
});


/**
 * 20. Admin Page: Active Redirections Manager List with Hit Counters
 */
function bx_admin_redirections_page() {
    global $wpdb;
    // Remove action is handled in admin_init (bx_powerkit_handle_admin_actions) before any output.

    $redirections = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value as target_url 
        FROM {$wpdb->posts} p 
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE pm.meta_key = '_bx_redirect_url' AND pm.meta_value != ''
    ", ARRAY_A);
    ?>
    <div class="wrap">
        <h1 style="font-size:22px; font-weight:700; margin:15px 0 5px;">Active 301 Redirections & Traffic Hits</h1>
        <p style="color:#64748b;">Complete list of all pages and posts currently configured with 301 redirects along with their live hit counts.</p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th style="width:230px;">Source Title (Page / Post)</th>
                    <th style="width:90px;">Type</th>
                    <th>Target Destination URL</th>
                    <th style="width:90px; text-align:center;">Hits</th>
                    <th style="width:110px; text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($redirections)): foreach ($redirections as $redir): 
                    $edit_link = get_edit_post_link($redir['ID']);
                    $hits = (int) get_post_meta($redir['ID'], '_bx_redirect_hits', true);
                ?>
                    <tr>
                        <td>#<?php echo esc_html($redir['ID']); ?></td>
                        <td>
                            <strong><a href="<?php echo esc_url($edit_link); ?>" target="_blank"><?php echo esc_html($redir['post_title'] ?: '(No Title)'); ?></a></strong>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">Status: <?php echo esc_html(ucfirst($redir['post_status'])); ?></div>
                        </td>
                        <td><span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;"><?php echo esc_html(ucfirst($redir['post_type'])); ?></span></td>
                        <td><a href="<?php echo esc_url($redir['target_url']); ?>" target="_blank" style="color:#0074FD;"><?php echo esc_html($redir['target_url']); ?></a></td>
                        <td style="text-align:center;"><span style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:12px; font-weight:700; font-size:12px;"><?php echo esc_html($hits); ?></span></td>
                        <td style="text-align:center;">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=brandix-redirections&bx_remove_redirection=' . (int) $redir['ID']), 'bx_remove_redir_' . (int) $redir['ID'])); ?>" class="button button-small" style="color:#ef4444;" onclick="return confirm('Remove redirection for this page?');">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px;">No active redirections configured yet. Use quick links in Pages/Posts list to add redirects.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * 21. Frontend Sticky Action Buttons Output (With Full SVG Rendering)
 */
add_action('wp_footer', function() {
    $options = get_option('sb_options', []);
    if (empty($options['enable_sticky_buttons']) || empty($options['buttons'])) return;

    $items = [];
    foreach ((array) $options['buttons'] as $b) { if (!empty($b['link'])) $items[] = $b; }
    if (!$items) return;

    $s          = bx_sticky_opts($options);
    $size       = absint($options['icon_size'] ?? 52) ?: 52;
    $label_size = absint($options['label_font_size'] ?? 14) ?: 14;
    $pos        = $options['position'] ?? 'right';
    $is_left    = in_array($pos, ['left', 'middle-left', 'top-left'], true);
    $vpos       = strpos($pos, 'top') === 0 ? 'top' : (strpos($pos, 'middle') === 0 ? 'middle' : 'bottom');
    $horiz      = ($s['sticky_layout'] ?? 'vertical') === 'horizontal';
    $side       = $is_left ? 'left' : 'right';
    $icons      = sb_get_native_icons();
    $toggle     = $s['sticky_mode'] === 'toggle';
    $card       = $s['item_style'] === 'card';
    $shape      = $s['launcher_shape'];
    $L          = absint($s['launcher_size']) ?: $size;   // launcher size
    $ico        = round($L * 0.46);                       // launcher icon size
    $lb         = $s['launcher_bg'];
    $lc         = $s['launcher_color'];
    $n          = count($items);

    // Icon button shape (detached style)
    $btn_radius = ['circle' => '50%', 'rounded' => round($size * 0.28) . 'px', 'square' => '6px', 'hexagon' => '0'][$s['btn_shape']] ?? '50%';

    // Launcher shape
    $shape_css = [
        'circle'  => 'border-radius:50%;',
        'rounded' => 'border-radius:' . round($L * 0.3) . 'px;',
        'square'  => 'border-radius:6px;',
        'pill'    => 'border-radius:999px;',
        'bubble'  => 'border-radius:' . ($is_left ? '50% 50% 50% 8px' : '50% 50% 8px 50%') . ';',
        'hexagon' => 'clip-path:polygon(25% 6%,75% 6%,100% 50%,75% 94%,25% 94%,0 50%);',
        'diamond' => 'border-radius:' . round($L * 0.22) . 'px;transform:rotate(45deg) scale(.8);',
        'blob'    => 'border-radius:42% 58% 55% 45% / 48% 42% 58% 52%;animation:bx-blob 7s ease-in-out infinite alternate;',
    ][$shape] ?? 'border-radius:50%;';
    $base_tf = $shape === 'diamond' ? 'rotate(45deg) scale(.8) ' : '';

    // Open animation start state
    $from = [
        'fade-up' => 'translateY(' . ($vpos === 'top' ? '-' : '') . '18px)',
        'pop'     => 'scale(.3)',
        'slide'   => 'translateX(' . ($is_left ? '-' : '') . '60px)',
        'rise'    => 'translateY(' . ($vpos === 'top' ? '-' : '') . '40px) scale(.5)',
        'flip'    => 'perspective(400px) rotateX(-90deg)',
        'fade'    => 'none',
    ][$s['open_anim']] ?? 'translateY(18px)';
    $ease = in_array($s['open_anim'], ['pop', 'rise'], true) ? 'cubic-bezier(.34,1.56,.64,1)' : 'cubic-bezier(.22,1,.36,1)';

    // Keep icons centred over the launcher when sizes differ
    $items_pad = ($toggle && !$card && !$horiz && $shape !== 'pill') ? max(0, round(($L - $size) / 2)) : 0;
    // Wrapper placement and direction
    $vcss = $vpos === 'top' ? 'top:' . (int) $s['offset_bottom'] . 'px;' : ($vpos === 'middle' ? 'top:0;bottom:0;margin:auto 0;height:max-content;' : 'bottom:' . (int) $s['offset_bottom'] . 'px;');
    if ($horiz) {
        $wrap_dir = $is_left ? 'row' : 'row-reverse'; $items_dir = $wrap_dir; $wrap_align = 'center'; $items_align = 'center';
    } else {
        $wrap_dir = $vpos === 'top' ? 'column' : 'column-reverse'; $items_dir = $wrap_dir;
        $wrap_align = $is_left ? 'flex-start' : 'flex-end'; $items_align = $card ? 'stretch' : $wrap_align;
    }
    $launch_margin = (!$card && $shape !== 'pill') ? max(0, round(($size - $L) / 2)) : 0;

    $start_open = $toggle && $s['start_state'] === 'open';
    $collapse_on = (array) $s['collapse_on'];
    $mq = ['d' => '(min-width:1025px)', 't' => '(min-width:768px) and (max-width:1024px)', 'm' => '(max-width:767px)'];

    $wrap_classes = ['bx-sticky-wrapper'];
    if ($toggle) $wrap_classes[] = 'bx-collapsible';
    if ($start_open) $wrap_classes[] = 'bx-open';
    if ($card) $wrap_classes[] = 'bx-style-card';
    if ($horiz) $wrap_classes[] = 'bx-layout-h';

    $launcher_icons = bx_launcher_icons();
    $open_svg  = $launcher_icons[$s['launcher_icon']]['svg'] ?? $launcher_icons['chat-dots']['svg'];
    $close_svg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
    ?>
    <style>
        #bx-sticky{position:fixed;<?php echo esc_attr($vcss); ?><?php echo esc_attr($side); ?>:<?php echo (int) $s['offset_side']; ?>px;z-index:99999;display:flex;flex-direction:<?php echo esc_attr($wrap_dir); ?>;align-items:<?php echo esc_attr($wrap_align); ?>;gap:14px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
        #bx-sticky .bx-items{display:flex;flex-direction:<?php echo esc_attr($items_dir); ?>;gap:12px;align-items:<?php echo esc_attr($items_align); ?>;padding-<?php echo esc_attr($side); ?>:<?php echo esc_attr($items_pad); ?>px}
        #bx-sticky .bx-btn-unit{display:inline-flex;align-items:center;flex-direction:<?php echo esc_attr($is_left ? 'row' : 'row-reverse'); ?>;gap:10px;text-decoration:none!important;transition:transform .25s ease}
        #bx-sticky .bx-btn-unit:hover{transform:translateY(-2px)}
        #bx-sticky .bx-icon-circle{width:<?php echo esc_attr($size); ?>px;height:<?php echo esc_attr($size); ?>px;border-radius:<?php echo esc_attr($btn_radius); ?>;<?php echo $s['btn_shape'] === 'hexagon' ? 'clip-path:polygon(25% 6%,75% 6%,100% 50%,75% 94%,25% 94%,0 50%);' : ''; ?>display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.25);flex-shrink:0;transition:transform .2s ease}
        #bx-sticky .bx-btn-unit:hover .bx-icon-circle{transform:scale(1.06)}
        #bx-sticky .bx-icon-circle svg{width:<?php echo esc_attr(round($size * 0.52)); ?>px;height:<?php echo esc_attr(round($size * 0.52)); ?>px}
        #bx-sticky .bx-pill-label{padding:<?php echo esc_attr(round($label_size * 0.5)); ?>px <?php echo esc_attr(round($label_size * 1.15)); ?>px;border-radius:12px;color:#fff;font-size:<?php echo esc_attr($label_size); ?>px;font-weight:600;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.18);letter-spacing:.2px;line-height:1.25}
        <?php if ($card): ?>
        #bx-sticky .bx-btn-unit{display:flex;flex-direction:row;gap:12px;padding:<?php echo esc_attr(round($label_size * 0.85)); ?>px <?php echo esc_attr(round($label_size * 1.6)); ?>px <?php echo esc_attr(round($label_size * 0.85)); ?>px <?php echo esc_attr(round($label_size * 1.15)); ?>px;background:<?php echo esc_attr($s['card_bg']); ?>;border-radius:14px;box-shadow:0 6px 18px rgba(0,0,0,.14)}
        #bx-sticky .bx-icon-circle{width:<?php echo esc_attr(round($label_size * 1.7)); ?>px;height:<?php echo esc_attr(round($label_size * 1.7)); ?>px;background:none!important;box-shadow:none;border-radius:0;clip-path:none}
        #bx-sticky .bx-icon-circle svg{width:<?php echo esc_attr(round($label_size * 1.45)); ?>px;height:<?php echo esc_attr(round($label_size * 1.45)); ?>px}
        #bx-sticky .bx-pill-label{background:none!important;box-shadow:none;padding:0;color:<?php echo esc_attr($s['card_text']); ?>;font-size:<?php echo esc_attr($label_size + 1); ?>px}
        <?php endif; ?>
        #bx-sticky .bx-cust-ico{display:block;width:100%;height:100%;background:currentColor}
        #bx-sticky .bx-icon-circle img{width:<?php echo esc_attr(round($size * 0.52)); ?>px;height:<?php echo esc_attr(round($size * 0.52)); ?>px;object-fit:contain}
        <?php if ($horiz && !$card): ?>
        #bx-sticky .bx-btn-unit{position:relative}
        #bx-sticky .bx-pill-label{position:absolute;<?php echo esc_attr($vpos === 'top' ? 'top' : 'bottom'); ?>:calc(100% + 8px);left:50%;transform:translateX(-50%);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease}
        #bx-sticky .bx-btn-unit:hover .bx-pill-label,#bx-sticky .bx-btn-unit:focus-visible .bx-pill-label{opacity:1;visibility:visible}
        <?php endif; ?>
        @media (min-width:1025px){#bx-sticky .bx-hide-btn-d,#bx-sticky .bx-hide-lbl-d{display:none!important}}
        @media (min-width:768px) and (max-width:1024px){#bx-sticky .bx-hide-btn-t{display:none!important}}
        @media (max-width:767px){#bx-sticky .bx-hide-btn-m,#bx-sticky .bx-hide-lbl-m{display:none!important}}

        <?php if ($toggle): ?>
        /* Collapsed / open states */
        #bx-sticky.bx-collapsible .bx-btn-unit{transform-origin:<?php echo esc_attr($side . ' ' . ($vpos === 'top' ? 'top' : 'bottom')); ?>;transition:opacity .2s ease,transform .25s ease,visibility 0s linear .25s}
        #bx-sticky.bx-collapsible:not(.bx-open) .bx-btn-unit{opacity:0;visibility:hidden;pointer-events:none;transform:<?php echo esc_attr($from); ?>}
        <?php for ($k = 1; $k <= $n; $k++): $delay = $s['stagger'] ? ($k - 1) * 60 : 0; ?>
        #bx-sticky.bx-open .bx-items > :nth-child(<?php echo esc_attr($k); ?>){transition:opacity .3s ease <?php echo esc_attr($delay); ?>ms,transform .45s <?php echo esc_attr($ease); ?> <?php echo esc_attr($delay); ?>ms,visibility 0s linear <?php echo esc_attr($delay); ?>ms}
        <?php endfor; ?>

        /* Launcher */
        #bx-sticky .bx-launcher{position:relative;width:<?php echo esc_attr($L); ?>px;height:<?php echo esc_attr($L); ?>px;margin:0 <?php echo esc_attr($is_left ? '0 0 ' . $launch_margin . 'px' : $launch_margin . 'px 0 0'); ?>;padding:0;border:0;background:none;cursor:pointer;color:<?php echo esc_attr($lc); ?>;display:flex;align-items:center;justify-content:center;filter:drop-shadow(0 6px 14px rgba(0,0,0,.28));transition:transform .25s ease;-webkit-tap-highlight-color:transparent;font:inherit;line-height:1}
        #bx-sticky .bx-launcher:hover{transform:scale(1.06)}
        #bx-sticky .bx-launcher:focus-visible{outline:2px solid <?php echo esc_attr($lb); ?>;outline-offset:5px}
        #bx-sticky .bx-l-in{position:relative;display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:100%}
        #bx-sticky .bx-l-bg,#bx-sticky .bx-l-pulse{position:absolute;top:0;right:0;bottom:0;left:0;background:<?php echo esc_attr($lb); ?>;<?php echo esc_attr($shape_css); ?>}
        #bx-sticky .bx-l-pulse{opacity:0}
        #bx-sticky .bx-l-icons{position:relative;z-index:1;width:<?php echo esc_attr($ico); ?>px;height:<?php echo esc_attr($ico); ?>px;flex-shrink:0}
        #bx-sticky .bx-l-ico{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;transition:transform .35s ease,opacity .25s ease}
        #bx-sticky .bx-l-ico svg{width:100%;height:100%}
        #bx-sticky .bx-l-close{opacity:0;transform:rotate(-90deg) scale(.5)}
        #bx-sticky.bx-open .bx-l-open{opacity:0;transform:rotate(90deg) scale(.5)}
        #bx-sticky.bx-open .bx-l-close{opacity:1;transform:none}
        #bx-sticky .bx-l-text{position:relative;z-index:1;font-size:<?php echo esc_attr($label_size + 1); ?>px;font-weight:600;white-space:nowrap}
        <?php if ($shape === 'pill'): ?>
        #bx-sticky .bx-launcher{width:auto}
        #bx-sticky .bx-l-in{width:auto;padding:0 <?php echo esc_attr(round($L * 0.45)); ?>px 0 <?php echo esc_attr(round($L * 0.34)); ?>px}
        <?php endif; ?>
        #bx-sticky .bx-l-badge{position:absolute;top:-4px;<?php echo esc_attr($is_left ? 'right' : 'left'); ?>:-4px;z-index:2;min-width:20px;height:20px;padding:0 6px;box-sizing:border-box;border-radius:10px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;line-height:20px;text-align:center;box-shadow:0 0 0 2px #fff}
        #bx-sticky.bx-open .bx-l-badge,#bx-sticky.bx-seen .bx-l-badge{display:none}

        /* Attention effects (only while closed) */
        @keyframes bx-ring{0%{transform:<?php echo esc_attr($base_tf); ?>scale(1);opacity:.55}100%{transform:<?php echo esc_attr($base_tf); ?>scale(1.6);opacity:0}}
        @keyframes bx-bounce{0%,20%,50%,80%,100%{transform:translateY(0)}40%{transform:translateY(-10px)}60%{transform:translateY(-5px)}}
        @keyframes bx-wiggle{0%,70%,100%{transform:rotate(0)}75%{transform:rotate(-12deg)}80%{transform:rotate(10deg)}85%{transform:rotate(-8deg)}90%{transform:rotate(6deg)}95%{transform:rotate(-3deg)}}
        @keyframes bx-heart{0%,42%,100%{transform:scale(1)}14%,35%{transform:scale(1.12)}28%{transform:scale(1)}}
        @keyframes bx-glow{0%{box-shadow:0 0 0 0 <?php echo esc_attr(bx_hex_rgba($lb, .6)); ?>}100%{box-shadow:0 0 0 16px <?php echo esc_attr(bx_hex_rgba($lb, 0)); ?>}}
        @keyframes bx-blob{0%{border-radius:42% 58% 55% 45% / 48% 42% 58% 52%}50%{border-radius:58% 42% 45% 55% / 55% 58% 42% 45%}100%{border-radius:48% 52% 62% 38% / 40% 52% 48% 60%}}
        <?php
        $att = [
            'pulse'     => '#bx-sticky:not(.bx-open) .bx-l-pulse{animation:bx-ring 1.8s ease-out infinite}',
            'bounce'    => '#bx-sticky:not(.bx-open) .bx-l-in{animation:bx-bounce 2.6s ease infinite}',
            'wiggle'    => '#bx-sticky:not(.bx-open) .bx-l-in{animation:bx-wiggle 3.2s ease infinite}',
            'heartbeat' => '#bx-sticky:not(.bx-open) .bx-l-in{animation:bx-heart 2s ease infinite}',
            'glow'      => '#bx-sticky:not(.bx-open) .bx-l-bg{animation:bx-glow 1.8s ease-out infinite' . ($shape === 'blob' ? ',bx-blob 7s ease-in-out infinite alternate' : '') . '}',
        ];
        echo esc_html($att[$s['attention']] ?? '');
        ?>

        /* Greeting bubble */
        #bx-sticky .bx-greet{position:absolute;<?php echo esc_attr($vpos === 'top' ? 'top' : 'bottom'); ?>:<?php echo esc_attr(round($L * 0.12)); ?>px;<?php echo esc_attr($side); ?>:<?php echo esc_attr($L + $launch_margin + 14); ?>px;width:max-content;max-width:min(250px,calc(100vw - <?php echo esc_attr($L + (int) $s['offset_side'] + 40); ?>px));padding:12px 34px 12px 14px;background:#fff;color:#1e293b;font-size:14px;line-height:1.45;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);cursor:pointer;opacity:0;visibility:hidden;transform:translateX(<?php echo esc_attr($is_left ? '-' : ''); ?>10px);transition:opacity .3s ease,transform .3s ease,visibility 0s linear .3s}
        #bx-sticky .bx-greet.bx-show{opacity:1;visibility:visible;transform:none;transition:opacity .3s ease,transform .3s ease}
        #bx-sticky .bx-greet-x{position:absolute;top:4px;right:6px;width:24px;height:24px;padding:0;border:0;background:none;color:#94a3b8;font-size:18px;line-height:24px;cursor:pointer}

        /* Dim overlay */
        #bx-sticky .bx-overlay{position:fixed;top:0;right:0;bottom:0;left:0;z-index:-1;background:rgba(15,23,42,.45);opacity:0;visibility:hidden;transition:opacity .3s ease,visibility 0s linear .3s}
        #bx-sticky.bx-open .bx-overlay{opacity:1;visibility:visible;transition:opacity .3s ease}

        <?php foreach ($mq as $dev => $query): if (in_array($dev, $collapse_on, true)) continue; ?>
        /* Not collapsed on this device: plain always-visible buttons */
        @media <?php echo esc_attr($query); ?>{
            #bx-sticky .bx-launcher,#bx-sticky .bx-greet,#bx-sticky .bx-overlay{display:none!important}
            #bx-sticky.bx-collapsible .bx-btn-unit{opacity:1!important;visibility:visible!important;transform:none!important;pointer-events:auto!important}
            #bx-sticky .bx-items{padding-<?php echo esc_attr($side); ?>:0}
        }
        <?php endforeach; ?>
        @media (prefers-reduced-motion:reduce){#bx-sticky *{animation:none!important;transition-duration:.01ms!important;transition-delay:0s!important}}
        <?php endif; ?>
    </style>
    <div id="bx-sticky" class="<?php echo esc_attr(implode(' ', $wrap_classes)); ?>"<?php if ($toggle): ?> data-open-on="<?php echo esc_attr($s['open_on']); ?>" data-start="<?php echo esc_attr($s['start_state']); ?>" data-delay="<?php echo (int) $s['auto_open_delay']; ?>" data-greet-delay="<?php echo (int) $s['greeting_delay']; ?>"<?php endif; ?>>
        <?php if ($toggle): ?>
            <button type="button" class="bx-launcher bx-shape-<?php echo esc_attr($shape); ?>" aria-expanded="<?php echo $start_open ? 'true' : 'false'; ?>" aria-controls="bx-sticky-items" aria-label="<?php echo esc_attr($s['launcher_text'] ?: 'Contact options'); ?>">
                <span class="bx-l-in">
                    <span class="bx-l-pulse" aria-hidden="true"></span>
                    <span class="bx-l-bg" aria-hidden="true"></span>
                    <span class="bx-l-icons"><span class="bx-l-ico bx-l-open"><?php echo $open_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the plugin. ?></span><span class="bx-l-ico bx-l-close"><?php echo $close_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the plugin. ?></span></span>
                    <?php if ($shape === 'pill' && $s['launcher_text'] !== ''): ?><span class="bx-l-text"><?php echo esc_html($s['launcher_text']); ?></span><?php endif; ?>
                </span>
                <?php if ($s['badge_text'] !== ''): ?><span class="bx-l-badge"><?php echo esc_html($s['badge_text']); ?></span><?php endif; ?>
            </button>
            <?php if ($s['greeting'] !== ''): ?>
                <div class="bx-greet" role="status"><?php echo esc_html($s['greeting']); ?><button type="button" class="bx-greet-x" aria-label="Close">&times;</button></div>
            <?php endif; ?>
            <?php if (!empty($s['dim_bg'])): ?><div class="bx-overlay" aria-hidden="true"></div><?php endif; ?>
        <?php endif; ?>
        <div class="bx-items" id="bx-sticky-items">
        <?php foreach ($items as $btn):
            $vis = (array) ($btn['vis'] ?? ['d', 't', 'm']);
            $label_vis = (array) ($btn['label_vis'] ?? ['d']);

            $btn_classes = ['bx-btn-unit'];
            if (!in_array('d', $vis)) $btn_classes[] = 'bx-hide-btn-d';
            if (!in_array('t', $vis)) $btn_classes[] = 'bx-hide-btn-t';
            if (!in_array('m', $vis)) $btn_classes[] = 'bx-hide-btn-m';

            $lbl_classes = ['bx-pill-label'];
            if (!in_array('d', $label_vis)) $lbl_classes[] = 'bx-hide-lbl-d';
            if (!in_array('m', $label_vis)) $lbl_classes[] = 'bx-hide-lbl-m';

            $icon_key = $btn['icon'] ?? 'phone';
            $svg = $icons[$icon_key]['svg'] ?? $icons['phone']['svg'];
            $icon_bg = $btn['icon_bg'] ?? ($btn['col'] ?? '#0074FD');
            $label_bg = $btn['label_bg'] ?? '#1e293b';
            // tel:/mailto:/sms: open in place; only web links get a new tab.
            $is_web = (bool) preg_match('#^https?://#i', (string) $btn['link']);
            $icon_style = $card ? 'color:' . $icon_bg . ';' : 'background:' . $icon_bg . ';';
            if (!empty($btn['custom_icon'])) {
                $cu = esc_url($btn['custom_icon']);
                // SVG uses the button colour (mask); PNG/JPG keeps its own colours.
                $svg = preg_match('/\.svg($|\?)/i', $btn['custom_icon'])
                    ? '<span class="bx-cust-ico" style="-webkit-mask:url(' . $cu . ') center/contain no-repeat;mask:url(' . $cu . ') center/contain no-repeat"></span>'
                    : '<img src="' . $cu . '" alt="" loading="lazy">';
            }
        ?>
            <a href="<?php echo esc_url($btn['link']); ?>" class="<?php echo esc_attr(implode(' ', $btn_classes)); ?>"<?php echo $is_web ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="<?php echo esc_attr($btn['label'] ?: ($icons[$icon_key]['name'] ?? 'Contact')); ?>">
                <span class="bx-icon-circle" style="<?php echo esc_attr($icon_style); ?>"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from the plugin icon list or an esc_url() image. ?></span>
                <?php if (!empty($btn['label'])): ?>
                    <span class="<?php echo esc_attr(implode(' ', $lbl_classes)); ?>"<?php echo $card ? '' : ' style="background:' . esc_attr($label_bg) . ';"'; ?>><?php echo esc_html($btn['label']); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <script>
    document.querySelectorAll('#bx-sticky a[href="#top"]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    });
    </script>
    <?php if ($toggle): ?>
    <script>
    (function () {
        var w = document.getElementById('bx-sticky');
        if (!w) return;
        var b = w.querySelector('.bx-launcher'), g = w.querySelector('.bx-greet'), ov = w.querySelector('.bx-overlay');
        if (!b) return;
        // sessionStorage can throw (private mode / blocked storage), so wrap it.
        function getS(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
        function setS(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
        function set(open) {
            w.classList.toggle('bx-open', open);
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                if (g) g.classList.remove('bx-show');
                w.classList.add('bx-seen');
                setS('bx_sticky_seen', '1');
            }
        }
        if (getS('bx_sticky_seen')) w.classList.add('bx-seen');

        var hover = w.getAttribute('data-open-on') === 'hover' && window.matchMedia && window.matchMedia('(hover: hover)').matches;
        b.addEventListener('click', function (e) { e.stopPropagation(); set(hover ? true : !w.classList.contains('bx-open')); });
        if (hover) {
            w.addEventListener('mouseenter', function () { set(true); });
            w.addEventListener('mouseleave', function () { set(false); });
        }
        document.addEventListener('click', function (e) { if (!w.contains(e.target) || e.target === ov) set(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
        w.querySelectorAll('.bx-btn-unit').forEach(function (a) { a.addEventListener('click', function () { setTimeout(function () { set(false); }, 150); }); });

        if (w.getAttribute('data-start') === 'auto' && !getS('bx_sticky_auto')) {
            setTimeout(function () { set(true); setS('bx_sticky_auto', '1'); }, (parseInt(w.getAttribute('data-delay'), 10) || 5) * 1000);
        }
        if (g && !getS('bx_sticky_greet')) {
            var x = g.querySelector('.bx-greet-x');
            setTimeout(function () { if (!w.classList.contains('bx-open')) g.classList.add('bx-show'); }, (parseInt(w.getAttribute('data-greet-delay'), 10) || 3) * 1000);
            if (x) x.addEventListener('click', function (e) { e.stopPropagation(); g.classList.remove('bx-show'); setS('bx_sticky_greet', '1'); });
            g.addEventListener('click', function (e) { if (e.target !== x) { e.stopPropagation(); setS('bx_sticky_greet', '1'); set(true); } });
        }
    })();
    </script>
    <?php endif; ?>
    <?php
});

/**
 * 22. 1-Click Page & Elementor Post Duplicator
 * Uses the proven Elementor-safe meta-copy method from the original working engine.
 */
add_filter('post_row_actions', 'bx_duplicator_row_hook', 10, 2);
add_filter('page_row_actions', 'bx_duplicator_row_hook', 10, 2);
function bx_duplicator_row_hook($actions, $post) {
    $options = get_option('sb_options', []);
    if (empty($options['enable_duplicator']) || !current_user_can('edit_post', $post->ID)) return $actions;

    $label = ($post->post_type === 'page') ? 'Duplicate Page' : 'Duplicate Post';
    $actions['bx_duplicate'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=sb_duplicate&post=' . $post->ID), 'sb_dup_' . $post->ID)) . '" style="color:#0074FD;font-weight:bold;">' . esc_html($label) . '</a>';
    return $actions;
}

add_action('admin_action_sb_duplicate', function() {
    // Respect the module switch even if someone calls the URL directly.
    if (empty(get_option('sb_options', [])['enable_duplicator'])) wp_die('The duplicator module is disabled in Brandix Kit.');
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked right below with this ID.
    if (!$post_id) wp_die('Invalid item.');
    check_admin_referer('sb_dup_' . $post_id);

    $post = get_post($post_id);
    if (!$post) wp_die('Item not found.');
    if (!current_user_can('edit_post', $post_id)) wp_die('Access denied.');

    $pto = get_post_type_object($post->post_type);
    $create_cap = ($pto && !empty($pto->cap->create_posts)) ? $pto->cap->create_posts : 'edit_posts';
    if (!current_user_can($create_cap)) wp_die('You cannot create this content type.');

    $new_post = [
        'post_author'           => get_current_user_id(),
        'post_content'          => $post->post_content,
        'post_content_filtered' => $post->post_content_filtered,
        'post_excerpt'          => $post->post_excerpt,
        'post_name'             => '',
        'post_parent'           => $post->post_parent,
        'post_status'           => 'draft',
        'post_title'            => $post->post_title . ' (Copy)',
        'post_type'             => $post->post_type,
        'menu_order'            => $post->menu_order,
        'comment_status'        => $post->comment_status,
        'ping_status'           => $post->ping_status,
        'post_password'         => $post->post_password,
    ];

    // wp_insert_post() unslashes its input; slash first so backslashes in content (e.g. Gutenberg JSON) survive.
    $new_id = wp_insert_post(wp_slash($new_post), true);
    if (is_wp_error($new_id)) wp_die(esc_html($new_id->get_error_message()));

    // Copy categories/tags/custom taxonomies exactly as the original working engine did.
    $taxonomies = get_object_taxonomies($post->post_type);
    foreach ($taxonomies as $taxonomy) {
        $post_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
        if (!is_wp_error($post_terms)) {
            wp_set_object_terms($new_id, $post_terms, $taxonomy, false);
        }
    }

    /*
     * Elementor-safe meta copy.
     * IMPORTANT: wp_slash() is intentional. WordPress strips slashes while storing meta;
     * without this, Elementor's JSON in _elementor_data can become invalid and the page
     * may open as one Text Editor widget instead of the original Elementor layout.
     */
    $meta_keys = get_post_custom_keys($post_id);
    $is_elementor = false;
    $skip_meta = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_bx_redirect_hits',
        '_bx_redirect_url',
        '_elementor_css',
    ];

    if ($meta_keys) {
        foreach ($meta_keys as $key) {
            if (in_array($key, $skip_meta, true)) continue;
            $meta_values = get_post_custom_values($key, $post_id);
            if (!$meta_values) continue;

            foreach ($meta_values as $value) {
                $value = maybe_unserialize($value);
                add_post_meta($new_id, $key, wp_slash($value));
            }
        }

        if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder') {
            $is_elementor = true;
        }
    }

    if ($is_elementor) {
        update_post_meta($new_id, '_elementor_edit_mode', 'builder');

        // Generate fresh CSS for the duplicate instead of copying the old post-specific CSS.
        if (class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
            try {
                $post_css = new \Elementor\Core\Files\CSS\Post($new_id);
                $post_css->update();
            } catch (Throwable $e) {
                // Duplication must still succeed even if CSS regeneration fails.
            }
        }

        // Clear Elementor files/cache when available.
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                if (isset(\Elementor\Plugin::$instance->files_manager)) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch (Throwable $e) {}
        }
    }

    clean_post_cache($new_id);
    wp_safe_redirect(admin_url('post.php?post=' . $new_id . '&action=edit'));
    exit;
});

/**
 * 23. Quick Redirection Row Trigger & Meta Box Editor
 */
add_filter('post_row_actions', 'sb_add_redirect_row_action', 15, 2);
add_filter('page_row_actions', 'sb_add_redirect_row_action', 15, 2);
function sb_add_redirect_row_action($actions, $post) {
    $options = get_option('sb_options', []);
    if (empty($options['enable_redirection'])) return $actions;

    if (current_user_can('edit_post', $post->ID)) {
        $current_target = get_post_meta($post->ID, '_bx_redirect_url', true);
        $badge_style = !empty($current_target) ? 'color:#10b981;font-weight:700;' : 'color:#64748b;font-weight:600;';
        $label = !empty($current_target) ? 'Redirect Active ➔' : 'Redirect To';

        $actions['bx_redirect_to'] = sprintf(
            '<a href="#" class="bx-row-redirect-trigger" data-post-id="%d" data-current-url="%s" style="%s">%s</a>',
            $post->ID,
            esc_attr($current_target),
            $badge_style,
            esc_html($label)
        );
    }
    return $actions;
}

add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->base, ['edit'])) return;
    ?>
    <script>
    jQuery(document).ready(function($) {
        $(document).on('click', '.bx-row-redirect-trigger', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var post_id = $btn.data('post-id');
            var current_url = $btn.data('current-url') || '';

            var new_url = window.prompt("Enter Target URL (Leave empty to remove redirect):", current_url);
            if (new_url === null) return;

            $btn.text('Saving...');

            $.post(ajaxurl, {
                action: 'bx_save_row_redirect',
                post_id: post_id,
                redirect_url: new_url,
                nonce: '<?php echo esc_js(wp_create_nonce('bx_save_redirect_nonce')); ?>'
            }, function(response) {
                if (response.success) {
                    if (response.data.url) {
                        $btn.attr('data-current-url', response.data.url);
                        $btn.text('Redirect Active ➔').css({'color':'#10b981', 'font-weight':'700'});
                    } else {
                        $btn.attr('data-current-url', '');
                        $btn.text('Redirect To').css({'color':'#64748b', 'font-weight':'600'});
                    }
                } else {
                    alert((response.data && response.data.message) ? response.data.message : 'Error saving redirect.');
                }
            });
        });
    });
    </script>
    <?php
});

function bx_validate_redirect_target($raw_url, $post_id) {
    $raw_url = trim((string) wp_unslash($raw_url));
    if ($raw_url === '') return '';
    $clean = esc_url_raw($raw_url, ['http','https']);
    if (!$clean) return new WP_Error('invalid_url', 'Enter a valid HTTP or HTTPS URL.');

    $target_abs = $clean;
    if (strpos($clean, '/') === 0) $target_abs = home_url($clean);
    $source = get_permalink($post_id);
    if ($source && untrailingslashit($target_abs) === untrailingslashit($source)) {
        return new WP_Error('self_redirect', 'A page cannot redirect to itself.');
    }

    $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $target_host = strtolower((string) wp_parse_url($target_abs, PHP_URL_HOST));
    if ($target_host && $home_host && $target_host !== $home_host && !current_user_can('manage_options')) {
        return new WP_Error('external_redirect_forbidden', 'Only administrators can create external redirects.');
    }
    return $clean;
}

add_action('wp_ajax_bx_save_row_redirect', function() {
    check_ajax_referer('bx_save_redirect_nonce', 'nonce');
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error();

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and sanitised in bx_validate_redirect_target().
    $result = bx_validate_redirect_target(wp_unslash($_POST['redirect_url'] ?? ''), $post_id);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
    if ($result !== '') {
        update_post_meta($post_id, '_bx_redirect_url', $result);
        wp_send_json_success(['url' => $result]);
    } else {
        delete_post_meta($post_id, '_bx_redirect_url');
        delete_post_meta($post_id, '_bx_redirect_hits');
        wp_send_json_success(['url' => '']);
    }
});

add_action('add_meta_boxes', function() {
    foreach (['post', 'page'] as $screen) {
        add_meta_box('bx_redirect_metabox', 'Brandix Page Redirect', function($post) {
            wp_nonce_field('bx_meta_redirect_save', 'bx_redirect_nonce');
            $val = get_post_meta($post->ID, '_bx_redirect_url', true);
            ?>
            <p style="margin-bottom:6px;font-size:12px;color:#64748b;">Target URL for 301-redirect (Active for Published, Draft & Trashed):</p>
            <input type="url" name="bx_redirect_target_url" value="<?php echo esc_attr($val); ?>" placeholder="https://example.com/target" style="width:100%;border-radius:4px;border:1px solid #cbd5e1;padding:6px 8px;">
            <?php
        }, $screen, 'side', 'default');
    }
});

add_action('save_post', function($post_id) {
    if (!isset($_POST['bx_redirect_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bx_redirect_nonce'])), 'bx_meta_redirect_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['bx_redirect_target_url'])) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and sanitised in bx_validate_redirect_target().
        $result = bx_validate_redirect_target(wp_unslash($_POST['bx_redirect_target_url']), $post_id);
        if (is_wp_error($result)) {
            add_filter('redirect_post_location', function($location) use ($result) {
                return add_query_arg('bx_redirect_error', rawurlencode($result->get_error_message()), $location);
            });
        } elseif ($result !== '') {
            update_post_meta($post_id, '_bx_redirect_url', $result);
        } else {
            delete_post_meta($post_id, '_bx_redirect_url');
            delete_post_meta($post_id, '_bx_redirect_hits');
        }
    }
});

add_action('admin_notices', function() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only error message.
    if (!empty($_GET['bx_redirect_error'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bx_redirect_error']))) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only error message.
    }
});


function bx_powerkit_cleanup_logs() {
    global $wpdb;
    $o = get_option('sb_options', []);
    $days = in_array((int)($o['log_retention_days'] ?? 90), [30,60,90], true) ? (int)$o['log_retention_days'] : 90;
    $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_email_logs WHERE sent_at < %s", $cutoff));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_user_activity WHERE login_time < %s", $cutoff));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_audit_activity WHERE action_time < %s", $cutoff));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_404_log WHERE last_seen < %s", $cutoff));

    // Keep the 404 log small: only the 5,000 most recent URLs.
    $keep_from = $wpdb->get_var("SELECT last_seen FROM {$wpdb->prefix}bx_404_log ORDER BY last_seen DESC LIMIT 1 OFFSET 5000");
    if ($keep_from) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_404_log WHERE last_seen <= %s", $keep_from));

    // Consent records are proof of consent, so they are kept for at least a year.
    $consent_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - (max($days, 365) * DAY_IN_SECONDS));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bx_consent_log WHERE created_at < %s", $consent_cutoff));
}
add_action('bx_powerkit_daily_cleanup', 'bx_powerkit_cleanup_logs');
add_action('init', function() {
    if (!wp_next_scheduled('bx_powerkit_daily_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bx_powerkit_daily_cleanup');
});
register_deactivation_hook(__FILE__, function() {
    $ts = wp_next_scheduled('bx_powerkit_daily_cleanup');
    if ($ts) wp_unschedule_event($ts, 'bx_powerkit_daily_cleanup');
});
