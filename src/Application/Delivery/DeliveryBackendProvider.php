<?php
/**
 * Delivery backend provider interface.
 *
 * The pluggable seam behind DeliveryBackendRegistry. Each provider
 * knows how to (a) declare whether it applies to the current config,
 * (b) report a cached, front-end-safe health state, and (c) construct
 * its concrete DeliveryBackend.
 *
 * Adding a NEW delivery backend = implement this interface in one
 * class + register it via the `oxpulse_delivery_backends` WP filter.
 * Zero edits to DeliveryBackendRegistry or DeliveryBackendFactory.
 *
 * Contract notes:
 * - `health()` MUST be front-end-safe: no network I/O — a cache read
 *   or a memoized local probe only (never a blocking/network call on
 *   the render path). The live probe that writes the cache runs at
 *   write-time via `recheck()` (where applicable), never on the
 *   render path.
 * - `build()` may return null to signal "preserve the original URL"
 *   (the passthrough floor).
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;

interface DeliveryBackendProvider
{
    /**
     * Stable provider identifier (e.g. 'imgproxy', 'local', 'passthrough').
     */
    public function id(): string;

    /**
     * Selection priority — HIGHER is preferred. The registry sorts
     * applicable providers by priority DESC and selects the first
     * whose health() is selectable (not Down).
     */
    public function priority(): int;

    /**
     * Whether the provider's prerequisites are present AT ALL for the
     * given config (e.g. imgproxy endpoint set, signing configured,
     * source mode compatible). Does NOT perform I/O — a cheap,
     * config-only check. The health check is a separate, cache-only
     * concern.
     */
    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool;

    /**
     * Front-end-safe health state. MUST NOT perform network I/O —
     * a cache read or a memoized local probe only (never a
     * blocking/network call on the render path). The live probe
     * (where applicable) runs at write-time via recheck(), never
     * here.
     */
    public function health(DeliveryConfig $config): BackendHealth;

    /**
     * Construct the concrete delivery backend, or null to preserve
     * the original URL (the passthrough floor). Called only after the
     * provider has been selected (isApplicable + health selectable).
     */
    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend;
}
