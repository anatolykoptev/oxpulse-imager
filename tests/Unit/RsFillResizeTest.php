<?php
/**
 * Ф9: rs:fill resize type tests.
 *
 * Verifies that resolveResizeType returns 'fill' when both width AND height
 * are specified (for Foxiz fixed crop sizes), 'fit' when only one dimension
 * is specified, and '' when neither is specified.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class RsFillResizeTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const SOURCE = 'https://example.com/wp-content/uploads/photo.jpg';

    private function createRewriter(): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_both_width_and_height_uses_fill(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 330, 220);

        $this->assertTrue($result->rewritten);
        // Foxiz foxiz_crop_g1 330x220 — exact crop.
        $this->assertStringContainsString('rs:fill:330:220', $result->url);
    }

    public function test_both_width_and_height_large_uses_fill(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 1300, 800);

        $this->assertTrue($result->rewritten);
        // Foxiz foxiz_crop_1300x800 — exact crop.
        $this->assertStringContainsString('rs:fill:1300:800', $result->url);
    }

    public function test_only_width_uses_fit(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // Only width → fit (preserve aspect ratio).
        $this->assertStringContainsString('rs:fit:800:0', $result->url);
        $this->assertStringNotContainsString('rs:fill', $result->url);
    }

    public function test_only_height_uses_fit(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 0, 600);

        $this->assertTrue($result->rewritten);
        // Only height → fit (preserve aspect ratio).
        $this->assertStringContainsString('rs:fit:0:600', $result->url);
        $this->assertStringNotContainsString('rs:fill', $result->url);
    }

    public function test_zero_width_and_height_no_resize_option(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 0, 0);

        $this->assertTrue($result->rewritten);
        // No dimensions → no rs: option at all (imgproxy serves native).
        $this->assertStringNotContainsString('rs:fill', $result->url);
        $this->assertStringNotContainsString('rs:fit', $result->url);
    }

    public function test_square_dimensions_uses_fill(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 512, 512);

        $this->assertTrue($result->rewritten);
        // Square crop (e.g. site icon 512x512) — both dims → fill.
        $this->assertStringContainsString('rs:fill:512:512', $result->url);
    }

    public function test_fill_does_not_emit_fit(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE, 400, 300);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('rs:fill:400:300', $result->url);
        // Ensure no rs:fit appears anywhere in the URL.
        $this->assertStringNotContainsString('rs:fit', $result->url);
    }
}
