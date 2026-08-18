<?php
/**
 * LQIP blur=0 tests: some imgproxy builds reject the blur processing
 * option at the URL-parser level ("Invalid URL", observed live on the
 * reference deployment's imgproxy-v4). blur=0 must OMIT the option —
 * a 20px placeholder stretched by background-size:cover is soft via
 * browser upscaling anyway — instead of being forced back to blur:1.
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
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use PHPUnit\Framework\TestCase;

class LqipBlurZeroTest extends TestCase
{
    private function rewriter(float $blur): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
                lqipEnabled: true,
                lqipBlur: $blur,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_blur_zero_omits_the_blur_option(): void
    {
        $result = $this->rewriter(0.0)->rewriteLqip('https://example.com/wp-content/uploads/a.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringNotContainsString('blur:', $result->url);
        $this->assertStringContainsString('rs:fit:20:20', $result->url);
    }

    public function test_blur_positive_still_emits_the_option(): void
    {
        $result = $this->rewriter(2.5)->rewriteLqip('https://example.com/wp-content/uploads/a.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('blur:2.5', $result->url);
    }

    public function test_validator_accepts_blur_zero(): void
    {
        $validator = new SettingsValidator();
        $result = $validator->validate(['lqip_blur' => 0]);

        $this->assertArrayNotHasKey('lqip_blur', $result['errors']);
        $this->assertSame(0.0, $result['values']['lqip_blur']);
    }
}
