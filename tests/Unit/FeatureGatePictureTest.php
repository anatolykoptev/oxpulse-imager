<?php
/**
 * Gate 3 — <picture> element feature gate tests.
 *
 * Verifies the picture-element Pro gate: when !isPro(), the
 * oxpulse_picture_enabled filter is forced FALSE regardless of the
 * stored option or any other filter callback — a free user who flips
 * the option or adds add_filter('oxpulse_picture_enabled',
 * '__return_true') still gets no <picture> wrapping. Under Pro the
 * filter passes through unchanged (option/filter controlled).
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class FeatureGatePictureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_fs_stub']
        );
    }

    /**
     * Register ONLY the picture-gate filter (the bootstrap piece that
     * forces oxpulse_picture_enabled false under free).
     */
    private function registerGateFilter(): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, 'registerPictureGate');
        $ref->setAccessible(true);
        $ref->invoke(null);
    }

    // ─── Pro: filter passes through ──────────────────────────────────

    public function test_pro_picture_filter_passes_through_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->registerGateFilter();

        $this->assertTrue(apply_filters('oxpulse_picture_enabled', true));
    }

    public function test_pro_picture_filter_passes_through_false(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->registerGateFilter();

        $this->assertFalse(apply_filters('oxpulse_picture_enabled', false));
    }

    // ─── Free: forced false regardless of input or other filters ─────

    public function test_free_forces_picture_false_even_when_input_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();

        $this->assertFalse(apply_filters('oxpulse_picture_enabled', true));
    }

    public function test_free_forces_picture_false_even_when_other_filter_forces_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        // A free user (or theme) tries to force picture on.
        add_filter('oxpulse_picture_enabled', '__return_true');
        $this->registerGateFilter();

        $this->assertFalse(
            apply_filters('oxpulse_picture_enabled', true),
            'Free must force picture OFF even when another filter returns true',
        );
    }
}
