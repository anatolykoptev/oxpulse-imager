<?php
/**
 * WP-CLI `wp oxpulse flush` command.
 *
 * Clears WordPress object cache entries related to OXPulse Imager.
 * Currently clears:
 * - The health check transient
 * - Any cached rewrite results (if a cache layer is configured)
 *
 * Does NOT clear imgproxy's own cache (that's a server-side concern —
 * use `imgproxy`'s cache purge or your CDN's purge API).
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

final class FlushCommand extends AbstractCommand
{
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

        $this->success(sprintf(__('Flushed %d cache entry/entries.', 'oxpulse-imager'), $cleared));
        $this->log(__('Note: imgproxy\'s own cache is not cleared — use your CDN/imgproxy purge API for that.', 'oxpulse-imager'));
    }
}
