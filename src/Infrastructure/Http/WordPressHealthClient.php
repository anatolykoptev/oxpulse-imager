<?php
/**
 * WordPress HTTP API health check client.
 *
 * Uses wp_remote_head() for health checks. Never runs on frontend
 * rendering paths; only invoked by the admin Test Connection action.
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
            return ['status' => 0, 'error' => 'WordPress HTTP API not available.'];
        }

        $response = wp_remote_head($url, [
            'timeout' => $timeoutSeconds,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            // Redact any URL components from error messages.
            $error = preg_replace('#https?://[^\s]+#i', '[url]', $error ?? '');
            return ['status' => 0, 'error' => $error];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return ['status' => $status, 'error' => null];
    }
}
