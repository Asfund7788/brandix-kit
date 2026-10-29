<?php
/**
 * Brandix PowerKit — Cookie Consent module (UK GDPR / PECR).
 *
 * - Banner with Accept all / Reject all (equal weight) / Manage preferences.
 * - Categories: Necessary (always on), Analytics, Marketing.
 * - Google Consent Mode v2: everything denied by default, updated on choice.
 * - Script blocking:
 *     1. Manual: <script type="text/plain" data-bx-consent="marketing"> ... </script>
 *        (use this in Elementor → Custom Code for any tracker).
 *     2. Automatic: known trackers (Meta Pixel, TikTok, LinkedIn, Hotjar, Clarity …)
 *        are rewritten to the blocked form before the page is sent.
 * - Page-cache safe: the banner decision is made in the browser from the cookie.
 * - Optional anonymous consent log (no IP stored).
 */
if (!defined('ABSPATH')) exit;

function bx_cc_defaults() {
    return [
        'title'        => 'We value your privacy',
        'message'      => 'We use cookies to make this website work, to understand how it is used and to support our marketing. You can accept all cookies, reject non-essential cookies, or choose your preferences.',
        'accept'       => 'Accept all',
        'reject'       => 'Reject all',
        'manage'       => 'Manage preferences',
        'save'         => 'Save preferences',
        'policy_url'   => '',
        'policy_label' => 'Cookie policy',
        'necessary_desc' => 'Required for the website to work, such as security and remembering your cookie choice. These cannot be switched off.',
        'analytics_desc' => 'Help us understand how visitors use the website (for example Google Analytics), so we can improve it.',
        'marketing_desc' => 'Used to show relevant adverts and measure campaigns (for example Google Ads and Meta Pixel).',
        'position'     => 'bar',      // bar | box-left | box-right
        'color_primary'=> '#0074FD',
        'color_bg'     => '#ffffff',
        'color_text'   => '#1e293b',
        'consent_days' => 180,
        'version'      => 1,
        'gcm'          => 1,          // Google Consent Mode v2
        'auto_block'   => 1,
        'log_consent'  => 0,
        'floating_btn' => 0,
    ];
}

function bx_cc_options() {
    static $o = null;
    if ($o === null) $o = wp_parse_args((array) get_option('bx_cookie_options', []), bx_cc_defaults());
    return $o;
}

function bx_cc_enabled() {
    return !empty(get_option('sb_options', [])['enable_cookie_consent']);
}

/** Only real frontend page views: no admin, AJAX, REST, feeds, cron or Elementor editor preview. */
function bx_cc_should_run() {
    if (!bx_cc_enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron()) return false;
    if ((defined('REST_REQUEST') && REST_REQUEST) || is_feed() || is_customize_preview()) return false;
    if (isset($_GET['elementor-preview'])) return false;
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->preview) && \Elementor\Plugin::$instance->preview->is_preview_mode()) return false;
    return true;
}

/** Known tracker signatures → category. Filterable for site-specific additions. */
function bx_cc_tracker_patterns() {
    return apply_filters('bx_cc_tracker_patterns', [
        'marketing' => [
            'connect.facebook.net', 'fbq(', 'analytics.tiktok.com', 'ttq.load', 'snap.licdn.com',
            '_linkedin_partner_id', 'static.ads-twitter.com', 'twq(', 'sc-static.net/scevent',
            'bat.bing.com', 's.pinimg.com/ct', 'pintrk(',
        ],
        'analytics' => [
            'static.hotjar.com', 'clarity.ms/tag', 'cdn.mouseflow.com', 'luckyorange',
            'fullstory.com/s/fs.js', 'cdn.segment.com',
        ],
    ]);
}

/* ------------------------------------------------------------------------- *
 * 1. Google Consent Mode v2 defaults — must print before any Google tag.
 * ------------------------------------------------------------------------- */
add_action('wp_head', function () {
    if (!bx_cc_should_run()) return;
    $o = bx_cc_options();
    if (empty($o['gcm'])) return;
    ?>
<script data-bx-skip>
/* Brandix Cookie Consent: Google Consent Mode v2 defaults (denied until the visitor chooses). */
window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});
gtag('set','ads_data_redaction',true);
(function(){try{var m=document.cookie.match(/(?:^|; )bx_consent=([^;]*)/);if(!m)return;var c=JSON.parse(decodeURIComponent(m[1]));if(!c||c.v!==<?php echo (int) $o['version']; ?>)return;
gtag('consent','update',{analytics_storage:c.a?'granted':'denied',ad_storage:c.m?'granted':'denied',ad_user_data:c.m?'granted':'denied',ad_personalization:c.m?'granted':'denied'});}catch(e){}})();
</script>
    <?php
}, -1000);

/* ------------------------------------------------------------------------- *
 * 2. Automatic blocking of known trackers (output buffer).
 * ------------------------------------------------------------------------- */
add_action('template_redirect', function () {
    if (!bx_cc_should_run() || empty(bx_cc_options()['auto_block'])) return;
    ob_start('bx_cc_block_scripts');
}, 1);

function bx_cc_block_scripts($html) {
    if (!is_string($html) || stripos($html, '<script') === false) return $html;
    $patterns = bx_cc_tracker_patterns();
    $out = preg_replace_callback('~<script\b([^>]*)>(.*?)</script>~is', function ($m) use ($patterns) {
        $attrs = $m[1];
        $body  = $m[2];
        if (stripos($attrs, 'data-bx-consent') !== false || stripos($attrs, 'data-bx-skip') !== false) return $m[0];
        // Leave JSON-LD, templates and ES modules alone; only classic JavaScript is blocked.
        if (preg_match('~\btype\s*=\s*["\']?([^"\'\s>]+)~i', $attrs, $t)
            && !in_array(strtolower($t[1]), ['text/javascript', 'application/javascript'], true)) {
            return $m[0];
        }
        $haystack = $attrs . ' ' . $body;
        foreach ($patterns as $cat => $needles) {
            foreach ($needles as $needle) {
                if (stripos($haystack, $needle) !== false) {
                    $attrs = preg_replace('~\s+type\s*=\s*(["\']?)[^"\'\s>]*\1~i', '', $attrs);
                    return '<script type="text/plain" data-bx-consent="' . esc_attr($cat) . '"' . $attrs . '>' . $body . '</script>';
                }
            }
        }
        return $m[0];
    }, $html);
    // On a regex failure (very large page), send the page unchanged rather than blank.
    return $out === null ? $html : $out;
}

/* ------------------------------------------------------------------------- *
 * 3. Banner, preferences panel and consent logic (footer).
 * ------------------------------------------------------------------------- */
add_action('wp_footer', function () {
    if (!bx_cc_should_run()) return;
    $o = bx_cc_options();
    $pos = in_array($o['position'], ['bar', 'box-left', 'box-right'], true) ? $o['position'] : 'bar';
    $cfg = [
        'version' => (int) $o['version'],
        'days'    => (int) $o['consent_days'],
        'log'     => !empty($o['log_consent']),
        'ajax'    => admin_url('admin-ajax.php'),
        'gcm'     => !empty($o['gcm']),
    ];
    ?>
    <style>
        #bx-cc{--bx-cc-p:<?php echo esc_attr($o['color_primary']); ?>;--bx-cc-bg:<?php echo esc_attr($o['color_bg']); ?>;--bx-cc-t:<?php echo esc_attr($o['color_text']); ?>;
            position:fixed;z-index:100000;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;line-height:1.5;color:var(--bx-cc-t);
            background:var(--bx-cc-bg);box-shadow:0 -4px 24px rgba(0,0,0,.14);box-sizing:border-box;max-height:100vh;overflow-y:auto}
        #bx-cc[hidden]{display:none!important}
        #bx-cc *{box-sizing:border-box}
        #bx-cc.bx-cc-bar{left:0;right:0;bottom:0;padding:18px 24px}
        #bx-cc.bx-cc-box-left,#bx-cc.bx-cc-box-right{bottom:20px;width:400px;max-width:calc(100vw - 32px);border-radius:12px;padding:20px;box-shadow:0 8px 32px rgba(0,0,0,.2)}
        #bx-cc.bx-cc-box-left{left:16px}#bx-cc.bx-cc-box-right{right:16px}
        .bx-cc-inner{max-width:1200px;margin:0 auto;display:flex;gap:20px;align-items:center;flex-wrap:wrap}
        .bx-cc-box-left .bx-cc-inner,.bx-cc-box-right .bx-cc-inner{display:block}
        .bx-cc-text{flex:1 1 420px}
        .bx-cc-title{font-weight:700;font-size:16px;margin:0 0 4px}
        .bx-cc-msg{margin:0}.bx-cc-msg a{color:var(--bx-cc-p);text-decoration:underline}
        .bx-cc-btns{display:flex;gap:10px;flex-wrap:wrap}
        .bx-cc-box-left .bx-cc-btns,.bx-cc-box-right .bx-cc-btns{margin-top:14px}
        #bx-cc button{font:inherit;font-weight:600;cursor:pointer;border-radius:8px;padding:10px 18px;border:2px solid var(--bx-cc-p);line-height:1.2;margin:0}
        #bx-cc .bx-cc-solid{background:var(--bx-cc-p);color:#fff}
        #bx-cc .bx-cc-ghost{background:transparent;color:var(--bx-cc-t);border-color:currentColor}
        #bx-cc .bx-cc-link{background:none;border:0;padding:10px 4px;color:var(--bx-cc-p);text-decoration:underline}
        #bx-cc button:focus-visible{outline:3px solid var(--bx-cc-p);outline-offset:2px}
        .bx-cc-prefs{flex-basis:100%;border-top:1px solid rgba(0,0,0,.1);padding-top:12px;margin-top:4px}
        .bx-cc-prefs[hidden]{display:none}
        .bx-cc-cat{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.06)}
        .bx-cc-cat strong{display:block}.bx-cc-cat small{display:block;opacity:.8;font-size:12.5px}
        .bx-cc-cat input{width:20px;height:20px;flex:0 0 20px;margin-top:3px;accent-color:var(--bx-cc-p)}
        #bx-cc-fab{position:fixed;left:16px;bottom:16px;z-index:99998;width:44px;height:44px;border-radius:50%;border:0;cursor:pointer;
            background:<?php echo esc_attr($o['color_primary']); ?>;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}
        #bx-cc-fab[hidden]{display:none}
        @media(max-width:600px){#bx-cc.bx-cc-bar{padding:16px}.bx-cc-btns button{flex:1 1 auto}}
        /* Lift the Brandix sticky buttons above the banner while it is open. */
        html.bx-cc-shown .bx-sticky-wrapper{bottom:calc(25px + var(--bx-cc-h,0px))!important;transition:bottom .2s}
    </style>
    <div id="bx-cc" class="bx-cc-<?php echo esc_attr($pos); ?>" role="dialog" aria-modal="false" aria-labelledby="bx-cc-title" aria-describedby="bx-cc-msg" hidden>
        <div class="bx-cc-inner">
            <div class="bx-cc-text">
                <p class="bx-cc-title" id="bx-cc-title"><?php echo esc_html($o['title']); ?></p>
                <p class="bx-cc-msg" id="bx-cc-msg"><?php echo esc_html($o['message']); ?>
                    <?php if (!empty($o['policy_url'])): ?> <a href="<?php echo esc_url($o['policy_url']); ?>"><?php echo esc_html($o['policy_label']); ?></a><?php endif; ?></p>
            </div>
            <div class="bx-cc-btns">
                <button type="button" class="bx-cc-solid" data-bx-cc="accept"><?php echo esc_html($o['accept']); ?></button>
                <button type="button" class="bx-cc-solid" data-bx-cc="reject"><?php echo esc_html($o['reject']); ?></button>
                <button type="button" class="bx-cc-link" data-bx-cc="manage" aria-expanded="false" aria-controls="bx-cc-prefs"><?php echo esc_html($o['manage']); ?></button>
            </div>
            <div class="bx-cc-prefs" id="bx-cc-prefs" hidden>
                <div class="bx-cc-cat"><div><strong>Necessary</strong><small><?php echo esc_html($o['necessary_desc']); ?></small></div><input type="checkbox" checked disabled aria-label="Necessary cookies (always on)"></div>
                <label class="bx-cc-cat"><div><strong>Analytics</strong><small><?php echo esc_html($o['analytics_desc']); ?></small></div><input type="checkbox" id="bx-cc-a"></label>
                <label class="bx-cc-cat"><div><strong>Marketing</strong><small><?php echo esc_html($o['marketing_desc']); ?></small></div><input type="checkbox" id="bx-cc-m"></label>
                <div class="bx-cc-btns" style="margin-top:12px"><button type="button" class="bx-cc-solid" data-bx-cc="save"><?php echo esc_html($o['save']); ?></button></div>
            </div>
        </div>
    </div>
    <?php if (!empty($o['floating_btn'])): ?>
        <button type="button" id="bx-cc-fab" class="bx-cc-open" aria-label="Cookie settings" hidden>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5zm-4.5 9a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm3 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm5.5-2a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/></svg>
        </button>
    <?php endif; ?>
    <script data-bx-skip>
    (function () {
        var CFG = <?php echo wp_json_encode($cfg); ?>;
        var box = document.getElementById('bx-cc');
        if (!box) return;
        var prefs = document.getElementById('bx-cc-prefs');
        var chkA = document.getElementById('bx-cc-a'), chkM = document.getElementById('bx-cc-m');
        var fab = document.getElementById('bx-cc-fab');
        var manageBtn = box.querySelector('[data-bx-cc="manage"]');

        function read() {
            try {
                var m = document.cookie.match(/(?:^|; )bx_consent=([^;]*)/);
                if (!m) return null;
                var c = JSON.parse(decodeURIComponent(m[1]));
                return (c && c.v === CFG.version) ? c : null;
            } catch (e) { return null; }
        }
        function rid() {
            var a = new Uint8Array(12);
            (window.crypto || window.msCrypto).getRandomValues(a);
            return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        }
        function write(a, m, prev) {
            var c = { v: CFG.version, a: a ? 1 : 0, m: m ? 1 : 0, t: Date.now(), id: (prev && prev.id) || rid() };
            var exp = new Date(Date.now() + CFG.days * 864e5).toUTCString();
            document.cookie = 'bx_consent=' + encodeURIComponent(JSON.stringify(c)) + '; expires=' + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            return c;
        }
        // Remove common tracking cookies when consent is withdrawn.
        function purge(cat) {
            var names = cat === 'a' ? ['_ga', '_gid', '_gat', '_hj', '_clck', '_clsk', 'mf_'] : ['_fbp', '_fbc', '_gcl', '_ttp', 'li_', '_uet', '_pin', '_scid'];
            var host = location.hostname.split('.');
            var domains = [''];
            for (var i = 0; i < host.length - 1; i++) domains.push('; domain=.' + host.slice(i).join('.'));
            document.cookie.split('; ').forEach(function (kv) {
                var n = kv.split('=')[0];
                if (!names.some(function (p) { return n.indexOf(p) === 0; })) return;
                domains.forEach(function (d) { document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + d; });
            });
        }
        function google(c) {
            if (!CFG.gcm || typeof window.gtag !== 'function') return;
            gtag('consent', 'update', {
                analytics_storage: c.a ? 'granted' : 'denied',
                ad_storage: c.m ? 'granted' : 'denied',
                ad_user_data: c.m ? 'granted' : 'denied',
                ad_personalization: c.m ? 'granted' : 'denied'
            });
            window.dataLayer = window.dataLayer || [];
            dataLayer.push({ event: 'bx_consent_update', bx_analytics: !!c.a, bx_marketing: !!c.m });
        }
        // Turn allowed text/plain scripts into real ones, one at a time so external files load in order.
        function activate(c) {
            var list = Array.prototype.slice.call(document.querySelectorAll('script[type="text/plain"][data-bx-consent]'));
            (function next() {
                var s = list.shift();
                if (!s) return;
                var cat = s.getAttribute('data-bx-consent');
                if (!((cat === 'analytics' && c.a) || (cat === 'marketing' && c.m))) return next();
                var n = document.createElement('script');
                for (var i = 0; i < s.attributes.length; i++) {
                    var at = s.attributes[i];
                    if (at.name !== 'type' && at.name !== 'data-bx-consent') n.setAttribute(at.name, at.value);
                }
                n.setAttribute('data-bx-activated', cat);
                if (s.src) { n.onload = n.onerror = next; n.async = false; }
                else { n.text = s.text; }
                s.parentNode.replaceChild(n, s);
                if (!s.src) next();
            })();
        }
        function log(c) {
            if (!CFG.log || !navigator.sendBeacon) return;
            var f = new FormData();
            f.append('action', 'bx_cc_log'); f.append('id', c.id); f.append('a', c.a); f.append('m', c.m);
            f.append('v', c.v); f.append('u', location.pathname);
            navigator.sendBeacon(CFG.ajax, f);
        }
        function show(open) {
            box.hidden = false;
            if (fab) fab.hidden = true;
            var cur = read();
            chkA.checked = !!(cur && cur.a); chkM.checked = !!(cur && cur.m);
            togglePrefs(!!open);
            document.documentElement.classList.add('bx-cc-shown');
            measure();
        }
        function hide() {
            box.hidden = true;
            document.documentElement.classList.remove('bx-cc-shown');
            if (fab) fab.hidden = false;
        }
        function measure() {
            if (!box.classList.contains('bx-cc-bar')) return;
            document.documentElement.style.setProperty('--bx-cc-h', box.offsetHeight + 'px');
        }
        function togglePrefs(open) {
            prefs.hidden = !open;
            manageBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            manageBtn.hidden = open;
            measure();
        }
        function decide(a, m) {
            var prev = read();
            var c = write(a, m, prev);
            google(c);
            activate(c);
            log(c);
            hide();
            document.dispatchEvent(new CustomEvent('bx:consent', { detail: c }));
            // A script that already ran cannot be unloaded: clear its cookies and reload.
            if (prev && ((prev.a && !c.a) || (prev.m && !c.m))) {
                if (prev.a && !c.a) purge('a');
                if (prev.m && !c.m) purge('m');
                location.reload();
            }
        }

        box.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bx-cc]');
            if (!b) return;
            var act = b.getAttribute('data-bx-cc');
            if (act === 'accept') decide(true, true);
            else if (act === 'reject') decide(false, false);
            else if (act === 'manage') togglePrefs(true);
            else if (act === 'save') decide(chkA.checked, chkM.checked);
        });
        // Any element with class "bx-cc-open", or a link to #cookie-settings, reopens the panel.
        document.addEventListener('click', function (e) {
            var t = e.target.closest('.bx-cc-open, a[href="#cookie-settings"]');
            if (!t) return;
            e.preventDefault();
            show(true);
        });
        window.addEventListener('resize', measure);
        window.bxCookieConsent = { open: function () { show(true); }, get: read };

        var c = read();
        if (c) { activate(c); if (fab) fab.hidden = false; }
        else { show(false); }
    })();
    </script>
    <?php
}, 5);

/** [bx_cookie_settings text="Cookie settings"] — footer link to reopen the panel. */
add_shortcode('bx_cookie_settings', function ($atts) {
    $atts = shortcode_atts(['text' => 'Cookie settings'], $atts, 'bx_cookie_settings');
    return '<a href="#cookie-settings" class="bx-cc-open">' . esc_html($atts['text']) . '</a>';
});

/* ------------------------------------------------------------------------- *
 * 4. Optional anonymous consent log (no IP stored; rate-limited per IP hash).
 * ------------------------------------------------------------------------- */
function bx_cc_log_handler() {
    if (!bx_cc_enabled() || empty(bx_cc_options()['log_consent'])) wp_die('', '', ['response' => 204]);
    $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{16,40}$/', $id)) wp_die('', '', ['response' => 400]);

    // Basic abuse protection: max 20 log entries per visitor IP per hour (IP is hashed, never stored).
    $key = 'bx_cc_rl_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
    $n = (int) get_transient($key);
    if ($n >= 20) wp_die('', '', ['response' => 429]);
    set_transient($key, $n + 1, HOUR_IN_SECONDS);

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'bx_consent_log', [
        'consent_id' => $id,
        'analytics'  => !empty($_POST['a']) ? 1 : 0,
        'marketing'  => !empty($_POST['m']) ? 1 : 0,
        'version'    => absint($_POST['v'] ?? 0),
        'page'       => substr(sanitize_text_field(wp_unslash($_POST['u'] ?? '')), 0, 255),
        'created_at' => current_time('mysql'),
    ], ['%s', '%d', '%d', '%d', '%s', '%s']);
    wp_die('', '', ['response' => 204]);
}
add_action('wp_ajax_bx_cc_log', 'bx_cc_log_handler');
add_action('wp_ajax_nopriv_bx_cc_log', 'bx_cc_log_handler');

/* ------------------------------------------------------------------------- *
 * 5. Settings page
 * ------------------------------------------------------------------------- */
add_action('admin_init', function () {
    register_setting('bx_cookie_group', 'bx_cookie_options', ['sanitize_callback' => 'bx_cc_sanitize']);
});

function bx_cc_sanitize($in) {
    $d = bx_cc_defaults();
    $old = wp_parse_args((array) get_option('bx_cookie_options', []), $d);
    $in = (array) $in;
    $out = [];
    foreach (['title', 'accept', 'reject', 'manage', 'save', 'policy_label'] as $k) {
        $v = sanitize_text_field($in[$k] ?? '');
        $out[$k] = $v !== '' ? $v : $d[$k];
    }
    foreach (['message', 'necessary_desc', 'analytics_desc', 'marketing_desc'] as $k) {
        $v = sanitize_textarea_field($in[$k] ?? '');
        $out[$k] = $v !== '' ? $v : $d[$k];
    }
    $out['policy_url'] = esc_url_raw($in['policy_url'] ?? '');
    $out['position'] = in_array($in['position'] ?? '', ['bar', 'box-left', 'box-right'], true) ? $in['position'] : 'bar';
    foreach (['color_primary', 'color_bg', 'color_text'] as $k) $out[$k] = sanitize_hex_color($in[$k] ?? '') ?: $d[$k];
    $out['consent_days'] = max(30, min(395, absint($in['consent_days'] ?? 180)));
    foreach (['gcm', 'auto_block', 'log_consent', 'floating_btn'] as $k) $out[$k] = !empty($in[$k]) ? 1 : 0;
    // "Ask everyone again" bumps the version so every stored choice becomes invalid.
    $out['version'] = max(1, (int) $old['version']) + (!empty($in['reprompt']) ? 1 : 0);
    return $out;
}

add_action('admin_menu', function () {
    if (!bx_cc_enabled()) return; // hidden while the module is off
    add_submenu_page('brandix-powerkit', 'Cookie Consent', 'Cookie Consent', 'manage_options', 'brandix-cookie-consent', 'bx_cc_admin_page');
}, 21);

function bx_cc_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    $o = bx_cc_options();
    global $wpdb;
    $t = $wpdb->prefix . 'bx_consent_log';
    $since = date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) total, SUM(analytics=1 AND marketing=1) all_on, SUM(analytics=0 AND marketing=0) all_off FROM {$t} WHERE created_at >= %s", $since
    ), ARRAY_A);
    $field = function ($k, $label, $type = 'text') use ($o) {
        $id = 'bx-cc-' . $k;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        if ($type === 'textarea') echo '<textarea id="' . esc_attr($id) . '" name="bx_cookie_options[' . esc_attr($k) . ']" rows="3" class="large-text">' . esc_textarea($o[$k]) . '</textarea>';
        elseif ($type === 'color') echo '<input id="' . esc_attr($id) . '" type="color" name="bx_cookie_options[' . esc_attr($k) . ']" value="' . esc_attr($o[$k]) . '">';
        elseif ($type === 'number') echo '<input id="' . esc_attr($id) . '" type="number" min="30" max="395" name="bx_cookie_options[' . esc_attr($k) . ']" value="' . esc_attr($o[$k]) . '" class="small-text"> days';
        else echo '<input id="' . esc_attr($id) . '" type="' . esc_attr($type) . '" name="bx_cookie_options[' . esc_attr($k) . ']" value="' . esc_attr($o[$k]) . '" class="regular-text">';
        echo '</td></tr>';
    };
    $check = function ($k, $label, $desc) use ($o) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="bx_cookie_options[' . esc_attr($k) . ']" value="1" ' . checked(!empty($o[$k]), true, false) . '> ' . esc_html($desc) . '</label></td></tr>';
    };
    ?>
    <div class="wrap">
        <h1>Cookie Consent</h1>
        <?php if (!bx_cc_enabled()): ?>
            <div class="notice notice-warning"><p>The banner is <strong>off</strong>. Turn on "Cookie Consent Banner" in <a href="<?php echo esc_url(admin_url('admin.php?page=brandix-powerkit')); ?>">Brandix Kit → Master Utility Modules</a>.</p></div>
        <?php endif; ?>
        <?php settings_errors(); if (function_exists('bx_log_deleted_notice')) bx_log_deleted_notice(); ?>

        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 18px;margin:16px 0;max-width:900px">
            <strong>How tracking scripts are handled</strong>
            <ul style="list-style:disc;margin:8px 0 0 20px">
                <li><strong>Google Analytics / Google Ads / GTM:</strong> nothing to change. Google Consent Mode v2 keeps them cookieless until the visitor accepts.</li>
                <li><strong>Meta Pixel, TikTok, LinkedIn, Hotjar, Clarity…:</strong> blocked automatically (if "Auto-block" is on) until the matching category is accepted.</li>
                <li><strong>Any other script</strong> added in Elementor → Custom Code: change <code>&lt;script&gt;</code> to <code>&lt;script type="text/plain" data-bx-consent="marketing"&gt;</code> (or <code>analytics</code>).</li>
                <li><strong>Footer link to change choice:</strong> shortcode <code>[bx_cookie_settings]</code>, or any link to <code>#cookie-settings</code>.</li>
            </ul>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('bx_cookie_group'); ?>
            <h2>Banner text</h2>
            <table class="form-table" role="presentation">
                <?php
                $field('title', 'Title');
                $field('message', 'Message', 'textarea');
                $field('accept', '"Accept" button');
                $field('reject', '"Reject" button');
                $field('manage', '"Manage" link');
                $field('save', '"Save" button');
                $field('policy_url', 'Cookie/Privacy policy URL', 'url');
                $field('policy_label', 'Policy link text');
                $field('necessary_desc', 'Necessary — description', 'textarea');
                $field('analytics_desc', 'Analytics — description', 'textarea');
                $field('marketing_desc', 'Marketing — description', 'textarea');
                ?>
            </table>
            <h2>Look</h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="bx-cc-position">Position</label></th><td><select id="bx-cc-position" name="bx_cookie_options[position]">
                    <option value="bar" <?php selected($o['position'], 'bar'); ?>>Full-width bar (bottom)</option>
                    <option value="box-left" <?php selected($o['position'], 'box-left'); ?>>Box — bottom left</option>
                    <option value="box-right" <?php selected($o['position'], 'box-right'); ?>>Box — bottom right</option>
                </select></td></tr>
                <?php $field('color_primary', 'Button colour', 'color'); $field('color_bg', 'Background', 'color'); $field('color_text', 'Text colour', 'color'); ?>
            </table>
            <h2>Behaviour</h2>
            <table class="form-table" role="presentation">
                <?php
                $check('gcm', 'Google Consent Mode v2', 'Send consent signals to GA4 / Google Ads / GTM (recommended)');
                $check('auto_block', 'Auto-block known trackers', 'Hold Meta Pixel, TikTok, LinkedIn, Hotjar, Clarity etc. until consent');
                $check('floating_btn', 'Floating cookie button', 'Show a small button (bottom-left) to reopen the settings after a choice');
                $check('log_consent', 'Consent log', 'Record anonymous choices (random ID, choice, date — no IP) as proof of consent');
                $field('consent_days', 'Remember choice for', 'number');
                ?>
                <tr><th scope="row">Ask everyone again</th><td><label><input type="checkbox" name="bx_cookie_options[reprompt]" value="1"> Show the banner to all visitors again on next visit (use after adding new trackers)</label><p class="description">Current consent version: <?php echo esc_html($o['version']); ?></p></td></tr>
            </table>
            <?php submit_button('Save cookie settings'); ?>
        </form>

        <?php if (!empty($o['log_consent'])): ?>
            <h2>Consent log — last 30 days</h2>
            <?php $total = (int) ($stats['total'] ?? 0); ?>
            <p><?php echo esc_html($total); ?> choices recorded.
            <?php if ($total): ?>
                Accepted all: <strong><?php echo esc_html(round(100 * $stats['all_on'] / $total)); ?>%</strong> ·
                Rejected all: <strong><?php echo esc_html(round(100 * $stats['all_off'] / $total)); ?>%</strong> ·
                Custom: <strong><?php echo esc_html(100 - round(100 * $stats['all_on'] / $total) - round(100 * $stats['all_off'] / $total)); ?>%</strong>
            <?php endif; ?></p>
            <?php $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 50", ARRAY_A); ?>
            <?php bx_log_bulk_form_open('consent'); ?>
            <p style="display:flex;gap:8px;">
                <?php bx_log_bulk_button(); ?>
                <a class="button button-secondary" href="<?php echo esc_url(bx_log_clear_url('consent')); ?>" onclick="return confirm('Clear the whole consent log?');">Clear All</a>
            </p>
            <table class="wp-list-table widefat fixed striped" style="max-width:980px">
                <thead><tr><th style="width:30px"><input type="checkbox" class="bx-check-all"></th><th>Consent ID</th><th>Analytics</th><th>Marketing</th><th>Version</th><th>Page</th><th>Date</th><th style="width:80px;text-align:center">Delete</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr><td><input type="checkbox" name="ids[]" value="<?php echo esc_attr($r['id']); ?>"></td><td><code><?php echo esc_html($r['consent_id']); ?></code></td><td><?php echo $r['analytics'] ? 'Yes' : 'No'; ?></td><td><?php echo $r['marketing'] ? 'Yes' : 'No'; ?></td><td><?php echo esc_html($r['version']); ?></td><td><?php echo esc_html($r['page']); ?></td><td><?php echo esc_html($r['created_at']); ?></td>
                        <td style="text-align:center"><a href="<?php echo esc_url(bx_log_delete_url('consent', $r['id'])); ?>" class="button button-small" style="color:#b91c1c" onclick="return confirm('Delete this record?');">Delete</a></td></tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" style="text-align:center">No choices recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </form>
            <?php bx_log_bulk_script(); ?>
        <?php endif; ?>
    </div>
    <?php
}
