<?php
/**
 * Rewrite result value object.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

final readonly class RewriteResult
{
    private function __construct(
        public bool $rewritten,
        public string $url,
        public string $reason
    ) {}

    public static function rewritten(string $url): self
    {
        return new self(true, $url, 'rewritten');
    }

    public static function preserved(string $originalUrl, string $reason): self
    {
        return new self(false, $originalUrl, $reason);
    }
}
