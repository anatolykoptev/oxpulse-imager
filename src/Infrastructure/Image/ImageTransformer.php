<?php
/**
 * Image transform engine (Phase 6, local delivery).
 *
 * Given an absolute source file path + transform params (width, height,
 * fit, quality, format), produces optimized WebP or AVIF bytes. Pure
 * infrastructure — no WordPress globals beyond what's injected, so it
 * is unit-testable with fixture images.
 *
 * Engine selection (preferred-first):
 *   1. Imagick  (extension_loaded('imagick'))
 *   2. GD       (function_exists('imagewebp') / imageavif)
 *   3. neither  -> null (FAIL-SAFE: caller serves the original)
 *
 * Resize rules:
 *   - fit:  scale to fit within width x height, preserving aspect ratio.
 *           Never upscales past the original.
 *   - fill: crop to the exact width x height box (centered crop).
 *           Never upscales past the original.
 *   - width=0 and height=0: no resize (encode the original dimensions).
 *
 * Size-guard: if the produced output is >= the original file size, returns
 * null (the caller serves the original — the "output larger than original"
 * pitfall from competitor research).
 *
 * #47: AVIF encode support. AVIF is CPU-heavier than WebP (esp. Imagick/
 * libaom), but the result is cached (one-time per variant) and the flock
 * miss-dedupe in the handler already bounds concurrent transcodes.
 *
 * @package OXPulse\Imager\Infrastructure\Image
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Image;

class ImageTransformer
{
    /**
     * Decompression-bomb cap: reject sources whose pixel count (W*H)
     * exceeds this many megapixels BEFORE invoking the decoder. A crafted
     * "tiny file, huge canvas" image (decompression bomb) would otherwise
     * exhaust memory/CPU during decode. 40MP is well above any reasonable
     * photo upload but well below the point where decode becomes a DoS
     * vector (a 40MP RGBA decode is ~640MB — bounded + transient).
     */
    private const MAX_MEGAPIXELS = 40;

    /**
     * Memoized real-encode capability per format (probe once per
     * process, not per request). Keyed by lowercase format name.
     *
     * @var array<string,bool>
     */
    private static array $encodeCapability = [];

    /**
     * Memoized Imagick real-encode capability per format.
     *
     * @var array<string,bool>
     */
    private static array $imagickEncodeCapability = [];

    /**
     * Memoized GD real-encode capability per format.
     *
     * @var array<string,bool>
     */
    private static array $gdEncodeCapability = [];

    /**
     * Transform a source image to WebP or AVIF and return the optimized bytes.
     *
     * @param string $sourcePath Absolute filesystem path to the source image.
     * @param int $width Target width (0 = auto/no resize).
     * @param int $height Target height (0 = auto/no resize).
     * @param string $fit Resize mode: 'fit' (preserve aspect, no upscale)
     *        or 'fill' (exact crop). Empty string = no resize.
     * @param int $quality Quality (1-100).
     * @param string $format Output format: 'webp' or 'avif'.
     * @return string|null Encoded bytes, or null when:
     *         - the source file does not exist / is unreadable
     *         - no encoder for the requested format is available
     *         - the source dimensions exceed the decompression-bomb cap
     *         - the source format is not decodable
     *         - the produced output is >= the original file size (size-guard)
     */
    public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }

        if (!$this->canEncode($format)) {
            return null;
        }

        // Decompression-bomb guard: reject oversized canvases BEFORE the
        // decoder runs. getimagesize() reads only the header (cheap, safe);
        // the actual decode happens inside encode() below. Returning null
        // here makes the caller fail-safe to the original bytes.
        //
        // FIX #34: fail-closed when dimensions are UNKNOWN. If
        // getimagesize() can't read the header (corrupt file, exotic
        // format, truncated upload), the cap can't be enforced — a
        // crafted "tiny file, huge canvas" image that defeats
        // getimagesize would OOM the FPM worker during decode. Returning
        // null (serve original) is the safe choice: the caller streams
        // the original bytes without invoking the decoder.
        $dims = $this->imageDimensions($sourcePath);
        if ($dims === null) {
            return null;
        }
        [$w, $h] = $dims;
        if ($w <= 0 || $h <= 0 || ($w * $h) > (self::MAX_MEGAPIXELS * 1_000_000)) {
            return null;
        }

        $originalSize = filesize($sourcePath);
        if ($originalSize === false) {
            return null;
        }

        $encoded = $this->encode($sourcePath, $width, $height, $fit, $quality, $format);
        if ($encoded === null) {
            return null;
        }

        // Size-guard: if the output is not smaller than the original, serve
        // the original instead. This handles the "output larger than
        // original" pitfall (small or already-optimized sources).
        if (strlen($encoded) >= $originalSize) {
            return null;
        }

        return $encoded;
    }

    /**
     * Read the source image dimensions from the header without decoding.
     *
     * Returns [width, height] or null when the header can't be parsed.
     * Protected so tests can stub it to simulate an oversized canvas
     * without constructing a real decompression bomb.
     *
     * @return array{0:int,1:int}|null
     */
    protected function imageDimensions(string $sourcePath): ?array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false || !isset($info[0], $info[1])) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Encode the source image to the requested format using the available engine.
     *
     * Imagick is preferred (better compression + format support); GD is
     * the fallback. Returns null on any encode failure (corrupt source,
     * unsupported format, engine error) — the caller's fail-safe serves
     * the original.
     *
     * @return string|null Raw encoded bytes, or null on encode failure.
     */
    protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        // #63 review: FALL THROUGH on null. Do NOT trust
        // imagickSupportsFormat() (queryFormats = read-side) as proof
        // Imagick will write — a decode-only-avif Imagick build answers
        // queryFormats('AVIF')=true but throws on getImageBlob(). The
        // canImagickEncode() probe actually attempts a 2x2 encode, so
        // dispatching on it routes a decode-only-avif + GD-imageavif
        // host straight to GD (and skips a doomed Imagick attempt).
        if ($this->canImagickEncode($format)) {
            $bytes = $this->encodeImagick($sourcePath, $width, $height, $fit, $quality, $format);
            if ($bytes !== null) {
                return $bytes;
            }
            // Imagick could encode the probe but failed on this source
            // (corrupt/odd image) — fall through to GD if it can encode.
        }

        if ($this->canGdEncode($format)) {
            return $this->encodeGd($sourcePath, $width, $height, $fit, $quality, $format);
        }

        return null;
    }

    /**
     * Whether any encoder can produce the requested format.
     */
    private function canEncode(string $format): bool
    {
        if ($format === 'avif') {
            return $this->supportsAvif();
        }
        return $this->supportsWebp();
    }

    /**
     * Whether the host can ACTUALLY ENCODE WebP — verified by a real
     * 2x2 encode probe (memoized), not by queryFormats (which reports
     * "format known", not "encode works"). Capability-gated so
     * negotiate() never picks a format the host can't encode.
     */
    public function supportsWebp(): bool
    {
        return $this->canReallyEncode('webp');
    }

    /**
     * Whether the host can ACTUALLY ENCODE AVIF — verified by a real
     * 2x2 encode probe (memoized), not by queryFormats. A decode-only-
     * avif Imagick build (queryFormats('AVIF')=true, getImageBlob()
     * throws) reports false here, so negotiate() skips avif and picks
     * webp instead — no prod re-transcode, no defeated cache.
     */
    public function supportsAvif(): bool
    {
        return $this->canReallyEncode('avif');
    }

    /**
     * Real encode-capability probe: attempts a minimal 2x2 encode of
     * $format via the SAME engine path encode() uses, and returns true
     * only if it yields non-empty bytes without throwing. Memoized per
     * format per-process (probe once, not per request).
     *
     * #63 review: queryFormats() reports "format known to Imagick",
     * NOT "Imagick can ENCODE it" — a decode-only Imagick build answers
     * true for queryFormats('AVIF') but throws on getImageBlob(). This
     * probe catches that by actually encoding a tiny image.
     */
    protected function canReallyEncode(string $format): bool
    {
        $format = strtolower($format);
        if (isset(self::$encodeCapability[$format])) {
            return self::$encodeCapability[$format];
        }
        return self::$encodeCapability[$format] =
            $this->canImagickEncode($format) || $this->canGdEncode($format);
    }

    /**
     * Whether Imagick can ACTUALLY ENCODE $format — real 2x2 encode
     * probe, memoized. Uses imagickSupportsFormat() (queryFormats) as a
     * fast read-side pre-gate, then confirms with a real getImageBlob()
     * on a 2x2 image (the write-side proof).
     */
    protected function canImagickEncode(string $format): bool
    {
        $format = strtolower($format);
        if (isset(self::$imagickEncodeCapability[$format])) {
            return self::$imagickEncodeCapability[$format];
        }
        if (!$this->hasImagick() || !$this->imagickSupportsFormat($format)) {
            return self::$imagickEncodeCapability[$format] = false;
        }
        try {
            $image = new \Imagick();
            $image->newImage(2, 2, new \ImagickPixel('white'));
            $image->setImageFormat(strtoupper($format));
            $blob = $image->getImageBlob();
            $image->clear();
            return self::$imagickEncodeCapability[$format] = ($blob !== '');
        } catch (\Throwable $e) {
            return self::$imagickEncodeCapability[$format] = false;
        }
    }

    /**
     * Whether GD can ACTUALLY ENCODE $format — real 2x2 encode probe,
     * memoized. Gated by hasGdWebp()/hasGdAvif() (function_exists) then
     * confirmed with a real imageavif/imagewebp on a 2x2 image.
     */
    protected function canGdEncode(string $format): bool
    {
        $format = strtolower($format);
        if (isset(self::$gdEncodeCapability[$format])) {
            return self::$gdEncodeCapability[$format];
        }
        $hasFn = $format === 'avif' ? $this->hasGdAvif() : $this->hasGdWebp();
        if (!$hasFn) {
            return self::$gdEncodeCapability[$format] = false;
        }
        try {
            $img = imagecreatetruecolor(2, 2);
            if ($img === false) {
                return self::$gdEncodeCapability[$format] = false;
            }
            ob_start();
            if ($format === 'avif') {
                $ok = imageavif($img, null, 50);
            } else {
                $ok = imagewebp($img, null, 50);
            }
            $bytes = ob_get_clean();
            imagedestroy($img);
            if ($ok === false || $bytes === false || $bytes === '') {
                return self::$gdEncodeCapability[$format] = false;
            }
            return self::$gdEncodeCapability[$format] = true;
        } catch (\Throwable $e) {
            return self::$gdEncodeCapability[$format] = false;
        }
    }

    protected function hasImagick(): bool
    {
        return extension_loaded('imagick');
    }

    protected function hasGdWebp(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * Whether Imagick has the AVIF delegate (extension_loaded + the
     * build's delegate list includes AVIF). Guarded in try/catch —
     * queryFormats can throw on odd builds.
     */
    protected function hasImagickAvif(): bool
    {
        if (!$this->hasImagick()) {
            return false;
        }
        try {
            return \Imagick::queryFormats('AVIF') !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hasGdAvif(): bool
    {
        return function_exists('imageavif');
    }

    /**
     * Whether Imagick can encode a given format (checks the delegate list).
     */
    private function imagickSupportsFormat(string $format): bool
    {
        if (!$this->hasImagick()) {
            return false;
        }
        try {
            $fmt = strtoupper($format);
            return in_array($fmt, \Imagick::queryFormats($fmt), true);
        } catch (\Throwable $e) {
            // If queryFormats throws, conservatively assume the format
            // is NOT supported (fail-safe → caller serves original).
            return false;
        }
    }

    /**
     * Compute the target dimensions, applying the no-upscale rule.
     *
     * Returns [width, height] to resize to. When the requested dimensions
     * are larger than the original (or zero), the original dimensions are
     * kept (no upscaling past the original).
     *
     * @return array{0:int,1:int} [targetWidth, targetHeight]
     */
    private function targetDimensions(int $origW, int $origH, int $reqW, int $reqH, string $fit): array
    {
        // No resize requested.
        if ($reqW <= 0 && $reqH <= 0) {
            return [$origW, $origH];
        }

        if ($fit === 'fill') {
            // Exact crop box. Don't upscale past the original — clamp to
            // the original dimensions.
            $tw = $reqW > 0 ? min($reqW, $origW) : $origW;
            $th = $reqH > 0 ? min($reqH, $origH) : $origH;
            return [$tw, $th];
        }

        // 'fit' (default): scale to fit within reqW x reqH, preserve aspect.
        // If only one dimension is specified, scale by that axis.
        if ($reqW > 0 && $reqH > 0) {
            $scale = min($reqW / $origW, $reqH / $origH);
            // Don't upscale.
            if ($scale >= 1.0) {
                return [$origW, $origH];
            }
            return [(int) round($origW * $scale), (int) round($origH * $scale)];
        }

        if ($reqW > 0) {
            $scale = $reqW / $origW;
            if ($scale >= 1.0) {
                return [$origW, $origH];
            }
            return [$reqW, (int) round($origH * $scale)];
        }

        // $reqH > 0 only.
        $scale = $reqH / $origH;
        if ($scale >= 1.0) {
            return [$origW, $origH];
        }
        return [(int) round($origW * $scale), $reqH];
    }

    private function encodeImagick(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format): ?string
    {
        try {
            // Resource limits: bound memory + pixel-area so a malformed
            // source that slipped past the header cap (or a decoder that
            // expands internally) can't exhaust the FPM worker. These are
            // no-ops on Imagick builds without the policy setter.
            try {
                $image = new \Imagick();
                // @phpstan-ignore-next-line — method exists on Imagick >= 6.2
                if (method_exists($image, 'setResourceLimit')) {
                    /** @phan-suppress-next-line PhanUndeclaredMethod */
                    $image->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                    /** @phan-suppress-next-line PhanUndeclaredMethod */
                    $image->setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_MEGAPIXELS * 1_000_000);
                }
                $image->readImage($sourcePath);
            } catch (\Throwable $e) {
                // Fallback: construct directly (no resource limits) for
                // Imagick builds where readImage on a fresh handle fails.
                $image = new \Imagick($sourcePath);
            }
            $origW = $image->getImageWidth();
            $origH = $image->getImageHeight();

            [$tw, $th] = $this->targetDimensions($origW, $origH, $width, $height, $fit);

            if ($tw !== $origW || $th !== $origH) {
                if ($fit === 'fill' && $width > 0 && $height > 0) {
                    // Crop to exact box: resize-to-cover then center-crop.
                    $image->cropThumbnailImage($tw, $th);
                } else {
                    $image->resizeImage($tw, $th, \Imagick::FILTER_LANCZOS, 1.0);
                }
            }

            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);

            $bytes = $image->getImageBlob();
            $image->clear();

            return $bytes === '' ? null : $bytes;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function encodeGd(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format): ?string
    {
        // Detect the source format and load via the appropriate GD loader.
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        $mime = $info['mime'] ?? '';
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => null,
        };

        if ($source === false || $source === null) {
            return null;
        }

        try {
            $origW = imagesx($source);
            $origH = imagesy($source);

            [$tw, $th] = $this->targetDimensions($origW, $origH, $width, $height, $fit);

            if ($tw !== $origW || $th !== $origH) {
                $resized = imagecreatetruecolor($tw, $th);
                if ($resized === false) {
                    return null;
                }

                if ($fit === 'fill' && $width > 0 && $height > 0) {
                    // Resize-to-cover then center-crop via imagecopyresampled.
                    $srcRatio = $origW / $origH;
                    $dstRatio = $tw / $th;
                    if ($srcRatio > $dstRatio) {
                        $srcW = (int) round($origH * $dstRatio);
                        $srcH = $origH;
                        $srcX = (int) (($origW - $srcW) / 2);
                        $srcY = 0;
                    } else {
                        $srcW = $origW;
                        $srcH = (int) round($origW / $dstRatio);
                        $srcX = 0;
                        $srcY = (int) (($origH - $srcH) / 2);
                    }
                    imagecopyresampled($resized, $source, 0, 0, $srcX, $srcY, $tw, $th, $srcW, $srcH);
                } else {
                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $tw, $th, $origW, $origH);
                }

                imagedestroy($source);
                $source = $resized;
            }

            ob_start();
            if ($format === 'avif') {
                $ok = imageavif($source, null, $quality);
            } else {
                $ok = imagewebp($source, null, $quality);
            }
            $bytes = ob_get_clean();

            if ($ok === false || $bytes === false) {
                return null;
            }

            return $bytes === '' ? null : $bytes;
        } finally {
            if (isset($source) && is_resource($source)) {
                imagedestroy($source);
            }
        }
    }
}
