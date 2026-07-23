<?php
/**
 * Delivery backend factory — thin composition root.
 *
 * Delegates to DeliveryBackendRegistry::default(), which builds the 3
 * core providers (imgproxy → local → passthrough), applies the
 * `oxpulse_delivery_backends` filter (the extension point), and selects
 * the best applicable, HEALTH-GATED backend: ranks by priority DESC,
 * skips providers whose cached health is Down, falls through to the
 * next best. Adding a new backend = one provider class + one
 * add_filter call — zero edits here.
 *
 * The factory stays as the thin seam so the two ServiceRegistrar call
 * sites (frontend delivery + prewarm cron) are UNCHANGED and the
 * pre-select endpoint-resolution stays as-is.
 *
 * Behavior parity with the pre-registry factory (preserved by the
 * registry's selection rules — see DeliveryBackendFactoryParityTest):
 * - signing === null → null.
 * - endpoint set + imgproxy healthy → ImgproxyBackend.
 * - endpoint empty + http source + signing → LocalBackend.
 * - endpoint empty + sourceMode === 'local' → null (passthrough).
 *
 * NEW behavior (the point of the registry): endpoint set + cached
 * imgproxy health Down → falls through to LocalBackend (if applicable)
 * else passthrough (null). No more broken URLs on a dead imgproxy.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;

final class DeliveryBackendFactory
{
    /**
     * Select the delivery backend for the given configuration.
     *
     * Delegates to the ranked, health-gated DeliveryBackendRegistry.
     * The registry memoizes one decision per instance; the factory
     * builds a fresh registry per call (mirroring the pre-registry
     * "one decision per call site" usage).
     *
     * @param DeliveryConfig $delivery
     * @param SigningConfig|null $signing
     * @return DeliveryBackend|null
     */
    public static function select(DeliveryConfig $delivery, ?SigningConfig $signing): ?DeliveryBackend
    {
        return DeliveryBackendRegistry::default($delivery, $signing)
            ->select($delivery, $signing);
    }
}

