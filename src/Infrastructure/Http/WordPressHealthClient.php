<?php
/**
 * WordPress HTTP API health check client.
 *
 * Uses wp_remote_head() and wp_remote_get() for health checks. Never
 * runs on frontend rendering paths; only invoked by the admin Test
 * Connection action.
 *
 * @package OXPulse\Imager\Infrastructure\Http
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Http;

use OXPulse\Imager\Application\Health\HealthCheckHttpClient;

final class WordPressHealthClient implements HealthCheckHttpClient
{
    public function head(string $url, int $timeoutSeconds): array
    {
        if (!function_exists('wp_remote_head')) {
            return ['status' => 0, 'error' => __('WordPress HTTP API not available.', 'oxpulse-imager'), 'headers' => []];
        }

        $response = wp_remote_head($url, [
            'timeout' => $timeoutSeconds,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        return $this->parseResponse($response);
    }

    public function get(string $url, int $timeoutSeconds, array $headers = []): array
    {
        if (!function_exists('wp_remote_get')) {
            return ['status' => 0, 'error' => __('WordPress HTTP API not available.', 'oxpulse-imager'), 'headers' => []];
        }

        $response = wp_remote_get($url, [
            'timeout' => $timeoutSeconds,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
        ]);

        return $this->parseResponse($response);
    }

    private function parseResponse($response): array
    {
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            // Redact any URL components from error messages.
            $error = preg_replace('#https?://[^\s]+#i', '[url]', $error ?? '');
            return ['status' => 0, 'error' => $error, 'headers' => []];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);

        // wp_remote_retrieve_headers returns a WpOrg\Requests\Utility\CaseInsensitiveDictionary
        // or an array. Convert to a plain array.
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        } elseif (is_object($headers) && $headers instanceof \ArrayObject) {
            $headers = $headers->getArrayCopy();
        } elseif (!is_array($headers)) {
            $headers = [];
        }

        return ['status' => $status, 'error' => null, 'headers' => $headers];
    }
}
