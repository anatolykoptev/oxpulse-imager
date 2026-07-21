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

    public function __construct(
        private DeliveryConfig $delivery,
        SigningConfig $signing
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
}
