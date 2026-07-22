<?php
/**
 * REST controller for the rewrite-capability re-probe + notice dismiss.
 *
 * #43 Phase 5 (plan D.5 / E.1 step 10): the admin "Re-test capability"
 * button and the AdminNotice dismiss button hit these endpoints. Both
 * are manage_options-gated + nonce-protected.
 *
 * Registers:
 * - POST /oxpulse/v1/capability/reprobe  — invalidate the cached probe
 *   result, re-run the live write-time probe, return the tri-state.
 * - POST /oxpulse/v1/capability/dismiss  — mark an admin-notice key as
 *   dismissed for the current capability state (so a capability flip
 *   re-surfaces the notice).
 *
 * The reprobe handler is the ONLY front-end-reachable entry point that
 * invokes CapabilityTester::recheck() (the live 3s HTTP probe). It is
 * admin-context only (permission_callback = manage_options), so the
 * 3s round-trip is acceptable here — never on the front-end read path.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CapabilityRestController
{
    private CapabilityTester $tester;
    private OptionSettingsRepository $repository;

    /**
     * @param CapabilityTester|null             $tester     Inject a stub
     *   for tests; null lazily constructs a real tester (production).
     * @param OptionSettingsRepository|null     $repository Inject for tests.
     */
    public function __construct(
        ?CapabilityTester $tester = null,
        ?OptionSettingsRepository $repository = null,
    ) {
        $this->repository = $repository ?? new OptionSettingsRepository();
        $this->tester = $tester ?? new CapabilityTester(null, $this->repository);
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('oxpulse/v1', '/capability/reprobe', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handleReprobe'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route('oxpulse/v1', '/capability/dismiss', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handleDismiss'],
                'permission_callback' => [$this, 'checkPermission'],
                'args'                => [
                    'noticeKey' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    /**
     * permission_callback — manage_options (the plan spec) + the
     * plugin capability. The plan D.5 says manage_options; the rest of
     * the admin REST surface uses OXPULSE_IMAGER_CAPABILITY which is
     * granted to administrators alongside manage_options. Require
     * manage_options per the spec.
     */
    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * POST /oxpulse/v1/capability/reprobe — invalidate + re-run the
     * write-time probe, return the tri-state + timestamp.
     *
     * @return WP_REST_Response
     */
    public function handleReprobe(): WP_REST_Response
    {
        // Drop the cached definitive value so recheck() re-probes
        // (recheck() itself ignores the cache, but invalidate keeps
        // the option + timestamp coherent if recheck() returns
        // 'unknown' — a prior definitive value is cleared first).
        $this->tester->invalidateCache();
        $capability = $this->tester->recheck();

        return rest_ensure_response([
            'capability'  => $capability,
            'checked_at'  => $this->repository->loadRewriteCapabilityCheckedAt(),
        ]);
    }

    /**
     * POST /oxpulse/v1/capability/dismiss — mark a notice key dismissed
     * for the current capability state. A later capability flip
     * (e.g. 'no'→'unknown') re-surfaces the notice because the stored
     * state no longer matches.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handleDismiss(WP_REST_Request $request)
    {
        $noticeKey = (string) ($request->get_param('noticeKey') ?? '');
        if ($noticeKey === '') {
            return new WP_Error(
                'oxpulse_dismiss_no_key',
                __('No notice key provided.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        // Resolve the dismiss state via the SAME helper the render gate
        // uses (AdminNotice::noticeDismissState) so the two ends agree:
        // co-install keys → capability-independent NOTICE_STATE_ACTIVE
        // (otherwise the notice could never be dismissed — #57 review
        // MAJOR); capability keys → the live capability (a flip
        // re-surfaces).
        $state = AdminNotice::noticeDismissState($noticeKey, $this->repository);
        $this->repository->dismissNotice($noticeKey, $state);

        return rest_ensure_response(['dismissed' => true]);
    }
}
