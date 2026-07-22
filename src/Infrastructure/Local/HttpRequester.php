<?php
/**
 * HTTP requester port for the rewrite capability probe.
 *
 * A thin abstraction over wp_remote_get() so the probe can be unit-tested
 * without a real HTTP round-trip. Mirrors the htaccess-capability-tester
 * HttpRequesterInterface contract: a single get() method returning a
 * normalized status+body array (or an error indicator).
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

interface HttpRequester
{
    /**
     * Issue an HTTP GET.
     *
     * @param string $url
     * @return array{status: int, body: string, error: ?string}
     *   - status: HTTP response code, or 0 on transport failure.
     *   - body:   response body (empty string on failure).
     *   - error:  non-null when a transport error occurred.
     */
    public function get(string $url): array;
}
