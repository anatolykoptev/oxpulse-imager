<?php
/**
 * Backend health state.
 *
 * The tri-state used by DeliveryBackendRegistry to rank and skip
 * providers during selection:
 *
 * - Healthy:  the backend is fully operational.
 * - Degraded: the backend is partially impaired but still USABLE —
 *             selection treats Degraded as selectable (the next-best
 *             provider is NOT preferred over a Degraded one).
 * - Down:     the backend is unavailable — selection SKIPS it and
 *             falls through to the next applicable provider.
 *
 * `health()` on a DeliveryBackendProvider MUST be front-end-safe:
 * it reads a cached probe result only and performs ZERO network I/O.
 * The live probe that WRITES the cache runs at write-time (admin/cron)
 * via the provider's `recheck()` method, never on the render path.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

enum BackendHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';

    /**
     * Whether this state is selectable by the registry. Healthy and
     * Degraded are selectable; Down is skipped.
     */
    public function selectable(): bool
    {
        return $this !== self::Down;
    }
}
