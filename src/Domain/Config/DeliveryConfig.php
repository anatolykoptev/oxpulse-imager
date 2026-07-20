<?php
/**
 * Immutable delivery configuration.
 *
 * Holds non-secret delivery settings: enabled state, imgproxy endpoint,
 * allowed source URL prefixes, and output policy.
 *
 * @package OXPulse\Imager\Domain\Config
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Config;

final readonly class DeliveryConfig
{
    /**
     * @param bool $enabled Whether delivery is enabled.
     * @param string $endpoint Validated imgproxy base URL (HTTPS in production).
     * @param array<string> $allowedSources Canonical source URL prefixes with trailing path boundary.
     * @param string $outputFormat Default output format: 'auto', 'avif', 'webp', or 'jpeg'.
     * @param int $defaultQuality Default quality (1-100).
     * @param bool $devHttpOverride Explicit development-only HTTP endpoint override.
     */
    public function __construct(
        public bool $enabled,
        public string $endpoint,
        public array $allowedSources,
        public string $outputFormat = 'auto',
        public int $defaultQuality = 80,
        public bool $devHttpOverride = false
    ) {}
}
