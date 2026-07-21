# Roadmap

Source of truth for OXPulse Imager development phases. Status reflects the actual codebase (not aspirations).

## Completed phases

### Phase 0 — Plugin skeleton ✅

- Plugin header, autoloader, license, composer config
- Unit + integration test bootstrap (PHPUnit 11)
- GitHub Actions workflow (PHP 8.3/8.4/8.5 matrix)
- Initial readme.txt + README.md

### Phase 1 — Signing & source policy ✅

- `SigningConfig` (hex key/salt, min 16 bytes)
- `HmacSigner` (SHA-256, imgproxy signature vectors)
- `SourcePolicy` (allowlist with path boundary, SSRF protection, fragment/query denial)
- `TransformRequest` / `TransformProfile` value objects
- `ImgproxyPathBuilder` (option serialization, base64url encoding)
- `ImgproxyUrlGenerator` (signed URL assembly)
- Unit tested against official imgproxy vectors and bypass scenarios

### Phase 2 — Settings API & health check ✅

- `OptionSettingsRepository` (WP options persistence)
- `SettingsValidator` (input sanitization, format/quality validation)
- `SettingsPage` + `SettingsController` (Settings API, nonce, capability gate)
- `HealthCheckService` + `WordPressHealthClient` (endpoint reachability)
- Secrets redaction in admin UI (never displayed after save)
- Test Connection action

### Phase 3 — Delivery: URL rewriting ✅

- `UrlRewriter` (single decision point, fail-safe preservation)
- `ContentImgTagRewriter` (`wp_content_img_tag`)
- `SrcsetRewriter` (`wp_calculate_image_srcset`)
- `AttachmentImageSrcRewriter` (`wp_get_attachment_image_src`)
- `ServiceRegistrar` wiring
- Integration tests for the full pipeline

### Phase 3.1 — 2026 format negotiation + Content-Disposition ✅

- `ImgproxyPathBuilder`: `auto` mode omits `@format` suffix → Accept header negotiation by imgproxy
- `filename:` option (base64url-encoded) for Content-Disposition
- `UrlRewriter`: builds Content-Disposition filename from source URL; replaces extension in explicit format mode
- `HealthCheckService::checkAvifSupport()` — sends `Accept: image/avif`, verifies response Content-Type
- `WordPressHealthClient::get()` with custom headers
- README: CDN cache key + `Vary: Accept` requirements
- Settings page: "auto = Accept negotiation" description

### Phase 4 — Production readiness ✅

**Performance:**
- `UrlRewriter` lazily creates and reuses `ImgproxyUrlGenerator` (and its `HmacSigner` + `ImgproxyPathBuilder` deps) instead of instantiating per call

**Coverage expansion (5 filters total, was 3):**
- `AttachmentUrlRewriter` for `wp_get_attachment_url` — image extension filtering (jpg/png/webp/avif/heic/tiff), non-image preservation
- `AvatarRewriter` for `get_avatar` — src extraction, size passing, attribute preservation, single/double quotes

**Admin UX:**
- "Test AVIF support" button — verifies `IMGPROXY_AUTO_AVIF` on server via Accept header probe
- Diagnostic logging dropdown (off/basic/verbose) — setting exposed in UI, persisted
- Remove on uninstall checkbox — deletes all plugin options on uninstall

**Tests:** 188 tests, 373 assertions, green on PHP 8.3/8.4/8.5.

## Upcoming phases

Source: competitive analysis against Optimole, Imagify, EWWW, ShortPixel, WebP Express, Anek (see `~/competitors-research/COMPETITIVE-ANALYSIS.md`).

### Phase 5.1 — Lazy load

**Why:** Every top competitor (Optimole, EWWW, ShortPixel, Imagify) has it. Users expect it. Native `loading="lazy"` is baseline; competitors add viewport-based lazy, placeholders, LCP protection, exclusion rules.

**Scope:**
- New `LazyLoadRewriter` adapter on `wp_content_img_tag` + `post_thumbnail_html`
- Replace `<img src>` with `<img data-src>` + JS observer (or use native `loading="lazy"` with LCP detection)
- Viewport-based lazy: only above-the-fold eager, rest deferred
- Placeholder: blur or empty while loading
- Exclusion rules: per-class, per-URL, per-page
- LCP image detection: don't lazy-load the LCP image
- Settings: enable/disable, lazyload mode (viewport/all/none), exclusion list

**Effort:** Medium. ~300 lines + tests.

### Phase 5.2 — Media library column + WP-CLI

**Why:** Power users expect per-image status in admin and CLI control. Imagify, EWWW, ShortPixel all have both.

**Scope:**
- `manage_media_custom_column` filter — adds "OXPulse" column showing rewrite status, file size, "Re-optimize" / "Restore" button
- `wp_prepare_attachment_for_js` filter — status in the media modal (Gutenberg/REST)
- WP-CLI commands:
  - `wp oxpulse warm --all` — pre-warm CDN cache for all images
  - `wp oxpulse warm --attachment=<id>` — warm a single attachment
  - `wp oxpulse status` — show config + health summary
  - `wp oxpulse flush` — clear WordPress object cache for rewritten URLs

**Effort:** Small-Medium. ~350 lines + tests.

### Phase 5.3 — SRCSET auto-generation + watermark

**Why:** We rewrite existing srcset but don't create srcset for images that lack it. Optimole and EWWW auto-generate. Watermark is a native imgproxy feature (`wm:`) we don't expose.

**Scope:**
- Extend `ContentImgTagRewriter`: when `<img>` has no `srcset` but has width/height, generate srcset with DPR variants (1x/2x/3x) or width-based breakpoints
- Watermark settings: enable, watermark image URL, opacity, position
- Pass `wm:` option in `ImgproxyPathBuilder` when watermark enabled
- Settings page section for watermark

**Effort:** Medium. ~400 lines + tests.

### Phase 5.4 — REST API + bulk pre-warming

**Why:** Headless WP / React admin / external automation need REST. Bulk pre-warming gives users the "Optimize all images" button they expect (even though imgproxy caches on first request, users want the feedback).

**Scope:**
- REST endpoints:
  - `GET /wp-json/oxpulse/v1/status` — config + health + counts
  - `POST /wp-json/oxpulse/v1/warm` — trigger bulk pre-warm (returns job ID)
  - `GET /wp-json/oxpulse/v1/warm/<job_id>` — poll job progress
  - `GET /wp-json/oxpulse/v1/health` — health check + AVIF check
- Bulk pre-warm admin page: "Warm all images" button, progress bar, results
- Background processing via WordPress cron or ActionScheduler
- Per-attachment warm via REST

**Effort:** Medium. ~500 lines + tests.

### Phase 5.5 — Diagnostics implementation + admin bar

**Why:** `diagnostic_level` setting exists but no logging implementation. Admin bar diagnostics give per-page visibility (EWWW has this).

**Scope:**
- `DiagnosticLogger` service — reads `diagnostic_level` option, writes via `error_log()`
  - `off`: silent
  - `basic`: per-request rewrite/preserve counts
  - `verbose`: per-URL with reason
- Hook into `UrlRewriter` and each adapter to emit log entries
- Admin bar item: "OXPulse: X rewritten, Y preserved on this page" with link to full diagnostics
- Diagnostics page in admin: recent log entries, filterable by level

**Effort:** Small. ~350 lines + tests.

### Phase 5.6 — Onboarding wizard

**Why:** Optimole, Imagify, ShortPixel all have first-run wizards. Reduces friction for new users.

**Scope:**
- First-run detection (option flag on activation)
- Wizard steps:
  1. Enter imgproxy endpoint URL
  2. Enter key + salt (or generate)
  3. Test connection
  4. Configure allowed sources (auto-detect `wp-content/uploads/`)
  5. Test AVIF support
  6. Enable delivery
- Redirect to wizard on first plugin activation
- Skip option (go straight to settings page)

**Effort:** Medium. ~400 lines + tests.

## Not planned (out of scope)

- **Media offload to S3/Cloud Storage** — different plugin category (storage, not delivery). Recommend a dedicated offload plugin alongside OXPulse.
- **CSS/JS URL rewriting** — scope creep. CSS `background-image` rewriting is a separate concern.
- **Sidecar file generation** (`.webp`/`.avif` next to originals) — fundamentally different architecture. We use imgproxy for on-the-fly transformation, not local file generation.
- **libvips/Imagick/GD processing** — imgproxy handles processing server-side. The plugin is a URL rewriter, not an image processor.
- **`.htaccess` rewrite rules** — we do URL rewriting in PHP, not server config. No .htaccess needed.
- **Cloud API key / quota management** — self-hosted, no API key needed.
- **Third-party gallery integrations** (NextGen, BuddyBoss) — marginal value. `wp_get_attachment_url` + `get_avatar` cover the main cases.

## Release milestones

| Version | Phases | Status |
|---|---|---|
| 0.1.0 | 0, 1, 2, 3, 3.1, 4 | Released (current `main`) |
| 0.2.0 | 5.1 (lazy load) | Planned |
| 0.3.0 | 5.2 (media column + CLI) | Planned |
| 0.4.0 | 5.3 (srcset auto + watermark) | Planned |
| 0.5.0 | 5.4 (REST + bulk warm) | Planned |
| 0.6.0 | 5.5 (diagnostics + admin bar) | Planned |
| 1.0.0 | 5.6 (onboarding) + polish | Planned — first stable release |
