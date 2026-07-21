<?php
/**
 * Pre-warm request value object.
 *
 * Represents a batch pre-warm request: a list of source image URLs
 * and optional target widths to warm in imgproxy's cache. The service
 * builds a signed imgproxy URL for each (url × width) combination and
 * dispatches HEAD requests to trigger processing + cache fill.
 *
 * @package OXPulse\Imager\Domain\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Prewarm;

final readonly class PrewarmRequest
{
    public const MAX_URLS_PER_BATCH = 50;
    public const MAX_WIDTHS_PER_BATCH = 5;
    public const DEFAULT_WIDTHS = [0];

    /**
     * @param array<int,string>  $sourceUrls Source image URLs to warm.
     * @param array<int,int>     $widths     Target widths in px (0 = no resize).
     */
    public function __construct(
        public array $sourceUrls,
        public array $widths = self::DEFAULT_WIDTHS
    ) {}

    /**
     * Total number of (url × width) combinations to warm.
     */
    public function totalCombinations(): int
    {
        $urls = count($this->sourceUrls);
        $widths = count($this->widths);
        if ($urls === 0 || $widths === 0) {
            return 0;
        }
        return $urls * $widths;
    }
}
