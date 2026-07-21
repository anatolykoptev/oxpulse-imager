<?php
/**
 * ImgproxyPathBuilder tests.
 *
 * Verifies deterministic path construction from transform requests.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyPathBuilder;
use PHPUnit\Framework\TestCase;

class ImgproxyPathBuilderTest extends TestCase
{
    private ImgproxyPathBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ImgproxyPathBuilder();
    }

    public function test_builds_fit_resize_path_with_format(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            resize: 'fit',
            format: 'avif'
        );

        $path = $this->builder->build($request);

        $this->assertSame('/rs:fit:800:0/plain/https://example.com/image.jpg@avif', $path);
    }

    public function test_builds_fill_resize_path_with_quality(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 300,
            height: 400,
            resize: 'fill',
            format: 'webp',
            quality: 75
        );

        $path = $this->builder->build($request);

        $this->assertSame('/rs:fill:300:400/q:75/plain/https://example.com/image.jpg@webp', $path);
    }

    public function test_builds_quality_only_path(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 0,
            height: 0,
            quality: 60
        );

        $path = $this->builder->build($request);

        $this->assertSame('/q:60/plain/https://example.com/image.jpg', $path);
    }

    public function test_builds_auto_format_without_extension(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 600,
            resize: 'fit',
            format: 'auto'
        );

        $path = $this->builder->build($request);

        // 'auto' produces no @format suffix — imgproxy uses Accept
        // header negotiation (requires IMGPROXY_AUTO_AVIF/AUTO_WEBP).
        $this->assertSame('/rs:fit:800:600/plain/https://example.com/image.jpg', $path);
    }

    public function test_builds_no_resize_when_zero_dimensions(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 0,
            height: 0,
            format: 'avif'
        );

        $path = $this->builder->build($request);

        $this->assertSame('/plain/https://example.com/image.jpg@avif', $path);
    }

    public function test_path_starts_with_slash(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 100,
            height: 100
        );

        $path = $this->builder->build($request);

        $this->assertStringStartsWith('/', $path);
    }

    public function test_filename_option_added_when_provided(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            format: 'avif'
        );

        $path = $this->builder->build($request, 'photo.avif');

        // fn: option is base64url-encoded with :1 flag indicating encoding.
        $this->assertStringContainsString('/fn:', $path);
        $this->assertStringContainsString(':1/plain/', $path);
    }

    public function test_filename_option_base64url_encoded(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 0,
            height: 0,
            format: 'avif'
        );

        $path = $this->builder->build($request, 'photo.avif');

        // Verify the filename is base64url-encoded: 'photo.avif' → 'cGhvdG8uYXZpZg'
        $expected = base64_encode('photo.avif');
        $expected = rtrim(strtr($expected, '+/', '-_'), '=');
        $this->assertStringContainsString('fn:' . $expected . ':1', $path);
    }

    public function test_filename_option_omitted_when_null(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            format: 'avif'
        );

        $path = $this->builder->build($request, null);

        $this->assertStringNotContainsString('fn:', $path);
    }

    public function test_filename_option_omitted_when_empty(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            format: 'avif'
        );

        $path = $this->builder->build($request, '');

        $this->assertStringNotContainsString('fn:', $path);
    }

    public function test_filename_option_with_no_other_options(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 0,
            height: 0,
            format: 'auto'
        );

        $path = $this->builder->build($request, 'photo.jpg');

        // When only filename is present, it should be the sole option.
        $this->assertStringStartsWith('/fn:', $path);
        $this->assertStringContainsString(':1/plain/', $path);
    }

    public function test_filename_option_combined_with_resize_and_quality(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 600,
            quality: 80,
            format: 'avif'
        );

        $path = $this->builder->build($request, 'photo.avif');

        // Options order: rs:fit:800:600/q:80/fn:...:1
        $this->assertStringContainsString('rs:fit:800:600/q:80/fn:', $path);
    }

    public function test_invalid_width_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 20000,
            height: 100
        );
    }

    public function test_invalid_quality_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 600,
            quality: 150
        );
    }

    public function test_full_url_generation_is_deterministic(): void
    {
        $config = new \OXPulse\Imager\Domain\Config\SigningConfig(
            key: str_repeat('a', 32),
            salt: str_repeat('b', 32)
        );
        $signer = new \OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner($config);
        $generator = new \OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyUrlGenerator(
            $this->builder,
            $signer,
            'https://imgproxy.example.com'
        );

        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            format: 'avif'
        );

        $url1 = $generator->generate($request);
        $url2 = $generator->generate($request);

        $this->assertSame($url1, $url2);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $url1);
    }

    // --- Ф1: local:// source mode tests ---

    public function test_local_mode_emits_local_segment_with_base64url_path(): void
    {
        $fsPath = '/var/www/wordpress/wp-content/uploads/2024/01/photo.jpg';
        $request = new TransformRequest(
            sourceUrl: $fsPath,
            width: 800,
            height: 0,
            resize: 'fit',
            format: 'avif',
            sourceMode: 'local',
        );

        $path = $this->builder->build($request);

        // Expected: /rs:fit:800:0/local://{base64url($fsPath)}@avif
        $expectedB64 = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $this->assertSame('/rs:fit:800:0/local://' . $expectedB64 . '@avif', $path);
    }

    public function test_local_mode_without_resize_emits_local_segment_only(): void
    {
        $fsPath = '/var/www/wordpress/wp-content/uploads/photo.jpg';
        $request = new TransformRequest(
            sourceUrl: $fsPath,
            width: 0,
            height: 0,
            format: 'auto',
            sourceMode: 'local',
        );

        $path = $this->builder->build($request);

        $expectedB64 = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $this->assertSame('/local://' . $expectedB64, $path);
    }

    public function test_local_mode_with_filename_option(): void
    {
        $fsPath = '/var/www/wordpress/wp-content/uploads/photo.jpg';
        $request = new TransformRequest(
            sourceUrl: $fsPath,
            width: 300,
            height: 200,
            resize: 'fill',
            format: 'webp',
            sourceMode: 'local',
        );

        $filename = 'photo.webp';
        $path = $this->builder->build($request, $filename);

        $expectedB64 = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $expectedFn = rtrim(strtr(base64_encode($filename), '+/', '-_'), '=');
        $this->assertSame('/rs:fill:300:200/fn:' . $expectedFn . ':1/local://' . $expectedB64 . '@webp', $path);
    }

    public function test_local_mode_path_is_base64url_no_padding(): void
    {
        // Path that produces base64 with padding when standard-encoded.
        $fsPath = '/var/www/wp-content/uploads/2024/01/abc';
        $request = new TransformRequest(
            sourceUrl: $fsPath,
            width: 100,
            height: 100,
            sourceMode: 'local',
        );

        $path = $this->builder->build($request);

        // Extract the base64 part and verify no padding.
        $this->assertStringContainsString('local://', $path);
        $this->assertStringNotContainsString('=', $path);
    }

    public function test_http_mode_is_default_when_source_mode_omitted(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/image.jpg',
            width: 800,
            height: 0,
            format: 'avif',
            // sourceMode omitted — defaults to 'http'
        );

        $path = $this->builder->build($request);

        $this->assertStringContainsString('plain/', $path);
        $this->assertStringNotContainsString('local://', $path);
    }

    public function test_local_mode_with_cyrillic_path_base64url_encoded(): void
    {
        // Cyrillic filesystem path — rawurldecode was applied upstream by
        // SourcePolicy, so the path here is the raw UTF-8 bytes.
        $fsPath = '/var/www/wp-content/uploads/2024/01/Фото.jpg';
        $request = new TransformRequest(
            sourceUrl: $fsPath,
            width: 800,
            height: 0,
            sourceMode: 'local',
        );

        $path = $this->builder->build($request);

        $expectedB64 = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $this->assertStringContainsString('local://' . $expectedB64, $path);
    }
}
