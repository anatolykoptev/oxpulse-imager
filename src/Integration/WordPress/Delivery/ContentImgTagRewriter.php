<?php
/**
 * Content <img> tag rewriter.
 *
 * Hooks into wp_content_img_tag (WP 5.5+) to rewrite the src and
 * srcset attributes of <img> tags in post content. Only rewrites
 * images whose source URL is in the configured allowlist.
 *
 * Phase 5.1 enhancements:
 * - LQIP placeholders: adds `data-placeholder` with a tiny blurred
 *   imgproxy URL (or inline SVG fallback) for CLS reduction.
 * - DPR-aware srcset: for images that lack srcset but have width,
 *   generates 1x/2x/3x x-descriptor variants via imgproxy dpr: option.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;

final class ContentImgTagRewriter
{
    private UrlRewriter $rewriter;
    private ?LqipPlaceholderBuilder $lqipBuilder;
    private DeliveryConfig $delivery;

    public function __construct(
        UrlRewriter $rewriter,
        DeliveryConfig $delivery,
        ?LqipPlaceholderBuilder $lqipBuilder = null
    ) {
        $this->rewriter = $rewriter;
        $this->delivery = $delivery;
        $this->lqipBuilder = $lqipBuilder;
    }

    /**
     * Filter callback for wp_content_img_tag.
     *
     * @param string $filteredImage The full <img> tag HTML.
     * @param string $context The context (e.g. 'the_content', 'the_excerpt').
     * @param int $attachmentId The attachment ID (0 for external images).
     * @return string
     */
    public function rewrite(string $filteredImage, string $context, int $attachmentId): string
    {
        if ($filteredImage === '') {
            return $filteredImage;
        }

        // #43 Phase 3 — tag-level idempotency guard. Skip an <img> that
        // (a) already carries our data-oxpulse marker (a previous pass
        // already rewrote it), or (b) carries ShortPixel's sp-no-webp
        // class (another plugin already handled this tag). The <picture>
        // parent check does not apply here — wp_content_img_tag fires per
        // individual <img> tag, so the parent is not visible; BufferRewriter
        // handles the <picture> case at the buffer level.
        if ($this->hasOxpulseMarker($filteredImage) || $this->hasSpNoWebpClass($filteredImage)) {
            return $filteredImage;
        }

        // Capture the original src BEFORE any rewriting — LQIP and DPR
        // generation need to authorize against the original URL, not the
        // rewritten imgproxy URL (which the SourcePolicy won't allow).
        $originalSrc = '';
        if (preg_match('/\bsrc=["\']([^"\']+)["\']/', $filteredImage, $srcMatch)) {
            $originalSrc = $srcMatch[1];
        }
        $originalWidth = $this->extractAttribute($filteredImage, 'width');
        $originalHeight = $this->extractAttribute($filteredImage, 'height');

        // Rewrite src attribute.
        $filteredImage = $this->rewriteSrcAttribute($filteredImage);

        // Rewrite srcset attribute (existing srcset → rewrite URLs).
        $filteredImage = $this->rewriteSrcsetAttribute($filteredImage);

        // Phase 5.1: Add LQIP placeholder.
        if ($this->delivery->lqipEnabled && $this->lqipBuilder !== null) {
            $filteredImage = $this->addLqipPlaceholder($filteredImage, $originalSrc, $originalWidth, $originalHeight);
        }

        // Phase 5.1: Generate DPR-aware srcset for images without one.
        if ($this->delivery->dprEnabled && !empty($this->delivery->dprVariants)) {
            $filteredImage = $this->addDprSrcset($filteredImage, $originalSrc, $originalWidth);
        }

        // #43 Phase 3: stamp a data-oxpulse="1" marker so a later pass
        // (ours or another plugin's) can skip this tag as already-handled.
        // Only add when we actually rewrote something (the src changed).
        $filteredImage = $this->addOxpulseMarker($filteredImage, $originalSrc);

        return $filteredImage;
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
     * class (ShortPixel's already-handled marker). Class attribute match
     * is word-boundary aware so "sp-no-webp-extra" does not match.
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
     * #43 Phase 3 — add data-oxpulse="1" marker to a rewritten <img>.
     * Only adds the attribute when the src actually changed (we rewrote
     * something). Inserted right after the opening <img to keep it
     * visible to a later regex pass.
     */
    private function addOxpulseMarker(string $imgTag, string $originalSrc): string
    {
        // Did the src actually change? If no rewrite happened, don't stamp.
        if ($originalSrc === '') {
            return $imgTag;
        }
        if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $imgTag, $m)) {
            return $imgTag;
        }
        if ($m[1] === $originalSrc) {
            return $imgTag;
        }

        // Insert data-oxpulse="1" right after the opening <img .
        return preg_replace(
            '/^(<img\b)/i',
            '$1 data-oxpulse="1"',
            $imgTag,
            1,
        );
    }

    private function rewriteSrcAttribute(string $imgTag): string
    {
        if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $imgTag, $matches)) {
            return $imgTag;
        }

        $originalSrc = $matches[1];
        $result = $this->rewriter->rewrite($originalSrc, 0, 0, 'content');

        if (!$result->rewritten) {
            return $imgTag;
        }

        // Extract width/height hints from the img tag if present, and
        // re-rewrite with dimensions for better imgproxy output.
        $width = $this->extractAttribute($imgTag, 'width');
        $height = $this->extractAttribute($imgTag, 'height');
        if ($width > 0 || $height > 0) {
            $sized = $this->rewriter->rewrite($originalSrc, $width, $height, 'content');
            if ($sized->rewritten) {
                return str_replace($originalSrc, $sized->url, $imgTag);
            }
        }

        return str_replace($originalSrc, $result->url, $imgTag);
    }

    private function rewriteSrcsetAttribute(string $imgTag): string
    {
        if (!preg_match('/\bsrcset=["\']([^"\']+)["\']/', $imgTag, $matches)) {
            return $imgTag;
        }

        $originalSrcset = $matches[1];
        $candidates = explode(', ', $originalSrcset);
        $rewritten = [];

        foreach ($candidates as $candidate) {
            $rewritten[] = $this->rewriteSrcsetCandidate($candidate);
        }

        $newSrcset = implode(', ', $rewritten);
        return str_replace($originalSrcset, $newSrcset, $imgTag);
    }

    private function rewriteSrcsetCandidate(string $candidate): string
    {
        // A srcset candidate is "URL descriptor" where descriptor is
        // like "800w" or "2x". Split on the first space.
        $parts = explode(' ', $candidate, 2);
        $url = trim($parts[0]);
        $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';

        // Extract width from "Nw" descriptor for imgproxy resize.
        $width = 0;
        if (preg_match('/(\d+)w/', $descriptor, $wMatch)) {
            $width = (int) $wMatch[1];
        }

        $result = $this->rewriter->rewrite($url, $width, 0, 'srcset');

        return $result->url . $descriptor;
    }

    /**
     * Add a data-placeholder attribute with the LQIP URL.
     *
     * Only adds the attribute if the img tag doesn't already have one
     * (respecting existing placeholders from other plugins/themes).
     *
     * @param string $imgTag The current (possibly already src-rewritten) img tag.
     * @param string $originalSrc The original src URL before src rewriting.
     * @param int $width Layout width hint.
     * @param int $height Layout height hint.
     */
    private function addLqipPlaceholder(string $imgTag, string $originalSrc, int $width, int $height): string
    {
        // Don't overwrite an existing placeholder.
        if (preg_match('/\bdata-placeholder=["\']/', $imgTag)) {
            return $imgTag;
        }

        if ($originalSrc === '') {
            return $imgTag;
        }

        // Build LQIP from the ORIGINAL src — rewriteLqip authorizes via
        // SourcePolicy, which would reject the already-rewritten imgproxy URL.
        $placeholder = $this->lqipBuilder->build($originalSrc, $width, $height);
        if ($placeholder === null) {
            return $imgTag;
        }

        // Insert data-placeholder before the closing > or before src.
        // Safest: insert right after the opening <img.
        if (preg_match('/(<img[^>]*?)(\s\/?>)/', $imgTag, $tagMatch, PREG_OFFSET_CAPTURE)) {
            $insertPos = $tagMatch[2][1];
            $attr = ' data-placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"';
            return substr($imgTag, 0, $insertPos) . $attr . substr($imgTag, $insertPos);
        }

        return $imgTag;
    }

    /**
     * Generate a DPR-aware srcset for images that lack one.
     *
     * Only acts on <img> tags with a src and width but no srcset.
     * Emits x-descriptor variants (1x/2x/3x) using imgproxy's dpr:
     * option. Images that already have srcset are left alone (their
     * w-descriptors already handle DPR via the browser).
     *
     * @param string $imgTag The current (possibly already src-rewritten) img tag.
     * @param string $originalSrc The original src URL before src rewriting.
     * @param int $width Layout width in CSS pixels.
     */
    private function addDprSrcset(string $imgTag, string $originalSrc, int $width): string
    {
        // Skip if already has srcset.
        if (preg_match('/\bsrcset=["\']/', $imgTag)) {
            return $imgTag;
        }

        if ($originalSrc === '' || $width <= 0) {
            return $imgTag;
        }

        // Build DPR variants from the ORIGINAL src — rewriteDpr authorizes
        // via SourcePolicy, which would reject the already-rewritten imgproxy URL.
        $variants = [];
        foreach ($this->delivery->dprVariants as $dpr) {
            $result = $this->rewriter->rewriteDpr($originalSrc, $width, (float) $dpr, 'srcset_dpr');
            if ($result->rewritten) {
                $variants[] = $result->url . ' ' . $dpr . 'x';
            }
        }

        if (empty($variants)) {
            return $imgTag;
        }

        $srcset = implode(', ', $variants);
        $attr = ' srcset="' . htmlspecialchars($srcset, ENT_QUOTES, 'UTF-8') . '"';

        // Insert srcset right after the src attribute.
        if (preg_match('/(\bsrc=["\'][^"\']*["\'])/', $imgTag, $srcMatch, PREG_OFFSET_CAPTURE)) {
            $insertPos = $srcMatch[0][1] + strlen($srcMatch[0][0]);
            return substr($imgTag, 0, $insertPos) . $attr . substr($imgTag, $insertPos);
        }

        return $imgTag;
    }

    private function extractAttribute(string $imgTag, string $attr): int
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '=["\'](\d+)["\']/', $imgTag, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}
