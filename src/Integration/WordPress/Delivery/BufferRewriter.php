<?php
/**
 * Output buffer rewriter.
 *
 * Catches <img> tags hardcoded by the theme in PHP templates — bypassing
 * the wp_content_img_tag filter (which only fires for images emitted
 * through wp_get_attachment_image / the_content). On piter.now the Foxiz
 * theme hardcodes <img> tags directly in hero/card/block templates; these
 * never pass through any of the 5 currently-hooked filters.
 *
 * Registered on template_redirect priority 5 via ob_start, runs once on
 * the full HTML response. Priority 5 places us INSIDE Autoptimize (pri 2)
 * and WP Rocket (pri 2) buffers — we get the final word on <img> tags,
 * then bubble into the outer page cache. The full guard battery (B.2
 * table) skips admin/AJAX/CRON/REST/XMLRPC/feed/embed/preview/customize/
 * AMP/page-builder-edit contexts, and the rewrite callback enforces a
 * content-type guard, an opt-out filter, a page marker, and a
 * fail-safe-to-original try/catch — never blank the page.
 *
 * The regex is intentionally unbounded (no {1,N} quantifier) — a bounded
 * quantifier caused catastrophic backtracking on malformed <img tags in
 * production (O(n²) on 4KB input, pinned a PHP-FPM worker at 100% CPU
 * for seconds).
 *
 * #43 Phase 3 — tag-level idempotency: skip an <img> that (a) already
 * carries a data-oxpulse marker, (b) has class sp-no-webp (ShortPixel's
 * already-handled marker), or (c) is inside a <picture> element. When we
 * rewrite an <img>, we add a data-oxpulse="1" marker so a later pass
 * skips it.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\PictureElementWrapper;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;

final class BufferRewriter
{
    /** Skip buffers larger than 2MB — protects memory on huge pages. */
    private const MAX_BUFFER = 2 * 1024 * 1024;

    /** Supported source extensions (case-insensitive). */
    private const SOURCE_EXTENSIONS = 'jpe?g|png|gif|webp|avif|bmp|tiff?';

    /**
     * Page-builder edit-mode $_GET keys. When any is present, the page
     * is being edited in a builder UI (Beaver/Divi/Bricks/Breakdance/
     * Oxygen/Elementor) — rewriting would corrupt the builder preview.
     * Source: ShortPixel PageConverter.php:30-52, EWWW :99-154.
     */
    private const PAGE_BUILDER_GET_KEYS = [
        'fl_builder', 'et_fb', 'bricks', 'breakdance',
        'ct_builder', 'elementor-preview', 'fb-edit',
        // WPBakery frontend editor (#55). Its backend editor is covered
        // by is_admin(); the frontend editor loads the public page.
        'vc_editable', 'vc_action',
    ];

    private UrlRewriter $rewriter;
    private DeliveryConfig $delivery;
    private ?PictureElementWrapper $pictureWrapper;

    /**
     * @param PictureElementWrapper|null $pictureWrapper Optional <picture>
     *        wrapper (Phase 1b). When null (the default — preserves backward
     *        compatibility with all pre-Phase-1b callers), no <picture>
     *        wrapping is attempted. When injected, the runtime
     *        oxpulse_picture_enabled filter + $delivery->pictureEnabled gate
     *        the actual wrapping at rewrite time, mirroring the
     *        ContentImgTagRewriter shape.
     */
    public function __construct(
        UrlRewriter $rewriter,
        DeliveryConfig $delivery,
        ?PictureElementWrapper $pictureWrapper = null
    ) {
        $this->rewriter = $rewriter;
        $this->delivery = $delivery;
        $this->pictureWrapper = $pictureWrapper;
    }

    /**
     * Register the output buffer on template_redirect priority 5. The
     * buffer is flushed automatically by PHP at request end; the rewrite
     * callback runs once on the full HTML response.
     *
     * The full guard battery (B.2 table) runs BEFORE ob_start — if any
     * skip condition is met, the buffer is not started at all (zero
     * overhead, no nesting into other plugins' buffers).
     */
    public function register(): void
    {
        add_action('template_redirect', function (): void {
            if (!$this->shouldBuffer()) {
                return;
            }
            if (!ob_start([$this, 'rewrite'])) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- defensive dev-only warning.
                trigger_error(
                    'OXPulse: ob_start failed — buffer rewriting disabled',
                    E_USER_WARNING
                );
            }
        }, 5);
    }

    /**
     * #43 Phase 3 — guard battery (B.2 table). Returns false (do NOT
     * start the buffer) when any skip condition is met. Mirrors EWWW
     * :80-218 + Autoptimize :387,400 + W3TC :1013 + LSCache can_optm.
     *
     * Public so tests can exercise each skip branch directly without
     * firing template_redirect. Called from the template_redirect
     * closure in register().
     */
    public function shouldBuffer(): bool
    {
        // Skip admin (without allowing AJAX — we don't buffer AJAX).
        if (is_admin()) {
            return false;
        }
        if (wp_doing_ajax()) {
            return false;
        }
        if (wp_doing_cron()) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }
        if (function_exists('is_feed') && is_feed()) {
            return false;
        }
        if (function_exists('is_embed') && is_embed()) {
            return false;
        }
        if (function_exists('is_preview') && is_preview()) {
            return false;
        }
        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return false;
        }
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) {
            return false;
        }
        // Page-builder edit modes via $_GET.
        foreach (self::PAGE_BUILDER_GET_KEYS as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET inspection, no state mutation.
            if (isset($_GET[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rewrite <img> tags in the HTML buffer.
     *
     * Called by PHP's output buffer handler. Must return the (possibly
     * rewritten) HTML string. Failures must preserve the input — never
     * return a partial or empty buffer.
     *
     * @param string $html Full HTML response (or chunk).
     * @return string Rewritten HTML, or original on any skip path.
     */
    public function rewrite(string $html): string
    {
        // Fast paths — skip without touching the regex engine.
        if ($html === '' || strlen($html) > self::MAX_BUFFER) {
            return $html;
        }
        if (
            !str_contains($html, '<img')
            && !str_contains($html, 'background')
            && !str_contains($html, 'rel="preload"')
            && !str_contains($html, "rel='preload'")
        ) {
            return $html;
        }

        // #43 Phase 3 — opt-out filter. An operator can disable buffer
        // rewriting globally (e.g. via a mu-plugin) even when
        // bufferRewritingEnabled is true. The register() guard already
        // ran, but this filter is re-checked at rewrite time so a late
        // filter addition (after register) still takes effect.
        if (!apply_filters('oxpulse_buffer_rewrite_enabled', $this->delivery->bufferRewritingEnabled)) {
            return $html;
        }

        // #43 Phase 3 — page marker. An operator can paste
        // <!-- no-oxpulse --> into a page/template to opt out per-page.
        if (str_contains($html, '<!-- no-oxpulse -->')) {
            return $html;
        }

        // #43 Phase 3 — content-type guard. Bail on JSON/XML/binary/
        // feeds/sitemaps/REST. Accept text/html via headers_list() OR
        // an Autoptimize-style <html sniff (some hosts don't send
        // Content-Type before ob_start callbacks run).
        if (!$this->isHtmlResponse($html)) {
            return $html;
        }

        // #43 Phase 3 — fail-safe: never blank the page. Any Throwable
        // from the regex/rewrite path returns the original buffer.
        try {
            $html = $this->rewriteImgTags($html);
            $html = $this->rewriteStyleBackgrounds($html);
            return $this->rewriteImagePreloads($html);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * #43 Phase 3 — content-type guard. Returns true when the response
     * is HTML (the only content type we should rewrite). Checks
     * headers_list() for a text/html Content-Type, falling back to a
     * `<html` sniff (Autoptimize-style) for hosts that don't emit the
     * header before the ob_start callback runs.
     */
    private function isHtmlResponse(string $buffer): bool
    {
        $headers = function_exists('headers_list') ? headers_list() : [];
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }
        // No Content-Type header found — sniff the buffer for <html.
        return stripos($buffer, '<html') !== false;
    }

    /**
     * Apply the buffer-rewrite regex to all <img> tags matching the
     * source-extension pattern. Delegates per-match to UrlRewriter,
     * which enforces source policy + signing + fail-safe preservation.
     *
     * #43 Phase 3 — per-match idempotency: skip an <img> that (a) already
     * carries a data-oxpulse marker, (b) has class sp-no-webp, or (c) is
     * inside a <picture> element. When we rewrite, add data-oxpulse="1".
     */
    private function rewriteImgTags(string $html): string
    {
        // Pre-compute the byte offsets of all <picture> open tags so we
        // can skip <img> tags that fall inside a <picture>...</picture>
        // span (another plugin already wrapped the img in <picture>).
        $pictureSpans = $this->findPictureSpans($html);

        // Match <img ... src|data-src="...wp-content/...ext" ...>
        //
        // CRITICAL: the URL character class [^"\x27]+ is UNBOUNDED — no
        // {1,N} quantifier. A bounded quantifier like [^"\x27]{1,2000}
        // causes catastrophic backtracking on malformed <img tags that
        // contain an unterminated quote followed by many non-quote chars:
        // the regex engine tries every split of the long run before
        // giving up, O(n²) on a 4KB tag. The unbounded greedy quantifier
        // is linear and safe — it grabs everything until the next quote.
        //
        // \x27 = single quote (avoid ' which can be confused in PHP).
        // The attribute alternation is explicit (#55): \bsrc alone already
        // matches the tail of data-lazy-src (the hyphen is a word boundary),
        // but that was an accident, not a contract.
        $pattern = '#(<img[^>]+(?:\bsrc|\bdata-src|\bdata-lazy-src)=["\x27])([^"\x27]+/wp-content/[^"\x27]+\.(?:'
            . self::SOURCE_EXTENSIONS
            . '))(["\x27][^>]*>)#i';

        // PREG_OFFSET_CAPTURE: every $m[n] is [text, byteOffset]. The offset
        // is relative to the original subject — the same coordinate space
        // findPictureSpans() uses (#55).
        $rewrittenHtml = preg_replace_callback($pattern, function (array $m) use ($pictureSpans): string {
            $prefix = $m[1][0];
            $url = $m[2][0];
            $suffix = $m[3][0];
            $fullTag = $m[0][0];
            $matchOffset = $m[0][1];

            // #43 Phase 3 — tag-level idempotency: skip already-marked.
            if ($this->hasOxpulseMarker($fullTag) || $this->hasSpNoWebpClass($fullTag)) {
                return $fullTag;
            }

            // #43 Phase 3 — skip <img> inside a <picture> element, using
            // the REAL match offset (#55: a strpos on the tag text resolved
            // duplicate identical tags to the first occurrence).
            if ($this->isInsidePicture($matchOffset, strlen($fullTag), $pictureSpans)) {
                return $fullTag;
            }

            // Extract width/height hints from the surrounding tag (in the
            // prefix + suffix) for better imgproxy resize targeting.
            $tagFragment = $prefix . $url . $suffix;
            $width = $this->extractAttribute($tagFragment, 'width');
            $height = $this->extractAttribute($tagFragment, 'height');

            $result = $this->rewriter->rewrite($url, $width, $height, 'buffer');

            if (!$result->rewritten) {
                // Source not allowed / delivery disabled / signing missing —
                // preserve the original URL.
                return $fullTag;
            }

            // #43 Phase 3 — stamp data-oxpulse="1" so a later pass skips.
            // #73: escape the rewritten URL for the attribute context.
            $rewritten = $prefix . htmlspecialchars($result->url, ENT_QUOTES, 'UTF-8') . $suffix;
            $rewritten = $this->addOxpulseMarker($rewritten);

            // Phase 1b — <picture> wrapping for theme-hardcoded <img> tags
            // (default OFF). Wraps the rewritten <img> in
            // <picture style="display:contents"><source type="image/avif">
            // <source type="image/webp"> so a modern browser negotiates AVIF
            // client-side on standard Apache — extends #68's content-path
            // <picture> to the buffer path (theme-hardcoded <img> that bypass
            // every WP content filter). Reuses PictureElementWrapper verbatim.
            // The runtime oxpulse_picture_enabled filter is the SINGLE honest
            // gate (mirrors oxpulse_buffer_rewrite_enabled + the content-path
            // shape); when false OR no wrapper is injected, behavior is
            // exactly today's (plain rewritten <img> with data-oxpulse).
            // Runs INSIDE rewriteImgTags → inside the try/catch in rewrite(),
            // so a wrap failure falls back to the (already-safe) non-picture
            // output, never throws out of the buffer.
            if ($this->pictureWrapper !== null
                && apply_filters('oxpulse_picture_enabled', $this->delivery->pictureEnabled)
                && oxpulse_license_gate()->isPro()
            ) {
                // JS-lazy images (#77): a <source srcset> is resolved EAGERLY
                // by the browser at parse time, which would defeat the
                // theme's lazy-loader and eager-load every below-the-fold
                // image. A <source> WITHOUT srcset is skipped by source
                // selection, so a lazy image is only wrapped when its
                // loader is known to swap <source data-srcset> -> srcset
                // on unveil (lazysizes; vanilla-lazyload via the filter).
                // Any other loader keeps today's behaviour: the src rewrite
                // above (WebP delivery) and no <picture>. Native
                // loading="lazy" on a plain src <img> is not JS-lazy and is
                // honored on the inner <img> inside the <picture>.
                $sourceAttr = 'srcset';
                $lazy = $this->isJsLazy($prefix, $fullTag);
                if ($lazy) {
                    $sourceAttr = $this->lazySourceAttribute($fullTag);
                    if ($sourceAttr === null) {
                        return $rewritten;
                    }
                }

                // Extract the ORIGINAL srcset from the full matched tag
                // — the buffer does NOT rewrite srcset, so the tag's srcset
                // is still the pre-rewrite value. Pass '' when absent (the
                // wrapper then builds single-URL <source>s).
                $originalSrcset = $this->extractSrcsetAttribute($fullTag, $lazy);
                $rewritten = $this->pictureWrapper->wrap(
                    $rewritten,
                    $url,
                    $originalSrcset,
                    $width,
                    $height,
                    $sourceAttr
                );
            }

            return $rewritten;
        }, $html, -1, $count, PREG_OFFSET_CAPTURE);

        // Never-blank: a PCRE failure returns null — keep the input.
        return $rewrittenHtml ?? $html;
    }

    /**
     * Find all <picture>...</picture> byte-offset spans in $html.
     *
     * Returns an array of [start, end] pairs (end exclusive). Used to
     * skip <img> tags that fall inside a <picture> (another plugin
     * already wrapped the img with <source type="image/webp">).
     *
     * @return array<int, array{0:int, 1:int}>
     */
    /**
     * #136 — rewrite CSS background-image URLs inside inline style
     * ATTRIBUTES (builder/theme markup the attachment API never sees).
     *
     * Scope is deliberately narrow: style="..." attributes only, one
     * url(...) token at a time, image extensions under /wp-content/.
     * A <style> block needs a CSS parser, not a regex — untouched.
     * Fail-safe: a preserved rewrite leaves the token byte-identical.
     */
    private function rewriteStyleBackgrounds(string $html): string
    {
        if (!str_contains($html, 'background')) {
            return $html;
        }

        $attrPattern = '#\bstyle\s*=\s*(["\x27])((?:(?!\1).)*?background(?:(?!\1).)*?)\1#i';

        return preg_replace_callback($attrPattern, function (array $attr): string {
            $quote = $attr[1];
            $css = $attr[2];

            $urlPattern = '#url\(\s*(["\x27]?)([^"\x27()\s]+/wp-content/[^"\x27()\s]+\.(?:' . self::SOURCE_EXTENSIONS . '))\1\s*\)#i';
            $rewrittenCss = preg_replace_callback($urlPattern, function (array $m): string {
                $result = $this->rewriter->rewrite($m[2], 0, 0, 'buffer_bg');
                if (!$result->rewritten) {
                    return $m[0];
                }
                // Attribute + CSS-url context: the generated URL is
                // base64url/imgproxy-options charset, safe for both; escape
                // for the surrounding HTML attribute anyway (#73 discipline).
                return 'url(' . htmlspecialchars($result->url, ENT_QUOTES, 'UTF-8') . ')';
            }, $css) ?? $css;

            return 'style=' . $quote . $rewrittenCss . $quote;
        }, $html) ?? $html;
    }

    /**
     * #136 — rewrite <link rel="preload" as="image" href="..."> so the
     * preload hint and the rendered <img> point at the SAME (proxied)
     * URL. A preload left at the origin double-downloads: the browser
     * fetches the origin bytes for the hint and the proxied bytes for
     * the tag. Attribute order is not guaranteed — match any <link>
     * carrying both rel=preload and as=image, then rewrite href.
     */
    private function rewriteImagePreloads(string $html): string
    {
        if (stripos($html, 'preload') === false) {
            return $html;
        }

        return preg_replace_callback('#<link\b[^>]*>#i', function (array $m): string {
            $tag = $m[0];
            if (
                !preg_match('#\brel\s*=\s*["\x27]preload["\x27]#i', $tag)
                || !preg_match('#\bas\s*=\s*["\x27]image["\x27]#i', $tag)
            ) {
                return $tag;
            }
            if (!preg_match('#\bhref\s*=\s*(["\x27])([^"\x27]+)\1#i', $tag, $href)) {
                return $tag;
            }

            $result = $this->rewriter->rewrite($href[2], 0, 0, 'buffer_preload');
            if (!$result->rewritten) {
                return $tag;
            }

            return str_replace(
                $href[0],
                'href=' . $href[1] . htmlspecialchars($result->url, ENT_QUOTES, 'UTF-8') . $href[1],
                $tag
            );
        }, $html) ?? $html;
    }

    private function findPictureSpans(string $html): array
    {
        $spans = [];
        $offset = 0;
        while (true) {
            $open = stripos($html, '<picture', $offset);
            if ($open === false) {
                break;
            }
            $close = stripos($html, '</picture>', $open);
            if ($close === false) {
                break;
            }
            $spans[] = [$open, $close + strlen('</picture>')];
            $offset = $close + strlen('</picture>');
        }
        return $spans;
    }

    /**
     * Whether the tag at byte offset $pos (length $length) falls inside
     * any <picture> span. $pos is the real preg match offset (#55).
     *
     * @param array<int, array{0:int, 1:int}> $pictureSpans
     */
    private function isInsidePicture(int $pos, int $length, array $pictureSpans): bool
    {
        $tagEnd = $pos + $length;
        foreach ($pictureSpans as [$start, $end]) {
            if ($pos >= $start && $tagEnd <= $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * #43 Phase 3 — whether the tag already carries our data-oxpulse
     * marker attribute (a previous pass already rewrote it).
     */
    private function hasOxpulseMarker(string $imgTag): bool
    {
        return (bool) preg_match('/\bdata-oxpulse\s*=\s*["\'][^"\']*["\']/i', $imgTag);
    }

    /**
     * #43 Phase 3 — whether the tag carries ShortPixel's sp-no-webp
     * class (ShortPixel's already-handled marker). Word-boundary aware.
     */
    private function hasSpNoWebpClass(string $imgTag): bool
    {
        if (!preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/i', $imgTag, $m)) {
            return false;
        }
        $classes = preg_split('/\s+/', $m[1]) ?: [];
        return in_array('sp-no-webp', $classes, true);
    }

    /**
     * #43 Phase 3 — add data-oxpulse="1" marker right after the opening
     * <img so a later pass (ours or another plugin's) can skip the tag.
     */
    private function addOxpulseMarker(string $imgTag): string
    {
        return preg_replace(
            '/^(<img\b)/i',
            '$1 data-oxpulse="1"',
            $imgTag,
            1,
        );
    }

    /**
     * Extract an integer attribute (width/height) from an <img> tag fragment.
     * Returns 0 when the attribute is absent or non-numeric.
     */
    private function extractAttribute(string $tagFragment, string $attr): int
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '=["\x27](\d+)["\x27]/', $tagFragment, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Whether the matched tag is JS-lazy: the URL we matched sits in an
     * attribute other than a plain `src` (data-src, data-lazy-src, ...),
     * or the tag carries a lazy data-* attribute or the lazysizes
     * `lazyload` class anywhere. Decided from the FULL tag (#77), not just
     * the prefix — a lazy attribute in the suffix must count too.
     */
    private function isJsLazy(string $prefix, string $fullTag): bool
    {
        if (preg_match('/([\w-]+)\s*=\s*["\x27]$/', $prefix, $a) && strtolower($a[1]) !== 'src') {
            return true;
        }
        if (preg_match('/(?<![\w-])data-(?:lazy-)?src(?:set)?\s*=/i', $fullTag)) {
            return true;
        }
        return $this->hasClassToken($fullTag, 'lazyload');
    }

    /**
     * The attribute name a lazy-loader swaps onto <source> elements, or
     * null when the loader is unknown (the tag is then left unwrapped).
     *
     * Detected contracts (verified against the loader source):
     * - lazysizes 5.3.2 (`lazyload` class): handleSources() copies
     *   `data-srcset` -> `srcset` on every <source> of the img's <picture>.
     * Other loaders (vanilla-lazyload `data-srcset` on `.lazy`, WP Rocket,
     * Jetpack, Foxiz built-in, ...) are not guessed; an operator can opt a
     * site in via the oxpulse_lazy_picture_source_attribute filter.
     */
    private function lazySourceAttribute(string $fullTag): ?string
    {
        $attr = $this->hasClassToken($fullTag, 'lazyload') ? 'data-srcset' : null;
        $attr = apply_filters('oxpulse_lazy_picture_source_attribute', $attr, $fullTag);
        if (!is_string($attr) || !preg_match('/^data-[a-z][a-z0-9-]*$/', $attr)) {
            return null;
        }
        return $attr;
    }

    private function hasClassToken(string $imgTag, string $token): bool
    {
        if (!preg_match('/(?<![\w-])class\s*=\s*["\x27]([^"\x27]*)["\x27]/i', $imgTag, $m)) {
            return false;
        }
        return in_array($token, preg_split('/\s+/', $m[1]) ?: [], true);
    }

    /**
     * Phase 1b — extract the srcset value from a full <img> tag. Returns
     * '' when the tag has none. The buffer does NOT rewrite srcset, so
     * this is the ORIGINAL pre-rewrite srcset — exactly what
     * PictureElementWrapper::wrap() needs to build per-format <source>
     * candidates. Handles double + single quotes.
     *
     * Attribute names are matched exactly (#77): a lazy tag prefers
     * data-srcset / data-lazy-srcset (its real candidates; a plain srcset
     * on such a tag is a placeholder), a non-lazy tag reads only srcset.
     */
    private function extractSrcsetAttribute(string $imgTag, bool $lazy = false): string
    {
        $names = $lazy ? ['data-srcset', 'data-lazy-srcset', 'srcset'] : ['srcset'];
        foreach ($names as $name) {
            if (preg_match('/(?<![\w-])' . preg_quote($name, '/') . '\s*=\s*["\x27]([^"\x27]*)["\x27]/i', $imgTag, $m)) {
                return $m[1];
            }
        }
        return '';
    }
}
