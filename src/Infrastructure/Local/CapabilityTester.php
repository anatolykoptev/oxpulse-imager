<?php
/**
 * Apache mod_rewrite / AllowOverride capability tester.
 *
 * Determines whether .htaccess rewrite rules can be trusted at runtime.
 * If mod_rewrite is unavailable or AllowOverride is off, the plugin
 * falls back to the FallbackRewriter output-buffer path (rewrites
 * cache URLs to oxpulse-img.php?k=<key> in the HTML output).
 *
 * #43 Phase 1: the stub allowOverrideEnabled() is replaced by a live
 * HTTP self-probe (LocalRewriteProbe). The probe writes a temporary
 * .htaccess with a test rewrite rule + fetches a probe URL. If the
 * rewrite fires, mod_rewrite + AllowOverride are both active. This is
 * the WebP-Express htaccess-capability-tester approach.
 *
 * Detection flow:
 *   1. isApache() false (nginx / LiteSpeed-LSAPI without apache in
 *      SERVER_SOFTWARE) → short-circuit false, NO HTTP round-trip.
 *   2. modRewriteLoaded() false (mod_php SAPI, module definitely absent)
 *      → false, NO probe. null (php-fpm, can't tell) → defer to probe.
 *   3. Read the cached option (tri-state 'yes'|'no'|'unknown'). On hit
 *      → return (cached === 'yes'). On miss → run the probe, store the
 *      result + timestamp, return (result === 'yes').
 *
 * 'unknown' (transport error, non-200, unexpected body) is treated as
 * NOT available → fallbackNeeded true (conservative — prefer fallback
 * so serving still works, mirroring the existing docblock philosophy).
 *
 * For unit testing, inject a stub LocalRewriteProbe and/or subclass and
 * override isApache() / modRewriteLoaded().
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

class CapabilityTester
{
    private ?LocalRewriteProbe $probe;

    /**
     * @param LocalRewriteProbe|null $probe Inject a stub for tests; null
     *   lazily constructs a real probe (used in production, not in tests).
     */
    public function __construct(?LocalRewriteProbe $probe = null)
    {
        $this->probe = $probe;
    }

    /**
     * Whether mod_rewrite + AllowOverride are available for the cache dir.
     *
     * Reads the cached probe result first (no HTTP round-trip on cache
     * hit). On cache miss, invokes the live probe and stores the result.
     *
     * @return bool
     */
    public function rewriteAvailable(): bool
    {
        // Fast static pre-filter: non-Apache → false, no probe.
        if (!$this->isApache()) {
            return false;
        }

        // mod_php without mod_rewrite → false, no probe.
        // null (php-fpm, can't tell) → defer to cache/probe.
        $modRewrite = $this->modRewriteLoaded();
        if ($modRewrite === false) {
            return false;
        }

        // Read the cached option first.
        $cached = $this->loadCachedCapability();
        if ($cached !== null) {
            return $cached === 'yes';
        }

        // Cache miss → probe + store.
        $result = $this->runProbe();
        return $result === 'yes';
    }

    /**
     * Whether the fallback (output-buffer URL rewrite) should be used.
     *
     * @return bool True when .htaccess rewrite is NOT available.
     */
    public function fallbackNeeded(): bool
    {
        return !$this->rewriteAvailable();
    }

    /**
     * Delete the cached probe result so the next rewriteAvailable()
     * call re-probes. Safe to call on non-Apache (no-op side effect).
     */
    public function invalidateCache(): void
    {
        delete_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY);
        delete_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT);
    }

    /**
     * Force a fresh probe (ignore the cache), store the result +
     * timestamp, and return the tri-state string.
     *
     * For the future "Re-test capability" admin button (Phase 5).
     * Does NOT wire any UI now.
     *
     * @return string 'yes' | 'no' | 'unknown'
     */
    public function recheck(): string
    {
        return $this->runProbe();
    }

    /**
     * Detect whether the server is Apache.
     *
     * Uses apache_get_version() when available (mod_php SAPI), and falls
     * back to inspecting $_SERVER['SERVER_SOFTWARE'] under CGI/FPM.
     * nginx + php-fpm → false; Apache + mod_php → true; Apache + php-fpm
     * → true (.htaccess is still processed by Apache in that config);
     * LiteSpeed → true (the probe is authoritative for its quirks).
     */
    protected function isApache(): bool
    {
        if (function_exists('apache_get_version')) {
            return true;
        }
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (is_string($server) && $server !== '') {
            return stripos($server, 'apache') !== false || stripos($server, 'litespeed') !== false;
        }
        return false;
    }

    /**
     * Detect whether mod_rewrite is loaded.
     *
     * Returns:
     *   - true  : apache_get_modules() exists and contains mod_rewrite.
     *   - false : apache_get_modules() exists and does NOT contain
     *             mod_rewrite (mod_php SAPI, module definitively absent).
     *   - null  : apache_get_modules() is undefined (php-fpm SAPI) —
     *             can't tell statically; the caller defers to the live
     *             probe, which definitively answers "does rewrite work".
     *
     * @return bool|null
     */
    protected function modRewriteLoaded(): ?bool
    {
        if (function_exists('apache_get_modules')) {
            $modules = @apache_get_modules();
            if (is_array($modules)) {
                return in_array('mod_rewrite', $modules, true);
            }
        }
        return null;
    }

    /**
     * Read the cached capability option. Returns null on cache miss
     * (option not set or invalid value).
     */
    private function loadCachedCapability(): ?string
    {
        $value = get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, null);
        if ($value === null) {
            return null;
        }
        if (in_array($value, ['yes', 'no', 'unknown'], true)) {
            return $value;
        }
        return null;
    }

    /**
     * Run the probe (lazily constructing a real one if none injected),
     * store the result + timestamp, and return the tri-state string.
     */
    private function runProbe(): string
    {
        $probe = $this->probe ?? $this->buildDefaultProbe();
        $result = $probe->probe();

        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, $result);
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, time());

        return $result;
    }

    /**
     * Lazily construct a real LocalRewriteProbe for production use.
     *
     * Uses the standard cache dir path + home_url() for the probe URL.
     * Phase 2 will wire a properly-constructed probe via DI; this
     * default exists so `new CapabilityTester()` works standalone.
     */
    private function buildDefaultProbe(): LocalRewriteProbe
    {
        $wpContent = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (ABSPATH . '/wp-content');
        $cacheDir = $wpContent . '/cache/oxpulse';
        $probeUrl = home_url('/wp-content/cache/oxpulse/.probe');

        return new LocalRewriteProbe($cacheDir, $probeUrl, new WpRemoteHttpRequester());
    }
}
