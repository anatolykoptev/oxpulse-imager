<?php
/**
 * BufferRewriter tests.
 *
 * Verifies ob_start buffer rewriting for theme-hardcoded <img> tags:
 * - rewrites src and data-src attributes pointing at /wp-content/
 * - preserves URLs not under /wp-content/ (external images)
 * - preserves URLs with non-image extensions
 * - skips buffers > 2MB and buffers without <img
 * - handles single-quoted attributes
 * - catastrophic backtracking regression test (malformed 4KB <img tag)
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\PictureElementWrapper;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Integration\WordPress\Delivery\BufferRewriter;
use OXPulse\Imager\Tests\Unit\Stubs\PictureTestBackend;
use PHPUnit\Framework\TestCase;

class BufferRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        // FIX 3: BufferRewriter now directly checks isPro() as a
        // belt-and-suspenders gate alongside the filter. Default isPro()
        // is false (no Freemius, no grandfather, no oxpulse_is_pro filter)
        // → picture wrapping would be disabled. Tests that exercise
        // picture wrapping set oxpulse_is_pro=true individually.
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_filters'], $GLOBALS['__oxpulse_options']);
        parent::tearDown();
    }

    private function createDeliveryConfig(bool $enabled = true): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: $enabled,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [self::ALLOWED],
            bufferRewritingEnabled: true,
        );
    }

    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            $this->createDeliveryConfig($enabled),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    private function createBufferRewriter(bool $enabled = true): BufferRewriter
    {
        return new BufferRewriter(
            $this->createRewriter($enabled),
            $this->createDeliveryConfig($enabled)
        );
    }

    /**
     * #43 Phase 3 — wrap a fragment in <html> so the content-type guard
     * (which sniffs for <html when no Content-Type header is set, as in
     * the CLI test environment) lets the buffer through to the regex.
     */
    private function wrap(string $fragment): string
    {
        return '<html><body>' . $fragment . '</body></html>';
    }

    public function test_empty_buffer_returned_unchanged(): void
    {
        $rewriter = $this->createBufferRewriter();
        $this->assertSame('', $rewriter->rewrite(''));
    }

    public function test_buffer_without_img_returned_unchanged(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = '<html><body><p>no images here</p></body></html>';
        $this->assertSame($html, $rewriter->rewrite($html));
    }

    public function test_rewrites_src_attribute_for_wp_content_image(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/2024/01/photo.jpg" alt="test">');
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('plain/https://example.com/wp-content/uploads/2024/01/photo.jpg', $result);
    }

    public function test_rewrites_data_src_attribute_for_lazy_load(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img data-src="https://example.com/wp-content/uploads/photo.jpg" src="placeholder.gif" alt="lazy">');
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        // data-src should be rewritten; placeholder.gif (no /wp-content/) preserved
        $this->assertStringContainsString('placeholder.gif', $result);
    }

    public function test_preserves_external_images_not_in_wp_content(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://cdn.example.com/photo.jpg" alt="external">');
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    public function test_preserves_non_image_extensions_in_wp_content(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/document.pdf" alt="doc">');
        $result = $rewriter->rewrite($html);

        $this->assertSame($html, $result);
    }

    public function test_handles_single_quoted_attributes(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap("<img src='https://example.com/wp-content/uploads/photo.jpg' alt='test'>");
        $result = $rewriter->rewrite($html);

        $this->assertNotSame($html, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_extracts_width_height_for_better_resize(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" width="800" height="600" alt="test">');
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('rs:fill:800:600', $result);
    }

    public function test_rewrites_multiple_img_tags_in_one_buffer(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<div>'
            . '<img src="https://example.com/wp-content/uploads/a.jpg" alt="a">'
            . '<img src="https://example.com/wp-content/uploads/b.png" alt="b">'
            . '</div>');
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('imgproxy.example.com', $result);
        // Both images rewritten — count occurrences of imgproxy in result
        $this->assertSame(2, substr_count($result, 'imgproxy.example.com'));
    }

    public function test_preserves_disallowed_wp_content_url(): void
    {
        // URL under /wp-content/ but not in the allowed sources allowlist.
        $rewriter = new BufferRewriter(
            new UrlRewriter(
                new SourcePolicy(),
                new DeliveryConfig(
                    enabled: true,
                    endpoint: 'https://imgproxy.example.com',
                    allowedSources: ['https://other.com/wp-content/uploads/'],
                    bufferRewritingEnabled: true,
                ),
                SigningConfig::fromHex('736563726574', '68656C6C6F')
            ),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://other.com/wp-content/uploads/'],
                bufferRewritingEnabled: true,
            )
        );

        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $result = $rewriter->rewrite($html);

        // Source not in allowlist → preserved.
        $this->assertSame($html, $result);
    }

    public function test_skips_buffer_over_2mb(): void
    {
        $rewriter = $this->createBufferRewriter();
        // Build a 2.1MB buffer containing an <img tag. The fast path
        // (strlen > MAX_BUFFER) fires before the content-type guard, so
        // no <html> wrapper is needed here.
        $img = '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">';
        $padding = str_repeat('x', 2 * 1024 * 1024 + 100);
        $html = $padding . $img;
        $result = $rewriter->rewrite($html);

        // Buffer too large → preserved unchanged.
        $this->assertSame($html, $result);
    }

    public function test_does_not_double_rewrite_imgproxy_urls(): void
    {
        // An already-rewritten imgproxy URL does not contain /wp-content/,
        // so the buffer regex won't match it — no double rewrite.
        $rewriter = $this->createBufferRewriter();
        $alreadyRewritten = $this->wrap('<img src="https://imgproxy.example.com/sig/rs:fit:800:0/plain/abc@avif" alt="test">');
        $result = $rewriter->rewrite($alreadyRewritten);

        $this->assertSame($alreadyRewritten, $result);
    }

    /**
     * Catastrophic backtracking regression test.
     *
     * A bounded quantifier like [^"\x27]{1,2000} caused O(n²) backtracking
     * on malformed <img tags with an unterminated quote followed by many
     * non-quote chars. The unbounded greedy [^"\x27]+ is linear. This test
     * verifies the regex completes quickly on a 4KB malformed tag.
     */
    public function test_no_catastrophic_backtracking_on_malformed_tag(): void
    {
        $rewriter = $this->createBufferRewriter();
        // 4KB of non-quote, non-> chars after an unterminated quote.
        // This is the worst case for a bounded quantifier.
        $malformed = '<img src="' . str_repeat('a', 4000) . ' <img';
        // Add a real img tag at the end to ensure the regex runs.
        $html = '<html>' . $malformed . '<img src="https://example.com/wp-content/uploads/photo.jpg" alt="ok">';

        $start = microtime(true);
        $result = $rewriter->rewrite($html);
        $elapsed = microtime(true) - $start;

        // Must complete in well under 1 second. A bounded quantifier would
        // take seconds on this input. We assert < 500ms with margin.
        $this->assertLessThan(0.5, $elapsed, 'Regex must not exhibit catastrophic backtracking');
        // The real img tag at the end should still be rewritten.
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = $this->createBufferRewriter(enabled: false);
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $result = $rewriter->rewrite($html);

        // Delivery disabled → UrlRewriter preserves → buffer regex matches
        // but the rewrite result is the original URL → no change.
        $this->assertSame($html, $result);
    }

    public function test_rewrites_various_image_extensions(): void
    {
        $rewriter = $this->createBufferRewriter();
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff'];

        foreach ($extensions as $ext) {
            $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.' . $ext . '" alt="test">');
            $result = $rewriter->rewrite($html);
            $this->assertStringContainsString(
                'imgproxy.example.com',
                $result,
                "Extension .$ext should be rewritten"
            );
        }
    }

    // --- #43 Phase 3: guard battery (B.2 table) — shouldBuffer() skip conditions ---

    /**
     * Each skip condition in shouldBuffer() must return false. We test
     * shouldBuffer() directly (it's public for this reason) by flipping
     * each $GLOBALS toggle. The register() closure calls shouldBuffer()
     * at template_redirect time — testing it directly is equivalent and
     * avoids needing to fire the action.
     */
    public function test_register_uses_template_redirect_priority_5(): void
    {
        $GLOBALS['__oxpulse_actions'] = [];
        $rewriter = $this->createBufferRewriter();
        $rewriter->register();

        $actions = array_filter(
            $GLOBALS['__oxpulse_actions'] ?? [],
            static fn($e) => $e['hook'] === 'template_redirect'
        );
        $this->assertNotEmpty($actions, 'register() must add a template_redirect action');
        // Priority 5 — inside Autoptimize (pri 2) / WP Rocket (pri 2).
        $first = reset($actions);
        $this->assertSame(5, $first['priority']);
    }

    public function test_should_buffer_returns_true_by_default(): void
    {
        $rewriter = $this->createBufferRewriter();
        $this->assertTrue($rewriter->shouldBuffer());
    }

    public function test_should_buffer_skips_admin(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_admin']);
        }
    }

    public function test_should_buffer_skips_ajax(): void
    {
        $GLOBALS['__oxpulse_doing_ajax'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_doing_ajax']);
        }
    }

    public function test_should_buffer_skips_cron(): void
    {
        $GLOBALS['__oxpulse_doing_cron'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_doing_cron']);
        }
    }

    public function test_should_buffer_skips_feed(): void
    {
        $GLOBALS['__oxpulse_is_feed'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_feed']);
        }
    }

    public function test_should_buffer_skips_embed(): void
    {
        $GLOBALS['__oxpulse_is_embed'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_embed']);
        }
    }

    public function test_should_buffer_skips_preview(): void
    {
        $GLOBALS['__oxpulse_is_preview'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_preview']);
        }
    }

    public function test_should_buffer_skips_customize_preview(): void
    {
        $GLOBALS['__oxpulse_is_customize_preview'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_customize_preview']);
        }
    }

    public function test_should_buffer_skips_amp(): void
    {
        $GLOBALS['__oxpulse_is_amp_endpoint'] = true;
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($GLOBALS['__oxpulse_is_amp_endpoint']);
        }
    }

    public function test_should_buffer_skips_page_builder_edit_mode(): void
    {
        $_GET['fl_builder'] = '1';
        try {
            $this->assertFalse($this->createBufferRewriter()->shouldBuffer());
        } finally {
            unset($_GET['fl_builder']);
        }
    }

    // --- #43 Phase 3: rewrite() content-type / opt-out / fail-safe ---

    public function test_rewrite_skips_non_html_buffer_without_html_tag(): void
    {
        $rewriter = $this->createBufferRewriter();
        // JSON buffer (no <html, no Content-Type header in CLI) → skip.
        $json = '{"posts":[{"img":"https://example.com/wp-content/uploads/photo.jpg"}]}';
        $this->assertSame($json, $rewriter->rewrite($json));
    }

    public function test_rewrite_skips_xml_buffer(): void
    {
        $rewriter = $this->createBufferRewriter();
        $xml = '<?xml version="1.0"?><rss><channel><img src="https://example.com/wp-content/uploads/photo.jpg"/></channel></rss>';
        $this->assertSame($xml, $rewriter->rewrite($xml));
    }

    public function test_rewrite_skips_when_no_oxpulse_marker_present(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<!-- no-oxpulse --><img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $this->assertSame($html, $rewriter->rewrite($html));
    }

    public function test_rewrite_skips_when_opt_out_filter_false(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');

        // Register a filter that disables buffer rewriting.
        $GLOBALS['__oxpulse_filters'] = [];
        add_filter('oxpulse_buffer_rewrite_enabled', '__return_false');
        try {
            $this->assertSame($html, $rewriter->rewrite($html));
        } finally {
            $GLOBALS['__oxpulse_filters'] = [];
        }
    }

    public function test_rewrite_returns_original_on_throw(): void
    {
        // UrlRewriter is final, so we can't inject a throwing subclass.
        // Instead, force preg_replace_callback to fail by setting a
        // tiny backtrack limit — the regex returns null, and returning
        // null from a : string method throws a TypeError, which the
        // try/catch in rewrite() must catch and return the original.
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');

        $originalLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            // Must return the original buffer, not blank or partial.
            $this->assertSame($html, $rewriter->rewrite($html));
        } finally {
            ini_set('pcre.backtrack_limit', $originalLimit !== false ? $originalLimit : '1000000');
        }
    }

    // --- #43 Phase 3: tag-level idempotency ---

    public function test_rewrite_skips_img_with_data_oxpulse_marker(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img data-oxpulse="1" src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $this->assertSame($html, $rewriter->rewrite($html));
    }

    public function test_rewrite_skips_img_with_sp_no_webp_class(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img class="sp-no-webp" src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $this->assertSame($html, $rewriter->rewrite($html));
    }

    public function test_rewrite_skips_img_inside_picture_element(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<picture><source type="image/webp" srcset="x.webp"><img src="https://example.com/wp-content/uploads/photo.jpg" alt="test"></picture>');
        $result = $rewriter->rewrite($html);

        // The <img> inside <picture> must NOT be rewritten.
        $this->assertStringNotContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('https://example.com/wp-content/uploads/photo.jpg', $result);
    }

    public function test_rewrite_adds_data_oxpulse_marker_on_rewrite(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');
        $result = $rewriter->rewrite($html);

        $this->assertStringContainsString('data-oxpulse="1"', $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
    }

    public function test_rewrite_second_pass_skips_already_marked(): void
    {
        $rewriter = $this->createBufferRewriter();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/photo.jpg" alt="test">');

        $first = $rewriter->rewrite($html);
        $this->assertStringContainsString('data-oxpulse="1"', $first);

        // Second pass over the already-marked buffer must not double-rewrite.
        $second = $rewriter->rewrite($first);
        $this->assertSame(1, substr_count($second, 'data-oxpulse="1"'));
        $this->assertSame(1, substr_count($second, 'imgproxy.example.com'));
    }

    // --- Phase 1b: <picture> wrapping in BufferRewriter (default-off) ---

    /**
     * Build a BufferRewriter with a PictureElementWrapper wired + both
     * flags (bufferRewritingEnabled + pictureEnabled) on, backed by the
     * PictureTestBackend stub so emitted per-format URLs are deterministic
     * (https://imgproxy.test/<format>/<base>@<format>). Mirrors the
     * PictureElementWrapperTest wiring.
     */
    private function createBufferRewriterWithPicture(
        bool $pictureEnabled = true,
        bool $bufferEnabled = true,
        array $allowedFormats = []
    ): BufferRewriter {
        // FIX 3: BufferRewriter now directly checks isPro() — the
        // belt-and-suspenders gate. Set Pro=true so the picture-
        // wrapping tests exercise the wrapping path (the filter-only
        // gate was the sole check before).
        add_filter('oxpulse_is_pro', '__return_true');
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.test',
            allowedSources: [self::ALLOWED],
            bufferRewritingEnabled: $bufferEnabled,
            pictureEnabled: $pictureEnabled,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F'),
            null,
            new PictureTestBackend($allowedFormats)
        );
        $wrapper = new PictureElementWrapper($rewriter);
        return new BufferRewriter($rewriter, $delivery, $wrapper);
    }

    public function test_picture_wrap_emits_picture_with_avif_and_webp_sources(): void
    {
        $rewriter = $this->createBufferRewriterWithPicture();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/hero.jpg" srcset="https://example.com/wp-content/uploads/hero-1024.jpg 1024w, https://example.com/wp-content/uploads/hero-2048.jpg 2048w" sizes="100vw" width="1600" height="900" alt="hero">');
        $result = $rewriter->rewrite($html);

        // Exactly one <picture style="display:contents"> wrapper.
        $this->assertSame(1, preg_match('/<picture style="display:contents">.*<\/picture>/s', $result, $picMatch), 'Expected a <picture style="display:contents"> wrapper');
        $picture = $picMatch[0];

        // <source> elements in order: AVIF-first, then WebP.
        preg_match_all('/<source\b[^>]*>/i', $picture, $sourceMatches);
        $sources = $sourceMatches[0];
        $this->assertCount(2, $sources);
        $this->assertStringContainsString('type="image/avif"', $sources[0]);
        $this->assertStringContainsString('type="image/webp"', $sources[1]);

        // Each source carries BOTH w-descriptors from the original srcset
        // (the buffer does NOT rewrite srcset — the wrapper builds the
        // per-format srcset from the original).
        foreach ($sources as $source) {
            $this->assertStringContainsString('1024w', $source);
            $this->assertStringContainsString('2048w', $source);
            // sizes copied from the inner img.
            $this->assertStringContainsString('sizes="100vw"', $source);
        }

        // Inner <img> carries both idempotency markers + rewritten src.
        preg_match('/(<img\b[^>]*>)/i', $picture, $imgMatch);
        $innerImg = $imgMatch[1];
        $this->assertStringContainsString('data-oxpulse="1"', $innerImg);
        $this->assertStringContainsString('data-oxpulse-picture="1"', $innerImg);
        $this->assertStringContainsString('imgproxy.test', $innerImg);
        // Original src no longer present on the inner img.
        $this->assertStringNotContainsString('src="https://example.com/wp-content/uploads/hero.jpg"', $innerImg);
    }

    public function test_picture_disabled_emits_plain_rewritten_img_no_picture(): void
    {
        $rewriter = $this->createBufferRewriterWithPicture(pictureEnabled: false);
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/hero.jpg" srcset="https://example.com/wp-content/uploads/hero-1024.jpg 1024w" width="1600" height="900" alt="hero">');
        $result = $rewriter->rewrite($html);

        // pictureEnabled=false → today's behavior: plain rewritten <img>.
        $this->assertStringNotContainsString('<picture', $result);
        $this->assertStringNotContainsString('data-oxpulse-picture', $result);
        $this->assertStringContainsString('data-oxpulse="1"', $result);
        $this->assertStringContainsString('imgproxy.test', $result);
    }

    public function test_picture_wrapper_null_emits_plain_rewritten_img_no_picture(): void
    {
        // No wrapper injected (pre-Phase-1b construction) → no <picture>,
        // even if pictureEnabled were true. Exercises the null-guard branch.
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.test',
            allowedSources: [self::ALLOWED],
            bufferRewritingEnabled: true,
            pictureEnabled: true,
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F'),
            null,
            new PictureTestBackend([])
        );
        $buffer = new BufferRewriter($rewriter, $delivery);
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/hero.jpg" width="800" height="600" alt="hero">');
        $result = $buffer->rewrite($html);

        $this->assertStringNotContainsString('<picture', $result);
        $this->assertStringNotContainsString('data-oxpulse-picture', $result);
        $this->assertStringContainsString('data-oxpulse="1"', $result);
        $this->assertStringContainsString('imgproxy.test', $result);
    }

    public function test_picture_wrap_second_pass_is_noop(): void
    {
        $rewriter = $this->createBufferRewriterWithPicture();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/hero.jpg" width="1600" height="900" alt="hero">');

        $first = $rewriter->rewrite($html);
        $this->assertSame(1, substr_count($first, '<picture'), 'first pass wraps once');

        // Second pass over already-wrapped HTML must be a no-op: the inner
        // img's src is now an imgproxy.test URL (no /wp-content/ → the
        // buffer regex does not match), AND it sits inside a <picture>
        // span (findPictureSpans), AND it carries data-oxpulse. Triple
        // protection — assert no nested/double <picture>.
        $second = $rewriter->rewrite($first);
        $this->assertSame(1, substr_count($second, '<picture'), 'second pass must not double-wrap');
        $this->assertSame(1, substr_count($second, 'data-oxpulse-picture="1"'));
        $this->assertSame($first, $second, 'second pass over already-wrapped HTML is a no-op');
    }

    public function test_picture_wrap_throw_caught_returns_original_buffer(): void
    {
        $rewriter = $this->createBufferRewriterWithPicture();
        $html = $this->wrap('<img src="https://example.com/wp-content/uploads/hero.jpg" width="800" height="600" alt="hero">');

        // PictureElementWrapper is final, so we cannot inject a throwing
        // stub. Instead force preg_replace_callback to fail (returns null →
        // TypeError from the : string callback) via a tiny backtrack limit.
        // The picture-wrap call lives INSIDE rewriteImgTags, which is inside
        // the try/catch in rewrite() — so the throw is caught and the
        // original buffer is returned, never blanked. This proves the
        // picture path does not escape the fail-safe.
        $originalLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->assertSame($html, $rewriter->rewrite($html));
        } finally {
            ini_set('pcre.backtrack_limit', $originalLimit !== false ? $originalLimit : '1000000');
        }
    }

    public function test_picture_wrap_skips_data_src_lazy_image(): void
    {
        // A JS-lazy <img> (data-src with a placeholder data-URI src) must
        // NOT be wrapped in <picture>: a <source srcset> is resolved
        // EAGERLY by the browser at parse time, which would defeat the
        // theme's JS lazy-loader and eager-load every below-the-fold
        // image. The lazy <img> still gets imgproxy delivery via the
        // data-src rewrite (the src-rewrite path is unaffected) — just no
        // <picture>. Native loading="lazy" on a plain src <img> is
        // unaffected (honored on the inner <img> inside <picture>).
        $rewriter = $this->createBufferRewriterWithPicture();
        $html = $this->wrap('<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="https://example.com/wp-content/uploads/lazy.jpg" width="800" height="600" alt="lazy">');
        $result = $rewriter->rewrite($html);

        // No <picture> wrapping for the lazy image.
        $this->assertStringNotContainsString('<picture', $result);
        // The data-src WAS still rewritten to a delivery URL — the lazy
        // image keeps working + gets imgproxy delivery, just no <picture>.
        $this->assertStringContainsString('imgproxy.test', $result);
        $this->assertStringContainsString(
            'data-src="https://imgproxy.test/',
            $result,
            'data-src must be rewritten to a delivery URL'
        );
        // The original data-src URL is gone (replaced by the delivery URL).
        $this->assertStringNotContainsString(
            'data-src="https://example.com/wp-content/uploads/lazy.jpg"',
            $result
        );
    }

    public function test_picture_wrap_skips_img_already_inside_picture(): void
    {
        // An <img> already inside a <picture> in the source HTML must be
        // skipped by findPictureSpans/isInsidePicture even when the picture
        // wrapper is wired + enabled — no nested <picture> emitted.
        $rewriter = $this->createBufferRewriterWithPicture();
        $html = $this->wrap('<picture><source type="image/webp" srcset="x.webp"><img src="https://example.com/wp-content/uploads/photo.jpg" alt="test"></picture>');
        $result = $rewriter->rewrite($html);

        $this->assertSame(1, substr_count($result, '<picture'));
        $this->assertStringNotContainsString('data-oxpulse-picture', $result);
        $this->assertStringNotContainsString('imgproxy.test', $result);
    }

    // --- #55: BufferRewriter refinements ---

    private const IMG_A = '<img src="https://example.com/wp-content/uploads/dup.jpg" alt="dup">';
    private const IMG_PICTURE_A = '<picture><source type="image/webp" srcset="x.webp"><img src="https://example.com/wp-content/uploads/dup.jpg" alt="dup"></picture>';

    public function test_duplicate_tag_standalone_then_inside_picture_uses_real_offset(): void
    {
        $rewriter = $this->createBufferRewriter();
        $result = $rewriter->rewrite($this->wrap(self::IMG_A . self::IMG_PICTURE_A));

        // Standalone one is rewritten; the one inside <picture> is left alone.
        $this->assertSame(1, substr_count($result, 'imgproxy.example.com'), 'only the standalone <img> may be rewritten');
        $this->assertStringContainsString(
            '<source type="image/webp" srcset="x.webp"><img src="https://example.com/wp-content/uploads/dup.jpg" alt="dup"></picture>',
            $result,
            'the <img> inside <picture> must stay untouched'
        );
    }

    public function test_duplicate_tag_inside_picture_then_standalone_uses_real_offset(): void
    {
        $rewriter = $this->createBufferRewriter();
        $result = $rewriter->rewrite($this->wrap(self::IMG_PICTURE_A . self::IMG_A));

        $this->assertSame(1, substr_count($result, 'imgproxy.example.com'), 'the standalone <img> after a <picture> must be rewritten');
        $this->assertStringContainsString(
            '<img src="https://example.com/wp-content/uploads/dup.jpg" alt="dup"></picture>',
            $result,
            'the <img> inside <picture> must stay untouched'
        );
    }

    public function test_should_buffer_skips_wpbakery_frontend_editor(): void
    {
        foreach (['vc_editable', 'vc_action'] as $key) {
            $_GET[$key] = '1';
            try {
                $this->assertFalse($this->createBufferRewriter()->shouldBuffer(), $key . ' must disable the buffer');
            } finally {
                unset($_GET[$key]);
            }
        }
    }

    public function test_rewrites_data_lazy_src_attribute(): void
    {
        $rewriter = $this->createBufferRewriter();
        $result = $rewriter->rewrite($this->wrap('<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-lazy-src="https://example.com/wp-content/uploads/lazy.jpg" alt="x">'));

        $this->assertStringContainsString('data-lazy-src="https://imgproxy.example.com/', $result);
        $this->assertStringNotContainsString('data-lazy-src="https://example.com/', $result);
    }

    public function test_never_blank_when_pcre_fails(): void
    {
        // An unterminated style attribute makes the background pass exhaust
        // pcre.backtrack_limit (JIT off) while the <img> pass still succeeds,
        // so the failure is NOT swallowed by the rewrite() try/catch. A
        // `(string) null` cast there would blank the whole page.
        $html = '<html><body><img src="https://example.com/wp-content/uploads/a.jpg" alt="a">'
            . '<div style="background' . str_repeat('a', 1500000) . '</div></body></html>';
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $result = $this->createBufferRewriter()->rewrite($html);
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }
        $this->assertNotSame('', $result, 'a PCRE failure must never blank the page');
        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('<div style="background', $result);
    }

    // --- #77: AVIF for JS-lazy images without eager-loading them ---

    private const GIF = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';

    /**
     * Parse-time oracle: a browser fetches `src` of an <img> and `srcset`
     * of an <img>/<source> at parse time. Return every such URL that
     * points at the delivery host. DOM-parsed so data-srcset cannot be
     * mistaken for srcset.
     *
     * @return list<string>
     */
    private function parseTimeFetches(string $html, string $host): array
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $fetches = [];
        foreach (['img', 'source'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                foreach (['src', 'srcset'] as $attr) {
                    $value = $el->getAttribute($attr);
                    if ($value !== '' && str_contains($value, $host)) {
                        $fetches[] = $tag . '[' . $attr . ']=' . $value;
                    }
                }
            }
        }
        return $fetches;
    }

    private function lazysizesTag(string $class = 'lazyload'): string
    {
        return '<img class="' . $class . '" src="' . self::GIF . '" data-src="https://example.com/wp-content/uploads/lazy.jpg"'
            . ' data-srcset="https://example.com/wp-content/uploads/lazy-400.jpg 400w, https://example.com/wp-content/uploads/lazy-800.jpg 800w"'
            . ' sizes="(max-width: 800px) 100vw, 800px" width="800" height="600" alt="lazy">';
    }

    public function test_lazysizes_image_gets_avif_sources_that_fetch_nothing_at_parse_time(): void
    {
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($this->lazysizesTag()));

        $this->assertSame(1, substr_count($result, '<picture'), 'lazysizes image should be wrapped');
        $this->assertSame(
            [],
            $this->parseTimeFetches($result, 'imgproxy.test'),
            'a parse-time browser must fetch NO variant for a below-fold lazy image'
        );
        // The variants are present, but only under data-srcset (the contract lazysizes swaps).
        preg_match_all('/<source\b[^>]*>/i', $result, $sources);
        $this->assertCount(2, $sources[0]);
        $this->assertStringContainsString('type="image/avif"', $sources[0][0]);
        $this->assertStringContainsString('data-srcset="https://imgproxy.test/avif/', $sources[0][0]);
        $this->assertStringContainsString('400w', $sources[0][0]);
        $this->assertStringContainsString('type="image/webp"', $sources[0][1]);
        $this->assertStringContainsString('data-srcset="https://imgproxy.test/webp/', $sources[0][1]);
        // The inner <img> keeps its placeholder src and its lazy data-src.
        $this->assertStringContainsString('src="' . self::GIF . '"', $result);
        $this->assertStringContainsString('data-src="https://imgproxy.test/', $result);
    }

    public function test_unknown_lazy_loader_is_not_wrapped(): void
    {
        // class="lazy" + data-src is the vanilla-lazyload default but is too
        // generic to assume; no contract detected -> no <picture>.
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($this->lazysizesTag('lazy')));

        $this->assertStringNotContainsString('<picture', $result);
        $this->assertSame([], $this->parseTimeFetches($result, 'imgproxy.test'));
        $this->assertStringContainsString('data-src="https://imgproxy.test/', $result, 'src rewrite still applies');
    }

    public function test_data_lazy_src_image_is_never_wrapped(): void
    {
        // WP Rocket / Jetpack style. Their loaders are not known to swap
        // <source> attributes; a wrapped tag would eager-load via srcset.
        $tag = '<img src="' . self::GIF . '" data-lazy-src="https://example.com/wp-content/uploads/l.jpg" data-lazy-srcset="https://example.com/wp-content/uploads/l-400.jpg 400w" width="400" height="300" alt="x">';
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($tag));

        $this->assertStringNotContainsString('<picture', $result);
        $this->assertSame([], $this->parseTimeFetches($result, 'imgproxy.test'));
        $this->assertStringContainsString('data-lazy-src="https://imgproxy.test/', $result);
    }

    public function test_lazy_attribute_after_matched_src_still_counts_as_lazy(): void
    {
        // The matched URL sits in plain src; the lazy marker is in the suffix.
        $tag = '<img src="https://example.com/wp-content/uploads/a.jpg" data-src="https://cdn.example.org/b.jpg" alt="x">';
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($tag));

        $this->assertStringNotContainsString('<picture', $result);
    }

    public function test_lazy_picture_requires_picture_enabled(): void
    {
        $result = $this->createBufferRewriterWithPicture(pictureEnabled: false)->rewrite($this->wrap($this->lazysizesTag()));

        $this->assertStringNotContainsString('<picture', $result);
        $this->assertStringNotContainsString('<source', $result);
    }

    public function test_filter_can_opt_a_loader_in_and_invalid_values_are_rejected(): void
    {
        add_filter('oxpulse_lazy_picture_source_attribute', fn ($attr) => 'data-srcset');
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($this->lazysizesTag('lazy')));
        $this->assertSame(1, substr_count($result, '<picture'));
        $this->assertSame([], $this->parseTimeFetches($result, 'imgproxy.test'));

        // A filter that returns a fetch-triggering name must be refused.
        $GLOBALS['__oxpulse_filters'] = [];
        add_filter('oxpulse_lazy_picture_source_attribute', fn ($attr) => 'srcset');
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($this->lazysizesTag('lazy')));
        $this->assertStringNotContainsString('<picture', $result);

        // A trailing newline must not slip past the anchor.
        $GLOBALS['__oxpulse_filters'] = [];
        add_filter('oxpulse_lazy_picture_source_attribute', fn ($attr) => "data-srcset\n");
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($this->lazysizesTag('lazy')));
        $this->assertStringNotContainsString('<picture', $result);
    }

    public function test_native_lazy_image_is_still_wrapped_with_real_srcset(): void
    {
        // Control: loading="lazy" with a plain src is native-lazy, not JS-lazy.
        $tag = '<img src="https://example.com/wp-content/uploads/n.jpg" loading="lazy" width="400" height="300" alt="n">';
        $result = $this->createBufferRewriterWithPicture()->rewrite($this->wrap($tag));

        $this->assertSame(1, substr_count($result, '<picture'));
        $this->assertStringContainsString(' srcset="https://imgproxy.test/avif/', $result);
    }
}
