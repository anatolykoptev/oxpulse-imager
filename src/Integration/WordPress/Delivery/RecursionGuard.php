<?php
/**
 * Recursion guard trait for filter handlers.
 *
 * Several rewriters call WordPress functions that trigger the same filter
 * chain they're hooked into (e.g. ImageDownsizeRewriter calls
 * wp_get_attachment_url, which is also hooked by AttachmentUrlRewriter).
 * A static flag breaks the cycle.
 *
 * Each class using this trait gets its OWN guard flag (via late static
 * binding on the class name) — handlers in different classes don't
 * interfere with each other, but re-entry within the same class is blocked.
 *
 * Usage:
 *   if ($this->inGuard()) { return $out; }
 *   $this->enterGuard();
 *   try { ... } finally { $this->exitGuard(); }
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

trait RecursionGuard
{
    /** @var array<string,bool> Per-class guard flags, keyed by class name. */
    private static array $guardFlags = [];

    /**
     * True when already inside this handler's own filter chain.
     */
    private function inGuard(): bool
    {
        return self::$guardFlags[static::class] ?? false;
    }

    /**
     * Enter the guarded section. Subsequent inGuard() calls return true
     * until exitGuard() is called.
     */
    private function enterGuard(): void
    {
        self::$guardFlags[static::class] = true;
    }

    /**
     * Exit the guarded section. Always call in a finally block.
     */
    private function exitGuard(): void
    {
        self::$guardFlags[static::class] = false;
    }
}
