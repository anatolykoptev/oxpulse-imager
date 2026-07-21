/**
 * OXPulse Imager Admin — i18n entry point.
 *
 * Re-exports the standard @wordpress/i18n gettext functions. The
 * bundle is self-contained (React + react-dom + @wordpress/i18n all
 * bundled by Vite), so no `wp.i18n` global is required at runtime.
 *
 * Translation data is loaded by WordPress via wp_set_script_translations()
 * in SettingsPage::enqueueAdminAssets() — WP inlines a
 * `<script>` before our bundle that populates the @wordpress/i18n
 * locale data registry for the 'oxpulse-imager' domain.
 *
 * Call sites use the same shape as before:
 *   __('Connection', 'oxpulse-imager')
 *   _x('Verifying…', 'health check', 'oxpulse-imager')
 *
 * @see https://developer.wordpress.org/apis/handling-translations/
 */

export {
  __,
  _x,
  _n,
  _nx,
  sprintf,
  setLocaleData,
  isRTL,
} from '@wordpress/i18n';
