<?php
/**
 * UrlRewriter factory — the single composition seam for building a
 * health-gated UrlRewriter from a delivery + signing config.
 *
 * Every URL producer (frontend render path, prewarm cron, WP-CLI info
 * + warm, REST info + prewarm controllers) calls this factory so the
 * DeliveryBackendFactory::select() health-gate is applied uniformly.
 * A `Down` imgproxy → LocalBackend / passthrough, never a lazy
 * ImgproxyBackend that bypasses the registry (#82).
 *
 * The caller is responsible for endpoint resolution
 * (OptionSettingsRepository::resolveEndpoint) BEFORE calling — the
 * factory does NOT re-resolve, keeping it pure Application/Delivery
 * with no Infrastructure dependency.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;

final class UrlRewriterFactory
{
    /**
     * Build a UrlRewriter with the health-gated backend injected.
     *
     * Delegates backend selection to DeliveryBackendFactory::select(),
     * which applies the ranked, health-gated registry: a cached-Down
     * imgproxy is skipped → LocalBackend (if applicable) → passthrough.
     * The selected backend is passed as the 5th UrlRewriter ctor arg so
     * the rewrite path exercises it directly (no lazy ImgproxyBackend
     * that would bypass the health-gate).
     *
     * @param DeliveryConfig $delivery  Endpoint-resolved delivery config.
     * @param SigningConfig|null $signing
     * @param DiagnosticLoggerInterface|null $logger
     */
    public static function fromConfig(
        DeliveryConfig $delivery,
        ?SigningConfig $signing,
        ?DiagnosticLoggerInterface $logger = null
    ): UrlRewriter {
        $backend = DeliveryBackendFactory::select($delivery, $signing);
        return new UrlRewriter(new SourcePolicy(), $delivery, $signing, $logger, $backend);
    }
}
