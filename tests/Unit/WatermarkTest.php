<?php
/**
 * Watermark value object tests.
 *
 * Verifies validation of opacity, position, offsets, and scale.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Transform\Watermark;
use PHPUnit\Framework\TestCase;

class WatermarkTest extends TestCase
{
    public function test_valid_construction_defaults(): void
    {
        $wm = new Watermark(
            opacity: 0.5,
            position: Watermark::POS_CENTER,
        );

        $this->assertSame(0.5, $wm->opacity);
        $this->assertSame('ce', $wm->position);
        $this->assertSame(0, $wm->xOffset);
        $this->assertSame(0, $wm->yOffset);
        $this->assertSame(0.0, $wm->scale);
    }

    public function test_valid_construction_all_params(): void
    {
        $wm = new Watermark(
            opacity: 1,
            position: Watermark::POS_SOUTH_EAST,
            xOffset: 10,
            yOffset: -5,
            scale: 0.2,
        );

        $this->assertSame(1.0, $wm->opacity);
        $this->assertSame('soea', $wm->position);
        $this->assertSame(10, $wm->xOffset);
        $this->assertSame(-5, $wm->yOffset);
        $this->assertSame(0.2, $wm->scale);
    }

    public function test_all_position_constants_allowed(): void
    {
        $positions = Watermark::ALLOWED_POSITIONS;

        foreach ($positions as $pos) {
            $wm = new Watermark(opacity: 0.5, position: $pos);
            $this->assertSame($pos, $wm->position, "Position $pos should be allowed");
        }
    }

    public function test_opacity_zero_allowed(): void
    {
        $wm = new Watermark(opacity: 0, position: Watermark::POS_CENTER);
        $this->assertSame(0.0, $wm->opacity);
    }

    public function test_opacity_one_allowed(): void
    {
        $wm = new Watermark(opacity: 1, position: Watermark::POS_CENTER);
        $this->assertSame(1.0, $wm->opacity);
    }

    public function test_opacity_too_high_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 1.1, position: Watermark::POS_CENTER);
    }

    public function test_opacity_negative_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: -0.1, position: Watermark::POS_CENTER);
    }

    public function test_invalid_position_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 0.5, position: 'invalid');
    }

    public function test_scale_too_high_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 0.5, position: Watermark::POS_CENTER, scale: 1.1);
    }

    public function test_scale_negative_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 0.5, position: Watermark::POS_CENTER, scale: -0.1);
    }

    public function test_x_offset_out_of_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 0.5, position: Watermark::POS_CENTER, xOffset: 10001);
    }

    public function test_y_offset_out_of_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Watermark(opacity: 0.5, position: Watermark::POS_CENTER, yOffset: -10001);
    }
}
