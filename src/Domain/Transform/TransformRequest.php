<?php
/**
 * Immutable transform request.
 *
 * Represents a request to transform an image: source URL, target
 * dimensions, resize policy, output format, quality, and imgproxy-
 * native enhancement options (DPR, blur, watermark, per-format
 * quality). Does not contain signing secrets.
 *
 * @package OXPulse\Imager\Domain\Transform
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Transform;

final readonly class TransformRequest
{
    /**
     * @param string $sourceUrl Canonical source URL (for 'http' mode) or resolved filesystem path (for 'local' mode).
     * @param int $width Target width (0 = auto).
     * @param int $height Target height (0 = auto).
     * @param string $resize Resize type: 'fit', 'fill', 'auto', or '' (no resize).
     * @param string $format Output format: 'auto', 'avif', 'webp', 'jpeg', 'png'.
     * @param int $quality Quality (1-100, 0 = use default / per-format).
     * @param string $context Where the transform originated (e.g. 'content', 'srcset', 'attachment').
     * @param float $dpr Device pixel ratio multiplier (0 = disabled, 1 = no scaling, 2/3 = retina).
     * @param float $blur Blur sigma for LQIP placeholders (0 = disabled, 1-100 typical).
     * @param Watermark|null $watermark Watermark configuration, or null to skip.
     * @param array<string,int> $formatQuality Per-format quality overrides, e.g. ['avif' => 70, 'webp' => 80]. Empty = use global quality.
     * @param string $sourceMode Source addressing: 'http' (sourceUrl is a URL) or 'local' (sourceUrl is a filesystem path).
     * @param bool $extensionFormat When true, ImgproxyPathBuilder emits the
     *        format suffix as a dot-extension (`.jpg` for jpeg) instead of
     *        the imgproxy-native `@format` suffix. Used for social-safe
     *        og:image URLs so RankMath's wp_check_filetype() accepts the
     *        URL. Default false = current `@format` behaviour everywhere.
     */
    public function __construct(
        public string $sourceUrl,
        public int $width,
        public int $height,
        public string $resize = 'fit',
        public string $format = 'auto',
        public int $quality = 0,
        public string $context = 'attachment',
        public float $dpr = 0,
        public float $blur = 0,
        public ?Watermark $watermark = null,
        public array $formatQuality = [],
        public string $sourceMode = 'http',
        public bool $extensionFormat = false
    ) {
        if ($width < 0 || $width > 10000) {
            throw new \InvalidArgumentException('Width must be between 0 and 10000.');
        }
        if ($height < 0 || $height > 10000) {
            throw new \InvalidArgumentException('Height must be between 0 and 10000.');
        }
        if ($quality < 0 || $quality > 100) {
            throw new \InvalidArgumentException('Quality must be between 0 and 100.');
        }
        if ($dpr < 0 || $dpr > 8) {
            throw new \InvalidArgumentException('DPR must be between 0 and 8.');
        }
        if ($blur < 0 || $blur > 100) {
            throw new \InvalidArgumentException('Blur must be between 0 and 100.');
        }
        if (!in_array($sourceMode, ['http', 'local'], true)) {
            throw new \InvalidArgumentException('sourceMode must be "http" or "local".');
        }
        foreach ($formatQuality as $fmt => $q) {
            if (!is_string($fmt) || !in_array($fmt, ['avif', 'webp', 'jpeg', 'png'], true)) {
                throw new \InvalidArgumentException('Format quality key must be one of: avif, webp, jpeg, png.');
            }
            if (!is_int($q) || $q < 1 || $q > 100) {
                throw new \InvalidArgumentException('Format quality value must be an integer between 1 and 100.');
            }
        }
    }
}
