# Changelog

## 0.1.0

- Initial public release.
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
