<?php
/**
 * Attachment URL rewriter.
 *
 * Hooks into wp_get_attachment_url to rewrite raw attachment URLs for
 * image files. This catches direct calls to wp_get_attachment_url()
 * that bypass wp_get_attachment_image_src (used by some themes,
 * plugins, and REST endpoints).
 *
 * Only rewrites URLs with image file extensions — PDFs, videos, and
 * other non-image attachments are passed through unchanged.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class AttachmentUrlRewriter
{
    private UrlRewriter $rewriter;

    /**
     * Image file extensions that should be rewritten. Lowercase.
     * SVG is excluded — imgproxy can handle it but it's already
     * vector and typically tiny; rewriting adds no value.
     */
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
        'bmp', 'tiff', 'tif', 'heic', 'heif',
    ];

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for wp_get_attachment_url.
     *
     * @param string $url The attachment URL.
     * @param int $attachmentId The attachment ID.
     * @return string
     */
    public function rewrite(string $url, int $attachmentId): string
    {
        if ($url === '') {
            return $url;
        }

        if (!$this->isImageUrl($url)) {
            return $url;
        }

        $result = $this->rewriter->rewrite($url, 0, 0, 'attachment_url');

        return $result->rewritten ? $result->url : $url;
    }

    /**
     * Check if a URL points to an image file based on its extension.
     */
    private function isImageUrl(string $url): bool
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }
}
