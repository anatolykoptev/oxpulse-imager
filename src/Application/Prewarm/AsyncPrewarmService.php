<?php
/**
 * Async pre-warm service.
 *
 * Creates pre-warm jobs, schedules cron processing, and processes
 * one batch per cron tick. Job state is persisted in PrewarmJobStore
 * (transient-based).
 *
 * Flow:
 * 1. createJob() — stores job as 'pending', schedules a single cron
 *    event (WP cron, ~1 minute).
 * 2. The cron hook fires processNextBatch() — processes up to 50 URLs,
 *    updates the job, and if more remain, schedules another event.
 * 3. The SPA/external client polls getJob() until status is 'complete'
 *    or 'failed'.
 *
 * @package OXPulse\Imager\Application\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Prewarm;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;

final class AsyncPrewarmService
{
    public const CRON_HOOK = 'oxpulse_prewarm_process_batch';
    public const BATCH_SIZE = 50;

    private PrewarmService $syncService;
    private PrewarmJobStore $store;

    public function __construct(PrewarmService $syncService, PrewarmJobStore $store)
    {
        $this->syncService = $syncService;
        $this->store = $store;
    }

    /**
     * #92: Detect whether WP-Cron is disabled on this host.
     *
     * Kinsta, WP Engine, Pantheon and similar hosts set
     * `define('DISABLE_WP_CRON', true);` and run wp-cron.php via a
     * real system cron (or not at all). When disabled, any job
     * scheduled via wp_schedule_single_event() will NEVER fire from
     * traffic — so an async pre-warm job would be enqueued and
     * silently never run. This is the deterministic signal; a
     * "likely-dead" WP-Cron (no traffic) is not detectable reliably.
     *
     * Mirrors the check WP core itself uses to short-circuit
     * wp-cron.php. Truthy values (bool true, string 'true') count
     * as disabled; `define('DISABLE_WP_CRON', false)` does NOT.
     */
    public static function isWpCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
    }

    /**
     * Create a new async pre-warm job and schedule the first batch.
     *
     * @param array<int,string> $sourceUrls
     * @param array<int,int>    $widths
     * @return string The job ID.
     */
    public function createJob(array $sourceUrls, array $widths): string
    {
        $jobId = $this->generateJobId();
        $this->store->create($jobId, $sourceUrls, $widths);

        // #92: Do NOT schedule a cron event when WP-Cron is disabled
        // (DISABLE_WP_CRON) — it would never fire and the job would
        // silently sit 'pending' forever. The REST controller checks
        // this BEFORE calling createJob and returns a 503 pointing to
        // the CLI/system-cron alternative; this guard is defensive so
        // a direct caller also never queues a dead event.
        if (self::isWpCronDisabled()) {
            return $jobId;
        }

        // Schedule the first batch processing.
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$jobId]);
        }

        return $jobId;
    }

    /**
     * Get the current state of a job.
     */
    public function getJob(string $jobId): ?array
    {
        return $this->store->get($jobId);
    }

    /**
     * Process the next batch of URLs for a job. Called by the cron
     * hook. Processes up to BATCH_SIZE URLs, updates the job state,
     * and schedules the next batch if more URLs remain.
     */
    public function processNextBatch(string $jobId): void
    {
        $job = $this->store->get($jobId);
        if ($job === null) {
            return;
        }

        if ($job['status'] === 'complete' || $job['status'] === 'failed') {
            return;
        }

        $job['status'] = 'running';
        $this->store->save($jobId, $job);

        $urls = $job['urls'];
        $widths = $job['widths'];
        $offset = $job['offset'];

        $batch = array_slice($urls, $offset, self::BATCH_SIZE);

        if (count($batch) === 0) {
            // All done.
            $job['status'] = 'complete';
            $this->store->save($jobId, $job);
            return;
        }

        // Process this batch synchronously (50 URLs, ~10s).
        $result = $this->syncService->warm(new PrewarmRequest($batch, $widths));

        // Merge results into the job.
        $job['items'] = array_merge($job['items'], $result->toArray()['items']);
        $job['warmed'] += $result->warmedCount();
        $job['skipped'] += $result->skippedCount();
        $job['failed'] += $result->failedCount();
        $job['total'] += $result->total();
        $job['offset'] = $offset + count($batch);

        // Check if more batches remain.
        if ($job['offset'] >= count($urls)) {
            $job['status'] = 'complete';
        } else {
            // Schedule the next batch.
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$jobId]);
            }
        }

        $this->store->save($jobId, $job);
    }

    /**
     * Register the cron hook handler.
     */
    public function registerCronHandler(): void
    {
        add_action(self::CRON_HOOK, [$this, 'processNextBatch']);
    }

    /**
     * Generate a v4 UUID (RFC 4122).
     */
    private function generateJobId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
