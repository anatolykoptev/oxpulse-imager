<?php
/**
 * Attachment image src rewriter.
 *
 * Hooks into wp_get_attachment_image_src to rewrite the URL of
 * attachment images (featured images, gallery thumbnails, etc.).
 * Receives the already-resolved [url, width, height, is_intermediate]
 * array and rewrites only the URL, preserving dimensions.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class AttachmentImageSrcRewriter
{
    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for wp_get_attachment_image_src.
     *
     * @param array|false $image {
     *     @type string $0 Image URL.
     *     @type int    $1 Image width.
     *     @type int    $2 Image height.
     *     @type bool   $3 Whether the image is an intermediate size.
     * }
     * @param int $attachmentId The attachment ID.
     * @param string|int[] $size The requested size.
     * @param bool $icon Whether to use an icon.
     * @return array|false
     */
    public function rewrite($image, int $attachmentId, $size, bool $icon)
    {
        if (!is_array($image) || !isset($image[0])) {
            return $image;
        }

        $url = (string) $image[0];
        $width = isset($image[1]) ? (int) $image[1] : 0;
        $height = isset($image[2]) ? (int) $image[2] : 0;

        $result = $this->rewriter->rewrite($url, $width, $height, 'attachment');

        if ($result->rewritten) {
            $image[0] = $result->url;
        }

        return $image;
    }
}
