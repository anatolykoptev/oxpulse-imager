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

    /**
     * Produce a servable social-safe raster (jpeg) URL for the given
     * transform request, or null when this backend cannot produce one.
     *
     * "Social-safe" = the URL ends in a social-platform-recognised
     * raster extension (`.jpg`, `.png`, `.gif`) so RankMath's
     * wp_check_filetype() accepts it and social networks/messengers
     * (VK, some Telegram, older parsers) can render the preview. The
     * request carries extensionFormat=true so imgproxy emits a
     * dot-extension instead of the @format suffix.
     *
     * The backend answers HONESTLY: returning null signals "I cannot
     * produce a servable social raster for this request" and the
     * caller degrades to the direct (e.g. .webp) URL — never breaks.
     * Concretely:
     * - ImgproxyBackend: local source → non-null signed .jpg URL;
     *   http source → null (the .jpg encoded-source form is unreliable
     *   for http sources).
     * - LocalBackend: null — jpeg is not in
     *   MissEndpointHandler::ALLOWED_FORMATS, so a .jpg local cache
     *   URL would be unservable.
     *
     * @param TransformRequest $request Transform to deliver (format
     *        typically 'jpeg', extensionFormat true).
     * @param string|null $filename Optional Content-Disposition filename.
     * @return string|null Absolute/relative social-safe URL, or null.
     */
    public function socialSafeUrl(TransformRequest $request, ?string $filename = null): ?string;
}
