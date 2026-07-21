<?php
/**
 * Pre-warm result value object.
 *
 * The result of warming a single (source URL × width) combination:
 * the signed imgproxy URL that was requested, the HTTP status, and
 * whether it was warmed, skipped (already cached / not authorized),
 * or failed.
 *
 * @package OXPulse\Imager\Domain\Prewarm
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Prewarm;

final readonly class PrewarmItemResult
{
    public function __construct(
        public string $sourceUrl,
        public int $width,
        public string $status,
        public string $imgproxyUrl,
        public int $httpStatus = 0,
        public string $message = ''
    ) {}

    public static function warmed(string $sourceUrl, int $width, string $imgproxyUrl, int $httpStatus): self
    {
        return new self($sourceUrl, $width, 'warmed', $imgproxyUrl, $httpStatus, 'OK');
    }

    public static function skipped(string $sourceUrl, int $width, string $imgproxyUrl, string $reason): self
    {
        return new self($sourceUrl, $width, 'skipped', $imgproxyUrl, 0, $reason);
    }

    public static function failed(string $sourceUrl, int $width, string $imgproxyUrl, string $message, int $httpStatus = 0): self
    {
        return new self($sourceUrl, $width, 'failed', $imgproxyUrl, $httpStatus, $message);
    }

    /**
     * Serialize to the array shape the REST API + SPA expect.
     *
     * @return array{sourceUrl: string, width: int, status: string, imgproxyUrl: string, httpStatus: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'sourceUrl'    => $this->sourceUrl,
            'width'        => $this->width,
            'status'       => $this->status,
            'imgproxyUrl'  => $this->imgproxyUrl,
            'httpStatus'   => $this->httpStatus,
            'message'      => $this->message,
        ];
    }
}
