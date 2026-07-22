<?php
/**
 * CapabilityRestController unit tests.
 *
 * #43 Phase 5 (plan D.5 / E.1 step 10): verifies the
 * /oxpulse/v1/capability/reprobe + /dismiss REST endpoints:
 *  - reprobe calls invalidateCache() then recheck() and returns the
 *    tri-state {capability, checked_at}.
 *  - dismiss marks a notice key dismissed for the current capability
 *    state (so a flip re-surfaces).
 *  - permission_callback rejects non-manage_options.
 *  - routes registered under the oxpulse/v1 namespace.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\CapabilityRestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

class CapabilityRestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_current_user_can'] = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_rest_routes'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_current_user_can'],
        );
    }

    /**
     * Fire all callbacks registered for a given hook (mirrors the
     * existing Integration test helper pattern).
     */
    private function fireHook(string $hook): void
    {
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }
    }

    // ─── route registration ────────────────────────────────────────────

    public function test_register_registers_reprobe_and_dismiss_routes(): void
    {
        $controller = new CapabilityRestController();
        $controller->register();
        $this->fireHook('rest_api_init');

        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey('oxpulse/v1/capability/reprobe', $routes);
        $this->assertArrayHasKey('oxpulse/v1/capability/dismiss', $routes);
    }

    // ─── permission_callback ───────────────────────────────────────────

    public function test_check_permission_allows_manage_options(): void
    {
        $controller = new CapabilityRestController();
        $this->assertTrue($controller->checkPermission());
    }

    public function test_check_permission_rejects_non_manage_options(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $controller = new CapabilityRestController();
        $this->assertFalse($controller->checkPermission());
    }

    // ─── reprobe: invalidate + recheck + tri-state response ────────────

    public function test_reprobe_calls_invalidate_then_recheck_and_returns_tri_state(): void
    {
        $tester = new StubCapabilityTester('no');
        $repo = new OptionSettingsRepository();
        $controller = new CapabilityRestController($tester, $repo);

        $response = $controller->handleReprobe();

        $this->assertFalse($response instanceof WP_Error);
        $data = $response->get_data();
        $this->assertSame('no', $data['capability']);
        $this->assertGreaterThan(0, $data['checked_at']);
        $this->assertTrue($tester->invalidateCalled, 'invalidateCache() must be called before recheck()');
        $this->assertTrue($tester->recheckCalled, 'recheck() must be called');
        $this->assertTrue($tester->invalidateBeforeRecheck, 'invalidateCache() must run BEFORE recheck()');
    }

    public function test_reprobe_returns_yes_when_probe_succeeds(): void
    {
        $tester = new StubCapabilityTester('yes');
        $controller = new CapabilityRestController($tester);

        $response = $controller->handleReprobe();
        $this->assertSame('yes', $response->get_data()['capability']);
    }

    public function test_reprobe_returns_unknown_on_inconclusive_probe(): void
    {
        $tester = new StubCapabilityTester('unknown');
        $controller = new CapabilityRestController($tester);

        $response = $controller->handleReprobe();
        $this->assertSame('unknown', $response->get_data()['capability']);
    }

    // ─── dismiss ───────────────────────────────────────────────────────

    public function test_dismiss_marks_notice_for_current_capability(): void
    {
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $controller = new CapabilityRestController();
        $request = new WP_REST_Request(['noticeKey' => 'capability_nginx']);

        $response = $controller->handleDismiss($request);
        $this->assertFalse($response instanceof WP_Error);
        $this->assertTrue($response->get_data()['dismissed']);

        $repo = new OptionSettingsRepository();
        $this->assertTrue($repo->isNoticeDismissed('capability_nginx', 'no'));
        // A capability flip re-surfaces (stored 'no' != 'unknown').
        $this->assertFalse($repo->isNoticeDismissed('capability_nginx', 'unknown'));
    }

    public function test_dismiss_rejects_empty_key(): void
    {
        $controller = new CapabilityRestController();
        $request = new WP_REST_Request(['noticeKey' => '']);

        $response = $controller->handleDismiss($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_dismiss_no_key', array_key_first($response->errors));
    }
}

/**
 * Stub CapabilityTester that records invalidate/recheck call order.
 */
class StubCapabilityTester extends CapabilityTester
{
    public bool $invalidateCalled = false;
    public bool $recheckCalled = false;
    public bool $invalidateBeforeRecheck = false;
    private string $recheckResult;

    public function __construct(string $recheckResult)
    {
        // Pass null probe — recheck() is overridden so the probe is
        // never touched.
        parent::__construct(null, new OptionSettingsRepository());
        $this->recheckResult = $recheckResult;
    }

    public function invalidateCache(): void
    {
        $this->invalidateCalled = true;
        if (!$this->recheckCalled) {
            $this->invalidateBeforeRecheck = true;
        }
    }

    public function recheck(): string
    {
        $this->recheckCalled = true;
        // Persist a definitive result so loadRewriteCapabilityCheckedAt
        // returns a real timestamp (mirrors production behavior).
        $this->saveStubResult($this->recheckResult);
        return $this->recheckResult;
    }

    private function saveStubResult(string $result): void
    {
        if ($result === 'yes' || $result === 'no') {
            update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, $result);
            update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, time());
        }
    }
}
