/**
 * OXPulse Imager Admin - API Utilities
 *
 * Talks to the `oxpulse/v1/options` REST route
 * (src/Integration/WordPress/Admin/OptionsRestController.php) directly
 * via fetch() — no `@wordpress/api-fetch` dependency, since the admin
 * bundle is fully self-contained. The REST URL + nonce are localized
 * onto `window.oxpulseAdmin` by SettingsPage::enqueueAdminAssets() via
 * `wp_localize_script()`.
 */

/**
 * Resolve the REST endpoint URL + nonce localized by PHP.
 *
 * @return {{restUrl: string, nonce: string}}
 */
export const getConfig = () => {
  const config = typeof window !== 'undefined' ? window.oxpulseAdmin : undefined;

  if (!config || !config.restUrl) {
    throw new Error(
      'OXPulse Imager admin: missing REST configuration (window.oxpulseAdmin). ' +
      'The admin script was not localized correctly.'
    );
  }

  return config;
};

/**
 * Parse a fetch() Response into JSON, raising a readable error for
 * non-2xx responses (including WP REST's `{ code, message, data }`
 * error shape and the validation error's `data.errors` field).
 *
 * @param {Response} response
 * @return {Promise<Object>}
 */
export const parseResponse = async (response) => {
  let body = null;

  try {
    body = await response.json();
  } catch (error) {
    body = null;
  }

  if (!response.ok) {
    const message = (body && body.message) || `Request failed with status ${response.status}`;
    const error = new Error(message);
    // Attach validation errors if present (WP_Error with data.errors).
    if (body && body.data && body.data.errors) {
      error.fieldErrors = body.data.errors;
    }
    throw error;
  }

  return body;
};

/**
 * Get plugin options from the WordPress REST API.
 *
 * @return {Promise<Object>} The plugin options (camelCase + secretStatus).
 */
export const getOptions = async () => {
  const { restUrl, nonce } = getConfig();

  try {
    const response = await fetch(restUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': nonce,
      },
    });

    return await parseResponse(response);
  } catch (error) {
    if (error.fieldErrors) {
      throw error;
    }
    throw new Error(error.message || 'Failed to load settings. Please try again.');
  }
};

/**
 * Save plugin options to the WordPress REST API.
 *
 * @param {Object} options The plugin options to save (camelCase).
 * @return {Promise<Object>} The sanitized, persisted plugin options (camelCase).
 */
export const saveOptions = async (options) => {
  const { restUrl, nonce } = getConfig();

  try {
    const response = await fetch(restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      body: JSON.stringify(options),
    });

    return await parseResponse(response);
  } catch (error) {
    if (error.fieldErrors) {
      throw error;
    }
    throw new Error(error.message || 'Failed to save settings. Please try again.');
  }
};
