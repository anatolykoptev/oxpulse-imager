<?php
/**
 * ContentImgTagRewriter tests.
 *
 * Verifies <img> tag rewriting: src attribute, srcset attribute,
 * width/height extraction, and preservation of non-allowed images.
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
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use PHPUnit\Framework\TestCase;

class ContentImgTagRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_empty_tag_returned_unchanged(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $this->assertSame('', $rewriter->rewrite('', 'the_content', 0));
    }

    public function test_rewrites_src_attribute_for_allowed_url(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringStartsWith('<img src="https://imgproxy.example.com/', $result);
        $this->assertStringContainsString('plain/https://example.com/wp-content/uploads/photo.jpg', $result);
        $this->assertStringContainsString('alt="Test"', $result);
    }

    public function test_preserves_src_for_non_allowed_url(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://evil.com/images/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_rewrites_srcset_candidates(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="https://example.com/wp-content/uploads/photo-300.jpg 300w, https://example.com/wp-content/uploads/photo-600.jpg 600w" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // Both srcset candidates should be rewritten to imgproxy URLs.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('300w', $result);
        $this->assertStringContainsString('600w', $result);
        // The original direct URLs should no longer appear as src values.
        $this->assertStringNotContainsString('src="https://example.com', $result);
    }

    public function test_preserves_srcset_for_non_allowed_urls(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://evil.com/photo.jpg" srcset="https://evil.com/photo-300.jpg 300w" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_mixed_srcset_allowed_and_not_allowed_preserves_not_allowed(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="https://example.com/wp-content/uploads/photo-300.jpg 300w, https://evil.com/photo-600.jpg 600w" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // The allowed URL should be rewritten, the non-allowed preserved.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('evil.com/photo-600.jpg 600w', $result);
    }

    public function test_uses_width_height_attributes_for_resize(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('rs:fit:800:600', $result);
    }

    public function test_omits_resize_when_no_width_height(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('rs:', $result);
    }

    public function test_preserves_tag_without_src(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img alt="No src" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter(enabled: false));
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_srcset_2x_descriptor_rewrites_without_width(): void
    {
        $rewriter = new ContentImgTagRewriter($this->createRewriter());
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="https://example.com/wp-content/uploads/photo.jpg 2x" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // 2x descriptor does not provide a width, so no resize option.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('2x', $result);
        $this->assertStringNotContainsString('rs:', $result);
    }
}
