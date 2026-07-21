<?php
/**
 * Pre-warm batch result value object.
 *
 * Aggregates per-item results for a full pre-warm batch, with summary
 * counts (warmed / skipped / failed / total).
 *
 * @package OXPulse\Imager\Domain\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Prewarm;

final readonly class PrewarmBatchResult
{
    /**
     * @param array<int,PrewarmItemResult> $items
     */
    public function __construct(
        public array $items
    ) {}

    public function total(): int
    {
        return count($this->items);
    }

    public function warmedCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i->status === 'warmed'));
    }

    public function skippedCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i->status === 'skipped'));
    }

    public function failedCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i->status === 'failed'));
    }

    /**
     * Serialize to the array shape the REST API + SPA expect.
     *
     * @return array{total: int, warmed: int, skipped: int, failed: int, items: array<int,array>}
     */
    public function toArray(): array
    {
        return [
            'total'   => $this->total(),
            'warmed'  => $this->warmedCount(),
            'skipped' => $this->skippedCount(),
            'failed'  => $this->failedCount(),
            'items'   => array_map(fn ($i) => $i->toArray(), $this->items),
        ];
    }
}
