<?php
/**
 * Brandix PowerKit — Image Optimizer module.
 *
 * Runs on every NEW upload (Media Library, Elementor, sideloads):
 *   1. Applies the camera's EXIF rotation, then shrinks images larger than the max dimension.
 *   2. Re-compresses at the chosen quality.
 *   3. Optionally converts JPEG/PNG to WebP (if the server supports it).
 * The original huge file is not kept, so disk usage drops too. WordPress then builds its
 * thumbnails from the optimized file. GIF (animation) and SVG are never touched.
 *
 * Existing images are deliberately NOT bulk-converted: Elementor stores image URLs inside
 * page data, and changing .jpg to .webp on old files would break those pages.
 */
if (!defined('ABSPATH')) exit;

function bx_img_settings() {
    $o = get_option('sb_options', []);
    return [
        'enabled' => !empty($o['enable_image_optimizer']),
        'max'     => max(0, (int) ($o['img_max_dimension'] ?? 2048)),
        'quality' => max(40, min(100, (int) ($o['img_quality'] ?? 82))),
        'webp'    => !empty($o['img_webp']),
    ];
}

function bx_img_webp_supported() {
    return function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/webp']);
}

add_filter('wp_handle_upload', 'bx_img_optimize_upload', 20, 2);
function bx_img_optimize_upload($upload, $context = 'upload') {
    $s = bx_img_settings();
    if (!$s['enabled'] || !empty($upload['error']) || empty($upload['file'])) return $upload;

    $type = $upload['type'] ?? '';
    if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) return $upload;
    $file = $upload['file'];
    if (!is_file($file) || !is_writable(dirname($file))) return $upload;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) return $upload;

    $before = (int) filesize($file);
    $to_webp = $s['webp'] && $type !== 'image/webp' && bx_img_webp_supported();

    // Phone photos are often stored sideways with an EXIF flag; bake the rotation in before
    // re-saving, because re-saving drops the EXIF flag.
    if ($type === 'image/jpeg' && method_exists($editor, 'maybe_exif_rotate')) $editor->maybe_exif_rotate();

    $resized = false;
    $size = $editor->get_size();
    if ($s['max'] > 0 && !empty($size['width']) && ($size['width'] > $s['max'] || $size['height'] > $s['max'])) {
        $resized = !is_wp_error($editor->resize($s['max'], $s['max'], false));
    }

    // Re-saving a PNG without resizing or converting gains nothing (PNG is lossless).
    if ($type === 'image/png' && !$resized && !$to_webp) return $upload;

    $editor->set_quality($s['quality']);
    $info = pathinfo($file);

    if ($to_webp) {
        $dest_name = wp_unique_filename($info['dirname'], $info['filename'] . '.webp');
        $saved = $editor->save($info['dirname'] . '/' . $dest_name, 'image/webp');
    } else {
        // Save next to the original first; only replace it if the result is actually smaller.
        $saved = $editor->save($info['dirname'] . '/' . $info['filename'] . '-bxtmp.' . $info['extension'], $type);
    }
    if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) return $upload;

    $after = (int) filesize($saved['path']);
    if (!$resized && $after >= $before) {
        // Already well compressed: keep the original untouched.
        @unlink($saved['path']);
        return $upload;
    }

    if ($to_webp) {
        @unlink($file);
        $upload['file'] = $saved['path'];
        $upload['url']  = trailingslashit(dirname($upload['url'])) . wp_basename($saved['path']);
        $upload['type'] = 'image/webp';
    } else {
        if (!@rename($saved['path'], $file)) { @unlink($saved['path']); return $upload; }
    }

    // Running totals for the dashboard.
    $stats = get_option('bx_image_stats', ['count' => 0, 'saved' => 0]);
    $stats['count'] = (int) $stats['count'] + 1;
    $stats['saved'] = (int) $stats['saved'] + max(0, $before - $after);
    update_option('bx_image_stats', $stats, false);

    return $upload;
}

/** Thumbnails use the same quality setting. */
add_filter('wp_editor_set_quality', function ($quality, $mime = '') {
    $s = bx_img_settings();
    return $s['enabled'] ? $s['quality'] : $quality;
}, 20, 2);

/** Keep WordPress' "-scaled" copy in line with our max size (WP default is 2560px). */
add_filter('big_image_size_threshold', function ($threshold) {
    $s = bx_img_settings();
    return ($s['enabled'] && $s['max'] > 0) ? $s['max'] : $threshold;
}, 20);

/** Dashboard card (rendered inside the main Brandix Kit settings form). */
function bx_img_settings_card($options) {
    $stats = get_option('bx_image_stats', ['count' => 0, 'saved' => 0]);
    $webp_ok = bx_img_webp_supported();
    $editor_ok = function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/jpeg']);
    ?>
    <div class="bx-card" data-tab="images">
        <div class="bx-header"><h2>Image Optimizer (new uploads)</h2><span class="bx-badge">Speed</span></div>
        <div class="bx-body">
            <?php if (!$editor_ok): ?>
                <p style="color:#b91c1c;font-weight:600">This server has no image library (GD or Imagick) available to PHP, so images cannot be optimized here.</p>
            <?php endif; ?>
            <div class="bx-grid-field">
                <div><div class="bx-label-title">Optimize images on upload</div><div class="bx-label-desc">Resizes oversized photos and re-compresses them before WordPress makes thumbnails. GIF and SVG are not touched. Existing images are not changed.</div></div>
                <label class="bx-switch"><input type="checkbox" name="sb_options[enable_image_optimizer]" value="1" <?php checked($options['enable_image_optimizer'] ?? 0, 1); ?>><span class="bx-slider"></span></label>
            </div>
            <div class="bx-grid-field">
                <div><div class="bx-label-title">Max width / height</div><div class="bx-label-desc">Longest side in pixels. 2048 suits full-width hero images; 0 = no resizing.</div></div>
                <input type="number" min="0" max="6000" step="1" name="sb_options[img_max_dimension]" class="bx-input" style="width:100px" value="<?php echo esc_attr($options['img_max_dimension'] ?? 2048); ?>">
            </div>
            <div class="bx-grid-field">
                <div><div class="bx-label-title">Quality</div><div class="bx-label-desc">40–100. 80–85 looks identical to the eye at a fraction of the size.</div></div>
                <input type="number" min="40" max="100" step="1" name="sb_options[img_quality]" class="bx-input" style="width:100px" value="<?php echo esc_attr($options['img_quality'] ?? 82); ?>">
            </div>
            <div class="bx-grid-field">
                <div><div class="bx-label-title">Convert JPEG/PNG to WebP</div><div class="bx-label-desc"><?php echo $webp_ok ? 'Usually 25–35% smaller than JPEG. Supported by all current browsers.' : '<strong style="color:#b91c1c">Not available: this server\'s image library has no WebP support.</strong>'; ?></div></div>
                <label class="bx-switch"><input type="checkbox" name="sb_options[img_webp]" value="1" <?php checked($options['img_webp'] ?? 0, 1); ?> <?php disabled(!$webp_ok); ?>><span class="bx-slider"></span></label>
            </div>
            <p class="bx-label-desc" style="margin:14px 0 0">Optimized so far: <strong><?php echo esc_html(number_format_i18n((int) $stats['count'])); ?></strong> images, <strong><?php echo esc_html((int) $stats['saved'] > 0 ? size_format((int) $stats['saved'], 1) : '0 MB'); ?></strong> saved.</p>
        </div>
    </div>
    <?php
}
