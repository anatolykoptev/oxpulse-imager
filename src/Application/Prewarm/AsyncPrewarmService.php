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

    /**
     * Option prefix for the per-job processing mutex. Taken atomically
     * via add_option() — the row's existence is the lock.
     */
    public const LOCK_OPTION_PREFIX = 'oxpulse_prewarm_lock_';

    /**
     * Lock TTL in seconds. A lock that survives past this belongs to a
     * worker that died mid-batch (fatal/timeout) and is reclaimed.
     */
    public const LOCK_STALE_SECONDS = 300;

    private PrewarmService $syncService;
    private PrewarmJobStore $store;

    /**
     * Lock value this instance wrote per job (`<expiry>|<random hex>`).
     * Release deletes the row only while it still holds OUR value, so an
     * overlong holder never removes the lock a reclaimer now owns.
     *
     * @var array<string, string>
     */
    private array $lockValues = [];

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

        // WP-cron can fire the same hook in overlapping requests; the
        // 'running' status is a state label, not a lock — without a
        // mutex two workers process (and double-warm) the same batch.
        // add_option() is the atomic acquisition.
        if (!$this->acquireLock($jobId)) {
            return;
        }

        try {
            // Re-read under the lock: the previous holder may have
            // completed or advanced the job while we raced for it.
            $job = $this->store->get($jobId);
            if ($job === null || $job['status'] === 'complete' || $job['status'] === 'failed') {
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

            // Schedule the follow-up BEFORE warming. A fatal or timeout in
            // warm() kills this request after its lock expires; nothing
            // would ever re-fire the job, leaving it 'running' forever.
            // A duplicate fire is harmless — it serialises on the lock.
            $this->scheduleFollowUp($jobId);

            // Process this batch synchronously (50 URLs, ~10s).
            $result = $this->syncService->warm(new PrewarmRequest($batch, $widths));

            // Merge results into the job.
            $job['items'] = array_merge($job['items'], $result->toArray()['items']);
            $job['warmed'] += $result->warmedCount();
            $job['skipped'] += $result->skippedCount();
            $job['failed'] += $result->failedCount();
            $job['total'] += $result->total();
            $job['offset'] = $offset + count($batch);

            // Check if more batches remain. The follow-up scheduled above
            // is the next batch; when done, drop it so it does not fire
            // for nothing.
            if ($job['offset'] >= count($urls)) {
                $job['status'] = 'complete';
                if (function_exists('wp_clear_scheduled_hook')) {
                    wp_clear_scheduled_hook(self::CRON_HOOK, [$jobId]);
                }
            }

            $this->store->save($jobId, $job);
        } finally {
            $this->releaseLock($jobId);
        }
    }

    /**
     * Ensure exactly one follow-up cron event exists for the job.
     */
    private function scheduleFollowUp(string $jobId): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::CRON_HOOK, [$jobId]) !== false) {
            return;
        }
        wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$jobId]);
    }

    /**
     * Take the per-job processing mutex. Returns false when a live
     * worker holds an unexpired lock; a stale or corrupt lock is
     * reclaimed for this worker.
     *
     * The lock value is `<expiry>|<random hex>`: the token makes every
     * holder's value unique, so reclaim and release can compare-then-
     * delete instead of deleting whatever is there.
     */
    private function acquireLock(string $jobId): bool
    {
        $option = self::LOCK_OPTION_PREFIX . $jobId;
        $value = (time() + self::LOCK_STALE_SECONDS) . '|' . bin2hex(random_bytes(8));
        if (add_option($option, $value, '', false)) {
            $this->lockValues[$jobId] = $value;
            return true;
        }

        // The row exists. An unexpired lock in the horizon we write
        // means a live worker owns it — bail with no work. Anything
        // else (expired, or a corrupt far-future/negative value no
        // writer of ours produces) is stale and reclaimed. (int) of
        // `<expiry>|<token>` reads the expiry; a legacy bare-int value
        // parses the same way.
        $observed = get_option($option, 0);
        $ttl = (int) $observed - time();
        if ($ttl > 0 && $ttl <= self::LOCK_STALE_SECONDS) {
            return false;
        }

        // Reclaim only the exact stale value we observed. Two reclaimers
        // both saw it, but only one DELETE matches a row; the loser (or
        // anyone facing a value that changed meanwhile) backs off.
        if (!$this->deleteLockIfValue($option, (string) $observed)) {
            return false;
        }
        if (add_option($option, $value, '', false)) {
            $this->lockValues[$jobId] = $value;
            return true;
        }
        return false;
    }

    /**
     * Release the lock, but only while the row still holds the value
     * this instance wrote.
     */
    private function releaseLock(string $jobId): void
    {
        if (!isset($this->lockValues[$jobId])) {
            return;
        }
        $this->deleteLockIfValue(self::LOCK_OPTION_PREFIX . $jobId, $this->lockValues[$jobId]);
        unset($this->lockValues[$jobId]);
    }

    /**
     * Atomic compare-then-delete of an option row: removes it only when
     * option_value still equals $expected. Mirrors core delete_option()'s
     * cache cleanup (the row is autoload=no) so a following add_option()
     * does not see the stale cached value. True when this call deleted it.
     */
    private function deleteLockIfValue(string $option, string $expected): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic compare-and-delete mutex; delete_option() cannot condition on the value, and a DELETE is not cacheable.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $option,
                $expected
            )
        );

        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');

        return (int) $deleted > 0;
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
