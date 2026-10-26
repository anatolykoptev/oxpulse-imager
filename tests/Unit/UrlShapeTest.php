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
use OXPulse\Imager\Infrastructure\Imgproxy\CacheBusterCapabilityProbe;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentContentVersion;
use PHPUnit\Framework\TestCase;

final class UrlShapeTest extends TestCase
{
    private const SRC = 'https://example.com/wp-content/uploads/2026/07/photo.jpg';

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_post_meta'], $GLOBALS['__oxpulse_url_to_id'], $GLOBALS['__oxpulse_url_to_id_calls'], $GLOBALS['__oxpulse_attachment_meta'], $GLOBALS['__oxpulse_obj_cache'], $GLOBALS['__oxpulse_options']);
        parent::tearDown();
    }

    /** A gate whose probe verdict is 'ok' (the endpoint accepts cb:). */
    private function provenGate(): SocialJpegCapabilityCache
    {
        $gate = SocialJpegCapabilityCache::forCacheBuster();
        $gate->write('ok');
        return $gate;
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
            $versions,
            $this->provenGate()
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
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend, $this->resolver('abc123'), $this->provenGate());

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
        SocialJpegCapabilityCache::forCacheBuster()->write('ok');
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');

        $url = \OXPulse\Imager\Application\Delivery\UrlRewriterFactory::fromConfig($delivery, $signing)->rewrite(self::SRC, 800, 0, 'content')->url;

        $this->assertStringContainsString('/cb:lq1abc/', $url);
    }


    /**
     * Fail-safe: an endpoint that has not PROVEN it accepts `cb:` (unprobed,
     * 'no', stale) must get today's URLs — an allowlist without `cb` would
     * otherwise 4xx every image.
     */
    public function test_unproven_endpoint_gets_no_cb_even_with_a_version(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::SRC => 7];
        $GLOBALS['__oxpulse_post_meta'] = [7 => [AttachmentContentVersion::META_KEY => 'lq1abc']];
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $url = fn () => \OXPulse\Imager\Application\Delivery\UrlRewriterFactory::fromConfig($delivery, $signing)->rewrite(self::SRC, 800, 0, 'content')->url;

        $this->assertStringNotContainsString('cb:', $url(), 'never probed');

        SocialJpegCapabilityCache::forCacheBuster()->write('no');
        $this->assertStringNotContainsString('cb:', $url(), 'probe said no');
    }

    public function test_resolver_without_a_gate_is_fail_closed(): void
    {
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, new ImgproxyBackend($delivery, $signing), $this->resolver('abc123'));

        $this->assertStringNotContainsString('cb:', $rewriter->rewrite(self::SRC, 800, 0, 'content')->url);
    }

    private function requester(int $status, string $type, ?string $error = null): HttpRequester
    {
        return new class($status, $type, $error) implements HttpRequester {
            public ?string $lastUrl = null;
            public function __construct(private int $s, private string $t, private ?string $e) {}
            public function get(string $url): array { return ['status' => 0, 'body' => '', 'error' => 'n/a']; }
            public function head(string $url): array { return ['status' => 0, 'body' => '', 'error' => 'n/a']; }
            public function getImage(string $url): array
            {
                $this->lastUrl = $url;
                return ['status' => $this->s, 'content_type' => $this->t, 'error' => $this->e];
            }
        };
    }

    private function probeVerdictFor(HttpRequester $requester, ?string $source = self::SRC): bool
    {
        $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
        $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $cache = SocialJpegCapabilityCache::forCacheBuster();
        (new CacheBusterCapabilityProbe($delivery, $signing, $requester, $cache, static fn () => $source))->run();
        return $cache->readOk();
    }

    public function test_probe_ok_only_for_a_200_image_on_a_signed_cb_url(): void
    {
        $req = $this->requester(200, 'image/webp');
        $this->assertTrue($this->probeVerdictFor($req));
        $this->assertMatchesRegularExpression('#/cb:p[a-f0-9]{12}/#', $req->lastUrl, 'the probe must request a cb: URL carrying a nonce');
    }

    public function test_every_probe_url_is_unique_so_no_cache_can_answer_it(): void
    {
        $a = $this->requester(200, 'image/webp');
        $b = $this->requester(200, 'image/webp');
        $this->probeVerdictFor($a);
        $this->probeVerdictFor($b);
        $this->assertNotSame($a->lastUrl, $b->lastUrl);
    }

    /** Only 400/403 are definitive rejects of a signed URL with a good source. */
    public function test_probe_says_no_only_on_a_definitive_reject(): void
    {
        foreach ([400, 403] as $status) {
            $this->assertFalse($this->probeVerdictFor($this->requester($status, 'text/plain')), (string) $status);
            $this->assertSame('no', SocialJpegCapabilityCache::forCacheBuster()->readVerdict(), (string) $status);
            SocialJpegCapabilityCache::forCacheBuster()->clear();
        }
    }

    /**
     * A verdict flip changes every imgproxy URL and refills the nginx cache,
     * so a blip (timeout, 5xx during a restart, 429, 404, a proxy HTML page)
     * must keep the prior verdict — in BOTH directions.
     */
    public function test_transient_failures_keep_the_prior_verdict(): void
    {
        $blips = [
            [0, '', 'timeout'], [502, 'text/html', null], [503, 'text/html', null],
            [429, 'text/plain', null], [404, 'text/plain', null], [200, 'text/html', null],
        ];
        foreach (['ok', 'no'] as $prior) {
            foreach ($blips as [$status, $type, $error]) {
                SocialJpegCapabilityCache::forCacheBuster()->write($prior);
                $this->probeVerdictFor($this->requester($status, $type, $error));
                $this->assertSame($prior, SocialJpegCapabilityCache::forCacheBuster()->readVerdict(), "prior $prior, blip $status/$error");
            }
        }
    }

    public function test_probe_with_no_or_unauthorised_source_writes_nothing(): void
    {
        $this->assertFalse($this->probeVerdictFor($this->requester(200, 'image/webp'), null));
        $this->assertFalse($this->probeVerdictFor($this->requester(200, 'image/webp'), 'https://evil.example/x.jpg'));
        $this->assertNull(SocialJpegCapabilityCache::forCacheBuster()->readVerdict());
    }

    public function test_probe_prior_ok_is_downgraded_by_a_definitive_reject(): void
    {
        $this->assertTrue($this->probeVerdictFor($this->requester(200, 'image/webp')));
        $this->assertFalse($this->probeVerdictFor($this->requester(403, 'text/plain')));
    }

    /** A quiet site must not lose `ok` to age alone. */
    public function test_ok_verdict_does_not_expire_by_age(): void
    {
        $clock = 1_000_000;
        $cache = SocialJpegCapabilityCache::forCacheBuster(static function () use (&$clock): int { return $clock; });
        $cache->write('ok');
        $clock += 10 * 365 * 86400;
        $this->assertTrue($cache->readOk());
    }

    public function test_page_caches_are_purged_exactly_when_the_effective_state_changes(): void
    {
        $purges = 0;
        $run = function (HttpRequester $r) use (&$purges): void {
            $delivery = new DeliveryConfig(enabled: true, endpoint: 'https://imgproxy.example.com', allowedSources: ['https://example.com/wp-content/uploads/']);
            $signing = SigningConfig::fromHex('736563726574', '68656C6C6F');
            (new CacheBusterCapabilityProbe($delivery, $signing, $r, SocialJpegCapabilityCache::forCacheBuster(), static fn () => self::SRC, static function () use (&$purges): void { $purges++; }))->run();
        };

        $run($this->requester(200, 'image/webp'));   // never probed -> ok: URLs change
        $this->assertSame(1, $purges, 'no -> ok');
        $run($this->requester(200, 'image/webp'));   // ok -> ok
        $this->assertSame(1, $purges, 'unchanged verdict must not purge');
        $run($this->requester(503, 'text/html'));    // blip
        $this->assertSame(1, $purges, 'blip must not purge');
        $run($this->requester(403, 'text/plain'));   // ok -> no
        $this->assertSame(2, $purges, 'ok -> no');
        $run($this->requester(403, 'text/plain'));   // no -> no
        $this->assertSame(2, $purges);
    }

    public function test_cachebuster_verdict_does_not_touch_the_social_jpeg_verdict(): void
    {
        SocialJpegCapabilityCache::forCacheBuster()->write('ok');
        $this->assertFalse((new SocialJpegCapabilityCache())->readOk());
    }

    // --- size / scaled variants (core's attachment_url_to_postid is an exact match) ---

    private const DIR = 'https://example.com/wp-content/uploads/2026/07/';

    private function attachmentWithSizes(): void
    {
        $GLOBALS['__oxpulse_url_to_id'] = [self::DIR . 'photo.jpg' => 7, self::DIR . 'big-scaled.jpg' => 8];
        $GLOBALS['__oxpulse_post_meta'] = [
            7 => [AttachmentContentVersion::META_KEY => 'v7stamp'],
            8 => [AttachmentContentVersion::META_KEY => 'v8stamp'],
        ];
        $GLOBALS['__oxpulse_attachment_meta'] = [
            7 => ['sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg'], 'medium' => ['file' => 'photo-300x200.jpg']]],
            8 => ['original_image' => 'big.jpg', 'sizes' => ['medium' => ['file' => 'big-scaled-300x200.jpg']]],
        ];
    }

    public function test_size_variant_url_resolves_to_its_attachment(): void
    {
        $this->attachmentWithSizes();
        $r = new AttachmentContentVersion();

        $this->assertSame('v7stamp', $r->versionFor(self::DIR . 'photo-300x200.jpg'));
        $this->assertSame('v8stamp', $r->versionFor(self::DIR . 'big-scaled-300x200.jpg'), 'sized variant of a scaled image');
    }

    public function test_pre_scaling_original_url_resolves_to_the_scaled_attachment(): void
    {
        $this->attachmentWithSizes();

        $this->assertSame('v8stamp', (new AttachmentContentVersion())->versionFor(self::DIR . 'big.jpg'));
        $this->assertSame('v8stamp', (new AttachmentContentVersion())->versionFor(self::DIR . 'big-scaled.jpg'), 'exact scaled file');
    }

    /** `photo-100x100.jpg` is a real upload name, not a size of `photo.jpg`. */
    public function test_a_filename_that_merely_looks_like_a_size_does_not_resolve_to_another_attachment(): void
    {
        $this->attachmentWithSizes();

        $this->assertSame('', (new AttachmentContentVersion())->versionFor(self::DIR . 'photo-100x100.jpg'));
        // A plain file whose "-scaled" sibling exists but did not come from it.
        $this->assertSame('', (new AttachmentContentVersion())->versionFor(self::DIR . 'other.jpg'));
    }

    /** `big.jpg` is NOT the pre-scaling original of `big-scaled.jpg` unless its metadata says so. */
    public function test_scaled_sibling_with_a_different_original_is_not_matched(): void
    {
        $this->attachmentWithSizes();
        $GLOBALS['__oxpulse_attachment_meta'][8]['original_image'] = 'something-else.jpg';

        $this->assertSame('', (new AttachmentContentVersion())->versionFor(self::DIR . 'big.jpg'));
    }

    /** A persistent object cache (Redis) hands ids back as strings. */
    public function test_object_cache_hit_returned_as_a_string_is_honoured(): void
    {
        $this->attachmentWithSizes();
        $GLOBALS['__oxpulse_obj_cache']['oxpulse_imager']['id:' . md5(self::DIR . 'photo.jpg')] = '7';

        $this->assertSame('v7stamp', (new AttachmentContentVersion())->versionFor(self::DIR . 'photo.jpg'));
        $this->assertArrayNotHasKey('__oxpulse_url_to_id_calls', $GLOBALS, 'a cache hit must not query');
    }

    public function test_variant_lookup_costs_one_extra_query_and_is_memoised(): void
    {
        $this->attachmentWithSizes();
        $r = new AttachmentContentVersion();
        $r->versionFor(self::DIR . 'photo-300x200.jpg');
        $r->versionFor(self::DIR . 'photo-300x200.jpg');

        $this->assertSame(2, $GLOBALS['__oxpulse_url_to_id_calls'], 'exact + one stripped lookup, once');
    }

    // --- negative lookups ---

    public function test_a_non_attachment_miss_is_looked_up_once_across_rewriter_instances(): void
    {
        $url = 'https://example.com/wp-content/uploads/raw.jpg';

        $this->assertSame('', (new AttachmentContentVersion())->versionFor($url));
        $this->assertSame('', (new AttachmentContentVersion())->versionFor($url . '?x=1'));

        // First instance: exact + the one `-scaled` candidate; the second must add nothing.
        $this->assertSame(2, $GLOBALS['__oxpulse_url_to_id_calls'], 'second instance must hit the object-cache negative entry');
    }

    // --- srcset_dpr fn: ---

    public function test_dpr_candidates_have_no_fn_segment(): void
    {
        $this->assertStringNotContainsString('/fn:', $this->rewriter(null)->rewriteDpr(self::SRC, 400, 2.0)->url);
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
