<?php
/**
 * UrlRewriter tests.
 *
 * Verifies the central rewrite decision: delivery disabled, no
 * endpoint, no signing config, source policy denial, and successful
 * rewrite with signed URL generation.
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

class UrlRewriterTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function createRewriter(
        bool $enabled = true,
        string $endpoint = self::ENDPOINT,
        array $allowed = [self::ALLOWED],
        ?SigningConfig $signing = null
    ): UrlRewriter {
        $delivery = new DeliveryConfig(
            enabled: $enabled,
            endpoint: $endpoint,
            allowedSources: $allowed,
        );
        return new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            $signing ?? SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );
    }

    public function test_preserves_url_when_delivery_disabled(): void
    {
        $rewriter = $this->createRewriter(enabled: false);
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('https://example.com/wp-content/uploads/img.jpg', $result->url);
        $this->assertSame('delivery_disabled', $result->reason);
    }

    public function test_preserves_url_when_no_endpoint(): void
    {
        $rewriter = $this->createRewriter(endpoint: '');
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('no_endpoint', $result->reason);
    }

    public function test_preserves_url_when_no_signing_config(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
        );
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, null);

        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('no_signing_config', $result->reason);
    }

    public function test_preserves_url_when_source_not_in_allowlist(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://evil.com/images/img.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('source_not_in_allowlist', $result->reason);
        $this->assertSame('https://evil.com/images/img.jpg', $result->url);
    }

    public function test_preserves_url_when_no_allowed_sources(): void
    {
        $rewriter = $this->createRewriter(allowed: []);
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('no_allowed_sources_configured', $result->reason);
    }

    public function test_preserves_url_on_proxy_loop(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://imgproxy.example.com/health');

        $this->assertFalse($result->rewritten);
        $this->assertSame('proxy_loop_detected', $result->reason);
    }

    public function test_rewrites_allowed_url_to_signed_imgproxy_url(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringStartsWith(self::ENDPOINT . '/', $result->url);
        // The signed URL must contain the plain source URL.
        $this->assertStringContainsString('plain/https://example.com/wp-content/uploads/img.jpg', $result->url);
    }

    public function test_rewritten_url_contains_signature(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        // URL format: endpoint/signature/options/plain/source
        $path = substr($result->url, strlen(self::ENDPOINT) + 1);
        $parts = explode('/', $path, 2);
        $signature = $parts[0];

        // Signature is base64url-encoded, 43 chars for SHA256.
        $this->assertSame(43, strlen($signature));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $signature);
    }

    public function test_rewrites_with_dimensions_produces_resize_option(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg', 800, 600);

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('rs:fit:800:600', $result->url);
    }

    public function test_rewrites_without_dimensions_omits_resize_option(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringNotContainsString('rs:', $result->url);
    }

    public function test_deterministic_same_input_same_output(): void
    {
        $rewriter = $this->createRewriter();
        $url = 'https://example.com/wp-content/uploads/photo.jpg';

        $r1 = $rewriter->rewrite($url, 400, 0);
        $r2 = $rewriter->rewrite($url, 400, 0);

        $this->assertSame($r1->url, $r2->url);
    }

    public function test_preserves_url_on_malformed_source(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('not-a-url');

        $this->assertFalse($result->rewritten);
        $this->assertSame('malformed_url', $result->reason);
    }

    public function test_preserves_url_with_control_characters(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite("https://example.com/\x00img.jpg");

        $this->assertFalse($result->rewritten);
        $this->assertSame('malformed_url', $result->reason);
    }

    public function test_path_boundary_prevents_sibling_bypass(): void
    {
        // Allowed: https://example.com/wp-content/uploads/
        // Attempt: https://example.com/wp-content/uploads-private/secret.jpg
        // This must NOT be rewritten (path boundary enforced).
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads-private/secret.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('source_not_in_allowlist', $result->reason);
    }

    public function test_context_does_not_affect_url_output(): void
    {
        $rewriter = $this->createRewriter();
        $url = 'https://example.com/wp-content/uploads/img.jpg';

        $fromContent = $rewriter->rewrite($url, 0, 0, 'content');
        $fromSrcset = $rewriter->rewrite($url, 0, 0, 'srcset');

        // Context is diagnostic only, does not change the signed URL.
        $this->assertSame($fromContent->url, $fromSrcset->url);
    }
}
