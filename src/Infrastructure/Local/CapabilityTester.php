<?php
/**
 * Apache mod_rewrite / AllowOverride capability tester.
 *
 * Determines whether .htaccess rewrite rules can be trusted at runtime.
 * If mod_rewrite is unavailable or AllowOverride is off, LocalBackend
 * emits ?k= endpoint URLs directly (no .htaccess rewrite needed).
 *
 * #43 Phase 1 review (BLOCKER): the front-end-reachable path —
 * rewriteAvailable() / fallbackNeeded() — performs ZERO blocking I/O.
 * It is called on EVERY front-end request from
 * ServiceRegistrar::registerDeliveryAdapters() when LocalBackend is
 * active, so it must never issue a wp_remote_get (up to 3s) or write
 * to the filesystem. The decision hierarchy is now read-only + cheap:
 *
 *   1. Read the cached capability option (via the repository accessor).
 *      A definitive 'yes' → true; 'no' → false.
 *   2. No definitive cached value (option unset OR 'unknown') → fall
 *      back to a CHEAP STATIC HEURISTIC (no probe):
 *        isApache() && modRewriteLoaded() === true  → true
 *        otherwise                                  → false
 *      Conservative — an unverified host defaults to the working
 *      `?k=` fallback, not to clean URLs that might 404.
 *
 * The live probe (LocalRewriteProbe) runs ONLY at WRITE-TIME via
 * recheck(), which is wired to fire in admin/activation context
 * (plugin activation + settings-save when LocalBackend becomes active
 * + once-per-version re-probe on plugin update). recheck() persists
 * ONLY a definitive 'yes' or 'no'; a probe 'unknown' (transport error,
 * non-200, loopback HTTP blocked) does NOT overwrite a prior definitive
 * value and does NOT persist a fallback-forcing 'unknown' (#43 MAJOR).
 *
 * Static detection helpers:
 *   - isApache() false (nginx / LiteSpeed-LSAPI without apache in
 *     SERVER_SOFTWARE) → heuristic false.
 *   - modRewriteLoaded() false (mod_php SAPI, module definitely absent)
 *     → heuristic false. null (php-fpm, can't tell) → heuristic false
 *     (conservative — only a definitive true from mod_php is trusted
 *     without a probe; FPM defers to the cached probe result, and on
 *     cache miss stays conservative until a write-time recheck succeeds).
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
    private OptionSettingsRepository $repository;

    /**
     * @param LocalRewriteProbe|null $probe Inject a stub for tests; null
     *   lazily constructs a real probe (used in production, not in tests).
     *   The probe is ONLY touched by recheck() — never by the read path.
     * @param OptionSettingsRepository|null $repository Inject for tests;
     *   null lazily constructs the canonical repository so all capability
     *   option reads/writes route through it (no direct get_option /
     *   update_option / delete_option in this class).
     */
    public function __construct(
        ?LocalRewriteProbe $probe = null,
        ?OptionSettingsRepository $repository = null,
    ) {
        $this->probe = $probe;
        $this->repository = $repository ?? new OptionSettingsRepository();
    }

    /**
     * Whether mod_rewrite + AllowOverride are available for the cache dir.
     *
     * FRONT-END-SAFE: performs ZERO blocking I/O. No wp_remote_get, no
     * filesystem writes, no probe invocation. Reads only the cached
     * capability option (cheap) and falls back to a static heuristic.
     *
     * Decision hierarchy:
     *   1. Cached definitive 'yes' → true; 'no' → false.
     *   2. No definitive cached value (unset OR 'unknown') →
     *      isApache() && modRewriteLoaded() === true (conservative).
     *
     * @return bool
     */
    public function rewriteAvailable(): bool
    {
        // 1. Read the cached definitive value (read-only, cheap).
        $cached = $this->repository->loadRewriteCapabilityOrNull();
        if ($cached === 'yes') {
            return true;
        }
        if ($cached === 'no') {
            return false;
        }

        // 2. No definitive cached value (unset OR 'unknown') → cheap
        //    static heuristic. NO probe, NO I/O. Conservative: only a
        //    definitive modRewriteLoaded() === true is trusted; FPM
        //    (null) and non-Apache default to fallback.
        return $this->isApache() && $this->modRewriteLoaded() === true;
    }

    /**
     * Whether the fallback (output-buffer URL rewrite) should be used.
     *
     * FRONT-END-SAFE: delegates to rewriteAvailable() (zero I/O).
     *
     * @return bool True when .htaccess rewrite is NOT available.
     */
    public function fallbackNeeded(): bool
    {
        return !$this->rewriteAvailable();
    }

    /**
     * Delete the cached probe result so the next rewriteAvailable()
     * call falls back to the static heuristic (and so a later recheck
     * can store a fresh definitive value). Safe to call on non-Apache.
     */
    public function invalidateCache(): void
    {
        $this->repository->invalidateRewriteCapability();
    }

    /**
     * Force a fresh probe (ignore the cache), store the result +
     * timestamp, and return the tri-state string.
     *
     * WRITE-TIME ONLY: this is the sole entry point that invokes the
     * live probe. Wired to fire in admin/activation context (plugin
     * activation, settings-save when LocalBackend becomes active,
     * once-per-version re-probe on plugin update) — never from the
     * front-end read path.
     *
     * #43 MAJOR: persists ONLY a definitive 'yes' or 'no'. A probe
     * 'unknown' (transport error, non-200, loopback HTTP blocked —
     * common on real WP hosts) does NOT overwrite a prior definitive
     * value and does NOT write a permanent 'unknown' that would force
     * fallback forever. Net effect: a mod_php+mod_rewrite host whose
     * loopback is blocked still gets clean URLs via the static
     * heuristic, and a later successful re-probe can upgrade to a
     * definitive 'yes'.
     *
     * @return string 'yes' | 'no' | 'unknown'
     */
    public function recheck(): string
    {
        $probe = $this->probe ?? $this->buildDefaultProbe();
        $result = $probe->probe();

        // Persist only definitive results. 'unknown' never overwrites
        // a prior 'yes'/'no' and never persists a fallback-forcing value.
        if ($result === 'yes' || $result === 'no') {
            $this->repository->saveRewriteCapability($result);
        }

        return $result;
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
     *             can't tell statically. The static heuristic treats
     *             null as NOT trusted (conservative fallback); a
     *             definitive answer requires a write-time recheck probe.
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
