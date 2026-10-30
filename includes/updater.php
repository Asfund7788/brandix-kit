<?php
/**
 * Brandix PowerKit — self-updater from GitHub Releases.
 *
 * Every site checks the GitHub repo set in Brandix Kit → Updates (or the BX_POWERKIT_REPO constant).
 * When a newer release exists, WordPress shows the normal "Update now" notice on the Plugins screen.
 * The release must carry a zip asset (brandix-sticky.zip) whose top folder is "brandix-sticky".
 */
if (!defined('ABSPATH')) exit;

/** "owner/repo" to check, or '' when not set. */
function bx_upd_repo() {
    $repo = defined('BX_POWERKIT_REPO') ? BX_POWERKIT_REPO : (get_option('sb_options', [])['update_repo'] ?? '');
    $repo = trim((string) $repo);
    if ($repo === '') $repo = 'Asfund7788/brandix-kit'; // default update source
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : '';
}

/** Latest release info from GitHub, cached for 6 hours. Returns null when unavailable. */
function bx_upd_latest($force = false) {
    $repo = bx_upd_repo();
    if (!$repo) return null;
    $key = 'bx_upd_' . md5($repo);
    if (!$force) {
        $cached = get_site_transient($key);
        if (is_array($cached)) return $cached['version'] ? $cached : null;
    }
    $res = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Brandix-Kit-Updater'],
    ]);
    $info = ['version' => '', 'zip' => '', 'url' => 'https://github.com/' . $repo, 'notes' => '', 'date' => '', 'error' => ''];
    if (is_wp_error($res)) {
        $info['error'] = $res->get_error_message();
    } elseif (wp_remote_retrieve_response_code($res) !== 200) {
        $info['error'] = 'GitHub returned ' . wp_remote_retrieve_response_code($res) . ' (no published release yet, or wrong repo name).';
    } else {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $zip = '';
        foreach ((array) ($data['assets'] ?? []) as $a) {
            if (substr($a['name'] ?? '', -4) !== '.zip') continue;
            if ($a['name'] === 'brandix-sticky.zip' || !$zip) $zip = $a['browser_download_url'];
        }
        if ($zip) {
            $info['version'] = ltrim((string) ($data['tag_name'] ?? ''), 'vV');
            $info['zip']     = $zip;
            $info['url']     = $data['html_url'] ?? $info['url'];
            $info['notes']   = (string) ($data['body'] ?? '');
            $info['date']    = (string) ($data['published_at'] ?? '');
        } else {
            $info['error'] = 'The latest release has no .zip file attached.';
        }
    }
    // Cache failures for 1 hour so a GitHub outage doesn't slow down wp-admin.
    set_site_transient($key, $info, $info['version'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    update_option('bx_upd_last_check', ['time' => time(), 'version' => $info['version'], 'error' => $info['error']], false);
    return $info['version'] ? $info : null;
}

function bx_upd_basename() { return plugin_basename(BX_POWERKIT_FILE); }

/** Tell WordPress about a newer version. */
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $rel = bx_upd_latest();
    if (!$rel) return $transient;
    $base = bx_upd_basename();
    $item = (object) [
        'id'          => $base,
        'slug'        => 'brandix-sticky',
        'plugin'      => $base,
        'new_version' => $rel['version'],
        'url'         => $rel['url'],
        'package'     => $rel['zip'],
        'icons'       => [],
        'banners'     => [],
        'requires'    => '6.2',
        'requires_php'=> '7.4',
    ];
    $current = $transient->checked[$base] ?? BX_POWERKIT_DB_VERSION;
    if (version_compare($rel['version'], $current, '>')) {
        $transient->response[$base] = $item;
        unset($transient->no_update[$base]);
    } else {
        $transient->no_update[$base] = $item;
        unset($transient->response[$base]);
    }
    return $transient;
});

/** "View details" popup on the Plugins screen. */
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'brandix-sticky') return $result;
    $rel = bx_upd_latest();
    if (!$rel) return $result;
    $notes = $rel['notes'] !== '' ? nl2br(esc_html($rel['notes'])) : 'No release notes.';
    return (object) [
        'name'          => 'Brandix PowerKit',
        'slug'          => 'brandix-sticky',
        'version'       => $rel['version'],
        'author'        => 'Brandix Soft',
        'homepage'      => $rel['url'],
        'download_link' => $rel['zip'],
        'requires'      => '6.2',
        'requires_php'  => '7.4',
        'last_updated'  => $rel['date'],
        'sections'      => ['changelog' => '<h4>' . esc_html($rel['version']) . '</h4><p>' . $notes . '</p>'],
    ];
}, 10, 3);

/** Make sure the unzipped folder is always "brandix-sticky", so the plugin stays active after updating. */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = []) {
    if (($hook_extra['plugin'] ?? '') !== bx_upd_basename()) return $source;
    $want = trailingslashit($remote_source) . 'brandix-sticky/';
    if (untrailingslashit($source) === untrailingslashit($want)) return $source;
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $want, true)) return $want;
    return $source;
}, 10, 4);

/** After an update, forget the cached release so the next check is fresh. */
add_action('upgrader_process_complete', function () {
    $repo = bx_upd_repo();
    if ($repo) delete_site_transient('bx_upd_' . md5($repo));
});

/** "Check for updates" link on the Plugins screen. */
add_filter('plugin_action_links_' . plugin_basename(BX_POWERKIT_FILE), function ($links) {
    if (!bx_upd_repo() || !current_user_can('update_plugins')) return $links;
    $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=bx_upd_check'), 'bx_upd_check')) . '">Check for updates</a>';
    return $links;
});

add_action('admin_post_bx_upd_check', function () {
    if (!current_user_can('update_plugins')) wp_die('Access denied.');
    check_admin_referer('bx_upd_check');
    bx_upd_latest(true);
    delete_site_transient('update_plugins');
    wp_update_plugins();
    $back = wp_get_referer() ?: admin_url('plugins.php');
    wp_safe_redirect(add_query_arg('bx_upd_checked', 1, $back));
    exit;
});
