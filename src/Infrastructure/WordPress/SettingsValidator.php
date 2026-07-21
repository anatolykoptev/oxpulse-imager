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

use OXPulse\Imager\Domain\Transform\Watermark;

final class SettingsValidator
{
    public const MIN_KEY_BYTES = 16;
    public const MIN_SALT_BYTES = 16;
    public const ALLOWED_FORMATS = ['auto', 'avif', 'webp', 'jpeg'];
    public const ALLOWED_DIAGNOSTIC_LEVELS = ['off', 'basic', 'verbose'];
    public const ALLOWED_WATERMARK_POSITIONS = Watermark::ALLOWED_POSITIONS;
    public const MIN_LQIP_BLUR = 0.1;
    public const MAX_LQIP_BLUR = 100;
    public const MIN_DPR = 1;
    public const MAX_DPR = 8;
    public const ALLOWED_FORMAT_QUALITY_KEYS = ['avif', 'webp', 'jpeg', 'png'];

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

        // Endpoint URL. May be absolute (https://imgproxy.example.com) or
        // relative (/imgproxy) for same-host reverse-proxy setups (Ф1).
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $isRelative = str_starts_with($endpoint, '/');
            if ($isRelative) {
                // Relative endpoint — must start with / and not contain host/scheme.
                // Used for nginx reverse-proxy setups where /imgproxy/* is proxied
                // to a local imgproxy daemon. No scheme/host validation needed.
                if (!preg_match('#^/[a-zA-Z0-9_\-/]*$#', $endpoint)) {
                    $errors['endpoint'] = __('Relative endpoint must start with / and contain only path characters.', 'oxpulse-imager');
                }
            } else {
                $parsed = wp_parse_url($endpoint);
                if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
                    $errors['endpoint'] = __('Endpoint must be a valid URL or a relative path (e.g. /imgproxy).', 'oxpulse-imager');
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
            $parsed = wp_parse_url($source);
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

        // Onboarding flag (Phase 5.5) — boolean pass-through.
        $values['onboarded'] = !empty($input['onboarded']);

        // --- Phase 5.1: imgproxy-native enhancements ---

        // LQIP placeholders.
        $values['lqip_enabled'] = !empty($input['lqip_enabled']);
        $lqipBlur = (float) ($input['lqip_blur'] ?? 1);
        if ($lqipBlur < self::MIN_LQIP_BLUR || $lqipBlur > self::MAX_LQIP_BLUR) {
            $errors['lqip_blur'] = sprintf(
                /* translators: 1: minimum blur, 2: maximum blur. */
                __('LQIP blur must be between %1$s and %2$s.', 'oxpulse-imager'),
                self::MIN_LQIP_BLUR,
                self::MAX_LQIP_BLUR
            );
        }
        $values['lqip_blur'] = (float) max(self::MIN_LQIP_BLUR, min(self::MAX_LQIP_BLUR, $lqipBlur));

        // DPR variants.
        $values['dpr_enabled'] = !empty($input['dpr_enabled']);
        $values['dpr_variants'] = $this->parseDprVariants($input['dpr_variants'] ?? '');

        // Per-format quality.
        $values['format_quality'] = $this->parseFormatQuality($input['format_quality'] ?? [], $errors);

        // Watermark.
        $values['watermark'] = $this->parseWatermark($input['watermark'] ?? [], $errors);

        // --- Ф1: Source addressing mode ---

        $sourceMode = (string) ($input['source_mode'] ?? 'http');
        $values['source_mode'] = in_array($sourceMode, ['http', 'local'], true) ? $sourceMode : 'http';

        $localBasePath = trim((string) ($input['local_base_path'] ?? ''));
        if ($values['source_mode'] === 'local') {
            if ($localBasePath === '') {
                $errors['local_base_path'] = __('Local base path is required when source mode is "local".', 'oxpulse-imager');
            } elseif (!str_starts_with($localBasePath, '/')) {
                $errors['local_base_path'] = __('Local base path must be an absolute filesystem path (starting with /).', 'oxpulse-imager');
            } elseif (!is_dir($localBasePath)) {
                $errors['local_base_path'] = __('Local base path does not exist or is not a directory.', 'oxpulse-imager');
            } elseif (!is_readable($localBasePath)) {
                $errors['local_base_path'] = __('Local base path is not readable by the web server.', 'oxpulse-imager');
            }
        }
        $values['local_base_path'] = $localBasePath;

        // Buffer rewriting (Ф2) — boolean toggle.
        $values['buffer_rewriting_enabled'] = !empty($input['buffer_rewriting_enabled']);

        // RankMath compatibility (Ф3) — boolean toggle, default true.
        $values['rankmath_compatibility'] = !empty($input['rankmath_compatibility']);

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

    /**
     * Parse DPR variants from a comma-separated string (e.g. "1,2,3").
     *
     * Each value must be an integer between MIN_DPR and MAX_DPR. Values
     * are deduplicated and sorted ascending. Empty input → empty array
     * (DPR variants disabled).
     *
     * @param string $raw
     * @return array<int>
     */
    private function parseDprVariants(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = explode(',', $raw);
        $variants = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $val = (int) $part;
            if ($val >= self::MIN_DPR && $val <= self::MAX_DPR) {
                $variants[$val] = $val;
            }
        }

        $variants = array_values($variants);
        sort($variants);
        return $variants;
    }

    /**
     * Parse per-format quality overrides from form input.
     *
     * Expected input shape: ['avif' => '70', 'webp' => '80', ...].
     * Values are cast to int and clamped to 1-100. Only keys in
     * ALLOWED_FORMAT_QUALITY_KEYS are accepted; others are silently
     * dropped. Empty values are dropped (means "use default quality").
     *
     * @param array $input
     * @param array<string,string> $errors Errors array (passed by reference).
     * @return array<string,int>
     */
    private function parseFormatQuality(array $input, array &$errors): array
    {
        $result = [];
        foreach (self::ALLOWED_FORMAT_QUALITY_KEYS as $fmt) {
            if (!isset($input[$fmt])) {
                continue;
            }
            $raw = trim((string) $input[$fmt]);
            if ($raw === '') {
                continue;
            }
            $val = (int) $raw;
            if ($val < 1 || $val > 100) {
                $errors['format_quality_' . $fmt] = sprintf(
                    /* translators: 1: format name, 2: minimum, 3: maximum. */
                    __('Quality for %1$s must be between %2$d and %3$d.', 'oxpulse-imager'),
                    $fmt,
                    1,
                    100
                );
                continue;
            }
            $result[$fmt] = $val;
        }
        return $result;
    }

    /**
     * Parse watermark configuration from form input.
     *
     * Expected input shape: [
     *   'enabled' => '1',
     *   'opacity' => '0.5',
     *   'position' => 'ce',
     *   'x_offset' => '0',
     *   'y_offset' => '0',
     *   'scale' => '0.1',
     * ]
     *
     * Returns null when watermark is disabled or any value is invalid.
     * Errors are added to the $errors array (passed by reference).
     *
     * @param array $input
     * @param array<string,string> $errors Errors array (passed by reference).
     * @return array|null
     */
    private function parseWatermark(array $input, array &$errors): ?array
    {
        if (empty($input['enabled'])) {
            return null;
        }

        $opacity = (float) ($input['opacity'] ?? 1);
        if ($opacity < 0 || $opacity > 1) {
            $errors['watermark_opacity'] = __('Watermark opacity must be between 0 and 1.', 'oxpulse-imager');
            return null;
        }

        $position = (string) ($input['position'] ?? Watermark::POS_CENTER);
        if (!in_array($position, self::ALLOWED_WATERMARK_POSITIONS, true)) {
            $errors['watermark_position'] = __('Invalid watermark position.', 'oxpulse-imager');
            return null;
        }

        $xOffset = (int) ($input['x_offset'] ?? 0);
        $yOffset = (int) ($input['y_offset'] ?? 0);
        $scale = (float) ($input['scale'] ?? 0);

        if ($scale < 0 || $scale > 1) {
            $errors['watermark_scale'] = __('Watermark scale must be between 0 and 1.', 'oxpulse-imager');
            return null;
        }

        return [
            'enabled' => true,
            'opacity' => $opacity,
            'position' => $position,
            'x_offset' => $xOffset,
            'y_offset' => $yOffset,
            'scale' => $scale,
        ];
    }
}
