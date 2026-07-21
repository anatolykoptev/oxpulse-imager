<?php
/**
 * Tests for IntermediateSizeRewriter.
 *
 * Verifies that the rewriter rebuilds the intermediate size URL using
 * the ORIGINAL attachment URL (from _wp_attached_file metadata) instead
 * of the rewritten imgproxy URL, preventing the path_join bug that
 * replaces the encoded source segment with the intermediate filename
 * basename (causing imgproxy 403 "Invalid signature").
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Integration\WordPress\Delivery\IntermediateSizeRewriter;
use PHPUnit\Framework\TestCase;

final class IntermediateSizeRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private IntermediateSizeRewriter $rewriter;

    protected function setUp(): void
    {
        parent::setUp();
        // AttachmentOriginResolver reads _wp_attached_file from post_meta
        // and builds the URL from wp_get_upload_dir()['baseurl'].
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/tmp/wp-content/uploads',
            'error'   => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2026/07/photo.webp'],
        ];

        $urlRewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
        $this->rewriter = new IntermediateSizeRewriter($urlRewriter);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_upload_dir'], $GLOBALS['__oxpulse_post_meta']);
    }

    public function test_rewrites_intermediate_size_url_correctly(): void
    {
        // Simulate WordPress core's image_get_intermediate_size output:
        // $data['file'] is the basename, $data['url'] is the BROKEN URL
        // built via path_join(dirname(rewritten_url), $file).
        $data = [
            'file'      => 'photo-330x220.webp',
            'width'     => 330,
            'height'    => 220,
            'mime-type' => 'image/webp',
            'path'      => '2026/07/photo-330x220.webp',
            'url'       => '/imgproxy/{sig}/rs:fill:330:220/photo-330x220.webp',
        ];

        $result = $this->rewriter->rewrite($data, 42, 'foxiz_crop_g1');

        $this->assertIsArray($result);
        // URL should be a proper imgproxy URL pointing at the INTERMEDIATE
        // file (with full uploads path), not the broken basename-only URL.
        $this->assertStringContainsString('imgproxy.example.com', $result['url']);
        $this->assertStringContainsString(
            'plain/https://example.com/wp-content/uploads/2026/07/photo-330x220.webp',
            $result['url']
        );
        // The broken basename-only URL must NOT appear.
        $this->assertStringNotContainsString('/photo-330x220.webp@', $result['url']);
        // URL must be absolute (start with the imgproxy endpoint host),
        // not a relative path like the broken /imgproxy/.../photo-330x220.webp.
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result['url']);
    }

    public function test_preserves_data_when_file_field_missing(): void
    {
        $data = ['width' => 100, 'height' => 100, 'url' => 'https://example.com/photo.jpg'];

        $result = $this->rewriter->rewrite($data, 42, 'thumbnail');

        $this->assertSame($data, $result);
    }

    public function test_preserves_data_when_post_meta_missing(): void
    {
        // Attachment ID with no _wp_attached_file metadata.
        $data = [
            'file'   => 'photo-330x220.webp',
            'width'  => 330,
            'height' => 220,
            'url'    => 'https://example.com/broken-url.webp',
        ];

        $result = $this->rewriter->rewrite($data, 999, 'foxiz_crop_g1');

        // resolveOriginalUrl returns null → data preserved unchanged.
        $this->assertSame('https://example.com/broken-url.webp', $result['url']);
    }

    public function test_recursion_guard_prevents_reentry(): void
    {
        $reflection = new \ReflectionClass(IntermediateSizeRewriter::class);
        $prop = $reflection->getProperty('guardFlags');
        $prop->setAccessible(true);
        $prop->setValue(null, [IntermediateSizeRewriter::class => true]);

        try {
            $data = [
                'file'   => 'photo-330x220.webp',
                'width'  => 330,
                'height' => 220,
                'url'    => 'https://example.com/original.webp',
            ];
            $result = $this->rewriter->rewrite($data, 42, 'foxiz_crop_g1');
            // Guard active → data returned unchanged.
            $this->assertSame('https://example.com/original.webp', $result['url']);
        } finally {
            $prop->setValue(null, [IntermediateSizeRewriter::class => false]);
        }
    }

    public function test_returns_false_input_unchanged(): void
    {
        // image_get_intermediate_size can return false when no intermediate
        // size matches. The rewriter should pass false through unchanged.
        $result = $this->rewriter->rewrite(false, 42, 'nonexistent');
        $this->assertFalse($result);
    }
}
