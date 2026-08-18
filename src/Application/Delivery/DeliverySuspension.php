<?php
/**
 * Request-scoped delivery suspension.
 *
 * A counter-based switch the REST integration uses to suspend URL
 * rewriting while WordPress serves attachment/media REST responses.
 * The block editor reads media through /wp/v2/media and persists the
 * returned URLs into post_content — if delivery rewrites them, signed
 * proxy URLs get BAKED into stored content and survive plugin
 * deactivation or key rotation as broken images (#135).
 *
 * Counter (not boolean) so nested internal REST dispatches (_embed)
 * balance correctly.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

final class DeliverySuspension
{
    private static int $depth = 0;

    public static function suspend(): void
    {
        self::$depth++;
    }

    public static function resume(): void
    {
        if (self::$depth > 0) {
            self::$depth--;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Test-only hard reset.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
