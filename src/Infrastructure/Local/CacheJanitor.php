<?php
/**
 * LocalBackend cache LRU eviction janitor (#93).
 *
 * The LocalBackend on-disk cache (WP_CONTENT_DIR/cache/oxpulse/) grows
 * unbounded — one file per transform variant per signed key per format.
 * A large media library × many sizes/formats can fill the disk.
 *
 * This janitor bounds the cache: it computes the total size of the cache
 * image files (webp/avif only), and when that exceeds a cap (MB) it
 * evicts least-recently-used files (LRU proxy = file mtime — atime is
 * unreliable under the relatime mount default) until the total drops
 * under a low-water mark (90% of the cap by default). Hardened files
 * (index.html, .htaccess) and non-image artifacts (.lock, .tmp) are
 * never counted or evicted — only cache image files.
 *
 * Path-safety: every deletion reuses Uninstaller::isWithinRoot() — the
 * same realpath containment guard the uninstall recursive-delete uses —
 * so a symlink traversal or .. path can never delete outside the cache
 * root. Deletion uses wp_delete_file() to satisfy Plugin Check.
 *
 * Invoked by the recurring oxpulse_cache_cleanup WP-cron event (wired in
 * ServiceRegistrar like the #81 imgproxy-health cron). The cleanup is a
 * no-op when LocalBackend isn't the active tier (imgproxy sites have no
 * local cache) — guarded in ServiceRegistrar::runCacheCleanup().
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Infrastructure\WordPress\Uninstaller;

final class CacheJanitor
{
    /**
     * Low-water mark as a percentage of the cap. Eviction stops once the
     * total cache size drops to this fraction of the cap, so the cache
     * isn't evicted down to the cap edge (which would re-evict on every
     * run as new files arrive).
     */
    public const DEFAULT_LOW_WATER_PERCENT = 90;

    /**
     * Cache image file extensions (the format segment of the cache
     * filename). Matches MissEndpointHandler::ALLOWED_FORMATS — only
     * these are real cache entries; everything else (index.html,
     * .htaccess, .lock sidecars, .tmp.PID atomics) is skipped.
     */
    public const CACHE_IMAGE_EXTENSIONS = ['webp', 'avif'];

    /**
     * Upper bound on files enumerated per run, so a pathological cache
     * dir cannot stall a cron tick. Correctness first: when the cap is
     * exceeded, the oldest of the enumerated files are evicted, which
     * bounds the cache even if not every file was seen.
     */
    public const MAX_FILES_PER_RUN = 5000;

    public function __construct(
        private string $cacheDir,
    ) {}

    /**
     * Run one LRU eviction pass.
     *
     * @param int $capMb             Cache size cap in megabytes. A
     *        non-positive value disables eviction (no-op) — lets an
     *        operator turn the cap off via the oxpulse_cache_max_mb
     *        filter returning 0.
     * @param int $lowWaterPercent  Eviction stops once the total cache
     *        size is at or below this percentage of the cap. Default 90.
     * @return int Number of cache image files evicted.
     */
    public function run(int $capMb, int $lowWaterPercent = self::DEFAULT_LOW_WATER_PERCENT): int
    {
        if ($capMb <= 0) {
            return 0;
        }

        $realRoot = realpath($this->cacheDir);
        if ($realRoot === false || !is_dir($realRoot)) {
            return 0;
        }

        $capBytes = $capMb * 1024 * 1024;
        $lowWaterBytes = (int) floor($capBytes * max(1, min(100, $lowWaterPercent)) / 100);

        // Enumerate cache image files (size + mtime), bounded.
        $files = [];
        $totalBytes = 0;
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $ext = strtolower($entry->getExtension());
            if (!in_array($ext, self::CACHE_IMAGE_EXTENSIONS, true)) {
                continue;
            }
            $files[] = [
                'path' => $entry->getPathname(),
                'size' => (int) $entry->getSize(),
                'mtime' => (int) $entry->getMTime(),
            ];
            $totalBytes += (int) $entry->getSize();
            if (++$count >= self::MAX_FILES_PER_RUN) {
                break;
            }
        }

        if ($totalBytes <= $capBytes) {
            return 0;
        }

        // LRU proxy = mtime ascending (oldest first). atime is
        // unreliable under relatime, so mtime is the LRU signal.
        usort($files, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        $evicted = 0;
        foreach ($files as $f) {
            if ($totalBytes <= $lowWaterBytes) {
                break;
            }
            // Path-safety: reuse the SAME realpath containment guard as
            // Uninstaller::removeDirectory — a symlinked dir entry that
            // resolves outside the cache root must never be deleted.
            if (!Uninstaller::isWithinRoot($f['path'], $realRoot)) {
                continue;
            }
            $real = realpath($f['path']);
            if ($real === false || !is_file($real)) {
                continue;
            }
            wp_delete_file($real);
            if (!is_file($real)) {
                $totalBytes -= $f['size'];
                $evicted++;
            }
        }

        return $evicted;
    }
}
