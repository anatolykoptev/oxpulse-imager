<?php
/**
 * camelCase (React admin) <-> snake_case (SettingsValidator input) key map.
 *
 * Single source of truth for the key-name translation between the REST
 * API shape (camelCase, what the React SPA reads/writes) and the flat
 * input array shape that SettingsValidator::validate() expects
 * (snake_case, matching the form POST keys the classic admin used).
 *
 * Every option the admin SPA can read or write MUST be listed in
 * CAMEL_TO_SNAKE. A key that is NOT listed here is invisible to the SPA
 * and is left untouched by the REST controller's merge.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

final class OptionsMapper
{
    /**
     * camelCase key => snake_case key.
     *
     * The snake_case values MUST match the keys SettingsValidator::validate()
     * reads from its $input array — this is the invariant the REST write
     * path silently depends on.
     *
     * @var array<string,string>
     */
    private const CAMEL_TO_SNAKE = [
        // Connection
        'enabled'             => 'enabled',
        'endpoint'            => 'endpoint',
        'key'                 => 'key',
        'salt'                => 'salt',
        'allowedSources'      => 'allowed_sources',
        // Format
        'outputFormat'        => 'output_format',
        'defaultQuality'      => 'default_quality',
        'formatQuality'       => 'format_quality',
        // Enhancements (Phase 5.1)
        'lqipEnabled'         => 'lqip_enabled',
        'lqipBlur'            => 'lqip_blur',
        'dprEnabled'          => 'dpr_enabled',
        'dprVariants'         => 'dpr_variants',
        'watermark'           => 'watermark',
        // <picture> element wrapping (Phase 1) — Pro-gated (PICTURE_ELEMENT).
        // The SPA toggle + this mapper entry + the validator producer + the
        // save branch land as a cohesive unit with the license UI.
        'pictureEnabled'      => 'picture_enabled',
        // LocalBackend cache size cap (MB) — Pro-gated (CACHE_MANAGEMENT).
        // Under free, loadCacheMaxMb() returns the default (512) regardless
        // of the stored value; the SPA locks the field under free.
        'cacheMaxMb'          => 'cache_max_mb',
        // Diagnostics
        'diagnosticLevel'     => 'diagnostic_level',
        'devHttpOverride'     => 'dev_http_override',
        'removeOnUninstall'   => 'remove_on_uninstall',
        // Onboarding (Phase 5.5)
        'onboarded'           => 'onboarded',
    ];

    /**
     * Expose the camel->snake map read-only, for callers that need to
     * reason about the mapping's key SET rather than translate a payload.
     *
     * @return array<string,string>
     */
    public static function getCamelToSnakeMap(): array
    {
        return self::CAMEL_TO_SNAKE;
    }

    /**
     * DB config objects (DeliveryConfig + SigningConfig + loose options)
     * -> REST/SPA (camelCase). Used for the GET response.
     *
     * @param array<string,mixed> $snakeOptions Flat snake_case options
     *   (as assembled by OptionsRestController from the repository).
     * @return array<string,mixed> camelCase-keyed options for the React admin.
     */
    public static function toCamel(array $snakeOptions): array
    {
        $camel = [];
        foreach (self::CAMEL_TO_SNAKE as $camelKey => $snakeKey) {
            if (array_key_exists($snakeKey, $snakeOptions)) {
                $camel[$camelKey] = $snakeOptions[$snakeKey];
            }
        }
        return $camel;
    }

    /**
     * REST/SPA (camelCase) -> flat snake_case input for SettingsValidator.
     *
     * @param array<string,mixed> $camelInput Raw REST POST body (camelCase).
     * @return array<string,mixed> snake_case-keyed flat array for validate().
     */
    public static function toSnake(array $camelInput): array
    {
        $snake = [];
        foreach (self::CAMEL_TO_SNAKE as $camelKey => $snakeKey) {
            if (array_key_exists($camelKey, $camelInput)) {
                $snake[$snakeKey] = $camelInput[$camelKey];
            }
        }
        return $snake;
    }
}
