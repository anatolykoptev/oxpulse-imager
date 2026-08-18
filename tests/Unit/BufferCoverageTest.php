<?php
/**
 * Full-page coverage tests (#136): CSS background-image in inline
 * style attributes and <link rel=preload as=image> href rewriting
 * through the BufferRewriter.
 *
 * These are the two escape hatches an attachment-API-shaped delivery
 * surface cannot see: theme/builder markup with backgrounds, and
 * theme-emitted preload hints that would otherwise double-download
 * (origin preload + proxied <img>).
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
use OXPulse\Imager\Integration\WordPress\Delivery\BufferRewriter;
use PHPUnit\Framework\TestCase;

class BufferCoverageTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const IMG = 'https://example.com/wp-content/uploads/bg.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_filters'], $GLOBALS['__oxpulse_options']);
        parent::tearDown();
    }

    private function config(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            bufferRewritingEnabled: true,
        );
    }

    private function buffer(): BufferRewriter
    {
        $cfg = $this->config();
        return new BufferRewriter(
            new UrlRewriter(new SourcePolicy(), $cfg, SigningConfig::fromHex('736563726574', '68656C6C6F')),
            $cfg
        );
    }

    private function wrap(string $fragment): string
    {
        return '<html><body>' . $fragment . '</body></html>';
    }

    // ─── CSS backgrounds in inline style attributes ─────────────────

    public function test_rewrites_background_image_in_style_attribute(): void
    {
        $html = $this->wrap('<div class="hero" style="background-image:url(' . self::IMG . ');height:300px"></div>');
        $out = $this->buffer()->rewrite($html);

        $this->assertStringContainsString('https://imgproxy.example.com/', $out);
        $this->assertStringNotContainsString('url(' . self::IMG . ')', $out);
        $this->assertStringContainsString('height:300px', $out);
    }

    public function test_rewrites_quoted_background_url(): void
    {
        $html = $this->wrap("<div style='background: #fff url(\"" . self::IMG . "\") no-repeat'></div>");
        $out = $this->buffer()->rewrite($html);

        $this->assertStringContainsString('https://imgproxy.example.com/', $out);
        $this->assertStringContainsString('no-repeat', $out);
    }

    public function test_preserves_external_and_data_backgrounds(): void
    {
        $html = $this->wrap(
            '<div style="background-image:url(https://other.site/a.jpg)"></div>'
            . '<div style="background-image:url(data:image/svg+xml;base64,AAAA)"></div>'
        );
        $out = $this->buffer()->rewrite($html);

        $this->assertSame($html, $out);
    }

    public function test_background_in_style_TAG_is_not_touched(): void
    {
        // Only inline style ATTRIBUTES in v1 — a <style> block needs a
        // CSS parser, not a regex; leave it byte-identical.
        $html = $this->wrap('<style>.x{background:url(' . self::IMG . ')}</style>');
        $out = $this->buffer()->rewrite($html);

        $this->assertSame($html, $out);
    }

    // ─── <link rel=preload as=image> ─────────────────────────────────

    public function test_rewrites_image_preload_href(): void
    {
        $html = $this->wrap('<link rel="preload" as="image" href="' . self::IMG . '" fetchpriority="high">');
        $out = $this->buffer()->rewrite($html);

        $this->assertStringContainsString('href="https://imgproxy.example.com/', $out);
        $this->assertStringContainsString('fetchpriority="high"', $out);
    }

    public function test_rewrites_preload_with_reversed_attr_order(): void
    {
        $html = $this->wrap('<link as="image" href="' . self::IMG . '" rel="preload">');
        $out = $this->buffer()->rewrite($html);

        $this->assertStringContainsString('href="https://imgproxy.example.com/', $out);
    }

    public function test_non_image_preload_untouched(): void
    {
        $html = $this->wrap('<link rel="preload" as="style" href="https://example.com/wp-content/themes/x/main.css">');
        $out = $this->buffer()->rewrite($html);

        $this->assertSame($html, $out);
    }

    public function test_page_with_only_background_and_no_img_is_still_processed(): void
    {
        // The old fast-path bailed when no <img> present — backgrounds
        // and preloads must lift it.
        $html = $this->wrap('<div style="background-image:url(' . self::IMG . ')"></div>');
        $out = $this->buffer()->rewrite($html);

        $this->assertStringContainsString('https://imgproxy.example.com/', $out);
    }
}
