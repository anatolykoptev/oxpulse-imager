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
    public function __construct(
        private ImageTransformer $transformer,
    ) {}

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
     */
    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool
    {
        return $signing !== null && $config->sourceMode !== 'local';
    }

    /**
     * Healthy when the host can ACTUALLY encode webp or avif (reusing
     * ImageTransformer's memoized real-encode probe — front-end-safe,
     * zero network I/O). Down when neither encoder is available
     * (LocalBackend would produce nothing usable).
     */
    public function health(DeliveryConfig $config): BackendHealth
    {
        if ($this->transformer->supportsWebp() || $this->transformer->supportsAvif()) {
            return BackendHealth::Healthy;
        }
        return BackendHealth::Down;
    }

    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend
    {
        return new LocalBackend($signing, new CapabilityTester());
    }
}
