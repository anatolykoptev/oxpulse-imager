<?php
/**
 * ServiceRegistrar cache-cleanup cron wiring tests (#93).
 *
 * Mirrors ServiceRegistrarImgproxyHealthTest (#81): verifies the
 * recurring LocalBackend cache LRU eviction cron is wired EXACTLY like
 * the imgproxy-health cron —
 *   - activation schedules the recurring event,
 *   - init self-heals (guard-schedules) on upgrade so an in-place
 *     upgrade converges without a deactivate→reactivate,
 *   - init is idempotent (no double-schedule, no timestamp shift),
 *   - deactivation clears the event,
 *   - the cron callback is registered at bootstrap via add_action,
 *   - the callback runs the janitor when LocalBackend is active
 *     (endpoint empty) and is a no-op when ImgproxyBackend is active
 *     (endpoint set — imgproxy sites have no local cache).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarCacheCleanupTest extends TestCase
{
    private string $wpContentDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        // Reuse the same fixed WP_CONTENT_DIR path as
        // ServiceRegistrarMultisiteGateTest (define-once constant) so
        // resolveLocalCacheDir() returns a real path regardless of
        // which test defines it first. Contents are reset per test.
        $this->wpContentDir = '/tmp/oxpulse-ms-test/wp-content';
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->wpContentDir);
        }
        $this->cacheDir = $this->wpContentDir . '/cache/oxpulse';
        $this->resetCacheDir();
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_scheduled_events']
        );
        $this->resetCacheDir();
    }

    private function resetCacheDir(): void
    {
        if (is_dir($this->cacheDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->cacheDir);
        }
    }

    private function seedOverCapCache(): void
    {
        @mkdir($this->cacheDir . '/h1', 0755, true);
        // 2 MB + 512 KB = 2.5 MB; cap (set via option below) = 1 MB,
        // low-water 90% = 900 KB. Evict old (2 MB) → 512 KB < 900 KB,
        // so the newest survives.
        file_put_contents($this->cacheDir . '/h1/old.webp', str_repeat('x', 2 * 1024 * 1024));
        touch($this->cacheDir . '/h1/old.webp', time() - 100);
        file_put_contents($this->cacheDir . '/h1/new.webp', str_repeat('x', 512 * 1024));
        touch($this->cacheDir . '/h1/new.webp', time());
    }

    private function buildPluginStub(): \OXPulse\Imager\Plugin
    {
        $ref = new \ReflectionClass(\OXPulse\Imager\Plugin::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function invokePrivate(string $method, array $args = []): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, $method);
        $ref->setAccessible(true);
        $ref->invoke(null, ...$args);
    }

    // ─── activation / deactivation scheduling ────────────────────────

    public function test_activation_schedules_cache_cleanup_cron(): void
    {
        oxpulse_imager_activate();

        $this->assertNotFalse(
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'Activation must schedule the recurring cache cleanup cron',
        );
    }

    public function test_deactivation_clears_cache_cleanup_cron(): void
    {
        wp_schedule_event(time(), 'twicedaily', 'oxpulse_cache_cleanup');
        $this->assertNotFalse(wp_next_scheduled('oxpulse_cache_cleanup'));

        oxpulse_imager_deactivate();

        $this->assertFalse(
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'Deactivation must clear the scheduled cron so it does not fire while inactive',
        );
    }

    // ─── bootstrap callback registration ─────────────────────────────

    public function test_cron_callback_registered_at_bootstrap(): void
    {
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerCacheCleanupCron', [$plugin]);

        $this->assertNotFalse(
            has_action('oxpulse_cache_cleanup'),
            'The cron callback must be registered via add_action at bootstrap',
        );
    }

    // ─── #84 self-heal: init guard-schedule on upgrade ───────────────

    public function test_init_self_heals_cron_when_unscheduled_on_upgrade(): void
    {
        $this->assertFalse(
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'Precondition: event must be unscheduled (upgrade path)',
        );

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerCacheCleanupCron', [$plugin]);

        do_action('init');

        $this->assertNotFalse(
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'init must guard-schedule the recurring cron when unscheduled (upgrade self-heal)',
        );
    }

    public function test_init_does_not_double_schedule_when_cron_already_scheduled(): void
    {
        $existingTimestamp = time() + 7200;
        wp_schedule_event($existingTimestamp, 'twicedaily', 'oxpulse_cache_cleanup');
        $this->assertSame(
            $existingTimestamp,
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'Precondition: event is scheduled at a known timestamp',
        );

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerCacheCleanupCron', [$plugin]);

        do_action('init');

        $matching = 0;
        foreach ($GLOBALS['__oxpulse_scheduled_events'] as $event) {
            if ($event['hook'] === 'oxpulse_cache_cleanup') {
                $matching++;
            }
        }
        $this->assertSame(1, $matching, 'init must not add a second schedule entry when one already exists');
        $this->assertSame(
            $existingTimestamp,
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'init must not shift the existing scheduled timestamp',
        );
    }

    // ─── cron callback behavior (local active vs imgproxy active) ────

    public function test_cron_callback_runs_cleanup_when_local_active(): void
    {
        // LocalBackend active = endpoint empty. Seed an over-cap cache
        // + a 1 MB cap so the janitor evicts the oldest file.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 1);
        $this->seedOverCapCache();

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerCacheCleanupCron', [$plugin]);

        do_action('oxpulse_cache_cleanup');

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_cache_cleanup_ran'),
            'The cron callback must run the janitor when LocalBackend is active',
        );
        $this->assertFileDoesNotExist(
            $this->cacheDir . '/h1/old.webp',
            'The oldest over-cap file must be evicted by the cron-triggered cleanup',
        );
        $this->assertFileExists(
            $this->cacheDir . '/h1/new.webp',
            'The newest under-low-water file must survive',
        );
    }

    public function test_cron_callback_is_noop_when_imgproxy_active(): void
    {
        // ImgproxyBackend active = endpoint set → no local cache → no-op.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $this->seedOverCapCache();

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerCacheCleanupCron', [$plugin]);

        do_action('oxpulse_cache_cleanup');

        $this->assertSame(
            0,
            did_action('oxpulse_cache_cleanup_ran'),
            'The cron callback must be a no-op when ImgproxyBackend is active (no local cache)',
        );
        $this->assertFileExists(
            $this->cacheDir . '/h1/old.webp',
            'No eviction must occur when imgproxy is the active tier',
        );
    }

    // ─── runCacheCleanup() direct behavior ───────────────────────────

    public function test_run_cache_cleanup_noop_when_imgproxy_active(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $this->seedOverCapCache();

        ServiceRegistrar::runCacheCleanup();

        $this->assertSame(0, did_action('oxpulse_cache_cleanup_ran'));
        $this->assertFileExists($this->cacheDir . '/h1/old.webp');
    }

    public function test_run_cache_cleanup_runs_when_local_active(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 1);
        $this->seedOverCapCache();

        ServiceRegistrar::runCacheCleanup();

        $this->assertGreaterThan(0, did_action('oxpulse_cache_cleanup_ran'));
        $this->assertFileDoesNotExist($this->cacheDir . '/h1/old.webp');
    }
}
