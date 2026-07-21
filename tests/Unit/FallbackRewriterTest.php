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

use OXPulse\Imager\Infrastructure\Local\FallbackRewriter;
use PHPUnit\Framework\TestCase;

class FallbackRewriterTest extends TestCase
{
    private const H1 = 'a1b2c3d4e5f6a1b2';
    private const H2 = 'f6e5d4c3b2a1f6e5';
    public function test_rewrites_cache_url_to_endpoint_query(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/key.webp" alt="test">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('/wp-content/oxpulse-img.php?k=key', $result);
        $this->assertStringNotContainsString('cache/oxpulse/' . self::H1 . '/key.webp', $result);
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

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/k1.webp">'
              . '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H2 . '/k2.webp">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('?k=k1', $result);
        $this->assertStringContainsString('?k=k2', $result);
    }

    public function test_rewrites_srcset_cache_urls(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img srcset="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/k1.webp 1x, https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/k2.webp 2x">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('?k=k1', $result);
        $this->assertStringContainsString('?k=k2', $result);
    }

    public function test_preserves_query_params_on_endpoint_url(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://example.com/wp-content/cache/oxpulse/' . self::H1 . '/key.webp">';
        $result = $rewriter->rewrite($html);

        // The endpoint URL should have ?k=key (no format extension).
        $this->assertStringContainsString('?k=key', $result);
        $this->assertStringNotContainsString('.webp', $result);
    }

    public function test_does_not_rewrite_other_domains(): void
    {
        $rewriter = new FallbackRewriter(
            homeUrl: 'https://example.com',
            endpointPath: '/wp-content/oxpulse-img.php',
        );

        $html = '<img src="https://other.com/wp-content/cache/oxpulse/' . self::H1 . '/key.webp">';
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }
}
