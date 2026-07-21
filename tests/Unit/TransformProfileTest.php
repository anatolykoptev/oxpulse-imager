<?php
/**
 * TransformProfile tests.
 *
 * Verifies deterministic imgproxy option string generation for all
 * Phase 5.1 options: resize, quality, per-format quality, DPR, blur,
 * watermark. Same input → same output (deterministic URL generation).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Transform\TransformProfile;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Domain\Transform\Watermark;
use PHPUnit\Framework\TestCase;

class TransformProfileTest extends TestCase
{
    private TransformProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = new TransformProfile();
    }

    public function test_resize_only(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
        );

        $this->assertSame('rs:fit:800:600', $this->profile->buildOptions($request));
    }

    public function test_resize_with_global_quality(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 0,
            quality: 80,
        );

        $this->assertSame('rs:fit:800:0/q:80', $this->profile->buildOptions($request));
    }

    public function test_per_format_quality_replaces_global_quality(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 0,
            quality: 80, // Should be ignored when formatQuality is set
            formatQuality: ['avif' => 70, 'webp' => 85],
        );

        // Keys are sorted alphabetically for deterministic output.
        $this->assertSame('rs:fit:800:0/fq:avif:70:webp:85', $this->profile->buildOptions($request));
    }

    public function test_per_format_quality_single_format(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 0,
            height: 0,
            formatQuality: ['webp' => 80],
        );

        $this->assertSame('fq:webp:80', $this->profile->buildOptions($request));
    }

    public function test_dpr_option(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 400,
            height: 0,
            dpr: 2,
        );

        $this->assertSame('rs:fit:400:0/dpr:2', $this->profile->buildOptions($request));
    }

    public function test_dpr_float_value_minimal_representation(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 400,
            height: 0,
            dpr: 1.5,
        );

        // No trailing .0 — minimal URL representation.
        $this->assertSame('rs:fit:400:0/dpr:1.5', $this->profile->buildOptions($request));
    }

    public function test_blur_option(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 20,
            height: 20,
            blur: 1,
        );

        $this->assertSame('rs:fit:20:20/blur:1', $this->profile->buildOptions($request));
    }

    public function test_watermark_centered(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
            watermark: new Watermark(
                opacity: 0.5,
                position: Watermark::POS_CENTER,
            ),
        );

        // Opacity 0.5 → 50 in URL.
        $this->assertSame('rs:fit:800:600/wm:50:ce:0:0:0', $this->profile->buildOptions($request));
    }

    public function test_watermark_with_offsets_and_scale(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
            watermark: new Watermark(
                opacity: 1,
                position: Watermark::POS_SOUTH_EAST,
                xOffset: 10,
                yOffset: -5,
                scale: 0.2,
            ),
        );

        $this->assertSame('rs:fit:800:600/wm:100:soea:10:-5:0.2', $this->profile->buildOptions($request));
    }

    public function test_all_options_combined(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
            formatQuality: ['avif' => 70, 'webp' => 80],
            dpr: 2,
            blur: 0,
            watermark: new Watermark(
                opacity: 0.3,
                position: Watermark::POS_NORTH_WEST,
                scale: 0.1,
            ),
        );

        // Order: resize → fq → dpr → blur (skipped, 0) → watermark
        $this->assertSame(
            'rs:fit:800:600/fq:avif:70:webp:80/dpr:2/wm:30:nowe:0:0:0.1',
            $this->profile->buildOptions($request)
        );
    }

    public function test_empty_request_produces_empty_options(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 0,
            height: 0,
        );

        $this->assertSame('', $this->profile->buildOptions($request));
    }

    public function test_deterministic_output_same_input_same_result(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
            formatQuality: ['webp' => 80, 'avif' => 70], // Different order
        );

        $request2 = new TransformRequest(
            sourceUrl: 'https://example.com/img.jpg',
            width: 800,
            height: 600,
            formatQuality: ['avif' => 70, 'webp' => 80], // Same values, different order
        );

        $this->assertSame(
            $this->profile->buildOptions($request),
            $this->profile->buildOptions($request2)
        );
    }
}
