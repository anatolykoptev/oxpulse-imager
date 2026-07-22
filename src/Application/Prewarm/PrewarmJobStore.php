<?php
/**
 * Pre-warm job store (transient-based).
 *
 * Stores async pre-warm job state in WordPress transients. Each job
 * has a UUID, status (pending/running/complete/failed), progress
 * counters, and per-URL results. Transients expire after 1 hour —
 * enough for any reasonable pre-warm to complete + be polled.
 *
 * @package OXPulse\Imager\Application\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Prewarm;

final class PrewarmJobStore
{
    private const TRANSIENT_PREFIX = 'oxpulse_prewarm_job_';
    private const EXPIRY_SECONDS = 3600;

    public function create(string $jobId, array $sourceUrls, array $widths): void
    {
        $job = [
            'id'        => $jobId,
            'status'    => 'pending',
            'urls'      => $sourceUrls,
            'widths'    => $widths,
            'offset'    => 0,
            'total'     => 0,
            'warmed'    => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'items'     => [],
            'createdAt' => time(),
            'updatedAt' => time(),
        ];
        $this->save($jobId, $job);
    }

    public function get(string $jobId): ?array
    {
        $data = get_transient(self::TRANSIENT_PREFIX . $jobId);
        return is_array($data) ? $data : null;
    }

    public function save(string $jobId, array $job): void
    {
        $job['updatedAt'] = time();
        set_transient(self::TRANSIENT_PREFIX . $jobId, $job, self::EXPIRY_SECONDS);
    }

    public function delete(string $jobId): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $jobId);
    }

    /**
     * Get all pending/running job IDs by scanning the options table
     * for transient names. This is used by the cron handler to find
     * jobs that need processing.
     *
     * @return array<int,string>
     */
    public function getPendingJobIds(): array
    {
        // Transients are stored in options as _transient_<name>.
        // We query the options table directly for efficiency.
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !property_exists($wpdb, 'options')) {
            return [];
        }

        $prefix = '_transient_' . self::TRANSIENT_PREFIX;
        $like = $wpdb->esc_like($prefix) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- job-queue state must bypass object cache; stale reads would double-run/skip jobs.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        $ids = [];
        foreach ($rows as $row) {
            $name = $row->option_name;
            if (str_starts_with($name, $prefix)) {
                $ids[] = substr($name, strlen($prefix));
            }
        }
        return $ids;
    }
}
