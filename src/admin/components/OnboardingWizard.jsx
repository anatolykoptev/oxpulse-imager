/**
 * OXPulse Imager Admin - Onboarding Wizard (Phase 5.5)
 *
 * First-run wizard shown when `options.onboarded === false`. Six steps:
 *   1. Welcome + endpoint URL
 *   2. Signing key + salt (with generate button)
 *   3. Test connection (POST /health with the endpoint)
 *   4. Allowed sources (auto-detect uploads URL)
 *   5. Test AVIF support (POST /avif-check)
 *   6. Enable delivery + finish
 *
 * Each step saves incrementally via the existing POST /options endpoint
 * so a user who quits mid-wizard keeps their progress. The final step
 * sets `onboarded: true`.
 *
 * "Skip for now" dismisses the wizard and sets `onboarded: true` without
 * enabling delivery — for advanced users who want to configure manually.
 */

import { useState, useCallback } from 'react';
import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import { getConfig, parseResponse } from '@utils/api';
import Button from '@components/ui/Button';
import TextField from '@components/ui/TextField';
import Textarea from '@components/ui/Textarea';
import StatusPill from '@components/ui/StatusPill';

const TOTAL_STEPS = 6;

// 32-byte hex string = 64 hex chars. imgproxy accepts hex-encoded keys/salts.
const generateHexSecret = () => {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
};

const StepHeader = ({ step, title, description }) => (
  <div className="oxp-mb-6">
    <div className="oxp-flex oxp-items-center oxp-gap-2 oxp-mb-2">
      {Array.from({ length: TOTAL_STEPS }, (_, i) => (
        <span
          key={i}
          className={
            i + 1 === step
              ? 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-primary'
              : i + 1 < step
                ? 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-success'
                : 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-gray-200'
          }
        />
      ))}
    </div>
    <h2 className="oxp-text-xl oxp-font-semibold oxp-text-gray-900">
      {__('Step', 'oxpulse-imager')} {step}/{TOTAL_STEPS} — {title}
    </h2>
    {description && <p className="oxp-mt-1 oxp-text-sm oxp-text-gray-600">{description}</p>}
  </div>
);

const OnboardingWizard = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);
  const setOptions = useOptionsStore((s) => s.setOptions);
  const saveOptions = useOptionsStore((s) => s.saveOptions);

  const [step, setStep] = useState(1);
  const [isWorking, setIsWorking] = useState(false);
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');

  // Local-only state for secrets (never goes into the persistent options
  // store until saved — matches ConnectionSection's pattern).
  const [key, setKey] = useState('');
  const [salt, setSalt] = useState('');

  // Step 3 + 5 results.
  const [healthResult, setHealthResult] = useState(null);
  const [avifResult, setAvifResult] = useState(null);

  const callRest = useCallback(async (path, method = 'POST', body = null) => {
    const { restUrl, nonce } = getConfig();
    const base = restUrl.replace(/\/options$/, '');
    const response = await fetch(base + path, {
      method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      body: body ? JSON.stringify(body) : null,
    });
    return parseResponse(response);
  }, []);

  const handleSaveAndAdvance = useCallback(async (nextStep) => {
    setError('');
    setIsWorking(true);
    const ok = await saveOptions();
    setIsWorking(false);
    if (ok) {
      setStep(nextStep);
    } else {
      setError(useOptionsStore.getState().error || __('Failed to save settings.', 'oxpulse-imager'));
    }
  }, [saveOptions]);

  // Step 1: endpoint → save → step 2.
  const handleStep1Next = () => {
    if (!options.endpoint || !options.endpoint.startsWith('http')) {
      setError(__('Please enter a valid HTTPS endpoint URL.', 'oxpulse-imager'));
      return;
    }
    handleSaveAndAdvance(2);
  };

  // Step 2: key + salt → save (with secrets) → step 3.
  const handleStep2Next = async () => {
    if (!key || !salt) {
      setError(__('Please enter both key and salt, or generate them.', 'oxpulse-imager'));
      return;
    }
    if (key.length < 32 || salt.length < 32) {
      setError(__('Key and salt must be at least 16 bytes (32 hex characters).', 'oxpulse-imager'));
      return;
    }
    setOptions({ key, salt });
    setError('');
    setIsWorking(true);
    const ok = await saveOptions();
    setIsWorking(false);
    if (ok) {
      setStep(3);
    } else {
      setError(useOptionsStore.getState().error || __('Failed to save secrets.', 'oxpulse-imager'));
    }
  };

  const handleGenerate = () => {
    setKey(generateHexSecret());
    setSalt(generateHexSecret());
    setInfo(__('Generated 32-byte key + salt. You can copy these before continuing — they will not be shown again after save.', 'oxpulse-imager'));
  };

  // Step 3: test connection → step 4.
  const handleStep3Test = async () => {
    setError('');
    setInfo('');
    setIsWorking(true);
    setHealthResult(null);
    try {
      const result = await callRest('/health', 'POST', { endpoint: options.endpoint });
      setHealthResult(result);
      if (!result.ok) {
        setError(__('Health check failed. Verify the endpoint URL and that imgproxy is running.', 'oxpulse-imager'));
      }
    } catch (err) {
      setError(err.message || __('Health check request failed.', 'oxpulse-imager'));
    } finally {
      setIsWorking(false);
    }
  };

  // Step 4: allowed sources → save → step 5.
  const handleStep4Next = () => {
    if (!Array.isArray(options.allowedSources) || options.allowedSources.length === 0) {
      setError(__('Please add at least one allowed source origin.', 'oxpulse-imager'));
      return;
    }
    handleSaveAndAdvance(5);
  };

  const handleAutodetectUploads = () => {
    // The SPA doesn't have direct access to wp_upload_dir(), but the
    // SettingsPage.php localizes `window.oxpulseAdmin.uploadsUrl` for us.
    const uploadsUrl = (typeof window !== 'undefined' && window.oxpulseAdmin?.uploadsUrl) || '';
    if (uploadsUrl) {
      const withSlash = uploadsUrl.endsWith('/') ? uploadsUrl : uploadsUrl + '/';
      const current = Array.isArray(options.allowedSources) ? [...options.allowedSources] : [];
      if (!current.includes(withSlash)) {
        setOption('allowedSources', [...current, withSlash]);
        setInfo(__('Added uploads URL to allowed sources.', 'oxpulse-imager'));
      } else {
        setInfo(__('Uploads URL is already in allowed sources.', 'oxpulse-imager'));
      }
    } else {
      setError(__('Could not auto-detect uploads URL. Please enter it manually.', 'oxpulse-imager'));
    }
  };

  // Step 5: test AVIF → step 6.
  const handleStep5Test = async () => {
    setError('');
    setInfo('');
    setIsWorking(true);
    setAvifResult(null);
    try {
      const result = await callRest('/avif-check', 'POST');
      setAvifResult(result);
      if (!result.supported) {
        setInfo(__('AVIF is not supported by your imgproxy build. The plugin will fall back to WebP. You can continue.', 'oxpulse-imager'));
      }
    } catch (err) {
      setError(err.message || __('AVIF check request failed.', 'oxpulse-imager'));
    } finally {
      setIsWorking(false);
    }
  };

  // Step 6: enable + finish.
  const handleStep6Finish = async () => {
    setError('');
    setOptions({ enabled: true, onboarded: true });
    setIsWorking(true);
    const ok = await saveOptions();
    setIsWorking(false);
    if (!ok) {
      setError(useOptionsStore.getState().error || __('Failed to enable delivery.', 'oxpulse-imager'));
    }
    // On success, the parent App will re-render (options.onboarded === true)
    // and unmount the wizard automatically.
  };

  const handleSkip = async () => {
    setError('');
    setOptions({ onboarded: true });
    setIsWorking(true);
    await saveOptions();
    setIsWorking(false);
  };

  const handleBack = () => {
    setError('');
    setInfo('');
    setStep((s) => Math.max(1, s - 1));
  };

  const allowedSourcesText = Array.isArray(options.allowedSources)
    ? options.allowedSources.join('\n')
    : '';

  const handleAllowedSourcesChange = (value) => {
    const lines = value.split('\n').map((l) => l.trim()).filter((l) => l !== '');
    setOption('allowedSources', lines);
  };

  return (
    <div className="oxp-fixed oxp-inset-0 oxp-z-50 oxp-flex oxp-items-center oxp-justify-center oxp-bg-black/40">
      <div className="oxp-w-full oxp-max-w-2xl oxp-max-h-[90vh] oxp-overflow-y-auto oxp-rounded-lg oxp-bg-white oxp-shadow-xl">
        <div className="oxp-flex oxp-items-center oxp-justify-between oxp-border-b oxp-border-gray-200 oxp-px-6 oxp-py-4">
          <h1 className="oxp-text-lg oxp-font-semibold oxp-text-gray-900">
            {__('Welcome to OXPulse Imager', 'oxpulse-imager')}
          </h1>
          <button
            type="button"
            onClick={handleSkip}
            disabled={isWorking}
            className="oxp-text-sm oxp-text-gray-500 hover:oxp-text-gray-700 disabled:oxp-opacity-50"
          >
            {__('Skip for now', 'oxpulse-imager')}
          </button>
        </div>

        <div className="oxp-px-6 oxp-py-6">
          {step === 1 && (
            <>
              <StepHeader
                step={1}
                title={__('imgproxy endpoint', 'oxpulse-imager')}
                description={__('Enter the base URL of your self-hosted imgproxy instance. HTTPS is required in production.', 'oxpulse-imager')}
              />
              <TextField
                name="endpoint"
                type="url"
                label={__('Endpoint URL', 'oxpulse-imager')}
                placeholder="https://imgproxy.example.com"
                help={__('No trailing slash. Example: https://imgproxy.yourdomain.com', 'oxpulse-imager')}
                value={options.endpoint}
                onChange={(v) => setOption('endpoint', v)}
              />
            </>
          )}

          {step === 2 && (
            <>
              <StepHeader
                step={2}
                title={__('Signing secrets', 'oxpulse-imager')}
                description={__('imgproxy requires a key + salt to sign transformed image URLs. Generate random 32-byte secrets, or paste your own hex-encoded values.', 'oxpulse-imager')}
              />
              <div className="oxp-mb-4">
                <Button onClick={handleGenerate} variant="secondary" disabled={isWorking}>
                  {__('Generate random key + salt', 'oxpulse-imager')}
                </Button>
              </div>
              <TextField
                name="key"
                type="text"
                label={__('Signing key (hex)', 'oxpulse-imager')}
                placeholder={__('64 hex characters (32 bytes)', 'oxpulse-imager')}
                value={key}
                onChange={setKey}
                inputClassName="oxp-font-mono"
              />
              <TextField
                name="salt"
                type="text"
                label={__('Signing salt (hex)', 'oxpulse-imager')}
                placeholder={__('64 hex characters (32 bytes)', 'oxpulse-imager')}
                value={salt}
                onChange={setSalt}
                inputClassName="oxp-font-mono"
              />
              {info && <p className="oxp-mt-2 oxp-text-sm oxp-text-gray-600">{info}</p>}
            </>
          )}

          {step === 3 && (
            <>
              <StepHeader
                step={3}
                title={__('Test connection', 'oxpulse-imager')}
                description={__('Verify that imgproxy is reachable and responding at the endpoint you configured.', 'oxpulse-imager')}
              />
              <p className="oxp-mb-4 oxp-text-sm oxp-text-gray-700">
                <strong>{__('Endpoint:', 'oxpulse-imager')}</strong> {options.endpoint}
              </p>
              <div className="oxp-mb-4">
                <Button onClick={handleStep3Test} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Testing…', 'oxpulse-imager') : __('Test connection', 'oxpulse-imager')}
                </Button>
              </div>
              {healthResult && (
                <div className="oxp-mb-4">
                  <StatusPill
                    status={healthResult.ok ? 'ok' : 'error'}
                    label={healthResult.ok
                      ? __('Connected — imgproxy is responding.', 'oxpulse-imager')
                      : __('Failed — ' + (healthResult.message || 'no response'), 'oxpulse-imager')}
                  />
                </div>
              )}
            </>
          )}

          {step === 4 && (
            <>
              <StepHeader
                step={4}
                title={__('Allowed source origins', 'oxpulse-imager')}
                description={__('Only images whose URL starts with one of these prefixes will be rewritten. Add your wp-content/uploads/ URL.', 'oxpulse-imager')}
              />
              <div className="oxp-mb-4">
                <Button onClick={handleAutodetectUploads} variant="secondary" disabled={isWorking}>
                  {__('Auto-detect uploads URL', 'oxpulse-imager')}
                </Button>
              </div>
              <Textarea
                name="allowed_sources"
                rows={4}
                placeholder="https://example.com/wp-content/uploads/"
                value={allowedSourcesText}
                onChange={handleAllowedSourcesChange}
                inputClassName="oxp-font-mono"
              />
              {info && <p className="oxp-mt-2 oxp-text-sm oxp-text-gray-600">{info}</p>}
            </>
          )}

          {step === 5 && (
            <>
              <StepHeader
                step={5}
                title={__('Test AVIF support', 'oxpulse-imager')}
                description={__('Check whether your imgproxy build supports AVIF output. If not, the plugin falls back to WebP automatically.', 'oxpulse-imager')}
              />
              <div className="oxp-mb-4">
                <Button onClick={handleStep5Test} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Testing…', 'oxpulse-imager') : __('Test AVIF', 'oxpulse-imager')}
                </Button>
              </div>
              {avifResult && (
                <div className="oxp-mb-4">
                  <StatusPill
                    status={avifResult.supported ? 'ok' : 'warning'}
                    label={avifResult.supported
                      ? __('AVIF supported — your imgproxy can serve AVIF.', 'oxpulse-imager')
                      : __('AVIF not supported — will fall back to WebP.', 'oxpulse-imager')}
                  />
                </div>
              )}
              {info && <p className="oxp-mt-2 oxp-text-sm oxp-text-gray-600">{info}</p>}
            </>
          )}

          {step === 6 && (
            <>
              <StepHeader
                step={6}
                title={__('Enable delivery', 'oxpulse-imager')}
                description={__('Everything is configured. Enable delivery to start rewriting image URLs to signed imgproxy URLs on the frontend.', 'oxpulse-imager')}
              />
              <div className="oxp-mb-4 oxp-rounded-md oxp-bg-gray-50 oxp-p-4 oxp-text-sm">
                <p className="oxp-mb-2"><strong>{__('Endpoint:', 'oxpulse-imager')}</strong> {options.endpoint}</p>
                <p className="oxp-mb-2"><strong>{__('Allowed sources:', 'oxpulse-imager')}</strong> {options.allowedSources.length}</p>
                <p className="oxp-mb-2"><strong>{__('AVIF:', 'oxpulse-imager')}</strong> {avifResult?.supported ? __('Supported', 'oxpulse-imager') : __('Not supported (WebP fallback)', 'oxpulse-imager')}</p>
                <p><strong>{__('Delivery:', 'oxpulse-imager')}</strong> {__('Will be enabled on finish', 'oxpulse-imager')}</p>
              </div>
            </>
          )}

          {error && (
            <p role="alert" className="oxp-mt-4 oxp-text-sm oxp-text-danger">{error}</p>
          )}

          <div className="oxp-mt-6 oxp-flex oxp-items-center oxp-justify-between oxp-border-t oxp-border-gray-200 oxp-pt-4">
            <Button onClick={handleBack} variant="secondary" disabled={isWorking || step === 1}>
              {__('Back', 'oxpulse-imager')}
            </Button>
            <div className="oxp-flex oxp-items-center oxp-gap-2">
              {step === 1 && (
                <Button onClick={handleStep1Next} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Saving…', 'oxpulse-imager') : __('Next', 'oxpulse-imager')}
                </Button>
              )}
              {step === 2 && (
                <Button onClick={handleStep2Next} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Saving…', 'oxpulse-imager') : __('Next', 'oxpulse-imager')}
                </Button>
              )}
              {step === 3 && (
                <Button onClick={() => setStep(4)} variant="primary" disabled={isWorking || !healthResult?.ok}>
                  {__('Next', 'oxpulse-imager')}
                </Button>
              )}
              {step === 4 && (
                <Button onClick={handleStep4Next} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Saving…', 'oxpulse-imager') : __('Next', 'oxpulse-imager')}
                </Button>
              )}
              {step === 5 && (
                <Button onClick={() => setStep(6)} variant="primary" disabled={isWorking}>
                  {__('Next', 'oxpulse-imager')}
                </Button>
              )}
              {step === 6 && (
                <Button onClick={handleStep6Finish} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Enabling…', 'oxpulse-imager') : __('Enable delivery', 'oxpulse-imager')}
                </Button>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default OnboardingWizard;
