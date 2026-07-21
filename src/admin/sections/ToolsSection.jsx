/**
 * OXPulse Imager Admin - Tools Section
 *
 * Health check + AVIF format check. These still use the legacy
 * admin-post endpoints (form POST → redirect) until Phase 5.3
 * moves them to REST endpoints. The forms below submit to
 * admin-post.php with the nonce from window.oxpulseAdmin.
 */

import { useState } from 'react';
import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import Card from '@components/ui/Card';
import Button from '@components/ui/Button';
import TextField from '@components/ui/TextField';

const ToolsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const [healthResult, setHealthResult] = useState(null);
  const [avifResult, setAvifResult] = useState(null);
  const [sampleImage, setSampleImage] = useState('');

  const config = typeof window !== 'undefined' ? window.oxpulseAdmin : {};
  const nonce = config.nonce || '';
  const adminPostUrl = typeof window !== 'undefined'
    ? window.location.origin + '/wp-admin/admin-post.php'
    : '';

  const handleHealthCheck = async () => {
    setHealthResult({ type: 'info', message: __('Checking…', 'oxpulse-imager') });

    try {
      const formData = new FormData();
      formData.append('action', 'oxpulse_imager_test_connection');
      formData.append('oxpulse_imager_nonce', nonce);
      formData.append('oxpulse_imager[endpoint]', options.endpoint);

      const response = await fetch(adminPostUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });

      // admin-post.php redirects on success — we can't follow it from
      // fetch. Instead, just report that the check was dispatched.
      // The real result will show on the next page load (legacy flow).
      setHealthResult({
        type: response.ok ? 'success' : 'error',
        message: response.ok
          ? __('Health check dispatched. Reload to see results.', 'oxpulse-imager')
          : __('Health check failed to dispatch.', 'oxpulse-imager'),
      });
    } catch (error) {
      setHealthResult({
        type: 'error',
        message: error.message || __('Health check failed.', 'oxpulse-imager'),
      });
    }
  };

  const handleAvifCheck = async () => {
    setAvifResult({ type: 'info', message: __('Checking…', 'oxpulse-imager') });

    try {
      const formData = new FormData();
      formData.append('action', 'oxpulse_imager_test_avif');
      formData.append('oxpulse_imager_nonce', nonce);
      formData.append('oxpulse_imager[endpoint]', options.endpoint);
      formData.append('oxpulse_imager[sample_image]', sampleImage);

      const response = await fetch(adminPostUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });

      setAvifResult({
        type: response.ok ? 'success' : 'error',
        message: response.ok
          ? __('AVIF check dispatched. Reload to see results.', 'oxpulse-imager')
          : __('AVIF check failed to dispatch.', 'oxpulse-imager'),
      });
    } catch (error) {
      setAvifResult({
        type: 'error',
        message: error.message || __('AVIF check failed.', 'oxpulse-imager'),
      });
    }
  };

  return (
    <>
      <Card
        title={__('Health check', 'oxpulse-imager')}
        description={__('Verify that the configured imgproxy endpoint is reachable and reports healthy status.', 'oxpulse-imager')}
      >
        <Button onClick={handleHealthCheck} variant="secondary">
          {__('Test connection', 'oxpulse-imager')}
        </Button>
        {healthResult && (
          <p
            className={`oxp-mt-3 oxp-text-sm ${
              healthResult.type === 'success'
                ? 'oxp-text-success'
                : healthResult.type === 'error'
                ? 'oxp-text-danger'
                : 'oxp-text-info'
            }`}
          >
            {healthResult.message}
          </p>
        )}
      </Card>

      <Card
        title={__('AVIF format check', 'oxpulse-imager')}
        description={__('Verify that imgproxy is configured for AVIF format negotiation (IMGPROXY_AUTO_AVIF=true). Sends a request with Accept: image/avif and checks the response Content-Type.', 'oxpulse-imager')}
      >
        <TextField
          name="sample_image"
          type="url"
          label={__('Sample image URL', 'oxpulse-imager')}
          placeholder="https://example.com/wp-content/uploads/test.jpg"
          help={__('A publicly accessible image URL from your allowed sources. If empty, the first allowed source + /oxpulse-avif-test.jpg is used.', 'oxpulse-imager')}
          value={sampleImage}
          onChange={setSampleImage}
        />
        <Button onClick={handleAvifCheck} variant="secondary">
          {__('Test AVIF support', 'oxpulse-imager')}
        </Button>
        {avifResult && (
          <p
            className={`oxp-mt-3 oxp-text-sm ${
              avifResult.type === 'success'
                ? 'oxp-text-success'
                : avifResult.type === 'error'
                ? 'oxp-text-danger'
                : 'oxp-text-info'
            }`}
          >
            {avifResult.message}
          </p>
        )}
      </Card>
    </>
  );
};

export default ToolsSection;
