<?php
/**
 * Brandix PowerKit uninstall cleanup.
 * Runs only when the plugin is DELETED from wp-admin (not on deactivate).
 * Removes plugin tables, options, OAuth tokens, redirect meta, cron and the uploads .htaccess rule.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin keeps its logs and redirects in its own custom tables; there is no WordPress API for them and results must be live, so they are dropped directly.

function bx_powerkit_uninstall_site() {
    global $wpdb;

    foreach (['bx_email_logs', 'bx_user_activity', 'bx_audit_activity', 'bx_redirects', 'bx_404_log', 'bx_consent_log'] as $t) {
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $wpdb->prefix . $t)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own tables on uninstall.
    }

    foreach (['sb_options', 'bx_powerkit_db_version', 'bx_mail_tokens_google', 'bx_mail_tokens_outlook',
              'bx_mail_refresh_lock_google', 'bx_mail_refresh_lock_outlook',
              'bx_redirects_compiled', 'bx_cookie_options', 'bx_image_stats'] as $opt) {
        delete_option($opt);
    }

    // Redirect targets and hit counters stored on posts/pages.
    delete_post_meta_by_key('_bx_redirect_url');
    delete_post_meta_by_key('_bx_redirect_hits');

    wp_clear_scheduled_hook('bx_powerkit_daily_cleanup');

    // Remove only our managed block from uploads/.htaccess; keep anything else in the file.
    $upload_dir = wp_upload_dir();
    if (empty($upload_dir['error']) && !empty($upload_dir['basedir'])) {
        $path = trailingslashit($upload_dir['basedir']) . '.htaccess';
        if (file_exists($path)) {
            $content = (string) @file_get_contents($path);
            $content = preg_replace('/\n?# BEGIN Brandix PowerKit.*?# END Brandix PowerKit\n?/s', "\n", $content);
            $content = trim($content);
            if ($content === '') wp_delete_file($path);
            else @file_put_contents($path, $content . "\n", LOCK_EX);
        }
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog($site_id);
        bx_powerkit_uninstall_site();
        restore_current_blog();
    }
} else {
    bx_powerkit_uninstall_site();
}
