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
use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
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

        // #43 Phase 3: use the shared isLocalBackendActive() predicate
        // (same idiom as ServiceRegistrar::recheckRewriteCapability and
        // LocalDeliveryInstaller::install) instead of a raw endpoint
        // emptiness check. The predicate is the single source of truth
        // for "is LocalBackend the active backend?" — keeping all call
        // sites aligned.
        if (!$delivery->isLocalBackendActive()) {
            return new ImgproxyBackend($delivery, $signing);
        }

        // #29.2: LocalBackend requires http source mode. When
        // sourceMode='local', SourcePolicy resolves the source to a bare
        // filesystem path (SourceDecision::fsPath !== null) and
        // UrlRewriter feeds that path — not the URL — into the
        // TransformRequest. LocalBackend then signs a key whose payload
        // 'source' is a bare fs path (no scheme+host). At miss-endpoint
        // time, PathGuard::resolve() requires scheme+host from
        // payload['source'] → null → 404 on every image; URL-normalized
        // invalidation can't match either. Returning null here makes
        // UrlRewriter preserve the original URL (no rewrite) — the safe
        // behavior when no imgproxy endpoint is configured for local
        // source mode. imgproxy + sourceMode='local' (local:// transport)
        // is handled by the ImgproxyBackend branch above.
        if ($delivery->sourceMode === 'local') {
            return null;
        }

        // #43 Phase 2: inject a CapabilityTester so LocalBackend can
        // emit ?k= fallback URLs when rewrite is unavailable. The
        // tester reads the cached capability option (front-end-safe,
        // zero blocking I/O — see CapabilityTester::rewriteAvailable()).
        return new LocalBackend($signing, new CapabilityTester());
    }
}
