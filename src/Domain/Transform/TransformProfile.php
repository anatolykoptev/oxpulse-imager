<?php
/**
 * Transform profile.
 *
 * Maps a TransformRequest to imgproxy processing options. Deterministic:
 * the same request always produces the same option string.
 *
 * Option order follows imgproxy convention: resize → quality →
 * format_quality → dpr → blur → watermark. This is not required by
 * imgproxy (options are order-independent) but keeps generated URLs
 * stable and readable.
 *
 * @package OXPulse\Imager\Domain\Transform
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/usage/processing
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Transform;

final class TransformProfile
{
    /**
     * Build deterministic imgproxy processing options from a transform request.
     *
     * @param TransformRequest $request
     * @return string imgproxy options string (e.g. "rs:fit:800:0/fq:avif:70:webp:80/dpr:2/blur:1")
     */
    public function buildOptions(TransformRequest $request): string
    {
        $parts = [];

        // Resize option.
        if ($request->width > 0 || $request->height > 0) {
            $resizeType = $request->resize !== '' ? $request->resize : 'fit';
            $parts[] = sprintf('rs:%s:%d:%d', $resizeType, $request->width, $request->height);
        }

        // Global quality option. Skipped when per-format quality is set —
        // in that case fq: is emitted instead and the global q: would be
        // redundant (imgproxy uses fq values first, then falls back to q).
        if ($request->quality > 0 && empty($request->formatQuality)) {
            $parts[] = 'q:' . $request->quality;
        }

        // Per-format quality (imgproxy `fq:` option).
        // Example: fq:avif:70:webp:80
        if (!empty($request->formatQuality)) {
            $parts[] = $this->formatQualityOption($request->formatQuality);
        }

        // DPR multiplier for retina/HiDPI displays.
        if ($request->dpr > 0) {
            $parts[] = $this->dprOption($request->dpr);
        }

        // Blur (used for LQIP placeholders).
        if ($request->blur > 0) {
            $parts[] = $this->blurOption($request->blur);
        }

        // Watermark.
        if ($request->watermark !== null) {
            $parts[] = $this->watermarkOption($request->watermark);
        }

        // Format is specified as @extension in the path builder, not as
        // a processing option. This avoids redundant format specification.

        return implode('/', $parts);
    }

    /**
     * Build the fq: (format_quality) option string.
     *
     * imgproxy syntax: fq:%format1:%quality1:%format2:%quality2:...
     * The order of formats is deterministic (sorted alphabetically) so
     * the same input always produces the same URL.
     *
     * @param array<string,int> $formatQuality
     */
    private function formatQualityOption(array $formatQuality): string
    {
        // Sort keys alphabetically for deterministic output.
        $formats = array_keys($formatQuality);
        sort($formats);

        $segments = ['fq'];
        foreach ($formats as $fmt) {
            $segments[] = $fmt;
            $segments[] = (string) $formatQuality[$fmt];
        }

        return implode(':', $segments);
    }

    /**
     * Build the dpr: option. imgproxy accepts integers or floats.
     * We emit the minimal representation (no trailing .0).
     */
    private function dprOption(float $dpr): string
    {
        $value = $this->formatFloat($dpr);
        return 'dpr:' . $value;
    }

    /**
     * Build the blur: option.
     */
    private function blurOption(float $blur): string
    {
        return 'blur:' . $this->formatFloat($blur);
    }

    /**
     * Build the wm: (watermark) option string.
     *
     * imgproxy syntax: wm:%opacity:%position:%x_offset:%y_offset:%scale
     * Opacity is 0-100 in the URL (we store 0-1 internally).
     */
    private function watermarkOption(Watermark $wm): string
    {
        $opacityPct = (int) round($wm->opacity * 100);
        return sprintf(
            'wm:%d:%s:%d:%d:%s',
            $opacityPct,
            $wm->position,
            $wm->xOffset,
            $wm->yOffset,
            $this->formatFloat($wm->scale)
        );
    }

    /**
     * Format a float as a minimal string: 2 → "2", 1.5 → "1.5", 0.1 → "0.1".
     * Avoids "2.0" which is needlessly verbose in URLs.
     */
    private function formatFloat(float $value): string
    {
        // Cast to string, then strip trailing zeros and trailing dot.
        $str = (string) $value;
        if (str_contains($str, '.')) {
            $str = rtrim($str, '0.');
            $str = $str === '' ? '0' : $str;
        }
        return $str;
    }
}
