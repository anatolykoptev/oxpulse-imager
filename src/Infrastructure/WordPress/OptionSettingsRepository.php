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
use OXPulse\Imager\Domain\Transform\Watermark;

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
    public const OPTION_ONBOARDED = 'oxpulse_imager_onboarded';

    // Phase 5.1: imgproxy-native enhancement options.
    public const OPTION_LQIP_ENABLED = 'oxpulse_imager_lqip_enabled';
    public const OPTION_LQIP_BLUR = 'oxpulse_imager_lqip_blur';
    public const OPTION_DPR_ENABLED = 'oxpulse_imager_dpr_enabled';
    public const OPTION_DPR_VARIANTS = 'oxpulse_imager_dpr_variants';
    public const OPTION_FORMAT_QUALITY = 'oxpulse_imager_format_quality';
    public const OPTION_WATERMARK = 'oxpulse_imager_watermark';

    // Source addressing mode (Ф1): 'http' (imgproxy fetches via HTTP) or
    // 'local' (imgproxy reads from filesystem via local:// transport).
    public const OPTION_SOURCE_MODE = 'oxpulse_imager_source_mode';
    public const OPTION_LOCAL_BASE_PATH = 'oxpulse_imager_local_base_path';

    // Buffer rewriting (Ф2): ob_start + regex for theme-hardcoded <img> tags.
    public const OPTION_BUFFER_REWRITING_ENABLED = 'oxpulse_imager_buffer_rewriting_enabled';

    // RankMath compatibility (Ф3): restore direct URLs in og:image meta tags.
    public const OPTION_RANKMATH_COMPATIBILITY = 'oxpulse_imager_rankmath_compatibility';

    // Save-Data header support (Ф7): reduce quality when browser sends Save-Data: on.
    public const OPTION_SAVE_DATA_QUALITY_REDUCTION = 'oxpulse_imager_save_data_quality_reduction';

    // Size-based quality tiers (Ф8): map of maxWidth => quality.
    public const OPTION_SIZE_QUALITY_TIERS = 'oxpulse_imager_size_quality_tiers';

    public function loadDeliveryConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: (bool) get_option(self::OPTION_ENABLED, false),
            endpoint: (string) get_option(self::OPTION_ENDPOINT, ''),
            allowedSources: $this->loadAllowedSources(),
            outputFormat: (string) get_option(self::OPTION_OUTPUT_FORMAT, 'auto'),
            defaultQuality: (int) get_option(self::OPTION_DEFAULT_QUALITY, 80),
            devHttpOverride: (bool) get_option(self::OPTION_DEV_HTTP, false),
            lqipEnabled: (bool) get_option(self::OPTION_LQIP_ENABLED, false),
            lqipBlur: (float) get_option(self::OPTION_LQIP_BLUR, 1),
            dprEnabled: (bool) get_option(self::OPTION_DPR_ENABLED, false),
            dprVariants: $this->loadDprVariants(),
            watermark: $this->loadWatermark(),
            formatQuality: $this->loadFormatQuality(),
            sourceMode: (string) get_option(self::OPTION_SOURCE_MODE, 'http'),
            localBasePath: (string) get_option(self::OPTION_LOCAL_BASE_PATH, ''),
            bufferRewritingEnabled: (bool) get_option(self::OPTION_BUFFER_REWRITING_ENABLED, false),
            rankMathCompatibility: (bool) get_option(self::OPTION_RANKMATH_COMPATIBILITY, true),
            saveDataQualityReduction: (int) get_option(self::OPTION_SAVE_DATA_QUALITY_REDUCTION, 15),
            sizeQualityTiers: $this->loadSizeQualityTiers(),
        );
    }

    /**
     * Resolve a configured imgproxy endpoint to an ABSOLUTE URL.
     *
     * Relative endpoints (e.g. '/imgproxy' for same-host nginx
     * reverse-proxy setups) are resolved against home_url() so that
     * generated imgproxy URLs are always absolute — required by
     * wp_get_attachment_url (contractually absolute), JSON-LD
     * ImageObject.url, og:image, RSS/feeds, REST, and sitemaps.
     *
     * Absolute endpoints (e.g. 'https://imgproxy.example.com') pass
     * through unchanged. Empty endpoints return empty.
     *
     * This is the WordPress-infrastructure boundary where the endpoint
     * option is read; callers resolve BEFORE injecting the endpoint
     * into ImgproxyUrlGenerator, keeping the generator pure.
     */
    public static function resolveEndpoint(string $endpoint): string
    {
        if ($endpoint === '') {
            return '';
        }

        // Relative endpoint — resolve against the site host.
        if (str_starts_with($endpoint, '/')) {
            return home_url($endpoint);
        }

        // Already absolute — keep as-is.
        return $endpoint;
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

        // Phase 5.1: imgproxy-native enhancement options.
        if (array_key_exists('lqip_enabled', $values)) {
            update_option(self::OPTION_LQIP_ENABLED, (bool) $values['lqip_enabled']);
        }
        if (array_key_exists('lqip_blur', $values)) {
            update_option(self::OPTION_LQIP_BLUR, (float) $values['lqip_blur']);
        }
        if (array_key_exists('dpr_enabled', $values)) {
            update_option(self::OPTION_DPR_ENABLED, (bool) $values['dpr_enabled']);
        }
        if (array_key_exists('dpr_variants', $values)) {
            update_option(self::OPTION_DPR_VARIANTS, $values['dpr_variants']);
        }
        if (array_key_exists('format_quality', $values)) {
            update_option(self::OPTION_FORMAT_QUALITY, $values['format_quality']);
        }
        if (array_key_exists('watermark', $values)) {
            update_option(self::OPTION_WATERMARK, $values['watermark']);
        }

        // Source addressing mode (Ф1).
        if (array_key_exists('source_mode', $values)) {
            $mode = (string) $values['source_mode'];
            if (in_array($mode, ['http', 'local'], true)) {
                update_option(self::OPTION_SOURCE_MODE, $mode);
            }
        }
        if (array_key_exists('local_base_path', $values)) {
            update_option(self::OPTION_LOCAL_BASE_PATH, (string) $values['local_base_path']);
        }

        // Buffer rewriting (Ф2).
        if (array_key_exists('buffer_rewriting_enabled', $values)) {
            update_option(self::OPTION_BUFFER_REWRITING_ENABLED, (bool) $values['buffer_rewriting_enabled']);
        }

        // RankMath compatibility (Ф3).
        if (array_key_exists('rankmath_compatibility', $values)) {
            update_option(self::OPTION_RANKMATH_COMPATIBILITY, (bool) $values['rankmath_compatibility']);
        }

        // Save-Data quality reduction (Ф7).
        if (array_key_exists('save_data_quality_reduction', $values)) {
            $reduction = (int) $values['save_data_quality_reduction'];
            update_option(self::OPTION_SAVE_DATA_QUALITY_REDUCTION, max(0, min(50, $reduction)));
        }

        // Size-based quality tiers (Ф8/Ф11). Two forms per tier:
        // int (simple, q:) or array<string,int> (per-format, fq:).
        if (array_key_exists('size_quality_tiers', $values)) {
            $tiers = $values['size_quality_tiers'];
            $clean = [];
            if (is_array($tiers)) {
                foreach ($tiers as $maxWidth => $quality) {
                    $mw = (int) $maxWidth;
                    if ($mw <= 0) {
                        continue;
                    }
                    if (is_array($quality)) {
                        $cleanFmt = [];
                        foreach ($quality as $fmt => $q) {
                            $qi = (int) $q;
                            if (is_string($fmt) && $fmt !== '' && $qi >= 1 && $qi <= 100) {
                                $cleanFmt[$fmt] = $qi;
                            }
                        }
                        if (!empty($cleanFmt)) {
                            $clean[$mw] = $cleanFmt;
                        }
                    } else {
                        $q = (int) $quality;
                        if ($q >= 1 && $q <= 100) {
                            $clean[$mw] = $q;
                        }
                    }
                }
            }
            ksort($clean, SORT_NUMERIC);
            update_option(self::OPTION_SIZE_QUALITY_TIERS, $clean);
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

    /**
     * Load DPR variants. Stored as an array of integers, e.g. [1, 2, 3].
     * Returns empty array when the option is missing or malformed.
     *
     * @return array<int>
     */
    private function loadDprVariants(): array
    {
        $variants = get_option(self::OPTION_DPR_VARIANTS, []);
        if (!is_array($variants)) {
            return [];
        }
        $result = [];
        foreach ($variants as $v) {
            if (is_int($v) && $v >= 1 && $v <= 8) {
                $result[] = $v;
            }
        }
        sort($result);
        return array_values(array_unique($result));
    }

    /**
     * Load per-format quality overrides. Stored as an associative array,
     * e.g. ['avif' => 70, 'webp' => 80]. Returns empty array when the
     * option is missing or malformed.
     *
     * @return array<string,int>
     */
    private function loadFormatQuality(): array
    {
        $stored = get_option(self::OPTION_FORMAT_QUALITY, []);
        if (!is_array($stored)) {
            return [];
        }
        $result = [];
        $allowed = ['avif', 'webp', 'jpeg', 'png'];
        foreach ($stored as $fmt => $q) {
            if (in_array($fmt, $allowed, true) && is_int($q) && $q >= 1 && $q <= 100) {
                $result[$fmt] = $q;
            }
        }
        return $result;
    }

    /**
     * Ф8/Ф11: Load size-based quality tiers. Stored as an associative
     * array [maxWidth => quality], where quality is either int (simple
     * form, emits q:) or array<string,int> (per-format form, emits fq:).
     * Example:
     *   [400 => 75, 800 => ['avif' => 65, 'webp' => 70, 'jpeg' => 78]]
     * Returns empty array when the option is missing or malformed.
     *
     * @return array<int, int|array<string,int>>
     */
    private function loadSizeQualityTiers(): array
    {
        $stored = get_option(self::OPTION_SIZE_QUALITY_TIERS, []);
        if (!is_array($stored)) {
            return [];
        }
        $result = [];
        foreach ($stored as $maxWidth => $quality) {
            if (!is_int($maxWidth) || $maxWidth <= 0) {
                continue;
            }
            if (is_int($quality) && $quality >= 1 && $quality <= 100) {
                $result[$maxWidth] = $quality;
            } elseif (is_array($quality) && !empty($quality)) {
                $cleanFmt = [];
                foreach ($quality as $fmt => $q) {
                    if (is_string($fmt) && $fmt !== '' && is_int($q) && $q >= 1 && $q <= 100) {
                        $cleanFmt[$fmt] = $q;
                    }
                }
                if (!empty($cleanFmt)) {
                    $result[$maxWidth] = $cleanFmt;
                }
            }
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /**
     * Load watermark configuration. Stored as an associative array or null.
     * Returns null when the option is missing, disabled, or malformed.
     */
    private function loadWatermark(): ?Watermark
    {
        $stored = get_option(self::OPTION_WATERMARK, null);
        if (!is_array($stored) || empty($stored['enabled'])) {
            return null;
        }

        try {
            return new Watermark(
                opacity: (float) ($stored['opacity'] ?? 1),
                position: (string) ($stored['position'] ?? Watermark::POS_CENTER),
                xOffset: (int) ($stored['x_offset'] ?? 0),
                yOffset: (int) ($stored['y_offset'] ?? 0),
                scale: (float) ($stored['scale'] ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }
}
