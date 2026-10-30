<?php
/**
 * Brandix PowerKit — Mobile Bottom Menu module.
 *
 * - 5 links: 2 left, 1 centre circle, 2 right. Labels, links and icons are editable.
 * - 8 ready-made designs; every colour and size can be changed afterwards.
 * - Scroll behaviour: always / show on scroll down / hide on scroll down / show after X px.
 * - Mobile only (optionally tablet too). Current page is highlighted automatically.
 */
if (!defined('ABSPATH')) exit;

function bx_bnav_enabled() {
    return !empty(get_option('sb_options', [])['enable_bottom_nav']);
}

/* ------------------------------------------------------------------------- *
 * Icons (outline, 24x24)
 * ------------------------------------------------------------------------- */
function bx_bnav_icons() {
    return [
        'home'       => ['Home', '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
        'landmark'   => ['Building / Solutions', '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>'],
        'briefcase'  => ['Briefcase / Services', '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'],
        'calculator' => ['Calculator', '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01"/><path d="M12 10h.01"/><path d="M8 10h.01"/><path d="M12 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M8 18h.01"/>'],
        'info'       => ['Info / About', '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'],
        'users'      => ['Team', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'user'       => ['Account', '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
        'mail'       => ['Email / Contact', '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'],
        'phone'      => ['Phone', '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'],
        'chat'       => ['Chat', '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>'],
        'whatsapp'   => ['WhatsApp', '__whatsapp__'],
        'calendar'   => ['Calendar / Booking', '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>'],
        'cart'       => ['Cart', '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>'],
        'tag'        => ['Tag / Offers', '<path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><path d="M7 7h.01"/>'],
        'search'     => ['Search', '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'],
        'grid'       => ['Grid / Categories', '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'],
        'menu'       => ['Menu', '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>'],
        'file'       => ['Document / Blog', '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>'],
        'image'      => ['Gallery', '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>'],
        'star'       => ['Star / Reviews', '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'],
        'heart'      => ['Heart', '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>'],
        'map-pin'    => ['Location', '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>'],
        'car'        => ['Car', '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>'],
        'wrench'     => ['Wrench / Repairs', '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'],
        'shield'     => ['Shield / Security', '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-.76c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'],
    ];
}

/** Full <svg> for an icon key. */
function bx_bnav_icon_svg($key) {
    $icons = bx_bnav_icons();
    $inner = $icons[$key][1] ?? $icons['home'][1];
    if ($inner === '__whatsapp__') {
        // WhatsApp is a filled logo.
        return '<svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M16 3C8.82 3 3 8.82 3 16c0 2.47.69 4.88 2 6.98L3 30l7.25-1.9c2.04 1.19 4.38 1.9 6.75 1.9 7.18 0 13-5.82 13-13S24.18 3 16 3zm0 23.63c-2.14 0-4.22-.61-6-1.76l-.43-.27-4.4 1.15 1.17-4.28-.28-.45A10.59 10.59 0 0 1 5.37 16c0-5.87 4.77-10.63 10.63-10.63 5.87 0 10.63 4.77 10.63 10.63 0 5.87-4.76 10.63-10.63 10.63zm5.83-7.97c-.32-.16-1.9-.94-2.19-1.04-.3-.11-.51-.16-.73.16-.21.32-.83 1.04-1.01 1.26-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.58-1.59-.95-.85-1.6-1.9-1.78-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.75-1-2.4-.26-.63-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.85.4-.3.32-1.12 1.1-1.12 2.68 0 1.59 1.15 3.12 1.31 3.33.16.21 2.27 3.47 5.5 4.86.77.33 1.37.53 1.83.68.77.25 1.47.21 2.03.13.62-.09 1.9-.78 2.17-1.53.27-.75.27-1.39.19-1.53-.08-.13-.29-.21-.61-.37z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/* ------------------------------------------------------------------------- *
 * Ready-made designs
 * ------------------------------------------------------------------------- */
function bx_bnav_presets() {
    return [
        'navy-gold' => ['name' => 'Navy & Gold (floating)', 'layout' => 'floating', 'bar_bg' => '#0b3a63', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#ffffff', 'label_color' => '#ffffff', 'active_color' => '#f5b335', 'center_bg' => '#f5b335', 'center_icon' => '#0b3a63',
            'center_style' => 'raised', 'ring_color' => '#0b3a63', 'indicator' => 'none', 'radius' => 22, 'show_labels' => 1, 'height' => 64],
        'classic-white' => ['name' => 'Classic White (full width)', 'layout' => 'full', 'bar_bg' => '#ffffff', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#64748b', 'label_color' => '#64748b', 'active_color' => '#2563eb', 'center_bg' => '#2563eb', 'center_icon' => '#ffffff',
            'center_style' => 'raised', 'ring_color' => '#ffffff', 'indicator' => 'line', 'radius' => 0, 'show_labels' => 1, 'height' => 64],
        'notch' => ['name' => 'Curved Notch', 'layout' => 'full', 'bar_bg' => '#ffffff', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#94a3b8', 'label_color' => '#94a3b8', 'active_color' => '#111827', 'center_bg' => '#111827', 'center_icon' => '#ffffff',
            'center_style' => 'notch', 'ring_color' => '', 'indicator' => 'dot', 'radius' => 0, 'show_labels' => 1, 'height' => 64],
        'dark-glass' => ['name' => 'Dark Glass', 'layout' => 'floating', 'bar_bg' => '#0f172a', 'bar_bg2' => '', 'bar_opacity' => 82, 'blur' => 1,
            'icon_color' => '#cbd5e1', 'label_color' => '#cbd5e1', 'active_color' => '#38bdf8', 'center_bg' => '#38bdf8', 'center_icon' => '#0f172a',
            'center_style' => 'raised', 'ring_color' => '', 'indicator' => 'dot', 'radius' => 24, 'show_labels' => 1, 'height' => 64],
        'gradient' => ['name' => 'Gradient Pop', 'layout' => 'floating', 'bar_bg' => '#7c3aed', 'bar_bg2' => '#db2777', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#ede9fe', 'label_color' => '#ede9fe', 'active_color' => '#ffffff', 'center_bg' => '#ffffff', 'center_icon' => '#7c3aed',
            'center_style' => 'raised', 'ring_color' => '', 'indicator' => 'dot', 'radius' => 22, 'show_labels' => 1, 'height' => 64],
        'icons-pill' => ['name' => 'Icons Only Pill', 'layout' => 'floating', 'bar_bg' => '#111827', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#9ca3af', 'label_color' => '#9ca3af', 'active_color' => '#ffffff', 'center_bg' => '#22c55e', 'center_icon' => '#ffffff',
            'center_style' => 'inline', 'ring_color' => '', 'indicator' => 'dot', 'radius' => 999, 'show_labels' => 0, 'height' => 62],
        'soft-light' => ['name' => 'Soft Light', 'layout' => 'floating', 'bar_bg' => '#f1f5f9', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#475569', 'label_color' => '#475569', 'active_color' => '#0f766e', 'center_bg' => '#0f766e', 'center_icon' => '#ffffff',
            'center_style' => 'raised', 'ring_color' => '#f1f5f9', 'indicator' => 'pill', 'radius' => 20, 'show_labels' => 1, 'height' => 66],
        'emerald' => ['name' => 'Emerald & Amber', 'layout' => 'full', 'bar_bg' => '#064e3b', 'bar_bg2' => '', 'bar_opacity' => 100, 'blur' => 0,
            'icon_color' => '#a7f3d0', 'label_color' => '#a7f3d0', 'active_color' => '#fbbf24', 'center_bg' => '#fbbf24', 'center_icon' => '#064e3b',
            'center_style' => 'notch', 'ring_color' => '', 'indicator' => 'line', 'radius' => 0, 'show_labels' => 1, 'height' => 64],
    ];
}

function bx_bnav_defaults() {
    $preset = bx_bnav_presets()['navy-gold'];
    unset($preset['name']);
    return array_merge([
        'preset'      => 'navy-gold',
        'scroll'      => 'always',
        'scroll_px'   => 200,
        'breakpoint'  => 'mobile',
        'body_space'  => 1,
        'hide_urls'   => '',
        'label_size'  => 11,
        'center_size' => 58,
        'items'       => [
            ['label' => 'Services', 'url' => '/services/', 'icon' => 'briefcase', 'custom_icon' => '', 'new_tab' => 0],
            ['label' => 'About',    'url' => '/about/',    'icon' => 'info',      'custom_icon' => '', 'new_tab' => 0],
            ['label' => '',         'url' => '/',          'icon' => 'home',      'custom_icon' => '', 'new_tab' => 0],
            ['label' => 'Contact',  'url' => '/contact/',  'icon' => 'mail',      'custom_icon' => '', 'new_tab' => 0],
            ['label' => 'Call',     'url' => 'tel:+440000000000', 'icon' => 'phone', 'custom_icon' => '', 'new_tab' => 0],
        ],
    ], $preset);
}

function bx_bnav_options() {
    $d = bx_bnav_defaults();
    $o = get_option('bx_bottom_nav', []);
    $o = is_array($o) ? array_merge($d, array_intersect_key($o, $d)) : $d;
    $items = [];
    for ($i = 0; $i < 5; $i++) $items[$i] = array_merge($d['items'][$i], (array) ($o['items'][$i] ?? []));
    $o['items'] = $items;
    return $o;
}

/* ------------------------------------------------------------------------- *
 * Settings
 * ------------------------------------------------------------------------- */
add_action('admin_init', function () {
    register_setting('bx_bnav_group', 'bx_bottom_nav', ['sanitize_callback' => 'bx_bnav_sanitize']);
});

function bx_bnav_sanitize($in) {
    $d = bx_bnav_defaults();
    $in = (array) $in;
    $pick = function ($k, $allowed) use ($in, $d) { $v = $in[$k] ?? ''; return in_array($v, $allowed, true) ? $v : $d[$k]; };
    $hex = function ($k, $allow_empty = false) use ($in, $d) {
        if ($allow_empty && !empty($in[$k . '_off'])) return ''; // optional colour switched off
        $v = sanitize_hex_color($in[$k] ?? '');
        if ($v) return $v;
        return $allow_empty ? '' : $d[$k];
    };
    $num = function ($k, $min, $max) use ($in, $d) { return max($min, min($max, (int) ($in[$k] ?? $d[$k]))); };

    $out = [
        'preset'       => $pick('preset', array_merge(array_keys(bx_bnav_presets()), ['custom'])),
        'layout'       => $pick('layout', ['floating', 'full']),
        'bar_bg'       => $hex('bar_bg'),
        'bar_bg2'      => $hex('bar_bg2', true),
        'bar_opacity'  => $num('bar_opacity', 30, 100),
        'blur'         => !empty($in['blur']) ? 1 : 0,
        'icon_color'   => $hex('icon_color'),
        'label_color'  => $hex('label_color'),
        'active_color' => $hex('active_color'),
        'center_bg'    => $hex('center_bg'),
        'center_icon'  => $hex('center_icon'),
        'center_style' => $pick('center_style', ['raised', 'notch', 'inline']),
        'ring_color'   => $hex('ring_color', true),
        'indicator'    => $pick('indicator', ['none', 'dot', 'line', 'pill']),
        'radius'       => $num('radius', 0, 999),
        'show_labels'  => !empty($in['show_labels']) ? 1 : 0,
        'height'       => $num('height', 50, 90),
        'label_size'   => $num('label_size', 9, 15),
        'center_size'  => $num('center_size', 44, 76),
        'scroll'       => $pick('scroll', ['always', 'show_on_down', 'hide_on_down', 'after_px']),
        'scroll_px'    => $num('scroll_px', 0, 5000),
        'breakpoint'   => $pick('breakpoint', ['mobile', 'tablet']),
        'body_space'   => !empty($in['body_space']) ? 1 : 0,
        'hide_urls'    => sanitize_textarea_field($in['hide_urls'] ?? ''),
        'items'        => [],
    ];
    $icons = array_keys(bx_bnav_icons());
    for ($i = 0; $i < 5; $i++) {
        $it = (array) ($in['items'][$i] ?? []);
        $out['items'][$i] = [
            'label'       => sanitize_text_field($it['label'] ?? ''),
            'url'         => esc_url_raw(trim($it['url'] ?? ''), ['http', 'https', 'tel', 'mailto', 'sms']),
            'icon'        => in_array($it['icon'] ?? '', $icons, true) ? $it['icon'] : $d['items'][$i]['icon'],
            'custom_icon' => esc_url_raw(trim($it['custom_icon'] ?? '')),
            'new_tab'     => !empty($it['new_tab']) ? 1 : 0,
        ];
        // Relative links like "/contact/" are allowed: esc_url_raw keeps them as-is.
        if ($out['items'][$i]['url'] === '' && !empty($it['url']) && strpos(trim($it['url']), '/') === 0) {
            $out['items'][$i]['url'] = esc_url_raw(home_url(trim($it['url'])));
        }
    }
    return $out;
}

/* ------------------------------------------------------------------------- *
 * Rendering (shared by the site and the admin previews)
 * ------------------------------------------------------------------------- */
function bx_bnav_css($s, $sel, $preview = false) {
    $H   = (int) $s['height'];
    $cs  = (int) $s['center_size'];
    $op  = max(30, min(100, (int) $s['bar_opacity'])) / 100;
    $float = $s['layout'] === 'floating';
    $radius = $float ? min((int) $s['radius'], 999) : 0;
    $style = $s['center_style'];

    $bg = $s['bar_bg2']
        ? 'linear-gradient(90deg,' . bx_hex_rgba($s['bar_bg'], $op) . ',' . bx_hex_rgba($s['bar_bg2'], $op) . ')'
        : bx_hex_rgba($s['bar_bg'], $op);

    $pos = $preview ? 'position:absolute;' : 'position:fixed;';
    $place = $float
        ? 'left:12px;right:12px;bottom:' . ($preview ? '12px' : 'calc(12px + env(safe-area-inset-bottom))') . ';'
        : 'left:0;right:0;bottom:0;';

    $css  = $sel . '{' . $pos . $place . 'z-index:99990;transition:transform .35s cubic-bezier(.22,1,.36,1),opacity .35s ease;filter:drop-shadow(0 8px 22px rgba(0,0,0,.18));font-family:inherit;-webkit-font-smoothing:antialiased}';
    $css .= $sel . '.bx-bn-hidden{transform:translateY(calc(100% + 40px));opacity:0;pointer-events:none}';
    $css .= $sel . ' .bx-bn-bar{position:absolute;top:0;right:0;bottom:0;left:0;border-radius:' . $radius . 'px;background:' . $bg . ';' . ($s['blur'] ? '-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);' : '') . '}';
    if ($style === 'notch') {
        $r = round($cs / 2) + 8;
        $mask = 'radial-gradient(circle at 50% 0,transparent ' . $r . 'px,#000 ' . ($r + 1) . 'px)';
        $css .= $sel . ' .bx-bn-bar{-webkit-mask:' . $mask . ';mask:' . $mask . '}';
    }
    if (!$float && !$preview) $css .= $sel . ' .bx-bn-items{padding-bottom:env(safe-area-inset-bottom)}';
    $css .= $sel . ' .bx-bn-items{position:relative;display:grid;grid-template-columns:repeat(5,1fr);height:' . $H . 'px;align-items:center;margin:0;padding-left:4px;padding-right:4px}';
    $css .= $sel . ' .bx-bn-item{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;height:100%;min-width:0;color:' . $s['icon_color'] . ';text-decoration:none!important;-webkit-tap-highlight-color:transparent;outline-offset:-4px}';
    $css .= $sel . ' .bx-bn-ico{display:flex;align-items:center;justify-content:center;width:22px;height:22px;transition:transform .2s ease,background .2s ease}';
    $css .= $sel . ' .bx-bn-ico svg,' . $sel . ' .bx-bn-ico img{width:100%;height:100%;display:block}';
    $css .= $sel . ' .bx-bn-mask{display:block;width:100%;height:100%;background:currentColor}';
    $css .= $sel . ' .bx-bn-item:active .bx-bn-ico{transform:scale(.88)}';
    $css .= $sel . ' .bx-bn-lbl{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:' . (int) $s['label_size'] . 'px;font-weight:600;line-height:1.15;letter-spacing:.1px;color:' . $s['label_color'] . '}';
    $css .= $sel . ' .bx-bn-item.is-active,' . $sel . ' .bx-bn-item.is-active .bx-bn-lbl{color:' . $s['active_color'] . '}';

    // Active indicator
    if ($s['indicator'] === 'dot') {
        $css .= $sel . ' .bx-bn-item.is-active:not(.bx-bn-center)::after{content:"";position:absolute;bottom:' . ($s['show_labels'] ? 3 : 9) . 'px;width:5px;height:5px;border-radius:50%;background:' . $s['active_color'] . '}';
    } elseif ($s['indicator'] === 'line') {
        $css .= $sel . ' .bx-bn-item.is-active:not(.bx-bn-center)::before{content:"";position:absolute;top:0;width:28px;height:3px;border-radius:0 0 3px 3px;background:' . $s['active_color'] . '}';
    } elseif ($s['indicator'] === 'pill') {
        $css .= $sel . ' .bx-bn-item.is-active:not(.bx-bn-center) .bx-bn-ico{width:22px;padding:4px 14px;border-radius:999px;background:' . bx_hex_rgba($s['active_color'], .14) . '}';
    }

    // Centre circle
    $ring = ($s['ring_color'] && $style !== 'notch') ? 'border:4px solid ' . $s['ring_color'] . ';' : 'border:0;';
    $circle = 'display:flex;align-items:center;justify-content:center;width:' . $cs . 'px;height:' . $cs . 'px;box-sizing:border-box;border-radius:50%;' . $ring . 'background:' . $s['center_bg'] . ';color:' . $s['center_icon'] . ';box-shadow:0 6px 16px rgba(0,0,0,.22);transition:transform .2s ease';
    if ($style === 'inline') {
        $size = min($cs, $H - 12);
        $css .= $sel . ' .bx-bn-circle{' . $circle . ';width:' . $size . 'px;height:' . $size . 'px;box-shadow:none}';
        $css .= $sel . ' .bx-bn-center .bx-bn-lbl{display:none}';
    } else {
        $top = $style === 'notch' ? -round($cs / 2) : -round($cs * 0.42);
        $css .= $sel . ' .bx-bn-circle{' . $circle . ';position:absolute;left:50%;top:' . $top . 'px;transform:translateX(-50%)}';
        $css .= $sel . ' .bx-bn-center .bx-bn-lbl{position:absolute;bottom:' . max(4, round($H * 0.12)) . 'px;left:0;right:0;text-align:center}';
        $css .= $sel . ' .bx-bn-center:active .bx-bn-circle{transform:translateX(-50%) scale(.94)}';
    }
    $css .= $sel . ' .bx-bn-circle .bx-bn-ico{width:' . round($cs * 0.42) . 'px;height:' . round($cs * 0.42) . 'px}';
    if (!$s['show_labels']) $css .= $sel . ' .bx-bn-lbl{display:none}';
    $css .= '@media (prefers-reduced-motion:reduce){' . $sel . ',' . $sel . ' *{transition:none!important}}';
    return $css;
}

/** Is this item the current page? */
function bx_bnav_is_active($url) {
    if (!$url || preg_match('#^(tel|mailto|sms):#i', $url)) return false;
    $p = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    if (!empty($p['host']) && !empty($home['host']) && strtolower($p['host']) !== strtolower($home['host'])) return false;
    $item_path = trailingslashit(strtolower($p['path'] ?? '/'));
    $cur_path  = trailingslashit(strtolower((string) wp_parse_url(bx_request_uri() ?: '/', PHP_URL_PATH)));
    return $item_path === $cur_path;
}

function bx_bnav_item_icon($it) {
    if (!empty($it['custom_icon'])) {
        $u = esc_url($it['custom_icon']);
        if (preg_match('/\.svg($|\?)/i', $it['custom_icon'])) {
            // SVG is used as a mask so it takes the menu's icon colour.
            return '<span class="bx-bn-mask" style="-webkit-mask:url(' . $u . ') center/contain no-repeat;mask:url(' . $u . ') center/contain no-repeat"></span>';
        }
        return '<img src="' . $u . '" alt="" loading="lazy">';
    }
    return bx_bnav_icon_svg($it['icon']);
}

/** Returns <style> + <nav>. $active_index forces the highlighted item (previews). */
function bx_bnav_render($s, $id, $preview = false, $active_index = null) {
    $classes = ['bx-bn'];
    if (!$preview && in_array($s['scroll'], ['show_on_down', 'after_px'], true)) $classes[] = 'bx-bn-hidden';
    $html  = '<style>' . bx_bnav_css($s, '#' . $id, $preview) . '</style>';
    $html .= '<nav id="' . esc_attr($id) . '" class="' . esc_attr(implode(' ', $classes)) . '" aria-label="Quick navigation"'
          . ($preview ? '' : ' data-scroll="' . esc_attr($s['scroll']) . '" data-px="' . (int) $s['scroll_px'] . '"') . '>';
    $html .= '<div class="bx-bn-bar"></div><div class="bx-bn-items">';
    foreach ($s['items'] as $i => $it) {
        $is_center = $i === 2;
        $label = $it['label'] !== '' ? $it['label'] : ($is_center ? '' : '');
        if (empty($it['url']) && !$preview) { $html .= '<span class="bx-bn-item" aria-hidden="true"></span>'; continue; }
        $active = $active_index !== null ? ($active_index === $i) : bx_bnav_is_active($it['url']);
        $cls = 'bx-bn-item' . ($is_center ? ' bx-bn-center' : '') . ($active ? ' is-active' : '');
        $target = (!$preview && !empty($it['new_tab'])) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $aria = $label !== '' ? $label : bx_bnav_icons()[$it['icon']][0] ?? 'Link';
        $href = $preview ? '#' : esc_url($it['url']);
        $html .= '<a class="' . $cls . '" href="' . $href . '"' . $target . ($active ? ' aria-current="page"' : '') . ' aria-label="' . esc_attr($aria) . '"' . ($preview ? ' tabindex="-1" onclick="return false"' : '') . '>';
        $icon = '<span class="bx-bn-ico">' . bx_bnav_item_icon($it) . '</span>';
        $html .= $is_center ? '<span class="bx-bn-circle">' . $icon . '</span>' : $icon;
        if ($label !== '') $html .= '<span class="bx-bn-lbl">' . esc_html($label) . '</span>';
        $html .= '</a>';
    }
    $html .= '</div></nav>';
    return $html;
}

/* ------------------------------------------------------------------------- *
 * Frontend output
 * ------------------------------------------------------------------------- */
add_action('wp_footer', function () {
    if (!bx_bnav_enabled() || is_admin()) return;
    $s = bx_bnav_options();

    // Hide on chosen URLs (one per line, "*" at the end = whole folder).
    $cur = strtolower(untrailingslashit((string) wp_parse_url(bx_request_uri() ?: '/', PHP_URL_PATH))) ?: '/';
    foreach (preg_split('/\r\n|\r|\n/', (string) $s['hide_urls']) as $rule) {
        $rule = strtolower(trim($rule));
        if ($rule === '') continue;
        $rule = (string) wp_parse_url($rule, PHP_URL_PATH);
        if (substr($rule, -1) === '*') {
            if (strpos($cur . '/', rtrim($rule, '*')) === 0) return;
        } elseif ($cur === (untrailingslashit($rule) ?: '/')) {
            return;
        }
    }

    $bp = $s['breakpoint'] === 'tablet' ? 1024 : 767;
    $H = (int) $s['height'];
    $reserve = $H + ($s['layout'] === 'floating' ? 24 : 0) + ($s['center_style'] !== 'inline' ? 10 : 0);

    echo bx_bnav_render($s, 'bx-bnav'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is escaped inside bx_bnav_render().
    ?>
    <style>
        @media (min-width:<?php echo (int) ($bp + 1); ?>px){#bx-bnav{display:none!important}}
        @media (max-width:<?php echo (int) $bp; ?>px){
            <?php if (!empty($s['body_space'])): ?>body{padding-bottom:<?php echo (int) $reserve; ?>px}<?php endif; ?>
            /* Keep Brandix sticky buttons above the menu */
            #bx-sticky{bottom:<?php echo (int) ($reserve + 16); ?>px!important}
        }
    </style>
    <?php if ($s['scroll'] !== 'always'): ?>
    <script>
    (function () {
        var nav = document.getElementById('bx-bnav');
        if (!nav) return;
        var mode = nav.getAttribute('data-scroll'), px = parseInt(nav.getAttribute('data-px'), 10) || 0;
        var last = window.pageYOffset, ticking = false;
        function show(v) { nav.classList.toggle('bx-bn-hidden', !v); }
        function update() {
            var y = window.pageYOffset, diff = y - last;
            if (mode === 'after_px') {
                show(y > px);
            } else if (Math.abs(diff) > 6) {
                var down = diff > 0;
                if (mode === 'show_on_down') show(y > 10 && down);   // appears while scrolling down, hides on scroll up
                if (mode === 'hide_on_down') show(y < 10 || !down);  // hides while scrolling down, comes back on scroll up
                last = y;
            }
            if (y < 10 && mode === 'hide_on_down') show(true);
            ticking = false;
        }
        window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }, { passive: true });
        update();
    })();
    </script>
    <?php endif;
}, 20);

/* ------------------------------------------------------------------------- *
 * Admin page
 * ------------------------------------------------------------------------- */
add_action('admin_menu', function () {
    if (!bx_bnav_enabled()) return; // hidden while the module is off
    add_submenu_page('brandix-powerkit', 'Mobile Bottom Menu', 'Mobile Bottom Menu', 'manage_options', 'brandix-bottom-nav', 'bx_bnav_admin_page');
}, 22);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'brandix-bottom-nav') !== false) wp_enqueue_media();
});

function bx_bnav_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    $s = bx_bnav_options();
    $presets = bx_bnav_presets();
    $icons = bx_bnav_icons();
    $slots = ['Left 1', 'Left 2', 'Centre circle', 'Right 1', 'Right 2'];
    $pages = get_pages(['sort_column' => 'menu_order,post_title']);
    $icon_svgs = [];
    foreach ($icons as $k => $v) $icon_svgs[$k] = bx_bnav_icon_svg($k);
    $color = function ($k, $label, $allow_empty = false) use ($s) {
        echo '<label class="bxn-color"><span>' . esc_html($label) . '</span><input type="color" name="bx_bottom_nav[' . esc_attr($k) . ']" value="' . esc_attr($s[$k] ?: '#ffffff') . '" data-key="' . esc_attr($k) . '"' . ($allow_empty && !$s[$k] ? ' data-empty="1"' : '') . '>';
        if ($allow_empty) echo '<button type="button" class="button-link bxn-clear" data-for="' . esc_attr($k) . '">' . ($s[$k] ? 'none' : 'off') . '</button><input type="hidden" name="bx_bottom_nav[' . esc_attr($k) . '_off]" value="' . ($s[$k] ? '' : '1') . '">';
        echo '</label>';
    };
    ?>
    <style>
        .bxn-wrap{max-width:1100px}
        .bxn-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px 22px;margin:16px 0}
        .bxn-card h2{margin:0 0 4px;font-size:17px}.bxn-help{color:#64748b;margin:0 0 14px}
        .bxn-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
        .bxn-preset{border:2px solid #e2e8f0;border-radius:12px;padding:10px;cursor:pointer;background:#fff;text-align:left}
        .bxn-preset.is-on{border-color:#0074FD;box-shadow:0 0 0 2px #bfdbfe}
        .bxn-preset strong{display:block;margin:8px 2px 0;font-size:13px}
        .bxn-stage{position:relative;height:120px;border-radius:8px;background:#e2e8f0;overflow:hidden}
        .bxn-phone{position:relative;width:340px;height:240px;margin:0 auto;border:10px solid #0f172a;border-bottom:0;border-radius:36px 36px 0 0;background:linear-gradient(#f8fafc,#e2e8f0);overflow:hidden}
        .bxn-phone p{margin:22px;color:#94a3b8;font-size:12px;line-height:1.7}
        .bxn-items-table{width:100%;border-collapse:collapse}
        .bxn-items-table th,.bxn-items-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:middle}
        .bxn-items-table th{font-size:12px;color:#64748b;font-weight:600}
        .bxn-iconprev{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:8px;background:#0f172a;color:#fff;vertical-align:middle;margin-right:6px}
        .bxn-iconprev svg,.bxn-iconprev img{width:18px;height:18px}
        .bxn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px 22px}
        .bxn-field label{display:block;font-weight:600;margin-bottom:5px}
        .bxn-field select,.bxn-field input[type=number],.bxn-field textarea{width:100%}
        .bxn-colors{display:flex;flex-wrap:wrap;gap:12px}
        .bxn-color{display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px}
        .bxn-color input[type=color]{width:34px;height:26px;padding:0;border:0;background:none}
        @media(max-width:782px){.bxn-items-table td{display:block}.bxn-items-table thead{display:none}}
    </style>
    <div class="wrap bxn-wrap">
        <h1>Mobile Bottom Menu</h1>
        <p class="bxn-help">Shows on phones only (or phones + tablets). Centre circle + 2 links on each side.</p>
        <?php settings_errors(); ?>

        <form method="post" action="options.php" id="bxn-form">
            <?php settings_fields('bx_bnav_group'); ?>
            <input type="hidden" name="bx_bottom_nav[preset]" id="bxn-preset" value="<?php echo esc_attr($s['preset']); ?>">

            <div class="bxn-card">
                <h2>Current menu (saved)</h2>
                <p class="bxn-help">Save to refresh this preview.</p>
                <div class="bxn-phone"><p>Your page content…</p><?php echo bx_bnav_render($s, 'bxn-live', true, 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside bx_bnav_render(). ?></div>
            </div>

            <div class="bxn-card">
                <h2>Ready-made designs</h2>
                <p class="bxn-help">Click a design to load its colours and style below, then fine-tune and save.</p>
                <div class="bxn-presets">
                    <?php foreach ($presets as $key => $p):
                        $ps = array_merge($s, $p); unset($ps['name']); ?>
                        <button type="button" class="bxn-preset <?php echo $s['preset'] === $key ? 'is-on' : ''; ?>" data-preset="<?php echo esc_attr($key); ?>" data-values="<?php echo esc_attr(wp_json_encode(array_diff_key($p, ['name' => 1]))); ?>">
                            <div class="bxn-stage"><?php echo bx_bnav_render($ps, 'bxn-p-' . $key, true, 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside bx_bnav_render(). ?></div>
                            <strong><?php echo esc_html($p['name']); ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bxn-card">
                <h2>Links & icons</h2>
                <p class="bxn-help">Pick a page or type any link (also <code>tel:</code>, <code>mailto:</code>, <code>https://wa.me/…</code>). Leave a label empty to show the icon only. An empty link leaves that slot blank.</p>
                <table class="bxn-items-table">
                    <thead><tr><th style="width:110px">Slot</th><th>Label</th><th>Link</th><th>Icon</th><th>Custom icon (optional)</th><th>New tab</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['items'] as $i => $it): $n = 'bx_bottom_nav[items][' . $i . ']'; ?>
                        <tr>
                            <td><strong><?php echo esc_html($slots[$i]); ?></strong></td>
                            <td><input type="text" name="<?php echo esc_attr($n); ?>[label]" value="<?php echo esc_attr($it['label']); ?>" style="width:120px" placeholder="<?php echo $i === 2 ? 'Icon only' : 'Label'; ?>"></td>
                            <td>
                                <input type="text" name="<?php echo esc_attr($n); ?>[url]" value="<?php echo esc_attr($it['url']); ?>" class="bxn-url" style="width:100%;min-width:170px" placeholder="/contact/ or tel:+44...">
                                <select class="bxn-page-pick" style="margin-top:4px;width:100%;max-width:220px">
                                    <option value="">— pick a page —</option>
                                    <option value="<?php echo esc_attr(home_url('/')); ?>">Homepage</option>
                                    <?php foreach ($pages as $pg): ?><option value="<?php echo esc_attr(get_permalink($pg)); ?>"><?php echo esc_html($pg->post_title ?: '(no title)'); ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td style="white-space:nowrap">
                                <span class="bxn-iconprev"><?php echo bx_bnav_item_icon($it); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from the plugin icon list or an esc_url() image. ?></span>
                                <select name="<?php echo esc_attr($n); ?>[icon]" class="bxn-icon-pick">
                                    <?php foreach ($icons as $k => $v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($it['icon'], $k); ?>><?php echo esc_html($v[0]); ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td style="white-space:nowrap">
                                <input type="text" name="<?php echo esc_attr($n); ?>[custom_icon]" value="<?php echo esc_attr($it['custom_icon']); ?>" class="bxn-custom" style="width:130px" placeholder="SVG / PNG URL">
                                <button type="button" class="button bxn-upload">Upload</button>
                            </td>
                            <td><input type="checkbox" name="<?php echo esc_attr($n); ?>[new_tab]" value="1" <?php checked($it['new_tab'], 1); ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="bxn-card">
                <h2>Design</h2>
                <p class="bxn-help">Changing anything here turns the design into "Custom".</p>
                <div class="bxn-colors" style="margin-bottom:18px">
                    <?php
                    $color('bar_bg', 'Bar');
                    $color('bar_bg2', 'Bar gradient end', true);
                    $color('icon_color', 'Icons');
                    $color('label_color', 'Labels');
                    $color('active_color', 'Active page');
                    $color('center_bg', 'Centre circle');
                    $color('center_icon', 'Centre icon');
                    $color('ring_color', 'Centre ring', true);
                    ?>
                </div>
                <div class="bxn-grid">
                    <div class="bxn-field"><label>Layout</label><select name="bx_bottom_nav[layout]" data-key="layout">
                        <option value="floating" <?php selected($s['layout'], 'floating'); ?>>Floating (rounded, with gap)</option>
                        <option value="full" <?php selected($s['layout'], 'full'); ?>>Full width (stuck to bottom)</option></select></div>
                    <div class="bxn-field"><label>Centre circle style</label><select name="bx_bottom_nav[center_style]" data-key="center_style">
                        <option value="raised" <?php selected($s['center_style'], 'raised'); ?>>Raised above the bar</option>
                        <option value="notch" <?php selected($s['center_style'], 'notch'); ?>>Raised with curved notch</option>
                        <option value="inline" <?php selected($s['center_style'], 'inline'); ?>>Inside the bar</option></select></div>
                    <div class="bxn-field"><label>Active page indicator</label><select name="bx_bottom_nav[indicator]" data-key="indicator">
                        <option value="none" <?php selected($s['indicator'], 'none'); ?>>Colour only</option>
                        <option value="dot" <?php selected($s['indicator'], 'dot'); ?>>Dot</option>
                        <option value="line" <?php selected($s['indicator'], 'line'); ?>>Top line</option>
                        <option value="pill" <?php selected($s['indicator'], 'pill'); ?>>Soft pill behind icon</option></select></div>
                    <div class="bxn-field"><label>Labels</label><select name="bx_bottom_nav[show_labels]" data-key="show_labels">
                        <option value="1" <?php selected($s['show_labels'], 1); ?>>Show labels</option>
                        <option value="0" <?php selected($s['show_labels'], 0); ?>>Icons only</option></select></div>
                    <div class="bxn-field"><label>Bar height (px)</label><input type="number" name="bx_bottom_nav[height]" value="<?php echo esc_attr($s['height']); ?>" min="50" max="90" data-key="height"></div>
                    <div class="bxn-field"><label>Centre circle size (px)</label><input type="number" name="bx_bottom_nav[center_size]" value="<?php echo esc_attr($s['center_size']); ?>" min="44" max="76"></div>
                    <div class="bxn-field"><label>Corner radius (floating, px)</label><input type="number" name="bx_bottom_nav[radius]" value="<?php echo esc_attr($s['radius']); ?>" min="0" max="999" data-key="radius"></div>
                    <div class="bxn-field"><label>Label size (px)</label><input type="number" name="bx_bottom_nav[label_size]" value="<?php echo esc_attr($s['label_size']); ?>" min="9" max="15"></div>
                    <div class="bxn-field"><label>Bar opacity (%)</label><input type="number" name="bx_bottom_nav[bar_opacity]" value="<?php echo esc_attr($s['bar_opacity']); ?>" min="30" max="100" data-key="bar_opacity"></div>
                    <div class="bxn-field"><label>Glass blur</label><select name="bx_bottom_nav[blur]" data-key="blur">
                        <option value="0" <?php selected($s['blur'], 0); ?>>Off</option>
                        <option value="1" <?php selected($s['blur'], 1); ?>>On (frosted glass)</option></select></div>
                </div>
            </div>

            <div class="bxn-card">
                <h2>Behaviour</h2>
                <div class="bxn-grid">
                    <div class="bxn-field"><label>When to show</label><select name="bx_bottom_nav[scroll]" id="bxn-scroll">
                        <option value="always" <?php selected($s['scroll'], 'always'); ?>>Always visible</option>
                        <option value="show_on_down" <?php selected($s['scroll'], 'show_on_down'); ?>>Show on scroll down, hide on scroll up</option>
                        <option value="hide_on_down" <?php selected($s['scroll'], 'hide_on_down'); ?>>Hide on scroll down, show on scroll up</option>
                        <option value="after_px" <?php selected($s['scroll'], 'after_px'); ?>>Show after scrolling a distance</option></select></div>
                    <div class="bxn-field" id="bxn-px-wrap" style="<?php echo $s['scroll'] === 'after_px' ? '' : 'display:none'; ?>"><label>Distance (px)</label><input type="number" name="bx_bottom_nav[scroll_px]" value="<?php echo esc_attr($s['scroll_px']); ?>" min="0" max="5000"></div>
                    <div class="bxn-field"><label>Show on</label><select name="bx_bottom_nav[breakpoint]">
                        <option value="mobile" <?php selected($s['breakpoint'], 'mobile'); ?>>Mobile only (up to 767px)</option>
                        <option value="tablet" <?php selected($s['breakpoint'], 'tablet'); ?>>Mobile + tablet (up to 1024px)</option></select></div>
                    <div class="bxn-field"><label>Page bottom space</label><select name="bx_bottom_nav[body_space]">
                        <option value="1" <?php selected($s['body_space'], 1); ?>>Add space so the footer isn't covered</option>
                        <option value="0" <?php selected($s['body_space'], 0); ?>>No extra space</option></select></div>
                    <div class="bxn-field" style="grid-column:1/-1"><label>Hide on these pages</label>
                        <textarea name="bx_bottom_nav[hide_urls]" rows="3" placeholder="/checkout/&#10;/cart/&#10;/landing/*"><?php echo esc_textarea($s['hide_urls']); ?></textarea>
                        <p class="bxn-help" style="margin:4px 0 0">One per line. <code>*</code> at the end hides a whole folder.</p></div>
                </div>
            </div>

            <?php submit_button('Save bottom menu'); ?>
        </form>
    </div>
    <script>
    jQuery(function ($) {
        var icons = <?php echo wp_json_encode($icon_svgs); ?>;
        var $form = $('#bxn-form');

        // Ready-made designs: copy values into the fields.
        $('.bxn-preset').on('click', function () {
            var vals = $(this).data('values');
            $('.bxn-preset').removeClass('is-on'); $(this).addClass('is-on');
            $('#bxn-preset').val($(this).data('preset'));
            $.each(vals, function (k, v) {
                var $f = $form.find('[name="bx_bottom_nav[' + k + ']"]');
                if (!$f.length) return;
                var $off = $form.find('[name="bx_bottom_nav[' + k + '_off]"]');
                if ($off.length) {
                    $off.val(v ? '' : '1');
                    $form.find('.bxn-clear[data-for="' + k + '"]').text(v ? 'none' : 'off');
                    if (!v) return;
                }
                $f.val(String(v));
            });
        });
        // Manual change = custom design.
        $form.on('change input', '.bxn-colors input, .bxn-grid [data-key]', function () {
            $('#bxn-preset').val('custom'); $('.bxn-preset').removeClass('is-on');
        });
        // Optional colours (gradient end, ring) can be switched off.
        $('.bxn-clear').on('click', function () {
            var k = $(this).data('for'), $off = $form.find('[name="bx_bottom_nav[' + k + '_off]"]');
            var off = $off.val() !== '1';
            $off.val(off ? '1' : ''); $(this).text(off ? 'off' : 'none');
            $('#bxn-preset').val('custom'); $('.bxn-preset').removeClass('is-on');
        });
        $form.on('input', 'input[type=color]', function () {
            var $off = $form.find('[name="bx_bottom_nav[' + $(this).data('key') + '_off]"]');
            if ($off.length) { $off.val(''); $form.find('.bxn-clear[data-for="' + $(this).data('key') + '"]').text('none'); }
        });

        $('.bxn-page-pick').on('change', function () { if (this.value) $(this).closest('td').find('.bxn-url').val(this.value); this.value = ''; });
        $('.bxn-icon-pick').on('change', function () {
            var $row = $(this).closest('tr');
            if (!$row.find('.bxn-custom').val()) $row.find('.bxn-iconprev').html(icons[this.value] || '');
        });
        $('#bxn-scroll').on('change', function () { $('#bxn-px-wrap').toggle(this.value === 'after_px'); });

        // Media library picker for custom icons.
        $('.bxn-upload').on('click', function (e) {
            e.preventDefault();
            var $input = $(this).siblings('.bxn-custom');
            var frame = wp.media({ title: 'Choose icon (SVG or PNG)', button: { text: 'Use this icon' }, multiple: false });
            frame.on('select', function () {
                var url = frame.state().get('selection').first().toJSON().url;
                $input.val(url);
                $input.closest('tr').find('.bxn-iconprev').html('<img src="' + url + '" alt="">');
            });
            frame.open();
        });
    });
    </script>
    <?php
}

