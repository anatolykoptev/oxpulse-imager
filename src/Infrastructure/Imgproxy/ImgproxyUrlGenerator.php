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
        // Preserve relative endpoints (e.g. '/imgproxy' for same-host reverse-proxy
        // setups via nginx). Only strip trailing slashes; do NOT prepend a scheme.
        $this->endpoint = $endpoint === '' ? '' : rtrim($endpoint, '/');
    }

    /**
     * Generate a complete signed imgproxy URL.
     *
     * For absolute endpoints (https://imgproxy.example.com): returns the full URL.
     * For relative endpoints (/imgproxy): returns a root-relative URL — the browser
     * resolves it against the current page's host, and nginx reverse-proxies it to
     * the imgproxy daemon. This is the standard same-host deployment pattern.
     *
     * @param TransformRequest $request
     * @param string|null $filename Optional filename for Content-Disposition.
     * @return string Full signed URL, e.g. "https://imgproxy.example.com/sig/rs:fit:800:0/plain/..."
     *         or "/imgproxy/sig/rs:fit:800:0/local://..." for relative endpoints.
     */
    public function generate(TransformRequest $request, ?string $filename = null): string
    {
        $path = $this->pathBuilder->build($request, $filename);
        $signature = $this->signer->sign($path);

        return $this->endpoint . '/' . $signature . $path;
    }
}
