<?php
/**
 * URL-shape tests: content version (#132), srcset fn: drop (#131),
 * social Content-Disposition extension (#118 nit 2).
 *
 * Drives the REAL ImgproxyBackend so the emitted URL is inspected, not a stub.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\ContentVersionResolver;
use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentContentVersion;
use PHPUnit\Framework\TestCase;

final class UrlShapeTest extends TestCase
{
    private const SRC = 'https://example.com/wp-content/uploads/2026/07/photo.jpg';

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_post_meta'], $GLOBALS['__oxpulse_url_to_id'], $GLOBALS['__oxpulse_url_to_id_calls'], $GLOBALS['__oxpulse_attachment_meta']);
        parent::tearDown();
    }

    private function resolver(string $version): ContentVersionResolver
    {
        return new class($version) implements ContentVersionResolver {
            public function __construct(private string $v) {}
            public function versionFor(string $sourceUrl): string
            {
                return $this->v;
            }
        };
    }

    private function rewriter(?ContentVersionResolver $versions, string $format = 'webp', bool $lqip = false): UrlRewriter
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
            outputFormat: $format,
            lqipEnabled: $lqip,
        );
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        return new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            $signing,
            null,
            new ImgproxyBackend($delivery, $signing),
            $versions
        );
    }

    // --- #132 content version ---

    public function test_version_enters_signed_path_as_cb_option(): void
    {
        $plain = $this->rewriter(null)->rewrite(self::SRC, 800, 0, 'content')->url;
        $versioned = $this->rewriter($this->resolver('abc123'))->rewrite(self::SRC, 800, 0, 'content')->url;

        $this->assertStringContainsString('/cb:abc123/', $versioned, 'version must be a cb: path option');
        $this->assertStringNotContainsString('cb:', $plain, 'no resolver -> today\'s URL');
        $this->assertNotSame(
            explode('/', $plain)[3] ?? '',
            explode('/', $versioned)[3] ?? '',
            'cb is inside the signed path, so the signature must change'
        );
        $this->assertStringNotContainsString('?', $versioned, 'a query string would be invisible to $uri-keyed caches');
    }

    public function test_version_changes_url_path_when_version_changes(): void
    {
        $a = $this->rewriter($this->resolver('v1'))->rewrite(self::SRC, 800, 0, 'srcset')->url;
        $b = $this->rewriter($this->resolver('v2'))->rewrite(self::SRC, 800, 0, 'srcset')->url;
        $this->assertNotSame($a, $b);
    }

    public function test_empty_version_leaves_url_byte_identical(): void
    {
        $this->assertSame(
            $this->rewriter(null)->rewrite(self::SRC, 800, 0, 'content')->url,
            $this->rewriter($this->resolver(''))->rewrite(self::SRC, 800, 0, 'content')->url
        );
    }

    public function test_format_variants_lqip_dpr_and_social_all_carry_the_version(): void
    {
        $r = $this->rewriter($this->resolver('abc123'), 'webp', true);

        $this->assertStringContainsString('/cb:abc123/', $r->rewriteFormat(self::SRC, 800, 0, 'avif')->url, 'picture <source>');
        $this->assertStringContainsString('/cb:abc123/', $r->rewriteLqip(self::SRC)->url, 'lqip');
        $this->assertStringContainsString('/cb:abc123/', $r->rewriteDpr(self::SRC, 400, 2.0)->url, 'dpr');
    }

    public function test_social_image_carries_the_version(): void
    {
        $seen = new \stdClass();
        $backend = new class($seen) implements DeliveryBackend {
            public function __construct(private \stdClass $seen) {}
            public function available(): bool { return true; }
            public function generate(TransformRequest $request, ?string $filename = null): string { return ''; }
            public function socialSafeUrl(TransformRequest $request, ?string $filename = null): ?string
            {
                $this->seen->version = $request->version;
                $this->seen->filename = $filename;
                return 'https://imgproxy.example.com/x.jpg';
            }
        };
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend, $this->resolver('abc123'));

        $rewriter->rewriteSocialImage(self::SRC, 1200, 630);

        $this->assertSame('abc123', $seen->version);
        // #118 nit 2: Save-As extension matches the URL's `.jpg`.
        $this->assertSame('photo.jpg', $seen->filename);
    }

    public function test_throwing_resolver_degrades_to_unversioned_url_not_a_broken_one(): void
    {
        $boom = new class implements ContentVersionResolver {
            public function versionFor(string $sourceUrl): string
            {
                throw new \RuntimeException('db down');
            }
        };
        $result = $this->rewriter($boom)->rewrite(self::SRC, 800, 0, 'content');

        $this->assertTrue($result->rewritten);
        $this->assertStringNotContainsString('cb:', $result->url);
    }

    public function test_local_backend_url_ignores_the_version(): void
    {
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $backend = new LocalBackend($signing);
        $base = new TransformRequest(sourceUrl: self::SRC, width: 800, height: 0, format: 'webp');
        $versioned = new TransformRequest(sourceUrl: self::SRC, width: 800, height: 0, format: 'webp', version: 'abc123');

        $this->assertSame($backend->generate($base), $backend->generate($versioned));
    }

    /**
     * Production wiring: every fromConfig() caller (frontend, prewarm, CLI)
     * must get the attachment resolver by default, or prewarm warms
     * unversioned keys nobody requests.
     */
    public function test_factory_default_wires_the_attachment_resolver(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::SRC => 7];
        $GLOBALS['__oxpulse_post_meta'] = [7 => [AttachmentContentVersion::META_KEY => 'lq1abc']];
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');

        $url = \OXPulse\Imager\Application\Delivery\UrlRewriterFactory::fromConfig($delivery, $signing)->rewrite(self::SRC, 800, 0, 'content')->url;

        $this->assertStringContainsString('/cb:lq1abc/', $url);
    }

    public function test_transform_request_rejects_unsafe_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransformRequest(sourceUrl: self::SRC, width: 1, height: 0, version: 'a/b');
    }

    // --- AttachmentContentVersion ---

    public function test_resolver_prefers_stamp_and_ignores_query_string(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::SRC => 7];
        $GLOBALS['__oxpulse_post_meta'] = [7 => [AttachmentContentVersion::META_KEY => 'lq1abc']];

        $this->assertSame('lq1abc', (new AttachmentContentVersion())->versionFor(self::SRC . '?t=1700000000'));
    }

    public function test_resolver_falls_back_to_metadata_checksum_for_unstamped_attachment(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::SRC => 7];
        $GLOBALS['__oxpulse_attachment_meta'] = [7 => ['filesize' => 1000, 'width' => 10, 'height' => 20]];
        $a = (new AttachmentContentVersion())->versionFor(self::SRC);

        $GLOBALS['__oxpulse_attachment_meta'] = [7 => ['filesize' => 1001, 'width' => 10, 'height' => 20]];
        $b = (new AttachmentContentVersion())->versionFor(self::SRC);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{1,8}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_resolver_returns_empty_for_non_attachment_url(): void
    {
        $this->assertSame('', (new AttachmentContentVersion())->versionFor('https://example.com/wp-content/uploads/raw.jpg'));
    }

    public function test_resolver_memoises_lookups_within_a_request(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::SRC => 7];
        $GLOBALS['__oxpulse_post_meta'] = [7 => [AttachmentContentVersion::META_KEY => 'lq1abc']];
        $r = new AttachmentContentVersion();
        $r->versionFor(self::SRC);
        $r->versionFor(self::SRC);
        $r->versionFor(self::SRC . '?t=5');

        $this->assertSame(1, $GLOBALS['__oxpulse_url_to_id_calls']);
    }

    public function test_stamp_writes_a_short_base36_token_and_returns_metadata_untouched(): void
    {
        $meta = ['file' => 'a.jpg'];
        $out = AttachmentContentVersion::stamp($meta, 7);

        $this->assertSame($meta, $out);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6,8}$/', $GLOBALS['__oxpulse_post_meta'][7][AttachmentContentVersion::META_KEY]);
    }

    /**
     * The stamp hook must be live with delivery DISABLED (the
     * state during an ASE replacement on a site whose delivery is off or
     * health-gated). Drives the real registration path.
     */
    public function test_registered_filter_stamps_attachment_with_delivery_disabled(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;

        $ref = new \ReflectionClass(\OXPulse\Imager\Plugin::class);
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::register($ref->newInstanceWithoutConstructor());

        $meta = ['file' => 'a.jpg'];
        $out = apply_filters('wp_update_attachment_metadata', $meta, 7);

        $this->assertSame($meta, $out);
        $this->assertArrayHasKey(
            AttachmentContentVersion::META_KEY,
            $GLOBALS['__oxpulse_post_meta'][7] ?? [],
            'wp_update_attachment_metadata must stamp the attachment even when delivery is disabled'
        );
        unset($GLOBALS['__oxpulse_options'], $GLOBALS['__oxpulse_actions'], $GLOBALS['__oxpulse_filters'], $GLOBALS['__oxpulse_scheduled_events'], $GLOBALS['__oxpulse_is_admin']);
    }

    // --- #131 srcset fn: ---

    public function test_srcset_candidates_have_no_fn_segment_but_other_contexts_keep_it(): void
    {
        $r = $this->rewriter(null);

        $this->assertStringNotContainsString('/fn:', $r->rewrite(self::SRC, 800, 0, 'srcset')->url);
        foreach (['content', 'picture', 'attachment'] as $ctx) {
            $this->assertStringContainsString('/fn:', $r->rewrite(self::SRC, 800, 0, $ctx)->url, $ctx);
        }
    }
}
