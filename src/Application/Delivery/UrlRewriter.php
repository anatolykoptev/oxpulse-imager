<?php
/**
 * URL rewriter service.
 *
 * The single decision point for image URL rewriting. Combines source
 * authorization (SourcePolicy) with signed URL generation
 * (ImgproxyUrlGenerator). Always fails safe: on any denial, error, or
 * missing configuration, the original URL is preserved unchanged.
 *
 * Generates Content-Disposition filenames from the source URL so that
 * "Save As" in browsers produces meaningful filenames. When an explicit
 * output format is configured (avif/webp), the filename extension
 * reflects the output format; in 'auto' mode (Accept negotiation), the
 * source filename is passed without extension modification.
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

    private ?ImgproxyUrlGenerator $generator = null;

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

            $filename = $this->buildContentDispositionFilename($sourceUrl);

            return RewriteResult::rewritten($this->generator()->generate($request, $filename));
        } catch (\Throwable $e) {
            // Fail safe: any unexpected error preserves the original URL.
            return RewriteResult::preserved($sourceUrl, 'generation_error');
        }
    }

    /**
     * Get or create the URL generator. The generator is created once
     * and reused for all subsequent rewrites, avoiding repeated object
     * instantiation on pages with many images.
     */
    private function generator(): ImgproxyUrlGenerator
    {
        if ($this->generator === null) {
            $signer = new HmacSigner($this->signing);
            $pathBuilder = new ImgproxyPathBuilder();
            $this->generator = new ImgproxyUrlGenerator($pathBuilder, $signer, $this->delivery->endpoint);
        }
        return $this->generator;
    }

    /**
     * Build a Content-Disposition filename from the source URL.
     *
     * When an explicit output format is configured (avif/webp/jpeg),
     * the filename extension is replaced to match. In 'auto' mode
     * (Accept negotiation), the original filename is preserved —
     * imgproxy will set the correct extension in Content-Disposition
     * based on the negotiated format.
     */
    private function buildContentDispositionFilename(string $sourceUrl): ?string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $basename = basename($path);
        if ($basename === '' || $basename === '.') {
            return null;
        }

        $format = $this->delivery->outputFormat;
        if ($format === '' || $format === 'auto') {
            return $basename;
        }

        $dotPos = strrpos($basename, '.');
        if ($dotPos === false) {
            return $basename . '.' . $format;
        }
        return substr($basename, 0, $dotPos) . '.' . $format;
    }

    private function resolveResizeType(int $width, int $height): string
    {
        if ($width <= 0 && $height <= 0) {
            return '';
        }
        return 'fit';
    }
}
