<?php
/**
 * HTTP client interface for health checks.
 *
 * Abstracts the HTTP transport so HealthCheckService can be tested
 * with a stub client. Production implementation uses wp_remote_head()
 * and wp_remote_get().
 *
 * @package OXPulse\Imager\Application\Health
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Health;

interface HealthCheckHttpClient
{
    /**
     * Perform a HEAD request to the given URL with a short timeout.
     *
     * @param string $url The health check URL.
     * @param int $timeoutSeconds
     * @return array{status: int, error: ?string, headers: array<string,string>}
     */
    public function head(string $url, int $timeoutSeconds): array;

    /**
     * Perform a GET request with optional custom headers.
     *
     * Used for format negotiation checks — sends a specific Accept
     * header and inspects the Content-Type of the response.
     *
     * @param string $url The URL to fetch.
     * @param int $timeoutSeconds
     * @param array<string,string> $headers Custom request headers.
     * @return array{status: int, error: ?string, headers: array<string,string>}
     */
    public function get(string $url, int $timeoutSeconds, array $headers = []): array;
}
