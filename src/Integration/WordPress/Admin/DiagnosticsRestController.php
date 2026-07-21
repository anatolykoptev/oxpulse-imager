<?php
/**
 * REST controller for diagnostics.
 *
 * Registers:
 * - GET /oxpulse/v1/diagnostics — recent log entries + current request summary.
 * - DELETE /oxpulse/v1/diagnostics — clear the recent-entries transient.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Infrastructure\WordPress\WordPressDiagnosticLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DiagnosticsRestController
{
    private WordPressDiagnosticLogger $logger;

    public function __construct(WordPressDiagnosticLogger $logger)
    {
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('oxpulse/v1', '/diagnostics', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handleGet'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'handleDelete'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can(OXPULSE_IMAGER_CAPABILITY);
    }

    /**
     * GET /oxpulse/v1/diagnostics — recent log entries + level.
     */
    public function handleGet(): WP_REST_Response
    {
        $level = (string) get_option(
            \OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
            'off'
        );

        return rest_ensure_response([
            'level'        => $level,
            'recentEntries' => $this->logger->getRecentEntries(),
        ]);
    }

    /**
     * DELETE /oxpulse/v1/diagnostics — clear the recent-entries transient.
     */
    public function handleDelete(WP_REST_Request $request)
    {
        delete_transient(WordPressDiagnosticLogger::RECENT_ENTRIES_TRANSIENT);
        return rest_ensure_response(['cleared' => true]);
    }
}
