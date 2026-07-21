/**
 * OXPulse Imager Admin - API extensions for health + prewarm
 *
 * Adds health check, AVIF check, and bulk pre-warm calls to the
 * existing api.js. Same fetch + nonce pattern.
 */

import { getConfig, parseResponse } from './api.js';

/**
 * Run a health check against the configured (or provided) endpoint.
 *
 * @param {string} [endpoint] Optional endpoint URL override.
 * @return {Promise<{ok: boolean, status: string, message: string, statusCode: number}>}
 */
export const checkHealth = async (endpoint = '') => {
  const { restUrl, nonce } = getConfig();
  const healthUrl = restUrl.replace('/options', '/health');

  const response = await fetch(healthUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: JSON.stringify({ endpoint }),
  });

  return await parseResponse(response);
};

/**
 * Run an AVIF format negotiation check.
 *
 * @param {string} [endpoint] Optional endpoint URL override.
 * @param {string} [sampleImage] Optional sample image URL.
 * @return {Promise<{ok: boolean, status: string, message: string, statusCode: number}>}
 */
export const checkAvif = async (endpoint = '', sampleImage = '') => {
  const { restUrl, nonce } = getConfig();
  const avifUrl = restUrl.replace('/options', '/avif-check');

  const response = await fetch(avifUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: JSON.stringify({ endpoint, sampleImage }),
  });

  return await parseResponse(response);
};

/**
 * Bulk pre-warm imgproxy cache for a batch of source URLs.
 *
 * @param {string[]} urls Source image URLs to warm.
 * @param {number[]} [widths] Optional target widths (0 = no resize).
 * @return {Promise<{total: number, warmed: number, skipped: number, failed: number, items: Array}>}
 */
export const prewarm = async (urls, widths = [0]) => {
  const { restUrl, nonce } = getConfig();
  const prewarmUrl = restUrl.replace('/options', '/prewarm');

  const response = await fetch(prewarmUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: JSON.stringify({ urls, widths }),
  });

  return await parseResponse(response);
};
