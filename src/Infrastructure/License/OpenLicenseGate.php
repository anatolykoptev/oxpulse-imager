<?php
/**
 * OpenLicenseGate — the DEFAULT LicenseGate used until a real provider
 * is wired.
 *
 * #89: isPro() returns true UNCONDITIONALLY (modulo the oxpulse_is_pro
 * filter). This is the pre-licensing default: every existing install —
 * including imgproxy/AVIF sites — keeps EVERYTHING unlocked, so wiring
 * this seam in is a pure no-op with zero behavior change. A
 * Freemius-backed gate replaces this implementation in ServiceRegistrar
 * once credentials exist.
 *
 * Design choice — planName() defaults to 'pro' (not 'free'): because
 * isPro() defaults to true, reporting 'pro' keeps status readouts from
 * implying a downgrade before licensing is actually live. Once a real
 * provider is wired and a site genuinely resolves to free, planName()
 * will return 'free' through the same isPro()-derived path.
 *
 * @package OXPulse\Imager\Infrastructure\License
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\License;

use OXPulse\Imager\Domain\License\LicenseGate;

final class OpenLicenseGate implements LicenseGate
{
    public function isPro(): bool
    {
        return (bool) apply_filters('oxpulse_is_pro', true);
    }

    public function planName(): string
    {
        return $this->isPro() ? 'pro' : 'free';
    }
}
