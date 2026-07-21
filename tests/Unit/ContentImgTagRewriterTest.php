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

    private function createDeliveryConfig(bool $enabled = true): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: $enabled,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
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

    private function createContentRewriter(bool $enabled = true): ContentImgTagRewriter
    {
        return new ContentImgTagRewriter(
            $this->createRewriter($enabled),
            $this->createDeliveryConfig($enabled)
        );
    }

    public function test_empty_tag_returned_unchanged(): void
    {
        $rewriter = $this->createContentRewriter();
        $this->assertSame('', $rewriter->rewrite('', 'the_content', 0));
    }

    public function test_rewrites_src_attribute_for_allowed_url(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringStartsWith('<img src="https://imgproxy.example.com/', $result);
        $this->assertStringContainsString('plain/https://example.com/wp-content/uploads/photo.jpg', $result);
        $this->assertStringContainsString('alt="Test"', $result);
    }

    public function test_preserves_src_for_non_allowed_url(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://evil.com/images/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_rewrites_srcset_candidates(): void
    {
        $rewriter = $this->createContentRewriter();
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
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://evil.com/photo.jpg" srcset="https://evil.com/photo-300.jpg 300w" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_mixed_srcset_allowed_and_not_allowed_preserves_not_allowed(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="https://example.com/wp-content/uploads/photo-300.jpg 300w, https://evil.com/photo-600.jpg 600w" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // The allowed URL should be rewritten, the non-allowed preserved.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('evil.com/photo-600.jpg 600w', $result);
    }

    public function test_uses_width_height_attributes_for_resize(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('rs:fill:800:600', $result);
    }

    public function test_omits_resize_when_no_width_height(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('rs:', $result);
    }

    public function test_preserves_tag_without_src(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img alt="No src" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = $this->createContentRewriter(enabled: false);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_srcset_2x_descriptor_rewrites_without_width(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" srcset="https://example.com/wp-content/uploads/photo.jpg 2x" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // 2x descriptor does not provide a width, so no resize option.
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('2x', $result);
        $this->assertStringNotContainsString('rs:', $result);
    }

    // --- Phase 5.1: LQIP placeholder tests ---

    public function test_lqip_adds_data_placeholder_when_enabled(): void
    {
        $delivery = $this->createDeliveryConfig();
        $delivery = new DeliveryConfig(
            enabled: $delivery->enabled,
            endpoint: $delivery->endpoint,
            allowedSources: $delivery->allowedSources,
            lqipEnabled: true,
            lqipBlur: 1,
        );
        $rewriter = $this->createRewriter();
        $lqipBuilder = new \OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder($rewriter);
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery, $lqipBuilder);

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('data-placeholder=', $result);
    }

    public function test_lqip_skipped_when_disabled(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('data-placeholder', $result);
    }

    public function test_lqip_does_not_overwrite_existing_placeholder(): void
    {
        $delivery = $this->createDeliveryConfig();
        $delivery = new DeliveryConfig(
            enabled: $delivery->enabled,
            endpoint: $delivery->endpoint,
            allowedSources: $delivery->allowedSources,
            lqipEnabled: true,
        );
        $rewriter = $this->createRewriter();
        $lqipBuilder = new \OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder($rewriter);
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery, $lqipBuilder);

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" data-placeholder="existing-placeholder" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        // Should not overwrite the existing placeholder.
        $this->assertStringContainsString('data-placeholder="existing-placeholder"', $result);
    }

    // --- Phase 5.1: DPR srcset tests ---

    public function test_dpr_adds_srcset_for_img_without_srcset(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            dprEnabled: true,
            dprVariants: [1, 2, 3],
        );
        $rewriter = new \OXPulse\Imager\Application\Delivery\UrlRewriter(
            new \OXPulse\Imager\Domain\Source\SourcePolicy(),
            $delivery,
            \OXPulse\Imager\Domain\Config\SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="400" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('srcset=', $result);
        $this->assertStringContainsString('1x', $result);
        $this->assertStringContainsString('2x', $result);
        $this->assertStringContainsString('3x', $result);
        $this->assertStringContainsString('dpr:2', $result);
        $this->assertStringContainsString('dpr:3', $result);
    }

    public function test_dpr_skipped_when_img_already_has_srcset(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            dprEnabled: true,
            dprVariants: [1, 2, 3],
        );
        $rewriter = new \OXPulse\Imager\Application\Delivery\UrlRewriter(
            new \OXPulse\Imager\Domain\Source\SourcePolicy(),
            $delivery,
            \OXPulse\Imager\Domain\Config\SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="400" srcset="https://example.com/wp-content/uploads/photo-800.jpg 800w" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        // Should not add x-descriptor srcset when w-descriptor srcset exists.
        $this->assertStringNotContainsString(' 1x', $result);
        $this->assertStringNotContainsString(' 2x', $result);
    }

    public function test_dpr_skipped_when_no_width(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            dprEnabled: true,
            dprVariants: [1, 2, 3],
        );
        $rewriter = new \OXPulse\Imager\Application\Delivery\UrlRewriter(
            new \OXPulse\Imager\Domain\Source\SourcePolicy(),
            $delivery,
            \OXPulse\Imager\Domain\Config\SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('srcset=', $result);
    }
}
