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

## [0.1.4](https://github.com/anatolykoptev/oxpulse-imager/compare/v0.1.3...v0.1.4) (2026-07-23)


### Added

* &lt;picture&gt; wrapping in BufferRewriter for theme-hardcoded &lt;img&gt; (default-off, Phase 1b, closes [#70](https://github.com/anatolykoptev/oxpulse-imager/issues/70)) ([#75](https://github.com/anatolykoptev/oxpulse-imager/issues/75)) ([44a2cf3](https://github.com/anatolykoptev/oxpulse-imager/commit/44a2cf3756061ebc28df549bd6a016395bcf5274))


### Fixed

* proxy-loop detection matches host+path so same-host imgproxy delivery works ([#78](https://github.com/anatolykoptev/oxpulse-imager/issues/78)) ([e3f3300](https://github.com/anatolykoptev/oxpulse-imager/commit/e3f330056197c9df64f22992e0cdcc254f0b6634))

## [0.1.3](https://github.com/anatolykoptev/oxpulse-imager/compare/v0.1.2...v0.1.3) (2026-07-22)


### Added

* deliver AVIF via &lt;picture&gt; element on the content path (default-off) ([#68](https://github.com/anatolykoptev/oxpulse-imager/issues/68)) ([4d214b9](https://github.com/anatolykoptev/oxpulse-imager/commit/4d214b9825147d63268356574b2533a20bae1dac))

## [0.1.2](https://github.com/anatolykoptev/oxpulse-imager/compare/v0.1.1...v0.1.2) (2026-07-22)


### Added

* AVIF in LocalBackend — server-side Accept negotiation AVIF&gt;WebP&gt;original ([#47](https://github.com/anatolykoptev/oxpulse-imager/issues/47)) ([54dbef6](https://github.com/anatolykoptev/oxpulse-imager/commit/54dbef684ebbfed51193f8fcac0a90734f215113))

## [0.1.1](https://github.com/anatolykoptev/oxpulse-imager/compare/v0.1.0...v0.1.1) (2026-07-22)


### Added

* admin capability notice + nginx snippet + re-test endpoint + co-install notice ([#43](https://github.com/anatolykoptev/oxpulse-imager/issues/43) phase 5) ([#57](https://github.com/anatolykoptev/oxpulse-imager/issues/57)) ([69941cc](https://github.com/anatolykoptev/oxpulse-imager/commit/69941cc45bcdc0b738da5b68e7900ed0e8f0cd14))
* capability probe + CapabilityTester live detection ([#43](https://github.com/anatolykoptev/oxpulse-imager/issues/43) phase 1) ([#52](https://github.com/anatolykoptev/oxpulse-imager/issues/52)) ([fac31e1](https://github.com/anatolykoptev/oxpulse-imager/commit/fac31e107cfa454e96db3277552484629b745538))
* coexistence hardening — idempotency + buffer guard battery + endpoint-header lock ([#43](https://github.com/anatolykoptev/oxpulse-imager/issues/43) phase 3) ([#54](https://github.com/anatolykoptev/oxpulse-imager/issues/54)) ([64d4ba5](https://github.com/anatolykoptev/oxpulse-imager/commit/64d4ba54c536efad85ba2522677988b0b7de45d4))
* **i18n:** full Russian translation + JS i18n pipeline ([ac15950](https://github.com/anatolykoptev/oxpulse-imager/commit/ac159502845062f34cea37944fb26772b9651513))
* LocalBackend emits ?k= fallback URLs when rewrite unavailable ([#43](https://github.com/anatolykoptev/oxpulse-imager/issues/43) phase 2) ([#53](https://github.com/anatolykoptev/oxpulse-imager/issues/53)) ([6371929](https://github.com/anatolykoptev/oxpulse-imager/commit/6371929b947718bb1057e2abfe2a3269f2b67753))
* Phase 6 LocalBackend — standard-hosting local delivery (MVP) ([#27](https://github.com/anatolykoptev/oxpulse-imager/issues/27)) ([424ca8b](https://github.com/anatolykoptev/oxpulse-imager/commit/424ca8b8fd54d85020eff05fc28b5229f328dfec))
* purge caching-plugin page caches on delivery settings change ([#43](https://github.com/anatolykoptev/oxpulse-imager/issues/43) phase 4) ([#56](https://github.com/anatolykoptev/oxpulse-imager/issues/56)) ([f291360](https://github.com/anatolykoptev/oxpulse-imager/commit/f291360a0c045ce3130eb3884a74e9fa517f067f))


### Fixed

* **admin:** grant manage_oxpulse_imager capability to administrators + add Settings link ([c904a76](https://github.com/anatolykoptev/oxpulse-imager/commit/c904a768d783f6d0fcab63df1c5bde41851bf5be))
* bug-hunt hardening ([#30](https://github.com/anatolykoptev/oxpulse-imager/issues/30)-[#36](https://github.com/anatolykoptev/oxpulse-imager/issues/36)) — endpoint key perms/atomicity, GD bomb fail-closed, path escaping, original cache-control, signing-config guard ([#37](https://github.com/anatolykoptev/oxpulse-imager/issues/37)) ([3ab9ea8](https://github.com/anatolykoptev/oxpulse-imager/commit/3ab9ea867dd321ccbe0329a09cdb5703e5a740be))
* **content-disposition:** strip source extension in auto mode to prevent double extension ([a06faa4](https://github.com/anatolykoptev/oxpulse-imager/commit/a06faa4cc014420709f807bbe60f31360c4edbe1))
* emit absolute imgproxy URLs (resolve relative endpoint against site host) ([#25](https://github.com/anatolykoptev/oxpulse-imager/issues/25)) ([6f69d5b](https://github.com/anatolykoptev/oxpulse-imager/commit/6f69d5bd38ced7e0fbb6a231b7681f2ad92167f7))
* **i18n:** JS JSON filename must use script handle, not domain ([6c77c60](https://github.com/anatolykoptev/oxpulse-imager/commit/6c77c60ab15ad35b3259a750b81948da25cc82bf))
* **i18n:** pass filesystem path to wp_set_script_translations ([9b08500](https://github.com/anatolykoptev/oxpulse-imager/commit/9b08500ddb2cabb480ab0b2beb478d8b20ac4f71))
* **i18n:** wp_set_script_translations path must point at languages/ dir ([d698078](https://github.com/anatolykoptev/oxpulse-imager/commit/d698078dd40d31d43527bad3aa625766fff0248a))
* **intermediate:** rewrite image_get_intermediate_size URL to fix 403 on theme crop sizes ([388834b](https://github.com/anatolykoptev/oxpulse-imager/commit/388834b98aceb7710b79fcb0228a6a7d86321017))
* LocalBackend .htaccess routes cache-miss on Apache ([#40](https://github.com/anatolykoptev/oxpulse-imager/issues/40)) ([#41](https://github.com/anatolykoptev/oxpulse-imager/issues/41)) ([6181744](https://github.com/anatolykoptev/oxpulse-imager/commit/6181744d721a640441e86495e8727ce6599ae149))
* LocalBackend endpoint bakes a src-path autoloader, not vendor ([#45](https://github.com/anatolykoptev/oxpulse-imager/issues/45)) ([#46](https://github.com/anatolykoptev/oxpulse-imager/issues/46)) ([8896c28](https://github.com/anatolykoptev/oxpulse-imager/commit/8896c282bf73d8f3a3f72164c3985dc584375941))
* LocalBackend serves original to non-webp clients instead of 404 ([#42](https://github.com/anatolykoptev/oxpulse-imager/issues/42)) ([#44](https://github.com/anatolykoptev/oxpulse-imager/issues/44)) ([e7f5189](https://github.com/anatolykoptev/oxpulse-imager/commit/e7f51893111cfcd42cdba0ae1d14d47627c37ee7))
* **local:** prepend leading slash in local:// path (imgproxy expects local:///path) ([04e35e1](https://github.com/anatolykoptev/oxpulse-imager/commit/04e35e1683e742aa7b60dfccbb4be3aed9172fe9))
* **local:** return path RELATIVE to localBasePath for imgproxy local:// ([af3fee3](https://github.com/anatolykoptev/oxpulse-imager/commit/af3fee3ed3532ac848b8fcee097ef7eff52e7fa0))
* **local:** use imgproxy ENCODED source format (base64url of local:///path) ([dc6463a](https://github.com/anatolykoptev/oxpulse-imager/commit/dc6463ab77e94ab09ce58a4d227f2083a5b6c616))
* Phase 6 [#29](https://github.com/anatolykoptev/oxpulse-imager/issues/29) followups — sourceMode guard + endpoint integration test + wp_remote_* ([#38](https://github.com/anatolykoptev/oxpulse-imager/issues/38)) ([d047634](https://github.com/anatolykoptev/oxpulse-imager/commit/d047634e8104e5cd63bde60c6d6a9fdbcba2de6c))
* release version-sync — PluginTest self-consistency + readme Stable tag sync step ([#60](https://github.com/anatolykoptev/oxpulse-imager/issues/60)) ([ca7a05e](https://github.com/anatolykoptev/oxpulse-imager/commit/ca7a05ee1113b94027a6d5b4af434a3664918d5b))
* wordpress.org plugin-check clean — i18n, escaping, sanitize, justified ignores ([#39](https://github.com/anatolykoptev/oxpulse-imager/issues/39)) ([9647e4d](https://github.com/anatolykoptev/oxpulse-imager/commit/9647e4d24f53978bc535066513d9d9592f43c119))


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
