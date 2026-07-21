<?php
/**
 * AttachmentUrlRewriter tests.
 *
 * Verifies wp_get_attachment_url rewriting: image extension filtering,
 * allowed URL rewriting, non-image preservation, and fail-safe.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentUrlRewriter;
use PHPUnit\Framework\TestCase;

class AttachmentUrlRewriterTest extends TestCase
{
    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_empty_url_returned_unchanged(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $this->assertSame('', $rewriter->rewrite('', 1));
    }

    public function test_rewrites_jpg_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.jpg', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_rewrites_png_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.png', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_rewrites_webp_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.webp', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_rewrites_avif_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.avif', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_rewrites_uppercase_extension(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.JPG', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_preserves_pdf_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $url = 'https://example.com/wp-content/uploads/document.pdf';
        $result = $rewriter->rewrite($url, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_video_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $url = 'https://example.com/wp-content/uploads/video.mp4';
        $result = $rewriter->rewrite($url, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_url_without_extension(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $url = 'https://example.com/wp-content/uploads/noextension';
        $result = $rewriter->rewrite($url, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_non_allowed_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $url = 'https://evil.com/photo.jpg';
        $result = $rewriter->rewrite($url, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter(enabled: false));
        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result = $rewriter->rewrite($url, 1);

        $this->assertSame($url, $result);
    }

    public function test_rewrites_heic_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.heic', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }

    public function test_rewrites_tiff_url(): void
    {
        $rewriter = new AttachmentUrlRewriter($this->createRewriter());
        $result = $rewriter->rewrite('https://example.com/wp-content/uploads/photo.tiff', 1);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result);
    }
}
