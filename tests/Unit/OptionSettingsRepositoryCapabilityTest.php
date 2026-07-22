<?php
/**
 * OptionSettingsRepository capability-option storage tests.
 *
 * Verifies the rewrite-capability option constants + getter/setter
 * helpers behave as pure storage (no auto-probe on load).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use PHPUnit\Framework\TestCase;

class OptionSettingsRepositoryCapabilityTest extends TestCase
{
    private OptionSettingsRepository $repo;

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $this->repo = new OptionSettingsRepository();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
    }

    public function test_load_rewrite_capability_defaults_to_unknown(): void
    {
        $this->assertSame('unknown', $this->repo->loadRewriteCapability());
    }

    public function test_save_and_load_rewrite_capability_yes(): void
    {
        $this->repo->saveRewriteCapability('yes');
        $this->assertSame('yes', $this->repo->loadRewriteCapability());
    }

    public function test_save_and_load_rewrite_capability_no(): void
    {
        $this->repo->saveRewriteCapability('no');
        $this->assertSame('no', $this->repo->loadRewriteCapability());
    }

    public function test_save_and_load_rewrite_capability_unknown(): void
    {
        $this->repo->saveRewriteCapability('unknown');
        $this->assertSame('unknown', $this->repo->loadRewriteCapability());
    }

    public function test_save_stamps_checked_at(): void
    {
        $before = time();
        $this->repo->saveRewriteCapability('yes');
        $after = time();

        $checkedAt = $this->repo->loadRewriteCapabilityCheckedAt();
        $this->assertGreaterThanOrEqual($before, $checkedAt);
        $this->assertLessThanOrEqual($after, $checkedAt);
    }

    public function test_checked_at_defaults_to_zero(): void
    {
        $this->assertSame(0, $this->repo->loadRewriteCapabilityCheckedAt());
    }

    public function test_invalidate_clears_capability_and_timestamp(): void
    {
        $this->repo->saveRewriteCapability('yes');
        $this->assertNotSame('unknown', $this->repo->loadRewriteCapability());

        $this->repo->invalidateRewriteCapability();

        $this->assertSame('unknown', $this->repo->loadRewriteCapability());
        $this->assertSame(0, $this->repo->loadRewriteCapabilityCheckedAt());
    }

    public function test_load_probe_version_defaults_to_empty(): void
    {
        $this->assertSame('', $this->repo->loadProbeVersion());
    }

    public function test_save_and_load_probe_version(): void
    {
        $this->repo->saveProbeVersion('0.1.0');
        $this->assertSame('0.1.0', $this->repo->loadProbeVersion());
    }

    /**
     * The invalid tri-state value must be normalized to 'unknown'
     * (conservative — treat garbage as never-probed).
     */
    public function test_load_normalizes_invalid_value_to_unknown(): void
    {
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'garbage');
        $this->assertSame('unknown', $this->repo->loadRewriteCapability());
    }

    // ─── #43 Phase 1 review: nullable variant (MINOR 1) ──────────────────

    /**
     * loadRewriteCapabilityOrNull() returns null on miss (NOT 'unknown')
     * so CapabilityTester can distinguish "never probed" from a stored
     * definitive result.
     */
    public function test_load_or_null_returns_null_on_miss(): void
    {
        $this->assertNull($this->repo->loadRewriteCapabilityOrNull());
    }

    public function test_load_or_null_returns_null_on_invalid_value(): void
    {
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'garbage');
        $this->assertNull($this->repo->loadRewriteCapabilityOrNull());
    }

    public function test_load_or_null_returns_yes(): void
    {
        $this->repo->saveRewriteCapability('yes');
        $this->assertSame('yes', $this->repo->loadRewriteCapabilityOrNull());
    }

    public function test_load_or_null_returns_no(): void
    {
        $this->repo->saveRewriteCapability('no');
        $this->assertSame('no', $this->repo->loadRewriteCapabilityOrNull());
    }

    public function test_load_or_null_returns_unknown_when_stored(): void
    {
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'unknown');
        $this->assertSame('unknown', $this->repo->loadRewriteCapabilityOrNull());
    }
}
