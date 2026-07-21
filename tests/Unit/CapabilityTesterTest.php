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

    /**
     * FIX #3: CapabilityTester::rewriteAvailable() was hardcoded
     * `return false`, forcing fallback even on Apache hosts with
     * mod_rewrite + AllowOverride. The real check probes the runtime:
     * Apache + mod_rewrite loaded + AllowOverride != None → true;
     * everything else (nginx, CGI, mod_php without mod_rewrite) → false
     * (conservative — prefer fallback). This test exercises the
     * detection via stubbed environment functions.
     */
    public function test_real_check_true_on_apache_with_mod_rewrite(): void
    {
        $tester = new class extends CapabilityTester {
            protected function isApache(): bool { return true; }
            protected function modRewriteLoaded(): bool { return true; }
            protected function allowOverrideEnabled(): bool { return true; }
        };

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($tester->fallbackNeeded());
    }

    public function test_real_check_false_on_apache_without_mod_rewrite(): void
    {
        $tester = new class extends CapabilityTester {
            protected function isApache(): bool { return true; }
            protected function modRewriteLoaded(): bool { return false; }
            protected function allowOverrideEnabled(): bool { return true; }
        };

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($tester->fallbackNeeded());
    }

    public function test_real_check_false_on_apache_with_allowoverride_off(): void
    {
        $tester = new class extends CapabilityTester {
            protected function isApache(): bool { return true; }
            protected function modRewriteLoaded(): bool { return true; }
            protected function allowOverrideEnabled(): bool { return false; }
        };

        $this->assertFalse($tester->rewriteAvailable());
    }

    public function test_real_check_false_on_non_apache(): void
    {
        $tester = new class extends CapabilityTester {
            protected function isApache(): bool { return false; }
            protected function modRewriteLoaded(): bool { return true; }
            protected function allowOverrideEnabled(): bool { return true; }
        };

        $this->assertFalse($tester->rewriteAvailable());
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
