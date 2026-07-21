<?php
/**
 * image_get_intermediate_size filter rewriter.
 *
 * WordPress core builds intermediate size URLs by:
 *   $file_url = wp_get_attachment_url($post_id);   // already rewritten to imgproxy
 *   $data['url'] = path_join(dirname($file_url), $data['file']);
 *
 * When the attachment URL has been rewritten to an encoded imgproxy URL
 * (e.g. /imgproxy/{sig}/rs:fill:330:220/bG9jYWw...), path_join replaces
 * the encoded source segment with the intermediate filename basename
 * (e.g. /imgproxy/{sig}/rs:fill:330:220/3-41-330x220.webp) — a URL that
 * imgproxy rejects with 403 "Invalid signature" because the source
 * segment is no longer a valid encoded source.
 *
 * This filter intercepts image_get_intermediate_size AFTER WordPress core
 * has built $data (with correct path/file/width/height) and REBUILDS the
 * url field by passing the ORIGINAL intermediate file URL through the
 * UrlRewriter. The original URL is reconstructed via
 * AttachmentOriginResolver (reads _wp_attached_file metadata directly,
 * bypassing the wp_get_attachment_url filter chain).
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class IntermediateSizeRewriter
{
    use RecursionGuard;

    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for image_get_intermediate_size.
     *
     * @param array|false $data Intermediate size data: ['file', 'width', 'height',
     *                          'mime-type', 'path', 'url'].
     * @param int $postId Attachment ID.
     * @param string|int[] $size Requested size name or [w, h] array.
     * @return array|false Rewritten data with correct imgproxy url, or original on failure.
     */
    public function rewrite($data, int $postId, $size)
    {
        if (!is_array($data) || empty($data['file'])) {
            return $data;
        }

        if ($this->inGuard()) {
            return $data;
        }

        // Resolve the ORIGINAL (unrewritten) attachment URL via the shared
        // resolver — bypasses the wp_get_attachment_url filter chain that
        // AttachmentUrlRewriter hooks.
        $originalUrl = AttachmentOriginResolver::resolveOriginalUrl($postId);
        if ($originalUrl === null) {
            return $data;
        }

        $intermediateUrl = AttachmentOriginResolver::buildIntermediateUrl($originalUrl, $data['file']);
        if ($intermediateUrl === null) {
            return $data;
        }

        $this->enterGuard();
        try {
            $width = isset($data['width']) ? (int) $data['width'] : 0;
            $height = isset($data['height']) ? (int) $data['height'] : 0;

            $result = $this->rewriter->rewrite($intermediateUrl, $width, $height, 'intermediate_size');

            if ($result->rewritten) {
                $data['url'] = $result->url;
            }
        } finally {
            $this->exitGuard();
        }

        return $data;
    }
}
