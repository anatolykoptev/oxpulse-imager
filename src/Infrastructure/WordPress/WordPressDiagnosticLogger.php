<?php
/**
 * WordPress diagnostic logger.
 *
 * Accumulates rewrite decisions in memory during the request, then
 * flushes to error_log() at shutdown. The log level is read from the
 * diagnostic_level option:
 *
 * - 'off':     silent — no logging, no accumulation, no flush
 * - 'basic':   per-request summary only (counts by context + reason)
 * - 'verbose': per-URL entries with source URL, width, reason
 *
 * Recent entries (last 100) are also stored in a transient for the
 * admin diagnostics page. The transient auto-expires after 1 hour.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Domain\Diagnostics\LogEntry;

final class WordPressDiagnosticLogger implements DiagnosticLoggerInterface
{
    public const RECENT_ENTRIES_TRANSIENT = 'oxpulse_imager_recent_log';
    public const RECENT_ENTRIES_MAX = 100;
    public const TRANSIENT_EXPIRY = 3600;

    private OptionSettingsRepository $repository;

    /** @var array<int,LogEntry> */
    private array $entries = [];

    private ?string $level = null;

    public function __construct(?OptionSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new OptionSettingsRepository();
    }

    public function log(LogEntry $entry): void
    {
        if ($this->level() === 'off') {
            return;
        }

        // 'basic' accumulates entries for the summary but doesn't
        // need per-URL detail. We still store them because the summary
        // is derived from the entries. The difference is in what gets
        // written to error_log at flush time.
        $this->entries[] = $entry;
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getSummary(): array
    {
        $rewritten = 0;
        $preserved = 0;

        foreach ($this->entries as $entry) {
            if ($entry->rewritten) {
                $rewritten++;
            } else {
                $preserved++;
            }
        }

        return [
            'rewritten' => $rewritten,
            'preserved' => $preserved,
            'total'     => count($this->entries),
        ];
    }

    public function flush(): void
    {
        $level = $this->level();
        if ($level === 'off' || count($this->entries) === 0) {
            return;
        }

        $summary = $this->getSummary();

        if ($level === 'basic') {
            // Per-request summary only: counts by context + reason.
            $byContext = [];
            foreach ($this->entries as $entry) {
                $key = $entry->context . ':' . ($entry->rewritten ? 'rewritten' : 'preserved:' . $entry->reason);
                $byContext[$key] = ($byContext[$key] ?? 0) + 1;
            }

            $lines = ['OXPulse Imager: ' . $summary['total'] . ' rewrites (' . $summary['rewritten'] . ' rewritten, ' . $summary['preserved'] . ' preserved)'];
            foreach ($byContext as $key => $count) {
                $lines[] = '  ' . $key . ': ' . $count;
            }
            error_log(implode("\n", $lines));
        } else {
            // 'verbose': per-URL entries.
            $lines = ['OXPulse Imager: ' . $summary['total'] . ' rewrites (' . $summary['rewritten'] . ' rewritten, ' . $summary['preserved'] . ' preserved)'];
            foreach ($this->entries as $entry) {
                $status = $entry->rewritten ? 'rewritten' : 'preserved:' . $entry->reason;
                $lines[] = sprintf(
                    '  [%s] %s %s (w=%d) — %s',
                    $entry->context,
                    $status,
                    $this->redactUrl($entry->sourceUrl),
                    $entry->width,
                    $entry->reason
                );
            }
            error_log(implode("\n", $lines));
        }

        // Store recent entries in a transient for the admin page.
        $this->storeRecentEntries();
    }

    /**
     * Get the recent log entries from the transient (for the admin
     * diagnostics page). Returns the most recent entries first,
     * capped at RECENT_ENTRIES_MAX.
     *
     * @return array<int,array>
     */
    public function getRecentEntries(): array
    {
        $data = get_transient(self::RECENT_ENTRIES_TRANSIENT);
        if (!is_array($data)) {
            return [];
        }
        // Most recent first.
        return array_slice(array_reverse($data), 0, self::RECENT_ENTRIES_MAX);
    }

    /**
     * Store the current request's entries in the recent-entries
     * transient. Merges with existing entries, caps at
     * RECENT_ENTRIES_MAX * 2 (to show ~2 requests of history).
     */
    private function storeRecentEntries(): void
    {
        $existing = get_transient(self::RECENT_ENTRIES_TRANSIENT);
        if (!is_array($existing)) {
            $existing = [];
        }

        $newEntries = array_map(fn (LogEntry $e) => $e->toArray(), $this->entries);
        $merged = array_merge($existing, $newEntries);

        // Cap at 2x the display limit (keeps ~2 requests of history).
        $merged = array_slice($merged, -self::RECENT_ENTRIES_MAX * 2);

        set_transient(self::RECENT_ENTRIES_TRANSIENT, $merged, self::TRANSIENT_EXPIRY);
    }

    private function level(): string
    {
        if ($this->level === null) {
            $this->level = (string) get_option(OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL, 'off');
        }
        return $this->level;
    }

    /**
     * Redact URL components from log entries — same pattern as the
     * HTTP clients. Never leak full source URLs into error_log.
     */
    private function redactUrl(string $url): string
    {
        // Keep the host + path structure but redact query strings.
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return '[url]';
        }
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        // Truncate path to first segment + ellipsis if too long.
        if (strlen($path) > 40) {
            $path = substr($path, 0, 37) . '...';
        }
        return $scheme . '://' . $host . $path;
    }
}
