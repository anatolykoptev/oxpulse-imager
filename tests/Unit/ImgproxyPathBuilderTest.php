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
}
