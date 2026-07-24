<?php
/**
 * FreemiusLicenseGate — the Freemius-backed LicenseGate.
 *
 * Replaces OpenLicenseGate as the default gate wired in
 * ServiceRegistrar::licenseGate(). isPro() reflects the REAL Freemius
 * license state: true when the site has an active premium entitlement
 * (can_use_premium_code()) OR when the install was grandfathered
 * (pre-Freemius installs keep every feature they already had). The
 * oxpulse_is_pro filter contract is preserved so QA/dev can force
 * free/pro without a provider.
 *
 * @package OXPulse\Imager\Infrastructure\License
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\License;

use OXPulse\Imager\Domain\License\LicenseGate;

final class FreemiusLicenseGate implements LicenseGate
{
    public function isPro(): bool
    {
        $fs = function_exists('oxpulse_fs') ? oxpulse_fs() : null;
        $pro = ($fs !== null && $fs->can_use_premium_code())
            || (bool) get_option('oxpulse_grandfathered');

        return (bool) apply_filters('oxpulse_is_pro', $pro);
    }

    public function planName(): string
    {
        return $this->isPro() ? 'pro' : 'free';
    }
}
