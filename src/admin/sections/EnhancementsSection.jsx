/**
 * OXPulse Imager Admin - Enhancements Section (Phase 5.1)
 *
 * LQIP placeholders, DPR-aware srcset, watermark — imgproxy-native
 * capabilities that differentiate OXPulse from cloud-based competitors.
 */

import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import Card from '@components/ui/Card';
import ToggleField from '@components/ui/ToggleField';
import TextField from '@components/ui/TextField';
import SelectField from '@components/ui/SelectField';
import { ProBadge, ProLock } from '@components/ui/ProBadge';

const WATERMARK_POSITIONS = [
  { value: 'ce', label: __('Center', 'oxpulse-imager') },
  { value: 'no', label: __('North', 'oxpulse-imager') },
  { value: 'ea', label: __('East', 'oxpulse-imager') },
  { value: 'so', label: __('South', 'oxpulse-imager') },
  { value: 'we', label: __('West', 'oxpulse-imager') },
  { value: 'noea', label: __('North-East', 'oxpulse-imager') },
  { value: 'nowe', label: __('North-West', 'oxpulse-imager') },
  { value: 'soea', label: __('South-East', 'oxpulse-imager') },
  { value: 'sowe', label: __('South-West', 'oxpulse-imager') },
  { value: 're', label: __('Replicate (tile)', 'oxpulse-imager') },
  { value: 'sm', label: __('Smart', 'oxpulse-imager') },
];

const EnhancementsSection = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);

  // dprVariants is an array in the store; the text field shows comma-separated.
  const dprVariantsText = Array.isArray(options.dprVariants)
    ? options.dprVariants.join(',')
    : '';

  const handleDprVariantsChange = (value) => {
    const nums = value
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s !== '')
      .map((s) => parseInt(s, 10))
      .filter((n) => !isNaN(n) && n >= 1 && n <= 8);
    const unique = [...new Set(nums)].sort((a, b) => a - b);
    setOption('dprVariants', unique);
  };

  const watermark = options.watermark;
  const watermarkEnabled = watermark !== null && watermark !== undefined;

  const handleWatermarkToggle = (enabled) => {
    if (enabled) {
      setOption('watermark', {
        enabled: true,
        opacity: 0.5,
        position: 'ce',
        xOffset: 0,
        yOffset: 0,
        scale: 0,
      });
    } else {
      setOption('watermark', null);
    }
  };

  const handleWatermarkField = (field, value) => {
    if (!watermark) return;
    let parsed = value;
    if (field === 'opacity' || field === 'scale') {
      parsed = parseFloat(value) || 0;
    } else if (field === 'xOffset' || field === 'yOffset') {
      parsed = parseInt(value, 10) || 0;
    }
    setOption('watermark', { ...watermark, [field]: parsed });
  };

  return (
    <>
      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('<picture> element', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Wrap eligible content images in a <picture> element with per-format <source> tags (AVIF/WebP) for progressive enhancement. Pro-gated (PICTURE_ELEMENT).', 'oxpulse-imager')}
      >
        <ProLock feature="picture_element">
          {(locked) => (
            <ToggleField
              name="picture_enabled"
              label={__('Wrap images in <picture> with per-format sources', 'oxpulse-imager')}
              help={__('When enabled, content <img> tags are wrapped in <picture><source type="image/avif"><source type="image/webp"><img></picture> for browser-negotiated format delivery.', 'oxpulse-imager')}
              checked={options.pictureEnabled}
              onChange={(v) => setOption('pictureEnabled', v)}
              disabled={locked}
            />
          )}
        </ProLock>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('LQIP placeholders', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Low-quality image placeholders — tiny blurred previews via imgproxy that reduce Cumulative Layout Shift (CLS). Falls back to inline SVG when imgproxy is unreachable. Pro-gated (imgproxy-native).', 'oxpulse-imager')}
      >
        <ProLock feature="imgproxy_delivery">
          {(locked) => (
            <>
              <ToggleField
                name="lqip_enabled"
                label={__('Emit data-placeholder on img tags', 'oxpulse-imager')}
                checked={options.lqipEnabled}
                onChange={(v) => setOption('lqipEnabled', v)}
                disabled={locked}
              />
              {options.lqipEnabled && (
                <TextField
                  name="lqip_blur"
                  type="number"
                  label={__('Blur sigma', 'oxpulse-imager')}
                  help={__('0.1–100. Higher = more blur. 1 is a good default.', 'oxpulse-imager')}
                  value={String(options.lqipBlur)}
                  onChange={(v) => setOption('lqipBlur', parseFloat(v) || 1)}
                  disabled={locked}
                  inputClassName="oxp-w-24"
                />
              )}
            </>
          )}
        </ProLock>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('DPR-aware srcset', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('For img tags with width but no srcset, generates 1x/2x/3x x-descriptor variants via imgproxy dpr: option. Images that already have w-descriptor srcset are left alone. Pro-gated (imgproxy-native).', 'oxpulse-imager')}
      >
        <ProLock feature="imgproxy_delivery">
          {(locked) => (
            <>
              <ToggleField
                name="dpr_enabled"
                label={__('Generate DPR variants for images without srcset', 'oxpulse-imager')}
                checked={options.dprEnabled}
                onChange={(v) => setOption('dprEnabled', v)}
                disabled={locked}
              />
              {options.dprEnabled && (
                <TextField
                  name="dpr_variants"
                  label={__('DPR multipliers', 'oxpulse-imager')}
                  help={__('Comma-separated, 1–8. e.g. 1,2,3 for standard/retina/hyper-retina.', 'oxpulse-imager')}
                  value={dprVariantsText}
                  onChange={handleDprVariantsChange}
                  disabled={locked}
                  inputClassName="oxp-w-32"
                />
              )}
            </>
          )}
        </ProLock>
      </Card>

      <Card
        title={
          <span className="oxp-flex oxp-items-center oxp-gap-1.5">
            {__('Watermark', 'oxpulse-imager')}
            <ProBadge />
          </span>
        }
        description={__('Applies imgproxy native watermark (wm: option). The watermark image is configured server-side via IMGPROXY_WATERMARK_PATH/URL — this setting controls placement only. Pro-gated (imgproxy-native).', 'oxpulse-imager')}
      >
        <ProLock feature="imgproxy_delivery">
          {(locked) => (
            <>
              <ToggleField
                name="watermark_enabled"
                label={__('Apply watermark', 'oxpulse-imager')}
                checked={watermarkEnabled}
                onChange={handleWatermarkToggle}
                disabled={locked}
              />
              {watermarkEnabled && watermark && (
                <div className="oxp-mt-4 oxp-space-y-4">
                  <TextField
                    name="watermark_opacity"
                    type="number"
                    label={__('Opacity', 'oxpulse-imager')}
                    help={__('0 = transparent, 1 = opaque.', 'oxpulse-imager')}
                    value={String(watermark.opacity)}
                    onChange={(v) => handleWatermarkField('opacity', v)}
                    disabled={locked}
                    inputClassName="oxp-w-24"
                  />
                  <SelectField
                    name="watermark_position"
                    label={__('Position', 'oxpulse-imager')}
                    value={watermark.position}
                    onChange={(v) => handleWatermarkField('position', v)}
                    options={WATERMARK_POSITIONS}
                    disabled={locked}
                  />
                  <div className="oxp-grid oxp-grid-cols-2 oxp-gap-4">
                    <TextField
                      name="watermark_x"
                      type="number"
                      label={__('X offset (px)', 'oxpulse-imager')}
                      value={String(watermark.xOffset)}
                      onChange={(v) => handleWatermarkField('xOffset', v)}
                      disabled={locked}
                      inputClassName="oxp-w-24"
                    />
                    <TextField
                      name="watermark_y"
                      type="number"
                      label={__('Y offset (px)', 'oxpulse-imager')}
                      value={String(watermark.yOffset)}
                      onChange={(v) => handleWatermarkField('yOffset', v)}
                      disabled={locked}
                      inputClassName="oxp-w-24"
                    />
                  </div>
                  <TextField
                    name="watermark_scale"
                    type="number"
                    label={__('Scale', 'oxpulse-imager')}
                    help={__('0 = auto-size, 0.1 = 10% of source image. Relative to source dimensions.', 'oxpulse-imager')}
                    value={String(watermark.scale)}
                    onChange={(v) => handleWatermarkField('scale', v)}
                    disabled={locked}
                    inputClassName="oxp-w-24"
                  />
                </div>
              )}
            </>
          )}
        </ProLock>
      </Card>
    </>
  );
};

export default EnhancementsSection;
