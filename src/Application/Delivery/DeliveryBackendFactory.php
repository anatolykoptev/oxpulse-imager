<?php
/**
 * Delivery backend factory.
 *
 * The selection seam: picks the delivery backend based on configuration.
 * Dispatch 1 selection rule (config-presence only, no health probe):
 *
 * - imgproxy endpoint configured AND non-empty -> ImgproxyBackend
 * - otherwise (no endpoint) -> LocalBackend
 *
 * A manual override option and a health-check probe are deferred to a
 * later polish phase (see ROADMAP Phase 6 — "Selection").
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;

final class DeliveryBackendFactory
{
    /**
     * Select the delivery backend for the given configuration.
     *
     * Dispatch 3: signing may be null during early init (secrets not
     * yet saved) — in that case no backend can sign keys, so we return
     * null. Callers treat null as "delivery inactive" (UrlRewriter
     * passes through, prewarm cron skips job processing).
     *
     * @param DeliveryConfig $delivery
     * @param SigningConfig|null $signing
     * @return DeliveryBackend|null
     */
    public static function select(DeliveryConfig $delivery, ?SigningConfig $signing): ?DeliveryBackend
    {
        if ($signing === null) {
            return null;
        }

        if ($delivery->endpoint !== '') {
            return new ImgproxyBackend($delivery, $signing);
        }

        return new LocalBackend($signing);
    }
}
