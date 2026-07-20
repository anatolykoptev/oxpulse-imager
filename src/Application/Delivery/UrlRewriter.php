<?php
/**
 * URL rewriter service.
 *
 * The single decision point for image URL rewriting. Combines source
 * authorization (SourcePolicy) with signed URL generation
 * (ImgproxyUrlGenerator). Always fails safe: on any denial, error, or
 * missing configuration, the original URL is preserved unchanged.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyPathBuilder;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyUrlGenerator;

final class UrlRewriter
{
    private SourcePolicy $policy;
    private DeliveryConfig $delivery;
    private ?SigningConfig $signing;

    public function __construct(
        SourcePolicy $policy,
        DeliveryConfig $delivery,
        ?SigningConfig $signing
    ) {
        $this->policy = $policy;
        $this->delivery = $delivery;
        $this->signing = $signing;
    }

    /**
     * Attempt to rewrite a source image URL as a signed imgproxy URL.
     *
     * @param string $sourceUrl Original image URL.
     * @param int $width Target width (0 = auto/no resize).
     * @param int $height Target height (0 = auto/no resize).
     * @param string $context Where the rewrite originated ('content', 'srcset', 'attachment').
     * @return RewriteResult
     */
    public function rewrite(string $sourceUrl, int $width = 0, int $height = 0, string $context = 'content'): RewriteResult
    {
        if (!$this->delivery->enabled) {
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if ($this->delivery->endpoint === '') {
            return RewriteResult::preserved($sourceUrl, 'no_endpoint');
        }

        if ($this->signing === null) {
            return RewriteResult::preserved($sourceUrl, 'no_signing_config');
        }

        $decision = $this->policy->authorize($sourceUrl, $this->delivery);
        if (!$decision->authorized) {
            return RewriteResult::preserved($sourceUrl, $decision->reason);
        }

        try {
            $request = new TransformRequest(
                sourceUrl: (string) $decision->url,
                width: $width,
                height: $height,
                resize: $this->resolveResizeType($width, $height),
                format: $this->delivery->outputFormat,
                quality: $this->delivery->defaultQuality > 0 ? $this->delivery->defaultQuality : 0,
                context: $context
            );

            $signer = new HmacSigner($this->signing);
            $pathBuilder = new ImgproxyPathBuilder();
            $generator = new ImgproxyUrlGenerator($pathBuilder, $signer, $this->delivery->endpoint);

            return RewriteResult::rewritten($generator->generate($request));
        } catch (\Throwable $e) {
            // Fail safe: any unexpected error preserves the original URL.
            return RewriteResult::preserved($sourceUrl, 'generation_error');
        }
    }

    private function resolveResizeType(int $width, int $height): string
    {
        if ($width <= 0 && $height <= 0) {
            return '';
        }
        return 'fit';
    }
}
