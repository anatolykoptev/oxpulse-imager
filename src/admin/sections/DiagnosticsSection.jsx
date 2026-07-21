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
import { getConfig, parseResponse } from '@utils/api';
import Card from '@components/ui/Card';
import Button from '@components/ui/Button';
import SelectField from '@components/ui/SelectField';
import StatusPill from '@components/ui/StatusPill';

const DiagnosticsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);
  const [entries, setEntries] = useState([]);
  const [level, setLevel] = useState('off');
  const [loading, setLoading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [error, setError] = useState('');

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
        title={__('Recent log entries', 'oxpulse-imager')}
        description={__('Recent diagnostic entries from the last few requests. Entries are kept for 1 hour. Source URLs are redacted (host + truncated path only).', 'oxpulse-imager')}
      >
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
      </Card>
    </>
  );
};

export default DiagnosticsSection;
