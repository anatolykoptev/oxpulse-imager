<?php
/**
 * Live HTTP self-probe for Apache `mod_headers` (`Header set`) capability.
 *
 * HtaccessGenerator emits `Header set Vary "Accept"` inside
 * `<IfModule mod_headers.c>`; when mod_headers is not loaded (or
 * AllowOverride forbids FileInfo) the block is skipped silently and
 * mixed-client caches can serve the wrong format. This probe finds out:
 * it writes a test .htaccess with `Header set X-Oxpulse-Probe "1"` plus a
 * static file into a unique <cacheDir>/.probe-headers-<id>/ dir, GETs the file and checks
 * the response header.
 *
 *   header present   => 'yes'
 *   200 + probe body, header absent => 'no' (.htaccess ignored or
 *                       mod_headers missing). A 200 with any other body
 *                       (soft-200 login/CDN/HTML page) => 'unknown'.
 *   anything else    => 'unknown' (transport error, non-200, fs failure).
 *                       The caller never persists 'unknown'.
 *
 * Same shape as LocalRewriteProbe (separate directory, because that probe
 * deletes its own); cleanup goes through the same containment-guarded
 * purge.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

class HeaderSetProbe
{
    public const PROBE_HEADER = 'x-oxpulse-probe';
    private const PROBE_SUBDIR_PREFIX = '.probe-headers-';
    private const PROBE_BODY = 'ok';
    private const HTACCESS_CONTENT = "<IfModule mod_headers.c>\nHeader set X-Oxpulse-Probe \"1\"\n</IfModule>";

    public function __construct(
        private string $cacheDir,
        private string $probeBaseUrl,
        private HttpRequester $requester,
    ) {}

    /**
     * @return string 'yes' | 'no' | 'unknown'
     */
    public function probe(): string
    {
        // Unique per probe: concurrent probes cannot delete each other's
        // files (which would read as a false 'no').
        $subdir = self::PROBE_SUBDIR_PREFIX . bin2hex(random_bytes(6));
        $probeDir = rtrim($this->cacheDir, '/') . '/' . $subdir;

        try {
            if (!$this->writeProbeFiles($probeDir)) {
                return 'unknown';
            }
            // The query string is a cache-buster for page caches / CDNs.
            $url = rtrim($this->probeBaseUrl, '/') . '/' . $subdir . '/probe.txt?oxp=' . time();
            $resp = $this->requester->get($url);
        } finally {
            LocalRewriteProbe::purgeProbeDir($this->cacheDir, $probeDir);
        }

        if (($resp['error'] ?? null) !== null || ($resp['status'] ?? 0) !== 200) {
            return 'unknown';
        }
        $headers = $resp['headers'] ?? null;
        if (!is_array($headers)) {
            return 'unknown';
        }
        if (($headers[self::PROBE_HEADER] ?? '') === '1') {
            return 'yes';
        }
        // Header absent: only a 200 that really is our probe file proves
        // .htaccess was read and the header was not applied.
        return ($resp['body'] ?? '') === self::PROBE_BODY ? 'no' : 'unknown';
    }

    private function writeProbeFiles(string $probeDir): bool
    {
        if (!is_dir($probeDir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- probe dir creation; same rationale as LocalRewriteProbe.
            if (!@mkdir($probeDir, 0755, true)) {
                return false;
            }
        }
        return @file_put_contents($probeDir . '/.htaccess', self::HTACCESS_CONTENT) !== false
            && @file_put_contents($probeDir . '/probe.txt', self::PROBE_BODY) !== false;
    }
}
