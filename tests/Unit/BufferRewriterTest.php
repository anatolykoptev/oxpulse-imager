<?php
/**
 * BufferRewriter tests.
 *
 * Verifies ob_start buffer rewriting for theme-hardcoded <img> tags:
 * - rewrites src and data-src attributes pointing at /wp-content/
 * - preserves URLs not under /wp-content/ (external images)
 * - preserves URLs with non-image extensions
 * - skips buffers > 2MB and buffers without <img
 * - handles single-quoted attributes
 * - catastrophic backtracking regression test (malformed 4KB <img tag)
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

class BufferRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function createDeliveryConfig(bool $enabled = true): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: $enabled,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            bufferRewritingEnabled: true,
        );
    }

    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            $this->createDeliveryConfig($enabled),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    private function createBufferRewriter(bool $enabled = true): BufferRewriter
    {
        return new BufferRewriter(
            $this->createRewriter($enabled),
            $this->createDeliveryConfig($enabled)
        );
    }

    public function test_empty_buffer_returned_unchanged(): void
    {
        $rewriter = $this->createBufferRewriter();
        $this->assertSame('', $rewriter->rewrite(''));
    }

    public function test_buffer_without_img_returned_unchanged(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<html><body><p>no images here</p></body></html>';
        $this->assertSame($html, $rewriter->rewrite($html));
    }

    public function test_rewrites_src_attribute_for_wp_content_image(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<img src="https://example.com/wp-content/uploads/2024/01/photo.jpg" alt="test">';
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('plain/https://example.com/wp-content/uploads/2024/01/photo.jpg', $result);
    }

    public function test_rewrites_data_src_attribute_for_lazy_load(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<img data-src="https://example.com/wp-content/uploads/photo.jpg" src="placeholder.gif" alt="lazy">';
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        // data-src should be rewritten; placeholder.gif (no /wp-content/) preserved
        $this->assertStringContainsString('placeholder.gif', $result);
    }

    public function test_preserves_external_images_not_in_wp_content(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<img src="https://cdn.example.com/photo.jpg" alt="external">';
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    public function test_preserves_non_image_extensions_in_wp_content(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<img src="https://example.com/wp-content/uploads/document.pdf" alt="doc">';
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    public function test_handles_single_quoted_attributes(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = "<img src='https://example.com/wp-content/uploads/photo.jpg' alt='test'>";
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_extracts_width_height_for_better_resize(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" alt="test">';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('rs:fit:800:600', $result);
    }

    public function test_rewrites_multiple_img_tags_in_one_buffer(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<div>'
            . '<img src="https://example.com/wp-content/uploads/a.jpg" alt="a">'
            . '<img src="https://example.com/wp-content/uploads/b.png" alt="b">'
            . '</div>';
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('imgproxy.example.com', $result);
        // Both images rewritten — count occurrences of imgproxy in result
        $this->assertSame(2, substr_count($result, 'imgproxy.example.com'));
    }

    public function test_preserves_disallowed_wp_content_url(): void
    {
        // URL under /wp-content/ but not in the allowed sources allowlist.
        $rewriter = new BufferRewriter(
            new UrlRewriter(
                new SourcePolicy(),
                new DeliveryConfig(
                    enabled: true,
                    endpoint: 'https://imgproxy.example.com',
                    allowedSources: ['https://other.com/wp-content/uploads/'],
                    bufferRewritingEnabled: true,
                ),
                SigningConfig::fromHex('736563726574', '68656C6C6F')
            ),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://other.com/wp-content/uploads/'],
                bufferRewritingEnabled: true,
            )
        );

        $html = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">';
        $result = $rewriter->rewrite($html);

        // Source not in allowlist → preserved.
        $this->assertSame($html, $result);
    }

    public function test_skips_buffer_over_2mb(): void
    {
        $rewriter = $this->createBufferRewriter();
        // Build a 2.1MB buffer containing an <img tag.
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">';
        $padding = str_repeat('x', 2 * 1024 * 1024 + 100);
        $html = $padding . $img;
        $result = $rewriter->rewrite($html);

        // Buffer too large → preserved unchanged.
        $this->assertSame($html, $result);
    }

    public function test_does_not_double_rewrite_imgproxy_urls(): void
    {
        // An already-rewritten imgproxy URL does not contain /wp-content/,
        // so the buffer regex won't match it — no double rewrite.
        $rewriter = $this->createBufferRewriter();
        $alreadyRewritten = '<img src="https://imgproxy.example.com/sig/rs:fit:800:0/plain/abc@avif" alt="test">';
        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertSame($alreadyRewritten, $result);
    }

    /**
     * Catastrophic backtracking regression test.
     *
     * A bounded quantifier like [^"\x27]{1,2000} caused O(n²) backtracking
     * on malformed <img tags with an unterminated quote followed by many
     * non-quote chars. The unbounded greedy [^"\x27]+ is linear. This test
     * verifies the regex completes quickly on a 4KB malformed tag.
     */
    public function test_no_catastrophic_backtracking_on_malformed_tag(): void
    {
        $rewriter = $this->createBufferRewriter();
        // 4KB of non-quote, non-> chars after an unterminated quote.
        // This is the worst case for a bounded quantifier.
        $malformed = '<img src="' . str_repeat('a', 4000) . ' <img';
        // Add a real img tag at the end to ensure the regex runs.
        $html = $malformed . '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="ok">';

        $start = microtime(true);
        $result = $rewriter->rewrite($html);
        $elapsed = microtime(true) - $start;

        // Must complete in well under 1 second. A bounded quantifier would
        // take seconds on this input. We assert < 500ms with margin.
        $this->assertLessThan(0.5, $elapsed, 'Regex must not exhibit catastrophic backtracking');
        // The real img tag at the end should still be rewritten.
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = $this->createBufferRewriter(enabled: false);
        $html = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">';
        $result = $rewriter->rewrite($html);

        // Delivery disabled → UrlRewriter preserves → buffer regex matches
        // but the rewrite result is the original URL → no change.
        $this->assertSame($html, $result);
    }

    public function test_rewrites_various_image_extensions(): void
    {
        $rewriter = $this->createBufferRewriter();
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff'];

        foreach ($extensions as $ext) {
            $html = '<img src="https://example.com/wp-content/uploads/photo.' . $ext . '" alt="test">';
            $result = $rewriter->rewrite($html);
            $this->assertStringContainsString(
                'imgproxy.example.com',
                $result,
                "Extension .$ext should be rewritten"
            );
        }
    }
}
