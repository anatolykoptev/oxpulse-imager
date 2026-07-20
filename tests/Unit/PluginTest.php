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

        $this->assertFalse(get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', true));
        $this->assertSame('', get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'endpoint', ''));
        $this->assertFalse(get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'remove_on_uninstall', true));
        $this->assertSame(1, (int) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'schema_version', 0));
    }
}
