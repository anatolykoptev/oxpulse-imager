<?php
/**
 * REST controller for status + info endpoints.
 *
 * Registers:
 * - GET /oxpulse/v1/status — config + health + counts in one call.
 * - GET /oxpulse/v1/info   — preview the imgproxy URL for a source URL
 *   without dispatching a request.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class StatusRestController
{
    private OptionSettingsRepository $repository;
    private HealthCheckService $healthCheck;

    public function __construct(OptionSettingsRepository $repository, ?HealthCheckService $healthCheck = null)
    {
        $this->repository = $repository;
        $this->healthCheck = $healthCheck ?? new HealthCheckService(new WordPressHealthClient());
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('oxpulse/v1', '/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handleStatus'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route('oxpulse/v1', '/info', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handleInfo'],
                'permission_callback' => [$this, 'checkPermission'],
                'args'                => [
                    'url' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                    'width' => [
                        'type'    => 'integer',
                        'default' => 0,
                    ],
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can(OXPULSE_IMAGER_CAPABILITY);
    }

    /**
     * GET /oxpulse/v1/status — config + health + counts.
     *
     * @return WP_REST_Response
     */
    public function handleStatus(): WP_REST_Response
    {
        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        $response = [
            'delivery' => [
                'enabled'        => $delivery->enabled,
                'endpoint'       => $delivery->endpoint,
                'outputFormat'   => $delivery->outputFormat,
                'defaultQuality' => $delivery->defaultQuality,
                'allowedSources' => $delivery->allowedSources,
                'lqipEnabled'    => $delivery->lqipEnabled,
                'dprEnabled'     => $delivery->dprEnabled,
                'watermark'      => $delivery->watermark !== null,
            ],
            'signing' => [
                'configured' => $signing !== null,
            ],
            'health' => null,
        ];

        // Run health check if endpoint is configured.
        if ($delivery->endpoint !== '') {
            $healthResult = $this->healthCheck->checkEndpoint($delivery->endpoint);
            $response['health'] = [
                'ok'         => $healthResult->ok,
                'status'     => $healthResult->status,
                'message'    => $healthResult->message,
                'statusCode' => $healthResult->statusCode,
            ];
        }

        return rest_ensure_response($response);
    }

    /**
     * GET /oxpulse/v1/info?url=<source>&width=<n> — preview the
     * generated imgproxy URL without dispatching a request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handleInfo(WP_REST_Request $request)
    {
        $sourceUrl = (string) ($request->get_param('url') ?? '');
        $width = (int) ($request->get_param('width') ?? 0);

        if ($sourceUrl === '') {
            return new WP_Error(
                'oxpulse_info_no_url',
                __('No source URL provided.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        if (!$delivery->enabled) {
            return rest_ensure_response([
                'sourceUrl'  => $sourceUrl,
                'width'      => $width,
                'rewritten'  => false,
                'reason'     => 'delivery_disabled',
                'imgproxyUrl' => null,
            ]);
        }

        if ($delivery->endpoint === '') {
            return rest_ensure_response([
                'sourceUrl'  => $sourceUrl,
                'width'      => $width,
                'rewritten'  => false,
                'reason'     => 'no_endpoint',
                'imgproxyUrl' => null,
            ]);
        }

        if ($signing === null) {
            return rest_ensure_response([
                'sourceUrl'  => $sourceUrl,
                'width'      => $width,
                'rewritten'  => false,
                'reason'     => 'no_signing_config',
                'imgproxyUrl' => null,
            ]);
        }

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        $result = $rewriter->rewrite($sourceUrl, $width, 0, 'info');

        return rest_ensure_response([
            'sourceUrl'   => $sourceUrl,
            'width'       => $width,
            'rewritten'   => $result->rewritten,
            'reason'      => $result->reason,
            'imgproxyUrl' => $result->rewritten ? $result->url : null,
        ]);
    }
}
