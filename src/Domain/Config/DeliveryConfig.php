<?php
/**
 * Immutable delivery configuration.
 *
 * Holds non-secret delivery settings: enabled state, imgproxy endpoint,
 * allowed source URL prefixes, output policy, and imgproxy-native
 * enhancement options (LQIP placeholders, DPR variants, watermark,
 * per-format quality).
 *
 * @package OXPulse\Imager\Domain\Config
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Config;

use OXPulse\Imager\Domain\Transform\Watermark;

final readonly class DeliveryConfig
{
    /**
     * @param bool $enabled Whether delivery is enabled.
     * @param string $endpoint Validated imgproxy base URL (HTTPS in production).
     *        May be relative (e.g. '/imgproxy') for same-host reverse-proxy setups.
     * @param array<string> $allowedSources Canonical source URL prefixes with trailing path boundary.
     * @param string $outputFormat Default output format: 'auto', 'avif', 'webp', or 'jpeg'.
     * @param int $defaultQuality Default quality (1-100). Used when formatQuality is empty.
     * @param bool $devHttpOverride Explicit development-only HTTP endpoint override.
     * @param bool $lqipEnabled Whether to emit LQIP placeholder URLs (imgproxy blur:1).
     * @param float $lqipBlur Blur sigma for LQIP placeholders (typically 1-10).
     * @param bool $dprEnabled Whether to emit DPR-aware srcset variants (1x/2x/3x).
     * @param array<int> $dprVariants DPR multipliers to emit, e.g. [1, 2, 3]. Empty = disabled.
     * @param Watermark|null $watermark Watermark configuration, or null to skip.
     * @param array<string,int> $formatQuality Per-format quality overrides, e.g. ['avif' => 70, 'webp' => 80]. Empty = use defaultQuality.
     * @param string $sourceMode Source addressing mode: 'http' (imgproxy fetches via HTTP)
     *        or 'local' (imgproxy reads from filesystem via local:// transport).
     * @param string $localBasePath Filesystem root for 'local' source mode (e.g. ABSPATH).
     *        Must be an absolute, existing, readable directory. Empty when sourceMode='http'.
     * @param bool $bufferRewritingEnabled Whether to register ob_start buffer rewriting
     *        for theme-hardcoded <img> tags. Default false — opt-in for themes (e.g. Foxiz)
     *        that bypass wp_content_img_tag.
     * @param bool $rankMathCompatibility Whether to register the RankMath og:image
     *        compatibility filter (restores direct URLs in OpenGraph/Twitter meta tags
     *        so RankMath's wp_check_filetype() validation doesn't drop them). Default true.
     * @param int $saveDataQualityReduction Points to subtract from image quality when
     *        the browser sends Save-Data: on (mobile data saver). 0 disables. Default 15.
     * @param array<int, int|array<string,int>> $sizeQualityTiers Map of
     *        maxWidth => quality for size-based quality tiers. Two forms:
     *        - Simple: `int` quality applied to all formats (emits `q:`).
     *          Example: `[400 => 75, 800 => 70]`
     *        - Per-format: `array<string,int>` map of format => quality
     *          (emits `fq:`). Example:
     *          `[400 => ['avif' => 55, 'webp' => 60, 'jpeg' => 70]]`
     *        Tiers are matched smallest-first: the first tier whose
     *        maxWidth >= requested width wins. Empty = disabled (use
     *        defaultQuality for all sizes). Mixed forms are allowed
     *        (some tiers int, some per-format). Default empty (disabled).
     */
    public function __construct(
        public bool $enabled,
        public string $endpoint,
        public array $allowedSources,
        public string $outputFormat = 'auto',
        public int $defaultQuality = 80,
        public bool $devHttpOverride = false,
        public bool $lqipEnabled = false,
        public float $lqipBlur = 1,
        public bool $dprEnabled = false,
        public array $dprVariants = [],
        public ?Watermark $watermark = null,
        public array $formatQuality = [],
        public string $sourceMode = 'http',
        public string $localBasePath = '',
        public bool $bufferRewritingEnabled = false,
        public bool $rankMathCompatibility = true,
        public int $saveDataQualityReduction = 15,
        public array $sizeQualityTiers = []
    ) {
        if (!in_array($sourceMode, ['http', 'local'], true)) {
            throw new \InvalidArgumentException('sourceMode must be "http" or "local".');
        }
        if ($saveDataQualityReduction < 0 || $saveDataQualityReduction > 50) {
            throw new \InvalidArgumentException('saveDataQualityReduction must be between 0 and 50.');
        }
        foreach ($sizeQualityTiers as $maxWidth => $quality) {
            if (!is_int($maxWidth) || $maxWidth <= 0) {
                throw new \InvalidArgumentException('sizeQualityTiers keys must be positive integers.');
            }
            if (is_int($quality)) {
                if ($quality < 1 || $quality > 100) {
                    throw new \InvalidArgumentException('sizeQualityTiers int values must be 1-100.');
                }
            } elseif (is_array($quality)) {
                if (empty($quality)) {
                    throw new \InvalidArgumentException('sizeQualityTiers per-format values must not be empty.');
                }
                foreach ($quality as $fmt => $q) {
                    if (!is_string($fmt) || $fmt === '') {
                        throw new \InvalidArgumentException('sizeQualityTiers per-format keys must be non-empty strings.');
                    }
                    if (!is_int($q) || $q < 1 || $q > 100) {
                        throw new \InvalidArgumentException('sizeQualityTiers per-format values must be integers 1-100.');
                    }
                }
            } else {
                throw new \InvalidArgumentException('sizeQualityTiers values must be int or array<string,int>.');
            }
        }
    }

    /**
     * Return a copy with a resolved endpoint.
     *
     * Used at the WordPress-infrastructure boundary to swap a relative
     * endpoint (e.g. '/imgproxy') for an absolute one
     * (e.g. 'https://example.test/imgproxy') before injection into the
     * URL generator, so filtered image URLs are always absolute.
     */
    public function withEndpoint(string $endpoint): self
    {
        return new self(
            enabled: $this->enabled,
            endpoint: $endpoint,
            allowedSources: $this->allowedSources,
            outputFormat: $this->outputFormat,
            defaultQuality: $this->defaultQuality,
            devHttpOverride: $this->devHttpOverride,
            lqipEnabled: $this->lqipEnabled,
            lqipBlur: $this->lqipBlur,
            dprEnabled: $this->dprEnabled,
            dprVariants: $this->dprVariants,
            watermark: $this->watermark,
            formatQuality: $this->formatQuality,
            sourceMode: $this->sourceMode,
            localBasePath: $this->localBasePath,
            bufferRewritingEnabled: $this->bufferRewritingEnabled,
            rankMathCompatibility: $this->rankMathCompatibility,
            saveDataQualityReduction: $this->saveDataQualityReduction,
            sizeQualityTiers: $this->sizeQualityTiers,
        );
    }
}
