<?php
/**
 * Live HTTP self-probe for the imgproxy social-jpeg capability.
 *
 * Validates that imgproxy can actually serve a .jpg transcoded URL for
 * og:image — the EXACT production URL form (signed, local:// source,
 * extensionFormat .jpg). Issues a single getImage() to the production
 * URL and writes 'ok'/'no' to SocialJpegCapabilityCache.
 *
 * The .jpg FORM validity (signing/base64url/imgproxy-config/transport)
 * is source-pixel-INDEPENDENT — one representative raster validates it.
 * So the probe uses a single representative image (the newest attachment)
 * rather than per-image probing.
 *
 * WRITE-TIME ONLY: this probe issues a live HTTP GET (bounded 5s timeout,
 * redirection = 0, sslverify = true — enforced by the HttpRequester impl).
 * It is NEVER called from the front-end render path. Wired to the same
 * triggers as recheckImgproxyHealth (activation, settings-save, hourly
 * cron, version-gated re-probe).
 *
 * Mirrors LocalRewriteProbe as a standalone *Probe class (testable, no
 * WP coupling beyond the injected deps). The cached tri-state is
 * persisted ONLY when definitive: 'ok' (200 + image/jpeg) or 'no'
 * (anything else). A null sourceProvider result (no images to probe)
 → returns WITHOUT writing (stay conservative — cache stays false).
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

final class SocialJpegCapabilityProbe
{
    public function __construct(
        private DeliveryConfig $delivery,
        private SigningConfig $signing,
        private HttpRequester $requester,
        private SocialJpegCapabilityCache $cache,
        private $sourceProvider,
    ) {}

    /**
     * Run the probe and write the verdict to the cache.
     *
     * Steps:
     * 1. Get a representative source image URL. null → return without
     *    writing (stay conservative — no images to probe).
     * 2. Authorize the source via SourcePolicy. Not authorized OR not
     *    local (fsPath === null) → write('no'); return.
     * 3. Build a TransformRequest for the 1200x630 fill jpeg .jpg form.
     * 4. Generate the EXACT production .jpg URL via an UNGATED
     *    ImgproxyBackend (no caches injected → no chicken-egg).
     * 5. getImage() the URL (endpoint already absolute).
     * 6. 200 + image/jpeg → write('ok'), else write('no').
     */
    public function run(): void
    {
        $url = ($this->sourceProvider)();
        if ($url === null) {
            return;
        }

        $decision = (new SourcePolicy())->authorize($url, $this->delivery);
        if (!$decision->authorized || $decision->fsPath === null) {
            $this->cache->write('no');
            return;
        }

        $req = new TransformRequest(
            sourceUrl: $decision->fsPath,
            width: 1200,
            height: 630,
            resize: 'fill',
            format: 'jpeg',
            quality: 0,
            context: 'og_probe',
            dpr: 0,
            blur: 0,
            watermark: null,
            formatQuality: [],
            sourceMode: 'local',
            extensionFormat: true,
        );

        $jpgUrl = (new ImgproxyBackend($this->delivery, $this->signing))->socialSafeUrl($req);
        if ($jpgUrl === null) {
            $this->cache->write('no');
            return;
        }

        $res = $this->requester->getImage($jpgUrl);
        $status = $res['status'] ?? 0;
        $contentType = $res['content_type'] ?? '';
        $error = $res['error'] ?? null;

        if ($error === null && $status === 200 && str_starts_with(strtolower($contentType), 'image/jpeg')) {
            $this->cache->write('ok');
            return;
        }

        $this->cache->write('no');
    }
}
