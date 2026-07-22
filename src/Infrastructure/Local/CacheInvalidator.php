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

use OXPulse\Imager\Domain\Source\NormalizedUrl;
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
     * The source URLs are normalized via NormalizedUrl (the same
     * canonicalization UrlRewriter applies at GENERATION) before
     * hashing, so the invalidation hash matches the generation hash
     * even when the raw uploads baseurl carries a default port
     * (https://host:443/...) or an uppercase host. Without this, the
     * two hashes diverge and invalidation silently misses the
     * generated cache dir.
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
            $normalized = $this->normalizeForHash($url);
            if ($normalized === null) {
                continue;
            }
            $hash = LocalBackend::sourceHash($normalized);
            $dir = $this->cacheDir . '/' . $hash;
            if (is_dir($dir)) {
                $this->rmrf($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Normalize a source URL to the same canonical form UrlRewriter
     * produces at generation time (NormalizedUrl::__toString: scheme
     * + host lowercased, default port stripped, fragment stripped).
     *
     * Returns null when the URL is malformed (skipped — fail-safe,
     * no dir to delete for an unparseable URL).
     */
    private function normalizeForHash(string $url): ?string
    {
        try {
            return (string) NormalizedUrl::parse($url);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
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
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cache purge; wp_delete_file has FTP-fallback side effects that change behavior.
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.unlink_unlink -- recursive cache purge; WP_Filesystem lacks recursive rmdir semantics and would change behavior.
            $file->isDir() ? rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- recursive cache purge; WP_Filesystem lacks recursive rmdir semantics.
        rmdir($dir);
    }
}
