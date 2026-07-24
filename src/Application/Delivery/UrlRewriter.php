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
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;

final class UrlRewriter
{
    private SourcePolicy $policy;
    private DeliveryConfig $delivery;
    private ?SigningConfig $signing;
    private ?DiagnosticLoggerInterface $logger;

    /**
     * @param DeliveryBackend|null $backend Injected delivery backend. When
     *        null (the default — preserves backward compatibility with all
     *        pre-seam callers), an ImgproxyBackend is lazily constructed from
     *        the delivery + signing config, reproducing the pre-seam
     *        UrlRewriter::generator() path byte-for-byte. When a backend is
     *        injected (e.g. a LocalBackend or a test stub), UrlRewriter
     *        delegates URL production to it.
     */
    public function __construct(
        SourcePolicy $policy,
        DeliveryConfig $delivery,
        ?SigningConfig $signing,
        ?DiagnosticLoggerInterface $logger = null,
        private ?DeliveryBackend $backend = null
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
        return $this->rewriteWithFormat(
            $sourceUrl,
            $width,
            $height,
            $this->delivery->outputFormat,
            $context
        );
    }

    /**
     * Rewrite a source image URL to an EXPLICIT output format (avif/webp),
     * overriding the configured outputFormat. Used by PictureElementWrapper
     * to emit per-format <source> URLs so a modern browser negotiates AVIF
     * client-side on standard Apache (no Accept-header negotiation needed).
     *
     * Identical to rewrite() EXCEPT the format is the explicit $format
     * argument, not $this->delivery->outputFormat. All guards (already-
     * rewritten, delivery-disabled, no-endpoint, no-signing, SourcePolicy
     * authorize, fail-safe try/catch, quality adjustments) are shared via
     * rewriteWithFormat(). The Content-Disposition filename extension
     * reflects $format (not the config outputFormat).
     *
     * @param string $sourceUrl Original image URL.
     * @param int $width Target width (0 = auto/no resize).
     * @param int $height Target height (0 = auto/no resize).
     * @param string $format Explicit output format ('avif' | 'webp' | 'jpeg' | 'png' | 'auto').
     * @param string $context Where the rewrite originated ('picture' for <picture> sources).
     * @return RewriteResult
     */
    public function rewriteFormat(string $sourceUrl, int $width, int $height, string $format, string $context = 'picture'): RewriteResult
    {
        return $this->rewriteWithFormat($sourceUrl, $width, $height, $format, $context);
    }

    /**
     * Rewrite an og:image / twitter:image source URL to a social-safe
     * raster (jpeg) URL via the active backend's socialSafeUrl() seam.
     *
     * Used by RankMathCompatibility::restoreDirectUrl when the restored
     * direct URL is NOT social-safe (e.g. .webp on a webp-original
     * install). Routes the source through the backend to an explicit-
     * jpeg, `.jpg`-terminated URL so RankMath's wp_check_filetype()
     * accepts it and social networks/messengers render the preview.
     *
     * Reuses the exact guard chain as rewriteWithFormat (already-
     * rewritten / delivery-disabled / no-endpoint / no-signing /
     * SourcePolicy::authorize) — all fail-safe paths return
     * preserved($sourceUrl, <reason>). On authorize success, builds a
     * TransformRequest with format='jpeg' + extensionFormat=true and
     * delegates to backend()->socialSafeUrl(). When the backend
     * answers null (LocalBackend / http-source / passthrough — cannot
     * produce a servable social raster), degrades to
     * preserved($sourceUrl, 'social_format_unsupported') (== today's
     * behaviour, never broken). try/catch → preserved('social_generation_error').
     *
     * @param string $sourceUrl Original (direct) image URL.
     * @param int $width Target width (og:image default 1200).
     * @param int $height Target height (og:image default 630).
     * @param string $context Diagnostic context (default 'og_image').
     * @return RewriteResult
     */
    public function rewriteSocialImage(string $sourceUrl, int $width, int $height, string $context = 'og_image'): RewriteResult
    {
        if ($this->isAlreadyRewritten($sourceUrl)) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'already_rewritten'));
            return RewriteResult::preserved($sourceUrl, 'already_rewritten');
        }

        if (!$this->delivery->enabled) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'delivery_disabled'));
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if (!$this->backendAvailable()) {
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
            $sourceForRequest = $decision->fsPath ?? (string) $decision->url;
            $sourceMode = $decision->fsPath !== null ? 'local' : 'http';

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
                format: 'jpeg',
                quality: $quality > 0 ? $quality : 0,
                context: $context,
                dpr: 0,
                blur: 0,
                watermark: $this->delivery->watermark,
                formatQuality: $formatQuality,
                sourceMode: $sourceMode,
                extensionFormat: true,
            );

            $filename = $this->buildContentDispositionFilename($sourceUrl, 'jpeg');

            $url = $this->backend()->socialSafeUrl($request, $filename);
            if ($url === null) {
                $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'social_format_unsupported'));
                return RewriteResult::preserved($sourceUrl, 'social_format_unsupported');
            }

            $this->log(LogEntry::rewritten($context, $sourceUrl, $width));
            return RewriteResult::rewritten($url);
        } catch (\Throwable $e) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'social_generation_error'));
            return RewriteResult::preserved($sourceUrl, 'social_generation_error');
        }
    }

    /**
     * Shared rewrite body for both rewrite() (config outputFormat) and
     * rewriteFormat() (explicit format). Behaviour-preserving for the
     * rewrite() path — all guards, quality adjustments, and the filename
     * builder run identically, only the format source differs.
     */
    private function rewriteWithFormat(string $sourceUrl, int $width, int $height, string $format, string $context): RewriteResult
    {
        // #43 Phase 3 — idempotency guard (no double-rewrite / recursion).
        // If the source URL is ALREADY one of OUR rewritten forms — a
        // LocalBackend cache URL (wp-content/cache/oxpulse/) or the
        // ?k= endpoint (oxpulse-img.php) — preserve it unchanged. This
        // stops the recursion where a filtered URL is fed back through
        // a content filter, and prevents re-rewriting a URL another
        // plugin already handed to us. Imgproxy URLs are not matched
        // here (they don't contain /wp-content/) and are separately
        // skipped by SourcePolicy's proxy-loop check.
        if ($this->isAlreadyRewritten($sourceUrl)) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'already_rewritten'));
            return RewriteResult::preserved($sourceUrl, 'already_rewritten');
        }

        if (!$this->delivery->enabled) {
            $this->log(LogEntry::preserved($context, $sourceUrl, $width, 'delivery_disabled'));
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if (!$this->backendAvailable()) {
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
                format: $format,
                quality: $quality > 0 ? $quality : 0,
                context: $context,
                dpr: 0,
                blur: 0,
                watermark: $this->delivery->watermark,
                formatQuality: $formatQuality,
                sourceMode: $sourceMode,
            );

            $filename = $this->buildContentDispositionFilename($sourceUrl, $format);

            $url = $this->backend()->generate($request, $filename);
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

        if ($this->isAlreadyRewritten($sourceUrl)) {
            return RewriteResult::preserved($sourceUrl, 'already_rewritten');
        }

        if (!$this->delivery->enabled) {
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if (!$this->backendAvailable()) {
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

            return RewriteResult::rewritten($this->backend()->generate($request));
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
        if ($this->isAlreadyRewritten($sourceUrl)) {
            return RewriteResult::preserved($sourceUrl, 'already_rewritten');
        }

        if (!$this->delivery->enabled) {
            return RewriteResult::preserved($sourceUrl, 'delivery_disabled');
        }

        if (!$this->backendAvailable()) {
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

            $filename = $this->buildContentDispositionFilename($sourceUrl, $this->delivery->outputFormat);

            return RewriteResult::rewritten($this->backend()->generate($request, $filename));
        } catch (\Throwable $e) {
            return RewriteResult::preserved($sourceUrl, 'dpr_generation_error');
        }
    }

    /**
     * Get the delivery backend. When a backend was injected via the
     * constructor, it is used directly. When none was injected (the
     * default for all pre-seam callers), an ImgproxyBackend is lazily
     * constructed from the delivery + signing config, reproducing the
     * pre-seam UrlRewriter::generator() path byte-for-byte. The backend
     * is created once and reused for all subsequent rewrites, avoiding
     * repeated object instantiation on pages with many images.
     */
    private function backend(): DeliveryBackend
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        // Lazily construct the default ImgproxyBackend. The signing-null
        // guard in each rewrite method preserves the URL before this point
        // is reached, so signing is guaranteed non-null here.
        $this->backend = new ImgproxyBackend($this->delivery, $this->signing);
        return $this->backend;
    }

    /**
     * Whether the active backend can produce delivery URLs under the
     * current config. Delegates to the injected backend's available()
     * when one was provided; for the default ImgproxyBackend path, checks
     * the endpoint directly (matching the pre-seam guard ordering — the
     * endpoint check ran BEFORE the signing check, so it must not require
     * a signing config to evaluate).
     */
    private function backendAvailable(): bool
    {
        if ($this->backend !== null) {
            return $this->backend->available();
        }

        return $this->delivery->endpoint !== '';
    }

    /**
     * #43 Phase 3 — idempotency guard: detect whether a URL is ALREADY
     * one of OUR rewritten forms, so we never re-process it.
     *
     * Matches the two LocalBackend output shapes:
     *   - clean cache URL:  .../wp-content/cache/oxpulse/<hash>/<key>.<fmt>
     *   - fallback endpoint: .../wp-content/oxpulse-img.php?k=<key>
     *
     * Imgproxy URLs are not matched here (they don't contain
     * /wp-content/cache/oxpulse/); SourcePolicy's proxy-loop check
     * separately skips them. The check is path-based (host-agnostic)
     * so it also catches a relative `/wp-content/cache/oxpulse/...`
     * URL emitted in a non-absolute context.
     */
    private function isAlreadyRewritten(string $sourceUrl): bool
    {
        return str_contains($sourceUrl, '/wp-content/cache/oxpulse/')
            || str_contains($sourceUrl, '/wp-content/oxpulse-img.php');
    }

    /**
     * Build a Content-Disposition filename from the source URL.
     *
     * When an explicit output format is configured (avif/webp/jpeg),
     * the filename extension is replaced to match. In 'auto' mode
     * (Accept negotiation), the filename is returned WITHOUT extension —
     * imgproxy appends the correct extension based on the negotiated
     * format (e.g. "photo" → "photo.avif" / "photo.webp" / "photo.jpg").
     * Returning the source extension here would produce a double
     * extension (e.g. "photo.webp.png" when imgproxy negotiates PNG from
     * a WebP source).
     */
    private function buildContentDispositionFilename(string $sourceUrl, string $format): ?string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $basename = basename($path);
        if ($basename === '' || $basename === '.') {
            return null;
        }

        if ($format === '' || $format === 'auto') {
            // Auto mode: strip the source extension. imgproxy appends the
            // negotiated format extension to the Content-Disposition
            // filename, so including the source extension here would
            // produce a double extension (photo.webp.png).
            $dotPos = strrpos($basename, '.');
            if ($dotPos === false) {
                return $basename;
            }
            return substr($basename, 0, $dotPos);
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP-agnostic Application layer; header only compared to a literal, never output/stored.
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
