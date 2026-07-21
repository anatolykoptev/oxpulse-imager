<?php
/**
 * Save-Data header support tests (Ф7).
 *
 * Verifies that the Save-Data: on header reduces image quality by the
 * configured amount, with a floor at 1, and that the feature is
 * disabled when the header is absent or saveDataQualityReduction is 0.
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

class SaveDataRewriteTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const SOURCE = 'https://example.com/wp-content/uploads/photo.jpg';

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up $_SERVER state.
        unset($_SERVER['HTTP_SAVE_DATA']);
    }

    private function createRewriter(int $reduction, array $formatQuality = [], int $defaultQuality = 80): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: $defaultQuality,
                formatQuality: $formatQuality,
                saveDataQualityReduction: $reduction,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_no_save_data_header_quality_unchanged(): void
    {
        unset($_SERVER['HTTP_SAVE_DATA']);
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // Default quality 80, no reduction → q:80 in URL.
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_save_data_on_reduces_default_quality(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // 80 - 15 = 65.
        $this->assertStringContainsString('q:65', $result->url);
        $this->assertStringNotContainsString('q:80', $result->url);
    }

    public function test_save_data_off_quality_unchanged(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'off';
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_save_data_on_case_insensitive(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'On';
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:65', $result->url);
    }

    public function test_save_data_reduction_zero_disables(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        $rewriter = $this->createRewriter(0);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // reduction=0 → no change.
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_save_data_quality_floor_at_one(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        // defaultQuality=10, reduction=15 → would be -5, floored at 1.
        $rewriter = $this->createRewriter(15, [], 10);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:1', $result->url);
    }

    public function test_save_data_reduces_per_format_quality(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        $rewriter = $this->createRewriter(15, ['avif' => 70, 'webp' => 80]);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        // avif: 70-15=55, webp: 80-15=65. fq:avif:55:webp:65 (sorted alphabetically).
        $this->assertStringContainsString('fq:avif:55:webp:65', $result->url);
    }

    public function test_save_data_per_format_quality_floor_at_one(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = 'on';
        // avif: 10-15=-5 → 1, webp: 80-15=65.
        $rewriter = $this->createRewriter(15, ['avif' => 10, 'webp' => 80]);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('fq:avif:1:webp:65', $result->url);
    }

    public function test_save_data_empty_header_quality_unchanged(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = '';
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:80', $result->url);
    }

    public function test_save_data_with_whitespace_on(): void
    {
        $_SERVER['HTTP_SAVE_DATA'] = ' on ';
        $rewriter = $this->createRewriter(15);

        $result = $rewriter->rewrite(self::SOURCE, 800, 0);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('q:65', $result->url);
    }
}
