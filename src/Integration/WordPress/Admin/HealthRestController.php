<?php
/**
 * REST controller for health checks.
 *
 * Registers POST /oxpulse/v1/health and POST /oxpulse/v1/avif-check,
 * replacing the legacy admin-post handlers (SettingsController).
 * The SPA calls these directly via fetch() — no form POST + redirect
 * dance.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class HealthRestController
{
    private HealthCheckService $healthCheck;

    public function __construct(HealthCheckService $healthCheck)
    {
        $this->healthCheck = $healthCheck;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'oxpulse/v1',
            '/health',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handleHealthCheck'],
                    'permission_callback' => [$this, 'checkPermission'],
                ],
            ]
        );

        register_rest_route(
            'oxpulse/v1',
            '/avif-check',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handleAvifCheck'],
                    'permission_callback' => [$this, 'checkPermission'],
                ],
            ]
        );
    }

    public function checkPermission(): bool
    {
        return current_user_can(OXPULSE_IMAGER_CAPABILITY);
    }

    /**
     * POST /oxpulse/v1/health — run a health check against the
     * configured endpoint (or an endpoint provided in the body).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handleHealthCheck(WP_REST_Request $request)
    {
        $endpoint = (string) ($request->get_param('endpoint') ?? '');

        if ($endpoint === '') {
            $endpoint = (string) get_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        }

        if ($endpoint === '') {
            return new WP_Error(
                'oxpulse_health_failed',
                __('Endpoint URL is empty.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        $result = $this->healthCheck->checkEndpoint($endpoint);

        return rest_ensure_response([
            'ok'         => $result->ok,
            'status'     => $result->status,
            'message'    => $result->message,
            'statusCode' => $result->statusCode,
        ]);
    }

    /**
     * POST /oxpulse/v1/avif-check — run an AVIF format negotiation
     * check. Accepts optional endpoint + sampleImage in the body;
     * falls back to the configured endpoint + first allowed source.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handleAvifCheck(WP_REST_Request $request)
    {
        $endpoint = (string) ($request->get_param('endpoint') ?? '');
        $sampleImage = (string) ($request->get_param('sampleImage') ?? '');

        if ($endpoint === '') {
            $endpoint = (string) get_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        }

        if ($endpoint === '') {
            return new WP_Error(
                'oxpulse_avif_failed',
                __('Endpoint URL is empty.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        if ($sampleImage === '') {
            $allowedSources = (array) get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, []);
            if (!empty($allowedSources)) {
                $sampleImage = rtrim((string) $allowedSources[0], '/') . '/oxpulse-avif-test.jpg';
            }
        }

        if ($sampleImage === '') {
            return new WP_Error(
                'oxpulse_avif_failed',
                __('No sample image URL available. Configure allowed sources first.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        $result = $this->healthCheck->checkAvifSupport($endpoint, $sampleImage);

        return rest_ensure_response([
            'ok'         => $result->ok,
            'status'     => $result->status,
            'message'    => $result->message,
            'statusCode' => $result->statusCode,
        ]);
    }
}
