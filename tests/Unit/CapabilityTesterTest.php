<?php
/**
 * CapabilityTester tests.
 *
 * Verifies the mod_rewrite capability-test logic picks the fallback
 * when rewrite is unavailable (stubbed — no live HTTP probe in tests).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use PHPUnit\Framework\TestCase;

class CapabilityTesterTest extends TestCase
{
    public function test_returns_false_when_mod_rewrite_unavailable(): void
    {
        $tester = new StubCapabilityTester(available: false);
        $this->assertFalse($tester->rewriteAvailable());
    }

    public function test_returns_true_when_mod_rewrite_available(): void
    {
        $tester = new StubCapabilityTester(available: true);
        $this->assertTrue($tester->rewriteAvailable());
    }

    public function test_fallback_needed_when_rewrite_unavailable(): void
    {
        $tester = new StubCapabilityTester(available: false);
        $this->assertTrue($tester->fallbackNeeded());
    }

    public function test_fallback_not_needed_when_rewrite_available(): void
    {
        $tester = new StubCapabilityTester(available: true);
        $this->assertFalse($tester->fallbackNeeded());
    }
}

/** Stub that overrides the live probe for deterministic testing. */
class StubCapabilityTester extends CapabilityTester
{
    public function __construct(private bool $available) {}

    public function rewriteAvailable(): bool
    {
        return $this->available;
    }
}
