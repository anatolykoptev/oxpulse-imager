<?php
/**
 * Passthrough delivery backend provider — the guaranteed floor.
 *
 * Priority 0 (lowest): selected only when every higher-priority
 * provider is either not applicable or cached Down. build() returns
 * null, which makes UrlRewriter preserve the original URL — the safe
 * behavior that matches the pre-seam "no backend" path. Always
 * applicable and always Healthy so the registry never falls off the
 * end of the list.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;

final class PassthroughBackendProvider implements DeliveryBackendProvider
{
    public function id(): string
    {
        return 'passthrough';
    }

    public function priority(): int
    {
        return 0;
    }

    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool
    {
        return true;
    }

    public function health(DeliveryConfig $config): BackendHealth
    {
        return BackendHealth::Healthy;
    }

    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend
    {
        return null;
    }
}
