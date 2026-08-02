<?php
/**
 * Srcset candidate-ladder thinning tests.
 *
 * Verifies that SrcsetRewriter thins the srcset candidate ladder by
 * relative width spacing so the emitted set is small and well-spaced
 * instead of one entry per registered intermediate size. This is the
 * falsification gate for the cache-surface regression introduced by
 * PR #124 (srcset restoration): 14 registered sizes → up to 10
 * candidates per image → ~14 GB derived surface against a 2 GB cache.
 *
 * The test drives the REAL production path: SrcsetRewriter::rewrite(),
 * the single chokepoint for both srcset paths (the direct
 * wp_calculate_image_srcset filter AND the AttachmentImageSrcsetRestorer
 * path, which re-runs wp_calculate_image_srcset and flows through the
 * same filter).
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

class SrcsetThinningTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    /**
     * Real observed widths from a live piter.now article page (10
     * candidates from 14 registered sizes). This is the ladder that
     * multiplied the derived-image surface ~10x against a fixed 2 GB
     * cache.
     */
    private const REAL_LADDER = [240, 300, 330, 420, 615, 768, 860, 900, 1536, 1920];

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the filter registry so the thin-step filter starts clean.
        $GLOBALS['__oxpulse_filters'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_filters']);
    }

    /**
     * Build a SrcsetRewriter with size-quality tiers configured so the
     * test can verify per-width tier quality survives thinning (#125
     * behaviour must be untouched).
     */
    private function createRewriter(): SrcsetRewriter
    {
        $urlRewriter = new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: [self::ALLOWED],
                defaultQuality: 80,
                sizeQualityTiers: [
                    400  => 75,   // widths <= 400 → q:75
                    800  => 70,   // widths <= 800 → q:70
                    1200 => 65,   // widths <= 1200 → q:65
                    2000 => 60,   // widths <= 2000 (largest tier) → q:60
                ],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );

        return new SrcsetRewriter($urlRewriter);
    }

    /**
     * Build the 10-candidate source array from the real observed ladder.
     */
    private function buildRealSources(): array
    {
        $sources = [];
        foreach (self::REAL_LADDER as $w) {
            $sources[] = [
                'url'        => sprintf('https://example.com/wp-content/uploads/photo-%d.jpg', $w),
                'descriptor' => 'w',
                'value'      => $w,
            ];
        }
        return $sources;
    }

    /**
     * Extract the width from a proxied candidate URL by looking for
     * the rs:fit:W:0 option in the imgproxy path.
     */
    private function extractWidthFromUrl(string $url): ?int
    {
        if (preg_match('#rs:fit:(\d+):0#', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Extract the quality from a proxied candidate URL by looking for
     * the q:N option in the imgproxy path.
     */
    private function extractQualityFromUrl(string $url): ?int
    {
        if (preg_match('#/q:(\d+)(/|$)#', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Falsification gate assertions (task requirements 1-4).
    // -------------------------------------------------------------------------

    /**
     * 1. A realistic 10-candidate input ladder is thinned to at most 5
     *    candidates.
     */
    public function test_real_ladder_thinned_to_at_most_5(): void
    {
        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        // sizeArray [800, 600] → srcWidth=800, not in the ladder, so
        // no src-forced candidate interferes with the spacing check.
        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertLessThanOrEqual(5, count($result), 'Thinned ladder must have at most 5 candidates');
        $this->assertGreaterThanOrEqual(2, count($result), 'Must never emit a single-candidate srcset');
    }

    /**
     * 2. The smallest (240) and largest (1920) survive.
     */
    public function test_smallest_and_largest_survive(): void
    {
        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $widths = [];
        foreach ($result as $candidate) {
            $w = $this->extractWidthFromUrl($candidate['url']);
            if ($w !== null) {
                $widths[] = $w;
            }
        }

        $this->assertContains(240, $widths, 'Smallest candidate (240) must survive');
        $this->assertContains(1920, $widths, 'Largest candidate (1920) must survive');
    }

    /**
     * 3. Every kept neighbouring pair is spaced by at least the
     *    configured step (1.4 by default). The smallest and largest are
     *    force-kept regardless of spacing, so only intermediate pairs
     *    are asserted.
     */
    public function test_kept_pairs_spaced_by_step(): void
    {
        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $widths = [];
        foreach ($result as $candidate) {
            $w = $this->extractWidthFromUrl($candidate['url']);
            if ($w !== null) {
                $widths[] = $w;
            }
        }
        sort($widths);

        $step = 1.4;
        for ($i = 1; $i < count($widths); $i++) {
            $prev = $widths[$i - 1];
            $curr = $widths[$i];
            // The smallest (i=0) and largest (i=last) are force-kept.
            // Intermediate pairs must satisfy the spacing rule.
            if ($i < count($widths) - 1) {
                $ratio = $curr / $prev;
                $this->assertGreaterThanOrEqual(
                    $step,
                    $ratio,
                    sprintf('Pair (%d, %d) ratio %.3f < step %.1f', $prev, $curr, $ratio, $step)
                );
            }
        }
    }

    /**
     * 4. Candidates that survive are still proxied and still carry the
     *    correct per-format quality for their width (the #125 tier
     *    behaviour must be untouched).
     */
    public function test_survivors_proxied_with_correct_tier_quality(): void
    {
        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $expectedQualityForWidth = [
            240  => 75,  // tier 400
            420  => 70,  // tier 800
            615  => 70,  // tier 800
            900  => 65,  // tier 1200
            1920 => 60,  // tier 2000 (largest tier fallback)
        ];

        foreach ($result as $candidate) {
            $url = $candidate['url'];

            // Every survivor must be proxied.
            $this->assertStringContainsString(
                'imgproxy.example.com',
                $url,
                "Survivor not proxied: $url"
            );

            $w = $this->extractWidthFromUrl($url);
            $this->assertNotNull($w, "Could not extract width from: $url");

            // The quality in the URL must match the tier for that width.
            if (isset($expectedQualityForWidth[$w])) {
                $q = $this->extractQualityFromUrl($url);
                $this->assertNotNull($q, "Could not extract quality from: $url");
                $this->assertSame(
                    $expectedQualityForWidth[$w],
                    $q,
                    sprintf('Width %d expected q:%d, got q:%d in %s', $w, $expectedQualityForWidth[$w], $q, $url)
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Edge cases.
    // -------------------------------------------------------------------------

    /**
     * The candidate matching the src width (sizeArray[0]) must survive
     * even if it would be dropped by spacing.
     */
    public function test_src_width_candidate_survives(): void
    {
        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        // sizeArray [330, 220] → srcWidth=330, which is in the ladder
        // but would be dropped by spacing (330 < 240*1.4=336).
        $result = $rewriter->rewrite($sources, [330, 220], 'src', [], 0);

        $widths = [];
        foreach ($result as $candidate) {
            $w = $this->extractWidthFromUrl($candidate['url']);
            if ($w !== null) {
                $widths[] = $w;
            }
        }

        $this->assertContains(330, $widths, 'Src-width candidate (330) must survive');
    }

    /**
     * A short ladder (3 candidates) that thinning would reduce below 2
     * must return the original ladder unchanged — never emit a
     * single-candidate srcset.
     */
    public function test_short_ladder_not_thinned_below_2(): void
    {
        $rewriter = $this->createRewriter();
        $sources = [
            ['url' => 'https://example.com/wp-content/uploads/photo-300.jpg', 'descriptor' => 'w', 'value' => 300],
            ['url' => 'https://example.com/wp-content/uploads/photo-330.jpg', 'descriptor' => 'w', 'value' => 330],
        ];

        $result = $rewriter->rewrite($sources, [320, 200], 'src', [], 0);

        // 2 candidates → thinning would keep smallest + largest = 2,
        // which is fine. But if the algorithm tried to thin to 1, it
        // must fall back to the original.
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    /**
     * The thin step is configurable via the oxpulse_srcset_thin_step
     * filter. A larger step produces fewer candidates.
     */
    public function test_step_configurable_via_filter(): void
    {
        add_filter('oxpulse_srcset_thin_step', static fn(): float => 2.0);

        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        // With step=2.0: 240, 615 (>=480), 1536 (>=1230), 1920 (largest)
        // → 4 candidates (1536 replaced by 1920 if too close, but
        // 1920 >= 1536*2=3072? No → replace → [240, 615, 1920] = 3).
        // Either way, <= 4 and >= 2.
        $this->assertGreaterThanOrEqual(2, count($result));
        $this->assertLessThanOrEqual(4, count($result));
    }

    /**
     * Setting step <= 1.0 disables thinning (returns original ladder).
     */
    public function test_step_le_1_disables_thinning(): void
    {
        add_filter('oxpulse_srcset_thin_step', static fn(): float => 1.0);

        $rewriter = $this->createRewriter();
        $sources  = $this->buildRealSources();

        $result = $rewriter->rewrite($sources, [800, 600], 'src', [], 0);

        $this->assertSame(count($sources), count($result), 'Step <= 1.0 must disable thinning');
    }

    /**
     * The src-matching candidate must survive even when the largest
     * candidate sits within one step of it.
     *
     * Regression: the largest-too-close branch popped the last kept
     * intermediate unconditionally. With the ladder 240/615/900/1100 and
     * src=900, 1100 < 900*1.4, so the branch fired and dropped 900 — the
     * one width the `src` attribute resolves to.
     */
    public function test_src_width_survives_when_largest_is_within_one_step(): void
    {
        $rewriter = $this->createRewriter();

        $sources = [];
        foreach ([240, 615, 900, 1100] as $w) {
            $sources[$w] = [
                'url'        => 'https://example.com/img-' . $w . '.webp',
                'descriptor' => 'w',
                'value'      => $w,
            ];
        }

        $result = $rewriter->rewrite($sources, [900, 600], 'https://example.com/img-900.webp', [], 0);

        $keptWidths = array_map(static fn(array $s): int => (int) $s['value'], array_values($result));

        $this->assertContains(900, $keptWidths, 'src-width candidate must never be thinned away');
        $this->assertContains(1100, $keptWidths, 'largest candidate must always survive');
        $this->assertContains(240, $keptWidths, 'smallest candidate must always survive');
    }
}
