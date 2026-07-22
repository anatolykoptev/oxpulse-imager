<?php
/**
 * Live HTTP self-probe for .htaccess rewrite capability.
 *
 * Writes a test .htaccess + 0.txt/1.txt into <cacheDir>/.probe/, issues
 * an HTTP GET to <probeBaseUrl>/0.txt, and interprets the body:
 *
 *   body === "1" ⇒ 'yes'  — the rewrite fired (mod_rewrite loaded +
 *                             AllowOverride not None + IfModule allowed).
 *   body === "0" ⇒ 'no'   — .htaccess was read but the rewrite did not
 *                             fire (mod_rewrite missing, RewriteEngine
 *                             disallowed, or AllowOverride None).
 *   anything else ⇒ 'unknown' — transport error, non-200, or unexpected
 *                             body. The caller treats 'unknown' as
 *                             unavailable (conservative — prefer fallback
 *                             so serving still works).
 *
 * Modelled on WebP Express's htaccess-capability-tester RewriteTester
 * (~80 lines, no new dependency). The .probe/ dir is cleaned up after
 * probing (best-effort). Does not follow symlinks out of the cache dir.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

class LocalRewriteProbe
{
    private const PROBE_SUBDIR = '.probe';
    private const HTACCESS_CONTENT = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^0\\.txt$ 1.txt [L]\n</IfModule>";

    public function __construct(
        private string $cacheDir,
        private string $probeBaseUrl,
        private HttpRequester $requester,
    ) {}

    /**
     * Run the probe and return the tri-state result.
     *
     * @return string 'yes' | 'no' | 'unknown'
     */
    public function probe(): string
    {
        $probeDir = rtrim($this->cacheDir, '/') . '/' . self::PROBE_SUBDIR;

        if (!$this->writeProbeFiles($probeDir)) {
            return 'unknown';
        }

        $url = rtrim($this->probeBaseUrl, '/') . '/0.txt';
        $resp = $this->requester->get($url);

        $this->cleanup($probeDir);

        $status = $resp['status'] ?? 0;
        $body = $resp['body'] ?? '';
        $error = $resp['error'] ?? null;

        if ($error !== null || $status !== 200) {
            return 'unknown';
        }
        if ($body === '1') {
            return 'yes';
        }
        if ($body === '0') {
            return 'no';
        }
        return 'unknown';
    }

    /**
     * Write the test .htaccess + 0.txt + 1.txt into the probe dir.
     *
     * @return bool True on success, false on filesystem failure.
     */
    private function writeProbeFiles(string $probeDir): bool
    {
        if (!is_dir($probeDir)) {
            if (!@mkdir($probeDir, 0755, true)) {
                return false;
            }
        }
        if (@file_put_contents($probeDir . '/.htaccess', self::HTACCESS_CONTENT) === false) {
            return false;
        }
        if (@file_put_contents($probeDir . '/0.txt', '0') === false) {
            return false;
        }
        if (@file_put_contents($probeDir . '/1.txt', '1') === false) {
            return false;
        }
        return true;
    }

    /**
     * Best-effort recursive removal of the .probe/ dir.
     *
     * Does not follow symlinks: a symlinked entry is unlinked, not
     * recursed into, so cleanup stays within the cache dir.
     */
    private function cleanup(string $probeDir): void
    {
        if (!is_dir($probeDir)) {
            return;
        }
        $entries = @scandir($probeDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $probeDir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->cleanup($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($probeDir);
    }
}
