<?php
/**
 * Plugin lifecycle integration tests.
 *
 * Verifies activation, deactivation, and uninstall behavior described in
 * the Phase 0 plan. These tests use the stub WordPress environment from
 * tests/bootstrap.php and a simulated option store.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use PHPUnit\Framework\TestCase;

class PluginLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        // Override stub functions to simulate a real option store.
        $GLOBALS['__oxpulse_options_store'] = &$GLOBALS['__oxpulse_options'];
    }

    public function test_activation_creates_disabled_defaults(): void
    {
        oxpulse_imager_activate();

        $store = $GLOBALS['__oxpulse_options_store'] ?? [];
        $this->assertArrayHasKey('oxpulse_imager_enabled', $store);
        $this->assertFalse($store['oxpulse_imager_enabled']);
        $this->assertArrayHasKey('oxpulse_imager_remove_on_uninstall', $store);
        $this->assertFalse($store['oxpulse_imager_remove_on_uninstall']);
    }

    public function test_activation_does_not_overwrite_existing_options(): void
    {
        // Simulate pre-existing configuration.
        $GLOBALS['__oxpulse_options_store']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options_store']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.test';

        oxpulse_imager_activate();

        $store = $GLOBALS['__oxpulse_options_store'];
        $this->assertTrue($store['oxpulse_imager_enabled']);
        $this->assertSame('https://imgproxy.example.test', $store['oxpulse_imager_endpoint']);
    }
}
