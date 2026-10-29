<?php
/**
 * Brandix PowerKit — 404 template.
 */
if (!defined('ABSPATH')) exit;

$o      = get_option('sb_options', []);
$title  = $o['p404_title']  ?? 'Page not found';
$text   = $o['p404_text']   ?? "Sorry, the page you're looking for doesn't exist or has been moved.";
$button = $o['p404_button'] ?? 'Back to Home';
$color  = sanitize_hex_color($o['p404_color'] ?? '') ?: '#0074FD';
$search = !empty($o['p404_search']);
// Classic themes can wrap the page in their header/footer; block themes use the standalone page.
$in_theme = ($o['p404_layout'] ?? 'theme') === 'theme' && !(function_exists('wp_is_block_theme') && wp_is_block_theme());

if ($in_theme) {
    get_header();
} else { ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bx-404-standalone'); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<?php } ?>
<style>
    .bx-404 { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; padding: 80px 20px; text-align: center; font-family: inherit; }
    body.bx-404-standalone { margin: 0; background: #f8fafc; }
    body.bx-404-standalone .bx-404 { min-height: 100vh; }
    .bx-404-logo { margin-bottom: 36px; }
    .bx-404-logo img { max-height: 56px; width: auto; }
    .bx-404-logo a { color: #0f172a; font-size: 20px; font-weight: 700; text-decoration: none; }
    .bx-404-code { margin: 0; font-size: 120px; font-weight: 800; line-height: 1; letter-spacing: -4px; color: <?php echo esc_attr($color); ?>; }
    .bx-404 h1 { margin: 18px 0 10px; font-size: 30px; line-height: 1.25; color: #0f172a; }
    .bx-404 p { max-width: 460px; margin: 0 auto 28px; font-size: 16px; line-height: 1.6; color: #64748b; }
    .bx-404-btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px; border-radius: 999px; background: <?php echo esc_attr($color); ?>; color: #ffffff !important; font-size: 15px; font-weight: 600; text-decoration: none !important; transition: transform .2s ease, box-shadow .2s ease; }
    .bx-404-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0, 0, 0, .15); }
    .bx-404-search { width: 100%; max-width: 420px; margin-top: 28px; }
    @media (max-width: 600px) { .bx-404-code { font-size: 86px; } .bx-404 h1 { font-size: 24px; } }
</style>
<main class="bx-404" role="main">
    <?php if (!$in_theme): ?>
        <div class="bx-404-logo">
            <?php if (function_exists('has_custom_logo') && has_custom_logo()) { the_custom_logo(); } else { ?>
                <a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a>
            <?php } ?>
        </div>
    <?php endif; ?>
    <div class="bx-404-code">404</div>
    <h1><?php echo esc_html($title); ?></h1>
    <p><?php echo esc_html($text); ?></p>
    <a class="bx-404-btn" href="<?php echo esc_url(home_url('/')); ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <?php echo esc_html($button); ?>
    </a>
    <?php if ($search): ?><div class="bx-404-search"><?php get_search_form(); ?></div><?php endif; ?>
</main>
<?php
if ($in_theme) {
    get_footer();
} else {
    wp_footer();
    echo '</body></html>';
}
