<?php
/**
 * Brandix PowerKit — custom 404 page.
 * Shown for real 404s when "Custom 404 Page" is on. "Redirect 404s to Homepage" runs earlier and wins.
 */
if (!defined('ABSPATH')) exit;

add_filter('template_include', function ($template) {
    $o = get_option('sb_options', []);
    if (empty($o['enable_404_page']) || !is_404()) return $template;
    return __DIR__ . '/templates/404.php';
}, 99);
