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
     * @param TransformRequest $request
     * @param string|null $filename Optional filename for Content-Disposition header.
     *                              When provided, adds fn: option to the path.
     * @return string Path starting with '/', e.g. "/rs:fit:800:0/fn:photo.avif/plain/https://example.com/img.jpg"
     */
    public function build(TransformRequest $request, ?string $filename = null): string
    {
        $options = $this->profile->buildOptions($request);
        $source = $request->sourceUrl;
        $formatSuffix = $this->formatSuffix($request->format);
        $filenameOption = $this->filenameOption($filename);

        // Combine processing options with the filename option.
        $allOptions = $options;
        if ($filenameOption !== '') {
            $allOptions = $allOptions !== '' ? $allOptions . '/' . $filenameOption : $filenameOption;
        }

        // Build path: /options/plain/source@format
        if ($allOptions !== '') {
            return '/' . $allOptions . '/plain/' . $source . $formatSuffix;
        }

        return '/plain/' . $source . $formatSuffix;
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
