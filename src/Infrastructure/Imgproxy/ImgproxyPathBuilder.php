<?php
/**
 * imgproxy path builder.
 *
 * Constructs the deterministic imgproxy processing URL path from
 * processing options, a source URL, and an optional filename for
 * Content-Disposition. Uses the plain source URL format.
 *
 * Format semantics (2026 industry standard — Accept header negotiation):
 * - 'auto': no @format suffix. imgproxy uses the Accept header to pick
 *   the best format (AVIF > WebP > original) when IMGPROXY_AUTO_AVIF
 *   and IMGPROXY_AUTO_WEBP are enabled on the server. This is the
 *   default and the recommended mode.
 * - 'avif', 'webp', 'jpeg', 'png': explicit @format suffix. Overrides
 *   Accept negotiation. Use only for testing or format-specific
 *   endpoints.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/usage/processing
 * @see https://docs.imgproxy.net/latest/configuration/options (AUTO_AVIF, AUTO_WEBP)
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Domain\Transform\TransformProfile;
use OXPulse\Imager\Domain\Transform\TransformRequest;

final class ImgproxyPathBuilder
{
    private TransformProfile $profile;

    public function __construct(?TransformProfile $profile = null)
    {
        $this->profile = $profile ?? new TransformProfile();
    }

    /**
     * Build the imgproxy path (without signature).
     *
     * Two source addressing modes:
     * - 'http': emits `/plain/{url}` — imgproxy fetches the source via HTTP.
     * - 'local': emits `/local://{base64url(path)}` — imgproxy reads the source
     *   directly from the filesystem. The path is the resolved filesystem path
     *   (already validated against localBasePath by SourcePolicy). rawurldecode
     *   is NOT applied here — the caller (SourcePolicy) is responsible for
     *   producing a filesystem path that matches the on-disk encoding.
     *
     * @param TransformRequest $request
     * @param string|null $filename Optional filename for Content-Disposition header.
     *                              When provided, adds fn: option to the path.
     * @return string Path starting with '/', e.g. "/rs:fit:800:0/fn:photo.avif/plain/https://example.com/img.jpg"
     *         or "/rs:fit:800:0/local://{base64url-path}"
     */
    public function build(TransformRequest $request, ?string $filename = null): string
    {
        $options = $this->profile->buildOptions($request);
        $sourceSegment = $this->sourceSegment($request);
        $formatSuffix = $this->formatSuffix($request->format);
        $filenameOption = $this->filenameOption($filename);

        // Combine processing options with the filename option.
        $allOptions = $options;
        if ($filenameOption !== '') {
            $allOptions = $allOptions !== '' ? $allOptions . '/' . $filenameOption : $filenameOption;
        }

        // Build path: /options/{source-segment}{@format}
        // For 'http' mode: /options/plain/{url}@format
        // For 'local' mode: /options/local://{base64url}@format
        // The @format suffix applies to both modes — imgproxy honours it for local:// too.
        if ($allOptions !== '') {
            return '/' . $allOptions . '/' . $sourceSegment . $formatSuffix;
        }

        return '/' . $sourceSegment . $formatSuffix;
    }

    /**
     * Build the source segment of the imgproxy path.
     *
     * - 'http' mode: `plain/{url}` (imgproxy fetches via HTTP).
     * - 'local' mode: `{base64url(local:///path)}` (imgproxy reads from
     *   filesystem). Uses imgproxy's ENCODED source format — the entire
     *   source string `local:///path` is base64url-encoded and placed
     *   directly after the processing options (no `plain/` prefix).
     *
     *   imgproxy supports three source formats:
     *     1. `plain/{source_url}@ext` — plain source URL
     *     2. `{base64url(source_url)}.{ext}` — encoded source (this one)
     *     3. `enc/{encrypted_source_url}.{ext}` — encrypted source
     *
     *   The mu-plugin this replaces uses format 2 for local:// sources,
     *   base64url-encoding the full `local:///wp-content/...` string.
     *   Using `local://` in plain text (without `plain/` prefix) is NOT
     *   a valid imgproxy source format and 403s.
     *
     *   imgproxy expects `local:///path/to/image.jpg` (three slashes —
     *   `local://` + `/path`). The leading slash in the path is REQUIRED.
     *   SourcePolicy returns a relative path (no leading slash), so we
     *   prepend '/' before constructing the full `local:///path` string.
     */
    private function sourceSegment(TransformRequest $request): string
    {
        if ($request->sourceMode === 'local') {
            // Build the full local:///path source string. Prepend leading
            // slash — imgproxy expects local:///path, and SourcePolicy
            // returns a relative path (no leading slash).
            $source = 'local:///' . ltrim($request->sourceUrl, '/');
            // Encode the ENTIRE source string (encoded format, no plain/ prefix).
            $encoded = rtrim(strtr(base64_encode($source), '+/', '-_'), '=');
            return $encoded;
        }

        return 'plain/' . $request->sourceUrl;
    }

    /**
     * Format suffix for the source URL.
     *
     * 'auto' produces no suffix — imgproxy uses Accept header negotiation
     * (requires IMGPROXY_AUTO_AVIF/AUTO_WEBP on the server). Explicit
     * formats produce an @format suffix.
     */
    private function formatSuffix(string $format): string
    {
        if ($format === '' || $format === 'auto') {
            return '';
        }
        return '@' . $format;
    }

    /**
     * Filename option for Content-Disposition.
     *
     * imgproxy uses the fn: option to set the Content-Disposition header,
     * which tells the browser the correct filename for Save As / download.
     * The filename should include the output format extension when an
     * explicit format is chosen; for 'auto' mode, the extension is
     * determined by the server at response time, so we omit it.
     */
    private function filenameOption(?string $filename): string
    {
        if ($filename === null || $filename === '') {
            return '';
        }
        // URL-safe Base64 encode the filename per imgproxy spec.
        $encoded = rtrim(strtr(base64_encode($filename), '+/', '-_'), '=');
        return 'fn:' . $encoded . ':1';
    }
}
