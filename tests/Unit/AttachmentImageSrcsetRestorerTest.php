<?php
/**
 * AttachmentImageSrcsetRestorer tests.
 *
 * Verifies that wp_get_attachment_image() yields a correct srcset +
 * sizes with proxied candidates when the src has been proxied by
 * ImageDownsizeRewriter (which breaks core's wp_calculate_image_srcset
 * basename matching).
 *
 * The test drives the production filter chain: registers SrcsetRewriter
 * on wp_calculate_image_srcset and AttachmentImageSrcsetRestorer on
 * wp_get_attachment_image_attributes, then fires the filter and asserts
 * the result carries >= 2 proxied candidates at distinct widths plus a
 * sizes attribute.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcsetRestorer;
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use PHPUnit\Framework\TestCase;

class AttachmentImageSrcsetRestorerTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the filter registry so each test starts clean.
        $GLOBALS['__oxpulse_filters'] = [];

        // Attachment metadata: a 1920x1280 image with 4 intermediate
        // sizes, all sharing the same 3:2 aspect ratio so core's ratio
        // matching (replicated in the bootstrap stub) includes them all.
        $GLOBALS['__oxpulse_attachment_meta'] = [
            57942 => [
                'width'  => 1920,
                'height' => 1280,
                'file'   => '2026/07/photo.jpg',
                'sizes'  => [
                    'thumbnail' => ['width' => 150, 'height' => 100, 'file' => 'photo-150x100.jpg'],
                    'medium'    => ['width' => 300, 'height' => 200, 'file' => 'photo-300x200.jpg'],
                    'large'     => ['width' => 1024, 'height' => 683, 'file' => 'photo-1024x683.jpg'],
                    'foxiz_g1'  => ['width' => 330, 'height' => 220, 'file' => 'photo-330x220.jpg'],
                ],
            ],
        ];

        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/tmp/wp-content/uploads',
            'error'   => false,
        ];

        // AttachmentOriginResolver reads _wp_attached_file from post_meta.
        $GLOBALS['__oxpulse_post_meta'] = [
            57942 => ['_wp_attached_file' => '2026/07/photo.jpg'],
        ];

        // Build the production pipeline: UrlRewriter → SrcsetRewriter
        // (hooks wp_calculate_image_srcset) + AttachmentImageSrcsetRestorer
        // (hooks wp_get_attachment_image_attributes).
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        $srcsetRewriter = new SrcsetRewriter($rewriter);
        add_filter('wp_calculate_image_srcset', [$srcsetRewriter, 'rewrite'], 10, 5);

        $this->restorer = new AttachmentImageSrcsetRestorer();
        add_filter('wp_get_attachment_image_attributes', [$this->restorer, 'restore'], 99, 3);
    }

    private AttachmentImageSrcsetRestorer $restorer;

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_attachment_meta'],
            $GLOBALS['__oxpulse_upload_dir'],
            $GLOBALS['__oxpulse_post_meta']
        );
    }

    /**
     * Simulates wp_get_attachment_image() after ImageDownsizeRewriter
     * has proxied the src: the $attr array has a proxied src but NO
     * srcset/sizes (core returned false because the proxied basename
     * doesn't match any metadata size file).
     */
    private function buildProxiedAttr(): array
    {
        // The proxied src that ImageDownsizeRewriter would produce
        // for size=foxiz_g1 (330x220). This is an imgproxy URL whose
        // basename is a base64-encoded source — core can't match it.
        $proxiedSrc = 'https://imgproxy.example.com/AaBBccDD/rs:fill:330:220/bG9jYWwLy93cC1jb250ZW50L3VwbG9hZHMvMjAyNi8wNy9waG90by5qcGc';

        return [
            'src'    => $proxiedSrc,
            'class'  => 'attachment-foxiz_g1 size-foxiz_g1',
            'alt'    => 'Test image',
            'width'  => 330,
            'height' => 220,
        ];
    }

    public function test_restores_srcset_with_proxied_candidates_at_distinct_widths(): void
    {
        $attr = $this->buildProxiedAttr();
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        // srcset must be present.
        $this->assertArrayHasKey('srcset', $result);
        $this->assertNotEmpty($result['srcset']);

        // Parse the srcset string and verify >= 2 candidates at
        // distinct widths, all proxied.
        $candidates = explode(', ', $result['srcset']);
        $this->assertGreaterThanOrEqual(2, count($candidates));

        $widths = [];
        foreach ($candidates as $candidate) {
            $parts = explode(' ', trim($candidate), 2);
            $url = $parts[0];
            $descriptor = $parts[1] ?? '';

            // Every candidate URL must be proxied (imgproxy endpoint).
            $this->assertStringContainsString(
                'imgproxy.example.com',
                $url,
                "Candidate URL not proxied: $url"
            );

            // Extract width from "Nw" descriptor.
            if (preg_match('/(\d+)w/', $descriptor, $m)) {
                $widths[] = (int) $m[1];
            }
        }

        // Distinct widths (no duplicates).
        $this->assertSame(count($widths), count(array_unique($widths)), 'Duplicate widths in srcset');
        $this->assertGreaterThanOrEqual(2, count($widths), 'Need >= 2 distinct widths');
    }

    public function test_restores_sizes_attribute(): void
    {
        $attr = $this->buildProxiedAttr();
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        $this->assertArrayHasKey('sizes', $result);
        $this->assertNotEmpty($result['sizes']);
        // sizes should reference the width (330px).
        $this->assertStringContainsString('330', $result['sizes']);
    }

    public function test_does_not_override_existing_srcset(): void
    {
        // When core already built a srcset (e.g. delivery disabled,
        // src not proxied), the restorer must not override it.
        $attr = $this->buildProxiedAttr();
        $attr['srcset'] = 'https://example.com/existing-300w.jpg 300w, https://example.com/existing-600w.jpg 600w';
        $attr['sizes'] = '(max-width: 330px) 100vw, 330px';

        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        // Existing srcset/sizes must be preserved unchanged.
        $this->assertSame(
            'https://example.com/existing-300w.jpg 300w, https://example.com/existing-600w.jpg 600w',
            $result['srcset']
        );
    }

    public function test_no_action_when_attachment_has_no_intermediate_sizes(): void
    {
        // An attachment with no intermediate sizes → only the full-size
        // candidate → < 2 sources → no srcset.
        $GLOBALS['__oxpulse_attachment_meta'] = [
            100 => [
                'width'  => 1920,
                'height' => 1280,
                'file'   => '2026/07/single.jpg',
                'sizes'  => [],
            ],
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            100 => ['_wp_attached_file' => '2026/07/single.jpg'],
        ];

        $attr = [
            'src'    => 'https://imgproxy.example.com/AaBB/rs:fill:1920:1280/bG9jYWw',
            'width'  => 1920,
            'height' => 1280,
        ];
        $attachment = new \stdClass();
        $attachment->ID = 100;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'full'
        );

        $this->assertArrayNotHasKey('srcset', $result);
    }

    /**
     * #127 Part 1 — a lazy-loaded image restored by the restorer must
     * get the 'auto, ' prefix on its sizes attribute, replicating what
     * core would have done in wp_get_attachment_image() (media.php
     * lines 1146-1157) if the srcset/sizes step had not been skipped.
     */
    public function test_lazy_loaded_image_gets_auto_sizes_prefix(): void
    {
        $attr = $this->buildProxiedAttr();
        $attr['loading'] = 'lazy';
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        $this->assertArrayHasKey('sizes', $result);
        $this->assertStringStartsWith('auto, ', $result['sizes']);
    }

    /**
     * #127 Part 1 — a non-lazy image must NOT get the auto prefix.
     * Core's own condition requires 'lazy' === $attr['loading'].
     */
    public function test_non_lazy_image_does_not_get_auto_prefix(): void
    {
        $attr = $this->buildProxiedAttr();
        // No 'loading' attribute (eager is the default).
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        $this->assertArrayHasKey('sizes', $result);
        $this->assertFalse(
            str_starts_with($result['sizes'], 'auto'),
            'sizes must not start with auto for a non-lazy image'
        );
    }

    /**
     * #127 Part 2 — when $content_width is set and smaller than the
     * image width, the sizes ceiling must be the content width, not
     * the image width. Core's wp_calculate_image_sizes() derives the
     * ceiling from the image width, which over-declares for images in
     * a constrained column.
     */
    public function test_content_width_clamps_sizes_ceiling(): void
    {
        $GLOBALS['content_width'] = 300;

        $attr = $this->buildProxiedAttr();
        // Image width is 330 (from buildProxiedAttr). content_width=300
        // is smaller → ceiling must clamp to 300.
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        unset($GLOBALS['content_width']);

        $this->assertArrayHasKey('sizes', $result);
        // Strip a possible 'auto, ' prefix before checking the ceiling.
        $sizes = $result['sizes'];
        if (str_starts_with($sizes, 'auto, ')) {
            $sizes = substr($sizes, 6);
        }
        // The ceiling (the number after the comma) must be 300, not 330.
        $this->assertStringContainsString('(max-width: 300px) 100vw, 300px', $sizes);
        $this->assertStringNotContainsString('330', $sizes);
    }

    /**
     * #127 Part 2 — the oxpulse_image_sizes filter lets an operator
     * supply a precise sizes string, overriding the computed default.
     */
    public function test_oxpulse_image_sizes_filter_overrides_sizes(): void
    {
        $custom = '(max-width: 700px) 85vw, 700px';
        add_filter('oxpulse_image_sizes', static function () use ($custom): string {
            return $custom;
        }, 10, 1);

        $attr = $this->buildProxiedAttr();
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        $this->assertSame($custom, $result['sizes']);
    }

    /**
     * #127 falsification gate — the sizes string (after stripping any
     * 'auto, ' prefix) must remain a valid media-query list in all
     * cases: lazy, non-lazy, and content_width-clamped.
     *
     * @dataProvider valid_sizes_provider
     */
    public function test_sizes_remains_valid_media_query_list(array $attrOverrides, ?int $contentWidth): void
    {
        if ($contentWidth !== null) {
            $GLOBALS['content_width'] = $contentWidth;
        }

        $attr = array_merge($this->buildProxiedAttr(), $attrOverrides);
        $attachment = new \stdClass();
        $attachment->ID = 57942;

        $result = apply_filters(
            'wp_get_attachment_image_attributes',
            $attr,
            $attachment,
            'foxiz_g1'
        );

        unset($GLOBALS['content_width']);

        $this->assertArrayHasKey('sizes', $result);
        $sizes = $result['sizes'];
        if (str_starts_with($sizes, 'auto, ')) {
            $sizes = substr($sizes, 6);
        }
        $this->assertMatchesRegularExpression(
            '/^\(max-width: \d+px\) 100vw, \d+px$/',
            $sizes,
            "sizes is not a valid media-query list: $sizes"
        );
    }

    public static function valid_sizes_provider(): array
    {
        return [
            'lazy'         => [['loading' => 'lazy'], null],
            'non-lazy'     => [[], null],
            'clamped'      => [['loading' => 'lazy'], 300],
        ];
    }
}
