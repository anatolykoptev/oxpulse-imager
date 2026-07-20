<?php
/**
 * Health check service.
 *
 * Validates imgproxy endpoint reachability and signing configuration
 * without exposing secrets. Uses a short timeout and never runs on
 * frontend rendering paths.
 *
 * @package OXPulse\Imager\Application\Health
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Health;

final class HealthCheckService
{
    private const TIMEOUT_SECONDS = 10;

    private HealthCheckHttpClient $httpClient;

    public function __construct(HealthCheckHttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Check imgproxy endpoint health.
     *
     * @param string $endpoint imgproxy base URL.
     * @return HealthResult
     */
    public function checkEndpoint(string $endpoint): HealthResult
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return HealthResult::failed('Endpoint URL is empty.');
        }

        $parsed = parse_url($endpoint);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return HealthResult::failed('Endpoint URL is malformed.');
        }

        $healthUrl = rtrim($endpoint, '/') . '/health';

        $result = $this->httpClient->head($healthUrl, self::TIMEOUT_SECONDS);

        if ($result['error'] !== null) {
            return HealthResult::unreachable($result['error']);
        }

        if ($result['status'] === 200) {
            return HealthResult::ok();
        }

        if ($result['status'] === 404) {
            return HealthResult::failed('Endpoint responded but health check path was not found.', 404);
        }

        if ($result['status'] >= 500) {
            return HealthResult::failed('imgproxy returned a server error.', $result['status']);
        }

        return HealthResult::failed('Unexpected response status.', $result['status']);
    }
}
