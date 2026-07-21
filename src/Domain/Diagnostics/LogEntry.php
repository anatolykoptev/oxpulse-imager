<?php
/**
 * Diagnostic log entry value object.
 *
 * One entry per rewrite decision (rewritten or preserved). Captured
 * by the DiagnosticLogger during the request and serialized to
 * error_log at shutdown.
 *
 * @package OXPulse\Imager\Domain\Diagnostics
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Diagnostics;

final readonly class LogEntry
{
    public function __construct(
        public string $context,
        public bool   $rewritten,
        public string $reason,
        public string $sourceUrl,
        public int    $width,
        public float  $timestamp
    ) {}

    public static function rewritten(string $context, string $sourceUrl, int $width, string $reason = 'rewritten'): self
    {
        return new self($context, true, $reason, $sourceUrl, $width, microtime(true));
    }

    public static function preserved(string $context, string $sourceUrl, int $width, string $reason): self
    {
        return new self($context, false, $reason, $sourceUrl, $width, microtime(true));
    }

    /**
     * @return array{context: string, rewritten: bool, reason: string, sourceUrl: string, width: int, timestamp: float}
     */
    public function toArray(): array
    {
        return [
            'context'   => $this->context,
            'rewritten' => $this->rewritten,
            'reason'    => $this->reason,
            'sourceUrl' => $this->sourceUrl,
            'width'     => $this->width,
            'timestamp' => $this->timestamp,
        ];
    }
}
