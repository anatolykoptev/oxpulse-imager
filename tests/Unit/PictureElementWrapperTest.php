<?php
/**
 * PictureElementWrapper unit tests.
 *
 * Verifies the <picture> wrapping logic: AVIF-first source order,
 * fallback-guard, idempotency, srcset mapping, attribute preservation,
 * and the data-oxpulse-picture marker. Uses a stub DeliveryBackend so
 * the emitted URLs are deterministic and format-selective failure can
 * be exercised.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\PictureElementWrapper;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Tests\Unit\Stubs\PictureTestBackend;
use PHPUnit\Framework\TestCase;

class PictureElementWrapperTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';

    private function createRewriter(array $allowedFormats = []): UrlRewriter
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.test',
            allowedSources: [self::ALLOWED],
            pictureEnabled: true,
        );
        return new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX),
            null,
            new PictureTestBackend($allowedFormats)
        );
    }

    private function createWrapper(array $allowedFormats = []): PictureElementWrapper
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.test',
            allowedSources: [self::ALLOWED],
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX),
            null,
            new PictureTestBackend($allowedFormats)
        );
        return new PictureElementWrapper($rewriter);
    }

    /**
     * Parse a <picture> HTML fragment and return the source types in
     * document order + the inner <img> tag. Asserts the structure by
     * parsing, not loose substring matching.
     */
    private function parsePicture(string $html): array
    {
        $this->assertSame(1, preg_match('/^<picture[\s>]/i', $html), 'Expected <picture> wrapper');
        // Extract <source> tags in order.
        preg_match_all('/<source\b[^>]*>/i', $html, $sourceMatches);
        $sources = $sourceMatches[0];

        // Extract type attributes in order.
        $types = [];
        foreach ($sources as $src) {
            preg_match('/\btype=["\']([^"\']+)["\']/i', $src, $m);
            $types[] = $m[1] ?? '';
        }

        // Extract the inner <img>.
        preg_match('/(<img\b[^>]*>)/i', $html, $imgMatch);
        $img = $imgMatch[1] ?? '';

        return ['sources' => $sources, 'types' => $types, 'img' => $img, 'html' => $html];
    }

    public function test_both_formats_rewrite_emits_picture_avif_first(): void
    {
        $wrapper = $this->createWrapper();
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" alt="Test" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $result = $wrapper->wrap($img, $src, '', 800, 600);
        $parsed = $this->parsePicture($result);

        $this->assertSame(['image/avif', 'image/webp'], $parsed['types']);
        // FIX 1: the <picture> open tag must carry style="display:contents"
        // so its box is removed from the layout tree and the inner <img>
        // stays the flex/grid layout participant (wrapping <img> in
        // <picture> otherwise inserts a new box that breaks direct-child
        // CSS rules in flex/grid image containers).
        $this->assertStringContainsString('<picture style="display:contents">', $result);
        $this->assertStringContainsString('data-oxpulse-picture="1"', $parsed['img']);
        // Inner img attributes preserved.
        $this->assertStringContainsString('alt="Test"', $parsed['img']);
        $this->assertStringContainsString('width="800"', $parsed['img']);
        $this->assertStringContainsString('height="600"', $parsed['img']);
        $this->assertStringContainsString('src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"', $parsed['img']);
    }

    public function test_only_webp_rewrites_emits_only_webp_source(): void
    {
        // avif rejected by the backend, webp allowed.
        $wrapper = $this->createWrapper(allowedFormats: ['avif' => false]);
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $result = $wrapper->wrap($img, $src, '', 800, 600);
        $parsed = $this->parsePicture($result);

        $this->assertSame(['image/webp'], $parsed['types']);
        $this->assertStringContainsString('data-oxpulse-picture="1"', $parsed['img']);
    }

    public function test_neither_rewrites_returns_img_unchanged(): void
    {
        $wrapper = $this->createWrapper(allowedFormats: ['avif' => false, 'webp' => false]);
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $this->assertSame($img, $wrapper->wrap($img, $src, '', 800, 600));
    }

    public function test_already_inside_picture_returns_unchanged(): void
    {
        $wrapper = $this->createWrapper();
        $html = '<picture><source type="image/avif" srcset="x.avif"><img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" /></picture>';

        $this->assertSame($html, $wrapper->wrap($html, 'https://example.com/wp-content/uploads/photo.jpg', '', 800, 600));
    }

    public function test_second_pass_over_marked_img_returns_unchanged(): void
    {
        $wrapper = $this->createWrapper();
        $img = '<img data-oxpulse-picture="1" src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';

        $this->assertSame($img, $wrapper->wrap($img, 'https://example.com/wp-content/uploads/photo.jpg', '', 800, 600));
    }

    public function test_inner_img_has_srcset_sources_get_per_format_srcset(): void
    {
        $wrapper = $this->createWrapper();
        $srcset = 'https://example.com/wp-content/uploads/photo-300.jpg 300w, https://example.com/wp-content/uploads/photo-600.jpg 600w';
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="' . $srcset . '" sizes="(max-width: 600px) 100vw, 50vw" width="600" height="400" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $result = $wrapper->wrap($img, $src, $srcset, 600, 400);
        $parsed = $this->parsePicture($result);

        $this->assertSame(['image/avif', 'image/webp'], $parsed['types']);

        // Each source must carry a srcset with BOTH descriptors.
        foreach ($parsed['sources'] as $source) {
            $this->assertStringContainsString('300w', $source);
            $this->assertStringContainsString('600w', $source);
            // sizes must be copied onto each source.
            $this->assertStringContainsString('sizes="', $source);
            $this->assertStringContainsString('(max-width: 600px) 100vw, 50vw', $source);
        }
    }

    public function test_inner_img_no_srcset_sources_get_single_url_srcset(): void
    {
        $wrapper = $this->createWrapper();
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $result = $wrapper->wrap($img, $src, '', 800, 600);
        $parsed = $this->parsePicture($result);

        $this->assertSame(['image/avif', 'image/webp'], $parsed['types']);

        // No w-descriptor (no "Nw") in the source srcset — single URL.
        foreach ($parsed['sources'] as $source) {
            $srcsetVal = $this->extractSrcset($source);
            $this->assertDoesNotMatchRegularExpression('/\d+w/', $srcsetVal, 'single-url srcset must not carry a w-descriptor');
            // No sizes attribute when the inner img had none.
            $this->assertStringNotContainsString('sizes=', $source);
        }
    }

    public function test_empty_original_src_returns_unchanged(): void
    {
        $wrapper = $this->createWrapper();
        $img = '<img src="" width="800" height="600" />';

        $this->assertSame($img, $wrapper->wrap($img, '', '', 800, 600));
    }

    public function test_empty_img_tag_returns_unchanged(): void
    {
        $wrapper = $this->createWrapper();
        $this->assertSame('', $wrapper->wrap('', 'https://example.com/wp-content/uploads/photo.jpg', '', 800, 600));
    }

    public function test_urls_are_escaped_in_source_attributes(): void
    {
        $wrapper = $this->createWrapper();
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';
        $src = 'https://example.com/wp-content/uploads/photo.jpg';

        $result = $wrapper->wrap($img, $src, '', 800, 600);

        // The source srcset must be a properly quoted attribute value.
        // No unescaped & or " inside the attribute (the test URLs have none,
        // but the structure must be srcset="...").
        preg_match_all('/<source\b[^>]*>/i', $result, $sources);
        foreach ($sources[0] as $source) {
            $this->assertMatchesRegularExpression('/srcset="[^"]*"/', $source, 'srcset must be double-quoted attribute');
        }
    }

    private function extractSrcset(string $sourceTag): string
    {
        preg_match('/\bsrcset=["\']([^"\']*)["\']/', $sourceTag, $m);
        return $m[1] ?? '';
    }
}
