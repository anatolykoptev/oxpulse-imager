<?php
/**
 * Gate 4 — cache management feature gate tests.
 *
 * Verifies the cache-management Pro gate:
 * - Under free, loadCacheMaxMb() returns the DEFAULT cap (512MB)
 *   regardless of the stored option or the oxpulse_cache_max_mb filter
 *   — free users cannot change the cap. The janitor still enforces it.
 * - Under Pro, the option + filter are honored (unchanged behavior).
 *
 * CRITICAL invariant: the CacheJanitor twicedaily cron registration is
 * NOT gated — it stays registered + scheduled for EVERYONE (free
 * included) so disk safety runs for all. This test asserts the cron
 * is still scheduled under free.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class FeatureGateCacheManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_scheduled_events'],
            $GLOBALS['__oxpulse_fs_stub']
        );
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

    // ─── Pro: cap honored (unchanged) ────────────────────────────────

    public function test_pro_honors_stored_cache_cap(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 1024);

        $repo = new OptionSettingsRepository();
        $this->assertSame(1024, $repo->loadCacheMaxMb());
    }

    public function test_pro_honors_cache_max_mb_filter(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 512);
        add_filter('oxpulse_cache_max_mb', static fn() => 2048);

        $repo = new OptionSettingsRepository();
        $this->assertSame(2048, $repo->loadCacheMaxMb());
    }

    // ─── Free: cap locked to default ─────────────────────────────────

    public function test_free_ignores_stored_cache_cap_and_uses_default(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 4096);

        $repo = new OptionSettingsRepository();
        $this->assertSame(
            OptionSettingsRepository::DEFAULT_CACHE_MAX_MB,
            $repo->loadCacheMaxMb(),
            'Free must use the default cap, ignoring the stored option',
        );
    }

    public function test_free_ignores_cache_max_mb_filter(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 512);
        add_filter('oxpulse_cache_max_mb', static fn() => 8192);

        $repo = new OptionSettingsRepository();
        $this->assertSame(
            OptionSettingsRepository::DEFAULT_CACHE_MAX_MB,
            $repo->loadCacheMaxMb(),
            'Free must use the default cap, ignoring the oxpulse_cache_max_mb filter',
        );
    }

    // ─── INVARIANT: janitor cron NOT gated — scheduled for everyone ──

    public function test_free_janitor_cron_still_registered_at_bootstrap(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->invokePrivate('registerCacheCleanupCron', [$this->buildPluginStub()]);

        $this->assertNotFalse(
            has_action('oxpulse_cache_cleanup'),
            'The CacheJanitor cron callback MUST be registered under free (disk safety not gated)',
        );
    }

    public function test_free_janitor_cron_still_scheduled_on_init(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->invokePrivate('registerCacheCleanupCron', [$this->buildPluginStub()]);

        do_action('init');

        $this->assertNotFalse(
            wp_next_scheduled('oxpulse_cache_cleanup'),
            'The CacheJanitor twicedaily cron MUST be scheduled under free (disk safety for all)',
        );
    }
}
