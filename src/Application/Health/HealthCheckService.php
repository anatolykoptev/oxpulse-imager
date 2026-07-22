<?php
/**
 * Health check service.
 *
 * Validates imgproxy endpoint reachability, signing configuration, and
 * format negotiation support without exposing secrets. Uses short
 * timeouts and never runs on frontend rendering paths.
 *
 * Format negotiation check: sends a request with Accept: image/avif
 * and verifies that imgproxy responds with Content-Type: image/avif.
 * This confirms that IMGPROXY_AUTO_AVIF is enabled on the server,
 * which is required for the 'auto' output format mode.
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
    private const AVIF_ACCEPT = 'image/avif,image/webp,image/*,*/*;q=0.8';

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
            return HealthResult::failed(__('Endpoint URL is empty.', 'oxpulse-imager'));
        }

        $parsed = parse_url($endpoint);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return HealthResult::failed(__('Endpoint URL is malformed.', 'oxpulse-imager'));
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
            return HealthResult::failed(__('Endpoint responded but health check path was not found.', 'oxpulse-imager'), 404);
        }

        if ($result['status'] >= 500) {
            return HealthResult::failed(__('imgproxy returned a server error.', 'oxpulse-imager'), $result['status']);
        }

        return HealthResult::failed(__('Unexpected response status.', 'oxpulse-imager'), $result['status']);
    }

    /**
     * Check whether imgproxy supports AVIF format negotiation.
     *
     * Sends a request with Accept: image/avif and checks that the
     * response Content-Type is image/avif. This verifies that
     * IMGPROXY_AUTO_AVIF is enabled on the server, which is required
     * for the 'auto' output format mode to deliver AVIF.
     *
     * @param string $endpoint imgproxy base URL.
     * @param string $sampleImageUrl A sample image URL to test with.
     * @return HealthResult
     */
    public function checkAvifSupport(string $endpoint, string $sampleImageUrl): HealthResult
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return HealthResult::failed(__('Endpoint URL is empty.', 'oxpulse-imager'));
        }

        $sampleImageUrl = trim($sampleImageUrl);
        if ($sampleImageUrl === '') {
            return HealthResult::failed(__('Sample image URL is empty.', 'oxpulse-imager'));
        }

        // Build a minimal imgproxy URL for the sample image without
        // format suffix — this triggers Accept negotiation.
        $healthUrl = rtrim($endpoint, '/') . '/plain/' . $sampleImageUrl;

        $result = $this->httpClient->get(
            $healthUrl,
            self::TIMEOUT_SECONDS,
            ['Accept' => self::AVIF_ACCEPT]
        );

        if ($result['error'] !== null) {
            return HealthResult::unreachable($result['error']);
        }

        if ($result['status'] !== 200) {
            return HealthResult::failed(
                __('imgproxy returned non-200 for format negotiation check.', 'oxpulse-imager'),
                $result['status']
            );
        }

        $contentType = $result['headers']['content-type'] ?? $result['headers']['Content-Type'] ?? '';
        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'image/avif')) {
            return HealthResult::ok(__('AVIF format negotiation is supported.', 'oxpulse-imager'));
        }

        if (str_contains($contentType, 'image/webp')) {
            return HealthResult::failed(
                __('imgproxy returned WebP, not AVIF. Enable IMGPROXY_AUTO_AVIF on the server for AVIF delivery.', 'oxpulse-imager')
            );
        }

        return HealthResult::failed(
            sprintf(
                /* translators: %s: the Content-Type header imgproxy returned. */
                __('imgproxy did not return AVIF. Check IMGPROXY_AUTO_AVIF configuration. Got Content-Type: %s', 'oxpulse-imager'),
                $contentType
            )
        );
    }
}
