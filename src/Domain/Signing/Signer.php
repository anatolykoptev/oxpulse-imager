<?php
/**
 * Signer interface.
 *
 * @package OXPulse\Imager\Domain\Signing
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Signing;

interface Signer
{
    /**
     * Sign an imgproxy path.
     *
     * @param string $path Path starting with '/'.
     * @return string URL-safe Base64-encoded signature without padding.
     */
    public function sign(string $path): string;
}
