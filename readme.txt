=== OXPulse Imager ===
Contributors: anatolykoptev
Tags: images, imgproxy, performance, avif, webp
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bring-your-own imgproxy image delivery for WordPress. Signed AVIF/WebP on demand, LQIP, DPR srcset, watermarking, WP-CLI, async pre-warm. No SaaS.

== Description ==

OXPulse Imager connects your WordPress site to a self-hosted [imgproxy](https://imgproxy.net/) v4 endpoint. When enabled by an administrator, it rewrites approved local image URLs to signed imgproxy URLs that deliver AVIF, WebP, and other modern formats on demand — without generating any intermediate files on your server.

**Disabled by default. No SaaS, no telemetry, no FFI, no license calls.** The plugin never mutates uploads, attachment metadata, post content, or generated files. When configuration, source policy, signing, or delivery cannot safely proceed, the original WordPress URL is preserved.

= Key features =

* **Bring-your-own imgproxy** — no SaaS dependency, no per-image pricing. Your imgproxy, your CDN, your rules.
* **AVIF/WebP on-demand** — `auto` output format uses Accept header negotiation. No intermediate files, no bulk regeneration.
* **LQIP placeholders** — low-quality image placeholders via imgproxy's `blur` + `quality` options.
* **DPR-aware srcset** — generates device-pixel-ratio variants for retina/4K displays.
* **Watermarking** — imgproxy-native watermark overlay with configurable position, opacity, scale.
* **Quality-per-format** — separate quality settings for AVIF, WebP, JPEG, PNG.
* **Optimization Detective integration** — preconnect to your imgproxy endpoint + breakpoint-specific preload links for LCP images (when the Optimization Detective plugin is active).
* **WP-CLI commands** — `wp oxpulse status`, `wp oxpulse info`, `wp oxpulse warm`, `wp oxpulse flush`.
* **Async pre-warming** — bulk cache pre-warm via WordPress cron with job polling.
* **REST API** — `/oxpulse/v1/status`, `/info`, `/health`, `/avif-check`, `/prewarm`, `/diagnostics`.
* **Diagnostics** — per-request logging (off/basic/verbose) + admin bar item showing live rewrite counts.
* **First-run wizard** — six-step onboarding from zero to a working imgproxy integration.
* **Fail-safe preservation** — original URL preserved on any delivery failure, source policy violation, or signing error.

= WordPress hooks covered =

* `wp_content_img_tag` — `<img>` tags in post content (src + srcset)
* `wp_calculate_image_srcset` — responsive srcset arrays
* `wp_get_attachment_image_src` — `[url, w, h, is_intermediate]` arrays
* `wp_get_attachment_url` — raw attachment URLs (image extensions only)
* `get_avatar` — avatar `<img>` tags (Gravatar + custom)

= Explicit non-goals =

* Local image optimization and sidecar generation.
* Disabling WordPress intermediate image generation.
* SaaS, hosted imgproxy, or any external service dependency.
* Mutating uploads, attachment metadata, post content, or generated files.

== Installation ==

1. Upload the `oxpulse-imager` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. The first-run wizard will guide you through configuring your imgproxy endpoint, signing secrets, and allowed source origins.
4. Alternatively, skip the wizard and configure manually via Settings > OXPulse Imager.
5. Enable delivery when ready.

= Requirements =

* WordPress 6.2+
* PHP 8.3+
* A self-hosted imgproxy v4+ instance (with `IMGPROXY_KEY` and `IMGPROXY_SALT` set for signed URLs)
* HTTPS for the imgproxy endpoint (production)

== Frequently Asked Questions ==

= Does this plugin send my images to a third-party service? =

No. The plugin connects only to the imgproxy endpoint you configure. No telemetry, no license checks, no external requests except the explicit administrator-triggered health check and the image requests to your own imgproxy instance.

= Does this plugin modify my media library or uploads? =

No. URL transformation is request-time presentation behavior. The plugin never mutates uploads, attachment metadata, post content, or generated files.

= What happens if imgproxy is unavailable or misconfigured? =

The plugin preserves the original WordPress image URL. Images remain visible. The page never breaks because of a delivery failure.

= Is imgproxy required? =

Yes — this plugin is a bring-your-own-imgproxy integration. You need a self-hosted imgproxy v4+ instance. See the [imgproxy documentation](https://imgproxy.net/) for setup instructions.

= Can I use this with a hosted imgproxy service? =

Yes, as long as the service provides a standard imgproxy endpoint with key/salt signing. Configure the endpoint URL and your signing secrets in the plugin settings.

= Does this work with the Optimization Detective plugin? =

Yes. When Optimization Detective is active, OXPulse adds a preconnect link to your imgproxy endpoint and registers a tag visitor for IMG tags with imgproxy URLs. If Image Prioritizer is also active, OXPulse defers to it for IMG preload links to avoid duplicates.

= Does this work with WP-CLI? =

Yes. The plugin provides four WP-CLI commands: `wp oxpulse status`, `wp oxpulse info <url>`, `wp oxpulse warm [--all|--attachment=<id>|<urls>...]`, and `wp oxpulse flush`.

== Screenshots ==

1. First-run onboarding wizard — six steps from zero to a working imgproxy integration.
2. Settings page — Connection, Format, Enhancements, Diagnostics, Tools, Pre-warm sections.
3. Diagnostics — recent log entries with context, status, URL, width, and reason.
4. Admin bar item — live rewrite counts on frontend pages.
5. Pre-warm — bulk cache pre-warm with sync/async modes.

== Changelog ==

= 0.1.0 =

* Initial public release.
* Optional imgproxy delivery mode (disabled by default).
* Strict source-policy allowlist validation.
* HMAC-SHA256 signed imgproxy URLs.
* Single settings page with Test Connection action.
* Original URL fallback on any delivery failure.
