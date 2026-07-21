<?php
/**
 * Size-based quality tiers tests (Ф8).
 *
 * Verifies that the 3-tier size-based quality configuration selects the
 * correct quality based on the requested image width, and that it
 * stacks correctly with Save-Data reduction (Ф7).
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

class SizeBasedQualityTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const SOURCE = 'https://example.com/wp-content/uploads/photo.jpg';

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_SAVE_DATA']);
    }

    /**
     * Create a rewriter with the 3-tier config from the issue:
     * ≤400px → q75, ≤800px → q70, ≤1200px → q65, >1200 → defaultQuality(80).
     */
    private function createRewriterWithTiers(int $defaultQuality = 80): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: $defaultQuality,
                sizeQualityTiers: [400 => 75, 800 => 70, 1200 => 65],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    private function createRewriterWithoutTiers(int $defaultQuality = 80): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: $defaultQuality,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_small_width_uses_first_tier_quality(): void
    {
        $rewriter = $this->createRewriterWithTiers();
        $result = $rewriter->rewrite(self::SOURCE, 300, 0);

        $this->assertTrue($result->rewritten);
        // 300 ≤ 400 → q75.
        $this->assertStringContainsString('q:75', $result->url);
    }

    public function test_medium_width_uses_second_tier_quality(): void
    {
        $rewriter = $this->createRewriterWithTiers();
        $result = $rewriter->rewrite(self::SOURCE, 600, 0);

        $this->assertTrue($result->rewritten);
        // 400 < 600 ≤ 800 → q70.
        $this->assertStringContainsString('q:70', $result->url);
    }

    public function test_large_width_uses_third_tier_quality(): void
    {
        $rewriter = $this->createRewriterWithTiers();
        $result = $rewriter->rewrite(self::SOURCE, 1000, 0);

        $this->assertTrue($result->rewritten);
        // 800 < 1000 ≤ 1200 → q65.
        $this->assertStringContainsString('q:65', $result->url);
    }

    public function test_width_larger_than_largest_tier_uses_default(): void
    {
        $rewriter = $this->createRewriterWithTiers();
        $result = $rewriter->rewrite(self::SOURCE, 2000, 0);

        $this->assertTrue($result->rewritten);
        // 2000 > 1200 → defaultQuality 80.
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_width_at_tier_boundary_uses_that_tier(): void
    {
        $rewriter = $this->createRewriterWithTiers();

        // Exactly 400 → tier 1 (q75).
        $r1 = $rewriter->rewrite(self::SOURCE, 400, 0);
        $this->assertStringContainsString('q:75', $r1->url);

        // Exactly 800 → tier 2 (q70).
        $r2 = $rewriter->rewrite(self::SOURCE, 800, 0);
        $this->assertStringContainsString('q:70', $r2->url);

        // Exactly 1200 → tier 3 (q65).
        $r3 = $rewriter->rewrite(self::SOURCE, 1200, 0);
        $this->assertStringContainsString('q:65', $r3->url);
    }

    public function test_zero_width_uses_default_quality(): void
    {
        $rewriter = $this->createRewriterWithTiers();
        $result = $rewriter->rewrite(self::SOURCE, 0, 0);

        $this->assertTrue($result->rewritten);
        // width=0 (auto) → no tier match → defaultQuality 80.
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_empty_tiers_uses_default_quality(): void
    {
        $rewriter = $this->createRewriterWithoutTiers();
        $result = $rewriter->rewrite(self::SOURCE, 600, 0);

        $this->assertTrue($result->rewritten);
        // No tiers configured → defaultQuality 80 for any width.
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_save_data_stacks_on_size_tier(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [400 => 75, 800 => 70, 1200 => 65],
                saveDataQualityReduction: 15,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // width=300 → tier q75, then Save-Data -15 → q60.
        $result = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:60', $result->url);

        // width=600 → tier q70, then Save-Data -15 → q55.
        $result2 = $rewriter->rewrite(self::SOURCE, 600, 0);
        $this->assertStringContainsString('q:55', $result2->url);

        // width=2000 → default q80, then Save-Data -15 → q65.
        $result3 = $rewriter->rewrite(self::SOURCE, 2000, 0);
        $this->assertStringContainsString('q:65', $result3->url);
    }

    public function test_tiers_unsorted_in_config_still_match_correctly(): void
    {
        // Config provided in reverse order — the resolver sorts internally.
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [1200 => 65, 400 => 75, 800 => 70],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // 300 ≤ 400 → q75 (smallest tier).
        $r1 = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertStringContainsString('q:75', $r1->url);

        // 1000 ≤ 1200 → q65 (largest tier).
        $r2 = $rewriter->rewrite(self::SOURCE, 1000, 0);
        $this->assertStringContainsString('q:65', $r2->url);
    }

    public function test_invalid_tier_quality_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            sizeQualityTiers: [400 => 150], // quality > 100
        );
    }

    public function test_invalid_tier_width_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            sizeQualityTiers: [0 => 75], // width <= 0
        );
    }
}
