/**
 * OXPulse Imager Admin - Options normalization
 *
 * Normalizes the REST GET response into the shape the store expects:
 * ensures every field has a value (falls back to defaults), converts
 * types where the REST layer may return loose types, and unpacks the
 * watermark object.
 */

import { defaultOptions } from '../store/defaults.js';

/**
 * Normalize a REST GET response into a complete options object.
 *
 * @param {Object} raw The raw REST response (camelCase + secretStatus).
 * @return {Object} Normalized options with all fields populated.
 */
export const normalizeOptions = (raw) => {
  const opts = { ...defaultOptions };

  if (!raw || typeof raw !== 'object') {
    return opts;
  }

  // Scalar fields — direct copy if present.
  const scalarFields = [
    'enabled', 'endpoint', 'allowedSources', 'outputFormat',
    'defaultQuality', 'lqipEnabled', 'lqipBlur', 'dprEnabled',
    'dprVariants', 'formatQuality', 'diagnosticLevel',
    'devHttpOverride', 'removeOnUninstall', 'onboarded', 'secretStatus',
  ];

  for (const field of scalarFields) {
    if (raw[field] !== undefined && raw[field] !== null) {
      opts[field] = raw[field];
    }
  }

  // allowedSources: REST returns an array; keep as array.
  if (Array.isArray(raw.allowedSources)) {
    opts.allowedSources = raw.allowedSources;
  }

  // dprVariants: REST returns an array of numbers; keep as array.
  if (Array.isArray(raw.dprVariants)) {
    opts.dprVariants = raw.dprVariants;
  }

  // formatQuality: REST returns an object {avif: 70, webp: 80}; keep as object.
  if (raw.formatQuality && typeof raw.formatQuality === 'object') {
    opts.formatQuality = raw.formatQuality;
  }

  // watermark: REST returns null or {enabled, opacity, position, ...}.
  // The SPA works with the same shape — null means disabled.
  opts.watermark = raw.watermark ?? null;

  return opts;
};
