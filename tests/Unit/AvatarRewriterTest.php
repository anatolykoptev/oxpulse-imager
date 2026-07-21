<?php
/**
 * AvatarRewriter tests.
 *
 * Verifies get_avatar rewriting: src attribute extraction, size
 * passing, preservation of other attributes, and fail-safe.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AvatarRewriter;
use PHPUnit\Framework\TestCase;

class AvatarRewriterTest extends TestCase
{
    private function createRewriter(bool $enabled = true): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/'],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_empty_avatar_returned_unchanged(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $this->assertSame('', $rewriter->rewrite('', 'user@example.com', 96, '', 'Avatar'));
    }

    public function test_rewrites_avatar_src(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = '<img src="https://example.com/avatar.jpg" class="avatar avatar-96" alt="User" width="96" height="96" />';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'User');

        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('class="avatar avatar-96"', $result);
        $this->assertStringContainsString('alt="User"', $result);
        $this->assertStringContainsString('width="96"', $result);
        $this->assertStringContainsString('height="96"', $result);
    }

    public function test_passes_size_as_dimensions(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = '<img src="https://example.com/avatar.jpg" alt="User" />';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 128, '', 'User');

        // The rewriter should request a 128x128 resize.
        $this->assertStringContainsString('rs:fill:128:128', $result);
    }

    public function test_preserves_non_allowed_avatar(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = '<img src="https://gravatar.com/avatar/abc123.jpg" class="avatar" alt="User" />';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'User');

        $this->assertSame($avatar, $result);
    }

    public function test_preserves_when_delivery_disabled(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter(enabled: false));
        $avatar = '<img src="https://example.com/avatar.jpg" class="avatar" alt="User" />';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'User');

        $this->assertSame($avatar, $result);
    }

    public function test_preserves_avatar_without_src(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = '<div class="avatar-placeholder">No image</div>';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'User');

        $this->assertSame($avatar, $result);
    }

    public function test_preserves_other_attributes(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = '<img src="https://example.com/avatar.jpg" class="avatar avatar-96 photo" alt="John" width="96" height="96" loading="lazy" srcset="https://example.com/avatar-192.jpg 2x" />';

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'John');

        // All attributes except src should be preserved.
        $this->assertStringContainsString('class="avatar avatar-96 photo"', $result);
        $this->assertStringContainsString('alt="John"', $result);
        $this->assertStringContainsString('loading="lazy"', $result);
        // srcset is NOT rewritten by AvatarRewriter (only src is handled).
        $this->assertStringContainsString('srcset="https://example.com/avatar-192.jpg 2x"', $result);
    }

    public function test_handles_single_quotes_in_src(): void
    {
        $rewriter = new AvatarRewriter($this->createRewriter());
        $avatar = "<img src='https://example.com/avatar.jpg' alt='User' />";

        $result = $rewriter->rewrite($avatar, 'user@example.com', 96, '', 'User');

        $this->assertStringContainsString('imgproxy.example.com', $result);
    }
}
