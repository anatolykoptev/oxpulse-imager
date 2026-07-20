<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options only when the explicit "remove settings on
 * uninstall" option is enabled. Never touches attachments, media files,
 * post content, external systems, or unrelated options.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$remove_on_uninstall = (bool) get_option('oxpulse_imager_remove_on_uninstall', false);

if (!$remove_on_uninstall) {
    return;
}

$options_to_delete = [
    'oxpulse_imager_enabled',
    'oxpulse_imager_endpoint',
    'oxpulse_imager_key',
    'oxpulse_imager_salt',
    'oxpulse_imager_allowed_sources',
    'oxpulse_imager_remove_on_uninstall',
    'oxpulse_imager_diagnostic_level',
    'oxpulse_imager_schema_version',
];

foreach ($options_to_delete as $option) {
    delete_option($option);
}

delete_transient('oxpulse_imager_health_check');
