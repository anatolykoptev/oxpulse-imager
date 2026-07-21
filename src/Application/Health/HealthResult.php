<?php
/**
 * Health check result value object.
 *
 * @package OXPulse\Imager\Application\Health
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Health;

final readonly class HealthResult
{
    public function __construct(
        public bool $ok,
        public string $status,
        public string $message,
        public int $statusCode = 0
    ) {}

    public static function ok(string $message = ''): self
    {
        if ($message === '') {
            $message = __('Connection successful.', 'oxpulse-imager');
        }
        return new self(true, 'ok', $message);
    }

    public static function failed(string $message, int $statusCode = 0): self
    {
        return new self(false, 'failed', $message, $statusCode);
    }

    public static function unreachable(string $message): self
    {
        return new self(false, 'unreachable', $message);
    }
}
