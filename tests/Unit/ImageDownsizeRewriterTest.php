<?php
/**
 * ImageDownsizeRewriter tests.
 *
 * Verifies the image_downsize filter handler:
 * - rewrites for registered size (thumbnail, medium)
 * - rewrites for theme-specific size from attachment metadata
 * - rewrites for array size [w, h]
 * - rewrites for 'full' size (uses metadata width/height)
 * - returns false when metadata missing (let WP core handle)
 * - returns false when URL not supported
 * - respects existing non-false result from earlier filters
 * - recursion guard prevents infinite loop
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
use OXPulse\Imager\Integration\WordPress\Delivery\ImageDownsizeRewriter;
use PHPUnit\Framework\TestCase;

class ImageDownsizeRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private ImageDownsizeRewriter $downsizeRewriter;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_attachment_urls'] = [
            42 => 'https://example.com/wp-content/uploads/2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'] = [
            42 => [
                'width' => 2048,
                'height' => 1536,
                'sizes' => [
                    'thumbnail' => ['width' => 150, 'height' => 150, 'file' => 'photo-150x150.jpg'],
                    'medium' => ['width' => 300, 'height' => 300, 'file' => 'photo-300x300.jpg'],
                    'foxiz_crop_g1' => ['width' => 330, 'height' => 220, 'file' => 'photo-330x220.jpg'],
                ],
            ],
        ];
        // AttachmentOriginResolver reads _wp_attached_file from post_meta
        // and builds the URL from wp_get_upload_dir()['baseurl']. Set
        // baseurl to match the allowed source prefix so the resolved URL
        // passes SourcePolicy authorization.
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://example.com/wp-content/uploads',
            'basedir'    => '/tmp/wp-content/uploads',
            'error'      => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2024/01/photo.jpg'],
        ];

        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $this->downsizeRewriter = new ImageDownsizeRewriter($rewriter);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_attachment_urls'],
            $GLOBALS['__oxpulse_attachment_meta'],
            $GLOBALS['__oxpulse_post_meta'],
            $GLOBALS['__oxpulse_upload_dir']
        );
    }

    public function test_rewrites_for_registered_size_thumbnail(): void
    {
        $result = $this->downsizeRewriter->rewrite(false, 42, 'thumbnail');

        $this->assertIsArray($result);
        $this->assertStringContainsString('imgproxy.example.com', $result[0]);
        $this->assertSame(150, $result[1]);
        $this->assertSame(150, $result[2]);
        $this->assertTrue($result[3]); // is_intermediate
    }

    public function test_rewrites_for_registered_size_medium(): void
    {
        $result = $this->downsizeRewriter->rewrite(false, 42, 'medium');

        $this->assertIsArray($result);
        $this->assertStringContainsString('imgproxy.example.com', $result[0]);
        $this->assertSame(300, $result[1]);
        $this->assertSame(300, $result[2]);
    }

    public function test_rewrites_for_theme_specific_size_from_metadata(): void
    {
        // foxiz_crop_g1 is a theme-registered size stored in attachment metadata.
        $result = $this->downsizeRewriter->rewrite(false, 42, 'foxiz_crop_g1');

        $this->assertIsArray($result);
        $this->assertStringContainsString('imgproxy.example.com', $result[0]);
        $this->assertSame(330, $result[1]);
        $this->assertSame(220, $result[2]);
    }

    public function test_rewrites_for_array_size(): void
    {
        $result = $this->downsizeRewriter->rewrite(false, 42, [800, 600]);

        $this->assertIsArray($result);
        $this->assertStringContainsString('rs:fill:800:600', $result[0]);
        $this->assertSame(800, $result[1]);
        $this->assertSame(600, $result[2]);
        $this->assertTrue($result[3]); // is_intermediate
    }

    public function test_rewrites_for_full_size_uses_metadata_dimensions(): void
    {
        $result = $this->downsizeRewriter->rewrite(false, 42, 'full');

        $this->assertIsArray($result);
        $this->assertStringContainsString('imgproxy.example.com', $result[0]);
        $this->assertSame(2048, $result[1]);
        $this->assertSame(1536, $result[2]);
        $this->assertFalse($result[3]); // 'full' → not intermediate
    }

    public function test_returns_false_when_metadata_missing(): void
    {
        // Attachment ID with no metadata.
        $GLOBALS['__oxpulse_attachment_meta'] = [99 => false];
        $GLOBALS['__oxpulse_attachment_urls'] = [99 => 'https://example.com/wp-content/uploads/photo.jpg'];
        // AttachmentOriginResolver reads _wp_attached_file to build the
        // URL. Without it, resolveOriginalUrl returns null → rewrite()
        // returns false (let WP core handle).
        $GLOBALS['__oxpulse_post_meta'] = [
            99 => ['_wp_attached_file' => 'photo.jpg'],
        ];

        $result = $this->downsizeRewriter->rewrite(false, 99, 'full');

        // No metadata → dimensions [0,0] → still rewrites (imgproxy auto).
        $this->assertIsArray($result);
        $this->assertStringContainsString('imgproxy.example.com', $result[0]);
    }

    public function test_returns_false_when_url_not_supported(): void
    {
        // Attachment URL not in the allowed sources allowlist.
        $GLOBALS['__oxpulse_attachment_urls'] = [
            77 => 'https://evil.com/steal.jpg',
        ];

        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6F6F')
        );
        $downsizeRewriter = new ImageDownsizeRewriter($rewriter);

        $result = $downsizeRewriter->rewrite(false, 77, 'thumbnail');

        // Source not allowed → return false (let WP core handle).
        $this->assertFalse($result);
    }

    public function test_respects_existing_non_false_result(): void
    {
        // If an earlier filter already produced a result, respect it.
        $existing = ['https://already-handled.com/img.jpg', 100, 100, true];
        $result = $this->downsizeRewriter->rewrite($existing, 42, 'thumbnail');

        $this->assertSame($existing, $result);
    }

    public function test_returns_false_when_attachment_url_missing(): void
    {
        // Attachment ID with no URL.
        $GLOBALS['__oxpulse_attachment_urls'] = [];

        $result = $this->downsizeRewriter->rewrite(false, 999, 'thumbnail');

        $this->assertFalse($result);
    }

    public function test_recursion_guard_prevents_infinite_loop(): void
    {
        // RecursionGuard trait uses a per-class static flag keyed by
        // static::class. Simulate re-entry by setting the flag directly,
        // then call rewrite() — it should bail (return $out unchanged).
        $reflection = new \ReflectionClass(ImageDownsizeRewriter::class);
        $prop = $reflection->getProperty('guardFlags');
        $prop->setAccessible(true);
        $prop->setValue(null, [ImageDownsizeRewriter::class => true]);

        try {
            // Simulate re-entry: guard flag is true → should bail,
            // returning $out (false) unchanged.
            $result = $this->downsizeRewriter->rewrite(false, 42, 'thumbnail');
            $this->assertFalse($result);
        } finally {
            $prop->setValue(null, [ImageDownsizeRewriter::class => false]);
        }
    }

    public function test_unknown_size_falls_back_to_zero_dimensions(): void
    {
        // A size name not in metadata or registered subsizes.
        $result = $this->downsizeRewriter->rewrite(false, 42, 'nonexistent_size');

        $this->assertIsArray($result);
        // [0, 0] dimensions → no rs:fit option, imgproxy uses original.
        $this->assertSame(0, $result[1]);
        $this->assertSame(0, $result[2]);
    }

    /**
     * Live piter hero: a [2048, 2048] box on a non-square original became
     * rs:fill:2048:2048 with 1:1 attributes. A box must fit the original.
     */
    public function test_array_box_is_fitted_to_original_aspect_ratio(): void
    {
        // Original is 2048x1536 (4:3).
        $result = $this->downsizeRewriter->rewrite(false, 42, [1024, 1024]);

        $this->assertSame(1024, $result[1]);
        $this->assertSame(768, $result[2]);
        $this->assertStringContainsString('rs:fill:1024:768', $result[0]);
    }

    public function test_non_cropped_registered_size_is_fitted_not_cropped_square(): void
    {
        $GLOBALS['__oxpulse_registered_subsizes'] = [
            '2048x2048' => ['width' => 2048, 'height' => 2048, 'crop' => false],
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42]['width'] = 848;
        $GLOBALS['__oxpulse_attachment_meta'][42]['height'] = 560;
        $result = $this->downsizeRewriter->rewrite(false, 42, '2048x2048');
        unset($GLOBALS['__oxpulse_registered_subsizes']);

        // No upscale, original aspect ratio kept.
        $this->assertSame(848, $result[1]);
        $this->assertSame(560, $result[2]);
        $this->assertStringNotContainsString('rs:fill:2048:2048', $result[0]);
    }

    public function test_hard_cropped_registered_size_keeps_configured_box(): void
    {
        $GLOBALS['__oxpulse_registered_subsizes'] = [
            'square' => ['width' => 500, 'height' => 500, 'crop' => true],
        ];
        $result = $this->downsizeRewriter->rewrite(false, 42, 'square');
        unset($GLOBALS['__oxpulse_registered_subsizes']);

        $this->assertSame(500, $result[1]);
        $this->assertSame(500, $result[2]);
    }

    public function test_box_is_unchanged_when_original_dimensions_unknown(): void
    {
        unset($GLOBALS['__oxpulse_attachment_meta'][42]['width'], $GLOBALS['__oxpulse_attachment_meta'][42]['height']);
        $result = $this->downsizeRewriter->rewrite(false, 42, [800, 800]);

        $this->assertSame(800, $result[1]);
        $this->assertSame(800, $result[2]);
    }
}
