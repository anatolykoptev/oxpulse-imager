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
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
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
            // <picture> element wrapping (Phase 1) — Pro-gated. The GET
            // value is gated by ServiceRegistrar::isPro() so the SPA
            // renders the toggle OFF under free even when the stored
            // option is true (e.g. a site that enabled <picture> while
            // Pro then downgraded on trial expiry). Mirrors the
            // cache_max_mb GET-gating via loadCacheMaxMb(). The backend
            // oxpulse_picture_enabled filter at PHP_INT_MAX remains the
            // real runtime gate; this guards the READ value only.
            'picture_enabled'   => ServiceRegistrar::isPro()
                ? (bool) get_option(
                    OptionSettingsRepository::OPTION_PICTURE_ENABLED,
                    false
                )
                : false,
            // LocalBackend cache cap (MB) — Pro-gated. loadCacheMaxMb()
            // returns the default (512) under free regardless of the
            // stored value, so the SPA shows 512 + locked under free.
            'cache_max_mb'      => $this->repository->loadCacheMaxMb(),
            'diagnostic_level'  => (string) get_option(
                OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
                'off'
            ),
            'remove_on_uninstall' => (bool) get_option(
                OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL,
                false
            ),
            'onboarded' => (bool) get_option(
                OptionSettingsRepository::OPTION_ONBOARDED,
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

        // snake_case keys ACTUALLY present in the POST body. The
        // validator below sanitizes the FULL field set (filling absent
        // keys with defaults so a partial POST still validates cleanly),
        // so $result['values'] contains EVERY key — not just the
        // submitted ones. To honor the docblock's partial-merge promise
        // ("a partial POST never resets unmentioned options"), persist
        // ONLY the keys the client actually sent. Without this gate the
        // validator-emitted defaults would overwrite unmentioned options
        // (e.g. a {enabled:true} onboarding POST would reset
        // diagnostic_level to 'off').
        $submittedSnake = OptionsMapper::toSnake($params);
        $submittedKeys = array_keys($submittedSnake);

        $result = $this->validator->validate($submittedSnake);

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

        // Restrict the sanitized values to the keys the client actually
        // submitted, so the repository's array_key_exists() writes below
        // only fire for mentioned options (partial-merge).
        $values = [];
        foreach ($submittedKeys as $key) {
            if (array_key_exists($key, $result['values'])) {
                $values[$key] = $result['values'][$key];
            }
        }

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
        if (array_key_exists('onboarded', $values)) {
            update_option(
                OptionSettingsRepository::OPTION_ONBOARDED,
                (bool) $values['onboarded']
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
