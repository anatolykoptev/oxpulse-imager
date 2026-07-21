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
     * The live probe is deferred to a real WordPress environment (it
     * needs an HTTP request to the site). In the stub/test environment
     * this returns false (conservative — prefer the fallback).
     *
     * @return bool
     */
    public function rewriteAvailable(): bool
    {
        // In a real WP environment, this would:
        // 1. Write a temp .htaccess with: RewriteRule ^oxpulse-probe$
        //    oxpulse-img.php?probe=1 [L]
        // 2. Fetch https://site/wp-content/cache/oxpulse/oxpulse-probe
        // 3. If the response is from the endpoint (probe=1), rewrite works.
        // 4. Clean up the temp .htaccess.
        //
        // Conservative default: false (use fallback) until a live probe
        // confirms capability. The plugin wires the live probe in a
        // future integration phase.
        return false;
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
}
