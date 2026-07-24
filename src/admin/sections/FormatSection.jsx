/**
 * OXPulse Imager Admin - Format Section
 *
 * Output format, default quality, per-format quality overrides
 * (AVIF, WebP).
 */

import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import { useLicenseStore } from '@store/useLicenseStore';
import Card from '@components/ui/Card';
import SelectField from '@components/ui/SelectField';
import TextField from '@components/ui/TextField';
import { ProBadge, ProLock } from '@components/ui/ProBadge';

const FormatSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);
  const isPro = useLicenseStore((s) => s.isPro);
  const isGrandfathered = useLicenseStore((s) => s.isGrandfathered);

  // AVIF is Pro-gated (ProFeatures::AVIF). Under free (not Pro, not
  // grandfathered), the AVIF option in the format select is disabled
  // and the AVIF quality field is locked. WebP remains selectable and
  // is the free default. The backend isPro() gate is the real
  // enforcement; this is UX only — never claim AVIF is active under free.
  const avifLocked = !isPro && !isGrandfathered;

  const formatOptions = [
    { value: 'auto', label: __('auto (Accept negotiation)', 'oxpulse-imager') },
    { value: 'avif', label: 'avif', disabled: avifLocked },
    { value: 'webp', label: 'webp' },
    { value: 'jpeg', label: 'jpeg' },
    { value: 'png', label: 'png' },
  ];

  const formatQuality = options.formatQuality || {};

  const handleFormatQuality = (format, value) => {
    const updated = { ...formatQuality };
    if (value === '') {
      delete updated[format];
    } else {
      const num = parseInt(value, 10);
      if (!isNaN(num)) {
        updated[format] = num;
      }
    }
    setOption('formatQuality', updated);
  };

  return (
    <Card title={__('Output format', 'oxpulse-imager')}>
      <SelectField
        name="output_format"
        label={__('Default output format', 'oxpulse-imager')}
        value={options.outputFormat}
        onChange={(v) => setOption('outputFormat', v)}
        options={formatOptions}
        help={__('auto = Accept header negotiation (AVIF/WebP/original based on browser support, requires IMGPROXY_AUTO_AVIF on the server). Explicit format overrides negotiation.', 'oxpulse-imager')}
      />

      <TextField
        name="default_quality"
        type="number"
        label={__('Default quality', 'oxpulse-imager')}
        help={__('1–100. Used when a transform request does not specify quality.', 'oxpulse-imager')}
        value={String(options.defaultQuality)}
        onChange={(v) => setOption('defaultQuality', parseInt(v, 10) || 80)}
        inputClassName="oxp-w-24"
      />

      <div className="oxp-grid oxp-grid-cols-2 oxp-gap-4">
        <div>
          <div className="oxp-mb-1.5 oxp-flex oxp-items-center oxp-gap-1.5">
            <span className="oxp-block oxp-text-sm oxp-font-medium oxp-text-gray-700">
              {__('AVIF quality', 'oxpulse-imager')}
            </span>
            <ProBadge />
          </div>
          <ProLock feature="avif">
            {(locked) => (
              <TextField
                name="format_quality_avif"
                type="number"
                placeholder={__('use default', 'oxpulse-imager')}
                help={__('1–100. Overrides default for AVIF. AVIF looks good at 50-70.', 'oxpulse-imager')}
                value={formatQuality.avif !== undefined ? String(formatQuality.avif) : ''}
                onChange={(v) => handleFormatQuality('avif', v)}
                disabled={locked}
                inputClassName="oxp-w-24"
              />
            )}
          </ProLock>
        </div>
        <TextField
          name="format_quality_webp"
          type="number"
          label={__('WebP quality', 'oxpulse-imager')}
          placeholder={__('use default', 'oxpulse-imager')}
          help={__('1–100. Overrides default for WebP. WebP looks good at 70-85.', 'oxpulse-imager')}
          value={formatQuality.webp !== undefined ? String(formatQuality.webp) : ''}
          onChange={(v) => handleFormatQuality('webp', v)}
          inputClassName="oxp-w-24"
        />
      </div>
    </Card>
  );
};

export default FormatSection;
