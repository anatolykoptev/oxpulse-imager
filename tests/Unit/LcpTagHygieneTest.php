<?php
/**
 * LcpTagHygiene tests (#133, #48, #49, #50).
 *
 * The fixture is real piter.now (Foxiz) homepage markup. Counts are taken
 * with substr_count / DOMDocument, never with the pass's own regexes.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Integration\WordPress\Delivery\LcpTagHygiene;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LcpTagHygieneTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/piter-home-trimmed.html';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_filters'], $GLOBALS['__oxpulse_actions']);
        parent::tearDown();
    }

    private function pass(bool $enabled = true): LcpTagHygiene
    {
        return new LcpTagHygiene(
            new DeliveryConfig(true, 'https://imgproxy.example.com', [], lcpHygieneEnabled: $enabled),
            static fn(): bool => true
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    /** @return list<\DOMElement> */
    private function images(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $out = [];
        foreach ($dom->getElementsByTagName('img') as $img) {
            $out[] = $img;
        }
        return $out;
    }

    private function page(string $body, string $head = ''): string
    {
        return "<!DOCTYPE html><html><head>{$head}</head><body>{$body}</body></html>";
    }

    private function big(string $extra = '', string $name = 'a'): string
    {
        return '<img ' . $extra . ' width="600" height="400" src="https://x.test/' . $name . '.jpg">';
    }

    // ─── real-markup measurement ─────────────────────────────────────

    public function test_fixture_before_counts_are_the_real_piter_numbers(): void
    {
        $html = $this->fixture();
        $this->assertCount(64, $this->images($html));
        $this->assertSame(98, substr_count($html, 'fetchpriority="high"'));
        $this->assertSame(61, substr_count($html, 'loading="lazy"'));
        $this->assertSame(0, substr_count($html, 'sizes="auto'));
    }

    public function test_fixture_ends_with_exactly_one_high_priority_image(): void
    {
        $out = $this->pass()->rewrite($this->fixture());

        $high = 0;
        foreach ($this->images($out) as $img) {
            $high += $img->getAttribute('fetchpriority') === 'high' ? 1 : 0;
        }
        $this->assertSame(1, $high, 'exactly one <img> may keep fetchpriority=high');
        // +1: the preload <link> carries fetchpriority=high by design.
        $this->assertSame(2, substr_count($out, 'fetchpriority="high"'), 'second fetchpriority="high" survived');
    }

    public function test_fixture_candidate_is_first_visible_big_image_not_logo_or_hidden_menu_copy(): void
    {
        $out = $this->pass()->rewrite($this->fixture());

        foreach ($this->images($out) as $img) {
            if ($img->getAttribute('fetchpriority') !== 'high') {
                continue;
            }
            $this->assertSame('615', $img->getAttribute('width'));
            $this->assertStringContainsString('featured-img', $img->getAttribute('class'));
            $this->assertSame('eager', $img->getAttribute('loading'), 'LCP image left lazy');
        }
    }

    public function test_fixture_lazy_images_with_sizes_gain_auto_exactly_like_core(): void
    {
        $out = $this->pass()->rewrite($this->fixture());

        $expected = 0;
        $actual = 0;
        foreach ($this->images($this->fixture()) as $i => $before) {
            $lazy = $before->getAttribute('loading') === 'lazy';
            $eligible = $lazy && $before->hasAttribute('sizes') && $before->hasAttribute('srcset')
                && $before->getAttribute('width') !== '';
            $after = $this->images($out)[$i];
            if ($after->getAttribute('fetchpriority') === 'high') {
                continue; // the LCP candidate is eager, handled elsewhere
            }
            if ($eligible) {
                $expected++;
                $this->assertSame('auto, ' . $before->getAttribute('sizes'), $after->getAttribute('sizes'));
            } else {
                $this->assertSame($before->getAttribute('sizes'), $after->getAttribute('sizes'));
            }
            $actual += str_starts_with($after->getAttribute('sizes'), 'auto, ') ? 1 : 0;
        }
        $this->assertGreaterThan(40, $expected);
        $this->assertSame($expected, $actual);
    }

    public function test_fixture_emits_one_preload_before_head_end_matching_the_candidate(): void
    {
        $out = $this->pass()->rewrite($this->fixture());

        $this->assertSame(1, substr_count($out, 'rel="preload" as="image"'));
        $this->assertLessThan(strpos($out, '</head>'), strpos($out, 'rel="preload" as="image"'));
        foreach ($this->images($out) as $img) {
            if ($img->getAttribute('fetchpriority') === 'high') {
                $this->assertStringContainsString('href="' . $img->getAttribute('src') . '"', $out);
                $this->assertStringContainsString(
                    'imagesrcset="' . htmlspecialchars($img->getAttribute('srcset'), ENT_QUOTES) . '"',
                    $out
                );
                $this->assertStringContainsString('imagesizes="' . $img->getAttribute('sizes') . '"', $out);
            }
        }
        $this->assertStringNotContainsString('imagesizes="auto', $out);
    }

    public function test_fixture_never_adds_lazy_and_pass_is_idempotent(): void
    {
        $once = $this->pass()->rewrite($this->fixture());
        $this->assertLessThanOrEqual(61, substr_count($once, 'loading="lazy"'));
        $this->assertSame($once, $this->pass()->rewrite($once));
    }

    // ─── gating ──────────────────────────────────────────────────────

    public function test_disabled_pass_returns_input_byte_for_byte(): void
    {
        $html = $this->fixture();
        $this->assertSame($html, $this->pass(false)->rewrite($html), 'pass applied while disabled');
    }

    public function test_filter_can_disable_an_enabled_pass(): void
    {
        add_filter('oxpulse_lcp_hygiene_enabled', static fn(): bool => false);
        $html = $this->fixture();
        $this->assertSame($html, $this->pass(true)->rewrite($html));
    }

    public function test_page_marker_opts_out(): void
    {
        $html = $this->page('<!-- no-oxpulse -->' . $this->big('fetchpriority="high"') . $this->big('fetchpriority="high"', 'b'));
        $this->assertSame($html, $this->pass()->rewrite($html));
    }

    public function test_register_starts_no_buffer_when_disabled_and_runs_outer_to_buffer_rewriter(): void
    {
        $this->pass(false)->register();
        $entry = $GLOBALS['__oxpulse_actions'][0];
        $this->assertSame('template_redirect', $entry['hook']);
        $this->assertLessThan(5, $entry['priority'], 'must be outer to BufferRewriter (template_redirect 5)');

        $level = ob_get_level();
        ($entry['callback'])();
        $this->assertSame($level, ob_get_level(), 'output buffer started while the pass is disabled');
    }

    public function test_register_starts_a_buffer_when_enabled(): void
    {
        $this->pass(true)->register();
        $level = ob_get_level();
        ($GLOBALS['__oxpulse_actions'][0]['callback'])();
        $this->assertSame($level + 1, ob_get_level());
        ob_end_clean();
    }

    // ─── heuristics and edge cases ───────────────────────────────────

    public function test_only_the_candidate_keeps_high_and_duplicate_attributes_are_stripped(): void
    {
        $html = $this->page(
            $this->big('fetchpriority="high" decoding="async" fetchpriority="high"', 'a')
            . $this->big('fetchpriority="high" fetchpriority=\'high\'', 'b')
            . $this->big('fetchpriority="low"', 'c')
        );
        $out = $this->pass()->rewrite($html);

        $this->assertSame(1, substr_count($out, 'fetchpriority="high"') - 1, 'preload link accounts for the extra one');
        $this->assertSame(1, substr_count($out, 'fetchpriority="low"'), 'low must be left alone');
        $this->assertSame(0, substr_count($out, "fetchpriority='high'"));
    }

    public function test_lazy_candidate_becomes_eager_with_single_loading_attr(): void
    {
        $html = $this->page($this->big('loading="lazy" fetchpriority="high" loading="lazy"'));
        $out = $this->pass()->rewrite($html);

        $this->assertSame(0, substr_count($out, 'loading="lazy"'), 'LCP image left lazy');
        $this->assertSame(1, substr_count($out, 'loading="eager"'));
    }

    public function test_candidate_without_loading_attr_gets_none_added(): void
    {
        $out = $this->pass()->rewrite($this->page($this->big()));
        $this->assertStringNotContainsString('loading=', $out);
        $this->assertStringContainsString('fetchpriority="high"', $out);
    }

    public function test_candidate_loses_leading_auto_in_sizes(): void
    {
        $html = $this->page($this->big('loading="lazy" srcset="https://x.test/a.jpg 600w" sizes="auto, 600px"'));
        $out = $this->pass()->rewrite($html);
        $this->assertStringContainsString('sizes="600px"', $out);
        $this->assertStringContainsString('imagesizes="600px"', $out);
    }

    public function test_small_images_and_logos_are_never_candidates(): void
    {
        $html = $this->page(
            '<img class="logo-default" fetchpriority="high" width="900" height="300" src="https://x.test/l.png">'
            . '<img fetchpriority="high" width="100" height="100" src="https://x.test/s.png">'
        );
        $out = $this->pass()->rewrite($html);
        $this->assertSame(0, substr_count($out, 'fetchpriority="high"'));
        $this->assertSame(0, substr_count($out, 'rel="preload"'));
    }

    public function test_images_in_hidden_containers_noscript_scripts_and_comments_are_not_candidates(): void
    {
        $body = '<nav>' . $this->big('', 'nav') . '</nav>'
            . '<div class="x mobile-collapse"><div>' . $this->big('', 'menu') . '</div></div>'
            . '<div style="display: none">' . $this->big('', 'hid') . '</div>'
            . '<div hidden>' . $this->big('', 'h2') . '</div>'
            . '<noscript>' . $this->big('', 'ns') . '</noscript>'
            . '<script>var s = \'' . $this->big('', 'js') . '\';</script>'
            . '<!-- ' . $this->big('', 'cm') . ' -->'
            . $this->big('', 'real');
        $out = $this->pass()->rewrite($this->page($body));

        $this->assertStringContainsString('<img fetchpriority="high"  width="600" height="400" src="https://x.test/real.jpg">', $out);
        $this->assertSame(1, substr_count($out, '<img fetchpriority="high"'));
        $this->assertStringContainsString('href="https://x.test/real.jpg"', $out);
    }

    public function test_candidate_inside_picture_gets_no_preload(): void
    {
        $out = $this->pass()->rewrite($this->page('<picture><source srcset="a.avif" type="image/avif">' . $this->big() . '</picture>'));
        $this->assertStringContainsString('fetchpriority="high"', $out);
        $this->assertStringNotContainsString('rel="preload"', $out);
    }

    public function test_existing_image_preload_is_not_duplicated(): void
    {
        $head = '<link rel="preload" as="image" href="https://x.test/a.jpg">';
        $out = $this->pass()->rewrite($this->page($this->big(), $head));
        $this->assertSame(1, substr_count($out, 'rel="preload"'));
    }

    public function test_preload_can_be_disabled_by_filter_and_needs_head(): void
    {
        add_filter('oxpulse_lcp_preload_enabled', static fn(): bool => false);
        $out = $this->pass()->rewrite($this->page($this->big()));
        $this->assertStringNotContainsString('rel="preload"', $out);
        $this->assertStringContainsString('fetchpriority="high"', $out);

        $GLOBALS['__oxpulse_filters'] = [];
        $headless = '<html><body>' . $this->big() . '</body></html>';
        $this->assertStringNotContainsString('rel="preload"', $this->pass()->rewrite($headless));
    }

    public function test_candidate_filter_can_pick_another_image_or_none(): void
    {
        $html = $this->page($this->big('', 'first') . $this->big('', 'second'));

        add_filter('oxpulse_lcp_candidate', static fn($default, array $all): int => 1, 10, 2);
        $out = $this->pass()->rewrite($html);
        $this->assertStringContainsString('<img fetchpriority="high"  width="600" height="400" src="https://x.test/second.jpg">', $out);
        $this->assertStringNotContainsString('<img fetchpriority="high"  width="600" height="400" src="https://x.test/first.jpg">', $out);

        $GLOBALS['__oxpulse_filters'] = [];
        add_filter('oxpulse_lcp_candidate', static fn($default, array $all) => null, 10, 2);
        $this->assertStringNotContainsString('fetchpriority="high"', $this->pass()->rewrite($html));
    }

    // ─── sizes=auto (core wp_img_tag_add_auto_sizes semantics) ───────

    /** @return array<string,array{0:string,1:bool}> */
    public static function sizesCases(): array
    {
        $base = 'width="300" height="100" srcset="https://x.test/s.jpg 300w" src="https://x.test/s.jpg"';
        return [
            'lazy + sizes gets auto'        => ['<img loading="lazy" sizes="100vw" ' . $base . '>', true],
            'already auto first'            => ['<img loading="lazy" sizes="Auto, 100vw" ' . $base . '>', false],
            'eager untouched'               => ['<img loading="eager" sizes="100vw" ' . $base . '>', false],
            'no loading untouched'          => ['<img sizes="100vw" ' . $base . '>', false],
            'no sizes untouched'            => ['<img loading="lazy" ' . $base . '>', false],
            'no srcset untouched'           => ['<img loading="lazy" sizes="100vw" width="300" height="100" src="https://x.test/s.jpg">', false],
            'no width untouched'            => ['<img loading="lazy" sizes="100vw" height="100" srcset="https://x.test/s.jpg 300w" src="https://x.test/s.jpg">', false],
        ];
    }

    #[DataProvider('sizesCases')]
    public function test_sizes_auto_semantics(string $img, bool $changes): void
    {
        // A first big eager image takes the LCP candidate role so $img is a plain one.
        $html = $this->page($this->big('', 'lcp') . $img);
        $out = $this->pass()->rewrite($html);
        $this->assertSame($changes, str_contains($out, 'sizes="auto, 100vw"'));
        if (!$changes) {
            $this->assertStringContainsString($img, $out);
        }
    }

    public function test_core_opt_out_filter_disables_auto_sizes(): void
    {
        add_filter('wp_img_tag_add_auto_sizes', static fn(): bool => false);
        $img = '<img loading="lazy" sizes="100vw" width="300" height="100" srcset="https://x.test/s.jpg 300w" src="https://x.test/s.jpg">';
        $out = $this->pass()->rewrite($this->page($this->big('', 'lcp') . $img));
        $this->assertStringNotContainsString('sizes="auto', $out);
    }

    public function test_malformed_and_attribute_with_gt_do_not_corrupt_output(): void
    {
        $html = $this->page('<img alt="a > b" fetchpriority="high" width="600" height="400" src="https://x.test/g.jpg"><img src="x" alt=\'it"s\' /><img');
        $out = $this->pass()->rewrite($html);
        $this->assertStringContainsString('alt="a > b"', $out);
        $this->assertStringContainsString('alt=\'it"s\' />', $out);
        $this->assertStringEndsWith('<img</body></html>', $out);
    }
}
