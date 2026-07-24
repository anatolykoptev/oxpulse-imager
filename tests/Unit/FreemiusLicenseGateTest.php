<?php
/**
 * FreemiusLicenseGate tests.
 *
 * Verifies the Freemius-backed LicenseGate:
 * - SDK not loaded (oxpulse_fs returns null) → isPro false / plan 'free'.
 * - Non-paying stub (can_use_premium_code=false) → false / 'free'.
 * - Paying stub (can_use_premium_code=true) → true / 'pro'.
 * - oxpulse_grandfathered option set → true even when not paying.
 * - add_filter('oxpulse_is_pro','__return_false') forces false even when paying.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\License\LicenseGate;
use OXPulse\Imager\Infrastructure\License\FreemiusLicenseGate;
use PHPUnit\Framework\TestCase;

class FreemiusLicenseGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    /**
     * Stub Freemius instance with a controllable can_use_premium_code().
     */
    private function fsStub(bool $premium): object
    {
        return new class($premium) {
            private bool $premium;
            public function __construct(bool $premium) { $this->premium = $premium; }
            public function can_use_premium_code(): bool { return $this->premium; }
        };
    }

    // ─── SDK not loaded ───────────────────────────────────────────────

    public function test_sdk_not_loaded_is_pro_false(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $gate = new FreemiusLicenseGate();
        $this->assertFalse($gate->isPro());
    }

    public function test_sdk_not_loaded_plan_name_free(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $gate = new FreemiusLicenseGate();
        $this->assertSame('free', $gate->planName());
    }

    // ─── SDK missing → free tier, no fatal (FIX 1 regression) ────────
    //
    // When the Freemius SDK is absent (deploy/ZIP shipped without
    // freemius/), oxpulse_fs() returns null. isPro() must degrade to
    // the free tier WITHOUT calling any method on null (which would
    // fatal-error the site). A custom error handler converts any
    // attempt to call a method on null into a failing assertion so the
    // test REDS if the null guard is removed.

    public function test_sdk_absent_is_pro_false_without_fatal(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;

        $caught = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            $caught = $errstr;
            return true;
        });

        try {
            $gate = new FreemiusLicenseGate();
            $result = $gate->isPro();
        } finally {
            restore_error_handler();
        }

        $this->assertNull($caught, 'isPro() must not raise a PHP error when the SDK is absent: ' . (string) $caught);
        $this->assertFalse($result, 'isPro() must return false (free tier) when the SDK is absent');
    }

    // ─── Non-paying stub ──────────────────────────────────────────────

    public function test_non_paying_is_pro_false(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(false);
        $gate = new FreemiusLicenseGate();
        $this->assertFalse($gate->isPro());
    }

    public function test_non_paying_plan_name_free(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(false);
        $gate = new FreemiusLicenseGate();
        $this->assertSame('free', $gate->planName());
    }

    // ─── Paying stub ──────────────────────────────────────────────────

    public function test_paying_is_pro_true(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(true);
        $gate = new FreemiusLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    public function test_paying_plan_name_pro(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(true);
        $gate = new FreemiusLicenseGate();
        $this->assertSame('pro', $gate->planName());
    }

    // ─── Grandfathered ────────────────────────────────────────────────

    public function test_grandfathered_option_makes_is_pro_true_even_when_not_paying(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(false);
        update_option('oxpulse_grandfathered', 1);
        $gate = new FreemiusLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    public function test_grandfathered_plan_name_pro(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(false);
        update_option('oxpulse_grandfathered', 1);
        $gate = new FreemiusLicenseGate();
        $this->assertSame('pro', $gate->planName());
    }

    public function test_grandfathered_true_even_when_sdk_not_loaded(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;
        update_option('oxpulse_grandfathered', 1);
        $gate = new FreemiusLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    // ─── Filter override ──────────────────────────────────────────────

    public function test_filter_force_false_overrides_paying(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(true);
        add_filter('oxpulse_is_pro', '__return_false');
        $gate = new FreemiusLicenseGate();
        $this->assertFalse($gate->isPro());
    }

    public function test_filter_force_false_overrides_grandfathered(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = null;
        update_option('oxpulse_grandfathered', 1);
        add_filter('oxpulse_is_pro', '__return_false');
        $gate = new FreemiusLicenseGate();
        $this->assertFalse($gate->isPro());
    }

    public function test_filter_force_true_overrides_non_paying(): void
    {
        $GLOBALS['__oxpulse_fs_stub'] = $this->fsStub(false);
        add_filter('oxpulse_is_pro', '__return_true');
        $gate = new FreemiusLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    // ─── Interface ────────────────────────────────────────────────────

    public function test_implements_license_gate_interface(): void
    {
        $gate = new FreemiusLicenseGate();
        $this->assertInstanceOf(LicenseGate::class, $gate);
    }
}
