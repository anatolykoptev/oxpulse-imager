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

    // ─── FIX 3: belt-and-suspenders — consumer-level isPro() check ───

    /**
     * FIX 3 (MINOR): the <picture> gate filter at PHP_INT_MAX is the
     * PRIMARY gate, but if it's NOT registered (ServiceRegistrar::
     * register() wasn't called, or the filter was removed by another
     * plugin), apply_filters returns the raw pictureEnabled value —
     * which could be true even for free users. The belt-and-suspenders
     * fix: BufferRewriter and ContentImgTagRewriter ALSO check
     * isPro() directly. This test verifies the isPro() check is
     * present in the consumer path (not just the filter): with the
     * gate filter NOT registered, isPro()=false, and pictureEnabled=
     * true, the consumer must still NOT wrap in <picture>.
     *
     * This test uses ContentImgTagRewriter directly (the content-path
     * consumer) — the buffer-path consumer (BufferRewriter) has the
     * same belt-and-suspenders check.
     */
    public function test_free_consumer_blocks_picture_even_without_gate_filter(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        // Gate filter NOT registered — simulates a missing
        // ServiceRegistrar::register() call or a filter removal.
        $delivery = new \OXPulse\Imager\Domain\Config\DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
            pictureEnabled: true,
        );
        $rewriter = new \OXPulse\Imager\Application\Delivery\UrlRewriter(
            new \OXPulse\Imager\Domain\Source\SourcePolicy(),
            $delivery,
            \OXPulse\Imager\Domain\Config\SigningConfig::fromHex('736563726574', '68656C6C6F'),
        );
        $pictureWrapper = new \OXPulse\Imager\Application\Delivery\PictureElementWrapper($rewriter);
        $contentRewriter = new \OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter(
            $rewriter,
            $delivery,
            null,
            $pictureWrapper,
        );

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString(
            '<picture',
            $result,
            'FIX 3: free user must NOT get <picture> wrapping even without the gate filter registered (belt-and-suspenders isPro() check)',
        );
    }
}
