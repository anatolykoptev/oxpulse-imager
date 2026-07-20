<?php
/**
 * SrcsetRewriter tests.
 *
 * Verifies srcset array rewriting: width-based descriptors, 2x
 * descriptors, and preservation of non-allowed URLs.
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
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use PHPUnit\Framework\TestCase;

class SrcsetRewriterTest extends TestCase
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

    public function test_empty_sources_returned_unchanged(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $this->assertSame([], $rewriter->rewrite([], [800, 600], 'src', [], 0));
    }

    public function test_rewrites_width_descriptor_sources(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo-300.jpg', 'descriptor' => 'w', 'value' => 300],
            ['url' => 'https://example.com/wp-content/uploads/photo-600.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]['url']);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[1]['url']);
        // Width descriptor should produce a resize option.
        $this->assertStringContainsString('rs:fit:300:0', $result[0]['url']);
        $this->assertStringContainsString('rs:fit:600:0', $result[1]['url']);
    }

    public function test_preserves_non_allowed_urls(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://evil.com/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('https://evil.com/photo.jpg', $result[0]['url']);
    }

    public function test_2x_descriptor_does_not_produce_resize(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'x', 'value' => 2],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]['url']);
        $this->assertStringNotContainsString('rs:', $result[0]['url']);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter(enabled: false));
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $result[0]['url']);
    }

    public function test_preserves_descriptor_and_value_fields(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('w', $result[0]['descriptor']);
        $this->assertSame(300, $result[0]['value']);
    }

    public function test_skips_sources_missing_url_field(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['descriptor' => 'w', 'value' => 300], // no url
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        // First source should be unchanged (no url), second should be rewritten.
        $this->assertSame(['descriptor' => 'w', 'value' => 300], $result[0]);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[1]['url']);
    }
}
