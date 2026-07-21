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

### Phase 5.1 — imgproxy-native enhancements ✅

**Shipped:** LQIP placeholders (`blur:`), DPR-aware srcset (`dpr:`), per-format quality (`fq:`), watermark (`wm:`). All via imgproxy URL options, no PHP image processing.

**Scope note:** "Responsive breakpoints srcset" (Cloudinary-style optimal breakpoints vs WP fixed sizes) was deferred — `SrcsetRewriter` already handles existing srcset, and generating optimal breakpoints requires an `info:` endpoint round-trip per image that doesn't fit the on-the-fly model. May revisit in a later phase.

**Commit:** `4cc7f63`

### Phase 5.2 — Modern React admin ✅

**Shipped (re-scoped from original ROADMAP):** Self-contained React SPA (Vite 4 + Tailwind 3 + Radix UI + Zustand, all bundled — no `wp-element`/`wp-api-fetch` deps). 6 sections (Connection, Format, Enhancements, Diagnostics, Tools, Pre-warm). REST backend (`/oxpulse/v1/options`). Deterministic build (Terser, content-hashed, CI rebuild-verification). Ported from the UTM Linker plugin pattern.

**Scope note:** The original ROADMAP defined Phase 5.2 as "WP integration 2026" (media library column, WP-CLI, Optimization Detective integration). That scope was re-prioritized: the React admin unblocked the modern UX surface first. The WP-CLI + Optimization Detective + media column items moved to Phase 5.7.

**Commit:** `ffbd612`

### Phase 5.3 — REST API + bulk pre-warm (synchronous) ✅

**Shipped:** `HealthRestController` (`POST /health`, `POST /avif-check`), `PrewarmRestController` (`POST /prewarm` with synchronous batch HEAD dispatch, concurrency=5 via `curl_multi`). `PrewarmService` builds signed imgproxy URLs via the existing `UrlRewriter` pipeline (only authorized sources warmed). SPA `ToolsSection` rewritten to call REST directly; new `PrewarmSection` with per-URL results table.

**Scope note (deferred to Phase 5.7):** The original ROADMAP specified background processing via WordPress cron with job IDs + polling (`GET /warm/<job_id>`), plus `GET /status` and `GET /info?url=` endpoints. The synchronous implementation handles up to 50 URLs per batch in seconds — enough for most use cases. Background cron + polling deferred to Phase 5.7 (headless WP / external automation with large catalogs need it; the SPA doesn't).

**Commit:** `71f1cbc`

## Upcoming phases

### Phase 5.4 — Diagnostics implementation + admin bar ✅

**Shipped:**
- **`DiagnosticLoggerInterface`** (Application) + **`WordPressDiagnosticLogger`** (Infrastructure) — accumulates rewrite decisions in memory during the request, flushes to `error_log()` at shutdown. Three levels: `off` (silent), `basic` (per-request summary with counts by context), `verbose` (per-URL entries with redacted source URL). Recent entries (capped at 100) stored in a transient for the admin page.
- **`UrlRewriter` logging hook** — optional `DiagnosticLoggerInterface` in the constructor; records a `LogEntry` on every rewrite decision (rewritten or preserved with reason). No-op when no logger is attached (the default for tests).
- **`AdminBarDiagnostics`** — adds "OXPulse: X rewritten, Y preserved" to the WordPress admin bar on frontend pages. Only visible to users with `OXPULSE_IMAGER_CAPABILITY`. Shows live in-memory counts from the current request.
- **`DiagnosticsRestController`** — `GET /oxpulse/v1/diagnostics` (recent entries + level), `DELETE /oxpulse/v1/diagnostics` (clear transient).
- **SPA `DiagnosticsSection`** — extended with recent log entries table (context, status, URL, width, reason), refresh + clear buttons, level pill.

**Effort:** ~900 lines + tests. 310 PHP tests green on 8.3/8.4/8.5.

### Phase 5.5 — Onboarding wizard ✅

**Shipped:**
- **`onboarded` option** — boolean flag, `false` on fresh activation, `true` after wizard completes or is skipped. Re-activation does NOT reset this (only a fresh install with no option in DB gets the wizard).
- **Backend wiring** — `OPTION_ONBOARDED` constant, `OptionsMapper` camel↔snake entry, `SettingsValidator` boolean pass-through, `OptionsRestController` GET returns `onboarded`, POST persists it.
- **`OnboardingWizard.jsx`** — modal overlay shown when `!options.onboarded`. Six steps:
  1. Endpoint URL (with HTTPS validation)
  2. Signing key + salt (with "Generate random 32-byte key + salt" button using `crypto.getRandomValues`)
  3. Test connection (POST /health with the endpoint from step 1)
  4. Allowed sources (with "Auto-detect uploads URL" button reading `window.oxpulseAdmin.uploadsUrl`)
  5. Test AVIF support (POST /avif-check, warns about WebP fallback)
  6. Enable delivery + finish (sets `enabled: true, onboarded: true`)
- **Incremental save** — each step saves via the existing POST /options endpoint, so a user who quits mid-wizard keeps their progress.
- **"Skip for now"** — dismisses the wizard and sets `onboarded: true` without enabling delivery (for advanced users who want to configure manually).
- **`SettingsPage.php`** — localizes `uploadsUrl` via `wp_upload_dir()['baseurl']` for the auto-detect button.
- **`App.jsx`** — renders `<OnboardingWizard />` when `!onboarded`, main settings UI otherwise.

**Effort:** ~600 lines + tests. 317 PHP tests green on 8.3/8.4/8.5. Build deterministic.

### Phase 5.6 — wordpress.org release prep ✅

**Shipped:**
- **`readme.txt`** — full wordpress.org format: description, key features, WordPress hooks covered, explicit non-goals, installation + requirements, FAQ (6 questions), screenshots (5), changelog (1.0.0 + 0.1.0), upgrade notice.
- **`CHANGELOG.md`** — updated with all Phase 5.1–5.7 entries + test coverage summary.
- **Version bump to 1.0.0** — `oxpulse-imager.php` (plugin header + `OXPULSE_IMAGER_VERSION`), `composer.json`, `package.json` (already 1.0.0).
- **Plugin description** — updated to reflect the full feature set (AVIF/WebP, LQIP, DPR srcset, watermarking, WP-CLI, Optimization Detective, async pre-warming).
- **`.pot` file** — `languages/oxpulse-imager.pot` generated via `wp-pot` (152 lines, all PHP gettext strings). `npm run make-pot` script added. CI verifies the .pot file is up to date.
- **SVN deploy workflow** — `.github/workflows/deploy.yml` triggers on `v*` tags. Runs tests as a gate, verifies version match (tag ↔ plugin header ↔ readme.txt stable tag), deploys to wordpress.org SVN via `10up/action-wordpress-plugin-deploy`, generates a release zip artifact.
- **CI .pot verification** — `test.yml` now runs `npm run make-pot` and verifies the committed .pot file matches.

**Effort:** ~300 lines + assets. 317 PHP tests green on 8.3/8.4/8.5.

### Phase 5.7 — WP integration 2026 + async pre-warm ✅

**Shipped:**
- **WP-CLI commands** — `wp oxpulse status`, `wp oxpulse info <url>`, `wp oxpulse warm [--all|--attachment=<id>|<urls>...] [--widths=...]`, `wp oxpulse flush`. `WarmCommand` chunks into batches of 50, enumerates attachment sizes via `wp_get_attachment_metadata`.
- **Optimization Detective integration** — `<link rel="preconnect">` to the imgproxy endpoint (always, via `wp_head`); OD tag visitor for IMG tags with imgproxy URLs (only when Image Prioritizer is NOT active, to avoid duplicate preload links). Adds breakpoint-specific preload links for LCP images via OD's `OD_Link_Collection`.
- **`GET /status`** — config + health + signing in one call.
- **`GET /info?url=<source>&width=<n>`** — preview the signed imgproxy URL without dispatching a request.
- **Async pre-warm via WordPress cron** — `POST /prewarm` with `async: true` creates a job + schedules a cron event; `GET /prewarm/<jobId>` polls progress. `AsyncPrewarmService` processes 50 URLs per cron tick, schedules the next batch, and marks the job complete when all URLs are processed. Job state in transients (1-hour expiry).

**Effort:** ~1200 lines + tests. 290 PHP tests green on 8.3/8.4/8.5.

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
| 0.2.0 | 5.1 (imgproxy-native: LQIP, DPR srcset, watermark, quality-per-format) | Released (`4cc7f63`) |
| 0.3.0 | 5.2 (modern React admin SPA) | Released (`ffbd612`) |
| 0.4.0 | 5.3 (REST API for health/AVIF + synchronous bulk pre-warm) | Released (`71f1cbc`) |
| 0.5.0 | 5.4 (diagnostics + admin bar) | Released |
| 0.6.0 | 5.5 (onboarding wizard) | Released |
| 0.7.0 | 5.7 (WP-CLI, Optimization Detective, async cron pre-warm, /status, /info) | Released |
| 1.0.0 | 5.6 (wordpress.org release) | Released |
