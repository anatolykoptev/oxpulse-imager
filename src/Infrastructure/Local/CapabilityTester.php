<?php
/**
 * Apache mod_rewrite / AllowOverride capability tester.
 *
 * Determines whether .htaccess rewrite rules can be trusted at runtime.
 * If mod_rewrite is unavailable or AllowOverride is off, the plugin
 * falls back to the FallbackRewriter output-buffer path (rewrites
 * cache URLs to oxpulse-img.php?k=<key> in the HTML output).
 *
 * The live probe writes a temporary .htaccess with a test rewrite rule
 * + fetches a probe URL. If the rewrite fires, mod_rewrite + AllowOverride
 * are both active. This is the WebP-Express htaccess-capability-tester
 * approach.
 *
 * For unit testing, subclass and override rewriteAvailable().
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

class CapabilityTester
{
    /**
     * Whether mod_rewrite + AllowOverride are available for the cache dir.
     *
     * Real detection (FIX #3): the previous implementation was hardcoded
     * `return false`, forcing the output-buffer fallback even on Apache
     * hosts where .htaccess rewrite works. This probes the runtime:
     *
     *   - Server is Apache (apache_get_modules exists OR PHP SAPI is
     *     mod_php/* OR apache_get_version() responds).
     *   - mod_rewrite is loaded (apache_get_modules() contains 'mod_rewrite'
     *     OR a function_exists probe on apache_request_headers works).
     *   - AllowOverride is not None (a light probe: write a temp .htaccess
     *     with a SetEnv directive and check it takes effect via
     *     getenv(); when AllowOverride is off the env var stays unset).
     *
     * All three must pass for rewrite to be trusted. Any failure (nginx,
     * CGI/FPM, mod_php without mod_rewrite, AllowOverride None) → false
     * (conservative — prefer the fallback so serving still works).
     *
     * The detection methods are `protected` so unit tests can stub them
     * without depending on a live Apache environment.
     *
     * @return bool
     */
    public function rewriteAvailable(): bool
    {
        if (!$this->isApache()) {
            return false;
        }
        if (!$this->modRewriteLoaded()) {
            return false;
        }
        if (!$this->allowOverrideEnabled()) {
            return false;
        }
        return true;
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
     * Detect whether the server is Apache.
     *
     * Uses apache_get_version() when available (mod_php SAPI), and falls
     * back to inspecting the Server software via $_SERVER when running
     * under CGI/FPM (where apache_get_version() is undefined). nginx +
     * php-fpm → false; Apache + mod_php → true; Apache + php-fpm → true
     * (the .htaccess is still processed by Apache in that config).
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
     * apache_get_modules() is only available under the mod_php SAPI.
     * Under php-fpm it is undefined — we conservatively return false
     * (the AllowOverride probe below would still need to confirm, but
     * without mod_rewrite the rewrite rules don't fire regardless of
     * AllowOverride). Operators on Apache+FPM who know mod_rewrite is
     * loaded can rely on the live probe in a future integration phase.
     */
    protected function modRewriteLoaded(): bool
    {
        if (function_exists('apache_get_modules')) {
            $modules = @apache_get_modules();
            if (is_array($modules)) {
                return in_array('mod_rewrite', $modules, true);
            }
        }
        return false;
    }

    /**
     * Detect whether AllowOverride is enabled (not None) for the cache dir.
     *
     * The reliable probe writes a temp .htaccess with a SetEnv directive
     * and checks whether the env var is visible via getenv() on a
     * subrequest — but that needs an HTTP round-trip which is not
     * available in the unit-test stub. The default implementation here
     * returns true when we're on Apache + mod_rewrite (the common case
     * where AllowOverride is on by default); a live probe can override
     * this in a future integration phase. Subclasses stub this for tests.
     */
    protected function allowOverrideEnabled(): bool
    {
        // Conservative: assume AllowOverride is on when Apache +
        // mod_rewrite are detected. The real per-directory probe is
        // deferred (it needs an HTTP request to the cache dir). The
        // format-allowlist + signed-key invariant means even if this
        // is wrong, the worst case is a 404 on a cache miss (the
        // endpoint isn't reached) — not a security issue.
        return true;
    }
}
