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

        // #43 Phase 3: a data-oxpulse="1" marker is now inserted right
        // after the opening <img, so the src is no longer the first
        // attribute.
        $this->assertStringStartsWith('<img data-oxpulse="1" src="https://imgproxy.example.com/', $result);
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

    // --- #43 Phase 3: tag-level idempotency + marker ---

    public function test_skips_img_with_data_oxpulse_marker(): void
    {
        $rewriter = $this->createContentRewriter();
        // Already marked by a previous pass — must be returned unchanged.
        $tag = '<img data-oxpulse="1" src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
        $this->assertStringNotContainsString('imgproxy.example.com', $result);
    }

    public function test_skips_img_with_sp_no_webp_class(): void
    {
        $rewriter = $this->createContentRewriter();
        // ShortPixel's already-handled marker — must be returned unchanged.
        $tag = '<img class="wp-image-42 sp-no-webp" src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
        $this->assertStringNotContainsString('imgproxy.example.com', $result);
    }

    public function test_sp_no_webp_class_match_is_word_boundary_aware(): void
    {
        $rewriter = $this->createContentRewriter();
        // A class named "sp-no-webp-extra" must NOT match — only the
        // exact "sp-no-webp" class is ShortPixel's marker.
        $tag = '<img class="sp-no-webp-extra" src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_adds_data_oxpulse_marker_on_rewrite(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('data-oxpulse="1"', $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_does_not_add_marker_when_no_rewrite_happened(): void
    {
        $rewriter = $this->createContentRewriter();
        // Non-allowed URL → no rewrite → no marker added.
        $tag = '<img src="https://evil.com/images/photo.jpg" alt="Test" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
        $this->assertStringNotContainsString('data-oxpulse', $result);
    }

    public function test_second_pass_skips_already_marked_tag(): void
    {
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';

        $firstPass = $rewriter->rewrite($tag, 'the_content', 0);
        $this->assertStringContainsString('data-oxpulse="1"', $firstPass);

        // A second pass over the already-marked tag must be a no-op.
        $secondPass = $rewriter->rewrite($firstPass, 'the_content', 0);
        $this->assertSame($firstPass, $secondPass);
    }

    // --- Phase 1: <picture> element wrapping integration ---

    private function createContentRewriterWithPicture(bool $pictureEnabled): ContentImgTagRewriter
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            pictureEnabled: $pictureEnabled,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $pictureWrapper = new \OXPulse\Imager\Application\Delivery\PictureElementWrapper($rewriter);
        return new ContentImgTagRewriter($rewriter, $delivery, null, $pictureWrapper);
    }

    public function test_picture_wrap_emits_picture_when_enabled(): void
    {
        $rewriter = $this->createContentRewriterWithPicture(pictureEnabled: true);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('<picture', $result);
        $this->assertStringContainsString('</picture>', $result);
        $this->assertStringContainsString('<source type="image/avif"', $result);
        $this->assertStringContainsString('<source type="image/webp"', $result);
        // AVIF source must come before WebP source.
        $avifPos = strpos($result, '<source type="image/avif"');
        $webpPos = strpos($result, '<source type="image/webp"');
        $this->assertNotFalse($avifPos);
        $this->assertNotFalse($webpPos);
        $this->assertLessThan($webpPos, $avifPos);
    }

    public function test_picture_wrap_skipped_when_disabled(): void
    {
        $rewriter = $this->createContentRewriterWithPicture(pictureEnabled: false);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('<picture', $result);
    }

    /**
     * FIX 2: the oxpulse_picture_enabled filter is the SINGLE honest
     * runtime gate. With config pictureEnabled=false BUT the filter
     * forced true, ContentImgTagRewriter::rewrite() MUST still emit a
     * <picture> — mirroring oxpulse_buffer_rewrite_enabled. Before the
     * fix, PictureElementWrapper::wrap() re-checked pictureEnabled and
     * bailed, making the force-enable filter a silent no-op.
     */
    public function test_picture_wrap_force_enabled_via_filter_overrides_disabled_config(): void
    {
        $rewriter = $this->createContentRewriterWithPicture(pictureEnabled: false);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';

        $GLOBALS['__oxpulse_filters'] = [];
        add_filter('oxpulse_picture_enabled', '__return_true');
        try {
            $result = $rewriter->rewrite($tag, 'the_content', 0);
            $this->assertStringContainsString('<picture', $result, 'filter force-enable must emit <picture> even when config pictureEnabled=false');
            $this->assertStringContainsString('</picture>', $result);
        } finally {
            $GLOBALS['__oxpulse_filters'] = [];
        }
    }

    public function test_picture_wrapper_null_skips_wrapping(): void
    {
        // When no PictureElementWrapper is injected (the default for all
        // pre-Phase-1 callers), wrapping is skipped — backward compatible.
        $rewriter = $this->createContentRewriter();
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringNotContainsString('<picture', $result);
    }

    /**
     * Real-shape integration test: a content <img> WITH a srcset (the
     * common case — WordPress core auto-adds srcset via
     * wp_calculate_image_srcset) must still get a <picture> wrapper with
     * per-format <source> elements. This exercises the FULL rewrite path:
     * ContentImgTagRewriter::rewrite() rewrites the src AND srcset first,
     * THEN calls PictureElementWrapper::wrap(). The per-format srcset
     * must be built from the ORIGINAL srcset (pre-rewrite), not the
     * already-rewritten one — otherwise every candidate is rejected by
     * the proxy-loop / already-rewritten guard and no <source> is emitted.
     */
    public function test_picture_wrap_with_srcset_bearing_img_emits_picture(): void
    {
        $rewriter = $this->createContentRewriterWithPicture(pictureEnabled: true);
        $tag = '<img src="https://example.com/wp-content/uploads/img.jpg" srcset="https://example.com/wp-content/uploads/img-300.jpg 300w, https://example.com/wp-content/uploads/img-800.jpg 800w" sizes="(max-width: 800px) 100vw, 800px" width="800" height="600" />';

        $result = $rewriter->rewrite($tag, 'the_content', 0);

        // Must be a <picture>, not a plain <img>.
        $this->assertStringContainsString('<picture', $result);
        $this->assertStringContainsString('</picture>', $result);

        // Extract the <source> tags in document order.
        preg_match_all('/<source\b[^>]*>/i', $result, $sourceMatches);
        $sources = $sourceMatches[0];
        $this->assertCount(2, $sources, 'expected exactly 2 <source> elements (avif + webp)');

        // First source = AVIF, second = WebP.
        $this->assertStringContainsString('type="image/avif"', $sources[0]);
        $this->assertStringContainsString('type="image/webp"', $sources[1]);

        foreach ($sources as $source) {
            // srcset must be non-empty.
            $this->assertMatchesRegularExpression('/srcset="[^"]+"/', $source, 'source srcset must be present and non-empty');

            // Parse the srcset value — must have exactly 2 candidates.
            preg_match('/\bsrcset="([^"]*)"/', $source, $srcsetMatch);
            $srcsetValue = $srcsetMatch[1];
            $candidates = array_filter(explode(', ', $srcsetValue), fn ($c) => trim($c) !== '');
            $this->assertCount(2, $candidates, 'per-format srcset must have 2 candidates (300w + 800w)');

            // Each candidate must carry the 300w / 800w descriptor and be
            // a rewritten delivery URL (starts with the imgproxy endpoint,
            // not a bare original URL). imgproxy URLs embed the original
            // source in the plain/ segment, so we check the prefix.
            $descriptors = [];
            foreach ($candidates as $candidate) {
                $this->assertStringStartsWith('https://imgproxy.example.com/', trim($candidate), 'candidate must be a rewritten delivery URL');
                if (preg_match('/\s(\d+w)$/', $candidate, $dMatch)) {
                    $descriptors[] = $dMatch[1];
                }
            }
            $this->assertContains('300w', $descriptors);
            $this->assertContains('800w', $descriptors);

            // sizes must be copied onto each <source>.
            $this->assertStringContainsString('sizes="(max-width: 800px) 100vw, 800px"', $source);
        }
    }
}
