<?php
/**
 * LqipPlaceholderBuilder tests.
 *
 * Verifies LQIP URL generation and SVG fallback behavior.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class LqipPlaceholderBuilderTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function createBuilder(bool $lqipEnabled = true, float $blur = 1): LqipPlaceholderBuilder
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            lqipEnabled: $lqipEnabled,
            lqipBlur: $blur,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );
        return new LqipPlaceholderBuilder($rewriter);
    }

    public function test_returns_null_for_empty_url(): void
    {
        $builder = $this->createBuilder();
        $this->assertNull($builder->build(''));
    }

    public function test_returns_imgproxy_lqip_url_for_allowed_source(): void
    {
        $builder = $this->createBuilder(true, 2);
        $result = $builder->build('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertNotNull($result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('blur:2', $result);
    }

    public function test_returns_svg_fallback_for_non_allowed_source(): void
    {
        $builder = $this->createBuilder(true);
        $result = $builder->build('https://evil.com/steal.jpg');

        // Should fall back to SVG data URI.
        $this->assertNotNull($result);
        $this->assertStringStartsWith('data:image/svg+xml,', $result);
    }

    public function test_svg_fallback_includes_dimensions_when_provided(): void
    {
        $builder = $this->createBuilder(true);
        $result = $builder->build('https://evil.com/steal.jpg', 800, 600);

        $this->assertNotNull($result);
        $this->assertStringContainsString('800', $result);
        $this->assertStringContainsString('600', $result);
    }

    public function test_svg_fallback_uses_1x1_when_no_dimensions(): void
    {
        $builder = $this->createBuilder(true);
        $result = $builder->build('https://evil.com/steal.jpg');

        $this->assertNotNull($result);
        // Default 1x1 when no dimensions. SVG is URL-encoded in the data URI.
        $this->assertStringContainsString('width%3D%221%22', $result);
        $this->assertStringContainsString('height%3D%221%22', $result);
    }

    public function test_returns_svg_fallback_when_lqip_disabled(): void
    {
        // When lqip is disabled, rewriteLqip returns preserved, so the
        // builder falls back to SVG.
        $builder = $this->createBuilder(false);
        $result = $builder->build('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertNotNull($result);
        $this->assertStringStartsWith('data:image/svg+xml,', $result);
    }
}
