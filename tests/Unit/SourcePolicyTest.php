<?php
/**
 * SourcePolicy tests.
 *
 * Verifies source URL authorization against the configured allowlist,
 * including SSRF bypass attempts, proxy loop detection, and path
 * boundary enforcement.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class SourcePolicyTest extends TestCase
{
    private SourcePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SourcePolicy();
    }

    private function config(array $sources, bool $enabled = true): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: $enabled,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: $sources
        );
    }

    // === Authorized cases ===

    public function test_authorizes_valid_uploads_url(): void
    {
        $config = $this->config(['https://example.com/wp-content/uploads/']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/2026/07/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
        $this->assertSame('authorized', $decision->reason);
    }

    public function test_authorizes_root_origin_prefix(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com/any/path/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
    }

    public function test_authorizes_explicit_subdirectory_prefix(): void
    {
        $config = $this->config(['https://example.com/wp-content/']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/2026/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
    }

    // === Denied cases ===

    public function test_denies_when_delivery_disabled(): void
    {
        $config = $this->config(['https://example.com/'], enabled: false);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('delivery_disabled', $decision->reason);
    }

    public function test_denies_when_no_allowed_sources(): void
    {
        $config = $this->config([]);
        $decision = $this->policy->authorize(
            'https://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('no_allowed_sources_configured', $decision->reason);
    }

    public function test_denies_foreign_host(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://evil.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_host_suffix_bypass(): void
    {
        // https://example.com/ must NOT authorize https://example.com.evil.test/
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com.evil.test/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_user_info_bypass(): void
    {
        // https://example.com/ must NOT authorize https://example.com@evil.test/
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com@evil.test/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_strips_fragment(): void
    {
        // Ф10: fragments are client-side only and must be stripped, not
        // rejected. The mu-plugin this replaces strips fragments via
        // preg_replace('/[#?].*$/', '', $url) before pathinfo. imager
        // strips them in NormalizedUrl::parse() — the fragment is silently
        // dropped and the URL is authorized on its path alone.
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com/image.jpg#fragment',
            $config
        );

        $this->assertTrue($decision->authorized);
        // Fragment must NOT appear in the canonical URL.
        $this->assertStringNotContainsString('#fragment', (string) $decision->url);
    }

    public function test_denies_non_http_scheme(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'data:image/png;base64,abc123',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_ftp_scheme(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'ftp://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_control_characters(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            "https://example.com/\x00image.jpg",
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_empty_url(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize('', $config);

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_proxy_loop(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://imgproxy.example.com/']
        );
        $decision = $this->policy->authorize(
            'https://imgproxy.example.com/some/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('proxy_loop_detected', $decision->reason);
    }

    public function test_denies_sibling_path(): void
    {
        // /wp-content must NOT authorize /wp-content-foo
        $config = $this->config(['https://example.com/wp-content']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content-foo/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_different_port(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com:8080/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_http_when_allowlist_is_https(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'http://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    // --- Ф1: local:// source mode tests ---

    private function localConfig(string $basePath, array $sources = ['https://example.com/']): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: '/imgproxy',
            allowedSources: $sources,
            sourceMode: 'local',
            localBasePath: $basePath,
        );
    }

    public function test_local_mode_authorizes_path_inside_base(): void
    {
        $tmpDir = sys_get_temp_dir() . '/oxpulse-local-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $subDir = $tmpDir . '/wp-content/uploads/2024/01';
        mkdir($subDir, 0755, true);
        $imagePath = $subDir . '/photo.jpg';
        file_put_contents($imagePath, 'fake-image');

        try {
            $config = $this->localConfig($tmpDir);
            $decision = $this->policy->authorize(
                'https://example.com/wp-content/uploads/2024/01/photo.jpg',
                $config
            );

            $this->assertTrue($decision->authorized);
            // fsPath is RELATIVE to localBasePath (the form imgproxy's
            // local:// transport expects — joined onto IMGPROXY_LOCAL_FILESYSTEM_ROOT).
            $this->assertSame('wp-content/uploads/2024/01/photo.jpg', $decision->fsPath);
        } finally {
            unlink($imagePath);
            rmdir($subDir);
            rmdir($tmpDir . '/wp-content/uploads/2024');
            rmdir($tmpDir . '/wp-content/uploads');
            rmdir($tmpDir . '/wp-content');
            rmdir($tmpDir);
        }
    }

    public function test_local_mode_denies_path_traversal(): void
    {
        $tmpDir = sys_get_temp_dir() . '/oxpulse-traversal-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $outsideDir = sys_get_temp_dir() . '/oxpulse-outside-' . uniqid();
        mkdir($outsideDir, 0755, true);
        $outsideFile = $outsideDir . '/secret.txt';
        file_put_contents($outsideFile, 'secret');

        try {
            $config = $this->localConfig($tmpDir);
            // Attempt traversal: /wp-content/../../../outside/secret.txt
            $decision = $this->policy->authorize(
                'https://example.com/wp-content/../../../' . basename($outsideDir) . '/secret.txt',
                $config
            );

            $this->assertFalse($decision->authorized);
            $this->assertSame('local_path_outside_base', $decision->reason);
        } finally {
            unlink($outsideFile);
            rmdir($outsideDir);
            rmdir($tmpDir);
        }
    }

    public function test_local_mode_denies_when_base_path_empty(): void
    {
        $config = $this->localConfig('');
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/photo.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('local_path_outside_base', $decision->reason);
    }

    public function test_local_mode_denies_when_file_does_not_exist(): void
    {
        $tmpDir = sys_get_temp_dir() . '/oxpulse-noexist-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $config = $this->localConfig($tmpDir);
            $decision = $this->policy->authorize(
                'https://example.com/wp-content/uploads/nonexistent.jpg',
                $config
            );

            $this->assertFalse($decision->authorized);
            $this->assertSame('local_path_outside_base', $decision->reason);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function test_local_mode_denies_sibling_directory_with_similar_prefix(): void
    {
        // /var/www/wp-content-evil should NOT be authorized when base is /var/www/wp-content
        $tmpBase = sys_get_temp_dir() . '/oxpulse-base-' . uniqid();
        $tmpSibling = sys_get_temp_dir() . '/oxpulse-base-evil-' . uniqid();
        mkdir($tmpBase, 0755, true);
        mkdir($tmpSibling, 0755, true);
        $evilFile = $tmpSibling . '/secret.jpg';
        file_put_contents($evilFile, 'evil');

        try {
            $config = $this->localConfig($tmpBase);
            // Construct a URL whose path resolves to the sibling directory.
            $decision = $this->policy->authorize(
                'https://example.com/' . basename($tmpSibling) . '/secret.jpg',
                $config
            );

            $this->assertFalse($decision->authorized);
        } finally {
            unlink($evilFile);
            rmdir($tmpSibling);
            rmdir($tmpBase);
        }
    }

    public function test_local_mode_rawurldecodes_percent_encoded_path(): void
    {
        // Cyrillic filename in URL is percent-encoded; on disk it's raw UTF-8.
        $tmpDir = sys_get_temp_dir() . '/oxpulse-cyr-test-' . uniqid();
        $subDir = $tmpDir . '/wp-content/uploads/2024/01';
        mkdir($subDir, 0755, true);
        $cyrillicName = 'Фото.jpg';
        $imagePath = $subDir . '/' . $cyrillicName;
        file_put_contents($imagePath, 'fake-image');

        try {
            $config = $this->localConfig($tmpDir);
            // URL-encoded Cyrillic: %D0%A4%D0%BE%D1%82%D0%BE.jpg
            $encodedUrl = 'https://example.com/wp-content/uploads/2024/01/' . rawurlencode($cyrillicName);
            $decision = $this->policy->authorize($encodedUrl, $config);

            $this->assertTrue($decision->authorized);
            // Relative path with raw UTF-8 filename (percent-decoded).
            $this->assertSame('wp-content/uploads/2024/01/' . $cyrillicName, $decision->fsPath);
        } finally {
            unlink($imagePath);
            rmdir($subDir);
            rmdir($tmpDir . '/wp-content/uploads/2024');
            rmdir($tmpDir . '/wp-content/uploads');
            rmdir($tmpDir . '/wp-content');
            rmdir($tmpDir);
        }
    }

    // === Same-host imgproxy delivery (proxy loop must match host+path) ===

    /**
     * The bug: when imgproxy is reverse-proxied at a PATH on the site's
     * own domain (endpoint https://example.com/imgproxy), a real uploads
     * image on the SAME host must NOT be flagged as a proxy loop. The
     * loop guard must compare host AND path prefix, not host alone.
     */
    public function test_same_host_imgproxy_authorizes_real_uploads_image(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/a.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
        $this->assertSame('authorized', $decision->reason);
    }

    /**
     * A genuine same-host loop — the source IS an imgproxy URL under the
     * endpoint path prefix — must STILL be denied.
     */
    public function test_same_host_imgproxy_denies_genuine_loop_under_endpoint_path(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/imgproxy/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/imgproxy/sig/rs:fill:8:8/plain/x',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('proxy_loop_detected', $decision->reason);
    }

    /**
     * Path-segment boundary: /imgproxy must NOT match /imgproxydata — a
     * sibling path that shares a string prefix but is not under the
     * endpoint. Such a source is NOT a loop; it follows normal allowlist
     * rules (here: not in allowlist → source_not_in_allowlist).
     */
    public function test_same_host_imgproxy_path_prefix_segment_boundary(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://example.com/imgproxy',
            allowedSources: ['https://example.com/imgproxydata/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/imgproxydata/a.jpg',
            $config
        );

        // NOT a proxy loop (segment boundary), and IS in the allowlist.
        $this->assertTrue($decision->authorized);
    }

    /**
     * Cross-host imgproxy: endpoint on a dedicated host still denies a
     * source on that host (loop), and authorizes a same-host uploads
     * image that is in the allowlist (not a loop).
     */
    public function test_cross_host_imgproxy_loop_and_normal_authorization(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://img.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );

        // Normal uploads image on a different host → authorized.
        $ok = $this->policy->authorize(
            'https://example.com/wp-content/uploads/a.jpg',
            $config
        );
        $this->assertTrue($ok->authorized);

        // Source on the imgproxy host → denied as a loop.
        $loop = $this->policy->authorize(
            'https://img.example.com/x',
            $config
        );
        $this->assertFalse($loop->authorized);
        $this->assertSame('proxy_loop_detected', $loop->reason);
    }

    // === Relative same-host endpoint (endpoint stored as '/imgproxy') ===
    //
    // OptionSettingsRepository stores the raw endpoint option. For a
    // same-host nginx reverse-proxy the documented value is a RELATIVE
    // path '/imgproxy' (no scheme/host). NormalizedUrl::parse throws on
    // it (no scheme) — so isProxyLoop must handle the relative form
    // itself: same-host is implied, compare the source path against the
    // endpoint path prefix at a segment boundary.

    /**
     * Relative endpoint, real uploads image: the guard must NOT flag a
     * normal uploads image as a loop (path doesn't start with /imgproxy).
     * Authorize proceeds to the allowlist. (Passes today because the guard
     * no-ops on the relative endpoint — kept to document the unblock.)
     */
    public function test_relative_endpoint_authorizes_real_uploads_image(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: '/imgproxy',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/a.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
        $this->assertSame('authorized', $decision->reason);
    }

    /**
     * Relative endpoint, genuine loop: the source IS an imgproxy URL
     * under the endpoint path prefix. MUST be denied proxy_loop_detected.
     * RED on current code — isProxyLoop no-ops on the relative endpoint
     * so this returns authorized today.
     */
    public function test_relative_endpoint_denies_genuine_loop_under_endpoint_path(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: '/imgproxy',
            allowedSources: ['https://example.com/imgproxy/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/imgproxy/sig/rs:fill:8:8/plain/x',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('proxy_loop_detected', $decision->reason);
    }

    /**
     * Relative endpoint, segment boundary: /imgproxy must NOT match
     * /imgproxydata — a sibling path sharing a string prefix but not
     * under the endpoint. NOT a loop; allowlist decides.
     */
    public function test_relative_endpoint_path_prefix_segment_boundary(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: '/imgproxy',
            allowedSources: ['https://example.com/imgproxydata/'],
        );

        $decision = $this->policy->authorize(
            'https://example.com/imgproxydata/a.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
    }

    public function test_local_mode_http_mode_still_works_when_source_mode_http(): void
    {
        // Regression: sourceMode='http' should NOT try to resolve filesystem paths.
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/'],
            sourceMode: 'http',
            localBasePath: '',
        );

        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/photo.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
        $this->assertNull($decision->fsPath);
    }
}
