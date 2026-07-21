<?php
/**
 * Ф11: per-format quality in size-based tiers tests.
 *
 * Verifies that sizeQualityTiers accepts per-format quality maps
 * (array<string,int>) in addition to simple int quality. Per-format
 * tiers emit fq: (format_quality) instead of q: (global quality),
 * matching the mu-plugin's production-tuned strategy:
 *   ≤400:   fq:avif:55:jpeg:70:webp:60
 *   ≤1000:  fq:avif:65:jpeg:78:webp:70
 *   >1000:  fq:avif:75:webp:80:jpeg:82
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

class PerFormatQualityTiersTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const SOURCE = 'https://example.com/wp-content/uploads/photo.jpg';

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_SAVE_DATA']);
    }

    /**
     * The mu-plugin's production 3-tier per-format config:
     *   ≤400:   avif:55 webp:60 jpeg:70
     *   ≤1000:  avif:65 webp:70 jpeg:78
     *   >1000:  defaultQuality (no tier match)
     */
    private function createRewriterWithPerFormatTiers(int $defaultQuality = 80): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: $defaultQuality,
                sizeQualityTiers: [
                    400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70],
                    1000 => ['avif' => 65, 'webp' => 70, 'jpeg' => 78],
                ],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_small_width_emits_fq_with_per_format_quality(): void
    {
        $rewriter = $this->createRewriterWithPerFormatTiers();
        $result = $rewriter->rewrite(self::SOURCE, 300, 0);

        $this->assertTrue($result->rewritten);
        // 300 ≤ 400 → fq:avif:55:jpeg:70:webp:60
        $this->assertStringContainsString('fq:avif:55:jpeg:70:webp:60', $result->url);
        // Must NOT emit q: when fq: is present.
        $this->assertStringNotContainsString('q:55', $result->url);
        $this->assertStringNotContainsString('q:60', $result->url);
    }

    public function test_medium_width_emits_fq_with_per_format_quality(): void
    {
        $rewriter = $this->createRewriterWithPerFormatTiers();
        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // 400 < 800 ≤ 1000 → fq:avif:65:jpeg:78:webp:70
        $this->assertStringContainsString('fq:avif:65:jpeg:78:webp:70', $result->url);
    }

    public function test_width_larger_than_largest_tier_uses_default_quality(): void
    {
        $rewriter = $this->createRewriterWithPerFormatTiers();
        $result = $rewriter->rewrite(self::SOURCE, 2000, 0);

        $this->assertTrue($result->rewritten);
        // 2000 > 1000 → defaultQuality 80, no fq: (formatQuality empty).
        $this->assertStringContainsString('q:80', $result->url);
        $this->assertStringNotContainsString('fq:', $result->url);
    }

    public function test_zero_width_uses_default_quality(): void
    {
        $rewriter = $this->createRewriterWithPerFormatTiers();
        $result = $rewriter->rewrite(self::SOURCE, 0, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:80', $result->url);
        $this->assertStringNotContainsString('fq:', $result->url);
    }

    public function test_save_data_stacks_on_per_format_tier(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [
                    400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70],
                ],
                saveDataQualityReduction: 15,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // width=300 → tier fq:avif:55:jpeg:70:webp:60, Save-Data -15
        // → fq:avif:40:jpeg:55:webp:45
        $result = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('fq:avif:40:jpeg:55:webp:45', $result->url);
    }

    public function test_mixed_tiers_int_and_per_format(): void
    {
        // First tier is int (q:), second tier is per-format (fq:).
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [
                    400 => 75, // simple int → q:75
                    1000 => ['avif' => 65, 'webp' => 70, 'jpeg' => 78], // per-format → fq:
                ],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // width=300 ≤ 400 → int tier → q:75
        $r1 = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertStringContainsString('q:75', $r1->url);
        $this->assertStringNotContainsString('fq:', $r1->url);

        // width=800 ≤ 1000 → per-format tier → fq:avif:65:jpeg:78:webp:70
        $r2 = $rewriter->rewrite(self::SOURCE, 800, 0);
        $this->assertStringContainsString('fq:avif:65:jpeg:78:webp:70', $r2->url);
        $this->assertStringNotContainsString('q:65', $r2->url);
    }

    public function test_per_format_tier_overrides_global_format_quality(): void
    {
        // Global formatQuality is set, but per-format tier should override it
        // for matching widths.
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                formatQuality: ['avif' => 90, 'webp' => 90, 'jpeg' => 90],
                sizeQualityTiers: [
                    400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70],
                ],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // width=300 ≤ 400 → tier per-format overrides global formatQuality
        $r1 = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertStringContainsString('fq:avif:55:jpeg:70:webp:60', $r1->url);
        $this->assertStringNotContainsString('avif:90', $r1->url);

        // width=2000 > 400 → no tier match → global formatQuality used
        $r2 = $rewriter->rewrite(self::SOURCE, 2000, 0);
        $this->assertStringContainsString('fq:avif:90:jpeg:90:webp:90', $r2->url);
    }

    public function test_invalid_per_format_quality_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            sizeQualityTiers: [400 => ['avif' => 150]], // quality > 100
        );
    }

    public function test_empty_per_format_tier_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            sizeQualityTiers: [400 => []], // empty per-format map
        );
    }

    public function test_invalid_tier_value_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            sizeQualityTiers: [400 => 'invalid'], // string not allowed
        );
    }

    public function test_mu_plugin_production_config_3_tiers(): void
    {
        // Exact reproduction of the mu-plugin's production config:
        //   ≤400:   fq:avif:55:jpeg:70:webp:60
        //   ≤1000:  fq:avif:65:jpeg:78:webp:70
        //   >1000:  defaultQuality (heroes, preserve gradients)
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [
                    400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70],
                    1000 => ['avif' => 65, 'webp' => 70, 'jpeg' => 78],
                ],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // Thumbnail 96x96 → ≤400 → fq:avif:55:jpeg:70:webp:60
        $r1 = $rewriter->rewrite(self::SOURCE, 96, 96);
        $this->assertStringContainsString('fq:avif:55:jpeg:70:webp:60', $r1->url);

        // Card 615x410 → ≤1000 → fq:avif:65:jpeg:78:webp:70
        $r2 = $rewriter->rewrite(self::SOURCE, 615, 410);
        $this->assertStringContainsString('fq:avif:65:jpeg:78:webp:70', $r2->url);

        // Hero 1536x1536 → >1000 → q:80 (defaultQuality)
        $r3 = $rewriter->rewrite(self::SOURCE, 1536, 1536);
        $this->assertStringContainsString('q:80', $r3->url);
        $this->assertStringNotContainsString('fq:', $r3->url);
    }

    public function test_mu_plugin_production_config_with_save_data(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        // Mu-plugin Save-Data mode shifts every tier down ~15 points:
        //   ≤400:   fq:avif:40:jpeg:55:webp:45
        //   ≤1000:  fq:avif:50:jpeg:63:webp:55
        //   >1000:  q:65 (80-15)
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [
                    400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70],
                    1000 => ['avif' => 65, 'webp' => 70, 'jpeg' => 78],
                ],
                saveDataQualityReduction: 15,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        // Thumbnail ≤400, Save-Data → fq:avif:40:jpeg:55:webp:45
        $r1 = $rewriter->rewrite(self::SOURCE, 300, 0);
        $this->assertStringContainsString('fq:avif:40:jpeg:55:webp:45', $r1->url);

        // Card ≤1000, Save-Data → fq:avif:50:jpeg:63:webp:55
        $r2 = $rewriter->rewrite(self::SOURCE, 800, 0);
        $this->assertStringContainsString('fq:avif:50:jpeg:63:webp:55', $r2->url);

        // Hero >1000, Save-Data → q:65 (80-15)
        $r3 = $rewriter->rewrite(self::SOURCE, 2000, 0);
        $this->assertStringContainsString('q:65', $r3->url);
    }
}
