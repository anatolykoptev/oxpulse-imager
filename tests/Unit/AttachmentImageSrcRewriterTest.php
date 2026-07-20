<?php
/**
 * AttachmentImageSrcRewriter tests.
 *
 * Verifies attachment image src rewriting: [url, w, h, is_intermediate]
 * array handling, dimension preservation, and fail-safe behavior.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcRewriter;
use PHPUnit\Framework\TestCase;

class AttachmentImageSrcRewriterTest extends TestCase
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

    public function test_false_input_returned_unchanged(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter());
        $this->assertFalse($rewriter->rewrite(false, 1, 'thumbnail', false));
    }

    public function test_rewrites_url_preserving_dimensions(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter());
        $image = [
            'https://example.com/wp-content/uploads/photo-300x200.jpg',
            300,
            200,
            true,
        ];

        $result = $rewriter->rewrite($image, 1, 'thumbnail', false);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]);
        $this->assertStringContainsString('rs:fit:300:200', $result[0]);
        // Dimensions preserved.
        $this->assertSame(300, $result[1]);
        $this->assertSame(200, $result[2]);
        $this->assertTrue($result[3]);
    }

    public function test_preserves_non_allowed_url(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter());
        $image = [
            'https://evil.com/photo.jpg',
            300,
            200,
            true,
        ];

        $result = $rewriter->rewrite($image, 1, 'thumbnail', false);

        $this->assertSame('https://evil.com/photo.jpg', $result[0]);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter(enabled: false));
        $image = [
            'https://example.com/wp-content/uploads/photo.jpg',
            300,
            200,
            true,
        ];

        $result = $rewriter->rewrite($image, 1, 'thumbnail', false);

        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $result[0]);
    }

    public function test_handles_array_missing_indices(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter());
        $image = ['https://example.com/wp-content/uploads/photo.jpg'];

        $result = $rewriter->rewrite($image, 1, 'full', false);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]);
    }

    public function test_non_array_input_returned_unchanged(): void
    {
        $rewriter = new AttachmentImageSrcRewriter($this->createRewriter());
        $this->assertSame(null, $rewriter->rewrite(null, 1, 'thumbnail', false));
    }
}
