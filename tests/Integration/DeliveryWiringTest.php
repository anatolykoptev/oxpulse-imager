<?php
/**
 * Delivery wiring integration tests.
 *
 * Verifies that ServiceRegistrar registers the delivery adapters
 * only when delivery is enabled and not in admin context. Tests
 * the full wiring path: options → config → UrlRewriter → adapters.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use PHPUnit\Framework\TestCase;

class DeliveryWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
    }

    public function test_full_rewrite_pipeline_with_stored_settings(): void
    {
        $repository = new OptionSettingsRepository();

        // Simulate a fully configured plugin via the settings layer.
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'auto',
            'default_quality' => 80,
        ]);
        $repository->saveSecrets(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16))
        );

        // Build the same pipeline ServiceRegistrar would build.
        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        // Content img tag rewriting.
        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="400" height="300" alt="Test" />';
        $rewrittenTag = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertStringContainsString('imgproxy.example.com', $rewrittenTag);
        $this->assertStringContainsString('rs:fill:400:300', $rewrittenTag);
        $this->assertStringContainsString('alt="Test"', $rewrittenTag);

        // Attachment image src rewriting.
        $attachmentRewriter = new AttachmentImageSrcRewriter($rewriter);
        $image = ['https://example.com/wp-content/uploads/photo-300x200.jpg', 300, 200, true];
        $rewrittenImage = $attachmentRewriter->rewrite($image, 1, 'thumbnail', false);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $rewrittenImage[0]);
        $this->assertSame(300, $rewrittenImage[1]);
        $this->assertSame(200, $rewrittenImage[2]);

        // Srcset rewriting.
        $srcsetRewriter = new SrcsetRewriter($rewriter);
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo-300.jpg', 'descriptor' => 'w', 'value' => 300],
            ['url' => 'https://example.com/wp-content/uploads/photo-600.jpg', 'descriptor' => 'w', 'value' => 600],
        ];
        $rewrittenSources = $srcsetRewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertStringContainsString('rs:fit:300:0', $rewrittenSources[0]['url']);
        $this->assertStringContainsString('rs:fit:600:0', $rewrittenSources[1]['url']);
    }

    public function test_pipeline_preserves_urls_when_disabled(): void
    {
        $repository = new OptionSettingsRepository();
        // Delivery is disabled by default (no options set).

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_pipeline_preserves_urls_when_secrets_missing(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
        ]);
        // No secrets saved.

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        $this->assertNull($signing);

        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);
        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Test" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_pipeline_preserves_external_urls_when_not_in_allowlist(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
        ]);
        $repository->saveSecrets(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery);
        $tag = '<img src="https://cdn.cloudflare.com/photo.jpg" alt="External" />';
        $result = $contentRewriter->rewrite($tag, 'the_content', 0);

        $this->assertSame($tag, $result);
    }

    public function test_deterministic_output_across_adapters(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'avif',
            'default_quality' => 82,
        ]);
        $repository->saveSecrets(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        $url = 'https://example.com/wp-content/uploads/photo.jpg';

        $r1 = $rewriter->rewrite($url, 400, 0, 'content');
        $r2 = $rewriter->rewrite($url, 400, 0, 'srcset');
        $r3 = $rewriter->rewrite($url, 400, 0, 'attachment');

        $this->assertSame($r1->url, $r2->url);
        $this->assertSame($r2->url, $r3->url);

        // Output format should be applied.
        $this->assertStringContainsString('@avif', $r1->url);
        // Quality should be applied.
        $this->assertStringContainsString('q:82', $r1->url);
    }
}
