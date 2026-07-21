<?php
/**
 * imgproxy URL generator.
 *
 * Combines path building and signing into a complete signed imgproxy URL.
 * Supports an optional filename for Content-Disposition.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Domain\Signing\Signer;
use OXPulse\Imager\Domain\Transform\TransformRequest;

final class ImgproxyUrlGenerator
{
    private ImgproxyPathBuilder $pathBuilder;
    private Signer $signer;
    private string $endpoint;

    public function __construct(ImgproxyPathBuilder $pathBuilder, Signer $signer, string $endpoint)
    {
        $this->pathBuilder = $pathBuilder;
        $this->signer = $signer;
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * Generate a complete signed imgproxy URL.
     *
     * @param TransformRequest $request
     * @param string|null $filename Optional filename for Content-Disposition.
     * @return string Full signed URL, e.g. "https://imgproxy.example.com/sig/rs:fit:800:0/plain/..."
     */
    public function generate(TransformRequest $request, ?string $filename = null): string
    {
        $path = $this->pathBuilder->build($request, $filename);
        $signature = $this->signer->sign($path);

        return $this->endpoint . '/' . $signature . $path;
    }
}
