<?php
/**
 * ProFeatures — declarative registry of WHICH capabilities are Pro.
 *
 * #89: a pure declarative catalog (const string keys + all()). No
 * gating logic lives here — features consult LicenseGate::isPro() at
 * runtime in a later PR. This registry is the single source of truth
 * for the free/pro split so the eventual gating + upgrade UI + QA
 * matrix all agree on what "Pro" means.
 *
 * Seeded split (keys only):
 * - AVIF               — AVIF output (imgproxy or local encoder).
 * - IMGPROXY_DELIVERY  — imgproxy as the delivery backend.
 * - PICTURE_ELEMENT    — <picture> element wrapping with per-format
 *                        <source> tags.
 * - CACHE_MANAGEMENT   — cache pre-warm / invalidation controls.
 * - ADMIN_STATUS       — the admin status/diagnostics readout.
 *
 * Free tier = everything NOT listed here: WebP output, local encode,
 * basic delivery (LocalBackend / passthrough), the core URL rewrite
 * pipeline. Keeping the free tier functional is the grandfathering
 * guarantee — no existing install loses the ability to serve images.
 *
 * @package OXPulse\Imager\Domain\License
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\License;

final class ProFeatures
{
    public const AVIF = 'avif';
    public const IMGPROXY_DELIVERY = 'imgproxy_delivery';
    public const PICTURE_ELEMENT = 'picture_element';
    public const CACHE_MANAGEMENT = 'cache_management';
    public const ADMIN_STATUS = 'admin_status';

    /**
     * @return list<string> The Pro capability keys, in a stable order.
     */
    public static function all(): array
    {
        return [
            self::AVIF,
            self::IMGPROXY_DELIVERY,
            self::PICTURE_ELEMENT,
            self::CACHE_MANAGEMENT,
            self::ADMIN_STATUS,
        ];
    }
}
