<?php
/**
 * Complete uninstall cleanup (#88).
 *
 * Removes EVERYTHING the plugin created on uninstall: all options
 * (the oxpulse_imager_ prefix family + standalone keys), all cron
 * events, all transients (static + dynamic UUID-suffixed), the on-disk
 * cache directory, the generated endpoint file + cache .htaccess, and
 * per-site cleanup on multisite.
 *
 * Enumerated from a grep of every get_option/update_option/add_option/
 * get_transient/set_transient call in src/ — NOT hardcoded from a spec.
 * The $wpdb prefix-scan is a safety net for future keys that join the
 * family without updating this list.
 *
 * Path-safety: every recursive delete realpath's the target and
 * verifies it is strictly under WP_CONTENT_DIR before unlinking — a
 * misconfigured constant or a symlink traversal can never delete
 * outside the cache root.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

final class Uninstaller
{
    /**
     * All known option keys under the oxpulse_imager_ prefix.
     *
     * Enumerated from OptionSettingsRepository constants (lines 24-77)
     * + activation defaults in oxpulse-imager.php. Every key the plugin
     * writes via update_option/add_option is listed here.
     *
     * @var array<int,string>
     */
    public const PREFIX_OPTIONS = [
        // Core settings (activation defaults + OptionSettingsRepository).
        'oxpulse_imager_enabled',
        'oxpulse_imager_endpoint',
        'oxpulse_imager_key',
        'oxpulse_imager_salt',
        'oxpulse_imager_allowed_sources',
        'oxpulse_imager_remove_on_uninstall',
        'oxpulse_imager_schema_version',
        'oxpulse_imager_onboarded',
        // Delivery / format settings.
        'oxpulse_imager_output_format',
        'oxpulse_imager_default_quality',
        'oxpulse_imager_dev_http_override',
        'oxpulse_imager_diagnostic_level',
        // Enhancement options (Phase 5.1).
        'oxpulse_imager_lqip_enabled',
        'oxpulse_imager_lqip_blur',
        'oxpulse_imager_dpr_enabled',
        'oxpulse_imager_dpr_variants',
        'oxpulse_imager_format_quality',
        'oxpulse_imager_watermark',
        // Source addressing (Ф1).
        'oxpulse_imager_source_mode',
        'oxpulse_imager_local_base_path',
        // Buffer rewriting (Ф2).
        'oxpulse_imager_buffer_rewriting_enabled',
        // <picture> wrapping (Phase 1).
        'oxpulse_imager_picture_enabled',
        // RankMath compatibility (Ф3).
        'oxpulse_imager_rankmath_compatibility',
        // Save-Data (Ф7).
        'oxpulse_imager_save_data_quality_reduction',
        // Size-based quality tiers (Ф8/Ф11).
        'oxpulse_imager_size_quality_tiers',
        // Rewrite-capability probe (#43 Phase 1).
        'oxpulse_imager_rewrite_capability',
        'oxpulse_imager_rewrite_capability_checked_at',
        'oxpulse_imager_probe_version',
        // Admin-notice dismissed keys (#43 Phase 5).
        'oxpulse_imager_admin_notice_dismissed',
    ];

    /**
     * Standalone option keys NOT under the oxpulse_imager_ prefix.
     *
     * @var array<int,string>
     */
    public const STANDALONE_OPTIONS = [
        // ImgproxyHealthCache::OPTION — persistent health verdict (#81).
        'oxpulse_imgproxy_health',
    ];

    /**
     * Known static transient keys (deleted via delete_transient).
     *
     * @var array<int,string>
     */
    public const TRANSIENTS = [
        // Deactivation hook + FlushCommand.
        'oxpulse_imager_health_check',
        // WordPressDiagnosticLogger::RECENT_ENTRIES_TRANSIENT.
        'oxpulse_imager_recent_log',
    ];

    /**
     * Transient name prefixes for dynamic (UUID-suffixed) transients.
     * These are scanned + deleted via $wpdb because the full key is
     * not known at uninstall time.
     *
     * @var array<int,string>
     */
    public const TRANSIENT_PREFIXES = [
        // PrewarmJobStore::TRANSIENT_PREFIX.
        'oxpulse_prewarm_job_',
    ];

    /**
     * All cron hook names the plugin schedules.
     *
     * Enumerated from wp_schedule_event / wp_schedule_single_event
     * calls in src/ and oxpulse-imager.php.
     *
     * @var array<int,string>
     */
    public const CRON_HOOKS = [
        // Recurring imgproxy health re-probe (#81).
        'oxpulse_imgproxy_health_recheck',
        // Async prewarm batch processing.
        'oxpulse_prewarm_process_batch',
    ];

    /**
     * Run the complete uninstall cleanup.
     *
     * Removes all options, cron events, transients, the on-disk cache,
     * and the generated endpoint artifacts. On multisite, iterates
     * every site for per-site options + cron; the cache dir and
     * endpoint file are shared (network-wide) and cleaned once.
     */
    public static function run(): void
    {
        if (function_exists('is_multisite') && is_multisite()) {
            self::cleanMultisite();
        } else {
            self::deleteOptions();
            self::clearCronEvents();
        }

        // Shared resources — cleaned once regardless of multisite.
        self::removeCache();
        self::removeEndpoint();
    }

    /**
     * Delete every known option + transient + prefix-scan.
     *
     * Explicit delete_option for each enumerated key (deterministic,
     * works without $wpdb — e.g. unit-test stub env), PLUS a $wpdb
     * prefix-scan as a production safety net for future keys.
     */
    private static function deleteOptions(): void
    {
        foreach (self::PREFIX_OPTIONS as $option) {
            delete_option($option);
        }
        foreach (self::STANDALONE_OPTIONS as $option) {
            delete_option($option);
        }

        // Network options (multisite) — no known network options exist
        // today, but delete via delete_site_option for future-proofing.
        if (function_exists('delete_site_option')) {
            foreach (self::STANDALONE_OPTIONS as $option) {
                delete_site_option($option);
            }
        }

        // Known static transients.
        foreach (self::TRANSIENTS as $transient) {
            delete_transient($transient);
        }

        // Dynamic UUID-suffixed transients — scan + delete via $wpdb.
        self::deleteTransientPrefixes();

        // Production safety-net: prefix-scan for any oxpulse_imager_
        // options we might have missed (future keys).
        self::prefixScanDelete('oxpulse_imager_');
    }

    /**
     * Scan the options table for dynamic transients matching the
     * enumerated prefixes and delete them + their timeout entries.
     */
    private static function deleteTransientPrefixes(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !property_exists($wpdb, 'options')) {
            return;
        }
        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup; must bypass object cache.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                )
            );
            $likeTimeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $likeTimeout
                )
            );
        }
    }

    /**
     * Prefix-scan DELETE via $wpdb — catches any option whose name
     * starts with the given prefix, including future keys not in the
     * enumerated list.
     */
    private static function prefixScanDelete(string $prefix): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !property_exists($wpdb, 'options')) {
            return;
        }
        $like = $wpdb->esc_like($prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup; must bypass object cache.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
    }

    /**
     * Clear every scheduled cron event for the enumerated hooks.
     */
    private static function clearCronEvents(): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        foreach (self::CRON_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Recursively delete the on-disk cache directory
     * (WP_CONTENT_DIR/cache/oxpulse/), guarded by a realpath
     * startsWith check so a misconfigured constant can never delete
     * outside wp-content.
     */
    private static function removeCache(): void
    {
        if (!defined('WP_CONTENT_DIR')) {
            return;
        }
        $cacheDir = WP_CONTENT_DIR . '/cache/oxpulse';
        self::removeDirectory($cacheDir, WP_CONTENT_DIR);
    }

    /**
     * Remove the generated endpoint file + cache .htaccess.
     *
     * Reuses ServiceRegistrar::uninstallLocalDelivery() which delegates
     * to LocalDeliveryInstaller::uninstall() — the same code path used
     * by the deactivation hook.
     */
    private static function removeEndpoint(): void
    {
        if (class_exists(ServiceRegistrar::class)) {
            ServiceRegistrar::uninstallLocalDelivery();
        }
    }

    /**
     * Recursively delete a directory, guarded by a realpath startsWith
     * containment check.
     *
     * The target must resolve (via realpath, which follows symlinks and
     * collapses ..) to a path STRICTLY UNDER the root. A symlink that
     * points outside the root, or a .. traversal, is refused — nothing
     * is deleted. This is the path-safety guard mandated by #88.
     *
     * @param string $target The directory to delete.
     * @param string $root   The root the target must be contained under.
     */
    public static function removeDirectory(string $target, string $root): void
    {
        $realTarget = realpath($target);
        if ($realTarget === false) {
            return;
        }
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return;
        }

        // Strict containment: target must be UNDER root. The trailing
        // separator prevents prefix collisions (e.g. /foo/bar matching
        // /foo/bar-evil).
        $targetWithSep = $realTarget . DIRECTORY_SEPARATOR;
        $rootWithSep = $realRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($targetWithSep, $rootWithSep)) {
            return;
        }

        if (!is_dir($realTarget)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realTarget, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (is_link($file->getPathname())) {
                @unlink($file->getPathname());
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($realTarget);
    }

    /**
     * Multisite cleanup: iterate every site, switch_to_blog, delete
     * that site's options + cron events, restore_current_blog.
     *
     * The cache dir and endpoint file are shared (network-wide) and
     * cleaned once in run(), NOT per-site.
     */
    private static function cleanMultisite(): void
    {
        if (!function_exists('get_sites') || !function_exists('switch_to_blog')) {
            return;
        }
        $sites = get_sites();
        if (!is_array($sites)) {
            return;
        }
        foreach ($sites as $site) {
            $blogId = is_object($site)
                ? ($site->blog_id ?? null)
                : ($site['blog_id'] ?? null);
            if ($blogId === null) {
                continue;
            }
            switch_to_blog((int) $blogId);
            self::deleteOptions();
            self::clearCronEvents();
            if (function_exists('restore_current_blog')) {
                restore_current_blog();
            }
        }
    }
}
