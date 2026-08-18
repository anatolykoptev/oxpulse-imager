<?php
/**
 * SrcsetRewriter tests.
 *
 * Verifies srcset array rewriting: width-based descriptors, 2x
 * descriptors, and preservation of non-allowed URLs.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use PHPUnit\Framework\TestCase;

class SrcsetRewriterTest extends TestCase
{
    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_empty_sources_returned_unchanged(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $this->assertSame([], $rewriter->rewrite([], [800, 600], 'src', [], 0));
    }

    public function test_rewrites_width_descriptor_sources(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo-300.jpg', 'descriptor' => 'w', 'value' => 300],
            ['url' => 'https://example.com/wp-content/uploads/photo-600.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]['url']);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[1]['url']);
        // Width descriptor should produce a resize option.
        $this->assertStringContainsString('rs:fit:300:0', $result[0]['url']);
        $this->assertStringContainsString('rs:fit:600:0', $result[1]['url']);
    }

    public function test_preserves_non_allowed_urls(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://evil.com/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('https://evil.com/photo.jpg', $result[0]['url']);
    }

    public function test_2x_descriptor_does_not_produce_resize(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'x', 'value' => 2],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[0]['url']);
        $this->assertStringNotContainsString('rs:', $result[0]['url']);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter(enabled: false));
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $result[0]['url']);
    }

    public function test_preserves_descriptor_and_value_fields(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame('w', $result[0]['descriptor']);
        $this->assertSame(300, $result[0]['value']);
    }

    public function test_skips_sources_missing_url_field(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            ['descriptor' => 'w', 'value' => 300], // no url
            ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        // First source should be unchanged (no url), second should be rewritten.
        $this->assertSame(['descriptor' => 'w', 'value' => 300], $result[0]);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result[1]['url']);
    }

    /**
     * Candidates for a known slot: core passes the rendered size as
     * $sizeArray. With the default density cap of 2.0, a 330px slot
     * keeps widths <= 660 plus the single smallest candidate above
     * the bound (upscale safety); the rest are dead weight no
     * viewport/DPR combination can select.
     */
    private function gridSources(): array
    {
        $sources = [];
        foreach ([420, 615, 768, 860, 900, 1536, 1920] as $w) {
            $sources[$w] = [
                'url' => "https://example.com/wp-content/uploads/photo-{$w}.jpg",
                'descriptor' => 'w',
                'value' => $w,
            ];
        }
        return $sources;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__oxpulse_filters'] = [];
        parent::tearDown();
    }

    public function test_trims_candidates_above_default_density_cap(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());

        $result = $rewriter->rewrite($this->gridSources(), [330, 220], 'src', [], 0);

        $this->assertSame([420, 615, 768], array_keys($result));
    }

    public function test_keeps_smallest_candidate_above_bound_for_upscale_safety(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = $this->gridSources();
        unset($sources[768]); // gap: next above 660 is 860

        $result = $rewriter->rewrite($sources, [330, 220], 'src', [], 0);

        $this->assertSame([420, 615, 860], array_keys($result));
    }

    public function test_no_trimming_when_size_array_unknown(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());

        $zero = $rewriter->rewrite($this->gridSources(), [0, 0], 'src', [], 0);
        $empty = $rewriter->rewrite($this->gridSources(), [], 'src', [], 0);

        $this->assertCount(7, $zero);
        $this->assertCount(7, $empty);
    }

    public function test_density_cap_filter_raises_the_bound(): void
    {
        add_filter('oxpulse_imager_srcset_density_cap', static fn() => 3.0);
        $rewriter = new SrcsetRewriter($this->createRewriter());

        $result = $rewriter->rewrite($this->gridSources(), [330, 220], 'src', [], 0);

        // bound 990: 420..900 kept, 1536 is the upscale-safety candidate.
        $this->assertSame([420, 615, 768, 860, 900, 1536], array_keys($result));
    }

    public function test_density_cap_zero_disables_trimming(): void
    {
        add_filter('oxpulse_imager_srcset_density_cap', static fn() => 0.0);
        $rewriter = new SrcsetRewriter($this->createRewriter());

        $result = $rewriter->rewrite($this->gridSources(), [330, 220], 'src', [], 0);

        $this->assertCount(7, $result);
    }

    public function test_x_descriptor_sources_are_never_trimmed(): void
    {
        $rewriter = new SrcsetRewriter($this->createRewriter());
        $sources = [
            1 => ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'descriptor' => 'x', 'value' => 1],
            2 => ['url' => 'https://example.com/wp-content/uploads/photo-2x.jpg', 'descriptor' => 'x', 'value' => 2],
        ];

        $result = $rewriter->rewrite($sources, [330, 220], 'src', [], 0);

        $this->assertCount(2, $result);
    }
}
