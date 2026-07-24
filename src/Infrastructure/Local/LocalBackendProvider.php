<?php
/**
 * Local delivery backend provider.
 *
 * Priority 50 — the middle tier: selected when imgproxy is NOT
 * applicable (no endpoint) or when imgproxy is cached Down (the
 * fallthrough). Requires signing + http source mode (the local://
 * source-mode case is handled by imgproxy, not LocalBackend — see the
 * DeliveryBackendFactory parity comment).
 *
 * Front-end safety: health() reuses ImageTransformer's memoized
 * real-encode probe (supportsWebp()/supportsAvif()) — a local 2x2
 * encode, memoized per process, ZERO network I/O. Down only when the
 * host can encode neither webp nor avif (LocalBackend would produce
 * nothing usable).
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\DeliveryBackendProvider;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;

final class LocalBackendProvider implements DeliveryBackendProvider
{
    /**
     * The miss-endpoint artifact path the provider checks in health().
     * Null when no path was injected AND WP_CONTENT_DIR is undefined
     * (the unit-test stub env) — in that case the artifact check is
     * SKIPPED (preserves the encoder-only health() behavior for tests
     * that don't exercise the artifact gate). In production this
     * resolves to WP_CONTENT_DIR/oxpulse-img.php (the file
     * LocalDeliveryInstaller::install() writes / uninstall() removes).
     */
    private ?string $endpointArtifactPath;

    public function __construct(
        private ImageTransformer $transformer,
        ?string $endpointArtifactPath = null,
    ) {
        if ($endpointArtifactPath === null && defined('WP_CONTENT_DIR')) {
            $endpointArtifactPath = WP_CONTENT_DIR . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
        }
        $this->endpointArtifactPath = $endpointArtifactPath;
    }

    public function id(): string
    {
        return 'local';
    }

    public function priority(): int
    {
        return 50;
    }

    /**
     * Applicable when signing is configured AND sourceMode is 'http'.
     * The sourceMode='local' case is NOT applicable here — LocalBackend
     * signs a key whose payload 'source' is a bare fs path, which the
     * miss-endpoint can't resolve (see DeliveryBackendFactory parity
     * comment). imgproxy + sourceMode='local' is handled by the
     * imgproxy provider.
     *
     * #87: NOT applicable on WordPress Multisite. LocalBackend bakes ONE
     * shared oxpulse-img.php endpoint with a single blog's per-site
     * values (key/salt/uploadsBasedir/uploadsBaseurl/avifQuality); on a
     * multisite every other blog's images fail (HMAC mismatch → 400,
     * PathGuard reject → 404). The registry falls through to Passthrough
     * (or ImgproxyBackend when an endpoint is configured). Per-site
     * multisite LocalBackend is a separate followup.
     */
    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool
    {
        if ($signing === null || $config->sourceMode === 'local') {
            return false;
        }
        // function_exists guard: is_multisite() is available in WP, but
        // the unit-test stub environment may load this class without it.
        return !function_exists('is_multisite') || !is_multisite();
    }

    /**
     * Healthy when the host can ACTUALLY encode webp or avif (reusing
     * ImageTransformer's memoized real-encode probe — front-end-safe,
     * zero network I/O) AND the LocalBackend miss-endpoint artifact
     * exists on disk. Down when neither encoder is available
     * (LocalBackend would produce nothing usable) OR the artifact is
     * absent.
     *
     * BLOCKER fix: LocalBackend emits signed URLs to
     * wp-content/oxpulse-img.php (the miss-endpoint written by
     * LocalDeliveryInstaller::install() ONLY when endpoint === '').
     * When the artifact is absent — e.g. a free user with a stored
     * imgproxy endpoint, where gate 2 strips ImgproxyBackendProvider
     * and the registry would select LocalBackend, but install()
     * self-gated because endpoint !== '' — LocalBackend would emit
     * signed URLs to a non-existent endpoint → 404 on every optimized
     * <img> sitewide. Marking Down makes select() fall through to
     * Passthrough (original URLs, unoptimized but WORKING).
     *
     * The artifact check is a cheap is_file() stat; select() is
     * memoized per request. When $endpointArtifactPath is null (no
     * WP_CONTENT_DIR — unit-test stub env), the check is skipped so
     * the encoder-only behavior is preserved for tests that don't
     * exercise the artifact gate.
     */
    public function health(DeliveryConfig $config): BackendHealth
    {
        if (!$this->transformer->supportsWebp() && !$this->transformer->supportsAvif()) {
            return BackendHealth::Down;
        }
        if ($this->endpointArtifactPath !== null && !is_file($this->endpointArtifactPath)) {
            return BackendHealth::Down;
        }
        return BackendHealth::Healthy;
    }

    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend
    {
        return new LocalBackend($signing, new CapabilityTester());
    }
}
