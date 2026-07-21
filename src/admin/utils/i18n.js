/**
 * OXPulse Imager Admin - i18n shim
 *
 * Self-contained bundle (see build/vite.config.js — React and all
 * runtime deps are bundled, nothing is loaded from WordPress globals
 * like `wp.i18n`). This shim keeps the same call shape (`__(text,
 * domain)`) so a real translation layer can be dropped in later
 * without touching every component, but for now simply returns the
 * source string.
 *
 * @param {string} text   Source (English) string.
 * @param {string} [domain] Text domain — unused, kept for call-site compatibility.
 * @return {string} The source string, unmodified.
 */
export const __ = (text, domain) => text; // eslint-disable-line no-unused-vars
