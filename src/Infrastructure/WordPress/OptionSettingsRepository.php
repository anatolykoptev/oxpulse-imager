<?php
/**
 * Option-based settings repository.
 *
 * Reads and writes plugin settings from WordPress options. Separates
 * non-secret delivery settings from signing secrets. Signing secrets
 * are stored in dedicated options and never returned in bulk reads.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;

final class OptionSettingsRepository
{
    public const OPTION_ENABLED = 'oxpulse_imager_enabled';
    public const OPTION_ENDPOINT = 'oxpulse_imager_endpoint';
    public const OPTION_KEY = 'oxpulse_imager_key';
    public const OPTION_SALT = 'oxpulse_imager_salt';
    public const OPTION_ALLOWED_SOURCES = 'oxpulse_imager_allowed_sources';
    public const OPTION_OUTPUT_FORMAT = 'oxpulse_imager_output_format';
    public const OPTION_DEFAULT_QUALITY = 'oxpulse_imager_default_quality';
    public const OPTION_DEV_HTTP = 'oxpulse_imager_dev_http_override';
    public const OPTION_REMOVE_ON_UNINSTALL = 'oxpulse_imager_remove_on_uninstall';
    public const OPTION_DIAGNOSTIC_LEVEL = 'oxpulse_imager_diagnostic_level';
    public const OPTION_SCHEMA_VERSION = 'oxpulse_imager_schema_version';

    public function loadDeliveryConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: (bool) get_option(self::OPTION_ENABLED, false),
            endpoint: (string) get_option(self::OPTION_ENDPOINT, ''),
            allowedSources: $this->loadAllowedSources(),
            outputFormat: (string) get_option(self::OPTION_OUTPUT_FORMAT, 'auto'),
            defaultQuality: (int) get_option(self::OPTION_DEFAULT_QUALITY, 80),
            devHttpOverride: (bool) get_option(self::OPTION_DEV_HTTP, false)
        );
    }

    public function loadSigningConfig(): ?SigningConfig
    {
        $keyHex = (string) get_option(self::OPTION_KEY, '');
        $saltHex = (string) get_option(self::OPTION_SALT, '');

        if ($keyHex === '' || $saltHex === '') {
            return null;
        }

        try {
            return SigningConfig::fromHex($keyHex, $saltHex);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Save non-secret delivery settings.
     *
     * @param array $values Sanitized values from the settings form.
     * @return void
     */
    public function saveDeliverySettings(array $values): void
    {
        if (array_key_exists('enabled', $values)) {
            update_option(self::OPTION_ENABLED, (bool) $values['enabled']);
        }
        if (array_key_exists('endpoint', $values)) {
            update_option(self::OPTION_ENDPOINT, (string) $values['endpoint']);
        }
        if (array_key_exists('allowed_sources', $values)) {
            update_option(self::OPTION_ALLOWED_SOURCES, $values['allowed_sources']);
        }
        if (array_key_exists('output_format', $values)) {
            update_option(self::OPTION_OUTPUT_FORMAT, (string) $values['output_format']);
        }
        if (array_key_exists('default_quality', $values)) {
            update_option(self::OPTION_DEFAULT_QUALITY, (int) $values['default_quality']);
        }
        if (array_key_exists('dev_http_override', $values)) {
            update_option(self::OPTION_DEV_HTTP, (bool) $values['dev_http_override']);
        }
        if (array_key_exists('remove_on_uninstall', $values)) {
            update_option(self::OPTION_REMOVE_ON_UNINSTALL, (bool) $values['remove_on_uninstall']);
        }
        if (array_key_exists('diagnostic_level', $values)) {
            update_option(self::OPTION_DIAGNOSTIC_LEVEL, (string) $values['diagnostic_level']);
        }
    }

    /**
     * Save signing secrets. These are written but never read back
     * in bulk or displayed in the UI after save.
     *
     * @param string $keyHex
     * @param string $saltHex
     * @return void
     */
    public function saveSecrets(string $keyHex, string $saltHex): void
    {
        update_option(self::OPTION_KEY, $keyHex);
        update_option(self::OPTION_SALT, $saltHex);
    }

    /**
     * Check whether secrets are configured (without returning them).
     */
    public function hasSecrets(): bool
    {
        return get_option(self::OPTION_KEY, '') !== '' && get_option(self::OPTION_SALT, '') !== '';
    }

    /**
     * Returns true if either key or salt is set, for partial-save detection.
     */
    public function hasPartialSecrets(): bool
    {
        $key = (string) get_option(self::OPTION_KEY, '');
        $salt = (string) get_option(self::OPTION_SALT, '');
        return ($key !== '' || $salt !== '') && !($key !== '' && $salt !== '');
    }

    /**
     * Returns a redacted indicator for UI display.
     * Never returns the actual key or salt value.
     */
    public function secretStatus(): string
    {
        $key = (string) get_option(self::OPTION_KEY, '');
        $salt = (string) get_option(self::OPTION_SALT, '');

        if ($key !== '' && $salt !== '') {
            return 'configured';
        }
        if ($key !== '' || $salt !== '') {
            return 'partial';
        }
        return 'empty';
    }

    private function loadAllowedSources(): array
    {
        $sources = get_option(self::OPTION_ALLOWED_SOURCES, []);
        if (!is_array($sources)) {
            return [];
        }
        return array_values(array_filter($sources, fn($s) => is_string($s) && $s !== ''));
    }
}
