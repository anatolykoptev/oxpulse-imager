<?php
/**
 * Attachment image srcset restorer.
 *
 * Fixes the HiDPI srcset regression caused by ImageDownsizeRewriter.
 *
 * Background: ImageDownsizeRewriter hooks image_downsize at priority 99
 * and short-circuits it by returning a proxied imgproxy URL. WordPress
 * core then uses that proxied URL as $image_src when computing srcset in
 * wp_get_attachment_image(). wp_calculate_image_srcset() resolves
 * candidates by matching the src basename against $image_meta['sizes']
 * (it needs the -WxH-suffixed upload filename), so a proxied
 * /imgproxy/<sig>/rs:fill:.../<b64> src matches nothing and core returns
 * false — no srcset, no sizes. Every attachment image is rendered as a
 * single bitmap resized to its CSS box, which a DPR-2/DPR-3 device
 * upscales → visibly blurry on retina.
 *
 * Fix: hook wp_get_attachment_image_attributes (fires AFTER core's
 * srcset/sizes computation in wp_get_attachment_image()). When the
 * attributes array lacks a srcset (core returned false), resolve the
 * ORIGINAL (unproxied) attachment URL via AttachmentOriginResolver,
 * re-run wp_calculate_image_srcset() with the original src so core can
 * match the upload filenames and build its candidate list, then let the
 * existing SrcsetRewriter (hooked on wp_calculate_image_srcset) proxy
 * each candidate. Also re-run wp_calculate_image_sizes() for the sizes
 * attribute. This is WordPress-documented behaviour — the filter exists
 * precisely for this kind of post-hoc attribute correction.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

final class AttachmentImageSrcsetRestorer
{
    /**
     * Filter callback for wp_get_attachment_image_attributes.
     *
     * Restores srcset + sizes when core could not build them because the
     * src was proxied by ImageDownsizeRewriter. Resolves the original
     * (unproxied) attachment URL, re-runs wp_calculate_image_srcset()
     * and wp_calculate_image_sizes() with it — core matches the upload
     * filenames and builds candidates, then SrcsetRewriter proxies each
     * candidate via the existing wp_calculate_image_srcset filter.
     *
     * @param array    $attr       Image attribute array (may already have srcset/sizes).
     * @param \WP_Post $attachment The attachment post object.
     * @param string|int[] $size   Requested size name or [w, h] array.
     * @return array
     */
    public function restore(array $attr, $attachment, $size): array
    {
        // If core already built a srcset, leave it alone — the existing
        // SrcsetRewriter already proxied the candidates. Only act when
        // srcset is missing (the regression case).
        if (!empty($attr['srcset'])) {
            return $attr;
        }

        // Need width + height to build the size_array for core. These
        // are set by wp_get_attachment_image() before the filter fires.
        $width = isset($attr['width']) ? (int) $attr['width'] : 0;
        $height = isset($attr['height']) ? (int) $attr['height'] : 0;
        if ($width < 1) {
            return $attr;
        }

        // Resolve the attachment ID from the post object.
        $attachmentId = 0;
        if (is_object($attachment) && isset($attachment->ID)) {
            $attachmentId = (int) $attachment->ID;
        } elseif (is_object($attachment) && method_exists($attachment, 'get_id')) {
            $attachmentId = (int) $attachment->get_id();
        }
        if ($attachmentId < 1) {
            return $attr;
        }

        // Resolve the ORIGINAL (unproxied) attachment URL directly from
        // _wp_attached_file metadata, bypassing the wp_get_attachment_url
        // filter chain (hooked by AttachmentUrlRewriter). This is the URL
        // core needs to match $image_meta['sizes'] basenames.
        $originalSrc = AttachmentOriginResolver::resolveOriginalUrl($attachmentId);
        if ($originalSrc === null) {
            return $attr;
        }

        $imageMeta = wp_get_attachment_metadata($attachmentId);
        if (!is_array($imageMeta)) {
            return $attr;
        }

        $sizeArray = [$width, $height];

        // Re-run core's srcset computation with the ORIGINAL src. Core
        // matches the upload filename basename against $image_meta['sizes'],
        // builds the candidate list, then SrcsetRewriter (hooked on
        // wp_calculate_image_srcset) proxies each candidate URL.
        $srcset = wp_calculate_image_srcset($sizeArray, $originalSrc, $imageMeta, $attachmentId);

        if ($srcset) {
            $attr['srcset'] = $srcset;

            // Re-run core's sizes computation with the original src.
            // wp_calculate_image_sizes needs a valid width (from sizeArray)
            // to produce "(max-width: Wpx) 100vw, Wpx".
            if (empty($attr['sizes'])) {
                $sizes = wp_calculate_image_sizes($sizeArray, $originalSrc, $imageMeta, $attachmentId);
                if ($sizes) {
                    $attr['sizes'] = $sizes;
                }
            }
        }

        return $attr;
    }
}
