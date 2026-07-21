<?php
/**
 * Settings page view template.
 *
 * All values are escaped on output. Signing secrets are NEVER displayed —
 * only a status indicator (configured / partial / empty) is shown. The
 * key/salt fields are always empty input fields; submitting empty values
 * means "keep existing secrets".
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 *
 * @var array $delivery {
 *     @type bool   $enabled
 *     @type string $endpoint
 *     @type array  $allowedSources
 *     @type string $outputFormat
 *     @type int    $defaultQuality
 *     @type bool   $devHttpOverride
 * }
 * @var string $secretStatus  One of: 'configured', 'partial', 'empty'.
 * @var array  $sources       Convenience alias of $delivery['allowedSources'].
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @var OXPulse\Imager\Integration\WordPress\Admin\SettingsPage $this
 */
$pageSlug = OXPulse\Imager\Integration\WordPress\Admin\SettingsPage::PAGE_SLUG;
$nonceAction = OXPulse\Imager\Integration\WordPress\Admin\SettingsPage::NONCE_ACTION;
$nonce = wp_create_nonce($nonceAction);

$enabled = $delivery->enabled ?? false;
$endpoint = $delivery->endpoint ?? '';
$allowedSourcesText = implode("\n", $delivery->allowedSources ?? []);
$outputFormat = $delivery->outputFormat ?? 'auto';
$defaultQuality = $delivery->defaultQuality ?? 80;
$devHttpOverride = $delivery->devHttpOverride ?? false;

$settingsUpdated = isset($_GET['settings_updated']) ? (string) $_GET['settings_updated'] : '';
$healthResult = isset($_GET['health_result']) ? (string) $_GET['health_result'] : '';
$healthMessage = isset($_GET['health_message']) ? (string) $_GET['health_message'] : '';
$settingsErrorsRaw = isset($_GET['settings_errors']) ? (string) $_GET['settings_errors'] : '';
$settingsErrors = [];
if ($settingsErrorsRaw !== '') {
    $decoded = json_decode($settingsErrorsRaw, true);
    if (is_array($decoded)) {
        $settingsErrors = $decoded;
    }
}

$secretLabel = match ($secretStatus) {
    'configured' => __('Secrets configured. Values are hidden for security.', 'oxpulse-imager'),
    'partial'    => __('Partial secrets detected. Please set both key and salt.', 'oxpulse-imager'),
    default      => __('No secrets configured. Generate a key and salt to enable signed URL delivery.', 'oxpulse-imager'),
};

$secretClass = match ($secretStatus) {
    'configured' => 'oxpulse-status-ok',
    'partial'    => 'oxpulse-status-warning',
    default      => 'oxpulse-status-empty',
};

$saveAction = admin_url('admin-post.php');
?>
<div class="wrap oxpulse-imager-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if ($settingsUpdated === '1'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'oxpulse-imager'); ?></p></div>
    <?php endif; ?>

    <?php if (!empty($settingsErrors)): ?>
        <div class="notice notice-error is-dismissible">
            <ul>
                <?php foreach ($settingsErrors as $field => $message): ?>
                    <li><strong><?php echo esc_html($field); ?>:</strong> <?php echo esc_html($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($healthResult !== ''): ?>
        <?php $healthClass = $healthResult === 'ok' ? 'notice-success' : 'notice-error'; ?>
        <div class="notice <?php echo esc_attr($healthClass); ?> is-dismissible">
            <p>
                <strong><?php esc_html_e('Health check:', 'oxpulse-imager'); ?></strong>
                <?php echo esc_html($healthMessage); ?>
                <?php if ($healthResult !== 'ok' && $healthResult !== 'failed' && $healthResult !== 'unreachable'): ?>
                    <?php printf('(HTTP %s)', esc_html($healthResult)); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <p class="oxpulse-status <?php echo esc_attr($secretClass); ?>"><?php echo esc_html($secretLabel); ?></p>

    <form method="post" action="<?php echo esc_url($saveAction); ?>" novalidate="novalidate">
        <input type="hidden" name="action" value="oxpulse_imager_save_settings" />
        <input type="hidden" name="oxpulse_imager_nonce" value="<?php echo esc_attr($nonce); ?>" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="oxpulse-enabled"><?php esc_html_e('Enable delivery', 'oxpulse-imager'); ?></label></th>
                <td>
                    <label for="oxpulse-enabled">
                        <input type="checkbox" id="oxpulse-enabled" name="oxpulse_imager[enabled]" value="1" <?php checked($enabled); ?> />
                        <?php esc_html_e('Rewrite approved image URLs to signed imgproxy URLs', 'oxpulse-imager'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When disabled, the plugin is a complete no-op on the frontend.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-endpoint"><?php esc_html_e('imgproxy endpoint', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="url" id="oxpulse-endpoint" name="oxpulse_imager[endpoint]" class="regular-text" value="<?php echo esc_attr($endpoint); ?>" placeholder="https://imgproxy.example.com" />
                    <p class="description"><?php esc_html_e('Base URL of your self-hosted imgproxy instance. HTTPS required in production.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-key"><?php esc_html_e('Signing key (hex)', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="password" id="oxpulse-key" name="oxpulse_imager[key]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Leave empty to keep existing', 'oxpulse-imager'); ?>" />
                    <p class="description"><?php esc_html_e('Hex-encoded imgproxy key. Minimum 16 bytes after decoding. Never displayed after save.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-salt"><?php esc_html_e('Signing salt (hex)', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="password" id="oxpulse-salt" name="oxpulse_imager[salt]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Leave empty to keep existing', 'oxpulse-imager'); ?>" />
                    <p class="description"><?php esc_html_e('Hex-encoded imgproxy salt. Minimum 16 bytes after decoding. Never displayed after save.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-sources"><?php esc_html_e('Allowed source origins', 'oxpulse-imager'); ?></label></th>
                <td>
                    <textarea id="oxpulse-sources" name="oxpulse_imager[allowed_sources]" rows="4" class="large-text code" placeholder="https://example.com/wp-content/uploads/"><?php echo esc_textarea($allowedSourcesText); ?></textarea>
                    <p class="description"><?php esc_html_e('One URL prefix per line. Only images whose URL starts with one of these prefixes will be rewritten. A trailing slash enforces a path boundary.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-format"><?php esc_html_e('Default output format', 'oxpulse-imager'); ?></label></th>
                <td>
                    <select id="oxpulse-format" name="oxpulse_imager[output_format]">
                        <?php foreach (OXPulse\Imager\Infrastructure\WordPress\SettingsValidator::ALLOWED_FORMATS as $fmt): ?>
                            <option value="<?php echo esc_attr($fmt); ?>" <?php selected($fmt, $outputFormat); ?>><?php echo esc_html($fmt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('auto = Accept header negotiation (AVIF/WebP/original based on browser support, requires IMGPROXY_AUTO_AVIF on the server). Explicit format overrides negotiation.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-quality"><?php esc_html_e('Default quality', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="number" id="oxpulse-quality" name="oxpulse_imager[default_quality]" min="1" max="100" value="<?php echo esc_attr((string) $defaultQuality); ?>" class="small-text" />
                    <span class="description"><?php esc_html_e('1–100. Used when a transform request does not specify quality.', 'oxpulse-imager'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Development overrides', 'oxpulse-imager'); ?></th>
                <td>
                    <fieldset>
                        <label for="oxpulse-dev-http">
                            <input type="checkbox" id="oxpulse-dev-http" name="oxpulse_imager[dev_http_override]" value="1" <?php checked($devHttpOverride); ?> />
                            <?php esc_html_e('Allow plain HTTP imgproxy endpoint (local development only)', 'oxpulse-imager'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save settings', 'oxpulse-imager')); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Health check', 'oxpulse-imager'); ?></h2>
    <p><?php esc_html_e('Verify that the configured imgproxy endpoint is reachable and reports healthy status.', 'oxpulse-imager'); ?></p>
    <form method="post" action="<?php echo esc_url($saveAction); ?>">
        <input type="hidden" name="action" value="oxpulse_imager_test_connection" />
        <input type="hidden" name="oxpulse_imager_nonce" value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="oxpulse_imager[endpoint]" value="<?php echo esc_attr($endpoint); ?>" />
        <?php
        submit_button(
            __('Test connection', 'oxpulse-imager'),
            'secondary',
            'oxpulse-test-connection',
            false
        );
        ?>
    </form>
</div>
