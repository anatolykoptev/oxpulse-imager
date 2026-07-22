<?php
/**
 * Immutable signing configuration.
 *
 * Holds decoded binary key and salt for HMAC-SHA256 signing.
 * Created from validated hex strings via SigningConfig::fromHex().
 *
 * @package OXPulse\Imager\Domain\Config
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Config;

final readonly class SigningConfig
{
    public function __construct(
        public string $key,
        public string $salt
    ) {
        // FIX #35: defense-in-depth at the crypto boundary. An empty
        // HMAC key signs everything trivially → silent no-security.
        // fromHex() already rejects empty (isValidHex requires non-
        // empty), but the constructor is the lowest-level seam — reject
        // empty key/salt here too so direct construction can't bypass
        // the guard. The normal path (loadSigningConfig) returns null
        // when secrets are missing, so this only hardens direct
        // construction (e.g. the baked endpoint constants path).
        if ($key === '') {
            throw new \InvalidArgumentException('Signing key must not be empty.');
        }
        if ($salt === '') {
            throw new \InvalidArgumentException('Signing salt must not be empty.');
        }
    }

    /**
     * Create from hex-encoded key and salt.
     *
     * @param string $keyHex Even-length hexadecimal key.
     * @param string $saltHex Even-length hexadecimal salt.
     * @return self
     * @throws \InvalidArgumentException If key or salt are invalid hex or too short.
     */
    public static function fromHex(string $keyHex, string $saltHex): self
    {
        if (!self::isValidHex($keyHex)) {
            throw new \InvalidArgumentException('Signing key must be a non-empty even-length hexadecimal string.');
        }
        if (!self::isValidHex($saltHex)) {
            throw new \InvalidArgumentException('Signing salt must be a non-empty even-length hexadecimal string.');
        }

        $key = @hex2bin($keyHex);
        $salt = @hex2bin($saltHex);

        if ($key === false || $salt === false) {
            throw new \InvalidArgumentException('Signing key or salt could not be decoded from hex.');
        }

        // Minimum length enforcement is deferred to the settings validation
        // layer (SettingsValidator in Phase 2) so that the pure domain
        // config can be used with documented test vectors that use short
        // keys for demonstration purposes.

        return new self($key, $salt);
    }

    private static function isValidHex(string $value): bool
    {
        return $value !== '' && strlen($value) % 2 === 0 && ctype_xdigit($value);
    }
}
