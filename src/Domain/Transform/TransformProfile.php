<?php
/**
 * Transform profile.
 *
 * Maps a TransformRequest to imgproxy processing options. Deterministic:
 * the same request always produces the same option string.
 *
 * @package OXPulse\Imager\Domain\Transform
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Transform;

final class TransformProfile
{
    /**
     * Build deterministic imgproxy processing options from a transform request.
     *
     * @param TransformRequest $request
     * @return string imgproxy options string (e.g. "rs:fit:800:0/fq:80")
     */
    public function buildOptions(TransformRequest $request): string
    {
        $parts = [];

        // Resize option.
        if ($request->width > 0 || $request->height > 0) {
            $resizeType = $request->resize !== '' ? $request->resize : 'fit';
            $parts[] = sprintf('rs:%s:%d:%d', $resizeType, $request->width, $request->height);
        }

        // Quality option.
        if ($request->quality > 0) {
            $parts[] = 'q:' . $request->quality;
        }

        // Format is specified as @extension in the path builder, not as
        // a processing option. This avoids redundant format specification.

        return implode('/', $parts);
    }
}
