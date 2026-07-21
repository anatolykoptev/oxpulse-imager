<?php
/**
 * Source authorization decision.
 *
 * Carries the authorization verdict and, when authorized, the resolved source
 * — either a NormalizedUrl (for 'http' source mode) or a filesystem path
 * (for 'local' source mode). The `fsPath` field is non-null only when the
 * source was authorized for local:// delivery; in that case `url` still
 * carries the parsed URL for logging/diagnostics, but the rewriter should
 * use `fsPath` as the TransformRequest source.
 *
 * @package OXPulse\Imager\Domain\Source
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Source;

final readonly class SourceDecision
{
    private function __construct(
        public bool $authorized,
        public string $reason,
        public ?NormalizedUrl $url,
        public ?string $fsPath = null
    ) {}

    public static function authorized(NormalizedUrl $url): self
    {
        return new self(true, 'authorized', $url);
    }

    /**
     * Authorized for local:// source mode. The filesystem path is the
     * resolved, realpath()-verified, traversal-safe path to the source
     * image. The rewriter passes this path (not the URL) to the
     * TransformRequest so ImgproxyPathBuilder can base64url-encode it
     * into the local:// segment.
     */
    public static function authorizedLocal(NormalizedUrl $url, string $fsPath): self
    {
        return new self(true, 'authorized_local', $url, $fsPath);
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason, null);
    }
}
