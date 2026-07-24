<?php
/**
 * LicenseGate seam tests.
 *
 * #89: verifies the pre-licensing abstraction is inert and complete:
 * - OpenLicenseGate::isPro() is unconditionally true (zero behavior
 *   change for every existing install, incl. imgproxy/AVIF sites).
 * - planName() defaults to 'pro' (chosen so status readouts don't
 *   imply a downgrade before licensing is live).
 * - the oxpulse_is_pro filter overrides isPro() (QA / dev force
 *   free/pro without a provider) — mirrors oxpulse_picture_enabled.
 * - ProFeatures::all() returns exactly the 5 seeded keys; each const
 *   is accessible.
 * - ServiceRegistrar::licenseGate() resolves a SHARED instance.
 * - the oxpulse_license_gate() global helper returns the gate.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\License\LicenseGate;
use OXPulse\Imager\Domain\License\ProFeatures;
use OXPulse\Imager\Infrastructure\License\FreemiusLicenseGate;
use OXPulse\Imager\Infrastructure\License\OpenLicenseGate;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class LicenseGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $this->resetLicenseGate();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $this->resetLicenseGate();
    }

    /**
     * Reset the private static $licenseGate on ServiceRegistrar so each
     * test starts from a clean lazy-init state (mirrors the rewriter
     * reset in ThumbUrlHelperTest).
     */
    private function resetLicenseGate(): void
    {
        $ref = new \ReflectionClass(ServiceRegistrar::class);
        $prop = $ref->getProperty('licenseGate');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ─── OpenLicenseGate default behavior ───────────────────────────────

    public function test_open_gate_is_pro_returns_true(): void
    {
        $gate = new OpenLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    public function test_open_gate_plan_name_defaults_to_pro(): void
    {
        $gate = new OpenLicenseGate();
        $this->assertSame('pro', $gate->planName());
    }

    public function test_open_gate_implements_license_gate_interface(): void
    {
        $gate = new OpenLicenseGate();
        $this->assertInstanceOf(LicenseGate::class, $gate);
    }

    // ─── oxpulse_is_pro filter override ─────────────────────────────────

    public function test_filter_force_false_makes_is_pro_false(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $gate = new OpenLicenseGate();
        $this->assertFalse($gate->isPro());
    }

    public function test_filter_force_true_makes_is_pro_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $gate = new OpenLicenseGate();
        $this->assertTrue($gate->isPro());
    }

    public function test_plan_name_reflects_filter_forced_free(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $gate = new OpenLicenseGate();
        $this->assertFalse($gate->isPro());
        $this->assertSame('free', $gate->planName());
    }

    // ─── ProFeatures registry ───────────────────────────────────────────

    public function test_pro_features_all_returns_exactly_seeded_keys(): void
    {
        $expected = [
            'avif',
            'imgproxy_delivery',
            'picture_element',
            'cache_management',
            'admin_status',
        ];
        $this->assertSame($expected, ProFeatures::all());
    }

    public function test_pro_features_all_returns_exactly_five_keys(): void
    {
        $this->assertCount(5, ProFeatures::all());
    }

    public function test_pro_features_constants_accessible_and_match_all(): void
    {
        $consts = [
            ProFeatures::AVIF,
            ProFeatures::IMGPROXY_DELIVERY,
            ProFeatures::PICTURE_ELEMENT,
            ProFeatures::CACHE_MANAGEMENT,
            ProFeatures::ADMIN_STATUS,
        ];
        $this->assertSame(ProFeatures::all(), $consts);
    }

    // ─── DI: ServiceRegistrar shared instance + helper ──────────────────

    public function test_service_registrar_license_gate_returns_shared_instance(): void
    {
        $a = ServiceRegistrar::licenseGate();
        $b = ServiceRegistrar::licenseGate();
        $this->assertSame($a, $b);
    }

    public function test_service_registrar_license_gate_returns_freemius_gate_by_default(): void
    {
        $gate = ServiceRegistrar::licenseGate();
        $this->assertInstanceOf(FreemiusLicenseGate::class, $gate);
        $this->assertInstanceOf(LicenseGate::class, $gate);
    }

    public function test_oxpulse_license_gate_helper_returns_gate(): void
    {
        $this->assertTrue(function_exists('oxpulse_license_gate'));
        $gate = oxpulse_license_gate();
        $this->assertInstanceOf(LicenseGate::class, $gate);
        $this->assertSame(ServiceRegistrar::licenseGate(), $gate);
    }
}
