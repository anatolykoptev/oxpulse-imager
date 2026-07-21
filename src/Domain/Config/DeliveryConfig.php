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
     * @param array<int,int> $sizeQualityTiers Map of maxWidth => quality for size-based
     *        quality tiers. When the requested width <= a tier's maxWidth, that tier's
     *        quality is used. Tiers are matched smallest-first. Empty = disabled (use
     *        defaultQuality for all sizes). Example: [400 => 75, 800 => 70, 1200 => 65]
     *        means widths ≤400 get q75, ≤800 get q70, ≤1200 get q65, >1200 get
     *        defaultQuality. Default empty (disabled).
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
            if (!is_int($quality) || $quality < 1 || $quality > 100) {
                throw new \InvalidArgumentException('sizeQualityTiers values must be integers 1-100.');
            }
        }
    }
}
