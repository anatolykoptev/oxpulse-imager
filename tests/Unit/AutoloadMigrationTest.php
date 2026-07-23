<?php
/**
 * #91: hot-option autoload migration tests.
 *
 * Verifies (a) the activation hook stores hot defaults with autoload=yes,
 * (b) the one-time schema_version 1→2 upgrade flips the hot render-path
 * options to autoload=yes on existing installs via wp_set_options_autoload
 * without touching stored values, (c) the upgrade is idempotent, and
 * (d) the upgrade never runs on the front-end (non-admin) read path.
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

class AutoloadMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_autoload'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options'], $GLOBALS['__oxpulse_autoload'], $GLOBALS['__oxpulse_is_admin']);
    }

    /**
     * Invoke the private maybeMigrateAutoload() via reflection so the
     * production visibility (private, called only from register()) is
     * preserved while the upgrade logic stays directly testable.
     */
    private function runMigration(): void
    {
        $method = new \ReflectionMethod(ServiceRegistrar::class, 'maybeMigrateAutoload');
        $method->invoke(null);
    }

    // ── Activation hook: hot defaults get autoload=yes ──────────────

    public function test_activation_hook_autoloads_hot_defaults(): void
    {
        oxpulse_imager_activate();

        foreach (OptionSettingsRepository::AUTOLOAD_OPTION_KEYS as $key) {
            $this->assertTrue(
                $GLOBALS['__oxpulse_autoload'][$key] ?? false,
                "activation must store hot option {$key} with autoload=yes",
            );
        }
    }

    public function test_activation_hook_keeps_non_hot_defaults_autoload_no(): void
    {
        oxpulse_imager_activate();

        $nonHot = [
            OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL,
            OptionSettingsRepository::OPTION_SCHEMA_VERSION,
            OptionSettingsRepository::OPTION_ONBOARDED,
        ];
        foreach ($nonHot as $key) {
            $this->assertFalse(
                $GLOBALS['__oxpulse_autoload'][$key] ?? true,
                "activation must keep non-hot option {$key} with autoload=no",
            );
        }
    }

    // ── One-time upgrade for existing installs ──────────────────────

    public function test_migration_flips_hot_options_to_autoload_yes(): void
    {
        // Existing install: schema_version=1 (pre-#91 activation),
        // hot options present with their stored values.
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, ['https://src.test/']);
        update_option(OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL, 'basic');

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runMigration();

        foreach (OptionSettingsRepository::AUTOLOAD_OPTION_KEYS as $key) {
            $this->assertTrue(
                $GLOBALS['__oxpulse_autoload'][$key] ?? false,
                "migration must flip hot option {$key} to autoload=yes",
            );
        }
        // Non-hot options were NOT touched by the migration.
        $this->assertArrayNotHasKey(
            OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL,
            $GLOBALS['__oxpulse_autoload'],
        );
    }

    public function test_migration_preserves_stored_values(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://preserve.test');

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runMigration();

        // wp_set_options_autoload flips only the autoload flag — the
        // stored values must be byte-identical before and after.
        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_ENABLED));
        $this->assertSame('https://preserve.test', get_option(OptionSettingsRepository::OPTION_ENDPOINT));
    }

    public function test_migration_bumps_schema_version_to_2(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runMigration();

        $this->assertSame(2, (int) get_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION));
    }

    public function test_migration_is_idempotent(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runMigration();
        $this->assertNotEmpty($GLOBALS['__oxpulse_autoload'], 'first run must flip options');
        $this->assertSame(2, (int) get_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION));

        // Second run: schema_version is already 2 → no-op.
        $GLOBALS['__oxpulse_autoload'] = [];
        $this->runMigration();

        $this->assertSame([], $GLOBALS['__oxpulse_autoload'], 'second run must not re-flip');
    }

    public function test_migration_skips_non_admin_requests(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        // Front-end request: is_admin() is false → migration must not
        // run (the autoload flip is a one-time admin housekeeping write,
        // never on the front-end read path).
        $GLOBALS['__oxpulse_is_admin'] = false;
        $this->runMigration();

        $this->assertSame([], $GLOBALS['__oxpulse_autoload']);
        $this->assertSame(1, (int) get_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION));
    }
}
