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
$diagnosticLevel = (string) get_option('oxpulse_imager_diagnostic_level', 'off');
$removeOnUninstall = (bool) get_option('oxpulse_imager_remove_on_uninstall', false);

// Phase 5.1: imgproxy-native enhancement options.
$lqipEnabled = $delivery->lqipEnabled ?? false;
$lqipBlur = $delivery->lqipBlur ?? 1;
$dprEnabled = $delivery->dprEnabled ?? false;
$dprVariants = $delivery->dprVariants ?? [];
$dprVariantsText = implode(',', $dprVariants);
$formatQuality = $delivery->formatQuality ?? [];
$watermark = $delivery->watermark;
$watermarkEnabled = $watermark !== null;
$watermarkOpacity = $watermark?->opacity ?? 1;
$watermarkPosition = $watermark?->position ?? 'ce';
$watermarkXOffset = $watermark?->xOffset ?? 0;
$watermarkYOffset = $watermark?->yOffset ?? 0;
$watermarkScale = $watermark?->scale ?? 0;

$settingsUpdated = isset($_GET['settings_updated']) ? (string) $_GET['settings_updated'] : '';
$healthResult = isset($_GET['health_result']) ? (string) $_GET['health_result'] : '';
$healthMessage = isset($_GET['health_message']) ? (string) $_GET['health_message'] : '';
$avifResult = isset($_GET['avif_result']) ? (string) $_GET['avif_result'] : '';
$avifMessage = isset($_GET['avif_message']) ? (string) $_GET['avif_message'] : '';
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

    <?php if ($avifResult !== ''): ?>
        <?php $avifClass = $avifResult === 'ok' ? 'notice-success' : 'notice-error'; ?>
        <div class="notice <?php echo esc_attr($avifClass); ?> is-dismissible">
            <p>
                <strong><?php esc_html_e('AVIF check:', 'oxpulse-imager'); ?></strong>
                <?php echo esc_html($avifMessage); ?>
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
                <th scope="row"><label for="oxpulse-q-avif"><?php esc_html_e('AVIF quality', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="number" id="oxpulse-q-avif" name="oxpulse_imager[format_quality][avif]" min="1" max="100" value="<?php echo esc_attr((string) ($formatQuality['avif'] ?? '')); ?>" class="small-text" placeholder="<?php esc_attr_e('use default', 'oxpulse-imager'); ?>" />
                    <span class="description"><?php esc_html_e('1–100. Overrides default quality for AVIF output. Leave empty to use default. AVIF typically looks good at 50-70.', 'oxpulse-imager'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-q-webp"><?php esc_html_e('WebP quality', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="number" id="oxpulse-q-webp" name="oxpulse_imager[format_quality][webp]" min="1" max="100" value="<?php echo esc_attr((string) ($formatQuality['webp'] ?? '')); ?>" class="small-text" placeholder="<?php esc_attr_e('use default', 'oxpulse-imager'); ?>" />
                    <span class="description"><?php esc_html_e('1–100. Overrides default quality for WebP output. Leave empty to use default. WebP typically looks good at 70-85.', 'oxpulse-imager'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('LQIP placeholders', 'oxpulse-imager'); ?></th>
                <td>
                    <fieldset>
                        <label for="oxpulse-lqip-enabled">
                            <input type="checkbox" id="oxpulse-lqip-enabled" name="oxpulse_imager[lqip_enabled]" value="1" <?php checked($lqipEnabled); ?> />
                            <?php esc_html_e('Emit low-quality image placeholders (data-placeholder attribute)', 'oxpulse-imager'); ?>
                        </label>
                        <p>
                            <label for="oxpulse-lqip-blur"><?php esc_html_e('Blur sigma:', 'oxpulse-imager'); ?></label>
                            <input type="number" id="oxpulse-lqip-blur" name="oxpulse_imager[lqip_blur]" min="0.1" max="100" step="0.1" value="<?php echo esc_attr((string) $lqipBlur); ?>" class="small-text" />
                        </p>
                        <p class="description"><?php esc_html_e('Generates a tiny blurred preview (20px, blur:1) via imgproxy and adds it as data-placeholder on <img> tags. Reduces Cumulative Layout Shift (CLS). Falls back to an inline SVG when imgproxy is unreachable.', 'oxpulse-imager'); ?></p>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('DPR-aware srcset', 'oxpulse-imager'); ?></th>
                <td>
                    <fieldset>
                        <label for="oxpulse-dpr-enabled">
                            <input type="checkbox" id="oxpulse-dpr-enabled" name="oxpulse_imager[dpr_enabled]" value="1" <?php checked($dprEnabled); ?> />
                            <?php esc_html_e('Generate 1x/2x/3x srcset variants for images without srcset', 'oxpulse-imager'); ?>
                        </label>
                        <p>
                            <label for="oxpulse-dpr-variants"><?php esc_html_e('DPR multipliers:', 'oxpulse-imager'); ?></label>
                            <input type="text" id="oxpulse-dpr-variants" name="oxpulse_imager[dpr_variants]" value="<?php echo esc_attr($dprVariantsText); ?>" class="regular-text" placeholder="1,2,3" />
                        </p>
                        <p class="description"><?php esc_html_e('Comma-separated DPR multipliers (e.g. 1,2,3). For <img> tags with width but no srcset, generates x-descriptor variants via imgproxy dpr: option. Images that already have w-descriptor srcset are left alone (w-descriptors handle DPR natively).', 'oxpulse-imager'); ?></p>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Watermark', 'oxpulse-imager'); ?></th>
                <td>
                    <fieldset>
                        <label for="oxpulse-wm-enabled">
                            <input type="checkbox" id="oxpulse-wm-enabled" name="oxpulse_imager[watermark][enabled]" value="1" <?php checked($watermarkEnabled); ?> />
                            <?php esc_html_e('Apply watermark via imgproxy wm: option', 'oxpulse-imager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('The watermark image is configured server-side via IMGPROXY_WATERMARK_PATH / IMGPROXY_WATERMARK_URL. This setting controls placement only.', 'oxpulse-imager'); ?></p>
                        <p>
                            <label for="oxpulse-wm-opacity"><?php esc_html_e('Opacity:', 'oxpulse-imager'); ?></label>
                            <input type="number" id="oxpulse-wm-opacity" name="oxpulse_imager[watermark][opacity]" min="0" max="1" step="0.05" value="<?php echo esc_attr((string) $watermarkOpacity); ?>" class="small-text" />
                            <span class="description"><?php esc_html_e('0 = transparent, 1 = opaque.', 'oxpulse-imager'); ?></span>
                        </p>
                        <p>
                            <label for="oxpulse-wm-position"><?php esc_html_e('Position:', 'oxpulse-imager'); ?></label>
                            <select id="oxpulse-wm-position" name="oxpulse_imager[watermark][position]">
                                <?php
                                $wmPositions = [
                                    'ce' => __('Center', 'oxpulse-imager'),
                                    'no' => __('North', 'oxpulse-imager'),
                                    'ea' => __('East', 'oxpulse-imager'),
                                    'so' => __('South', 'oxpulse-imager'),
                                    'we' => __('West', 'oxpulse-imager'),
                                    'noea' => __('North-East', 'oxpulse-imager'),
                                    'nowe' => __('North-West', 'oxpulse-imager'),
                                    'soea' => __('South-East', 'oxpulse-imager'),
                                    'sowe' => __('South-West', 'oxpulse-imager'),
                                    're' => __('Replicate (tile)', 'oxpulse-imager'),
                                    'sm' => __('Smart', 'oxpulse-imager'),
                                ];
                                foreach ($wmPositions as $code => $label):
                                ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $watermarkPosition); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="oxpulse-wm-x"><?php esc_html_e('X offset (px):', 'oxpulse-imager'); ?></label>
                            <input type="number" id="oxpulse-wm-x" name="oxpulse_imager[watermark][x_offset]" value="<?php echo esc_attr((string) $watermarkXOffset); ?>" class="small-text" />
                            <label for="oxpulse-wm-y"><?php esc_html_e('Y offset (px):', 'oxpulse-imager'); ?></label>
                            <input type="number" id="oxpulse-wm-y" name="oxpulse_imager[watermark][y_offset]" value="<?php echo esc_attr((string) $watermarkYOffset); ?>" class="small-text" />
                        </p>
                        <p>
                            <label for="oxpulse-wm-scale"><?php esc_html_e('Scale:', 'oxpulse-imager'); ?></label>
                            <input type="number" id="oxpulse-wm-scale" name="oxpulse_imager[watermark][scale]" min="0" max="1" step="0.05" value="<?php echo esc_attr((string) $watermarkScale); ?>" class="small-text" />
                            <span class="description"><?php esc_html_e('0 = auto-size, 0.1 = 10% of source image. Relative to the source image dimensions.', 'oxpulse-imager'); ?></span>
                        </p>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="oxpulse-diagnostic"><?php esc_html_e('Diagnostic logging', 'oxpulse-imager'); ?></label></th>
                <td>
                    <select id="oxpulse-diagnostic" name="oxpulse_imager[diagnostic_level]">
                        <?php foreach (OXPulse\Imager\Infrastructure\WordPress\SettingsValidator::ALLOWED_DIAGNOSTIC_LEVELS as $level): ?>
                            <option value="<?php echo esc_attr($level); ?>" <?php selected($level, $diagnosticLevel); ?>><?php echo esc_html($level); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('off = silent. basic = log rewrite/preserve counts per request. verbose = log each URL with reason. Logs go to PHP error log via error_log().', 'oxpulse-imager'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Cleanup', 'oxpulse-imager'); ?></th>
                <td>
                    <fieldset>
                        <label for="oxpulse-uninstall">
                            <input type="checkbox" id="oxpulse-uninstall" name="oxpulse_imager[remove_on_uninstall]" value="1" <?php checked($removeOnUninstall); ?> />
                            <?php esc_html_e('Remove all plugin data (settings + secrets) on uninstall', 'oxpulse-imager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, deleting the plugin via Plugins > Installed Plugins will delete all OXPulse Imager options from the database. Off by default — keeps settings across re-installs.', 'oxpulse-imager'); ?></p>
                    </fieldset>
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

    <h2><?php esc_html_e('AVIF format check', 'oxpulse-imager'); ?></h2>
    <p><?php esc_html_e('Verify that imgproxy is configured for AVIF format negotiation (IMGPROXY_AUTO_AVIF=true). Sends a request with Accept: image/avif and checks the response Content-Type.', 'oxpulse-imager'); ?></p>
    <form method="post" action="<?php echo esc_url($saveAction); ?>">
        <input type="hidden" name="action" value="oxpulse_imager_test_avif" />
        <input type="hidden" name="oxpulse_imager_nonce" value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="oxpulse_imager[endpoint]" value="<?php echo esc_attr($endpoint); ?>" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="oxpulse-sample-image"><?php esc_html_e('Sample image URL', 'oxpulse-imager'); ?></label></th>
                <td>
                    <input type="url" id="oxpulse-sample-image" name="oxpulse_imager[sample_image]" class="regular-text" value="" placeholder="<?php esc_attr_e('https://example.com/wp-content/uploads/test.jpg', 'oxpulse-imager'); ?>" />
                    <p class="description"><?php esc_html_e('A publicly accessible image URL from your allowed sources. If empty, the first allowed source + /oxpulse-avif-test.jpg is used.', 'oxpulse-imager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
        submit_button(
            __('Test AVIF support', 'oxpulse-imager'),
            'secondary',
            'oxpulse-test-avif',
            false
        );
        ?>
    </form>
</div>
