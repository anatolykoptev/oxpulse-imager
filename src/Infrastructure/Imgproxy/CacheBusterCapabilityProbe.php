<?php
/**
 * imgproxy `cb:` (cachebuster) capability probe.
 *
 * imgproxy servers commonly run with IMGPROXY_ALLOWED_PROCESSING_OPTIONS,
 * and a list without `cb` turns every versioned URL (#132) into a 4xx. This
 * probe fetches ONE signed URL carrying `cb:` — built by the production
 * generator, so path shape and signature are the real thing — with a random
 * nonce so no cache layer can answer it.
 *
 * Verdict rules (a flip changes EVERY imgproxy URL and refills the nginx
 * cache, so only definitive answers move it):
 * - 200 + image/*            -> 'ok'.
 * - 400 or 403               -> 'no' (imgproxy's answer to a disallowed
 *                               option on a signed URL with a good source).
 * - anything else (transport error, 5xx, 429, 404, odd content type,
 *   unauthorised/missing source) -> nothing written; the prior verdict stands.
 *
 * When the effective state (verdict === 'ok') changes, the page caches are
 * purged through the injected callable (production: CachePurger::purge()),
 * since cached pages have baked the previous URL shape.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;

final class CacheBusterCapabilityProbe
{
    /** @var callable|null */
    private $purgePageCaches;

    public function __construct(
        private DeliveryConfig $delivery,
        private SigningConfig $signing,
        private HttpRequester $requester,
        private SocialJpegCapabilityCache $cache,
        private $sourceProvider,
        ?callable $purgePageCaches = null,
    ) {
        $this->purgePageCaches = $purgePageCaches;
    }

    public function run(): void
    {
        $url = ($this->sourceProvider)();
        if ($url === null) {
            return;
        }

        $decision = (new SourcePolicy())->authorize($url, $this->delivery);
        if (!$decision->authorized) {
            // Says nothing about the endpoint.
            return;
        }

        $req = new TransformRequest(
            sourceUrl: $decision->fsPath ?? (string) $decision->url,
            width: 64,
            height: 0,
            resize: 'fit',
            format: $this->delivery->outputFormat,
            context: 'cb_probe',
            sourceMode: $decision->fsPath !== null ? 'local' : 'http',
            // Unique per probe: piter's nginx caches imgproxy 200s, which
            // would otherwise answer a later probe from cache.
            version: 'p' . bin2hex(random_bytes(6)),
        );

        $probeUrl = (new ImgproxyBackend($this->delivery, $this->signing))->generate($req);
        $res = $this->requester->getImage($probeUrl);

        $status = (int) ($res['status'] ?? 0);
        if (($res['error'] ?? null) === null
            && $status === 200
            && str_starts_with(strtolower($res['content_type'] ?? ''), 'image/')
        ) {
            $verdict = 'ok';
        } elseif (($res['error'] ?? null) === null && ($status === 400 || $status === 403)) {
            $verdict = 'no';
        } else {
            return;
        }

        $wasOk = $this->cache->readVerdict() === 'ok';
        $this->cache->write($verdict);

        if ($wasOk !== ($verdict === 'ok') && $this->purgePageCaches !== null) {
            try {
                ($this->purgePageCaches)();
            } catch (\Throwable $e) {
                // A purge failure must never break the probe.
            }
        }
    }
}
