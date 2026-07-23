<?php
/**
 * Diagnostic logger port.
 *
 * The Application layer's interface for recording rewrite decisions.
 * The Infrastructure layer provides the implementation
 * (WordPressDiagnosticLogger using error_log + transient storage).
 *
 * @package OXPulse\Imager\Application\Diagnostics
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Diagnostics;

use OXPulse\Imager\Domain\Diagnostics\LogEntry;

interface DiagnosticLoggerInterface
{
    /**
     * Record a rewrite decision. Called by UrlRewriter on every
     * rewrite attempt (rewritten or preserved).
     */
    public function log(LogEntry $entry): void;

    /**
     * Get all entries accumulated during the current request.
     *
     * @return array<int,LogEntry>
     */
    public function getEntries(): array;

    /**
     * Get summary counts for the current request.
     *
     * @return array{rewritten: int, preserved: int, total: int}
     */
    public function getSummary(): array;

    /**
     * Flush the accumulated entries to the persistent log
     * (error_log + transient). Called once at shutdown.
     */
    public function flush(): void;

    /**
     * #92: Record a one-shot operational warning (NOT a rewrite
     * decision — e.g. "background pre-warm blocked because WP-Cron
     * is disabled"). Written to error_log immediately (not deferred
     * to shutdown), gated on the diagnostic level being non-'off'
     * so an operator who silenced diagnostics stays silent.
     *
     * Distinct from log(LogEntry): LogEntry models a per-URL rewrite
     * decision and is accumulated for the request summary; a warning
     * is a single operational event with no per-URL shape.
     */
    public function warning(string $message): void;
}
