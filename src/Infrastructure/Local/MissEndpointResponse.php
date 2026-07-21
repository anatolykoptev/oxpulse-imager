<?php
/**
 * Miss-endpoint response value object.
 *
 * The handler produces this; the generated endpoint file emits the
 * HTTP headers and streams the body or filePath. Separating logic
 * from I/O makes the handler fully unit-testable without xdebug.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

final readonly class MissEndpointResponse
{
    /**
     * @param int $status HTTP status code.
     * @param string $contentType Content-Type header value.
     * @param array<string,string|int> $headers Additional headers.
     * @param string|null $body Inline body bytes (when set, stream these).
     * @param string|null $filePath File to readfile (when body is null and
     *        a file should be streamed — e.g. fail-safe original serving).
     */
    public function __construct(
        public int $status,
        public string $contentType,
        public array $headers = [],
        public ?string $body = null,
        public ?string $filePath = null,
    ) {}
}
