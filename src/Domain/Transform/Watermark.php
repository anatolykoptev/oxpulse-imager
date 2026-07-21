<?php
/**
 * Immutable watermark configuration.
 *
 * Maps to imgproxy's `wm:` (watermark) processing option:
 *   wm:%opacity:%position:%x_offset:%y_offset:%scale
 *
 * The watermark image itself is configured server-side via
 * IMGPROXY_WATERMARK_PATH / IMGPROXY_WATERMARK_URL /
 * IMGPROXY_WATERMARK_DATA — the plugin only controls placement.
 *
 * @package OXPulse\Imager\Domain\Transform
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/features/watermark
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Transform;

final readonly class Watermark
{
    /** Watermark position constants — match imgproxy's gravity types. */
    public const POS_CENTER = 'ce';
    public const POS_NORTH = 'no';
    public const POS_EAST = 'ea';
    public const POS_SOUTH = 'so';
    public const POS_WEST = 'we';
    public const POS_NORTH_EAST = 'noea';
    public const POS_NORTH_WEST = 'nowe';
    public const POS_SOUTH_EAST = 'soea';
    public const POS_SOUTH_WEST = 'sowe';
    public const POS_REPLICATE = 're';
    public const POS_SMART = 'sm';

    public const ALLOWED_POSITIONS = [
        self::POS_CENTER, self::POS_NORTH, self::POS_EAST, self::POS_SOUTH,
        self::POS_WEST, self::POS_NORTH_EAST, self::POS_NORTH_WEST,
        self::POS_SOUTH_EAST, self::POS_SOUTH_WEST, self::POS_REPLICATE,
        self::POS_SMART,
    ];

    /**
     * @param float $opacity Opacity 0-1 (0 = transparent, 1 = opaque).
     * @param string $position Position constant (see POS_* constants).
     * @param int $xOffset X offset in pixels (0 = aligned to position).
     * @param int $yOffset Y offset in pixels (0 = aligned to position).
     * @param float $scale Scale factor relative to the source image (0-1, e.g. 0.1 = 10%).
     */
    public function __construct(
        public float $opacity,
        public string $position,
        public int $xOffset = 0,
        public int $yOffset = 0,
        public float $scale = 0
    ) {
        if ($opacity < 0 || $opacity > 1) {
            throw new \InvalidArgumentException('Watermark opacity must be between 0 and 1.');
        }
        if (!in_array($position, self::ALLOWED_POSITIONS, true)) {
            throw new \InvalidArgumentException('Invalid watermark position. Use a POS_* constant.');
        }
        if ($xOffset < -10000 || $xOffset > 10000) {
            throw new \InvalidArgumentException('Watermark X offset must be between -10000 and 10000.');
        }
        if ($yOffset < -10000 || $yOffset > 10000) {
            throw new \InvalidArgumentException('Watermark Y offset must be between -10000 and 10000.');
        }
        if ($scale < 0 || $scale > 1) {
            throw new \InvalidArgumentException('Watermark scale must be between 0 and 1.');
        }
    }
}
