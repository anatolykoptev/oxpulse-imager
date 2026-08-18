<?php
/**
 * Path-normalization evasion tests (#79).
 *
 * The proxy-loop guard and the allowlist prefix compare must normalize
 * the source path first, or `//imgproxy`, `/./imgproxy`,
 * `/imgproxy/../imgproxy`, an encoded `%2f`, or a backslash can route
 * to the same backend (nginx merge_slashes default-on) while the
 * str_starts_with/segment check misses it — a bounded imgproxy
 * self-loop under a permissive root allowlist.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Source\NormalizedUrl;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class PathNormalizationTest extends TestCase
{
    private SourcePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SourcePolicy();
    }

    /** Root allowlist — the only config where the loop is reachable. */
    private function rootConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/'],
        );
    }

    /**
     * @dataProvider loopEvasionForms
     */
    public function test_proxy_loop_guard_catches_normalized_evasion(string $path): void
    {
        $decision = $this->policy->authorize('https://example.com' . $path, $this->rootConfig());
        $this->assertFalse(
            $decision->authorized,
            "Evasion form '{$path}' should be denied as a proxy loop, got: {$decision->reason}"
        );
        $this->assertSame('proxy_loop_detected', $decision->reason);
    }

    public static function loopEvasionForms(): array
    {
        return [
            'double slash'        => ['//imgproxy/x.jpg'],
            'dot segment'         => ['/./imgproxy/x.jpg'],
            'parent traversal'    => ['/imgproxy/../imgproxy/x.jpg'],
            'trailing dot-dot'    => ['/a/../imgproxy/x.jpg'],
            'mixed'               => ['/.//imgproxy/./x.jpg'],
        ];
    }

    public function test_normalizes_repeated_slashes(): void
    {
        $u = NormalizedUrl::parse('https://example.com//wp-content///uploads//a.jpg');
        $this->assertSame('/wp-content/uploads/a.jpg', $u->path);
    }

    public function test_resolves_dot_and_dotdot_segments(): void
    {
        $u = NormalizedUrl::parse('https://example.com/wp-content/./uploads/../uploads/a.jpg');
        $this->assertSame('/wp-content/uploads/a.jpg', $u->path);
    }

    public function test_dotdot_cannot_escape_root(): void
    {
        // Over-popping stays at root, never a negative/relative path.
        $u = NormalizedUrl::parse('https://example.com/../../a.jpg');
        $this->assertSame('/a.jpg', $u->path);
    }

    public function test_rejects_encoded_slash(): void
    {
        // %2f is a slash the router may collapse but str_starts_with
        // would not — reject rather than guess.
        $this->expectException(\InvalidArgumentException::class);
        NormalizedUrl::parse('https://example.com/imgproxy%2f..%2fx.jpg');
    }

    public function test_encoded_slash_in_query_is_accepted(): void
    {
        // A %2f in the query string (e.g. a CDN ?url= param) is
        // legitimate and never enters the prefix compare — it must not
        // reject the whole URL. Regression for the Devin over-broad
        // rejection finding.
        $u = NormalizedUrl::parse('https://example.com/wp-content/uploads/a.jpg?u=https%3A%2F%2Fcdn.example.com%2Fb.jpg');
        $this->assertSame('/wp-content/uploads/a.jpg', $u->path);
        $this->assertStringContainsString('%2F', $u->query);
    }

    public function test_rejects_backslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NormalizedUrl::parse('https://example.com/imgproxy\\..\\x.jpg');
    }

    public function test_rejects_encoded_dot_segment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NormalizedUrl::parse('https://example.com/x/%2e%2e/imgproxy/a.jpg');
    }

    public function test_rejects_encoded_backslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NormalizedUrl::parse('https://example.com/x/%5c/y.jpg');
    }

    public function test_encoded_parent_denied_under_uploads_allowlist(): void
    {
        // The SEC finding: /wp-content/uploads/%2e%2e/other.jpg passes a
        // naive uploads-prefix compare but the origin resolves it ABOVE
        // uploads. Rejected at parse → malformed_url, never authorized.
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/%2e%2e/other.jpg',
            $config
        );
        $this->assertFalse($decision->authorized);
    }

    public function test_trailing_dotdot_keeps_directory_slash(): void
    {
        $u = NormalizedUrl::parse('https://example.com/a/b/../');
        $this->assertSame('/a/', $u->path);
        $u2 = NormalizedUrl::parse('https://example.com/a/b/..');
        $this->assertSame('/a/', $u2->path);
    }

    public function test_normal_uploads_url_still_authorizes(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $decision = $this->policy->authorize('https://example.com/wp-content/uploads/2026/08/a.jpg', $config);
        $this->assertTrue($decision->authorized);
    }
}
