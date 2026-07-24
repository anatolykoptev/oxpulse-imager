<?php
/**
 * REST route registration gating tests.
 *
 * Guards the BLOCKER 404 from the first-run onboarding (#120
 * fix-round): the SPA's `POST /oxpulse/v1/options` (issued by
 * OnboardingWizard handleStep1Next → saveOptions) returned
 * `rest_no_route` because ServiceRegistrar::registerAdminSettings()
 * gated the ENTIRE admin-settings block — including
 * OptionsRestController::register() — behind `if (!is_admin())`.
 * `is_admin()` is FALSE on REST API requests (`/wp-json/...`), so the
 * route was never registered for the very requests that need it.
 *
 * This test models the real failure: a REST request is NOT admin
 * context, yet the `oxpulse/v1/options` route MUST be registered so
 * the SPA's POST is matched. It drives the effect through the REAL
 * ServiceRegistrar wiring (register → rest_api_init), not a direct
 * controller call — so a regression in the gate is caught.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Plugin;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarRestRouteRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        // REST API requests are NOT admin context — is_admin() is false
        // on /wp-json/ requests. This is the exact condition that
        // produced rest_no_route.
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_rest_routes'],
            $GLOBALS['__oxpulse_transients'],
            $GLOBALS['__oxpulse_scheduled_events'],
            $GLOBALS['__oxpulse_is_admin'],
        );
    }

    /**
     * Build a Plugin instance without triggering Plugin::load()'s
     * spl_autoload_register side effect (the singleton + autoloader
     * persist across tests in one process). Reflection bypasses the
     * private constructor.
     */
    private function plugin(): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * Fire a registered action hook (mirrors StatusRestControllerTest).
     */
    private function fireHook(string $hook): void
    {
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }
    }

    /**
     * The SPA's POST /oxpulse/v1/options must be matched on a REST
     * request (is_admin() === false). Pre-fix the route was gated off
     * by the is_admin() guard in registerAdminSettings → rest_no_route.
     */
    public function test_options_route_registered_when_not_admin_context(): void
    {
        ServiceRegistrar::register($this->plugin());
        $this->fireHook('rest_api_init');

        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey(
            'oxpulse/v1/options',
            $routes,
            'POST /oxpulse/v1/options must be registered on REST requests (is_admin() === false); '
            . 'gating it behind is_admin() produces rest_no_route 404 on the onboarding save.',
        );

        // The SPA sends POST (api.js saveOptions → method: 'POST').
        // Confirm the registered methods include POST (CREATABLE).
        $args = $routes['oxpulse/v1/options'];
        $methods = [];
        foreach ($args as $endpoint) {
            if (isset($endpoint['methods'])) {
                $methods[] = $endpoint['methods'];
            }
        }
        $this->assertContains(
            'POST',
            $methods,
            'The options route must accept POST (the method the SPA saveOptions sends).',
        );
    }
}
