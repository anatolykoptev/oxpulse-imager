/**
 * OXPulse Imager Admin - Tools Section
 *
 * Health check + AVIF format check. Calls the REST API directly
 * (POST /oxpulse/v1/health, POST /oxpulse/v1/avif-check) — no
 * admin-post form POST + redirect dance.
 */

import { useState } from 'react';
import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import { checkHealth, checkAvif } from '@utils/api-extended';
import Card from '@components/ui/Card';
import Button from '@components/ui/Button';
import TextField from '@components/ui/TextField';

const ToolsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const [healthResult, setHealthResult] = useState(null);
  const [healthLoading, setHealthLoading] = useState(false);
  const [avifResult, setAvifResult] = useState(null);
  const [avifLoading, setAvifLoading] = useState(false);
  const [sampleImage, setSampleImage] = useState('');

  const handleHealthCheck = async () => {
    setHealthLoading(true);
    setHealthResult(null);
    try {
      const result = await checkHealth(options.endpoint);
      setHealthResult(result);
    } catch (error) {
      setHealthResult({ ok: false, status: 'error', message: error.message });
    } finally {
      setHealthLoading(false);
    }
  };

  const handleAvifCheck = async () => {
    setAvifLoading(true);
    setAvifResult(null);
    try {
      const result = await checkAvif(options.endpoint, sampleImage);
      setAvifResult(result);
    } catch (error) {
      setAvifResult({ ok: false, status: 'error', message: error.message });
    } finally {
      setAvifLoading(false);
    }
  };

  const renderResult = (result) => {
    if (!result) return null;
    const color = result.ok
      ? 'oxp-text-success'
      : result.status === 'unreachable'
      ? 'oxp-text-danger'
      : 'oxp-text-warning';
    return (
      <p className={`oxp-mt-3 oxp-text-sm ${color}`}>
        <strong>{result.status}:</strong> {result.message}
      </p>
    );
  };

  return (
    <>
      <Card
        title={__('Health check', 'oxpulse-imager')}
        description={__('Verify that the configured imgproxy endpoint is reachable and reports healthy status.', 'oxpulse-imager')}
      >
        <Button onClick={handleHealthCheck} variant="secondary" disabled={healthLoading}>
          {healthLoading ? __('Checking…', 'oxpulse-imager') : __('Test connection', 'oxpulse-imager')}
        </Button>
        {renderResult(healthResult)}
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
        <Button onClick={handleAvifCheck} variant="secondary" disabled={avifLoading}>
          {avifLoading ? __('Checking…', 'oxpulse-imager') : __('Test AVIF support', 'oxpulse-imager')}
        </Button>
        {renderResult(avifResult)}
      </Card>
    </>
  );
};

export default ToolsSection;
