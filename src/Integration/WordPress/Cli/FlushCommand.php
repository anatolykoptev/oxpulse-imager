<?php
/**
 * WP-CLI `wp oxpulse flush` command.
 *
 * Clears OXPulse Imager caches:
 * - The health check transient
 * - The local delivery cache directory (wp-content/cache/oxpulse/) when
 *   LocalBackend is active — this is the REAL cache purge (Phase 6).
 * - Any cached rewrite results via wp_cache_flush_group (best-effort).
 *
 * Does NOT clear imgproxy's own cache (that's a server-side concern —
 * use imgproxy's cache purge or your CDN's purge API).
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Infrastructure\Local\CacheInvalidator;

final class FlushCommand extends AbstractCommand
{
    private ?string $cacheDir;

    public function __construct(?OptionSettingsRepository $repository = null, ?string $cacheDir = null)
    {
        parent::__construct($repository);
        $this->cacheDir = $cacheDir;
    }

    /**
     * Flush OXPulse Imager caches.
     *
     * ## EXAMPLES
     *
     *     wp oxpulse flush
     *
     * @param array $args       Positional args (unused).
     * @param array $assoc_args Associative args (unused).
     */
    public function flush(array $args, array $assoc_args): void
    {
        $cleared = 0;

        // Health check transient (matches the one deleted in
        // oxpulse_imager_deactivate()).
        if (function_exists('delete_transient')) {
            delete_transient('oxpulse_imager_health_check');
            $cleared++;
        }

        // Clear any rewrite cache groups if an object cache is present.
        if (function_exists('wp_cache_flush_group')) {
            // WP 6.1+ supports group flush. Best-effort — ignore failures.
            @wp_cache_flush_group('oxpulse_imager');
            $cleared++;
        }

        // Purge the local delivery cache directory (Phase 6).
        $cacheDir = $this->cacheDir ?? $this->resolveCacheDir();
        if ($cacheDir !== null && is_dir($cacheDir)) {
            $invalidator = new CacheInvalidator($cacheDir);
            $purged = $invalidator->purgeAll();
            if ($purged > 0) {
                $this->log(sprintf(
                    __('Purged %d entries from the local cache (%s).', 'oxpulse-imager'),
                    $purged,
                    $cacheDir
                ));
                $cleared += $purged;
            }
        }

        $this->success(sprintf(__('Flushed %d cache entry/entries.', 'oxpulse-imager'), $cleared));
        $this->log(__('Note: imgproxy\'s own cache is not cleared — use your CDN/imgproxy purge API for that.', 'oxpulse-imager'));
    }

    /**
     * Resolve the local cache directory path.
     *
     * Uses WP_CONTENT_DIR when available, falls back to null when the
     * path cannot be determined (e.g. in the stub test environment
     * without WP_CONTENT_DIR).
     */
    private function resolveCacheDir(): ?string
    {
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/cache/oxpulse';
        }

        return null;
    }
}
