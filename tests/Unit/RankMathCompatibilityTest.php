<?php
/**
 * RankMathCompatibility tests.
 *
 * Verifies the og:image compatibility layer:
 * - restores direct URL when attachment ID is available
 * - falls back to base64url decode for local:// sources
 * - falls back to plain/ extraction for http sources
 * - clears URL on decode failure (lets RankMath skip gracefully)
 * - rejects path traversal in decoded paths
 * - rejects decoded paths outside /wp-content/
 * - no-op for non-imgproxy URLs (already direct)
 * - no-op for empty url
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Integration\WordPress\Compatibility\RankMathCompatibility;
use PHPUnit\Framework\TestCase;

class RankMathCompatibilityTest extends TestCase
{
    private RankMathCompatibility $compat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compat = new RankMathCompatibility();
        // Reset the attachment URL stub map.
        $GLOBALS['__oxpulse_attachment_urls'] = [];
        // Reset the filter registry so per-test add_filter calls don't leak.
        $GLOBALS['__oxpulse_filters'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_attachment_urls']);
        unset($GLOBALS['__oxpulse_filters']);
    }

    public function test_no_op_for_empty_url(): void
    {
        $result = $this->compat->restoreDirectUrl(['url' => '', 'id' => 0]);
        $this->assertSame('', $result['url']);
    }

    public function test_no_op_for_already_direct_url_with_extension(): void
    {
        $attachment = ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);
        $this->assertSame($attachment['url'], $result['url']);
    }

    public function test_no_op_for_direct_url_with_webp_extension(): void
    {
        $attachment = ['url' => 'https://example.com/wp-content/uploads/photo.webp', 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);
        $this->assertSame($attachment['url'], $result['url']);
    }

    public function test_restores_direct_url_when_attachment_id_available(): void
    {
        // Stub: wp_get_attachment_url(42) returns a direct URL.
        $GLOBALS['__oxpulse_attachment_urls'] = [
            42 => 'https://example.com/wp-content/uploads/2024/01/photo.jpg',
        ];

        // An imgproxy URL (no extension) with attachment ID.
        $imgproxyUrl = 'https://imgproxy.example.com/abc123/rs:fit:1200:630/local://XYZ';
        $attachment = ['url' => $imgproxyUrl, 'id' => 42];

        $result = $this->compat->restoreDirectUrl($attachment);

        $this->assertSame(
            'https://example.com/wp-content/uploads/2024/01/photo.jpg',
            $result['url']
        );
    }

    public function test_falls_back_to_base64url_decode_for_local_source(): void
    {
        // Build an imgproxy URL with a local:// source pointing to a
        // known filesystem path under /wp-content/.
        $fsPath = '/var/www/wordpress/wp-content/uploads/2024/01/photo.jpg';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // home_url stub returns https://example.test/{path}
        $this->assertSame(
            'https://example.test/wp-content/uploads/2024/01/photo.jpg',
            $result['url']
        );
    }

    public function test_falls_back_to_plain_extraction_for_http_source(): void
    {
        $sourceUrl = 'https://example.com/wp-content/uploads/photo.jpg';
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/plain/' . $sourceUrl . '@avif';

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // @avif suffix stripped, direct URL extracted.
        $this->assertSame($sourceUrl, $result['url']);
    }

    public function test_clears_url_on_decode_failure(): void
    {
        // An imgproxy-looking URL (no extension) with a garbage source
        // segment that won't decode to a valid path.
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://!!!invalid-base64!!!';
        $attachment = ['url' => $imgproxyUrl, 'id' => 0];

        $result = $this->compat->restoreDirectUrl($attachment);

        // Decode failed → URL cleared so RankMath skips gracefully.
        $this->assertSame('', $result['url']);
    }

    public function test_rejects_path_traversal_in_decoded_local_path(): void
    {
        // Build a local:// source with a path traversal segment.
        $fsPath = '/var/www/wordpress/wp-content/uploads/../../../etc/passwd';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // Path traversal detected → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_rejects_decoded_path_outside_wp_content(): void
    {
        // A local:// source that decodes to a path NOT under /wp-content/.
        $fsPath = '/etc/shadow';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // Path outside /wp-content/ → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_clears_url_when_attachment_id_lookup_fails(): void
    {
        // Attachment ID present but wp_get_attachment_url returns false.
        $GLOBALS['__oxpulse_attachment_urls'] = [];

        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://XYZnotdecodable';
        $attachment = ['url' => $imgproxyUrl, 'id' => 999];

        $result = $this->compat->restoreDirectUrl($attachment);

        // ID lookup failed + decode failed → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_register_adds_rankmath_filters(): void
    {
        $GLOBALS['__oxpulse_filters'] = [];
        $this->compat->register();

        $filterHooks = array_column($GLOBALS['__oxpulse_filters'], 'hook');
        $this->assertContains('rank_math/opengraph/facebook/image_array', $filterHooks);
        $this->assertContains('rank_math/opengraph/twitter/image_array', $filterHooks);
    }

    /**
     * WordPress filter callbacks must tolerate the actual value type
     * they receive — `apply_filters` carries any type. RankMath (or
     * another plugin earlier in the chain) can pass a non-array
     * (empty string, URL string, or false) to the image_array filter
     * when no image is resolved. Under strict_types=1 the `array`
     * param hint throws a TypeError → 500. The callback must pass the
     * non-array value through unchanged.
     */
    public function test_passes_empty_string_through_unchanged(): void
    {
        $result = $this->compat->restoreDirectUrl('');
        $this->assertSame('', $result);
    }

    public function test_passes_url_string_through_unchanged(): void
    {
        $url = 'https://piter.now/wp-content/uploads/2025/04/x.jpg';
        $result = $this->compat->restoreDirectUrl($url);
        $this->assertSame($url, $result);
    }

    public function test_passes_false_through_unchanged(): void
    {
        $result = $this->compat->restoreDirectUrl(false);
        $this->assertSame(false, $result);
    }

    // --- social-safe resolver tests (rewriter injection) ---
    // When the restored direct URL is NOT social-safe (.webp/.avif),
    // restoreDirectUrl routes it through the injected UrlRewriter's
    // rewriteSocialImage() to produce a .jpg-terminated URL. When the
    // rewriter is null or returns preserved, it degrades to the direct
    // URL (never breaks, never emits @jpeg or extensionless).

    private const WEBP_DIRECT = 'https://piter.now/wp-content/uploads/2026/07/photo.webp';
    private const SOCIAL_ALLOWED = 'https://piter.now/wp-content/uploads/';

    /**
     * Build a REAL UrlRewriter (final class — cannot be faked) wired to
     * a stub DeliveryBackend whose socialSafeUrl() returns a canned
     * .jpg URL (or null to simulate LocalBackend/http-source/passthrough).
     * When $throwOnSocial is true, socialSafeUrl() throws — used to
     * prove the rewriter path is NOT reached for social-safe inputs.
     */
    private function rewriterWithSocialUrl(?string $socialUrl, bool $throwOnSocial = false): UrlRewriter
    {
        $backend = new class($socialUrl, $throwOnSocial) implements DeliveryBackend {
            public function __construct(private ?string $socialUrl, private bool $throw) {}
            public function available(): bool { return true; }
            public function generate(TransformRequest $r, ?string $f = null): string
            {
                throw new \LogicException('generate must not be called by restoreDirectUrl');
            }
            public function socialSafeUrl(TransformRequest $r, ?string $f = null): ?string
            {
                if ($this->throw) {
                    throw new \LogicException('socialSafeUrl must not be called for a social-safe direct URL');
                }
                return $this->socialUrl;
            }
        };
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.test',
            allowedSources: [self::SOCIAL_ALLOWED],
        );
        return new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F'),
            null,
            $backend,
        );
    }

    public function test_webp_direct_url_with_rewriter_becomes_jpg_with_og_dimensions(): void
    {
        $rewriter = $this->rewriterWithSocialUrl('https://imgproxy.test/sig/rs:fill:1200:630/plain/photo.jpg');
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => self::WEBP_DIRECT, 'id' => 0, 'width' => 0, 'height' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertStringEndsWith('.jpg', $result['url']);
        $this->assertSame(1200, $result['width']);
        $this->assertSame(630, $result['height']);
    }

    public function test_jpg_direct_url_unchanged_and_rewriter_not_called(): void
    {
        // Invariant 2: jpg-origin og array byte-for-byte unchanged, zero
        // rewriter/backend calls. The stub backend THROWS if
        // socialSafeUrl is reached — proving the rewriter path is skipped.
        $rewriter = $this->rewriterWithSocialUrl(null, true);
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => 'https://piter.now/wp-content/uploads/2026/07/photo.jpg', 'id' => 0, 'width' => 999, 'height' => 999];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertSame('https://piter.now/wp-content/uploads/2026/07/photo.jpg', $result['url']);
        // Width/height untouched — no og-size rewrite for social-safe input.
        $this->assertSame(999, $result['width']);
        $this->assertSame(999, $result['height']);
    }

    public function test_png_direct_url_unchanged_and_rewriter_not_called(): void
    {
        $rewriter = $this->rewriterWithSocialUrl(null, true);
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => 'https://piter.now/wp-content/uploads/2026/07/photo.png', 'id' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertSame('https://piter.now/wp-content/uploads/2026/07/photo.png', $result['url']);
    }

    public function test_gif_direct_url_unchanged_and_rewriter_not_called(): void
    {
        $rewriter = $this->rewriterWithSocialUrl(null, true);
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => 'https://piter.now/wp-content/uploads/2026/07/photo.gif', 'id' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertSame('https://piter.now/wp-content/uploads/2026/07/photo.gif', $result['url']);
    }

    public function test_webp_direct_url_rewriter_returns_preserved_degrades_to_webp(): void
    {
        // Backend answers null (LocalBackend / http-source / passthrough)
        // → degrade to the webp direct URL. Never emit @jpeg or extensionless.
        $rewriter = $this->rewriterWithSocialUrl(null);
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => self::WEBP_DIRECT, 'id' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertSame(self::WEBP_DIRECT, $result['url']);
        $this->assertStringNotContainsString('@jpeg', $result['url']);
    }

    public function test_webp_direct_url_rewriter_null_degrades_to_webp(): void
    {
        // Null rewriter (default ctor) → today's behaviour: webp passes through.
        $compat = new RankMathCompatibility();

        $attachment = ['url' => self::WEBP_DIRECT, 'id' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertSame(self::WEBP_DIRECT, $result['url']);
    }

    public function test_twitter_filter_uses_same_resolver_as_facebook(): void
    {
        // Both rank_math/opengraph/{facebook,twitter}/image_array filter
        // hooks route through restoreDirectUrl. Verify the twitter hook
        // is registered and produces the same .jpg for a webp input.
        $rewriter = $this->rewriterWithSocialUrl('https://imgproxy.test/sig/rs:fill:1200:630/plain/photo.jpg');
        $compat = new RankMathCompatibility($rewriter);
        $compat->register();

        // Apply the twitter filter via the stub apply_filters.
        $twitterResult = apply_filters(
            'rank_math/opengraph/twitter/image_array',
            ['url' => self::WEBP_DIRECT, 'id' => 0, 'width' => 0, 'height' => 0]
        );
        $facebookResult = apply_filters(
            'rank_math/opengraph/facebook/image_array',
            ['url' => self::WEBP_DIRECT, 'id' => 0, 'width' => 0, 'height' => 0]
        );

        $this->assertStringEndsWith('.jpg', $twitterResult['url']);
        $this->assertSame($twitterResult['url'], $facebookResult['url']);
        $this->assertSame(1200, $twitterResult['width']);
        $this->assertSame(630, $twitterResult['height']);
    }

    public function test_og_size_filter_overrides_default_dimensions(): void
    {
        add_filter('oxpulse_og_image_size', static fn(): array => ['width' => 800, 'height' => 400]);
        $rewriter = $this->rewriterWithSocialUrl('https://imgproxy.test/sig/rs:fill:800:400/plain/photo.jpg');
        $compat = new RankMathCompatibility($rewriter);

        $attachment = ['url' => self::WEBP_DIRECT, 'id' => 0, 'width' => 0, 'height' => 0];
        $result = $compat->restoreDirectUrl($attachment);

        $this->assertStringEndsWith('.jpg', $result['url']);
        $this->assertSame(800, $result['width']);
        $this->assertSame(400, $result['height']);
    }

    /**
     * Invariant 1: every URL the resolver emits either ends social-safe
     * (jpe?g|png|gif) OR equals the input (degrade) — NEVER @jpeg or
     * extensionless. Covers the core case, degrade, and null-rewriter.
     */
    public function test_emitted_url_always_social_safe_or_equals_input(): void
    {
        $inputs = [
            'https://piter.now/wp-content/uploads/2026/07/photo.webp',
            'https://piter.now/wp-content/uploads/2026/07/photo.avif',
        ];

        // Core case: rewriter yields .jpg.
        $rewriter = $this->rewriterWithSocialUrl('https://imgproxy.test/sig/rs:fill:1200:630/plain/photo.jpg');
        $compat = new RankMathCompatibility($rewriter);
        foreach ($inputs as $url) {
            $result = $compat->restoreDirectUrl(['url' => $url, 'id' => 0]);
            $this->assertTrue(
                preg_match('#\.(jpe?g|png|gif)$#i', $result['url']) === 1 || $result['url'] === $url,
                "emitted URL must end social-safe or equal input: {$result['url']}"
            );
            $this->assertStringNotContainsString('@jpeg', $result['url']);
        }

        // Degrade: rewriter returns null.
        $degradeRewriter = $this->rewriterWithSocialUrl(null);
        $degradeCompat = new RankMathCompatibility($degradeRewriter);
        foreach ($inputs as $url) {
            $result = $degradeCompat->restoreDirectUrl(['url' => $url, 'id' => 0]);
            $this->assertSame($url, $result['url'], 'degrade must equal input');
            $this->assertStringNotContainsString('@jpeg', $result['url']);
        }

        // Null rewriter.
        $nullCompat = new RankMathCompatibility();
        foreach ($inputs as $url) {
            $result = $nullCompat->restoreDirectUrl(['url' => $url, 'id' => 0]);
            $this->assertSame($url, $result['url']);
        }
    }
}
