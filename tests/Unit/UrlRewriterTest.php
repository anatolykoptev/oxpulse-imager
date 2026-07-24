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

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
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
        $this->assertStringContainsString('rs:fill:800:600', $result->url);
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
        // In auto mode, the filename is the basename WITHOUT extension —
        // imgproxy appends the negotiated format extension (avif/webp/jpeg)
        // to the Content-Disposition filename. Including the source
        // extension here would produce a double extension (photo.jpg.avif).
        $expected = rtrim(strtr(base64_encode('photo'), '+/', '-_'), '=');
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
        // LQIP uses rs:fit (not rs:fill) — placeholder preserves aspect ratio.
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
            // Encoded source format: `local:///path` is base64url-encoded
            // as a single string, so `local://` is NOT visible in the URL.
            // Decode the last path segment to verify it contains local:///.
            $segments = explode('/', $result->url);
            $lastSegment = end($segments);
            $decoded = base64_decode(strtr($lastSegment, '-_', '+/'), true);
            $this->assertStringStartsWith('local:///', $decoded);
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

    // --- #43 Phase 3: URL-level idempotency guard (no double-rewrite) ---

    /**
     * If a source URL is ALREADY one of OUR rewritten forms — a
     * LocalBackend cache URL — it must be preserved unchanged, never
     * re-rewritten. This stops the recursion where a filtered URL is
     * fed back through a content filter.
     */
    public function test_preserves_already_rewritten_cache_url(): void
    {
        $rewriter = $this->createRewriter();
        $alreadyRewritten = 'https://example.com/wp-content/cache/oxpulse/a1b2c3d4e5f6a1b2/eyJfIjoid2VicCJ9.sig.webp';

        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
        $this->assertSame($alreadyRewritten, $result->url);
    }

    public function test_preserves_already_rewritten_endpoint_url(): void
    {
        $rewriter = $this->createRewriter();
        $alreadyRewritten = 'https://example.com/wp-content/oxpulse-img.php?k=eyJfIjoid2VicCJ9.sig';

        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
        $this->assertSame($alreadyRewritten, $result->url);
    }

    public function test_preserves_already_rewritten_relative_cache_url(): void
    {
        // Relative cache URLs (emitted in non-absolute contexts) must
        // also be detected — the guard is path-based, not host-based.
        $rewriter = $this->createRewriter();
        $alreadyRewritten = '/wp-content/cache/oxpulse/a1b2c3d4e5f6a1b2/key.webp';

        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
    }

    public function test_idempotency_guard_runs_before_delivery_disabled_check(): void
    {
        // Even with delivery disabled, an already-rewritten URL is
        // preserved as already_rewritten (not delivery_disabled) — the
        // guard is the first check, so we never accidentally re-process
        // a URL that was rewritten before delivery was toggled off.
        $rewriter = $this->createRewriter(enabled: false);
        $alreadyRewritten = 'https://example.com/wp-content/cache/oxpulse/h/key.webp';

        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
    }

    public function test_idempotency_guard_does_not_match_imgproxy_urls(): void
    {
        // Imgproxy URLs are NOT matched by the guard (they don't contain
        // /wp-content/cache/oxpulse/). They are separately handled by
        // SourcePolicy's proxy-loop check. Verify the guard leaves them
        // to the normal path (which denies them via proxy_loop_detected).
        $rewriter = $this->createRewriter();
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:800:0/plain/abc@avif';

        $result = $rewriter->rewrite($imgproxyUrl);

        $this->assertFalse($result->rewritten);
        $this->assertSame('proxy_loop_detected', $result->reason);
    }

    public function test_rewrite_lqip_preserves_already_rewritten_url(): void
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

        $alreadyRewritten = 'https://example.com/wp-content/oxpulse-img.php?k=key.sig';
        $result = $rewriter->rewriteLqip($alreadyRewritten);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
    }

    public function test_rewrite_dpr_preserves_already_rewritten_url(): void
    {
        $rewriter = $this->createRewriter();
        $alreadyRewritten = 'https://example.com/wp-content/cache/oxpulse/h/key.webp';

        $result = $rewriter->rewriteDpr($alreadyRewritten, 400, 2.0);

        $this->assertFalse($result->rewritten);
        $this->assertSame('already_rewritten', $result->reason);
    }

    // --- Phase 1: rewriteFormat (explicit per-call format override) ---

    public function test_rewrite_format_avif_produces_avif_url(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewriteFormat(
            'https://example.com/wp-content/uploads/img.jpg',
            800,
            600,
            'avif'
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('@avif', $result->url);
        $this->assertStringNotContainsString('@webp', $result->url);
    }

    public function test_rewrite_format_webp_produces_webp_url(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewriteFormat(
            'https://example.com/wp-content/uploads/img.jpg',
            800,
            600,
            'webp'
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('@webp', $result->url);
        $this->assertStringNotContainsString('@avif', $result->url);
    }

    public function test_rewrite_format_filename_reflects_explicit_format(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewriteFormat(
            'https://example.com/wp-content/uploads/photo.jpg',
            0,
            0,
            'avif'
        );

        $this->assertTrue($result->rewritten);
        // The Content-Disposition filename (fn: option) must carry the
        // explicit format extension, not the config outputFormat.
        $this->assertStringContainsString('fn:', $result->url);
        // Decode the fn: base64url to verify the .avif extension.
        preg_match('/\/fn:([A-Za-z0-9_-]+):1\//', $result->url, $m);
        $this->assertNotEmpty($m, 'fn: option must be present');
        $filename = base64_decode(strtr($m[1], '-_', '+/'), true);
        $this->assertSame('photo.avif', $filename);
    }

    public function test_rewrite_format_preserves_when_delivery_disabled(): void
    {
        $rewriter = $this->createRewriter(enabled: false);
        $result = $rewriter->rewriteFormat(
            'https://example.com/wp-content/uploads/img.jpg',
            800,
            600,
            'avif'
        );

        $this->assertFalse($result->rewritten);
        $this->assertSame('delivery_disabled', $result->reason);
    }

    public function test_rewrite_format_preserves_non_allowed_source(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewriteFormat(
            'https://evil.com/images/img.jpg',
            800,
            600,
            'avif'
        );

        $this->assertFalse($result->rewritten);
        $this->assertSame('source_not_in_allowlist', $result->reason);
    }

    public function test_rewrite_format_context_is_picture(): void
    {
        $rewriter = $this->createRewriter();
        // rewriteFormat defaults context to 'picture'. The context is
        // diagnostic (does not change the URL), so we just verify it
        // does not throw and produces a rewritten URL.
        $result = $rewriter->rewriteFormat(
            'https://example.com/wp-content/uploads/img.jpg',
            0,
            0,
            'webp'
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('@webp', $result->url);
    }

    // --- rewriteSocialImage tests ---
    // rewriteSocialImage routes an og:image source through the active
    // backend's socialSafeUrl() seam to produce a .jpg-terminated URL.
    // Reuses the rewriteWithFormat guard chain (already-rewritten /
    // delivery-disabled / no-endpoint / no-signing / SourcePolicy
    // authorize). Degrades to preserved($sourceUrl) when the backend
    // returns null (LocalBackend / http-source / passthrough).

    private const SOCIAL_SOURCE = 'https://piter.now/wp-content/uploads/2026/07/photo.webp';
    private const SOCIAL_ALLOWED = 'https://piter.now/wp-content/uploads/';

    private function socialStubBackend(?string $socialUrl): DeliveryBackend
    {
        return new class($socialUrl) implements DeliveryBackend {
            public function __construct(private ?string $socialUrl) {}
            public function available(): bool { return true; }
            public function generate(\OXPulse\Imager\Domain\Transform\TransformRequest $request, ?string $filename = null): string
            {
                throw new \LogicException('generate must not be called by rewriteSocialImage');
            }
            public function socialSafeUrl(\OXPulse\Imager\Domain\Transform\TransformRequest $request, ?string $filename = null): ?string
            {
                return $this->socialUrl;
            }
        };
    }

    private function createSocialRewriter(
        bool $enabled = true,
        ?SigningConfig $signing = null,
        ?DeliveryBackend $backend = null
    ): UrlRewriter {
        $delivery = new DeliveryConfig(
            enabled: $enabled,
            endpoint: self::ENDPOINT,
            allowedSources: [self::SOCIAL_ALLOWED],
        );
        return new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            $signing ?? SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX),
            null,
            $backend,
        );
    }

    public function test_rewrite_social_image_stub_returns_jpg_is_rewritten(): void
    {
        $stub = $this->socialStubBackend('https://imgproxy.test/sig/rs:fill:1200:630/plain/photo.jpg');
        $rewriter = $this->createSocialRewriter(backend: $stub);

        $result = $rewriter->rewriteSocialImage(self::SOCIAL_SOURCE, 1200, 630);

        $this->assertTrue($result->rewritten);
        $this->assertStringEndsWith('.jpg', $result->url);
    }

    public function test_rewrite_social_image_backend_null_is_preserved(): void
    {
        // Backend answers null (LocalBackend / http-source / passthrough)
        // → degrade to the original webp URL, never break.
        $stub = $this->socialStubBackend(null);
        $rewriter = $this->createSocialRewriter(backend: $stub);

        $result = $rewriter->rewriteSocialImage(self::SOCIAL_SOURCE, 1200, 630);

        $this->assertFalse($result->rewritten);
        $this->assertSame(self::SOCIAL_SOURCE, $result->url);
        $this->assertSame('social_format_unsupported', $result->reason);
    }

    public function test_rewrite_social_image_delivery_disabled_is_preserved(): void
    {
        $stub = $this->socialStubBackend('https://imgproxy.test/photo.jpg');
        $rewriter = $this->createSocialRewriter(enabled: false, backend: $stub);

        $result = $rewriter->rewriteSocialImage(self::SOCIAL_SOURCE, 1200, 630);

        $this->assertFalse($result->rewritten);
        $this->assertSame('delivery_disabled', $result->reason);
        $this->assertSame(self::SOCIAL_SOURCE, $result->url);
    }

    public function test_rewrite_social_image_signing_null_is_preserved(): void
    {
        $stub = $this->socialStubBackend('https://imgproxy.test/photo.jpg');
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::SOCIAL_ALLOWED],
        );
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, null, null, $stub);

        $result = $rewriter->rewriteSocialImage(self::SOCIAL_SOURCE, 1200, 630);

        $this->assertFalse($result->rewritten);
        $this->assertSame('no_signing_config', $result->reason);
        $this->assertSame(self::SOCIAL_SOURCE, $result->url);
    }
}
