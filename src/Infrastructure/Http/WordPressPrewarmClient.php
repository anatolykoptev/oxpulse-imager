<?php
/**
 * WordPress HTTP API pre-warm client.
 *
 * Implements PrewarmHttpClient using wp_remote_head() for each URL in
 * the batch. Runs in WP context (prewarm cron / REST / WP-CLI), so
 * wp-load is available and the WP HTTP API is the correct transport —
 * raw curl_* triggers WordPress.WP.AlternativeFunctions.curl_curl_error
 * (a WARNING that is not suppressable via ignore-codes for wordpress.org
 * submission).
 *
 * HEAD requests are used (not GET) — we only need to trigger imgproxy's
 * processing + cache fill, not download the response body. imgproxy
 * processes the image on a HEAD request the same as a GET (the
 * processing happens before the response is sent, regardless of method).
 *
 * The WP HTTP API is single-request (no multi/concurrency primitive),
 * so the batch is sequential. For a prewarm cron job this is acceptable
 * — the batch is bounded (max 50 URLs) and each HEAD completes in well
 * under the per-request timeout. The previous curl_multi implementation
 * provided bounded concurrency (5 simultaneous) but at the cost of
 * wordpress.org Plugin Check compliance.
 *
 * @package OXPulse\Imager\Infrastructure\Http
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Http;

use OXPulse\Imager\Application\Prewarm\PrewarmHttpClient;

final class WordPressPrewarmClient implements PrewarmHttpClient
{
    public function headBatch(array $imgproxyUrls, int $timeoutSeconds): array
    {
        if (count($imgproxyUrls) === 0) {
            return [];
        }

        if (!function_exists('wp_remote_head')) {
            return array_map(
                fn () => ['status' => 0, 'error' => __('WordPress HTTP API not available.', 'oxpulse-imager'), 'elapsed_ms' => 0],
                $imgproxyUrls
            );
        }

        $results = [];
        foreach ($imgproxyUrls as $idx => $url) {
            $start = microtime(true);

            $response = wp_remote_head($url, [
                'timeout' => $timeoutSeconds,
                'redirection' => 0,
                'sslverify' => true,
                // imgproxy needs the Accept header for format negotiation.
                'headers' => [
                    'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8',
                ],
            ]);

            $elapsedMs = (int) round((microtime(true) - $start) * 1000);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                $results[$idx] = [
                    'status' => 0,
                    'error' => $this->redactUrl($error ?? ''),
                    'elapsed_ms' => $elapsedMs,
                ];
                continue;
            }

            $results[$idx] = [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'error' => null,
                'elapsed_ms' => $elapsedMs,
            ];
        }

        return $results;
    }

    /**
     * Redact URL components from error messages (same pattern as
     * WordPressHealthClient — never leak source URLs into logs).
     */
    private function redactUrl(string $message): string
    {
        return preg_replace('#https?://[^\s]+#i', '[url]', $message) ?? $message;
    }
}
