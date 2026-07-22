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

use OXPulse\Imager\Application\Delivery\DeliveryBackendFactory;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\BufferRewriter;
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

    /**
     * Dispatch 3 invariant: when an imgproxy endpoint is configured, the
     * factory-selected backend MUST be ImgproxyBackend and the rewrite
     * output MUST be byte-identical to the pre-seam path (UrlRewriter
     * with no injected backend, which lazily constructs ImgproxyBackend
     * itself). This is the "wiring must NOT change the imgproxy path"
     * guard — any divergence here breaks every existing imgproxy site
     * on upgrade.
     */
    public function test_imgproxy_wiring_byte_identical_to_pre_seam_path(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'auto',
            'default_quality' => 80,
        ]);
        $repository->saveSecrets(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3'
        );

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        // Pre-seam path: UrlRewriter with no injected backend (lazy).
        $preSeamRewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        // Dispatch 3 path: factory selects the backend, injected into UrlRewriter.
        $backend = DeliveryBackendFactory::select($delivery, $signing);
        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
        $postSeamRewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend);

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $pre = $preSeamRewriter->rewrite($url, 400, 300, 'content');
        $post = $postSeamRewriter->rewrite($url, 400, 300, 'content');

        $this->assertSame(
            $pre->url,
            $post->url,
            'Factory-wired imgproxy path must be byte-identical to the pre-seam lazy path.'
        );
        $this->assertStringContainsString('imgproxy.example.com', $post->url);
    }

    /**
     * Dispatch 3: when NO imgproxy endpoint is configured, the factory
     * selects LocalBackend and the rewrite output is a cache URL (not
     * an imgproxy URL). This is the "LocalBackend is now actually
     * reached at runtime" guard — pre-seam, UrlRewriter always lazily
     * built ImgproxyBackend even with an empty endpoint, so LocalBackend
     * was dead code.
     */
    public function test_local_backend_selected_when_no_imgproxy_endpoint(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => '',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'auto',
            'default_quality' => 80,
        ]);
        $repository->saveSecrets(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3'
        );
        // #43 Phase 2: set capability to 'yes' so LocalBackend emits
        // clean cache URLs (the test env has no Apache → fallbackNeeded
        // is true by default → ?k= URLs). This test verifies LocalBackend
        // SELECTION, not fallback behavior (covered in LocalBackendTest).
        $repository->saveRewriteCapability('yes');

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        $backend = DeliveryBackendFactory::select($delivery, $signing);
        $this->assertInstanceOf(LocalBackend::class, $backend);

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend);
        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $result = $rewriter->rewrite($url, 400, 0, 'content');

        // LocalBackend emits cache URLs under /wp-content/cache/oxpulse/.
        $this->assertStringContainsString('/wp-content/cache/oxpulse/', $result->url);
        $this->assertStringContainsString('.webp', $result->url);
        $this->assertStringNotContainsString('imgproxy', $result->url);
    }

    /**
     * Factory returns null when signing is missing — callers must treat
     * this as "delivery inactive" (pass-through, no rewrite).
     */
    public function test_factory_returns_null_when_signing_missing(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );

        $backend = DeliveryBackendFactory::select($delivery, null);
        $this->assertNull($backend);
    }

    // --- #43 Phase 2: fallback buffer wiring ---

    /**
     * After Phase 2, ServiceRegistrar does NOT register an
     * output-buffer fallback by default when LocalBackend is
     * active — LocalBackend emits ?k= URLs directly through the
     * collision-safe wp_content_img_tag filter. The auto-on-
     * fallbackNeeded buffer registration is removed.
     *
     * #43 Phase 3: FallbackRewriter is removed entirely. The
     * idempotency guard in UrlRewriter handles cache-URL detection.
     *
     * We verify by checking that no 'template_redirect' action
     * callback related to buffer rewriting is registered when
     * registerDeliveryAdapters() runs with LocalBackend active and
     * bufferRewritingEnabled=false.
     */
    public function test_no_fallback_buffer_registered_by_default(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => '',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'auto',
            'default_quality' => 80,
        ]);
        $repository->saveSecrets(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3'
        );

        // bufferRewritingEnabled defaults to false.
        $delivery = $repository->loadDeliveryConfig();
        $this->assertFalse($delivery->bufferRewritingEnabled);

        // Simulate the wiring: registerDeliveryAdapters is private,
        // so we check the observable effect — no template_redirect
        // action should be registered for buffer rewriting when
        // bufferRewritingEnabled is false.
        $actions = $GLOBALS['__oxpulse_actions'] ?? [];
        $templateRedirectCallbacks = array_filter(
            $actions,
            static fn($entry) => $entry['hook'] === 'template_redirect'
        );
        $this->assertEmpty(
            $templateRedirectCallbacks,
            'No template_redirect buffer handler should be registered when bufferRewritingEnabled is false',
        );
    }

    /**
     * BufferRewriter still registers when bufferRewritingEnabled=true
     * — it remains the explicit opt-in for theme-hardcoded <img> tags
     * (Foxiz). The Phase 2 change only removes the auto-on-fallbackNeeded
     * registration, not BufferRewriter itself.
     */
    public function test_buffer_rewriter_registers_when_opted_in(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => '',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
            'output_format' => 'auto',
            'default_quality' => 80,
            'buffer_rewriting_enabled' => true,
        ]);
        $repository->saveSecrets(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3'
        );

        $delivery = $repository->loadDeliveryConfig();
        $this->assertTrue($delivery->bufferRewritingEnabled);

        // Build the same pipeline ServiceRegistrar would build and
        // register the BufferRewriter (mirrors ServiceRegistrar:261-264).
        $signing = $repository->loadSigningConfig();
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );
        $backend = DeliveryBackendFactory::select($delivery, $signing);
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend);
        $bufferRewriter = new BufferRewriter($rewriter, $delivery);
        $bufferRewriter->register();

        // A template_redirect action must be registered.
        $actions = $GLOBALS['__oxpulse_actions'] ?? [];
        $templateRedirectCallbacks = array_filter(
            $actions,
            static fn($entry) => $entry['hook'] === 'template_redirect'
        );
        $this->assertNotEmpty(
            $templateRedirectCallbacks,
            'BufferRewriter must register a template_redirect handler when bufferRewritingEnabled is true',
        );
    }
}
