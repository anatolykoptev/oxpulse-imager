/**
 * OXPulse Imager Admin - Connection Section
 *
 * Enable delivery, imgproxy endpoint, signing key/salt, allowed sources.
 * Secrets are NEVER displayed — only a status pill (configured / partial
 * / empty). Key/salt fields are always empty; submitting empty means
 * "keep existing secrets".
 */

import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import Card from '@components/ui/Card';
import ToggleField from '@components/ui/ToggleField';
import TextField from '@components/ui/TextField';
import Textarea from '@components/ui/Textarea';
import StatusPill from '@components/ui/StatusPill';
import { ProBadge, ProLock } from '@components/ui/ProBadge';

const ConnectionSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);

  const secretLabel = {
    configured: __('Secrets configured. Values are hidden for security.', 'oxpulse-imager'),
    partial: __('Partial secrets detected. Please set both key and salt.', 'oxpulse-imager'),
    empty: __('No secrets configured. Generate a key and salt to enable signed URL delivery.', 'oxpulse-imager'),
  };

  const secretStatus = {
    configured: 'ok',
    partial: 'warning',
    empty: 'empty',
  };

  // allowedSources is an array in the store; the textarea shows one per line.
  const allowedSourcesText = Array.isArray(options.allowedSources)
    ? options.allowedSources.join('\n')
    : '';

  const handleAllowedSourcesChange = (value) => {
    const lines = value.split('\n').map((l) => l.trim()).filter((l) => l !== '');
    setOption('allowedSources', lines);
  };

  return (
    <>
      <Card title={__('Delivery', 'oxpulse-imager')}>
        <ToggleField
          name="enabled"
          label={__('Enable delivery', 'oxpulse-imager')}
          help={__('Rewrite approved image URLs to signed imgproxy URLs. When disabled, the plugin is a complete no-op on the frontend.', 'oxpulse-imager')}
          checked={options.enabled}
          onChange={(v) => setOption('enabled', v)}
        />
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('imgproxy endpoint', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
      >
        <ProLock feature="imgproxy_delivery">
          {(locked) => (
            <TextField
              name="endpoint"
              type="url"
              label={__('Endpoint URL', 'oxpulse-imager')}
              placeholder="https://imgproxy.example.com"
              help={__('Base URL of your self-hosted imgproxy instance. HTTPS required in production.', 'oxpulse-imager')}
              value={options.endpoint}
              onChange={(v) => setOption('endpoint', v)}
              disabled={locked}
            />
          )}
        </ProLock>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('Signing secrets', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Hex-encoded imgproxy key + salt. Minimum 16 bytes after decoding. Never displayed after save — leave empty to keep existing.', 'oxpulse-imager')}
      >
        <div className="oxp-mb-4">
          <StatusPill
            status={secretStatus[options.secretStatus] || 'empty'}
            label={secretLabel[options.secretStatus] || secretLabel.empty}
          />
        </div>
        <ProLock feature="imgproxy_delivery">
          {(locked) => (
            <>
              <TextField
                name="key"
                type="password"
                label={__('Signing key (hex)', 'oxpulse-imager')}
                placeholder={__('Leave empty to keep existing', 'oxpulse-imager')}
                value=""
                onChange={(v) => setOption('key', v)}
                disabled={locked}
                inputClassName="oxp-font-mono"
              />
              <TextField
                name="salt"
                type="password"
                label={__('Signing salt (hex)', 'oxpulse-imager')}
                placeholder={__('Leave empty to keep existing', 'oxpulse-imager')}
                value=""
                onChange={(v) => setOption('salt', v)}
                disabled={locked}
                inputClassName="oxp-font-mono"
              />
            </>
          )}
        </ProLock>
      </Card>

      <Card
        title={__('Allowed source origins', 'oxpulse-imager')}
        description={__('One URL prefix per line. Only images whose URL starts with one of these prefixes will be rewritten. A trailing slash enforces a path boundary.', 'oxpulse-imager')}
      >
        <Textarea
          name="allowed_sources"
          rows={4}
          placeholder="https://example.com/wp-content/uploads/"
          value={allowedSourcesText}
          onChange={handleAllowedSourcesChange}
          inputClassName="oxp-font-mono"
        />
      </Card>
    </>
  );
};

export default ConnectionSection;
