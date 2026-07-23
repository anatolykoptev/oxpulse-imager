<?php
/**
 * UrlRewriterFactory unit tests.
 *
 * Verifies the factory seam applies the DeliveryBackendFactory health
 * gate: with imgproxy cached Down, the built UrlRewriter does NOT
 * produce an imgproxy URL (falls through to LocalBackend or preserves
 * the original), matching the front-end health-gate behavior.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriterFactory;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use PHPUnit\Framework\TestCase;

class UrlRewriterFactoryTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_transients']);
        parent::tearDown();
    }

    private function delivery(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            sourceMode: 'http',
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
    }

    public function test_from_config_produces_imgproxy_url_when_healthy(): void
    {
        $rewriter = UrlRewriterFactory::fromConfig($this->delivery(), $this->signing());

        $result = $rewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.jpg',
            800,
            0,
            'test'
        );

        $this->assertTrue($result->rewritten, 'healthy imgproxy → URL is rewritten');
        $this->assertStringStartsWith(
            self::ENDPOINT . '/',
            $result->url,
            'healthy imgproxy → rewritten URL is an imgproxy URL'
        );
    }

    public function test_from_config_does_not_emit_imgproxy_url_when_health_down(): void
    {
        // Mark imgproxy health Down in the persistent cache.
        (new ImgproxyHealthCache())->write('down');

        $rewriter = UrlRewriterFactory::fromConfig($this->delivery(), $this->signing());

        $result = $rewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.jpg',
            800,
            0,
            'test'
        );

        // With health Down, the factory selects LocalBackend (or
        // passthrough) — NEVER ImgproxyBackend. The result is either a
        // rewritten local URL or a preserved original, but NEVER an
        // imgproxy URL.
        if ($result->rewritten) {
            $this->assertStringNotContainsString(
                self::ENDPOINT . '/',
                $result->url,
                'cached-Down imgproxy must NOT produce an imgproxy URL'
            );
        }
        // Falsification: with the bug (lazy ImgproxyBackend, no health
        // gate), the URL would start with the imgproxy endpoint.
        $this->assertFalse(
            str_starts_with($result->url, self::ENDPOINT . '/'),
            'the produced URL must not be an imgproxy URL when health is Down'
        );
    }
}
