<?php
/**
 * WordPress/cURL pre-warm HTTP client.
 *
 * Implements PrewarmHttpClient using curl_multi for bounded concurrency
 * (max 5 simultaneous requests). Bypasses wp_remote_* because that API
 * is single-request — for a batch of 50+ URLs, sequential requests
 * would take minutes.
 *
 * Uses HEAD requests (not GET) — we only need to trigger imgproxy's
 * processing + cache fill, not download the response body. imgproxy
 * processes the image on a HEAD request the same as a GET (the
 * processing happens before the response is sent, regardless of
 * method).
 *
 * @package OXPulse\Imager\Infrastructure\Http
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_* -- Legitimate
// use of curl_multi for bounded concurrency (wp_remote_* is single-
// request; a 50-URL batch would take minutes sequentially). The HTTP
// API does not offer a multi-request primitive.

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Http;

use OXPulse\Imager\Application\Prewarm\PrewarmHttpClient;

final class WordPressPrewarmClient implements PrewarmHttpClient
{
    private const MAX_CONCURRENCY = 5;

    public function headBatch(array $imgproxyUrls, int $timeoutSeconds): array
    {
        if (count($imgproxyUrls) === 0) {
            return [];
        }

        if (!function_exists('curl_multi_init')) {
            return array_map(
                fn () => ['status' => 0, 'error' => 'cURL extension not available.', 'elapsed_ms' => 0],
                $imgproxyUrls
            );
        }

        $results = array_fill(0, count($imgproxyUrls), null);
        $handles = [];
        $mh = curl_multi_init();

        // Initialize all handles up front.
        foreach ($imgproxyUrls as $idx => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeoutSeconds, 5));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            // imgproxy needs the Accept header for format negotiation.
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: image/avif,image/webp,image/*,*/*;q=0.8',
            ]);

            $handles[$idx] = $ch;
        }

        // Process with bounded concurrency: add MAX_CONCURRENCY handles
        // to the multi handle, run until some complete, add more, repeat.
        $pending = array_keys($handles);
        $running = 0;

        do {
            // Add handles up to the concurrency limit.
            while (count($pending) > 0 && $running < self::MAX_CONCURRENCY) {
                $idx = array_shift($pending);
                curl_multi_add_handle($mh, $handles[$idx]);
                $running++;
            }

            if ($running === 0) {
                break;
            }

            // Execute.
            do {
                $status = curl_multi_exec($mh, $running);
                if ($status === CURLM_OK && $running > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($status === CURLM_OK && $running > 0);

            // Collect completed handles.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $idx = array_search($ch, $handles, true);
                if ($idx === false) {
                    continue;
                }

                $start = microtime(true);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                $results[$idx] = [
                    'status'     => $httpStatus,
                    'error'      => $error !== '' ? $this->redactUrl($error) : null,
                    'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                ];

                curl_multi_remove_handle($mh, $ch);
                // curl_close() is a no-op since PHP 8.0 and deprecated
                // in 8.5 — curl handles are freed when their refcount
                // hits zero. Remove the call entirely.
            }
        } while (count($pending) > 0 || $running > 0);

        curl_multi_close($mh);

        // Fill any nulls (shouldn't happen, but defensive).
        foreach ($results as $idx => $r) {
            if ($r === null) {
                $results[$idx] = ['status' => 0, 'error' => 'Unknown error.', 'elapsed_ms' => 0];
            }
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
