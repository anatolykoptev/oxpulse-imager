<?php
/**
 * Immutable transform request.
 *
 * Represents a request to transform an image: source URL, target
 * dimensions, resize policy, output format, and quality. Does not
 * contain signing secrets.
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
     * @param string $sourceUrl Canonical source URL.
     * @param int $width Target width (0 = auto).
     * @param int $height Target height (0 = auto).
     * @param string $resize Resize type: 'fit', 'fill', 'auto', or '' (no resize).
     * @param string $format Output format: 'auto', 'avif', 'webp', 'jpeg', 'png'.
     * @param int $quality Quality (1-100, 0 = use default).
     * @param string $context Where the transform originated (e.g. 'content', 'srcset', 'attachment').
     */
    public function __construct(
        public string $sourceUrl,
        public int $width,
        public int $height,
        public string $resize = 'fit',
        public string $format = 'auto',
        public int $quality = 0,
        public string $context = 'attachment'
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
    }
}
