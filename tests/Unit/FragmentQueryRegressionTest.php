<?php
/**
 * Ф10: regression tests for URL with query/fragment handling.
 *
 * Confirms that:
 * 1. URLs carrying a query string (?ver=123) are rewritten (query is
 *    preserved in the imgproxy source URL for cache-busting).
 * 2. URLs carrying a fragment (#section) are rewritten (fragment is
 *    silently stripped by NormalizedUrl::parse, never forwarded to
 *    imgproxy — fragments are client-side only).
 * 3. URLs carrying both ?query#fragment are rewritten (query preserved,
 *    fragment stripped).
 *
 * The mu-plugin this replaces strips fragments via
 * preg_replace('/[#?].*$/', '', $url) before pathinfo. imager strips
 * fragments in NormalizedUrl::parse() — the fragment is silently
 * dropped and the URL is authorized on its path+query alone.
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
use OXPulse\Imager\Domain\Source\NormalizedUrl;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class FragmentQueryRegressionTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const SOURCE = 'https://example.com/wp-content/uploads/photo.jpg';

    private function createRewriter(): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_url_with_query_string_is_rewritten(): void
    {
        $rewriter = $this->createRewriter();
        // ?ver=123 is a common WordPress cache-buster — must not break
        // the rewrite. The query is preserved in the imgproxy source URL.
        $result = $rewriter->rewrite(self::SOURCE . '?ver=123', 0, 0);

        $this->assertTrue($result->rewritten, 'URL with ?query must be rewritten');
        $this->assertStringContainsString('?ver=123', $result->url);
    }

    public function test_url_with_fragment_is_rewritten(): void
    {
        $rewriter = $this->createRewriter();
        // #section is client-side only — must be stripped, not rejected.
        // Before Ф10, NormalizedUrl::parse() threw on fragments, causing
        // SourcePolicy to deny the URL as 'malformed_url'.
        $result = $rewriter->rewrite(self::SOURCE . '#section', 0, 0);

        $this->assertTrue($result->rewritten, 'URL with #fragment must be rewritten');
        // Fragment must NOT appear in the rewritten imgproxy URL.
        $this->assertStringNotContainsString('#section', $result->url);
    }

    public function test_url_with_query_and_fragment_is_rewritten(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(self::SOURCE . '?ver=123#section', 0, 0);

        $this->assertTrue($result->rewritten, 'URL with ?query#fragment must be rewritten');
        // Query preserved, fragment stripped.
        $this->assertStringContainsString('?ver=123', $result->url);
        $this->assertStringNotContainsString('#section', $result->url);
    }

    public function test_url_with_webp_and_query_is_rewritten(): void
    {
        $rewriter = $this->createRewriter();
        $result = $rewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.webp?ver=456',
            0,
            0
        );

        $this->assertTrue($result->rewritten);
        $this->assertStringContainsString('?ver=456', $result->url);
    }

    public function test_normalized_url_parse_strips_fragment(): void
    {
        // Direct verification of the NormalizedUrl::parse contract.
        $url = NormalizedUrl::parse('https://example.com/image.jpg#fragment');
        $this->assertSame('https://example.com/image.jpg', (string) $url);
        $this->assertStringNotContainsString('#fragment', (string) $url);
    }

    public function test_normalized_url_parse_preserves_query(): void
    {
        $url = NormalizedUrl::parse('https://example.com/image.jpg?ver=123');
        $this->assertSame('https://example.com/image.jpg?ver=123', (string) $url);
    }

    public function test_normalized_url_parse_strips_fragment_keeps_query(): void
    {
        $url = NormalizedUrl::parse('https://example.com/image.jpg?ver=123#section');
        $this->assertSame('https://example.com/image.jpg?ver=123', (string) $url);
    }

    public function test_normalized_url_parse_strips_empty_fragment(): void
    {
        // Trailing # with no fragment text — still valid, fragment stripped.
        $url = NormalizedUrl::parse('https://example.com/image.jpg#');
        $this->assertSame('https://example.com/image.jpg', (string) $url);
    }
}
