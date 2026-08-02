<?php
/**
 * Srcset rewriter.
 *
 * Hooks into wp_calculate_image_srcset to rewrite each source URL
 * in the srcset array. Uses the width descriptor from each source
 * as the imgproxy target width.
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
    public function rewrite(array $sources, array $sizeArray, string $src, array $imageMeta, int $attachmentId): array
    {
        if (empty($sources)) {
            return $sources;
        }

        // Thin the candidate ladder BEFORE proxying so dropped candidates
        // are never signed or fetched. WordPress emits one srcset candidate
        // per registered intermediate size; a site with 14 registered sizes
        // (core 6 + 8 theme crops) produces up to 10 candidates per image,
        // multiplying the derived-image surface ~10x against a fixed cache.
        // Thinning by relative width spacing collapses clusters like
        // 860/880/900/928/960 (within 12% of each other) into a single rung.
        $sources = $this->thinCandidates($sources, $sizeArray);

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
     * Thin the srcset candidate ladder by relative width spacing.
     *
     * WordPress emits one candidate per registered intermediate size. A
     * site with 14 registered sizes produces up to 10 candidates per
     * image, multiplying the derived-image surface ~10x against a fixed
     * imgproxy result cache. Thinning collapses near-identical widths
     * (e.g. 860/880/900/928/960 — within 12% of each other) into a
     * small, well-spaced set that a browser gains nothing from losing.
     *
     * Rules:
     * - Walk candidates ascending by width. Keep one only if its width
     *   is at least `step`× the last kept width (default 1.4 — a standard
     *   responsive step that turns a 10-entry ladder into ~4-5).
     * - Always keep the smallest candidate (narrow mobile slots) and
     *   the largest (HiDPI desktop pick), regardless of spacing.
     * - Always keep the candidate matching the src width (sizeArray[0])
     *   if present, so the default and the ladder agree.
     * - If the largest would sit too close to the last kept intermediate,
     *   replace that intermediate with the largest rather than keeping
     *   both (avoids a tight pair at the top of the ladder).
     * - Never thin below 2 candidates: if thinning would leave a single
     *   candidate, return the original ladder. WordPress core's
     *   wp_calculate_image_srcset returns false for < 2 sources, so a
     *   single-candidate srcset is never useful — the original ladder
     *   preserves the existing behaviour.
     * - Non-width descriptors (e.g. 'x' for DPR) skip thinning entirely
     *   — the ladder is already small and thinning by width is meaningless.
     *
     * The step is configurable via the `oxpulse_srcset_thin_step` filter
     * (mirror of the existing `oxpulse_*` filter convention). A site with
     * a different registered-size profile may want a different step.
     * Setting step <= 1.0 disables thinning (returns the original ladder).
     *
     * @param array $sources   Candidate array from wp_calculate_image_srcset.
     * @param array $sizeArray Display dimensions [width, height] — used to
     *                         identify the src-width candidate to preserve.
     * @return array Thinned candidate array (same key order as input).
     */
    private function thinCandidates(array $sources, array $sizeArray): array
    {
        // Collect width-descriptor candidates. If any source uses a
        // non-width descriptor (e.g. 'x' for DPR), skip thinning — the
        // ladder is already small and width-spacing is meaningless.
        $widths = [];
        foreach ($sources as $key => $source) {
            if (!isset($source['descriptor'], $source['value'])) {
                continue;
            }
            if ($source['descriptor'] !== 'w') {
                return $sources;
            }
            $widths[$key] = (int) $source['value'];
        }

        // Nothing to thin with fewer than 3 width-descriptor candidates.
        if (count($widths) < 3) {
            return $sources;
        }

        $step = (float) apply_filters('oxpulse_srcset_thin_step', 1.4);
        if ($step <= 1.0) {
            return $sources;
        }

        // Sort by width ascending, preserving original keys.
        asort($widths);
        $sortedKeys = array_keys($widths);

        $srcWidth = isset($sizeArray[0]) ? (int) $sizeArray[0] : 0;

        $smallestKey = $sortedKeys[0];
        $largestKey  = $sortedKeys[count($sortedKeys) - 1];

        $keptKeys = [$smallestKey];
        $lastWidth = $widths[$smallestKey];

        // Walk from the 2nd candidate to the 2nd-to-last. The largest
        // is handled separately after the loop to avoid tight pairs.
        $intermediateKeys = array_slice($sortedKeys, 1, -1);

        foreach ($intermediateKeys as $key) {
            $w = $widths[$key];
            $isSrc = $srcWidth > 0 && $w === $srcWidth;

            if ($isSrc || $w >= $lastWidth * $step) {
                $keptKeys[] = $key;
                $lastWidth = $w;
            }
        }

        // Handle the largest candidate. Always keep it, but if it sits
        // too close to the last kept intermediate, replace that
        // intermediate with the largest rather than keeping both.
        $largestWidth = $widths[$largestKey];
        if ($largestWidth >= $lastWidth * $step) {
            $keptKeys[] = $largestKey;
        } else {
            // Largest too close to the last kept. Replace that
            // intermediate with the largest — but NEVER drop the
            // src-matching candidate: the ladder must always contain the
            // width the `src` attribute resolves to, otherwise a browser
            // using srcset can no longer pick the size the layout asked
            // for. Also keep the smallest when no intermediate survived
            // (smallest + largest is the minimum viable srcset).
            $lastIsSrc = $srcWidth > 0 && $lastWidth === $srcWidth;
            if ($lastWidth !== $widths[$smallestKey] && !$lastIsSrc) {
                array_pop($keptKeys);
            }
            $keptKeys[] = $largestKey;
        }

        // Never thin below 2 candidates — return the original ladder
        // so WordPress core's count < 2 check handles the edge case.
        if (count($keptKeys) < 2) {
            return $sources;
        }

        // Rebuild preserving original key order.
        $keptSet = array_flip($keptKeys);
        $thinned = [];
        foreach ($sources as $key => $source) {
            if (isset($keptSet[$key])) {
                $thinned[$key] = $source;
            }
        }
        return $thinned;
    }
}
