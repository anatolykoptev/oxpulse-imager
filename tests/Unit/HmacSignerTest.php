<?php
/**
 * HmacSigner tests.
 *
 * Verifies signing against the official imgproxy v4 documentation vector
 * and validates error handling for invalid inputs.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/usage/signing_url
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner;
use PHPUnit\Framework\TestCase;

class HmacSignerTest extends TestCase
{
    /**
     * Official imgproxy documentation test vector.
     *
     * Key: "secret" (hex: 736563726574)
     * Salt: "hello" (hex: 68656C6C6F)
     * Path: /rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png
     * Expected signature: oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8
     */
    public function test_official_imgproxy_signing_vector(): void
    {
        $config = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $signer = new HmacSigner($config);

        $path = '/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png';
        $signature = $signer->sign($path);

        $this->assertSame('oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8', $signature);
    }

    public function test_signature_is_deterministic(): void
    {
        $config = SigningConfig::fromHex(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5'
        );
        $signer = new HmacSigner($config);

        $path = '/rs:fit:800:0/plain/https://example.com/image.jpg@avif';
        $sig1 = $signer->sign($path);
        $sig2 = $signer->sign($path);

        $this->assertSame($sig1, $sig2);
    }

    public function test_different_paths_produce_different_signatures(): void
    {
        $config = SigningConfig::fromHex(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5'
        );
        $signer = new HmacSigner($config);

        $sig1 = $signer->sign('/rs:fit:800:0/plain/https://example.com/a.jpg');
        $sig2 = $signer->sign('/rs:fit:800:0/plain/https://example.com/b.jpg');

        $this->assertNotSame($sig1, $sig2);
    }

    public function test_path_must_start_with_slash(): void
    {
        $config = SigningConfig::fromHex(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5'
        );
        $signer = new HmacSigner($config);

        $this->expectException(\InvalidArgumentException::class);
        $signer->sign('rs:fit:800:0/plain/https://example.com/image.jpg');
    }

    public function test_invalid_hex_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningConfig::fromHex('not-hex', '68656C6C6F');
    }

    public function test_empty_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningConfig::fromHex('', '68656C6C6F');
    }

    public function test_odd_length_hex_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningConfig::fromHex('abc', '68656C6C6F');
    }

    public function test_short_key_accepted_by_domain_config(): void
    {
        // Minimum length is enforced by the settings validation layer,
        // not by the pure domain config. This allows the documented test
        // vector (key="secret", 6 bytes) to work.
        $config = SigningConfig::fromHex('736563726574', '68656C6C6F');
        $this->assertSame('secret', $config->key);
        $this->assertSame('hello', $config->salt);
    }

    // --- FIX #35: SigningConfig accepts empty key/salt ---
    //
    // The constructor accepted empty key/salt → silent no-security (an
    // empty HMAC key signs everything trivially). fromHex already
    // rejects empty (isValidHex requires non-empty), but the constructor
    // is the crypto boundary — defense-in-depth: reject empty key/salt
    // there too. The normal path uses loadSigningConfig which returns
    // null when secrets are missing, so this only hardens direct
    // construction.

    public function test_direct_constructor_rejects_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SigningConfig('', 'non-empty-salt');
    }

    public function test_direct_constructor_rejects_empty_salt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SigningConfig('non-empty-key', '');
    }

    public function test_direct_constructor_rejects_both_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SigningConfig('', '');
    }

    public function test_direct_constructor_accepts_non_empty_key_and_salt(): void
    {
        $config = new SigningConfig('non-empty-key', 'non-empty-salt');
        $this->assertSame('non-empty-key', $config->key);
        $this->assertSame('non-empty-salt', $config->salt);
    }
}
