<?php
/**
 * Original (unrewritten) attachment URL resolver.
 *
 * Reads the _wp_attached_file metadata directly and builds the WordPress
 * uploads URL from it — bypassing the wp_get_attachment_url filter chain
 * entirely. The filter is hooked by AttachmentUrlRewriter and returns an
 * already-rewritten imgproxy URL, which is useless for callers that need
 * the original uploads URL (e.g. to build intermediate size URLs via
 * path_join, or to resolve the on-disk filesystem path).
 *
 * Shared by IntermediateSizeRewriter and ImageDownsizeRewriter so both
 * can resolve the original URL without re-entering the rewriting pipeline.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

final class AttachmentOriginResolver
{
    /**
     * Resolve the original (unrewritten) attachment URL for a post ID.
     *
     * @param int $postId Attachment post ID.
     * @return string|null Original uploads URL (e.g.
     *         https://site.example/wp-content/uploads/2026/07/3-41.webp),
     *         or null when metadata is missing or uploads base URL is empty.
     */
    public static function resolveOriginalUrl(int $postId): ?string
    {
        $attachedFile = get_post_meta($postId, '_wp_attached_file', true);
        if (!is_string($attachedFile) || $attachedFile === '') {
            return null;
        }

        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl'])) {
            return null;
        }

        // $attachedFile is relative to the uploads base (e.g. "2026/07/3-41.webp").
        return rtrim($uploads['baseurl'], '/') . '/' . ltrim($attachedFile, '/');
    }

    /**
     * Build the intermediate file URL by replacing the basename of the
     * original attachment URL with the intermediate filename.
     *
     * The intermediate file lives in the same directory as the original
     * attachment (WordPress stores them side-by-side in uploads/YYYY/MM/).
     *
     * @param string $originalUrl Original attachment URL.
     * @param string $intermediateFile Intermediate filename basename (e.g. "3-41-330x220.webp").
     * @return string|null Intermediate file URL, or null on parse failure.
     */
    public static function buildIntermediateUrl(string $originalUrl, string $intermediateFile): ?string
    {
        $path = wp_parse_url($originalUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $dir = dirname($path);
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }

        $newPath = $dir . '/' . $intermediateFile;

        $parsed = wp_parse_url($originalUrl);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return null;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        return $scheme . '://' . $host . $port . $newPath . $query;
    }
}
