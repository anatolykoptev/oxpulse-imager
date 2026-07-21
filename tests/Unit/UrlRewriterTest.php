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
        // Content-Disposition filename option must be present.
        $this->assertStringContainsString('fn:', $result->url);
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

    public function test_auto_format_omits_at_suffix_for_accept_negotiation(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertTrue($result->rewritten);
        // No @avif or @webp suffix — imgproxy uses Accept header.
        $this->assertStringNotContainsString('@avif', $result->url);
        $this->assertStringNotContainsString('@webp', $result->url);
    }

    public function test_explicit_avif_format_adds_at_suffix(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            outputFormat: 'avif',
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );

        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/img.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('@avif', $result->url);
    }

    public function test_content_disposition_filename_preserves_original_in_auto_mode(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertTrue($result->rewritten);
        // In auto mode, the filename is the original basename.
        // Verify the base64url-encoded 'photo.jpg' appears in the URL.
        $expected = rtrim(strtr(base64_encode('photo.jpg'), '+/', '-_'), '=');
        $this->assertStringContainsString('fn:' . $expected . ':1', $result->url);
    }

    public function test_content_disposition_filename_replaces_extension_in_explicit_format(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            outputFormat: 'avif',
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );

        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertTrue($result->rewritten);
        // In explicit format mode, the filename extension is replaced.
        $expected = rtrim(strtr(base64_encode('photo.avif'), '+/', '-_'), '=');
        $this->assertStringContainsString('fn:' . $expected . ':1', $result->url);
    }

    public function test_content_disposition_filename_handles_no_extension(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo');

        $this->assertTrue($result->rewritten);
        // Original filename passed through in auto mode.
        $expected = rtrim(strtr(base64_encode('photo'), '+/', '-_'), '=');
        $this->assertStringContainsString('fn:' . $expected . ':1', $result->url);
    }

    public function test_generator_reused_across_multiple_rewrites(): void
    {
        // The UrlRewriter should create the URL generator once and reuse
        // it for subsequent rewrites. We verify this by checking that
        // multiple rewrites produce consistent, deterministic output
        // (which requires the same signer/pathBuilder/generator chain).
        $rewriter = $this->createRewriter();
        $url = 'https://example.com/wp-content/uploads/photo.jpg';

        // First rewrite creates the generator.
        $r1 = $rewriter->rewrite($url, 400, 0);
        // Subsequent rewrites reuse it.
        $r2 = $rewriter->rewrite($url, 400, 0);
        $r3 = $rewriter->rewrite($url, 400, 0);

        $this->assertSame($r1->url, $r2->url);
        $this->assertSame($r2->url, $r3->url);
    }

    public function test_multiple_different_urls_rewritten_with_same_rewriter(): void
    {
        $rewriter = $this->createRewriter();

        $r1 = $rewriter->rewrite('https://example.com/wp-content/uploads/photo1.jpg');
        $r2 = $rewriter->rewrite('https://example.com/wp-content/uploads/photo2.jpg');
        $r3 = $rewriter->rewrite('https://example.com/wp-content/uploads/photo3.jpg');

        $this->assertTrue($r1->rewritten);
        $this->assertTrue($r2->rewritten);
        $this->assertTrue($r3->rewritten);
        $this->assertStringContainsString('photo1.jpg', $r1->url);
        $this->assertStringContainsString('photo2.jpg', $r2->url);
        $this->assertStringContainsString('photo3.jpg', $r3->url);
    }

    // --- Phase 5.1: rewriteLqip tests ---

    public function test_rewriteLqip_preserves_when_lqip_disabled(): void
    {
        $rewriter = $this->createRewriter(); // lqipEnabled defaults to false
        $result = $rewriter->rewriteLqip('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertFalse($result->rewritten);
        $this->assertSame('lqip_disabled', $result->reason);
    }

    public function test_rewriteLqip_generates_blurred_url_when_enabled(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            lqipEnabled: true,
            lqipBlur: 2,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );

        $result = $rewriter->rewriteLqip('https://example.com/wp-content/uploads/photo.jpg');

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('imgproxy.example.com', $result->url);
        // LQIP uses blur and small dimensions.
        $this->assertStringContainsString('blur:2', $result->url);
        $this->assertStringContainsString('rs:fit:20:20', $result->url);
    }

    public function test_rewriteLqip_preserves_non_allowed_url(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            lqipEnabled: true,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
        );

        $result = $rewriter->rewriteLqip('https://evil.com/steal.jpg');

        $this->assertFalse($result->rewritten);
    }

    // --- Phase 5.1: rewriteDpr tests ---

    public function test_rewriteDpr_generates_dpr_url(): void
    {
        $rewriter = $this->createRewriter();

        $result = $rewriter->rewriteDpr(
            'https://example.com/wp-content/uploads/photo.jpg',
            400,
            2.0
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('imgproxy.example.com', $result->url);
        $this->assertStringContainsString('rs:fit:400:0', $result->url);
        $this->assertStringContainsString('dpr:2', $result->url);
    }

    public function test_rewriteDpr_preserves_when_delivery_disabled(): void
    {
        $rewriter = $this->createRewriter(enabled: false);

        $result = $rewriter->rewriteDpr(
            'https://example.com/wp-content/uploads/photo.jpg',
            400,
            2.0
        );

        $this->assertFalse($result->rewritten);
        $this->assertSame('delivery_disabled', $result->reason);
    }

    public function test_rewriteDpr_preserves_non_allowed_url(): void
    {
        $rewriter = $this->createRewriter();

        $result = $rewriter->rewriteDpr('https://evil.com/steal.jpg', 400, 2.0);

        $this->assertFalse($result->rewritten);
    }

    public function test_rewriteDpr_with_dpr_one_works(): void
    {
        $rewriter = $this->createRewriter();

        $result = $rewriter->rewriteDpr(
            'https://example.com/wp-content/uploads/photo.jpg',
            400,
            1.0
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('dpr:1', $result->url);
    }

    // --- Ф1: local:// source mode + relative endpoint tests ---

    public function test_local_mode_rewrite_produces_local_segment(): void
    {
        $tmpDir = sys_get_temp_dir() . '/oxpulse-rewriter-local-' . uniqid();
        $subDir = $tmpDir . '/wp-content/uploads/2024/01';
        mkdir($subDir, 0755, true);
        $imagePath = $subDir . '/photo.jpg';
        file_put_contents($imagePath, 'fake-image');

        try {
            $delivery = new DeliveryConfig(
                enabled: true,
                endpoint: '/imgproxy',  // relative endpoint
                allowedSources: ['https://example.com/wp-content/uploads/'],
                sourceMode: 'local',
                localBasePath: $tmpDir,
            );
            $rewriter = new UrlRewriter(
                new SourcePolicy(),
                $delivery,
                SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
            );

            $result = $rewriter->rewrite(
                'https://example.com/wp-content/uploads/2024/01/photo.jpg',
                800,
                0
            );

            $this->assertTrue($result->rewritten);
            $this->assertStringStartsWith('/imgproxy/', $result->url);
            $this->assertStringContainsString('local://', $result->url);
            $this->assertStringNotContainsString('plain/', $result->url);
        } finally {
            unlink($imagePath);
            rmdir($subDir);
            rmdir($tmpDir . '/wp-content/uploads/2024');
            rmdir($tmpDir . '/wp-content/uploads');
            rmdir($tmpDir . '/wp-content');
            rmdir($tmpDir);
        }
    }

    public function test_local_mode_preserves_url_when_file_missing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/oxpulse-rewriter-missing-' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $delivery = new DeliveryConfig(
                enabled: true,
                endpoint: '/imgproxy',
                allowedSources: ['https://example.com/wp-content/uploads/'],
                sourceMode: 'local',
                localBasePath: $tmpDir,
            );
            $rewriter = new UrlRewriter(
                new SourcePolicy(),
                $delivery,
                SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX)
            );

            $sourceUrl = 'https://example.com/wp-content/uploads/nonexistent.jpg';
            $result = $rewriter->rewrite($sourceUrl, 800, 0);

            // Fail safe: file doesn't exist → preserve original URL.
            $this->assertFalse($result->rewritten);
            $this->assertSame($sourceUrl, $result->url);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function test_relative_endpoint_produces_relative_url(): void
    {
        // Even in HTTP source mode, a relative endpoint should produce a
        // root-relative imgproxy URL (for nginx reverse-proxy setups).
        $rewriter = $this->createRewriter(endpoint: '/imgproxy');

        $result = $rewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.jpg',
            800,
            0
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringStartsWith('/imgproxy/', $result->url);
        $this->assertStringNotContainsString('://imgproxy', $result->url);
    }
}
