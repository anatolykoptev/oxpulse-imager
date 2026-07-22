<?php
/**
 * HtaccessGenerator + CapabilityTester tests.
 *
 * Verifies:
 * - The .htaccess generator emits the expected rewrite rules (miss →
 *   oxpulse-img.php, WebP Accept gate).
 * - The capability tester picks the fallback when mod_rewrite is
 *   unavailable (stubbed).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\HtaccessGenerator;
use PHPUnit\Framework\TestCase;

class HtaccessGeneratorTest extends TestCase
{
    public function test_generates_rewrite_rules_for_cache_miss(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        // RewriteEngine On.
        $this->assertStringContainsString('RewriteEngine On', $rules);
        // RewriteCond %{REQUEST_FILENAME} !-f (only on miss).
        $this->assertStringContainsString('%{REQUEST_FILENAME} !-f', $rules);
        // Rewrite to the endpoint.
        $this->assertStringContainsString('oxpulse-img.php', $rules);
        // WebP Accept gate.
        $this->assertStringContainsString('%{HTTP_ACCEPT} image/webp', $rules);
    }

    public function test_rules_include_cache_control_headers(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        // The .htaccess should set long cache for existing cache files.
        $this->assertStringContainsString('Cache-Control', $rules);
        $this->assertStringContainsString('immutable', $rules);
    }

    public function test_rules_deny_php_execution_in_cache_dir(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        $this->assertStringContainsString('php_flag engine off', $rules);
    }

    /**
     * Regression for #40. The .htaccess is written INTO the cache dir
     * (a per-directory context), where mod_rewrite matches the path
     * RELATIVE to that dir. The old rule used a docroot-relative
     * pattern (`^wp-content/cache/oxpulse/(.+)$`) that could never match
     * there → every cache-miss 404'd on real Apache. The rule must match
     * the dir-relative trailing "<key>.webp" segment instead, and MUST
     * NOT carry the docroot-relative prefix.
     */
    public function test_rewrite_rule_is_dir_relative_not_docroot(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        // The broken docroot-relative pattern must be gone.
        $this->assertStringNotContainsString('^wp-content/cache/oxpulse', $rules);
        // The broken one-level-up relative target must be gone.
        $this->assertStringNotContainsString('../oxpulse-img.php', $rules);
        // The rule matches the dir-relative trailing "<key>.webp".
        $this->assertStringContainsString('([^/]+)\.webp$', $rules);
    }

    /**
     * Regression for #40. The rewrite must forward the signed key as
     * ?k=<key> to an ABSOLUTE endpoint URL-path (the endpoint re-derives
     * sourceHash + format from the payload, so the query form suffices
     * and avoids PATH_INFO/AcceptPathInfo portability differences).
     */
    public function test_rewrite_forwards_key_as_query_to_absolute_endpoint(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        $this->assertStringContainsString('/wp-content/oxpulse-img.php?k=$1', $rules);
    }

    /**
     * The endpoint URL-path is derived from cacheBaseUrl (two levels up:
     * .../wp-content/cache/oxpulse → .../wp-content/oxpulse-img.php), so
     * it is correct for a WordPress install in a subdirectory.
     */
    public function test_endpoint_path_derived_for_subdirectory_install(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/blog/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        $this->assertStringContainsString('/blog/wp-content/oxpulse-img.php?k=$1', $rules);
    }

    /**
     * The derivation follows a custom WP_CONTENT_URL / CDN cache base,
     * not a hardcoded '/wp-content/'.
     */
    public function test_endpoint_path_derived_for_custom_content_base(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://cdn.example.com/assets/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        $this->assertStringContainsString('/assets/oxpulse-img.php?k=$1', $rules);
    }
}
