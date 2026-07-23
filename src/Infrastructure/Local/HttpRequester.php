<?php
/**
 * HTTP requester port for the rewrite capability probe + imgproxy health probe.
 *
 * A thin abstraction over the WordPress HTTP API so probes can be unit-tested
 * without a real HTTP round-trip. Mirrors the htaccess-capability-tester
 * HttpRequesterInterface contract: get() returns a normalized status+body
 * array (or an error indicator); head() returns status-only (no body) for
 * the bounded imgproxy health probe.
 *
 * Security: the concrete implementation enforces the probe safety constraints
 * (bounded timeout, redirection = 0) so a misconfigured caller cannot weaken
 * them. head() issues a HEAD request (no response body) and never follows
 * redirects — the imgproxy health probe must not be an SSRF / redirect vector.
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

    /**
     * Issue an HTTP HEAD (no response body). Used by the imgproxy
     * health probe — bounded timeout + redirection = 0 are enforced
     * by the implementation, not the caller.
     *
     * @param string $url
     * @return array{status: int, body: string, error: ?string}
     *   - status: HTTP response code, or 0 on transport failure.
     *   - body:   always '' (HEAD carries no body).
     *   - error:  non-null when a transport error occurred.
     */
    public function head(string $url): array;
}
