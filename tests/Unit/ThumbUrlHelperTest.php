<?php
/**
 * oxpulse_thumb_url() helper tests.
 *
 * Verifies the public global helper:
 * - returns imgproxy URL when delivery enabled + source allowed
 * - returns original URL when delivery disabled (rewriter is null)
 * - returns original URL when source not in allowlist
 * - deterministic: same args → same URL
 * - function_exists check
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
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ThumbUrlHelperTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static rewriter before each test.
        $this->setStaticRewriter(null);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setStaticRewriter(null);
    }

    /**
     * Use reflection to set the private static $rewriter on ServiceRegistrar.
     * This avoids depending on the full registerDeliveryAdapters() flow
     * (which requires WordPress hook infrastructure).
     */
    private function setStaticRewriter(?UrlRewriter $rewriter): void
    {
        $ref = new \ReflectionClass(ServiceRegistrar::class);
        $prop = $ref->getProperty('rewriter');
        $prop->setAccessible(true);
        $prop->setValue(null, $rewriter);
    }

    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_function_exists(): void
    {
        $this->assertTrue(function_exists('oxpulse_thumb_url'));
    }

    public function test_returns_original_url_when_rewriter_null(): void
    {
        // Rewriter is null (delivery disabled / not initialized).
        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result = oxpulse_thumb_url($url, 330, 220);

        $this->assertSame($url, $result);
    }

    public function test_returns_imgproxy_url_when_delivery_enabled(): void
    {
        $this->setStaticRewriter($this->createRewriter(true));

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result = oxpulse_thumb_url($url, 330, 220);

        $this->assertNotSame($url, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('rs:fit:330:220', $result);
    }

    public function test_returns_original_url_when_source_not_allowed(): void
    {
        $this->setStaticRewriter($this->createRewriter(true));

        $url = 'https://evil.com/steal.jpg';
        $result = oxpulse_thumb_url($url, 330, 220);

        $this->assertSame($url, $result);
    }

    public function test_deterministic_same_args_produce_same_url(): void
    {
        $this->setStaticRewriter($this->createRewriter(true));

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result1 = oxpulse_thumb_url($url, 330, 220);
        $result2 = oxpulse_thumb_url($url, 330, 220);

        $this->assertSame($result1, $result2);
    }

    public function test_different_dimensions_produce_different_urls(): void
    {
        $this->setStaticRewriter($this->createRewriter(true));

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $r1 = oxpulse_thumb_url($url, 330, 220);
        $r2 = oxpulse_thumb_url($url, 800, 600);

        $this->assertNotSame($r1, $r2);
    }

    public function test_zero_dimensions_allowed(): void
    {
        $this->setStaticRewriter($this->createRewriter(true));

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result = oxpulse_thumb_url($url, 0, 0);

        // 0x0 = no resize option, just format conversion.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringNotContainsString('rs:fit:0:0', $result);
    }

    public function test_getRewriter_returns_null_by_default(): void
    {
        $this->assertNull(ServiceRegistrar::getRewriter());
    }

    public function test_getRewriter_returns_instance_after_set(): void
    {
        $rewriter = $this->createRewriter(true);
        $this->setStaticRewriter($rewriter);

        $this->assertSame($rewriter, ServiceRegistrar::getRewriter());
    }
}
