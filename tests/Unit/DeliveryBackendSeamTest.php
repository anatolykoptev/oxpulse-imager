<?php
/**
 * DeliveryBackend seam tests.
 *
 * Verifies the UrlRewriter -> DeliveryBackend delegation:
 * - With an injected ImgproxyBackend, output is byte-identical to the
 *   pre-seam hard-constructed ImgproxyUrlGenerator path (the existing
 *   UrlRewriterTest covers the default-injection path; this covers the
 *   explicit-injection path).
 * - With a stub DeliveryBackend, UrlRewriter delegates and returns the
 *   stub's URL unchanged.
 * - UrlRewriter preserves the original URL when the backend reports
 *   available() === false (reason 'no_endpoint').
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use PHPUnit\Framework\TestCase;

class DeliveryBackendSeamTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    public function test_injected_imgproxy_backend_produces_same_url_as_default_path(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        // Default (no backend injected) — the pre-seam path.
        $defaultRewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        // Explicit ImgproxyBackend injected.
        $backend = new ImgproxyBackend($delivery, $signing);
        $injectedRewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend);

        $url = 'https://example.com/wp-content/uploads/photo.jpg';

        $rDefault = $defaultRewriter->rewrite($url, 800, 600);
        $rInjected = $injectedRewriter->rewrite($url, 800, 600);

        $this->assertTrue($rDefault->rewritten);
        $this->assertTrue($rInjected->rewritten);
        // Byte-identical: the seam must not change the imgproxy URL.
        $this->assertSame($rDefault->url, $rInjected->url);
    }

    public function test_injected_stub_backend_url_is_returned_verbatim(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $stub = new class implements DeliveryBackend {
            public bool $availableCalled = false;
            public bool $generateCalled = false;
            public function available(): bool
            {
                $this->availableCalled = true;
                return true;
            }
            public function generate(TransformRequest $request, ?string $filename = null): string
            {
                $this->generateCalled = true;
                return 'https://stub.test/cache/' . $request->width . 'x' . $request->height . '/' . basename($request->sourceUrl);
            }
        };

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $stub);

        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.jpg', 400, 300);

        $this->assertTrue($result->rewritten);
        $this->assertSame('https://stub.test/cache/400x300/photo.jpg', $result->url);
        $this->assertTrue($stub->availableCalled);
        $this->assertTrue($stub->generateCalled);
    }

    public function test_unavailable_backend_preserves_url_with_no_endpoint_reason(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $stub = new class implements DeliveryBackend {
            public function available(): bool { return false; }
            public function generate(TransformRequest $request, ?string $filename = null): string
            {
                throw new \LogicException('generate must not be called when available() is false');
            }
        };

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $stub);

        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.jpg', 400, 0);

        $this->assertFalse($result->rewritten);
        $this->assertSame('no_endpoint', $result->reason);
        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $result->url);
    }
}
