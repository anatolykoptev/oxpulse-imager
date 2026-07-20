<?php
/**
 * Source authorization decision.
 *
 * @package OXPulse\Imager\Domain\Source
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Source;

final readonly class SourceDecision
{
    private function __construct(
        public bool $authorized,
        public string $reason,
        public ?NormalizedUrl $url
    ) {}

    public static function authorized(NormalizedUrl $url): self
    {
        return new self(true, 'authorized', $url);
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason, null);
    }
}
