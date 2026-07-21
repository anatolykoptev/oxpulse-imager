<?php
/**
 * Pre-warm HTTP client port.
 *
 * The Application layer's interface for dispatching batch HEAD requests
 * to imgproxy. The Infrastructure layer provides the implementation
 * (WordPressPrewarmClient using curl_multi for concurrency).
 *
 * @package OXPulse\Imager\Application\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Prewarm;

interface PrewarmHttpClient
{
    /**
     * Dispatch HEAD requests to a batch of signed imgproxy URLs with
     * bounded concurrency. Returns one result per URL, in the same
     * order as the input.
     *
     * @param array<int,string> $imgproxyUrls  Signed imgproxy URLs to warm.
     * @param int               $timeoutSeconds Per-request timeout.
     * @return array<int,array{status: int, error: ?string, elapsed_ms: int}>
     */
    public function headBatch(array $imgproxyUrls, int $timeoutSeconds): array;
}
