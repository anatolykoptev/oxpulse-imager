<?php
/**
 * imgproxy `cb:` (cachebuster) capability probe.
 *
 * imgproxy servers commonly run with IMGPROXY_ALLOWED_PROCESSING_OPTIONS,
 * and a list without `cb` turns every versioned URL (#132) into a 4xx. This
 * probe fetches ONE signed URL carrying `cb:` — built by the production
 * generator, so path shape and signature are the real thing — and writes
 * 'ok' only for a 200 image response. Anything else (rejected option,
 * transport error, no source image) leaves or writes 'no', and the
 * rewriter then emits unversioned URLs. Same verdict store, TTL and
 * triggers as SocialJpegCapabilityProbe — see SocialJpegCapabilityCache.
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
    public function __construct(
        private DeliveryConfig $delivery,
        private SigningConfig $signing,
        private HttpRequester $requester,
        private SocialJpegCapabilityCache $cache,
        private $sourceProvider,
    ) {}

    public function run(): void
    {
        $url = ($this->sourceProvider)();
        if ($url === null) {
            // Nothing to probe with: keep the previous verdict (it expires
            // by TTL), never upgrade to 'ok' on no evidence.
            return;
        }

        $decision = (new SourcePolicy())->authorize($url, $this->delivery);
        if (!$decision->authorized) {
            $this->cache->write('no');
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
            version: 'probe',
        );

        $probeUrl = (new ImgproxyBackend($this->delivery, $this->signing))->generate($req);
        $res = $this->requester->getImage($probeUrl);

        $ok = ($res['error'] ?? null) === null
            && ($res['status'] ?? 0) === 200
            && str_starts_with(strtolower($res['content_type'] ?? ''), 'image/');

        $this->cache->write($ok ? 'ok' : 'no');
    }
}
