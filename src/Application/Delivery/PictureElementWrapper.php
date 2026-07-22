<?php
/**
 * <picture> element wrapper.
 *
 * Wraps an eligible <img> tag in a <picture> element with one <source>
 * per output format (AVIF-first, then WebP) so a modern browser
 * negotiates AVIF client-side on standard Apache — host-agnostic and
 * CDN-safe, no Accept-header server-side negotiation required. This is
 * the Phase-1 content-path counterpart to WordPress' own performance
 * plugin's webp_uploads_wrap_image_in_picture().
 *
 * Pure service — no WordPress calls, unit-testable in isolation. The
 * runtime oxpulse_picture_enabled filter is applied by the caller
 * (ContentImgTagRewriter, the WP integration layer, where filters
 * belong), mirroring the oxpulse_buffer_rewrite_enabled filter shape.
 * wrap() is a pure "wrap this img" operation called only when enabled
 * — it does NOT re-check pictureEnabled (the single honest gate is the
 * filter in the caller; a second internal check would make a
 * force-enable filter a silent no-op).
 *
 * Hard invariants (this plugin's #1 rule is NEVER BREAK SITES):
 * - DEFAULT OFF: the caller gates on apply_filters('oxpulse_picture_enabled',
 *   $delivery->pictureEnabled) — default false. wrap() itself has no
 *   enable check (see above).
 * - Fallback-guard: if NEITHER avif NOR webp can be rewritten for the
 *   image, the <img> is returned UNCHANGED — never emit a <picture>
 *   whose only working source is the inner <img>, and never emit a
 *   <source> with a non-rewritten (original) URL.
 * - Preserve the inner <img> byte-for-byte: src, srcset, sizes, class,
 *   alt, width, height, loading, decoding, style, data-* all pass
 *   through. The ONLY mutation is prepending the data-oxpulse-picture
 *   idempotency marker right after the opening <img.
 * - Idempotency: never double-wrap. If the input already starts with
 *   <picture, or the inner <img> already carries data-oxpulse-picture,
 *   return unchanged.
 * - Escape every URL going into an attribute (htmlspecialchars
 *   ENT_QUOTES UTF-8) — WordPress plugin-check EscapeOutput flags
 *   unescaped output.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

final class PictureElementWrapper
{
    private const PICTURE_MARKER = 'data-oxpulse-picture';

    /**
     * AVIF-first source order: a modern browser that supports AVIF
     * picks the first <source> it can decode; WebP is the fallback for
     * browsers without AVIF support. This matches the WordPress
     * performance plugin's source ordering.
     */
    private const FORMATS = ['avif' => 'image/avif', 'webp' => 'image/webp'];

    public function __construct(
        private UrlRewriter $rewriter
    ) {}

    /**
     * Wrap an <img> tag in a <picture> element with per-format <source>s.
     *
     * @param string $imgTag The full <img> tag HTML (already src/srcset-
     *        rewritten by ContentImgTagRewriter). May be empty.
     * @param string $originalSrc The ORIGINAL src URL (before src
     *        rewriting) — rewriteFormat authorizes against this, not
     *        the already-rewritten imgproxy URL.
     * @param string $originalSrcset The ORIGINAL srcset value (before
     *        srcset rewriting) — used to build per-format <source>
     *        srcset candidates. When empty, the single-URL path is
     *        used (rewriteFormat on $originalSrc). Must be the
     *        pre-rewrite srcset; passing the already-rewritten srcset
     *        would cause every candidate to be rejected by the
     *        proxy-loop / already-rewritten guard.
     * @param int $width Layout width hint (0 = unknown).
     * @param int $height Layout height hint (0 = unknown).
     * @return string The <img> unchanged when no format can be rewritten
     *         (fallback-guard) or the input is empty; otherwise
     *         <picture style="display:contents"><source type="image/avif" ...><source type="image/webp" ...><img ...></picture>.
     *         The enable gate lives solely in the caller
     *         (ContentImgTagRewriter, via apply_filters('oxpulse_picture_enabled')).
     */
    public function wrap(string $imgTag, string $originalSrc, string $originalSrcset, int $width, int $height): string
    {
        if ($imgTag === '' || $originalSrc === '') {
            return $imgTag;
        }

        // Idempotency: don't double-wrap. The input to wrap() is a
        // single tag from wp_content_img_tag (per-<img> filter); if a
        // previous pass already wrapped it, the outer tag is <picture>.
        // Anchored to start so a literal "<picture" inside an alt
        // attribute can't false-trigger (it would not be at position 0).
        if (preg_match('/^\s*<picture[\s>]/i', $imgTag)) {
            return $imgTag;
        }

        // Idempotency marker: skip an <img> already carrying our
        // data-oxpulse-picture attribute (a previous wrap pass). This
        // covers the case where only the inner <img> is re-processed
        // (e.g. a later filter strips the <picture> parent but leaves
        // the marked <img>). Structured attribute match, not naive
        // substring — mirrors ContentImgTagRewriter::hasOxpulseMarker.
        if ($this->hasPictureMarker($imgTag)) {
            return $imgTag;
        }

        // Build per-format <source> elements. Only formats that
        // actually rewrote (rewritten === true) get a <source> — the
        // fallback-guard: never emit a <source> with a non-rewritten
        // (original) URL.
        $sources = $this->buildSources($imgTag, $originalSrc, $originalSrcset, $width, $height);

        // Fallback-guard: if NO format produced a <source>, return the
        // <img> unchanged — never emit a <picture> whose only working
        // source is the inner <img>.
        if ($sources === []) {
            return $imgTag;
        }

        // Stamp the idempotency marker on the inner <img> (right after
        // the opening <img, mirroring ContentImgTagRewriter::addOxpulseMarker).
        $markedImg = $this->addPictureMarker($imgTag);

        // style="display:contents" removes the <picture> box from the
        // layout tree so the inner <img> stays the flex/grid layout
        // participant — without this, wrapping <img> in <picture> inserts
        // a new box that becomes the flex/grid item and direct-child CSS
        // rules stop matching (visible layout regression on flex/grid
        // image containers when the flag is enabled).
        return '<picture style="display:contents">' . implode('', $sources) . $markedImg . '</picture>';
    }

    /**
     * Build the per-format <source> elements in AVIF-first order.
     *
     * For each format, attempt to rewrite the original src to that
     * format. When the original srcset (pre-rewrite) is non-empty, build
     * a per-format srcset by mapping each ORIGINAL candidate through
     * rewriteFormat (preserving descriptors); only rewritten candidates
     * are included (a non-rewritten original URL in a format-specific
     * <source> would serve the wrong mime type). When the original
     * srcset is empty, the <source> srcset is the single rewritten URL.
     *
     * The per-format srcset is built from $originalSrcset (the PRE-rewrite
     * srcset), NOT from the srcset extracted out of $imgTag — by the time
     * wrap() is called, ContentImgTagRewriter has already rewritten the
     * <img>'s srcset to delivery URLs (imgproxy / cache), which
     * rewriteFormat would reject via the proxy-loop / already-rewritten
     * guard. This mirrors the single-URL path using $originalSrc.
     *
     * The inner <img>'s sizes attribute (when present) is copied onto
     * each <source> so the browser applies the same responsive sizing.
     * sizes is NOT rewritten by ContentImgTagRewriter, so extracting it
     * from $imgTag is safe.
     *
     * @return list<string> <source> HTML strings, AVIF-first. Empty
     *         when no format could be rewritten (fallback-guard).
     */
    private function buildSources(string $imgTag, string $originalSrc, string $originalSrcset, int $width, int $height): array
    {
        $innerSizes = $this->extractAttribute($imgTag, 'sizes');

        $sources = [];
        foreach (self::FORMATS as $format => $mimeType) {
            if ($originalSrcset !== '') {
                $srcset = $this->buildPerFormatSrcset($originalSrcset, $format);
                if ($srcset === '') {
                    // No candidate rewrote for this format — skip the
                    // <source> entirely (don't emit a <source> with
                    // only original-URL candidates).
                    continue;
                }
            } else {
                $result = $this->rewriter->rewriteFormat($originalSrc, $width, $height, $format, 'picture');
                if (!$result->rewritten) {
                    continue;
                }
                $srcset = htmlspecialchars($result->url, ENT_QUOTES, 'UTF-8');
            }

            $source = '<source type="' . $mimeType . '" srcset="' . $srcset . '"';
            if ($innerSizes !== '') {
                $source .= ' sizes="' . htmlspecialchars($innerSizes, ENT_QUOTES, 'UTF-8') . '"';
            }
            $source .= '>';
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * Build a per-format srcset by mapping each srcset candidate
     * ("URL descriptor") through rewriteFormat with the candidate's
     * w-descriptor width. Only rewritten candidates are included;
     * non-rewritten candidates are dropped (they would serve the
     * original format under a format-specific <source> mime type).
     *
     * Reuses the srcset-candidate parsing shape from
     * ContentImgTagRewriter::rewriteSrcsetCandidate() — split on the
     * first space, extract the w-descriptor width, rewrite, rejoin.
     *
     * @return string The per-format srcset value (already HTML-escaped,
     *         ready for an attribute), or '' when no candidate rewrote.
     */
    private function buildPerFormatSrcset(string $srcset, string $format): string
    {
        $candidates = explode(', ', $srcset);
        $rewritten = [];

        foreach ($candidates as $candidate) {
            $parts = explode(' ', $candidate, 2);
            $url = trim($parts[0]);
            $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';

            // Extract width from "Nw" descriptor for imgproxy resize.
            $w = 0;
            if (preg_match('/(\d+)w/', $descriptor, $wMatch)) {
                $w = (int) $wMatch[1];
            }

            $result = $this->rewriter->rewriteFormat($url, $w, 0, $format, 'picture');
            if (!$result->rewritten) {
                continue;
            }

            // Escape the ASSEMBLED candidate (url + descriptor) once.
            // Previously the URL was escaped but the $descriptor (from
            // the original srcset) was concatenated raw into the srcset
            // attribute value — not exploitable (the upstream capture
            // regex strips quotes) but a plugin-check EscapeOutput gap.
            // The ', ' join between candidates has no special chars and
            // stays raw.
            $rewritten[] = htmlspecialchars($result->url . $descriptor, ENT_QUOTES, 'UTF-8');
        }

        return implode(', ', $rewritten);
    }

    /**
     * Whether the <img> tag already carries our data-oxpulse-picture
     * idempotency marker. Structured attribute regex (not naive
     * substring) — mirrors ContentImgTagRewriter::hasOxpulseMarker.
     */
    private function hasPictureMarker(string $imgTag): bool
    {
        return (bool) preg_match(
            '/\b' . preg_quote(self::PICTURE_MARKER, '/') . '\s*=\s*["\'][^"\']*["\']/i',
            $imgTag
        );
    }

    /**
     * Add the data-oxpulse-picture="1" marker right after the opening
     * <img, mirroring ContentImgTagRewriter::addOxpulseMarker. The
     * hasPictureMarker guard above ensures this runs only when the
     * marker is not already present.
     */
    private function addPictureMarker(string $imgTag): string
    {
        return preg_replace(
            '/^(<img\b)/i',
            '$1 ' . self::PICTURE_MARKER . '="1"',
            $imgTag,
            1,
        );
    }

    /**
     * Extract an attribute value from an <img> tag. Returns '' when
     * the attribute is absent. Handles double-quoted values only
     * (WordPress core emits double-quoted attributes).
     */
    private function extractAttribute(string $imgTag, string $attr): string
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '=["\']([^"\']*)["\']/i', $imgTag, $m)) {
            return $m[1];
        }
        return '';
    }
}
