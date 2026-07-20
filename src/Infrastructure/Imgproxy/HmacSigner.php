<?php
/**
 * HMAC-SHA256 imgproxy signer.
 *
 * Implements the official imgproxy v4 URL signing algorithm:
 * signature = URL-safe Base64(HMAC-SHA256(salt + path, key))
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://docs.imgproxy.net/latest/usage/signing_url
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Signing\Signer;

final class HmacSigner implements Signer
{
    private SigningConfig $config;

    public function __construct(SigningConfig $config)
    {
        $this->config = $config;
    }

    public function sign(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Path must start with "/".');
        }

        $digest = hash_hmac('sha256', $this->config->salt . $path, $this->config->key, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }
}
