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
        if (!str_contains($html, '<img')) {
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
            return $this->rewriteImgTags($html);
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
        $pattern = '#(<img[^>]+(?:\bsrc|\bdata-src)=["\x27])([^"\x27]+/wp-content/[^"\x27]+\.(?:'
            . self::SOURCE_EXTENSIONS
            . '))(["\x27][^>]*>)#i';

        return preg_replace_callback($pattern, function (array $m) use ($pictureSpans, $html): string {
            $prefix = $m[1];
            $url = $m[2];
            $suffix = $m[3];
            $fullTag = $m[0];

            // #43 Phase 3 — tag-level idempotency: skip already-marked.
            if ($this->hasOxpulseMarker($fullTag) || $this->hasSpNoWebpClass($fullTag)) {
                return $fullTag;
            }

            // #43 Phase 3 — skip <img> inside a <picture> element. The
            // match offset is computed via the prefix position in $html.
            // preg_replace_callback doesn't give us the offset directly,
            // so we locate the tag in the full buffer. This is a linear
            // search per match — acceptable because the number of <img>
            // matches per page is small (<100 typically).
            if ($this->isInsidePicture($fullTag, $html, $pictureSpans)) {
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
            $rewritten = $prefix . $result->url . $suffix;
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
            ) {
                // Extract the ORIGINAL srcset from the full matched tag
                // ($m[0]) — the buffer does NOT rewrite srcset, so the
                // tag's srcset is still the pre-rewrite value. Pass '' when
                // absent (the wrapper then builds single-URL <source>s).
                $originalSrcset = $this->extractSrcsetAttribute($fullTag);
                $rewritten = $this->pictureWrapper->wrap(
                    $rewritten,
                    $url,
                    $originalSrcset,
                    $width,
                    $height
                );
            }

            return $rewritten;
        }, $html);
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
     * Whether a tag occurrence falls inside any <picture> span. We
     * locate the tag's first occurrence at or after the last known
     * search cursor — this is a linear scan but bounded by the number
     * of <img> matches (small per page).
     */
    private function isInsidePicture(string $tag, string $html, array $pictureSpans): bool
    {
        if ($pictureSpans === []) {
            return false;
        }
        $pos = strpos($html, $tag);
        if ($pos === false) {
            return false;
        }
        $tagEnd = $pos + strlen($tag);
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
     * Phase 1b — extract the srcset attribute value from a full <img> tag
     * (the original matched tag $m[0]). Returns '' when the tag has no
     * srcset. The buffer does NOT rewrite srcset, so this is the ORIGINAL
     * pre-rewrite srcset — exactly what PictureElementWrapper::wrap() needs
     * to build per-format <source> srcset candidates (passing the already-
     * rewritten srcset would cause every candidate to be rejected by the
     * proxy-loop / already-rewritten guard). Handles double + single quotes.
     */
    private function extractSrcsetAttribute(string $imgTag): string
    {
        if (preg_match('/\bsrcset=["\x27]([^"\x27]*)["\x27]/i', $imgTag, $m)) {
            return $m[1];
        }
        return '';
    }
}
