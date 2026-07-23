<?php
/**
 * License gate — the single seam through which the plugin asks
 * "is this a paying (Pro) customer?".
 *
 * #89: this abstraction lets a Freemius (or any) license provider be
 * plugged in later behind it WITHOUT scattering provider-specific calls
 * across feature code. Every feature that will eventually be gated
 * resolves this one interface and asks isPro(); the provider
 * implementation is wired once in ServiceRegistrar.
 *
 * This PR lands ONLY the abstraction + a safe default (OpenLicenseGate).
 * No feature is gated yet — gating comes in a later PR alongside the
 * Freemius wire + grandfathering rollout.
 *
 * @package OXPulse\Imager\Domain\License
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\License;

interface LicenseGate
{
    /**
     * True when the site has an active Pro entitlement.
     *
     * The result passes through the `oxpulse_is_pro` filter so dev/QA
     * can force free/pro without a provider (mirrors the existing
     * `oxpulse_picture_enabled` / `oxpulse_buffer_rewrite_enabled`
     * filter idiom). This is also how QA exercises gated behavior
     * before Freemius lands.
     */
    public function isPro(): bool;

    /**
     * Human-readable plan name for status readouts: 'free' | 'pro'.
     *
     * Derives from isPro() so the two never disagree.
     */
    public function planName(): string;
}
