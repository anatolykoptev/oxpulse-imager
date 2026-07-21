<?php
/**
 * image_downsize filter rewriter.
 *
 * Hooks into image_downsize at priority 99 (late, to override earlier
 * filters) so that plugins/themes calling image_downsize() directly —
 * bypassing wp_get_attachment_image_src — get rewritten imgproxy URLs.
 *
 * Recursion guard: wp_get_attachment_url() inside the handler triggers
 * the wp_get_attachment_url filter, which is also hooked by the plugin.
 * A static $inDownsize flag breaks the cycle.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class ImageDownsizeRewriter
{
    private UrlRewriter $rewriter;

    /**
     * Recursion guard. Set while the handler is running so the
     * wp_get_attachment_url filter (called inside this handler via
     * wp_get_attachment_url()) does not re-enter image_downsize.
     */
    private static bool $inDownsize = false;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for image_downsize.
     *
     * @param array|false $out Existing downsize result [url, width, height, is_intermediate], or false.
     * @param int $id Attachment ID.
     * @param string|int[] $size Requested size: 'thumbnail', 'medium', 'full', or [w, h].
     * @return array|false Rewritten [url, width, height, is_intermediate], or false to let WP core handle.
     */
    public function rewrite($out, int $id, $size)
    {
        // Recursion guard — if we're already inside this handler, bail.
        if (self::$inDownsize) {
            return $out;
        }

        // If another filter already produced a result at higher priority,
        // respect it (don't override). We only act when WP core would
        // handle the downsize (false = "let WP core do it").
        if ($out !== false) {
            return $out;
        }

        self::$inDownsize = true;
        try {
            // Get the direct attachment URL. This triggers the
            // wp_get_attachment_url filter (also hooked by the plugin),
            // but the recursion guard prevents re-entry.
            $url = wp_get_attachment_url($id);
            if (!is_string($url) || $url === '') {
                return false;
            }

            // Resolve target dimensions from the requested size.
            [$width, $height] = $this->resolveDimensions($id, $size);

            // Rewrite to imgproxy. UrlRewriter enforces source policy +
            // signing + fail-safe preservation.
            $result = $this->rewriter->rewrite($url, $width, $height, 'downsize');

            if (!$result->rewritten) {
                // Source not allowed / delivery disabled / signing missing —
                // let WP core handle the downsize.
                return false;
            }

            // is_intermediate: true when the returned image is a resized
            // version (not the original). For 'full' size, false; for
            // registered sizes and array sizes, true.
            $isIntermediate = $size !== 'full' && !($width === 0 && $height === 0);

            return [$result->url, $width, $height, $isIntermediate];
        } finally {
            self::$inDownsize = false;
        }
    }

    /**
     * Resolve target dimensions for a requested image size.
     *
     * @param int $id Attachment ID.
     * @param string|int[] $size Size name ('thumbnail', 'medium', 'full', …) or [width, height].
     * @return array{0:int,1:int} [width, height] in pixels. [0, 0] when unknown.
     */
    private function resolveDimensions(int $id, $size): array
    {
        // Array size: [width, height] directly.
        if (is_array($size) && count($size) >= 2) {
            return [(int) $size[0], (int) $size[1]];
        }

        $sizeName = is_string($size) ? $size : '';

        // 'full' or empty: use the original image dimensions from metadata.
        if ($sizeName === 'full' || $sizeName === '') {
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta) && isset($meta['width'], $meta['height'])) {
                return [(int) $meta['width'], (int) $meta['height']];
            }
            return [0, 0];
        }

        // Registered size: look up in attachment metadata first (theme-
        // specific sizes like foxiz_crop_g1 are stored here after
        // regeneration), then fall back to the registered subsize config.
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && isset($meta['sizes'][$sizeName]['width'], $meta['sizes'][$sizeName]['height'])) {
            return [(int) $meta['sizes'][$sizeName]['width'], (int) $meta['sizes'][$sizeName]['height']];
        }

        // Registered subsize (core sizes: thumbnail, medium, medium_large,
        // large, plus any theme-registered sizes). These may not have a
        // generated file yet, but the target dimensions are known.
        $registered = wp_get_registered_image_subsizes();
        if (isset($registered[$sizeName])) {
            $w = (int) ($registered[$sizeName]['width'] ?? 0);
            $h = (int) ($registered[$sizeName]['height'] ?? 0);
            // WP uses 0 as "unlimited" for one dimension. Keep as-is —
            // imgproxy treats 0 as "auto" in rs:fit.
            return [$w, $h];
        }

        // Unknown size — let imgproxy use the original dimensions.
        return [0, 0];
    }
}
