<?php
/**
 * imgproxy delivery backend adapter.
 *
 * A thin adapter that wraps the EXISTING ImgproxyUrlGenerator /
 * ImgproxyPathBuilder / HmacSigner chain so UrlRewriter can delegate to a
 * DeliveryBackend interface without rewriting the imgproxy URL production
 * path. Behaviour is byte-identical to the pre-seam UrlRewriter::generator()
 * path — the existing UrlRewriterTest / DeliveryWiringTest suites are the
 * contract.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;

final class ImgproxyBackend implements DeliveryBackend
{
    private ImgproxyUrlGenerator $generator;
    private ?ImgproxyHealthCache $health;
    private ?SocialJpegCapabilityCache $capability;

    public function __construct(
        private DeliveryConfig $delivery,
        SigningConfig $signing,
        ?ImgproxyHealthCache $health = null,
        ?SocialJpegCapabilityCache $capability = null,
    ) {
        // Construct the exact same chain the pre-seam UrlRewriter::generator()
        // built: HmacSigner -> ImgproxyPathBuilder -> ImgproxyUrlGenerator.
        // The endpoint is read from DeliveryConfig (already resolved to an
        // absolute URL at the WordPress boundary by DeliveryConfig::withEndpoint).
        $this->generator = new ImgproxyUrlGenerator(
            new ImgproxyPathBuilder(),
            new HmacSigner($signing),
            $this->delivery->endpoint
        );
        $this->health = $health;
        $this->capability = $capability;
    }

    public function available(): bool
    {
        // Matches the pre-seam UrlRewriter guard: empty endpoint => preserve
        // original URL with reason 'no_endpoint'.
        return $this->delivery->endpoint !== '';
    }

    public function generate(TransformRequest $request, ?string $filename = null): string
    {
        return $this->generator->generate($request, $filename);
    }

    public function socialSafeUrl(TransformRequest $request, ?string $filename = null): ?string
    {
        // The .jpg encoded-source form is reliable for local:// sources
        // (imgproxy reads the file directly and transcodes to jpeg).
        // For http sources the .jpg extension-form is unreliable →
        // answer null so the caller degrades to the direct URL.
        if ($request->sourceMode !== 'local') {
            return null;
        }

        // Conservative capability gate: og:image is EITHER a proven-working
        // imgproxy .jpg OR the webp direct URL — never a URL that might 403.
        // The health cache is a cheap belt (imgproxy down → no .jpg); the
        // capability cache is the conservative gate (unproven → degrade to
        // webp). Both caches are front-end-safe (zero network I/O — option
        // reads only). When the caches are null (direct construction, e.g.
        // the probe), the gate is bypassed (today's optimistic behavior).
        if ($this->health !== null && $this->health->read() === 'down') {
            return null;
        }
        if ($this->capability !== null && !$this->capability->readOk()) {
            return null;
        }

        return $this->generator->generate($request, $filename);
    }
}
