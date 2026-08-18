<?php
/**
 * Srcset rewriter.
 *
 * Hooks into wp_calculate_image_srcset to rewrite each source URL
 * in the srcset array. Uses the width descriptor from each source
 * as the imgproxy target width.
 *
 * Before rewriting, width-descriptor candidates are trimmed to a
 * density cap relative to the rendered slot size core passes in
 * $sizeArray: candidates wider than slot * cap cannot be selected
 * by any viewport/DPR combination and only add markup weight and
 * rewrite work. The single smallest candidate above the bound is
 * kept as upscale safety (the HiDPI lesson from #124). Cap default
 * 2.0, overridable via the oxpulse_imager_srcset_density_cap
 * filter; a cap of 0 disables trimming.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class SrcsetRewriter
{
    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for wp_calculate_image_srcset.
     *
     * @param array $sources {
     *     @type array {
     *         @type string $url        The URL of the source.
     *         @type string $descriptor The descriptor (e.g. '800w' or '2x').
     *         @type int    $value      The value of the descriptor.
     *     }
     * }
     * @param array $sizeArray Array of width and height values.
     * @param string $src The src URL.
     * @param array $imageMeta The image metadata.
     * @param int $attachmentId The attachment ID.
     * @return array
     */
    /**
     * Density cap applied to the rendered slot width when trimming
     * srcset candidates. 2.0 covers every 2x display exactly; DPR-3
     * phones fall back to the upscale-safety candidate (~2.3x
     * effective density at a 330px slot), the same trade Jetpack's
     * CDN makes.
     */
    private const DEFAULT_DENSITY_CAP = 2.0;

    public function rewrite(array $sources, array $sizeArray, string $src, array $imageMeta, int $attachmentId): array
    {
        if (empty($sources)) {
            return $sources;
        }

        $sources = $this->trimToDensityCap($sources, $sizeArray, $attachmentId);

        foreach ($sources as $i => $source) {
            if (!isset($source['url'], $source['value'])) {
                continue;
            }

            $url = (string) $source['url'];
            $width = 0;

            // Use the descriptor value as target width when the
            // descriptor is 'w' (width-based srcset).
            if (isset($source['descriptor']) && $source['descriptor'] === 'w') {
                $width = (int) $source['value'];
            }

            $result = $this->rewriter->rewrite($url, $width, 0, 'srcset');

            $sources[$i]['url'] = $result->url;
        }

        return $sources;
    }

    /**
     * Drop width-descriptor candidates no display can select.
     *
     * Keeps candidates with value <= ceil(slotWidth * cap) plus the
     * single smallest candidate above that bound (upscale safety for
     * displays denser than the cap). Non-width descriptors (x-based)
     * and sources with an unknown slot size are left untouched.
     *
     * @param array $sources     Srcset sources keyed by width.
     * @param array $sizeArray   [width, height] of the rendered size.
     * @param int $attachmentId  The attachment post ID.
     * @return array
     */
    private function trimToDensityCap(array $sources, array $sizeArray, int $attachmentId): array
    {
        $slotWidth = (int) ($sizeArray[0] ?? 0);
        if ($slotWidth <= 0) {
            return $sources;
        }

        /**
         * Filters the srcset density cap.
         *
         * @param float $cap          Maximum device-pixel density to serve exactly. 0 disables trimming.
         * @param array $sizeArray    [width, height] of the rendered size.
         * @param int   $attachmentId The attachment post ID.
         */
        $cap = (float) apply_filters('oxpulse_imager_srcset_density_cap', self::DEFAULT_DENSITY_CAP, $sizeArray, $attachmentId);
        if ($cap <= 0) {
            return $sources;
        }

        $bound = (int) ceil($slotWidth * $cap);

        $upscaleSafetyKey = null;
        $upscaleSafetyValue = PHP_INT_MAX;
        foreach ($sources as $i => $source) {
            if (($source['descriptor'] ?? '') !== 'w') {
                continue;
            }
            $value = (int) ($source['value'] ?? 0);
            if ($value > $bound && $value < $upscaleSafetyValue) {
                $upscaleSafetyKey = $i;
                $upscaleSafetyValue = $value;
            }
        }

        $trimmed = [];
        foreach ($sources as $i => $source) {
            if (($source['descriptor'] ?? '') !== 'w') {
                $trimmed[$i] = $source;
                continue;
            }
            if ((int) ($source['value'] ?? 0) <= $bound || $i === $upscaleSafetyKey) {
                $trimmed[$i] = $source;
            }
        }

        return $trimmed;
    }
}
