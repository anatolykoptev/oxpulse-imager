/**
 * OXPulse Imager Admin - Default Options
 *
 * Fallback shown before the first successful GET /oxpulse/v1/options
 * response lands. Every field the SPA renders MUST have a default
 * here, so the UI never shows `undefined`.
 */

export const defaultOptions = {
  // Connection
  enabled: false,
  endpoint: '',
  allowedSources: [],
  // Format
  outputFormat: 'auto',
  defaultQuality: 80,
  formatQuality: {},
  // Enhancements (Phase 5.1)
  lqipEnabled: false,
  lqipBlur: 1,
  dprEnabled: false,
  dprVariants: [1, 2, 3],
  watermark: null,
  // <picture> element wrapping (Phase 1) — Pro-gated (PICTURE_ELEMENT).
  // Default OFF; the backend oxpulse_picture_enabled filter at PHP_INT_MAX
  // is the real gate (enforces false under free).
  pictureEnabled: false,
  // LocalBackend cache size cap (MB) — Pro-gated (CACHE_MANAGEMENT).
  // Default 512; under free the backend loadCacheMaxMb() returns this
  // default regardless of the stored value. The SPA locks the field
  // under free (janitor still runs — do NOT imply caching is off).
  cacheMaxMb: 512,
  // Diagnostics
  diagnosticLevel: 'off',
  devHttpOverride: false,
  removeOnUninstall: false,
  // Onboarding (Phase 5.5) — false until the wizard completes or is skipped
  onboarded: false,
  // Secrets (status only — never the actual values)
  secretStatus: 'empty',
};
