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
 * The grandfathered flag lives in a NON-autoloaded option, so a fresh
 * read is a SELECT — and isPro() runs once per <img> through the
 * oxpulse_picture_enabled filter. The read is memoised per request in a
 * static (gate instances can be short-lived); the add/update/delete
 * option hooks drop the memo so a mid-request change is still seen.
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
    private const GRANDFATHERED_OPTION = 'oxpulse_grandfathered';

    /** Per-request memo of the grandfathered flag (null = not yet read). */
    private static ?bool $grandfathered = null;

    public function __construct()
    {
        // Invalidate the memo on any write to the option in this request.
        // has_action() is the once-guard — it also re-heals after a test
        // harness resets the hook table while the static memo persists.
        foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
            $hook = $prefix . self::GRANDFATHERED_OPTION;
            if (function_exists('add_action') && has_action($hook, [self::class, 'resetMemo']) === false) {
                add_action($hook, [self::class, 'resetMemo']);
            }
        }
    }

    /**
     * Drop the memoised flag. Registered on the add/update/delete option
     * hooks; also the test seam for isolating the static between tests.
     */
    public static function resetMemo(): void
    {
        self::$grandfathered = null;
    }

    public function isPro(): bool
    {
        $fs = function_exists('oxpulse_fs') ? oxpulse_fs() : null;
        $pro = ($fs !== null && $fs->can_use_premium_code())
            || self::grandfathered();

        return (bool) apply_filters('oxpulse_is_pro', $pro);
    }

    public function planName(): string
    {
        return $this->isPro() ? 'pro' : 'free';
    }

    private static function grandfathered(): bool
    {
        if (self::$grandfathered === null) {
            self::$grandfathered = (bool) get_option(self::GRANDFATHERED_OPTION);
        }
        return self::$grandfathered;
    }
}
