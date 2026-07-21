# Changelog

All notable changes are documented here. From 0.2.0 onward, entries are
managed by [release-please](https://github.com/googleapis/release-please)
via Conventional Commits.

## Unreleased

### Phase 5.1 — imgproxy-native enhancements

- **LQIP placeholders** — low-quality image placeholders via imgproxy's `blur` + `quality` options, injected as `data-src` on `<img>` tags
- **DPR-aware srcset** — generates device-pixel-ratio variants (1x/2x/3x) for retina/4K displays
- **Watermarking** — imgproxy-native watermark overlay with configurable position, opacity, scale
- **Quality-per-format** — separate quality settings for AVIF, WebP, JPEG, PNG

### Phase 5.2 — Modern React admin SPA

- Settings page rebuilt as a Vite + React + Zustand SPA
- Single scrollable page with sticky section-anchor nav
- Sections: Connection, Format, Enhancements, Diagnostics, Tools, Pre-warm
- Self-contained bundle (no `@wordpress/*` packages), ~280KB JS / ~88KB gzip

### Phase 5.3 — REST API + synchronous bulk pre-warm

- `POST /oxpulse/v1/prewarm` — bulk cache pre-warm for a batch of source image URLs
- `POST /oxpulse/v1/health` — health check against the configured (or provided) endpoint
- `POST /oxpulse/v1/avif-check` — verify AVIF support on the imgproxy instance
- `GET/POST /oxpulse/v1/options` — read/write plugin settings
- `PrewarmService` with `curl_multi` concurrent HEAD requests
- Domain value objects: `PrewarmRequest`, `PrewarmItemResult`, `PrewarmBatchResult`

### Phase 5.4 — Diagnostics + admin bar

- `DiagnosticLoggerInterface` + `WordPressDiagnosticLogger` — accumulates rewrite decisions in memory, flushes to `error_log` at shutdown
- Three log levels: `off` (silent), `basic` (per-request counts), `verbose` (per-URL with redacted source)
- `AdminBarDiagnostics` — "OXPulse: X rewritten, Y preserved" in the WordPress admin bar on frontend pages
- `GET/DELETE /oxpulse/v1/diagnostics` — recent log entries + clear
- SPA `DiagnosticsSection` extended with recent log entries table

### Phase 5.5 — Onboarding wizard

- Six-step first-run wizard (endpoint → secrets → test connection → allowed sources → test AVIF → enable delivery)
- `onboarded` option flag — `false` on fresh activation, `true` after wizard completes or is skipped
- "Skip for now" for advanced users who want to configure manually
- Incremental save — each step saves via POST /options, so progress is kept if the user quits mid-wizard
- "Auto-detect uploads URL" button reads `wp_upload_dir()['baseurl']`

### Phase 5.7 — WP integration 2026 + async pre-warm

- **WP-CLI commands** — `wp oxpulse status`, `wp oxpulse info <url>`, `wp oxpulse warm [--all|--attachment=<id>|<urls>...]`, `wp oxpulse flush`
- **Optimization Detective integration** — `<link rel="preconnect">` to imgproxy endpoint (always) + OD tag visitor for IMG tags with imgproxy URLs (when Image Prioritizer is NOT active)
- **`GET /status`** — config + health + signing in one call
- **`GET /info?url=<source>&width=<n>`** — preview the signed imgproxy URL without dispatching a request
- **Async pre-warm via WordPress cron** — `POST /prewarm` with `async: true` creates a job + schedules a cron event; `GET /prewarm/<jobId>` polls progress. `AsyncPrewarmService` processes 50 URLs per cron tick. Job state in transients (1-hour expiry). UUID v4 job IDs.

### Test coverage

- 317 PHP tests (8.3/8.4/8.5) — all green
- 6 JS tests — all green
- Build deterministic (Vite + content-hash manifest)

## [0.1.1](https://github.com/anatolykoptev/oxpulse-imager/compare/v0.1.0...v0.1.1) (2026-07-21)


### Added

* **i18n:** full Russian translation + JS i18n pipeline ([ac15950](https://github.com/anatolykoptev/oxpulse-imager/commit/ac159502845062f34cea37944fb26772b9651513))


### Fixed

* **admin:** grant manage_oxpulse_imager capability to administrators + add Settings link ([c904a76](https://github.com/anatolykoptev/oxpulse-imager/commit/c904a768d783f6d0fcab63df1c5bde41851bf5be))
* **content-disposition:** strip source extension in auto mode to prevent double extension ([a06faa4](https://github.com/anatolykoptev/oxpulse-imager/commit/a06faa4cc014420709f807bbe60f31360c4edbe1))
* emit absolute imgproxy URLs (resolve relative endpoint against site host) ([#25](https://github.com/anatolykoptev/oxpulse-imager/issues/25)) ([6f69d5b](https://github.com/anatolykoptev/oxpulse-imager/commit/6f69d5bd38ced7e0fbb6a231b7681f2ad92167f7))
* **i18n:** JS JSON filename must use script handle, not domain ([6c77c60](https://github.com/anatolykoptev/oxpulse-imager/commit/6c77c60ab15ad35b3259a750b81948da25cc82bf))
* **i18n:** pass filesystem path to wp_set_script_translations ([9b08500](https://github.com/anatolykoptev/oxpulse-imager/commit/9b08500ddb2cabb480ab0b2beb478d8b20ac4f71))
* **i18n:** wp_set_script_translations path must point at languages/ dir ([d698078](https://github.com/anatolykoptev/oxpulse-imager/commit/d698078dd40d31d43527bad3aa625766fff0248a))
* **intermediate:** rewrite image_get_intermediate_size URL to fix 403 on theme crop sizes ([388834b](https://github.com/anatolykoptev/oxpulse-imager/commit/388834b98aceb7710b79fcb0228a6a7d86321017))
* **local:** prepend leading slash in local:// path (imgproxy expects local:///path) ([04e35e1](https://github.com/anatolykoptev/oxpulse-imager/commit/04e35e1683e742aa7b60dfccbb4be3aed9172fe9))
* **local:** return path RELATIVE to localBasePath for imgproxy local:// ([af3fee3](https://github.com/anatolykoptev/oxpulse-imager/commit/af3fee3ed3532ac848b8fcee097ef7eff52e7fa0))
* **local:** use imgproxy ENCODED source format (base64url of local:///path) ([dc6463a](https://github.com/anatolykoptev/oxpulse-imager/commit/dc6463ab77e94ab09ce58a4d227f2083a5b6c616))


### Documentation

* **roadmap:** Phase 6 LocalBackend design (standard-hosting local delivery) ([#26](https://github.com/anatolykoptev/oxpulse-imager/issues/26)) ([01a0a7d](https://github.com/anatolykoptev/oxpulse-imager/commit/01a0a7d0d1c39968a3d04f5b11cbe2abe2491562))


### Reverts

* no major version bump — stay on 0.1.0 ([49cf03c](https://github.com/anatolykoptev/oxpulse-imager/commit/49cf03cd46168dd139384afa1a184e66cc3368f1))

## 0.1.0

Initial public release.

- Optional imgproxy delivery mode (disabled by default).
- Strict source-policy allowlist validation.
- HMAC-SHA256 signed imgproxy URLs.
- Single settings page with Test Connection action.
- Original URL fallback on any delivery failure.

### Phase 0 — Plugin skeleton

- Plugin header, autoloader, license, composer config
- Unit + integration test bootstrap (PHPUnit 11)
- GitHub Actions workflow (PHP 8.3/8.4/8.5 matrix)

### Phase 1 — Signing & source policy

- `SigningConfig` (hex key/salt, min 16 bytes)
- `HmacSigner` (SHA-256, imgproxy signature vectors)
- `SourcePolicy` (allowlist with path boundary, SSRF protection, fragment/query denial)
- `ImgproxyPathBuilder` + `ImgproxyUrlGenerator`
- Unit tested against official imgproxy vectors and bypass scenarios

### Phase 2 — Settings API & health check

- `OptionSettingsRepository` (WP options persistence)
- `SettingsValidator` (input sanitization, format/quality validation)
- `SettingsPage` + `SettingsController` (Settings API, nonce, capability gate)
- `HealthCheckService` + `WordPressHealthClient` (endpoint reachability)
- Secrets redaction in admin UI (never displayed after save)
- Test Connection action

### Phase 3 — Delivery: URL rewriting

- `UrlRewriter` (single decision point, fail-safe preservation)
- `ContentImgTagRewriter` (`wp_content_img_tag`)
- `SrcsetRewriter` (`wp_calculate_image_srcset`)
- `AttachmentImageSrcRewriter` (`wp_get_attachment_image_src`)
- `ServiceRegistrar` wiring
- Integration tests for the full pipeline

### Phase 3.1 — 2026 format negotiation + Content-Disposition

- `auto` output format uses Accept header negotiation (AVIF/WebP/original) — matches Cloudflare Images, Cloudinary, Imgix, Vercel
- `filename:` option (base64url-encoded) for Content-Disposition — meaningful filenames on "Save As"
- `HealthCheckService::checkAvifSupport()` — verifies `IMGPROXY_AUTO_AVIF` on server
- `WordPressHealthClient::get()` with custom headers
- README: CDN cache key + `Vary: Accept` requirements

### Phase 4 — Production readiness

- **Performance:** `UrlRewriter` reuses `ImgproxyUrlGenerator` across rewrites (lazy creation)
- **Coverage:** `AttachmentUrlRewriter` for `wp_get_attachment_url` (image extensions only)
- **Coverage:** `AvatarRewriter` for `get_avatar` (src rewrite, size passing, attribute preservation)
- **Admin UX:** "Test AVIF support" button in settings
- **Admin UX:** Diagnostic logging dropdown (off/basic/verbose)
- **Admin UX:** Remove on uninstall checkbox
- 5 WordPress filters covered total (was 3)
- 188 tests, 373 assertions, green on PHP 8.3/8.4/8.5
