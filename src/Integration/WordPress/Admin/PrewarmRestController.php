<?php
/**
 * REST controller for bulk pre-warm.
 *
 * Registers POST /oxpulse/v1/prewarm — accepts a batch of source image
 * URLs + optional widths, dispatches HEAD requests to imgproxy to
 * trigger processing + cache fill, returns per-URL results.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient;
use OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyPathBuilder;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyUrlGenerator;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PrewarmRestController
{
    private OptionSettingsRepository $repository;

    public function __construct(OptionSettingsRepository $repository)
    {
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'oxpulse/v1',
            '/prewarm',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handlePrewarm'],
                    'permission_callback' => [$this, 'checkPermission'],
                ],
            ]
        );

        // GET /oxpulse/v1/prewarm/<job_id> — poll async job status.
        register_rest_route(
            'oxpulse/v1',
            '/prewarm/(?P<jobId>[a-f0-9-]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handlePrewarmJobStatus'],
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
     * POST /oxpulse/v1/prewarm — warm a batch of source URLs.
     *
     * Body: { urls: string[], widths?: number[], async?: boolean }
     *
     * When async=true, creates a background job and returns { jobId }
     * immediately. Poll GET /prewarm/<jobId> for progress.
     * When async=false (default), processes synchronously and returns
     * the full result.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handlePrewarm(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $urls = $params['urls'] ?? [];
        $widths = $params['widths'] ?? PrewarmRequest::DEFAULT_WIDTHS;
        $async = (bool) ($params['async'] ?? false);

        // Validate + clamp inputs.
        if (!is_array($urls) || count($urls) === 0) {
            return new WP_Error(
                'oxpulse_prewarm_no_urls',
                __('No URLs provided.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        if (count($urls) > PrewarmRequest::MAX_URLS_PER_BATCH) {
            return new WP_Error(
                'oxpulse_prewarm_too_many_urls',
                sprintf(
                    /* translators: %d: max URLs per batch */
                    __('Too many URLs. Maximum %d per batch.', 'oxpulse-imager'),
                    PrewarmRequest::MAX_URLS_PER_BATCH
                ),
                ['status' => 400]
            );
        }

        // Sanitize URLs: must be strings, trim, dedupe, drop empties.
        $urls = array_values(array_unique(array_filter(
            array_map(fn ($u) => is_string($u) ? trim($u) : '', $urls),
            fn ($u) => $u !== ''
        )));

        if (count($urls) === 0) {
            return new WP_Error(
                'oxpulse_prewarm_no_valid_urls',
                __('No valid URLs provided.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        // Sanitize widths: must be ints 0-10000, dedupe, sort, clamp count.
        if (!is_array($widths)) {
            $widths = PrewarmRequest::DEFAULT_WIDTHS;
        }
        $widths = array_values(array_unique(array_filter(
            array_map(fn ($w) => is_numeric($w) ? (int) $w : -1, $widths),
            fn ($w) => $w >= 0 && $w <= 10000
        )));
        if (count($widths) === 0) {
            $widths = PrewarmRequest::DEFAULT_WIDTHS;
        }
        if (count($widths) > PrewarmRequest::MAX_WIDTHS_PER_BATCH) {
            $widths = array_slice($widths, 0, PrewarmRequest::MAX_WIDTHS_PER_BATCH);
        }
        sort($widths);

        // Build the UrlRewriter + PrewarmService from current config.
        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        if (!$delivery->enabled) {
            return new WP_Error(
                'oxpulse_prewarm_disabled',
                __('Delivery is disabled. Enable it in Connection settings first.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        if ($delivery->endpoint === '') {
            return new WP_Error(
                'oxpulse_prewarm_no_endpoint',
                __('No imgproxy endpoint configured.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        if ($signing === null) {
            return new WP_Error(
                'oxpulse_prewarm_no_signing',
                __('No signing secrets configured.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        $policy = new SourcePolicy();
        $rewriter = new UrlRewriter($policy, $delivery, $signing);
        $httpClient = new WordPressPrewarmClient();
        $service = new PrewarmService($rewriter, $httpClient);

        // Async mode: create a job, schedule cron processing, return
        // the job ID immediately. The client polls GET /prewarm/<jobId>.
        if ($async) {
            $asyncService = new AsyncPrewarmService($service, new \OXPulse\Imager\Application\Prewarm\PrewarmJobStore());
            $jobId = $asyncService->createJob($urls, $widths);

            return rest_ensure_response([
                'jobId'   => $jobId,
                'status'  => 'pending',
                'message' => __('Pre-warm job created. Poll GET /oxpulse/v1/prewarm/<jobId> for progress.', 'oxpulse-imager'),
            ]);
        }

        // Sync mode (default): process now and return the full result.
        $prewarmRequest = new PrewarmRequest($urls, $widths);
        $batchResult = $service->warm($prewarmRequest);

        return rest_ensure_response($batchResult->toArray());
    }

    /**
     * GET /oxpulse/v1/prewarm/<jobId> — poll async job status.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handlePrewarmJobStatus(WP_REST_Request $request)
    {
        $jobId = (string) ($request->get_param('jobId') ?? '');
        if ($jobId === '') {
            return new WP_Error(
                'oxpulse_prewarm_no_job_id',
                __('No job ID provided.', 'oxpulse-imager'),
                ['status' => 400]
            );
        }

        $store = new \OXPulse\Imager\Application\Prewarm\PrewarmJobStore();
        $job = $store->get($jobId);

        if ($job === null) {
            return new WP_Error(
                'oxpulse_prewarm_job_not_found',
                __('Job not found. It may have expired (jobs are kept for 1 hour).', 'oxpulse-imager'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($job);
    }
}
