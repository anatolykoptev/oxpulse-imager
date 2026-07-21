<?php
/**
 * Delivery backend interface.
 *
 * The seam between the URL rewriting decision logic (UrlRewriter) and the
 * concrete URL production strategy. Two implementations:
 *
 * - ImgproxyBackend: wraps the existing ImgproxyUrlGenerator/ImgproxyPathBuilder/
 *   HmacSigner chain to produce signed imgproxy URLs (requires the daemon).
 * - LocalBackend: produces a signed, absolute, stable cache-file URL under
 *   /wp-content/cache/oxpulse/ for on-disk local delivery (Phase 6).
 *
 * The interface is intentionally minimal: UrlRewriter only needs to (a) ask
 * whether the backend can function under the current config (available()) and
 * (b) produce a delivery URL for a transform request (generate()). Backend
 * selection lives in DeliveryBackendFactory.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Transform\TransformRequest;

interface DeliveryBackend
{
    /**
     * Whether the backend can produce delivery URLs under the current
     * configuration.
     *
     * ImgproxyBackend returns false when no endpoint is configured (the
     * UrlRewriter preserves the original URL with reason 'no_endpoint' in
     * that case, matching the pre-seam behaviour). LocalBackend returns
     * true unconditionally — it needs only a signing key, which is checked
     * separately by UrlRewriter.
     */
    public function available(): bool;

    /**
     * Produce a delivery URL for the given transform request.
     *
     * @param TransformRequest $request The transform to deliver.
     * @param string|null $filename Optional Content-Disposition filename
     *        (imgproxy fn: option; ignored by LocalBackend which serves a
     *        static cache file with no Content-Disposition override).
     * @return string Absolute (LocalBackend) or endpoint-relative/absolute
     *        (ImgproxyBackend) delivery URL.
     */
    public function generate(TransformRequest $request, ?string $filename = null): string;
}
