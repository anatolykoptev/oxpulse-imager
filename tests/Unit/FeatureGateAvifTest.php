<?php
/**
 * Gate 1 — AVIF feature gate tests.
 *
 * Verifies the AVIF Pro gate in MissEndpointHandler:
 * - avifAllowed=true (Pro)  → AVIF negotiated + served (unchanged behavior).
 * - avifAllowed=false (free) → AVIF NEVER an eligible output format:
 *   auto negotiation resolves to WebP (or original), and a direct
 *   .avif request downgrades to WebP or original — never fatal, never avif.
 *
 * The free-fallback invariant: WebP is still negotiated + served normally.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\MissEndpointHandler;
use OXPulse\Imager\Infrastructure\Local\PathGuard;
use PHPUnit\Framework\TestCase;

class FeatureGateAvifTest extends TestCase
{
    private const KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SALT_HEX = 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5';
    private const SOURCE = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';

    private string $uploadsBase;
    private string $cacheDir;
    private string $uploadsBaseUrl;
    private LocalBackend $backend;
    private SigningConfig $signing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-gate-avif-' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-gate-avif-cache-' . uniqid();
        $this->uploadsBaseUrl = 'https://example.com/wp-content/uploads';
        mkdir($this->uploadsBase . '/2024/01', 0755, true);
        mkdir($this->cacheDir, 0755, true);
        file_put_contents($this->uploadsBase . '/2024/01/photo.jpg', str_repeat('jpeg-original-' . PHP_EOL, 50));
        $this->signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
        $this->backend = new LocalBackend($this->signing);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->uploadsBase);
        $this->rmrf($this->cacheDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function handler(bool $avifAllowed): MissEndpointHandler
    {
        return new MissEndpointHandler(
            backend: $this->backend,
            transformer: new GateAvifTransformer(true, true, 'avif-bytes', 'webp-bytes'),
            pathGuard: new PathGuard($this->uploadsBase, $this->uploadsBaseUrl),
            cacheDir: $this->cacheDir,
            uploadsBasedir: $this->uploadsBase,
            avifAllowed: $avifAllowed,
        );
    }

    private function validKey(): string
    {
        $req = new TransformRequest(
            sourceUrl: self::SOURCE,
            width: 800, height: 0, resize: 'fit', format: 'webp',
            quality: 80, context: 'content', dpr: 0, blur: 0,
            watermark: null, formatQuality: [], sourceMode: 'http',
        );
        $url = $this->backend->generate($req);
        $basename = basename(parse_url($url, PHP_URL_PATH));
        return substr($basename, 0, strrpos($basename, '.'));
    }

    // ─── Pro: AVIF active (unchanged behavior) ───────────────────────

    public function test_pro_auto_negotiates_avif_when_accepted(): void
    {
        $response = $this->handler(true)->handle($this->validKey(), 'auto', 'image/avif,image/webp,*/*;q=0.8');
        $this->assertSame('image/avif', $response->contentType);
        $this->assertSame('avif-bytes', $response->body);
    }

    public function test_pro_explicit_avif_served_when_accepted(): void
    {
        $response = $this->handler(true)->handle($this->validKey(), 'avif', 'image/avif,image/webp');
        $this->assertSame('image/avif', $response->contentType);
    }

    // ─── Free: AVIF never eligible ───────────────────────────────────

    public function test_free_auto_negotiates_webp_not_avif_even_when_avif_accepted(): void
    {
        $response = $this->handler(false)->handle($this->validKey(), 'auto', 'image/avif,image/webp,*/*;q=0.8');
        $this->assertNotSame('image/avif', $response->contentType, 'Free must never serve avif');
        $this->assertSame('image/webp', $response->contentType, 'Free must still negotiate WebP');
        $this->assertSame('webp-bytes', $response->body);
    }

    public function test_free_explicit_avif_request_downgrades_to_webp(): void
    {
        $response = $this->handler(false)->handle($this->validKey(), 'avif', 'image/avif,image/webp');
        $this->assertNotSame('image/avif', $response->contentType, 'A direct .avif request under free must never serve avif');
        $this->assertSame('image/webp', $response->contentType, 'Free .avif request must downgrade to WebP when accepted');
        $this->assertSame(200, $response->status, 'Free .avif request must never fatal (no 400/500)');
    }

    public function test_free_explicit_avif_request_serves_original_when_webp_not_accepted(): void
    {
        $response = $this->handler(false)->handle($this->validKey(), 'avif', 'image/jpeg,*/*;q=0.8');
        $this->assertNotSame('image/avif', $response->contentType);
        $this->assertSame(200, $response->status, 'Free .avif request must never fatal');
        $this->assertNull($response->body, 'Original serve uses filePath streaming (body null)');
    }

    public function test_free_auto_serves_original_when_neither_format_accepted(): void
    {
        $response = $this->handler(false)->handle($this->validKey(), 'auto', 'image/jpeg,*/*;q=0.8');
        $this->assertSame(200, $response->status);
        $this->assertNotSame('image/avif', $response->contentType);
    }
}

/**
 * Fake transformer with deterministic capability flags + per-format bytes.
 */
class GateAvifTransformer extends ImageTransformer
{
    public function __construct(
        private bool $avifCap,
        private bool $webpCap,
        private string $avifBytes,
        private string $webpBytes,
    ) {}

    public function supportsAvif(): bool { return $this->avifCap; }
    public function supportsWebp(): bool { return $this->webpCap; }

    public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        return $format === 'avif' ? $this->avifBytes : $this->webpBytes;
    }
}
