<?php
/**
 * Content-hashed asset filename resolver.
 *
 * Resolves a logical bundle filename (e.g. 'admin-app.js') to the
 * content-hashed filename currently on disk (e.g.
 * 'admin-app.a1b2c3d4.js') by reading assets/manifest.json — written
 * at build time by build/write-manifest.mjs.
 *
 * Ported from UTM Linker (includes/AssetManifest.php). Never fatal:
 * a missing/unreadable/malformed manifest, or a logical name the
 * manifest doesn't know about, all fall back to the logical name
 * itself — keeps a broken manifest from ever taking the site down.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

final class AssetManifest
{
    /** @var array<string,string>|null */
    private static ?array $manifest = null;

    /**
     * Resolve a logical bundle filename to its content-hashed filename.
     *
     * @param string $logical Logical filename, e.g. 'admin-app.js'.
     * @return string The hashed filename, or $logical unchanged if the
     *                manifest is missing/unreadable/malformed or has no
     *                entry for it.
     */
    public static function resolve(string $logical): string
    {
        $manifest = self::getManifest();
        return $manifest[$logical] ?? $logical;
    }

    /**
     * Load + cache assets/manifest.json for the lifetime of the request.
     *
     * @return array<string,string>
     */
    private static function getManifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/assets/manifest.json';
        if (!is_readable($path)) {
            self::$manifest = [];
            return self::$manifest;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$manifest = [];
            return self::$manifest;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$manifest = [];
            return self::$manifest;
        }

        // Validate: values must be plain strings.
        $clean = [];
        foreach ($decoded as $logical => $hashed) {
            if (is_string($logical) && is_string($hashed)) {
                $clean[$logical] = $hashed;
            }
        }

        self::$manifest = $clean;
        return self::$manifest;
    }
}
