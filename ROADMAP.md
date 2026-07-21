# Roadmap

Source of truth for OXPulse Imager development phases. Status reflects the actual codebase (not aspirations).

## 2026 principles

- **Do not duplicate WordPress native.** WP 7.0 already ships `loading="lazy"`, `fetchpriority="high"` for LCP, `decoding="async"`, `sizes="auto"`, Speculation Rules API, Image Prioritizer. Reimplementing these is an anti-pattern.
- **Leverage imgproxy capabilities WordPress cannot match.** On-the-fly resize to any dimension, watermark, blur placeholders, DPR variants, format negotiation — these are imgproxy-native and have no WP core equivalent.
- **Integrate with WP performance features, don't compete.** Optimization Detective, Image Prioritizer, Modern Image Formats — extend them with imgproxy URLs where relevant.
- **Match Cloudflare Images / Cloudinary / Vercel standards** for format negotiation, Content-Disposition, cache headers, responsive delivery.

## What we explicitly do NOT build (WP native 2026 covers it)

| Feature | WP version | Why we skip |
|---|---|---|
| Lazy load (`loading="lazy"`) | 5.9+ | Native, automatic, no JS needed |
| `fetchpriority="high"` for LCP | 6.3+ | Native LCP detection |
| `decoding="async"` | 6.3+ | Native |
| `sizes="auto"` for lazy-loaded | 6.7+ | Native |
| Speculation Rules API | 6.8+ | Native prefetch/prerender |
| Image Prioritizer (preload links) | Performance Lab | Native breakpoint-specific preload |
| Optimization Detective | Performance Lab | Native viewport detection |

## Completed phases

### Phase 0 — Plugin skeleton ✅

- Plugin header, autoloader, license, composer config
- Unit + integration test bootstrap (PHPUnit 11)
- GitHub Actions workflow (PHP 8.3/8.4/8.5 matrix)

### Phase 1 — Signing & source policy ✅

- `SigningConfig`, `HmacSigner` (SHA-256, imgproxy signature vectors)
- `SourcePolicy` (allowlist with path boundary, SSRF protection)
- `ImgproxyPathBuilder` + `ImgproxyUrlGenerator`
- Unit tested against official imgproxy vectors and bypass scenarios

### Phase 2 — Settings API & health check ✅

- `OptionSettingsRepository`, `SettingsValidator`
- `SettingsPage` + `SettingsController` (Settings API, nonce, capability gate)
- `HealthCheckService` + `WordPressHealthClient`
- Secrets redaction, Test Connection action

### Phase 3 — Delivery: URL rewriting ✅

- `UrlRewriter` (single decision point, fail-safe preservation)
- 3 adapters: `ContentImgTagRewriter`, `SrcsetRewriter`, `AttachmentImageSrcRewriter`
- `ServiceRegistrar` wiring, integration tests

### Phase 3.1 — 2026 format negotiation + Content-Disposition ✅

- `auto` format = Accept header negotiation (AVIF/WebP/original)
- `filename:` option for Content-Disposition
- `HealthCheckService::checkAvifSupport()`
- README: CDN cache key + `Vary: Accept` requirements

### Phase 4 — Production readiness ✅

- Performance: `UrlRewriter` reuses `ImgproxyUrlGenerator` (lazy creation)
- Coverage: `AttachmentUrlRewriter` (`wp_get_attachment_url`), `AvatarRewriter` (`get_avatar`)
- Admin UX: Test AVIF button, diagnostic level dropdown, remove on uninstall
- 5 WordPress filters covered, 188 tests, green on PHP 8.3/8.4/8.5

## Upcoming phases

### Phase 5.1 — imgproxy-native enhancements

**Why:** These are capabilities imgproxy has that WordPress core never will. This is our real differentiator vs Optimole/EWWW/Imagify — they process images in PHP or via cloud API; we get them free from imgproxy URL options.

**Scope:**
- **LQIP placeholders** — imgproxy `blur:1` option generates a tiny blurred placeholder. Add `data-placeholder` attribute or inline `data:image/svg+xml` background. Reduces CLS, improves perceived performance. Cloudinary and Imgix do this; WP native does not.
- **DPR-aware srcset** — extend `SrcsetRewriter` to emit `1x/2x/3x` DPR variants via imgproxy `dpr:` option. Mobile retina displays get sharper images without over-serving desktop.
- **Responsive breakpoints srcset** — instead of WP fixed sizes (thumbnail/medium/large), generate srcset with Cloudinary-style optimal breakpoints (e.g. 200/400/800/1200/1600). Configurable in settings.
- **Watermark** — expose imgproxy `wm:` option. Settings: watermark image URL, opacity, position, scale. Applied on-the-fly, no local file processing.
- **Quality per format** — imgproxy supports `q:` per format (`q:avif:70` separate from `q:webp:80`). Expose in settings for fine-tuned AVIF/WebP quality.

**Effort:** Medium-Large. ~800 lines + tests.

### Phase 5.2 — WP integration 2026

**Why:** Meet power users where they are. Media library column and WP-CLI are table stakes for any serious image plugin. Integration with Optimization Detective is the 2026 way to enhance LCP handling without duplicating core.

**Scope:**
- **Media library column** — `manage_media_custom_column` filter. Shows "OXPulse: rewritten" status, file size savings (via imgproxy `info:` endpoint), "Re-optimize" button.
- **`wp_prepare_attachment_for_js`** — status in media modal (Gutenberg/REST).
- **Optimization Detective integration** — if the Performance Lab / Optimization Detective plugin is active, register our imgproxy URLs for breakpoint-specific preload links. We don't reimplement LCP detection; we feed our URLs into their pipeline.
- **WP-CLI commands:**
  - `wp oxpulse warm --all` — pre-warm CDN cache for all images
  - `wp oxpulse warm --attachment=<id>` — warm a single attachment
  - `wp oxpulse status` — config + health + counts
  - `wp oxpulse flush` — clear object cache for rewritten URLs
  - `wp oxpulse info <url>` — show imgproxy URL that would be generated for a source URL

**Effort:** Medium. ~500 lines + tests.

### Phase 5.3 — REST API + bulk pre-warming

**Why:** Headless WP / React admin / external automation need REST. Bulk pre-warming gives users the "Optimize all images" button they expect from Optimole/Imagify — even though imgproxy caches on first request, users want progress feedback.

**Scope:**
- **REST endpoints:**
  - `GET /wp-json/oxpulse/v1/status` — config + health + counts
  - `POST /wp-json/oxpulse/v1/warm` — trigger bulk pre-warm (returns job ID)
  - `GET /wp-json/oxpulse/v1/warm/<job_id>` — poll job progress
  - `GET /wp-json/oxpulse/v1/health` — health check + AVIF check
  - `GET /wp-json/oxpulse/v1/info?url=<source>` — preview generated imgproxy URL
- **Bulk pre-warm admin page** — "Warm all images" button, progress bar, results table. Background processing via WordPress cron.
- **Per-attachment warm** via REST and admin media column.

**Effort:** Medium. ~500 lines + tests.

### Phase 5.4 — Diagnostics implementation + admin bar

**Why:** `diagnostic_level` setting exists but no logging implementation. Admin bar diagnostics give per-page visibility (EWWW has this).

**Scope:**
- **`DiagnosticLogger` service** — reads `diagnostic_level` option, writes via `error_log()`:
  - `off`: silent
  - `basic`: per-request rewrite/preserve counts
  - `verbose`: per-URL with reason
- Hook into `UrlRewriter` and each adapter to emit log entries.
- **Admin bar item** — "OXPulse: X rewritten, Y preserved on this page" with link to full diagnostics.
- **Diagnostics page** in admin — recent log entries, filterable by level. Reads from error log or a custom log table.

**Effort:** Small. ~350 lines + tests.

### Phase 5.5 — Onboarding wizard

**Why:** Optimole, Imagify, ShortPixel all have first-run wizards. Reduces friction for new users.

**Scope:**
- First-run detection (option flag on activation).
- Wizard steps:
  1. Enter imgproxy endpoint URL
  2. Enter key + salt (or generate)
  3. Test connection
  4. Configure allowed sources (auto-detect `wp-content/uploads/`)
  5. Test AVIF support
  6. Enable delivery
- Redirect to wizard on first plugin activation. Skip option for advanced users.

**Effort:** Medium. ~400 lines + tests.

### Phase 5.6 — wordpress.org release prep

**Why:** First stable 1.0.0 release on wordpress.org requires standards-compliant assets.

**Scope:**
- `readme.txt` — full wordpress.org format (description, FAQ, screenshots, changelog)
- Assets: banner (772×250), icon (128×128 + 256×256), screenshots
- `.pot` file for translations
- SVN deploy workflow (GitHub Action to push tags to wordpress.org)
- Final security review, review against Plugin Guidelines
- Version bump to 1.0.0

**Effort:** Small-Medium. ~200 lines + assets.

## Out of scope (different plugin category)

- **Media offload to S3/Cloud Storage** — different concern (storage, not delivery). Use a dedicated offload plugin alongside OXPulse.
- **CSS/JS URL rewriting** — scope creep. CSS `background-image` rewriting is a separate concern.
- **Sidecar file generation** (`.webp`/`.avif` next to originals) — fundamentally different architecture. We use imgproxy for on-the-fly transformation.
- **libvips/Imagick/GD local processing** — imgproxy handles processing server-side.
- **`.htaccess` rewrite rules** — we do URL rewriting in PHP, not server config.
- **Cloud API key / quota management** — self-hosted, no API key needed.
- **Third-party gallery integrations** (NextGen, BuddyBoss) — marginal value. `wp_get_attachment_url` + `get_avatar` cover the main cases.
- **JS lazy loader / custom IntersectionObserver** — WP native `loading="lazy"` + `fetchpriority` since 6.3. Reimplementing is an anti-pattern.

## Release milestones

| Version | Phases | Status |
|---|---|---|
| 0.1.0 | 0, 1, 2, 3, 3.1, 4 | Released (current `main`) |
| 0.2.0 | 5.1 (imgproxy-native: LQIP, DPR srcset, watermark, quality-per-format) | Planned |
| 0.3.0 | 5.2 (media column, WP-CLI, Optimization Detective integration) | Planned |
| 0.4.0 | 5.3 (REST API, bulk pre-warm) | Planned |
| 0.5.0 | 5.4 (diagnostics + admin bar) | Planned |
| 0.6.0 | 5.5 (onboarding wizard) | Planned |
| 1.0.0 | 5.6 (wordpress.org release) | Planned — first stable release |
