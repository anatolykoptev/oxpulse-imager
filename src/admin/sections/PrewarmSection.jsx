/**
 * OXPulse Imager Admin - Pre-warm Section
 *
 * Bulk pre-warm imgproxy cache for a batch of source image URLs.
 * Dispatches HEAD requests via POST /oxpulse/v1/prewarm with bounded
 * concurrency (5 parallel). Shows per-URL results + summary counts.
 */

import { useState } from 'react';
import { __ } from '@utils/i18n';
import { prewarm } from '@utils/api-extended';
import Card from '@components/ui/Card';
import Button from '@components/ui/Button';
import Textarea from '@components/ui/Textarea';
import TextField from '@components/ui/TextField';
import StatusPill from '@components/ui/StatusPill';

const PrewarmSection = () => {
  const [urlsText, setUrlsText] = useState('');
  const [widthsText, setWidthsText] = useState('');
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const handlePrewarm = async () => {
    const urls = urlsText
      .split('\n')
      .map((l) => l.trim())
      .filter((l) => l !== '');

    if (urls.length === 0) {
      setError(__('Enter at least one URL.', 'oxpulse-imager'));
      return;
    }

    if (urls.length > 50) {
      setError(__('Maximum 50 URLs per batch.', 'oxpulse-imager'));
      return;
    }

    const widths = widthsText
      .split(',')
      .map((s) => parseInt(s.trim(), 10))
      .filter((n) => !isNaN(n) && n >= 0 && n <= 10000);

    setLoading(true);
    setError('');
    setResult(null);

    try {
      const res = await prewarm(urls, widths.length > 0 ? widths : [0]);
      setResult(res);
    } catch (err) {
      setError(err.message || __('Pre-warm failed.', 'oxpulse-imager'));
    } finally {
      setLoading(false);
    }
  };

  const summaryPill = () => {
    if (!result) return null;
    if (result.failed > 0) {
      return <StatusPill status="error" label={`${result.failed} failed`} />;
    }
    if (result.skipped > 0) {
      return <StatusPill status="warning" label={`${result.skipped} skipped`} />;
    }
    return <StatusPill status="ok" label={`${result.warmed} warmed`} />;
  };

  return (
    <Card
      title={__('Bulk pre-warm', 'oxpulse-imager')}
      description={__('Trigger imgproxy to process + cache a batch of source image URLs NOW, so the first visitor does not pay the processing latency. HEAD requests are dispatched with concurrency=5. Max 50 URLs per batch.', 'oxpulse-imager')}
    >
      <Textarea
        name="prewarm_urls"
        rows={6}
        label={__('Source image URLs', 'oxpulse-imager')}
        placeholder={'https://example.com/wp-content/uploads/photo1.jpg\nhttps://example.com/wp-content/uploads/photo2.jpg'}
        help={__('One URL per line. Only URLs matching your allowed sources will be warmed.', 'oxpulse-imager')}
        value={urlsText}
        onChange={setUrlsText}
        inputClassName="oxp-font-mono"
      />

      <TextField
        name="prewarm_widths"
        label={__('Target widths (optional)', 'oxpulse-imager')}
        placeholder="800,1200,1600"
        help={__('Comma-separated widths in px. Empty = no resize (the default variant). Max 5 widths.', 'oxpulse-imager')}
        value={widthsText}
        onChange={setWidthsText}
        inputClassName="oxp-w-48"
      />

      <div className="oxp-flex oxp-items-center oxp-gap-3">
        <Button onClick={handlePrewarm} disabled={loading}>
          {loading ? __('Warming…', 'oxpulse-imager') : __('Warm cache', 'oxpulse-imager')}
        </Button>
        {summaryPill()}
      </div>

      {error && (
        <p role="alert" className="oxp-mt-3 oxp-text-sm oxp-text-danger">{error}</p>
      )}

      {result && (
        <div className="oxp-mt-4">
          <div className="oxp-mb-3 oxp-flex oxp-gap-4 oxp-text-sm">
            <span className="oxp-text-gray-600">
              {__('Total:', 'oxpulse-imager')} <strong>{result.total}</strong>
            </span>
            <span className="oxp-text-success">
              {__('Warmed:', 'oxpulse-imager')} <strong>{result.warmed}</strong>
            </span>
            <span className="oxp-text-warning">
              {__('Skipped:', 'oxpulse-imager')} <strong>{result.skipped}</strong>
            </span>
            <span className="oxp-text-danger">
              {__('Failed:', 'oxpulse-imager')} <strong>{result.failed}</strong>
            </span>
          </div>

          {result.items.length > 0 && (
            <details className="oxp-rounded-md oxp-border oxp-border-gray-200">
              <summary className="oxp-cursor-pointer oxp-px-3 oxp-py-2 oxp-text-sm oxp-font-medium oxp-text-gray-700">
                {__('Per-URL results', 'oxpulse-imager')}
              </summary>
              <div className="oxp-max-h-96 oxp-overflow-y-auto">
                <table className="oxp-w-full oxp-text-xs">
                  <thead className="oxp-sticky oxp-top-0 oxp-bg-gray-50">
                    <tr>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">URL</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">W</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">Status</th>
                      <th className="oxp-px-3 oxp-py-2 oxp-text-left oxp-font-medium oxp-text-gray-600">Message</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.items.map((item, idx) => (
                      <tr key={idx} className="oxp-border-t oxp-border-gray-100">
                        <td className="oxp-px-3 oxp-py-2 oxp-font-mono oxp-text-gray-700 oxp-break-all">{item.sourceUrl}</td>
                        <td className="oxp-px-3 oxp-py-2 oxp-text-gray-500">{item.width || '—'}</td>
                        <td className="oxp-px-3 oxp-py-2">
                          <span className={
                            item.status === 'warmed' ? 'oxp-text-success' :
                            item.status === 'skipped' ? 'oxp-text-warning' :
                            'oxp-text-danger'
                          }>
                            {item.status}
                          </span>
                        </td>
                        <td className="oxp-px-3 oxp-py-2 oxp-text-gray-500">{item.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          )}
        </div>
      )}
    </Card>
  );
};

export default PrewarmSection;
