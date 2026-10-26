<?php
/**
 * Cached `mod_headers` capability verdict (#51).
 *
 * Persistent, non-autoloaded option written by CapabilityTester::recheck()
 * (write-time only — same triggers and the same OPTION_PROBE_VERSION-gated
 * re-probe as the rewrite probe) and read by the settings screen. Holds
 * only a DEFINITIVE 'yes' or 'no' together with the plugin version that
 * produced it and a timestamp; anything else reads as null (unknown).
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

final class HeadersCapabilityCache
{
    public const OPTION = 'oxpulse_imager_headers_capability';

    /**
     * @return string|null 'yes' | 'no' | null (never probed / garbage).
     */
    public function read(): ?string
    {
        $value = get_option(self::OPTION, null);
        if (!is_array($value)) {
            return null;
        }
        $result = $value['result'] ?? null;
        return $result === 'yes' || $result === 'no' ? $result : null;
    }

    /** Persist a definitive verdict; anything else is ignored. */
    public function write(string $result): void
    {
        if ($result !== 'yes' && $result !== 'no') {
            return;
        }
        update_option(self::OPTION, [
            'result'  => $result,
            'version' => defined('OXPULSE_IMAGER_VERSION') ? (string) OXPULSE_IMAGER_VERSION : '',
            'at'      => time(),
        ], false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
