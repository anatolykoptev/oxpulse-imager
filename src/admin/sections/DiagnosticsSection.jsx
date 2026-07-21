/**
 * OXPulse Imager Admin - Diagnostics Section
 *
 * Diagnostic logging level, development overrides, cleanup on uninstall.
 */

import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import Card from '@components/ui/Card';
import ToggleField from '@components/ui/ToggleField';
import SelectField from '@components/ui/SelectField';

const DIAGNOSTIC_OPTIONS = [
  { value: 'off', label: 'off (silent)' },
  { value: 'basic', label: 'basic (rewrite/preserve counts)' },
  { value: 'verbose', label: 'verbose (each URL with reason)' },
];

const DiagnosticsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);

  return (
    <>
      <Card title={__('Diagnostic logging', 'oxpulse-imager')}>
        <SelectField
          name="diagnostic_level"
          label={__('Level', 'oxpulse-imager')}
          value={options.diagnosticLevel}
          onChange={(v) => setOption('diagnosticLevel', v)}
          options={DIAGNOSTIC_OPTIONS}
          help={__('Logs go to PHP error log via error_log(). off = silent. basic = log rewrite/preserve counts per request. verbose = log each URL with reason.', 'oxpulse-imager')}
        />
      </Card>

      <Card title={__('Development overrides', 'oxpulse-imager')}>
        <ToggleField
          name="dev_http_override"
          label={__('Allow plain HTTP imgproxy endpoint', 'oxpulse-imager')}
          help={__('Local development only. Never enable in production — signed URLs over HTTP leak the signing key.', 'oxpulse-imager')}
          checked={options.devHttpOverride}
          onChange={(v) => setOption('devHttpOverride', v)}
        />
      </Card>

      <Card title={__('Cleanup', 'oxpulse-imager')}>
        <ToggleField
          name="remove_on_uninstall"
          label={__('Remove all plugin data on uninstall', 'oxpulse-imager')}
          help={__('When enabled, deleting the plugin via Plugins > Installed Plugins will delete all OXPulse Imager options from the database. Off by default — keeps settings across re-installs.', 'oxpulse-imager')}
          checked={options.removeOnUninstall}
          onChange={(v) => setOption('removeOnUninstall', v)}
        />
      </Card>
    </>
  );
};

export default DiagnosticsSection;
