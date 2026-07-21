<?php
/**
 * LQIP placeholder builder.
 *
 * Builds Low-Quality Image Placeholder attributes for `<img>` tags.
 * Two strategies:
 *
 * 1. URL-based: generates a tiny blurred imgproxy URL (blur:1, 20px)
 *    and emits it as `data-placeholder`. A small inline script or the
 *    browser's native loading pipeline swaps it for the full image.
 *    This is the Cloudinary/Imgix approach.
 *
 * 2. SVG fallback: when the imgproxy URL cannot be generated (disabled,
 *    unauthorized source, generation error), emits a minimal inline
 *    SVG data URI as a neutral placeholder so layout shift is still
 *    prevented even if imgproxy is unreachable.
 *
 * The builder does NOT modify the `src` attribute — it only adds
 * `data-placeholder` alongside it. The actual swap is handled by the
 * browser (native `loading="lazy"` + `decoding="async"`) or by an
 * optional inline script emitted by ContentImgTagRewriter.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

final class LqipPlaceholderBuilder
{
    public function __construct(
        private UrlRewriter $rewriter
    ) {}

    /**
     * Build a placeholder URL for a source image.
     *
     * Returns the imgproxy LQIP URL when available, or an inline SVG
     * data URI as a fallback. Returns null when the source URL is empty.
     *
     * @param string $sourceUrl Original image URL.
     * @param int $width Layout width (for SVG fallback aspect ratio).
     * @param int $height Layout height (for SVG fallback aspect ratio).
     * @return string|null Placeholder URL or data URI, or null on empty input.
     */
    public function build(string $sourceUrl, int $width = 0, int $height = 0): ?string
    {
        if ($sourceUrl === '') {
            return null;
        }

        $result = $this->rewriter->rewriteLqip($sourceUrl);

        if ($result->rewritten) {
            return $result->url;
        }

        // Fallback: inline SVG data URI. Neutral gray, preserves aspect
        // ratio when dimensions are known, 1x1 when not.
        return $this->svgPlaceholder($width, $height);
    }

    /**
     * Build a minimal inline SVG placeholder as a data URI.
     *
     * The SVG is a single gray rectangle. When width/height are known,
     * they're used as the viewBox to preserve aspect ratio; otherwise
     * a 1x1 square is emitted (the browser will stretch it to the
     * img element's dimensions).
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function svgPlaceholder(int $width, int $height): string
    {
        $w = $width > 0 ? $width : 1;
        $h = $height > 0 ? $height : 1;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">' .
            '<rect width="100%%" height="100%%" fill="#f0f0f0"/>' .
            '</svg>',
            $w,
            $h,
            $w,
            $h
        );

        // URL-encode the SVG for use in a data: URI.
        return 'data:image/svg+xml,' . rawurlencode($svg);
    }
}
