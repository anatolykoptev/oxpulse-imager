<?php
/**
 * Plugin bootstrap tests.
 *
 * Verifies the runtime guard, activation defaults, and the disabled-default
 * no-op invariant. Phase 0 success criteria.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the option store is reset between tests.
        $GLOBALS['__oxpulse_options'] = [];
        // Default administrator role without the plugin capability —
        // tests can override to simulate pre-existing grants.
        $GLOBALS['__oxpulse_roles'] = [
            'administrator' => new \WP_Role(['manage_options' => true]),
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_roles']);
    }

    public function test_constants_are_defined(): void
    {
        $this->assertSame('0.1.0', OXPULSE_IMAGER_VERSION);
        $this->assertSame('oxpulse_imager_', OXPULSE_IMAGER_OPTION_PREFIX);
        $this->assertSame('manage_oxpulse_imager', OXPULSE_IMAGER_CAPABILITY);
    }

    public function test_disabled_default_does_not_register_delivery(): void
    {
        $this->assertFalse(
            (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false),
            'Delivery must be disabled by default.'
        );
    }

    public function test_activation_sets_disabled_defaults(): void
    {
        oxpulse_imager_activate();

        $store = $GLOBALS['__oxpulse_options'] ?? [];
        $this->assertArrayHasKey('oxpulse_imager_enabled', $store);
        $this->assertFalse($store['oxpulse_imager_enabled']);
        $this->assertSame('', $store['oxpulse_imager_endpoint'] ?? '');
        $this->assertFalse($store['oxpulse_imager_remove_on_uninstall'] ?? true);
        $this->assertSame(1, (int) ($store['oxpulse_imager_schema_version'] ?? 0));
    }

    public function test_activation_grants_capability_to_administrator(): void
    {
        // Fresh activate: administrator starts without the plugin capability.
        $role = get_role('administrator');
        $this->assertNotNull($role);
        $this->assertFalse($role->has_cap(OXPULSE_IMAGER_CAPABILITY));

        oxpulse_imager_activate();

        // Activation must have granted the capability.
        $role = get_role('administrator');
        $this->assertTrue($role->has_cap(OXPULSE_IMAGER_CAPABILITY));
    }

    public function test_grant_capability_is_idempotent(): void
    {
        // First grant adds the capability.
        oxpulse_imager_grant_capability();
        $role = get_role('administrator');
        $this->assertTrue($role->has_cap(OXPULSE_IMAGER_CAPABILITY));

        // Second grant is a no-op (no error, capability still present).
        oxpulse_imager_grant_capability();
        $this->assertTrue($role->has_cap(OXPULSE_IMAGER_CAPABILITY));
    }

    public function test_grant_capability_no_administrator_role_is_safe(): void
    {
        // Edge case: no administrator role exists (broken install).
        // grant_capability must not fatal — it just does nothing.
        $GLOBALS['__oxpulse_roles'] = [];

        oxpulse_imager_grant_capability();

        // No assertion needed — reaching here without fatal proves safety.
        $this->assertTrue(true);
    }
}
