<?php
/**
 * #111 / #112: the AVIF bake re-runs when the license flips, both on the
 * admin-poll self-heal and the instant Freemius reports the change.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Plugin;
use PHPUnit\Framework\TestCase;

class LicenseRebakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_is_admin'],
            $GLOBALS['__oxpulse_fs_stub']
        );
        parent::tearDown();
    }

    private function register(): void
    {
        ServiceRegistrar::register((new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
    }

    /** Fs stub that records add_action() registrations. */
    private function fsStub(): object
    {
        return new class {
            /** @var array<string,callable> */
            public array $hooks = [];
            public function add_action(string $tag, callable $fn): void
            {
                $this->hooks[$tag] = $fn;
            }
            public function can_use_premium_code(): bool
            {
                return false;
            }
        };
    }

    private function rebakes(): int
    {
        return (int) ($GLOBALS['__oxpulse_did_action']['oxpulse_rebaked_avif'] ?? 0);
    }

    public function test_freemius_license_change_rebakes_a_local_backend_site_immediately(): void
    {
        $fs = $this->fsStub();
        $GLOBALS['__oxpulse_fs_stub'] = $fs;
        update_option('oxpulse_avif_baked_pro', true); // baked while Pro.
        add_filter('oxpulse_is_pro', '__return_false'); // license just lapsed.

        $this->register();

        $this->assertArrayHasKey('after_license_change', $fs->hooks, 'the Freemius hook must be registered');
        $this->assertSame(0, $this->rebakes(), 'nothing runs until the license changes');

        ($fs->hooks['after_license_change'])();

        $this->assertSame(1, $this->rebakes(), 'a Pro->free flip must re-bake without waiting for an admin load');
        $this->assertFalse(get_option('oxpulse_avif_baked_pro'));
    }

    public function test_no_freemius_sdk_registers_nothing_and_does_not_fatal(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $this->register();
        $this->assertTrue(true);

        // An instance without add_action() is skipped too.
        $GLOBALS['__oxpulse_fs_stub'] = new class {
            public function can_use_premium_code(): bool
            {
                return false;
            }
        };
        $this->register();
        $this->assertSame(0, $this->rebakes());
    }

    public function test_imgproxy_site_updates_the_sentinel_without_a_rebake(): void
    {
        $fs = $this->fsStub();
        $GLOBALS['__oxpulse_fs_stub'] = $fs;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_false');

        $this->register();
        ($fs->hooks['after_license_change'])();

        $this->assertSame(0, $this->rebakes());
        $this->assertFalse(get_option('oxpulse_avif_baked_pro'), 'sentinel still follows the license');
    }

    public function test_admin_poll_still_rebakes_on_drift(): void
    {
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_false');
        $GLOBALS['__oxpulse_is_admin'] = true;

        (new \ReflectionMethod(ServiceRegistrar::class, 'maybeRebakeAvifOnLicenseChange'))->invoke(null);

        $this->assertSame(1, $this->rebakes());
    }
}
