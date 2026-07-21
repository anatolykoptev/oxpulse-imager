<?php
/**
 * Cache invalidator for local delivery (Phase 6, Dispatch 2).
 *
 * Per-attachment invalidation WITHOUT an index: the cache is laid out
 * by sourceHash (cache/oxpulse/<sourceHash>/<key>.<fmt>), so
 * invalidation = deleting the <sourceHash>/ directory for each of the
 * attachment's source URLs (original + all intermediate sizes).
 *
 * Hooks: wp_update_attachment_metadata / delete_attachment /
 * clean_post_cache → invalidateAttachment($postId).
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentOriginResolver;

final class CacheInvalidator
{
    public function __construct(
        private string $cacheDir,
    ) {}

    /**
     * Invalidate all cached variants for an attachment.
     *
     * Enumerates the attachment's original URL + all intermediate size
     * URLs, computes the sourceHash for each, and deletes the matching
     * cache directories.
     *
     * @param int $attachmentId The attachment post ID.
     * @return int Number of sourceHash directories deleted.
     */
    public function invalidateAttachment(int $attachmentId): int
    {
        $sourceUrls = $this->collectSourceUrls($attachmentId);
        if ($sourceUrls === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($sourceUrls as $url) {
            $hash = LocalBackend::sourceHash($url);
            $dir = $this->cacheDir . '/' . $hash;
            if (is_dir($dir)) {
                $this->rmrf($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Purge the entire local cache directory.
     *
     * Used by `wp oxpulse flush` and on-demand cache clear.
     *
     * @return int Number of top-level entries (sourceHash dirs) removed.
     */
    public function purgeAll(): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $count = 0;
        $it = new \DirectoryIterator($this->cacheDir);
        foreach ($it as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @unlink($path);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Collect all source URLs for an attachment (original + sizes).
     *
     * @param int $attachmentId
     * @return list<string>
     */
    private function collectSourceUrls(int $attachmentId): array
    {
        $urls = [];

        // Original URL via AttachmentOriginResolver (bypasses the
        // wp_get_attachment_url filter chain which may return
        // already-rewritten URLs).
        $originalUrl = AttachmentOriginResolver::resolveOriginalUrl($attachmentId);
        if ($originalUrl !== null) {
            $urls[] = $originalUrl;
        }

        // Intermediate size URLs.
        $metadata = function_exists('wp_get_attachment_metadata')
            ? wp_get_attachment_metadata($attachmentId)
            : false;

        if (is_array($metadata) && isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base = $originalUrl;
            if ($base !== null) {
                foreach ($metadata['sizes'] as $size) {
                    if (!isset($size['file']) || !is_string($size['file'])) {
                        continue;
                    }
                    $intermediateUrl = AttachmentOriginResolver::buildIntermediateUrl($base, $size['file']);
                    if ($intermediateUrl !== null) {
                        $urls[] = $intermediateUrl;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
