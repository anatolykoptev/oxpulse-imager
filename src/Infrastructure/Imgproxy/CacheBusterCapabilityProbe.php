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
 * cache, so only definitive answers move it). A control request — the same
 * signed URL without `cb:` — must return 200 image first, otherwise nothing
 * is written. Then:
 * - cb: 200 + image/*        -> 'ok'.
 * - cb: 400 / 403 / 404      -> 'no' (a forbidden option is a 404 in imgproxy).
 * - cb: transport error, 5xx, 429, odd content type -> nothing written.
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

        // Same signed URL twice: a CONTROL without `cb:` and the `cb:` probe.
        // imgproxy answers a disallowed option (IMGPROXY_ALLOWED_PROCESSING_
        // OPTIONS) with 404 "Invalid URL" — v4.0.11 options/parser/errors.go
        // newForbiddenOptionError — the same status as a missing source, so a
        // 404 alone proves nothing. Only "control fine, cb: refused" does.
        // The width is random so neither URL can be answered from a cache.
        $width = 64 + random_int(0, 4000);
        $make = fn (string $version): string => (new ImgproxyBackend($this->delivery, $this->signing))->generate(
            new TransformRequest(
                sourceUrl: $decision->fsPath ?? (string) $decision->url,
                width: $width,
                height: 0,
                resize: 'fit',
                format: $this->delivery->outputFormat,
                context: 'cb_probe',
                sourceMode: $decision->fsPath !== null ? 'local' : 'http',
                version: $version,
            )
        );

        if (!$this->isImage($this->requester->getImage($make('')))) {
            // Source missing, 5xx, timeout, 429...: the endpoint told us
            // nothing about `cb`. Keep the prior verdict.
            return;
        }

        // Unique per probe (piter's nginx caches imgproxy 200s).
        $res = $this->requester->getImage($make('p' . bin2hex(random_bytes(6))));
        $status = (int) ($res['status'] ?? 0);
        if ($this->isImage($res)) {
            $verdict = 'ok';
        } elseif (($res['error'] ?? null) === null && in_array($status, [400, 403, 404], true)) {
            $verdict = 'no';
        } else {
            return; // transient failure on the cb: request only
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

    /** @param array{status?: int, content_type?: string, error?: ?string} $res */
    private function isImage(array $res): bool
    {
        return ($res['error'] ?? null) === null
            && (int) ($res['status'] ?? 0) === 200
            && str_starts_with(strtolower($res['content_type'] ?? ''), 'image/');
    }
}
