<?php
/**
 * Onboarding first-run save regression tests.
 *
 * Reproduces the uncaught-\Throwable HTTP 500 ("There has been a
 * critical error on this website") fired when the first-run onboarding
 * wizard Step 1 "Turn on optimization" persists {enabled:true} via
 * POST /oxpulse/v1/options. The SPA's saveOptions() sends the full
 * merged options object, but the REST handler's docblock PROMISES a
 * partial POST never resets unmentioned options — so the partial
 * {enabled:true} body (the reported trigger) MUST persist cleanly too.
 *
 * The harness wires the controller exactly as
 * ServiceRegistrar::registerAdminRestControllers() does, fires
 * rest_api_init, and dispatches the registered POST callback with an
 * authorized admin user — exercising the real handleUpdate → save →
 * handleGet round-trip in a REST (is_admin()===false) context.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsRestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class OnboardingSaveRestTest extends TestCase
{
    private OptionSettingsRepository $repository;
    private OptionsRestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh FREE install: empty option store, REST context (not
        // admin), authorized user, clean action/route registries.
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $GLOBALS['__oxpulse_is_admin'] = false;
        // Freemius SDK not loaded → isPro() === false (free tier).
        $GLOBALS['__oxpulse_fs_stub'] = null;

        $this->repository = new OptionSettingsRepository();
        $this->controller = new OptionsRestController($this->repository, new SettingsValidator());
        $this->controller->register();
        $this->fireHook('rest_api_init');
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_rest_routes'],
            $GLOBALS['__oxpulse_current_user_can'],
            $GLOBALS['__oxpulse_is_admin'],
            $GLOBALS['__oxpulse_fs_stub']
        );
        parent::tearDown();
    }

    /**
     * Fire all callbacks registered for a given hook (mirrors the
     * stub do_action semantics used by the rest of the integration suite).
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
     * Dispatch an authorized POST /oxpulse/v1/options with the given
     * JSON body through the REAL registered route callback, surfacing
     * any uncaught \Throwable (class + message + file:line + trace) so
     * the actual root cause is visible instead of WP's opaque 500.
     *
     * @return WP_REST_Response|WP_Error
     */
    private function dispatchPost(array $jsonBody)
    {
        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey('oxpulse/v1/options', $routes, 'options route not registered');
        $post = null;
        foreach ($routes['oxpulse/v1/options'] as $routeDef) {
            if (($routeDef['methods'] ?? '') === WP_REST_Server::CREATABLE) {
                $post = $routeDef;
                break;
            }
        }
        $this->assertNotNull($post, 'POST /options route not registered');

        // Permission check runs first (mirrors real REST dispatch).
        $this->assertTrue(call_user_func($post['permission_callback']), 'authorized admin should pass permission check');

        $request = new WP_REST_Request($jsonBody);

        try {
            return call_user_func($post['callback'], $request);
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                "POST /oxpulse/v1/options threw an uncaught %s:\n%s\nat %s:%d\n\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
        }
    }

    /**
     * RED: the reported first-run onboarding trigger — a PARTIAL
     * {enabled:true} POST (the handler docblock promises partial-merge).
     * Currently throws an uncaught \Throwable → HTTP 500.
     */
    public function test_step1_turn_on_partial_enabled_post_returns_200(): void
    {
        $response = $this->dispatchPost(['enabled' => true]);

        $this->assertNotInstanceOf(WP_Error::class, $response, 'partial {enabled:true} save must not error');
        $this->assertInstanceOf(WP_REST_Response::class, $response);

        $data = $response->get_data();
        $this->assertTrue($data['enabled'], 'enabled must persist true');
    }

    /**
     * GREEN target: after the partial save, GET /oxpulse/v1/options
     * returns enabled:true AND every other option unchanged (partial-
     * merge preserved — unmentioned options keep their defaults).
     */
    public function test_partial_enabled_post_preserves_other_options(): void
    {
        // Pre-set a non-default diagnostic_level so we can prove the
        // partial save does NOT clobber unmentioned options.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';

        $response = $this->dispatchPost(['enabled' => true]);

        $this->assertNotInstanceOf(WP_Error::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['enabled']);
        // Unmentioned option preserved (not reset to default 'off').
        $this->assertSame('verbose', $data['diagnosticLevel']);
        // Other defaults intact.
        $this->assertSame('auto', $data['outputFormat']);
        $this->assertSame(80, $data['defaultQuality']);
        $this->assertFalse($data['onboarded']);
    }

    /**
     * The SPA's saveOptions() actually sends the FULL merged options
     * object (defaultOptions ∪ {enabled:true}, minus secretStatus).
     * Confirm that path persists cleanly too — guards against a fix
     * that handles only the partial case.
     */
    public function test_step1_turn_on_full_default_merged_post_returns_200(): void
    {
        $fullBody = [
            'enabled'           => true,
            'endpoint'          => '',
            'allowedSources'    => [],
            'outputFormat'      => 'auto',
            'defaultQuality'    => 80,
            'formatQuality'     => [],
            'lqipEnabled'       => false,
            'lqipBlur'          => 1,
            'dprEnabled'        => false,
            'dprVariants'       => [1, 2, 3],
            'watermark'         => null,
            'pictureEnabled'    => false,
            'cacheMaxMb'        => 512,
            'diagnosticLevel'   => 'off',
            'devHttpOverride'   => false,
            'removeOnUninstall' => false,
            'onboarded'         => false,
        ];

        $response = $this->dispatchPost($fullBody);

        $this->assertNotInstanceOf(WP_Error::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['enabled']);
    }
}
