<?php
/**
 * Pre-warm service.
 *
 * Orchestrates a batch pre-warm: for each (source URL × width)
 * combination, builds a signed imgproxy URL via the existing
 * UrlRewriter pipeline (so only authorized sources are warmed),
 * then dispatches HEAD requests in batch via PrewarmHttpClient.
 *
 * Fails safe: any URL that can't be rewritten (unauthorized source,
 * missing config, generation error) is recorded as 'skipped' with a
 * reason — never throws.
 *
 * @package OXPulse\Imager\Application\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Prewarm;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Prewarm\PrewarmBatchResult;
use OXPulse\Imager\Domain\Prewarm\PrewarmItemResult;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;

final class PrewarmService
{
    private const TIMEOUT_SECONDS = 10;

    private UrlRewriter $rewriter;
    private PrewarmHttpClient $httpClient;

    public function __construct(UrlRewriter $rewriter, PrewarmHttpClient $httpClient)
    {
        $this->rewriter = $rewriter;
        $this->httpClient = $httpClient;
    }

    /**
     * Warm a batch of source URLs at the given widths.
     *
     * @param PrewarmRequest $request
     * @return PrewarmBatchResult
     */
    public function warm(PrewarmRequest $request): PrewarmBatchResult
    {
        $items = [];
        $imgproxyUrls = [];
        $itemKeys = [];

        // Phase 1: build signed imgproxy URLs via the same pipeline the
        // frontend uses. URLs that can't be rewritten are recorded as
        // 'skipped' — they don't go to the HTTP batch.
        foreach ($request->sourceUrls as $sourceUrl) {
            foreach ($request->widths as $width) {
                $result = $this->rewriter->rewrite($sourceUrl, $width, 0, 'prewarm');

                if (!$result->rewritten) {
                    $items[] = PrewarmItemResult::skipped(
                        $sourceUrl,
                        $width,
                        '',
                        $result->reason
                    );
                    continue;
                }

                $imgproxyUrls[] = $result->url;
                $itemKeys[] = ['sourceUrl' => $sourceUrl, 'width' => $width];
            }
        }

        // Phase 2: dispatch HEAD requests in batch with bounded concurrency.
        if (count($imgproxyUrls) === 0) {
            return new PrewarmBatchResult($items);
        }

        $httpResults = $this->httpClient->headBatch($imgproxyUrls, self::TIMEOUT_SECONDS);

        // Phase 3: map HTTP results back to item results.
        foreach ($httpResults as $idx => $http) {
            $key = $itemKeys[$idx];
            $imgproxyUrl = $imgproxyUrls[$idx];

            if ($http['error'] !== null) {
                $items[] = PrewarmItemResult::failed(
                    $key['sourceUrl'],
                    $key['width'],
                    $imgproxyUrl,
                    $http['error']
                );
                continue;
            }

            if ($http['status'] === 200) {
                $items[] = PrewarmItemResult::warmed(
                    $key['sourceUrl'],
                    $key['width'],
                    $imgproxyUrl,
                    $http['status']
                );
                continue;
            }

            $items[] = PrewarmItemResult::failed(
                $key['sourceUrl'],
                $key['width'],
                $imgproxyUrl,
                sprintf(__('imgproxy returned HTTP %d', 'oxpulse-imager'), $http['status']),
                $http['status']
            );
        }

        return new PrewarmBatchResult($items);
    }
}
