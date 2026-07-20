<?php
/**
 * HTTP client interface for health checks.
 *
 * Abstracts the HTTP transport so HealthCheckService can be tested
 * with a stub client. Production implementation uses wp_remote_head().
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
     * @param string $url The health check URL (already signed if needed).
     * @param int $timeoutSeconds
     * @return array{status: int, error: ?string}
     */
    public function head(string $url, int $timeoutSeconds): array;
}
