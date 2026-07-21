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

use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Diagnostics\LogEntry;
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
    private ?DiagnosticLoggerInterface $logger;

    private ?ImgproxyUrlGenerator $generator = null;

    public function __construct(
        SourcePolicy $policy,
        DeliveryConfig $delivery,
        ?SigningConfig $signing,
        ?DiagnosticLoggerInterface $logger = null
    ) {
        $this->policy = $policy;
        $this->delivery = $delivery;
        $this->signing = $signing;
        $this->logger = $logger;
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
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'delivery_disabled'));
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if ($this->delivery->endpoint === '') {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'no_endpoint'));
            return RewriteResult::preserved($sourceUrl, 'no_endpoint');
        }

        if ($this->signing === null) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'no_signing_config'));
            return RewriteResult::preserved($sourceUrl, 'no_signing_config');
        }

        $decision = $this->policy->authorize($sourceUrl, $this->delivery);
        if (!$decision->authorized) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, $decision->reason));
            return RewriteResult::preserved($sourceUrl, $decision->reason);
        }

        try {
            // For 'local' source mode, use the resolved filesystem path as the
            // source; for 'http' mode, use the canonical URL. The sourceMode is
            // passed through to TransformRequest so ImgproxyPathBuilder can emit
            // the correct segment (local:// vs plain/).
            $sourceForRequest = $decision->fsPath ?? (string) $decision->url;
            $sourceMode = $decision->fsPath !== null ? 'local' : 'http';

            // Ф7: Save-Data header support. When the browser sends
            // Save-Data: on (mobile data saver / lite mode), reduce image
            // quality by the configured amount. This saves bandwidth on
            // metered connections with minimal perceptual quality loss.
            // Ф8: Size-based quality tiers. When the requested width falls
            // within a configured tier (maxWidth), use that tier's quality
            // instead of defaultQuality. Applied BEFORE Save-Data reduction
            // so Save-Data stacks on top of the size-appropriate quality.
            [$quality, $formatQuality] = $this->applyQualityAdjustments(
                $width,
                $this->delivery->defaultQuality,
                $this->delivery->formatQuality
            );

            $request = new TransformRequest(
                sourceUrl: $sourceForRequest,
                width: $width,
                height: $height,
                resize: $this->resolveResizeType($width, $height),
                format: $this->delivery->outputFormat,
                quality: $quality > 0 ? $quality : 0,
                context: $context,
                dpr: 0,
                blur: 0,
                watermark: $this->delivery->watermark,
                formatQuality: $formatQuality,
                sourceMode: $sourceMode,
            );

            $filename = $this->buildContentDispositionFilename($sourceUrl);

            $url = $this->generator()->generate($request, $filename);
            $this->log(LogEntry::rewritten($context, $sourceUrl, $width));
            return RewriteResult::rewritten($url);
        } catch (\Throwable $e) {
            // Fail safe: any unexpected error preserves the original URL.
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'generation_error'));
            return RewriteResult::preserved($sourceUrl, 'generation_error');
        }
    }

    /**
     * Record a log entry if a logger is attached. No-op when no
     * logger is configured (the default — logging is opt-in via
     * diagnostic_level).
     */
    private function log(LogEntry $entry): void
    {
        if ($this->logger !== null) {
            $this->logger->log($entry);
        }
    }

    /**
     * Build a LQIP (Low-Quality Image Placeholder) URL for a source image.
     *
     * Uses imgproxy's blur option to generate a tiny, blurred preview
     * that can be inlined as a data URI or loaded before the full image.
     * Returns null when LQIP is disabled or the URL cannot be rewritten.
     *
     * @param string $sourceUrl Original image URL.
     * @return RewriteResult
     */
    public function rewriteLqip(string $sourceUrl): RewriteResult
    {
        if (!$this->delivery->lqipEnabled) {
            return RewriteResult::preserved($sourceUrl, 'lqip_disabled');
        }

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
            $sourceForRequest = $decision->fsPath ?? (string) $decision->url;
            $sourceMode = $decision->fsPath !== null ? 'local' : 'http';

            // LQIP: small width + blur. No resize dimensions = use blur only,
            // imgproxy will serve a small blurred version. We add a modest
            // width cap (20px) so the placeholder is genuinely tiny.
            $request = new TransformRequest(
                sourceUrl: $sourceForRequest,
                width: 20,
                height: 20,
                resize: 'fit',
                format: $this->delivery->outputFormat,
                quality: 30,
                context: 'lqip',
                dpr: 0,
                blur: $this->delivery->lqipBlur > 0 ? $this->delivery->lqipBlur : 1,
                watermark: null, // Never watermark the placeholder
                formatQuality: [],
                sourceMode: $sourceMode,
            );

            return RewriteResult::rewritten($this->generator()->generate($request));
        } catch (\Throwable $e) {
            return RewriteResult::preserved($sourceUrl, 'lqip_generation_error');
        }
    }

    /**
     * Build a DPR-aware variant URL for a source image.
     *
     * Used by SrcsetRewriter to emit 1x/2x/3x variants. The width is
     * multiplied by the DPR factor so imgproxy serves a sharper image
     * for HiDPI displays.
     *
     * @param string $sourceUrl Original image URL.
     * @param int $width Base width (CSS pixels).
     * @param float $dpr Device pixel ratio (1, 2, 3).
     * @param string $context Rewrite context.
     * @return RewriteResult
     */
    public function rewriteDpr(string $sourceUrl, int $width, float $dpr, string $context = 'srcset'): RewriteResult
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
            $sourceForRequest = $decision->fsPath ?? (string) $decision->url;
            $sourceMode = $decision->fsPath !== null ? 'local' : 'http';

            $request = new TransformRequest(
                sourceUrl: $sourceForRequest,
                width: $width,
                height: 0,
                resize: 'fit',
                format: $this->delivery->outputFormat,
                quality: $this->delivery->defaultQuality > 0 ? $this->delivery->defaultQuality : 0,
                context: $context,
                dpr: $dpr,
                blur: 0,
                watermark: $this->delivery->watermark,
                formatQuality: $this->delivery->formatQuality,
                sourceMode: $sourceMode,
            );

            $filename = $this->buildContentDispositionFilename($sourceUrl);

            return RewriteResult::rewritten($this->generator()->generate($request, $filename));
        } catch (\Throwable $e) {
            return RewriteResult::preserved($sourceUrl, 'dpr_generation_error');
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
        // Ф9: rs:fill when both dimensions are specified — exact crop to the
        // requested box. The Foxiz theme registers fixed crop sizes
        // (foxiz_crop_g1 330x220, foxiz_crop_1300x800, etc.) and the theme's
        // composition is tuned for imgproxy's exact-crop behaviour. With
        // rs:fit, images preserve aspect ratio (one axis may be shorter),
        // producing wrong framing and gaps in fixed-size containers.
        if ($width > 0 && $height > 0) {
            return 'fill';
        }
        return 'fit';
    }

    /**
     * Ф8: Resolve the quality for a given requested width from the
     * size-based quality tiers configuration.
     *
     * Tiers are matched smallest-first: the first tier whose maxWidth >=
     * $width wins. If no tier matches (width larger than the largest
     * tier, or width is 0/auto), returns null → caller uses
     * defaultQuality.
     *
     * @param int $width Requested width in pixels (0 = auto/original).
     * @return int|array<string,int>|null Resolved quality (int for simple
     *         tier, array for per-format tier), or null when no tier matches.
     */
    private function resolveSizeQuality(int $width): int|array|null
    {
        if ($width <= 0 || empty($this->delivery->sizeQualityTiers)) {
            return null;
        }

        // Sort tiers by maxWidth ascending so the smallest matching tier
        // wins. The config is a map [maxWidth => quality]; PHP preserves
        // insertion order but we sort for determinism.
        $tiers = $this->delivery->sizeQualityTiers;
        ksort($tiers, SORT_NUMERIC);

        foreach ($tiers as $maxWidth => $quality) {
            if ($width <= $maxWidth) {
                return $quality;
            }
        }

        // Width larger than the largest tier → use defaultQuality.
        return null;
    }

    /**
     * Ф7 + Ф8 + Ф11: Apply size-based quality tiers, then Save-Data reduction.
     *
     * Order matters: size-based quality is applied first (selects the
     * appropriate base quality for the image size), then Save-Data
     * reduction stacks on top (further reduces for data-saving browsers).
     * Both are no-ops when their respective configs are empty/zero.
     *
     * Ф11: sizeQualityTiers now supports per-format quality maps. When a
     * tier value is an array (e.g. ['avif' => 55, 'webp' => 60, 'jpeg' =>
     * 70]), it replaces formatQuality and defaultQuality is zeroed (fq:
     * is emitted). When a tier value is int, it replaces defaultQuality
     * and formatQuality is cleared (q: is emitted).
     *
     * @param int $width Requested width in pixels.
     * @param int $defaultQuality Configured default quality.
     * @param array<string,int> $formatQuality Configured per-format quality.
     * @return array{0:int,1:array<string,int>} [adjusted quality, adjusted formatQuality]
     */
    private function applyQualityAdjustments(int $width, int $defaultQuality, array $formatQuality): array
    {
        // Ф8/Ф11: Size-based quality tier overrides defaultQuality and/or
        // formatQuality. Int tier → replaces defaultQuality (q: emitted).
        // Per-format tier → replaces formatQuality (fq: emitted).
        $sizeQuality = $this->resolveSizeQuality($width);
        if (is_int($sizeQuality)) {
            $defaultQuality = $sizeQuality;
            $formatQuality = [];
        } elseif (is_array($sizeQuality)) {
            $formatQuality = $sizeQuality;
            $defaultQuality = 0;
        }

        // Ф7: Save-Data reduction stacks on top.
        return $this->applySaveDataReduction($defaultQuality, $formatQuality);
    }

    /**
     * Ф7: Check whether the current request has the Save-Data: on header.
     *
     * The Save-Data Client Hint header is sent by browsers when the user
     * has enabled "Data Saver" / "Lite mode" (Chrome on Android, Opera,
     * Edge). It is an honest signal from the browser — not a security
     * concern (worst case: user gets lower-quality images when they
     * didn't need them).
     *
     * The header value is case-insensitive "on". Other values ("off",
     * empty) mean no data-saving.
     */
    private function saveDataActive(): bool
    {
        $value = $_SERVER['HTTP_SAVE_DATA'] ?? '';
        return is_string($value) && strtolower(trim($value)) === 'on';
    }

    /**
     * Ф7: Apply Save-Data quality reduction to the configured quality values.
     *
     * When Save-Data: on is active and saveDataQualityReduction > 0:
     * - Reduces defaultQuality by the configured amount (floor at 1).
     * - Reduces each per-format quality in formatQuality (floor at 1).
     *
     * When Save-Data is not active or reduction is 0, returns the
     * original values unchanged (zero overhead).
     *
     * @param int $defaultQuality Configured default quality.
     * @param array<string,int> $formatQuality Configured per-format quality.
     * @return array{0:int,1:array<string,int>} [reduced defaultQuality, reduced formatQuality]
     */
    private function applySaveDataReduction(int $defaultQuality, array $formatQuality): array
    {
        $reduction = $this->delivery->saveDataQualityReduction;
        if ($reduction <= 0 || !$this->saveDataActive()) {
            return [$defaultQuality, $formatQuality];
        }

        $reducedDefault = $defaultQuality > 0 ? max(1, $defaultQuality - $reduction) : $defaultQuality;

        $reducedFormat = [];
        foreach ($formatQuality as $fmt => $q) {
            $reducedFormat[$fmt] = max(1, $q - $reduction);
        }

        return [$reducedDefault, $reducedFormat];
    }
}
