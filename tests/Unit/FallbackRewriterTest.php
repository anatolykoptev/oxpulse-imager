<?php
/**
 * FallbackRewriter tests.
 *
 * Verifies the output-buffer fallback that rewrites cache URLs
 * (emitted by LocalBackend) to oxpulse-img.php?k=<key> so serving
 * works without Apache rewrite rules (nginx / no-.htaccess).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Local\FallbackRewriter;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use PHPUnit\Framework\TestCase;

class FallbackRewriterTest extends TestCase
{
    private const H1 = 'a1b2c3d4e5f6a1b2';
    private const H2 = 'f6e5d4c3b2a1f6e5';
    private const KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SALT_HEX = 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5';
    private const SOURCE = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
    // Realistic keys: base64url(payload).base64url(sig) — the key ALWAYS
    // contains an internal '.' (the FallbackRewriter regex must span it).
    private const K1 = 'eyJmIjoid2VicCIsInciOjMwMH0.sigAAAA';
    private const K2 = 'eyJmIjoid2VicCIsInciOjYwMH0.sigBBBBBB';

    public function test_rewrites_cache_url_to_endpoint_query(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp" alt="test">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('/wp-content/oxpulse-img.php?k=' . self::K1, $result);
        $this->assertStringNotContainsString('cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp', $result);
    }

    public function test_preserves_non_cache_urls(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">';
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    public function test_rewrites_multiple_cache_urls_in_one_html(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp">'
              . '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H2 . '/' . self::K2 . '.webp">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('?k=' . self::K1, $result);
        $this->assertStringContainsString('?k=' . self::K2, $result);
    }

    public function test_rewrites_srcset_cache_urls(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img srcset="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp 1x, https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K2 . '.webp 2x">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('?k=' . self::K1, $result);
        $this->assertStringContainsString('?k=' . self::K2, $result);
    }

    public function test_preserves_query_params_on_endpoint_url(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp">';
        $result = $rewriter->rewrite($html);

        // The endpoint URL should have ?k=<key> (no format extension).
        $this->assertStringContainsString('?k=' . self::K1, $result);
        $this->assertStringNotContainsString('.webp', $result);
    }

    public function test_does_not_rewrite_other_domains(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://other.com/wp-content/cache/oxpulse/' . self::H1 . '/' . self::K1 . '.webp">';
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    /**
     * FIX #3: the key is `base64url(payload).base64url(sig)` — it
     * contains an internal '.'. The old capture `([A-Za-z0-9_-]+)`
     * stopped at the first '.' and truncated the key, so the endpoint
     * saw only the payload half and verify() failed (400 every image
     * in nginx/fallback mode). The capture must span the internal dot.
     *
     * This round-trip test builds a REAL signed key, rewrites its
     * cache URL to ?k=<key>, then verifies the recovered key passes
     * LocalBackend::verify() — proving the full key survives the
     * fallback rewrite.
     */
    public function test_round_trip_real_key_with_internal_dot_passes_verify(): void
    {
        $backend = new LocalBackend(SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX));
        $req = new TransformRequest(
            sourceUrl: self::SOURCE,
            width: 800, height: 0, resize: 'fit', format: 'webp', quality: 80,
            context: 'content', dpr: 0, blur: 0, watermark: null,
            formatQuality: [], sourceMode: 'http',
        );

        $cacheUrl = $backend->generate($req);
        // The cache URL contains the real key (with internal dot).
        $this->assertStringContainsString('.', basename(parse_url($cacheUrl, PHP_URL_PATH)));

        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.test',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="' . $cacheUrl . '">';
        $result = $rewriter->rewrite($html);

        // Extract ?k=<key> from the rewritten HTML.
        $this->assertStringContainsString('/wp-content/oxpulse-img.php?k=', $result);
        $this->assertMatchesRegularExpression('#\?k=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)#', $result, 'Captured key must include the internal dot.');
        preg_match('#\?k=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)#', $result, $m);
        $capturedKey = $m[1];

        // The captured key must round-trip through verify().
        $payload = $backend->verify($capturedKey);
        $this->assertNotNull($payload, 'Fallback-rewritten key must pass verify() (round-trip).');
        $this->assertSame(self::SOURCE, $payload['source']);
        $this->assertSame(800, $payload['width']);
    }
}
