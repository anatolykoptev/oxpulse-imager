<?php
/**
 * REST controller backing the React admin SPA (src/admin/).
 *
 * Registers GET|POST /wp-json/oxpulse/v1/options. The write path
 * funnels through the SAME SettingsValidator::validate() the classic
 * form used — this route never re-implements validation.
 *
 * Ported from UTM Linker (includes/Rest/OptionsController.php),
 * adapted for OXPulse's multi-option storage (each setting is its own
 * wp_option row, not one array — see OptionSettingsRepository).
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OptionsRestController
{
    private OptionSettingsRepository $repository;
    private SettingsValidator $validator;

    public function __construct(
        OptionSettingsRepository $repository,
        SettingsValidator $validator
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'oxpulse/v1',
            '/options',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handleGet'],
                    'permission_callback' => [$this, 'checkPermission'],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handleUpdate'],
                    'permission_callback' => [$this, 'checkPermission'],
                ],
            ]
        );
    }

    /**
     * Permission check — same capability the settings page requires.
     */
    public function checkPermission(): bool
    {
        return current_user_can(OXPULSE_IMAGER_CAPABILITY);
    }

    /**
     * GET /oxpulse/v1/options — current settings, camelCase-mapped.
     *
     * Assembles a flat snake_case array from the repository's config
     * objects + loose options, then maps to camelCase for the SPA.
     *
     * Secrets (key/salt) are NEVER returned — only a status indicator
     * ('configured' | 'partial' | 'empty') so the SPA can show whether
     * signing is set up without exposing the actual hex values.
     */
    public function handleGet(): WP_REST_Response
    {
        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        $snake = [
            'enabled'           => $delivery->enabled,
            'endpoint'          => $delivery->endpoint,
            'allowed_sources'   => $delivery->allowedSources,
            'output_format'     => $delivery->outputFormat,
            'default_quality'   => $delivery->defaultQuality,
            'dev_http_override' => $delivery->devHttpOverride,
            'lqip_enabled'      => $delivery->lqipEnabled,
            'lqip_blur'         => $delivery->lqipBlur,
            'dpr_enabled'       => $delivery->dprEnabled,
            'dpr_variants'      => $delivery->dprVariants,
            'format_quality'    => $delivery->formatQuality,
            'watermark'         => $this->watermarkToArray($delivery->watermark),
            'diagnostic_level'  => (string) get_option(
                OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
                'off'
            ),
            'remove_on_uninstall' => (bool) get_option(
                OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL,
                false
            ),
        ];

        $camel = OptionsMapper::toCamel($snake);

        // Secrets: status only, never the values.
        $camel['secretStatus'] = $this->repository->secretStatus();

        return rest_ensure_response($camel);
    }

    /**
     * POST /oxpulse/v1/options — merge + sanitize + persist, then
     * return the sanitized result (camelCase-mapped) so the SPA can
     * adopt the authoritative persisted state.
     *
     * The merge is shallow: only top-level keys OptionsMapper
     * recognizes from the POST body are overlaid on the current
     * state; omitted keys keep their current value. This means a
     * partial POST (e.g. only toggling `enabled`) never resets
     * unmentioned options.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handleUpdate(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $result = $this->validator->validate(OptionsMapper::toSnake($params));

        if (!empty($result['errors'])) {
            return new WP_Error(
                'oxpulse_validation_failed',
                __('Validation failed.', 'oxpulse-imager'),
                [
                    'status' => 400,
                    'errors' => $result['errors'],
                ]
            );
        }

        $values = $result['values'];

        $this->repository->saveDeliverySettings($values);

        // Only save secrets if non-empty values were submitted. Empty
        // values mean "keep existing" — never overwrite secrets with
        // empty strings.
        if (!empty($values['key']) && !empty($values['salt'])) {
            $this->repository->saveSecrets($values['key'], $values['salt']);
        }

        // Loose options (not part of DeliveryConfig).
        if (array_key_exists('diagnostic_level', $values)) {
            update_option(
                OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
                $values['diagnostic_level']
            );
        }
        if (array_key_exists('remove_on_uninstall', $values)) {
            update_option(
                OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL,
                (bool) $values['remove_on_uninstall']
            );
        }

        // Return the authoritative persisted state.
        return $this->handleGet();
    }

    /**
     * Convert a Watermark value object (or null) to the array shape
     * the SPA expects, or null when no watermark is configured.
     *
     * @return array<string,mixed>|null
     */
    private function watermarkToArray(?\OXPulse\Imager\Domain\Transform\Watermark $wm): ?array
    {
        if ($wm === null) {
            return null;
        }
        return [
            'enabled'  => true,
            'opacity'  => $wm->opacity,
            'position' => $wm->position,
            'xOffset'  => $wm->xOffset,
            'yOffset'  => $wm->yOffset,
            'scale'    => $wm->scale,
        ];
    }
}
