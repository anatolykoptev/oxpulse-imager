<?php
/**
 * imgproxy path builder.
 *
 * Constructs the deterministic imgproxy processing URL path from
 * processing options and a source URL. Uses the plain source URL
 * format for MVP.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/usage/processing
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
     * @return string Path starting with '/', e.g. "/rs:fit:800:0/plain/https://example.com/img.jpg@avif"
     */
    public function build(TransformRequest $request): string
    {
        $options = $this->profile->buildOptions($request);
        $source = $request->sourceUrl;
        $format = $request->format !== '' && $request->format !== 'auto'
            ? '@' . $request->format
            : '';

        // Build path: /options/plain/source@format
        if ($options !== '') {
            return '/' . $options . '/plain/' . $source . $format;
        }

        return '/plain/' . $source . $format;
    }
}
