/**
 * OXPulse Imager Admin - Diagnostics Section (extended)
 *
 * Shows the diagnostic level setting (from the existing DiagnosticsSection)
 * PLUS recent log entries fetched from GET /oxpulse/v1/diagnostics.
 * Includes a "Clear log" button (DELETE /oxpulse/v1/diagnostics).
 */

import { useState, useEffect, useCallback } from 'react';
import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import { useLicenseStore } from '@store/useLicenseStore';
import { getConfig, parseResponse } from '@utils/api';
import Card from '@components/ui/Card';
import Button from '@components/ui/Button';
import SelectField from '@components/ui/SelectField';
import TextField from '@components/ui/TextField';
import StatusPill from '@components/ui/StatusPill';
import { ProBadge, ProLock } from '@components/ui/ProBadge';

const DiagnosticsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);
  const isPro = useLicenseStore((s) => s.isPro);
  const isGrandfathered = useLicenseStore((s) => s.isGrandfathered);
  const [entries, setEntries] = useState([]);
  const [level, setLevel] = useState('off');
  const [loading, setLoading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [error, setError] = useState('');

  // admin_status (ProFeatures::ADMIN_STATUS): the detailed delivery-status
  // / diagnostics readout is Pro. Under free, the detailed recent-log-entries
  // panel is locked — the operator sees the basic honest status line
  // (rendered by SettingsPage::render() as .oxpulse-delivery-status, mirrors
  // backend FIX 4: "imgproxy" / "local (WebP)" / "passthrough"). The
  // diagnostic LEVEL control is free (it's just logging config); the
  // detailed per-URL entries table is the Pro readout.
  const adminStatusLocked = !isPro && !isGrandfathered;

  const fetchDiagnostics = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const { restUrl, nonce } = getConfig();
      const diagnosticsUrl = restUrl.replace('/options', '/diagnostics');
      const response = await fetch(diagnosticsUrl, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce },
      });
      const data = await parseResponse(response);
      setEntries(data.recentEntries || []);
      setLevel(data.level || 'off');
    } catch (err) {
      setError(err.message || __('Failed to load diagnostics.', 'oxpulse-imager'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchDiagnostics();
  }, [fetchDiagnostics]);

  const handleClear = async () => {
    setClearing(true);
    setError('');
    try {
      const { restUrl, nonce } = getConfig();
      const diagnosticsUrl = restUrl.replace('/options', '/diagnostics');
      const response = await fetch(diagnosticsUrl, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce },
      });
      await parseResponse(response);
      setEntries([]);
    } catch (err) {
      setError(err.message || __('Failed to clear log.', 'oxpulse-imager'));
    } finally {
      setClearing(false);
    }
  };

  const levelOptions = [
    { value: 'off', label: __('Off (silent)', 'oxpulse-imager') },
    { value: 'basic', label: __('Basic (per-request counts)', 'oxpulse-imager') },
    { value: 'verbose', label: __('Verbose (per-URL with reason)', 'oxpulse-imager') },
  ];

  return (
    <>
      <Card
        title={__('Diagnostic level', 'oxpulse-imager')}
        description={__('Controls what gets written to the PHP error log on each page load. Off = no logging. Basic = one summary line per request (counts by context). Verbose = per-URL entries with reason + redacted source URL.', 'oxpulse-imager')}
      >
        <SelectField
          name="diagnostic_level"
          label={__('Level', 'oxpulse-imager')}
          value={options.diagnosticLevel || 'off'}
          onChange={(v) => setOption('diagnosticLevel', v)}
          options={levelOptions}
        />
        <p className="oxp-mt-2 oxp-text-xs oxp-text-gray-500">
          {__('Changes take effect on the next page load. The admin bar item (frontend) shows live counts for the current page.', 'oxpulse-imager')}
        </p>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('Cache size cap', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Maximum disk space the LocalBackend image cache may use before the janitor evicts oldest entries. The janitor runs for everyone (disk safety); only the cap control is Pro. Under free, the default 512 MB cap is enforced.', 'oxpulse-imager')}
      >
        <ProLock feature="cache_management">
          {(locked) => (
            <TextField
              name="cache_max_mb"
              type="number"
              label={__('Cache cap (MB)', 'oxpulse-imager')}
              help={__('0 disables eviction (unlimited). Default 512. The janitor cron runs regardless — this only controls the cap.', 'oxpulse-imager')}
              value={String(options.cacheMaxMb)}
              onChange={(v) => setOption('cacheMaxMb', parseInt(v, 10) || 0)}
              disabled={locked}
              inputClassName="oxp-w-32"
            />
          )}
        </ProLock>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('Recent log entries', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Recent diagnostic entries from the last few requests. Entries are kept for 1 hour. Source URLs are redacted (host + truncated path only). Pro-gated (ADMIN_STATUS).', 'oxpulse-imager')}
      >
        {adminStatusLocked ? (
          <ProLock feature="admin_status">
            <p className="oxp-text-sm oxp-text-gray-500">
              {__('The active delivery status is shown at the top of this page. Detailed per-URL diagnostics are available on Pro.', 'oxpulse-imager')}
            </p>
          </ProLock>
        ) : (
          <>
            <div className="oxp-flex oxp-items-center oxp-gap-3 oxp-mb-4">
              <Button onClick={fetchDiagnostics} variant="secondary" disabled={loading}>
                {loading ? __('Loading…', 'oxpulse-imager') : __('Refresh', 'oxpulse-imager')}
              </Button>
              <Button onClick={handleClear} variant="secondary" disabled={clearing || entries.length === 0}>
                {clearing ? __('Clearing…', 'oxpulse-imager') : __('Clear log', 'oxpulse-imager')}
              </Button>
              <StatusPill status={level === 'off' ? 'neutral' : 'ok'} label={__('level: ', 'oxpulse-imager') + level} />
            </div>

            {error && (
              <p role="alert" className="oxp-mb-3 oxp-text-sm oxp-text-danger">{error}</p>
            )}

            {entries.length === 0 ? (
              <p className="oxp-text-sm oxp-text-gray-500">
                {level === 'off'
                  ? __('Diagnostics are off. Set a level above and save to start logging.', 'oxpulse-imager')
                  : __('No entries yet. Visit a frontend page with images to generate log entries.', 'oxpulse-imager')}
              </p>
            ) : (
              <div className="oxp-max-h-96 oxp-overflow-y-auto oxp-rounded-md oxp-border oxp-border-gray-200">
                <table className="oxp-w-full oxp-text-xs">
                  <thead className="oxp-sticky oxp-top-0 oxp-bg-gray-50">
                    <tr>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">{__('Context', 'oxpulse-imager')}</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">{__('Status', 'oxpulse-imager')}</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">URL</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">W</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">{__('Reason', 'oxpulse-imager')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {entries.map((entry, idx) => (
                      <tr key={idx} className="oxp-border-t oxp-border-gray-100">
                        <td className="oxp-px-3 oxp-py-2 oxp-text-gray-700">{entry.context}</td>
                        <td className="oxp-px-3 oxp-py-2">
                          <span className={entry.rewritten ? 'oxp-text-success' : 'oxp-text-warning'}>
                            {entry.rewritten ? __('rewritten', 'oxpulse-imager') : __('preserved', 'oxpulse-imager')}
                          </span>
                        </td>
                        <td className="oxp-px-3 oxp-py-2 oxp-font-mono oxp-text-gray-500 oxp-break-all">{entry.sourceUrl}</td>
                        <td className="oxp-px-3 oxp-py-2 oxp-text-gray-500">{entry.width || '—'}</td>
                        <td className="oxp-px-3 oxp-py-2 oxp-text-gray-500">{entry.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </Card>
    </>
  );
};

export default DiagnosticsSection;
