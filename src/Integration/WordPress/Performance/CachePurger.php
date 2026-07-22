<?php
/**
 * CachePurger — flush cache-plugin page caches on delivery settings save.
 *
 * #43 Phase 4 (plan B.3 / D.4 #5): when our delivery settings change
 * (endpoint / rewrite-capability / signing key/salt / enabled), pages
 * already cached by a caching plugin have BAKED the OLD image URLs
 * (clean vs ?k=). This fires each cache plugin's standard purge hook
 * so those pages regenerate with the new URLs.
 *
 * Design constraints (plan B.3 / D.4 #5):
 *   - Each plugin purge is individually guarded — a no-op when that
 *     plugin is absent (function_exists / class_exists / has_action).
 *   - Each plugin purge is individually try/caught — one plugin's
 *     failure can't break the others or the settings-save. NEVER fatal.
 *   - Dependency-light: pure WP primitives only. No hard-depend on any
 *     cache plugin's classes.
 *   - A generic escape hatch (do_action('oxpulse_purge_page_cache'))
 *     always fires so operators / other plugins can hook their own
 *     purge.
 *
 * @package OXPulse\Imager\Integration\WordPress\Performance
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Performance;

class CachePurger
{
    /**
     * Fire every supported cache-plugin purge + the generic escape hatch.
     *
     * Each sub-purge is guarded + isolated; this method never throws.
     */
    public function purge(): void
    {
        $this->purgeWpRocket();
        $this->purgeW3TotalCache();
        $this->purgeLiteSpeed();
        $this->purgeWpSuperCache();
        $this->purgeCacheEnabler();
        $this->purgeGeneric();
    }

    protected function purgeWpRocket(): void
    {
        if (!$this->wpRocketAvailable()) {
            return;
        }
        try {
            // rocket_clean_domain() performs the full-domain purge AND
            // fires the `after_rocket_clean_domain` action itself, so we
            // must NOT fire that action manually (it would run listeners —
            // e.g. CDN-purge add-ons — twice, and out of order).
            rocket_clean_domain();
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function purgeW3TotalCache(): void
    {
        try {
            if ($this->w3tcAvailable()) {
                w3tc_flush_all();
            } else {
                // Future-proofing only: when w3tc_flush_all() is absent
                // W3TC isn't loaded, so nothing listens on this action
                // (effective no-op). Kept in case a future W3TC exposes
                // the flush purely as an action.
                do_action('w3tc_flush_all');
            }
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function purgeLiteSpeed(): void
    {
        if (!$this->litespeedAvailable()) {
            return;
        }
        try {
            do_action('litespeed_purge_all');
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function purgeWpSuperCache(): void
    {
        if (!$this->wpSuperCacheAvailable()) {
            return;
        }
        try {
            wp_cache_clear_cache();
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function purgeCacheEnabler(): void
    {
        if (!$this->cacheEnablerAvailable()) {
            return;
        }
        try {
            do_action('cache_enabler_clear_complete_cache');
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function purgeGeneric(): void
    {
        try {
            do_action('oxpulse_purge_page_cache');
        } catch (\Throwable $e) {
            // Never fatal during settings-save.
        }
    }

    protected function wpRocketAvailable(): bool
    {
        return function_exists('rocket_clean_domain');
    }

    protected function w3tcAvailable(): bool
    {
        return function_exists('w3tc_flush_all');
    }

    protected function litespeedAvailable(): bool
    {
        return class_exists('\LiteSpeed\Purge');
    }

    protected function wpSuperCacheAvailable(): bool
    {
        return function_exists('wp_cache_clear_cache');
    }

    protected function cacheEnablerAvailable(): bool
    {
        return has_action('cache_enabler_clear_complete_cache') !== false;
    }
}
