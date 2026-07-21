<?php
/**
 * Settings controller.
 *
 * Handles settings form submission and Test Connection action. Enforces
 * nonce verification, capability checks, input sanitization, and secret
 * redaction in all responses.
 *
 * The public handleSave() / handleTestConnection() methods are thin
 * wrappers that perform the capability + nonce gate, delegate to the
 * internal doSave() / doTestConnection() methods (which return a
 * redirect URL string), then issue wp_safe_redirect + exit. The split
 * keeps the redirect/exit side effects out of the testable logic.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;

final class SettingsController
{
    private OptionSettingsRepository $repository;
    private SettingsValidator $validator;
    private ?HealthCheckService $healthCheck;

    public function __construct(
        OptionSettingsRepository $repository,
        SettingsValidator $validator,
        ?HealthCheckService $healthCheck = null
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->healthCheck = $healthCheck;
    }

    /**
     * Register admin-post handlers for health check + AVIF test.
     *
     * Settings save is now handled by the REST controller
     * (OptionsRestController). These two actions remain on admin-post
     * until Phase 5.3 moves them to REST endpoints.
     */
    public function register(): void
    {
        add_action('admin_post_oxpulse_imager_test_connection', [$this, 'handleTestConnection']);
        add_action('admin_post_oxpulse_imager_test_avif', [$this, 'handleTestAvif']);
    }

    public function handleSave(): void
    {
        $this->guard();
        $url = $this->doSave($_POST['oxpulse_imager'] ?? []);
        wp_safe_redirect($url);
        exit;
    }

    public function handleTestConnection(): void
    {
        $this->guard();
        $endpoint = (string) ($_POST['oxpulse_imager']['endpoint'] ?? '');
        $url = $this->doTestConnection($endpoint);
        wp_safe_redirect($url);
        exit;
    }

    public function handleTestAvif(): void
    {
        $this->guard();
        $endpoint = (string) ($_POST['oxpulse_imager']['endpoint'] ?? '');
        $sampleImage = (string) ($_POST['oxpulse_imager']['sample_image'] ?? '');
        $url = $this->doTestAvif($endpoint, $sampleImage);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Internal: validate and persist settings. Returns the admin URL
     * to redirect to (with status query params). Does not redirect or
     * exit — testable in isolation.
     *
     * @param array $input Raw form input (already stripped of magic quotes by WP).
     * @return string Redirect URL.
     */
    public function doSave(array $input): string
    {
        if (!is_array($input)) {
            $input = [];
        }

        $result = $this->validator->validate($input);

        if (!empty($result['errors'])) {
            return $this->redirectUrl([
                'settings_errors' => wp_json_encode($result['errors']),
            ]);
        }

        $values = $result['values'];

        $this->repository->saveDeliverySettings($values);

        // Only save secrets if non-empty values were submitted. Empty
        // values mean "keep existing" — never overwrite secrets with
        // empty strings from the redacted form.
        if (!empty($values['key']) && !empty($values['salt'])) {
            $this->repository->saveSecrets($values['key'], $values['salt']);
        }

        return $this->redirectUrl(['settings_updated' => '1']);
    }

    /**
     * Internal: run a health check against the given endpoint (or the
     * currently configured endpoint if empty). Returns the admin URL
     * to redirect to. Does not redirect or exit.
     *
     * @param string $endpoint Endpoint URL from the form, may be empty.
     * @return string Redirect URL.
     */
    public function doTestConnection(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            $endpoint = (string) get_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        }

        if ($endpoint === '') {
            return $this->redirectUrl([
                'health_result' => 'failed',
                'health_message' => 'Endpoint URL is empty.',
            ]);
        }

        if ($this->healthCheck === null) {
            return $this->redirectUrl([
                'health_result' => 'failed',
                'health_message' => 'Health check service not available.',
            ]);
        }

        $result = $this->healthCheck->checkEndpoint($endpoint);

        return $this->redirectUrl([
            'health_result' => $result->status,
            'health_message' => $result->message,
        ]);
    }

    /**
     * Internal: run an AVIF format negotiation check against the given
     * endpoint using a sample image URL. Returns the admin URL to
     * redirect to. Does not redirect or exit.
     *
     * @param string $endpoint Endpoint URL from the form, may be empty.
     * @param string $sampleImage Sample image URL to test with, may be empty.
     * @return string Redirect URL.
     */
    public function doTestAvif(string $endpoint, string $sampleImage): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            $endpoint = (string) get_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        }

        if ($endpoint === '') {
            return $this->redirectUrl([
                'avif_result' => 'failed',
                'avif_message' => 'Endpoint URL is empty.',
            ]);
        }

        $sampleImage = trim($sampleImage);

        if ($sampleImage === '') {
            // Fall back to the first allowed source URL + a placeholder
            // image name. This gives a reasonable default for the check.
            $allowedSources = (array) get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, []);
            if (!empty($allowedSources)) {
                $sampleImage = rtrim((string) $allowedSources[0], '/') . '/oxpulse-avif-test.jpg';
            }
        }

        if ($sampleImage === '') {
            return $this->redirectUrl([
                'avif_result' => 'failed',
                'avif_message' => 'No sample image URL available. Configure allowed sources first.',
            ]);
        }

        if ($this->healthCheck === null) {
            return $this->redirectUrl([
                'avif_result' => 'failed',
                'avif_message' => 'Health check service not available.',
            ]);
        }

        $result = $this->healthCheck->checkAvifSupport($endpoint, $sampleImage);

        return $this->redirectUrl([
            'avif_result' => $result->status,
            'avif_message' => $result->message,
        ]);
    }

    private function guard(): void
    {
        if (!current_user_can(OXPULSE_IMAGER_CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'oxpulse-imager'), 403);
        }

        check_admin_referer(SettingsPage::NONCE_ACTION, 'oxpulse_imager_nonce');
    }

    private function redirectUrl(array $queryArgs): string
    {
        $query = http_build_query(array_merge(['page' => SettingsPage::PAGE_SLUG], $queryArgs));
        return admin_url('options-general.php?' . $query);
    }
}
