=== OXPulse Imager ===
Contributors: anatolykoptev
Tags: images, imgproxy, delivery, avif, webp, performance
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional bring-your-own imgproxy image delivery for WordPress. Generates signed, deterministic imgproxy URLs for approved local origins while preserving the original URL whenever configuration, source policy, signing, or delivery cannot safely proceed.

== Description ==

OXPulse Imager is a privacy-first WordPress plugin that connects your site to a bring-your-own imgproxy v4 endpoint. When enabled by an administrator, it rewrites approved local image URLs to signed imgproxy URLs that deliver AVIF, WebP, and other modern formats on demand.

Disabled by default. No SaaS, no telemetry, no FFI, no license calls. The plugin never mutates uploads, attachment metadata, post content, or generated files. When configuration, source policy, signing, or delivery cannot safely proceed, the original WordPress URL is preserved.

= MVP scope =

* Optional imgproxy delivery mode, disabled by default.
* Strict source-policy allowlist validation.
* HMAC-SHA256 signed imgproxy URLs.
* WordPress-native hooks: attachment attributes, responsive srcset, and scoped content image rewriting via WP_HTML_Tag_Processor.
* Single settings page with manual Test Connection action.
* Original URL fallback on any delivery failure.

= Explicit non-goals for v1 =

* Local image optimization and sidecar generation.
* SEO plugin-specific adapters (Yoast, Rank Math).
* Background bulk processing.
* Disabling WordPress intermediate image generation.
* SaaS, hosted imgproxy, or any external service dependency.

== Installation ==

1. Upload the `oxpulse-imager` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Settings > OXPulse Imager.
4. Configure your imgproxy endpoint, signing key, salt, and allowed source URL prefixes.
5. Use the Test Connection action to validate your configuration.
6. Enable delivery.

== Frequently Asked Questions ==

= Does this plugin send my images to a third-party service? =

No. The plugin connects only to the imgproxy endpoint you configure. No telemetry, no license checks, no external requests except the explicit administrator-triggered health check.

= Does this plugin modify my media library or uploads? =

No. URL transformation is request-time presentation behavior. The plugin never mutates uploads, attachment metadata, post content, or generated files.

= What happens if imgproxy is unavailable or misconfigured? =

The plugin preserves the original WordPress image URL. Images remain visible. The page never breaks because of a delivery failure.

= Is imgproxy required? =

No. The plugin is disabled by default and changes no frontend URLs until an administrator explicitly enables it. Without imgproxy configured, the plugin is inert.

== Changelog ==

= 0.1.0 =

* Initial public release.
* Optional imgproxy delivery mode (disabled by default).
* Strict source-policy allowlist validation.
* HMAC-SHA256 signed imgproxy URLs.
* Single settings page with Test Connection action.
* Original URL fallback on any delivery failure.
