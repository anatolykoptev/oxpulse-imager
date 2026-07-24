<?php
/**
 * Grandfather pre-Freemius installs tests.
 *
 * Verifies the one-time upgrade detector:
 * - An install with prior-install markers (schema_version present) and
 *   NO oxpulse_born_version sentinel gets oxpulse_grandfathered=1.
 * - A fresh install (born_version sentinel present) does NOT get
 *   grandfathered.
 * - Running the detector twice is idempotent (second run is a no-op).
 * - An install with no prior markers and no born_version (edge case)
 *   does NOT get grandfathered.
 * - The detector never runs on non-admin (front-end) requests.
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

class GrandfatherPreFreemiusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_autoload'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_autoload'],
            $GLOBALS['__oxpulse_is_admin'],
        );
    }

    /**
     * Invoke the private maybeGrandfatherPreFreemiusInstalls() via
     * reflection so production visibility is preserved while the
     * upgrade logic stays directly testable (mirrors
     * AutoloadMigrationTest::runMigration).
     */
    private function runGrandfatherDetector(): void
    {
        $method = new \ReflectionMethod(ServiceRegistrar::class, 'maybeGrandfatherPreFreemiusInstalls');
        $method->invoke(null);
    }

    // ─── Upgrade of pre-existing install ─────────────────────────────

    public function test_upgrade_of_pre_existing_install_sets_grandfathered(): void
    {
        // Pre-existing install: schema_version present (set by a
        // pre-Freemius activation), but NO born_version sentinel.
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runGrandfatherDetector();

        $this->assertSame(1, (int) get_option('oxpulse_grandfathered'));
    }

    // ─── Fresh install (born_version sentinel present) ───────────────

    public function test_fresh_install_with_born_version_does_not_grandfather(): void
    {
        // Fresh install on the Freemius version: activation hook set
        // born_version + schema_version. The detector must NOT
        // grandfather — this is a brand-new install that was never
        // pre-Freemius.
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);
        update_option('oxpulse_born_version', OXPULSE_IMAGER_VERSION);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runGrandfatherDetector();

        $this->assertFalse(get_option('oxpulse_grandfathered', false));
    }

    // ─── Idempotent ──────────────────────────────────────────────────

    public function test_running_detector_twice_is_idempotent(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runGrandfatherDetector();
        $this->assertSame(1, (int) get_option('oxpulse_grandfathered'));

        // Second run: oxpulse_grandfathered is already set → no-op.
        // The value must not change (no double-write side effects).
        $this->runGrandfatherDetector();
        $this->assertSame(1, (int) get_option('oxpulse_grandfathered'));
    }

    // ─── No prior markers, no born_version ───────────────────────────

    public function test_no_prior_markers_and_no_born_version_does_not_grandfather(): void
    {
        // Edge case: no schema_version, no born_version, no grandfathered.
        // This shouldn't happen in practice (activation always sets
        // schema_version), but the detector must not grandfather
        // blindly — it requires prior-install markers.
        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runGrandfatherDetector();

        $this->assertFalse(get_option('oxpulse_grandfathered', false));
    }

    // ─── Non-admin (front-end) ───────────────────────────────────────

    public function test_detector_skips_non_admin_requests(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        // Front-end request: is_admin() is false → detector must not
        // run (the grandfather write is a one-time admin housekeeping
        // operation, never on the front-end read path).
        $GLOBALS['__oxpulse_is_admin'] = false;
        $this->runGrandfatherDetector();

        $this->assertFalse(get_option('oxpulse_grandfathered', false));
    }

    // ─── Activation hook sets born_version ───────────────────────────

    public function test_activation_hook_sets_born_version(): void
    {
        oxpulse_imager_activate();

        $this->assertSame(
            OXPULSE_IMAGER_VERSION,
            get_option('oxpulse_born_version'),
            'activation must set oxpulse_born_version to the current version',
        );
    }

    public function test_activation_hook_does_not_overwrite_existing_born_version(): void
    {
        // An install that was born on an earlier Freemius version
        // already has born_version. Reactivation must NOT overwrite it
        // (that would make the detector think it's a fresh install).
        update_option('oxpulse_born_version', '0.1.4');

        oxpulse_imager_activate();

        $this->assertSame('0.1.4', get_option('oxpulse_born_version'));
    }

    // ─── Grandfathered option stored with autoload=no ────────────────

    public function test_grandfathered_option_stored_autoload_no(): void
    {
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);

        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->runGrandfatherDetector();

        // The grandfathered flag is read only by FreemiusLicenseGate
        // (on every isPro() call), but it's a one-time write that
        // never changes after the initial set. Autoload=no keeps the
        // autoload set lean — the gate reads it via get_option which
        // falls back to a per-call SELECT, acceptable since isPro()
        // is not on the hot render path (feature gating is Phase-B).
        $this->assertFalse(
            $GLOBALS['__oxpulse_autoload']['oxpulse_grandfathered'] ?? true,
            'oxpulse_grandfathered must be stored with autoload=no',
        );
    }
}
