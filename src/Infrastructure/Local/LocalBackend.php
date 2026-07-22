<?php
/**
 * Local delivery backend (Phase 6).
 *
 * Produces an ABSOLUTE, STABLE, SIGNED cache-file URL for on-disk local
 * delivery on standard/shared hosting (no imgproxy daemon required):
 *
 *     home_url('/wp-content/cache/oxpulse/' . $key . '.' . $fmt)
 *
 * where $key is a signed, self-contained token carrying the transform
 * payload, and $fmt is the output format (webp for the MVP).
 *
 * KEY FORMAT
 * ----------
 * The key is `base64url(payload_json) . '.' . base64url(hmac)` where:
 *
 *   payload_json = a canonical JSON object with sorted keys:
 *     {"f":"<format>","h":<height>,"q":<quality>,"r":"<resize>",
 *      "s":"<sourceUrl>","w":<width>}
 *   hmac = HmacSigner->sign('/' . base64url(payload_json))
 *
 * The leading '/' in the signed string satisfies HmacSigner's imgproxy-
 * protocol guard (the signer requires a leading-slash path); it is part
 * of the signed content and does not weaken the signature. The HMAC is
 * recomputed over the same string at verification time, so any tampering
 * with the payload (or the signature) is detected.
 *
 * The key is:
 * - STABLE: a pure function of (source, transform, format) — the same
 *   input always produces the same key/URL (SEO/schema-safe).
 * - SIGNED: the HMAC blocks arbitrary-transform abuse — an attacker
 *   cannot craft a key that requests a different transform or source.
 * - SELF-CONTAINING: the payload is embedded in the key, so the Dispatch 2
 *   miss-endpoint can recover (source, transform, format) from the key
 *   alone after verifying the signature — no database lookup needed.
 *
 * The '.' separator is safe because the base64url alphabet
 * ([A-Za-z0-9_-]) does not include '.'.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner;

final class LocalBackend implements DeliveryBackend
{
    private const CACHE_PATH = '/wp-content/cache/oxpulse/';
    private const ENDPOINT_PATH = '/wp-content/oxpulse-img.php';

    private HmacSigner $signer;
    private ?CapabilityTester $capability;

    /**
     * Memoized fallback decision — resolved ONCE per instance, not per
     * generate() call. A page has many images; the capability tester
     * must be consulted once. Null = not yet resolved.
     */
    private ?bool $fallback = null;

    /**
     * @param SigningConfig $signing
     * @param CapabilityTester|null $capability #43 Phase 2: inject for
     *   fallback URL emission. Null (default) = no fallback decision
     *   available → clean cache URLs (used by the generated
     *   oxpulse-img.php endpoint, which only verifies keys + serves
     *   files, never generates URLs — it has no WP option access).
     */
    public function __construct(SigningConfig $signing, ?CapabilityTester $capability = null)
    {
        $this->signer = new HmacSigner($signing);
        $this->capability = $capability;
    }

    public function available(): bool
    {
        // LocalBackend needs only a signing key (checked by UrlRewriter's
        // signing guard), not an endpoint. It is always available once
        // constructed.
        return true;
    }

    /**
     * #43 Phase 2: whether this instance carries a CapabilityTester.
     * Used by tests to verify the factory injects the tester. The
     * generated oxpulse-img.php endpoint constructs LocalBackend
     * WITHOUT a tester (it only verifies keys, never generates URLs).
     */
    public function hasCapabilityTester(): bool
    {
        return $this->capability !== null;
    }

    public function generate(TransformRequest $request, ?string $filename = null): string
    {
        $fmt = $this->resolveFormat($request->format);
        $key = $this->buildKey($request, $fmt);
        $hash = self::sourceHash($request->sourceUrl);

        // #43 Phase 2: emit ?k= URLs when the host can't serve clean
        // .webp URLs (nginx, AllowOverride None, mod_rewrite missing).
        // The decision is resolved ONCE per instance (memoized) — not
        // per generate() call. An opt-out filter lets an operator who
        // pasted the nginx snippet force clean URLs even when the
        // capability probe said fallback is needed.
        if ($this->shouldFallback()) {
            return home_url(self::ENDPOINT_PATH . '?k=' . $key);
        }

        return home_url(self::CACHE_PATH . $hash . '/' . $key . '.' . $fmt);
    }

    /**
     * Resolve the fallback decision ONCE per instance (memoized).
     *
     * The decision is: apply_filters('oxpulse_fallback_rewrite_enabled',
     * $capability->fallbackNeeded()). An operator who pasted the nginx
     * snippet can add_filter('oxpulse_fallback_rewrite_enabled',
     * '__return_false') to force clean URLs. When no CapabilityTester
     * is injected (the generated endpoint case), defaults to false
     * (clean URLs — the endpoint never generates URLs anyway).
     */
    private function shouldFallback(): bool
    {
        if ($this->fallback !== null) {
            return $this->fallback;
        }

        $capabilityFallback = $this->capability !== null && $this->capability->fallbackNeeded();
        $this->fallback = (bool) apply_filters(
            'oxpulse_fallback_rewrite_enabled',
            $capabilityFallback,
        );
        return $this->fallback;
    }

    /**
     * Compute the stable source-hash path segment for a source URL.
     *
     * The cache is laid out as cache/oxpulse/<sourceHash>/<key>.<fmt> so
     * that per-attachment invalidation = deleting the <sourceHash>/
     * directory (all transform variants of one source share the dir).
     *
     * The hash is a truncated SHA-1 of the source URL — a path segment,
     * NOT part of the signed payload. The key still fully determines
     * the transform; the sourceHash only groups cache files by source
     * for O(1) invalidation without an index.
     *
     * @param string $sourceUrl The source image URL.
     * @return string 16-character hex string.
     */
    public static function sourceHash(string $sourceUrl): string
    {
        return substr(sha1($sourceUrl), 0, 16);
    }

    /**
     * Verify a cache key and recover its payload.
     *
     * @param string $key The key portion of the cache filename (the
     *        basename without the format extension).
     * @return array<string,mixed>|null The decoded payload
     *         {source, width, height, resize, format, quality}, or null
     *         if the key is malformed or the signature does not verify.
     */
    public function verify(string $key): ?array
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$payloadB64, $sig] = $parts;

        // Recompute the signature over the same string that was signed at
        // generation time: '/' + base64url(payload_json).
        $expected = $this->signer->sign('/' . $payloadB64);

        // Constant-time comparison to prevent timing attacks on the HMAC.
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        return $this->normalizePayload($payload);
    }

    /**
     * Build the signed cache key for a transform request.
     *
     * @param TransformRequest $request
     * @param string $fmt Resolved output format (webp, avif, ...).
     * @return string The key: `base64url(payload).base64url(hmac)`.
     */
    private function buildKey(TransformRequest $request, string $fmt): string
    {
        $payload = $this->canonicalPayload($request, $fmt);
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $sig = $this->signer->sign('/' . $payloadB64);

        return $payloadB64 . '.' . $sig;
    }

    /**
     * Build the canonical payload array (sorted keys for determinism).
     *
     * @return array<string,mixed>
     */
    private function canonicalPayload(TransformRequest $request, string $fmt): array
    {
        return [
            'f' => $fmt,
            'h' => $request->height,
            'q' => $request->quality,
            'r' => $request->resize,
            's' => $request->sourceUrl,
            'w' => $request->width,
        ];
    }

    /**
     * Resolve the output format for the URL extension and payload.
     *
     * MVP: 'auto' / '' -> 'webp' (Accept negotiation is the Dispatch 2
     * endpoint's concern). Explicit formats pass through.
     */
    private function resolveFormat(string $format): string
    {
        if ($format === '' || $format === 'auto') {
            return 'webp';
        }
        return $format;
    }

    /**
     * Normalize a decoded payload into the typed return shape.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalizePayload(array $payload): ?array
    {
        $required = ['f', 'h', 'q', 'r', 's', 'w'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                return null;
            }
        }

        $source = $payload['s'];
        $resize = $payload['r'];
        $format = $payload['f'];
        if (!is_string($source) || !is_string($resize) || !is_string($format)) {
            return null;
        }

        $width = $payload['w'];
        $height = $payload['h'];
        $quality = $payload['q'];
        if (!is_int($width) || !is_int($height) || !is_int($quality)) {
            return null;
        }

        return [
            'source' => $source,
            'width' => $width,
            'height' => $height,
            'resize' => $resize,
            'format' => $format,
            'quality' => $quality,
        ];
    }
}
