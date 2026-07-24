<?php
/**
 * imgproxy delivery backend provider.
 *
 * The highest-priority provider (100): when an imgproxy endpoint is
 * configured, it is preferred over local + passthrough. Selection
 * skips it only when its cached health is Down — then the registry
 * falls through to LocalBackend (if applicable) or passthrough.
 *
 * Front-end safety: health() reads the ImgproxyHealthCache ONLY
 * (zero network I/O). The live probe that WRITES the cache runs at
 * write-time via recheck(), wired into the SAME hooks as the rewrite-
 * capability recheck (admin settings-save + the version-gated re-probe
 * + activation). recheck() is NEVER called on the front-end render path.
 *
 * Security: recheck() probes ONLY the admin-configured endpoint host
 * via HttpRequester::head() (bounded 2s timeout, redirection = 0, no
 * body — enforced by the requester implementation). It does not scan,
 * guess, or derive other hosts (no SSRF surface).
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\DeliveryBackendProvider;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;

final class ImgproxyBackendProvider implements DeliveryBackendProvider
{
    public function __construct(
        private HttpRequester $requester,
        private ImgproxyHealthCache $cache,
        private ?SocialJpegCapabilityCache $capability = null,
    ) {}

    public function id(): string
    {
        return 'imgproxy';
    }

    public function priority(): int
    {
        return 100;
    }

    /**
     * Applicable when an imgproxy endpoint is configured (i.e. the
     * local backend is NOT the active one). Config-presence only —
     * no I/O. The signing-null short-circuit is handled by the
     * registry before providers are consulted.
     */
    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool
    {
        return !$config->isLocalBackendActive();
    }

    /**
     * Cached, front-end-safe health. ZERO network I/O — reads the
     * ImgproxyHealthCache only. Optimistic default: an unset cache
     * returns Healthy (a never-probed endpoint is NOT marked down).
     * A cached 'down' (written by a probe that got a non-2xx/3xx or
     * a transport error) returns Down.
     */
    public function health(DeliveryConfig $config): BackendHealth
    {
        return $this->cache->read() === 'down' ? BackendHealth::Down : BackendHealth::Healthy;
    }

    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend
    {
        // A provider-built backend is ALWAYS conservative: inject the
        // health cache (cheap belt) + the capability cache (conservative
        // gate). When capability is unset (null ctor arg), a real
        // SocialJpegCapabilityCache is constructed so the gate is active
        // — an unprobed endpoint defaults to readOk=false → degrade to
        // webp, never a URL that might 403.
        $capability = $this->capability ?? new SocialJpegCapabilityCache();
        return new ImgproxyBackend($config, $signing, $this->cache, $capability);
    }

    /**
     * Write-time bounded health probe. HEADs the admin-configured
     * endpoint, 2xx/3xx → up, anything else / WP_Error / timeout →
     * down, and writes the cache. NEVER called on the front-end
     * render path — wired to admin settings-save + the version-gated
     * re-probe + activation only.
     *
     * Security: probes ONLY the configured endpoint host. The
     * HttpRequester implementation enforces timeout ≤ 2s,
     * redirection = 0, HEAD (no body) — a misconfigured caller cannot
     * weaken these. No host scanning / guessing / derivation.
     */
    public function recheck(DeliveryConfig $config): void
    {
        if ($config->isLocalBackendActive()) {
            return;
        }

        $resp = $this->requester->head($config->endpoint);
        $status = $resp['status'] ?? 0;
        $error = $resp['error'] ?? null;

        if ($error !== null || $status < 200 || $status >= 400) {
            $this->cache->write('down');
            return;
        }

        $this->cache->write('up');
    }
}
