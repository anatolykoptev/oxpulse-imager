<?php
/**
 * Test-only DeliveryBackend stub for PictureElementWrapper tests.
 *
 * Produces deterministic per-format URLs so tests can assert the exact
 * <picture>/<source> structure without relying on the real imgproxy
 * signing chain. Configurable to reject specific formats (throw) so the
 * "only webp rewrites" / "neither rewrites" fallback-guard paths can be
 * exercised — UrlRewriter's fail-safe try/catch preserves the original
 * URL when generate() throws.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit\Stubs;

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Domain\Transform\TransformRequest;

final class PictureTestBackend implements DeliveryBackend
{
    /**
     * @param array<string,bool> $allowedFormats Map of format => allowed.
     *        Missing formats default to allowed=true. When false,
     *        generate() throws for that format (simulates a host without
     *        that encoder).
     */
    public function __construct(
        private array $allowedFormats = []
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function generate(TransformRequest $request, ?string $filename = null): string
    {
        $fmt = $request->format;
        $allowed = $this->allowedFormats[$fmt] ?? true;
        if (!$allowed) {
            throw new \RuntimeException('format not supported: ' . $fmt);
        }

        // Deterministic URL: endpoint/<format>/<source-basename>@<format>
        $base = basename(parse_url($request->sourceUrl, PHP_URL_PATH) ?: '');
        return 'https://imgproxy.test/' . $fmt . '/' . $base . '@' . $fmt;
    }
}
