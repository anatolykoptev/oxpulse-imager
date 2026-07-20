<?php
/**
 * Settings validator.
 *
 * Sanitizes and validates all settings fields from the admin form.
 * Enforces minimum key/salt length for production safety, URL format
 * validation, and allowed source prefix normalization.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

final class SettingsValidator
{
    public const MIN_KEY_BYTES = 16;
    public const MIN_SALT_BYTES = 16;
    public const ALLOWED_FORMATS = ['auto', 'avif', 'webp', 'jpeg'];
    public const ALLOWED_DIAGNOSTIC_LEVELS = ['off', 'basic', 'verbose'];

    /**
     * Validate and sanitize all settings from a form submission.
     *
     * @param array $input Raw input from the settings form.
     * @return array{values: array, errors: array<string,string>}
     */
    public function validate(array $input): array
    {
        $values = [];
        $errors = [];

        // Enabled toggle.
        $values['enabled'] = !empty($input['enabled']);

        // Endpoint URL.
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $parsed = parse_url($endpoint);
            if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
                $errors['endpoint'] = __('Endpoint must be a valid URL.', 'oxpulse-imager');
            } elseif (strtolower($parsed['scheme']) !== 'https') {
                if (!empty($input['dev_http_override'])) {
                    if (strtolower($parsed['scheme']) !== 'http') {
                        $errors['endpoint'] = __('Endpoint must be HTTP or HTTPS.', 'oxpulse-imager');
                    }
                } else {
                    $errors['endpoint'] = __('Endpoint must use HTTPS in production. Enable dev HTTP override only for local development.', 'oxpulse-imager');
                }
            }
        }
        $values['endpoint'] = rtrim($endpoint, '/');

        // Signing key (hex).
        $keyHex = trim((string) ($input['key'] ?? ''));
        if ($keyHex !== '') {
            if (!$this->isValidHex($keyHex)) {
                $errors['key'] = __('Key must be a non-empty even-length hexadecimal string.', 'oxpulse-imager');
            } elseif (strlen(@hex2bin($keyHex) ?: '') < self::MIN_KEY_BYTES) {
                $errors['key'] = sprintf(
                    /* translators: %d: minimum key length in bytes. */
                    __('Key must be at least %d bytes after hex decoding.', 'oxpulse-imager'),
                    self::MIN_KEY_BYTES
                );
            }
        }
        $values['key'] = $keyHex;

        // Signing salt (hex).
        $saltHex = trim((string) ($input['salt'] ?? ''));
        if ($saltHex !== '') {
            if (!$this->isValidHex($saltHex)) {
                $errors['salt'] = __('Salt must be a non-empty even-length hexadecimal string.', 'oxpulse-imager');
            } elseif (strlen(@hex2bin($saltHex) ?: '') < self::MIN_SALT_BYTES) {
                $errors['salt'] = sprintf(
                    /* translators: %d: minimum salt length in bytes. */
                    __('Salt must be at least %d bytes after hex decoding.', 'oxpulse-imager'),
                    self::MIN_SALT_BYTES
                );
            }
        }
        $values['salt'] = $saltHex;

        // Allowed sources (array of URL prefixes).
        $rawSources = $input['allowed_sources'] ?? '';
        $sources = $this->parseAllowedSources($rawSources);
        foreach ($sources as $i => $source) {
            $parsed = parse_url($source);
            if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
                $errors['allowed_sources'] = __('Allowed sources must be valid HTTP(S) URLs with trailing path boundary.', 'oxpulse-imager');
            }
        }
        $values['allowed_sources'] = $sources;

        // Output format.
        $format = (string) ($input['output_format'] ?? 'auto');
        $values['output_format'] = in_array($format, self::ALLOWED_FORMATS, true) ? $format : 'auto';

        // Default quality.
        $quality = (int) ($input['default_quality'] ?? 80);
        $values['default_quality'] = max(1, min(100, $quality));

        // Dev HTTP override.
        $values['dev_http_override'] = !empty($input['dev_http_override']);

        // Remove on uninstall.
        $values['remove_on_uninstall'] = !empty($input['remove_on_uninstall']);

        // Diagnostic level.
        $diagLevel = (string) ($input['diagnostic_level'] ?? 'off');
        $values['diagnostic_level'] = in_array($diagLevel, self::ALLOWED_DIAGNOSTIC_LEVELS, true) ? $diagLevel : 'off';

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Parse allowed sources from a textarea (one URL per line).
     *
     * Each source is normalized to end with a trailing slash so that
     * prefix matching enforces a path boundary (e.g. an allowed source
     * of "https://example.com/uploads" does NOT match
     * "https://example.com/uploads-private/secret.jpg").
     *
     * @param string $raw
     * @return array<string>
     */
    private function parseAllowedSources(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $sources = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                if (!str_ends_with($line, '/')) {
                    $line .= '/';
                }
                $sources[] = $line;
            }
        }
        return $sources;
    }

    private function isValidHex(string $value): bool
    {
        return $value !== '' && strlen($value) % 2 === 0 && ctype_xdigit($value);
    }
}
