<?php
/**
 * Live HTTP self-probe for Apache `mod_headers` (`Header set`) capability.
 *
 * HtaccessGenerator emits `Header set Vary "Accept"` inside
 * `<IfModule mod_headers.c>`; when mod_headers is not loaded (or
 * AllowOverride forbids FileInfo) the block is skipped silently and
 * mixed-client caches can serve the wrong format. This probe finds out:
 * it writes a test .htaccess with `Header set X-Oxpulse-Probe "1"` plus a
 * static file into <cacheDir>/.probe-headers/, GETs the file and checks
 * the response header.
 *
 *   header present   => 'yes'
 *   200, header absent => 'no'  (.htaccess ignored or mod_headers missing)
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
    private const PROBE_SUBDIR = '.probe-headers';
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
        $probeDir = rtrim($this->cacheDir, '/') . '/' . self::PROBE_SUBDIR;

        if (!$this->writeProbeFiles($probeDir)) {
            LocalRewriteProbe::purgeProbeDir($this->cacheDir, $probeDir);
            return 'unknown';
        }

        $resp = $this->requester->get(rtrim($this->probeBaseUrl, '/') . '/' . self::PROBE_SUBDIR . '/probe.txt');

        LocalRewriteProbe::purgeProbeDir($this->cacheDir, $probeDir);

        if (($resp['error'] ?? null) !== null || ($resp['status'] ?? 0) !== 200) {
            return 'unknown';
        }
        $headers = $resp['headers'] ?? null;
        if (!is_array($headers)) {
            return 'unknown';
        }
        return ($headers[self::PROBE_HEADER] ?? '') === '1' ? 'yes' : 'no';
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
            && @file_put_contents($probeDir . '/probe.txt', 'ok') !== false;
    }
}
