<?php
/**
 * Default HttpRequester backed by wp_remote_get().
 *
 * Wraps the WordPress HTTP API with a 3s timeout and a wildcard Accept.
 * sslverify follows WordPress defaults (true under HTTPS, configurable
 * via the sslverify arg). Used as the default requester when
 * CapabilityTester lazily constructs a LocalRewriteProbe.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

class WpRemoteHttpRequester implements HttpRequester
{
    public function get(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout' => 3,
            'headers' => ['Accept' => '*/*'],
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => '', 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    /**
     * HEAD request for the imgproxy health probe (write-time only).
     *
     * Security constraints enforced HERE, not by the caller:
     * - timeout 2s (bounded — never hangs the admin/cron path).
     * - redirection = 0 (never follow a redirect — the probe touches
     *   ONLY the admin-configured endpoint host, no SSRF / open-redirect
     *   surface).
     * - HEAD method (no response body downloaded).
     * - sslverify true (default — do not silently downgrade to insecure).
     *
     * The caller (ImgproxyBackendProvider::recheck) is responsible for
     * passing ONLY the admin-configured endpoint URL; this method does
     * not scan, guess, or derive other hosts.
     */
    public function head(string $url): array
    {
        $response = wp_remote_head($url, [
            'timeout' => 2,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => '', 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return ['status' => $status, 'body' => '', 'error' => null];
    }
}
