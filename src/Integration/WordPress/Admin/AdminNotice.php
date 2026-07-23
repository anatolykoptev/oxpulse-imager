<?php
/**
 * Admin notice renderer for the rewrite-capability fallback state.
 *
 * #43 Phase 5 (plan D.5 / E.1 steps 9 + 11 / G adjustment #2):
 * tells the operator what is happening when the host cannot serve
 * clean .webp cache URLs (nginx / AllowOverride None / LiteSpeed /
 * probe inconclusive) and lets them fix or upgrade — with the
 * CONCRETE perf cost quantified, not just "faster".
 *
 * Rendering rules:
 *  - Hooked on `admin_notices`; renders ONLY when is_admin() &&
 *    current_user_can('manage_options').
 *  - Reads the cached capability (via OptionSettingsRepository).
 *    Renders a notice ONLY when LocalBackend is active (no imgproxy
 *    endpoint — DeliveryConfig::isLocalBackendActive()) AND the
 *    capability is NOT 'yes' (i.e. 'no' or unset/'unknown' → the
 *    site is on the ?k= fallback or unverified).
 *  - Branches the message on the environment:
 *      nginx   → dismissable WARNING + the exact nginx try_files
 *                snippet INLINE (paths derived for THIS site) +
 *                perf quantification + "Re-test capability" button.
 *      apache  → dismissable warning (AllowOverride advice when the
 *                probe is 'no'; info "probe inconclusive" otherwise)
 *                + "Re-test capability" button.
 *      litespeed / unknown → dismissable info + "Re-test" button.
 *  - Co-install notice (step 11): when a competing WebP/optimization
 *    plugin is active, a dismissable INFO notice recommending the
 *    operator pick one delivery layer. Non-blocking.
 *  - Dismissal persists per-notice-key, keyed on the capability
 *    state at dismiss time so a capability flip re-surfaces the
 *    notice. Dismissed via the /oxpulse/v1/capability/dismiss REST
 *    endpoint (nonce + manage_options).
 *  - All output is escaped (esc_html / esc_attr / wp_kses_post for
 *    the structured HTML) and i18n'd via __() with translator
 *    comments where placeholders are used.
 *
 * The "Re-test" button is a tiny inline vanilla-JS fetch() to
 * POST /oxpulse/v1/capability/reprobe (no React dependency) — the
 * admin SPA build is NOT required for this button to work.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

final class AdminNotice
{
    /**
     * Competing WebP/optimization plugins (slug → label). Detected via
     * is_plugin_active() (guarded — wp-admin/includes/plugin.php is
     * loaded in admin context). Non-blocking: an operator may
     * legitimately run two delivery layers.
     */
    private const COMPETING_PLUGINS = [
        'webp-express/webp-express.php'                       => 'WebP Express',
        'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
        'shortpixel-image-optimiser/wp-shortpixel.php'       => 'ShortPixel',
        'ewww-image-optimizer/ewww-image-optimizer.php'       => 'EWWW Image Optimizer',
        'optimole-wp/optimole-wp.php'                         => 'Optimole',
    ];

    /**
     * Notice-key prefix for co-install notices. The dismiss state for
     * these keys is capability-INDEPENDENT (the notice is about a
     * competing plugin being present, not about the rewrite
     * capability), so it is stored against the fixed
     * NOTICE_STATE_ACTIVE marker. Capability-notice keys
     * ('capability_*') keep their dismissal keyed on the live
     * capability state so a capability flip re-surfaces them.
     */
    public const COINSTALL_KEY_PREFIX = 'coinstall_';

    /**
     * Fixed dismiss-state marker for capability-independent notices
     * (co-install). Referenced by BOTH the render gate and the dismiss
     * handler via noticeDismissState() so the two ends cannot drift.
     */
    public const NOTICE_STATE_ACTIVE = 'active';

    /**
     * #87: notice key for the multisite LocalBackend-unsupported notice.
     * Capability-independent (the notice is about the install being
     * multisite, not the rewrite capability), so its dismissal is stored
     * against NOTICE_STATE_ACTIVE — mirroring the co-install keyed-dismiss
     * pattern.
     */
    public const MULTISITE_KEY = 'multisite_local_unsupported';

    /**
     * #90: notice key for the "no image encoder available" warning.
     */
    public const NO_ENCODER_KEY = 'no_encoder';

    /**
     * #90: notice key for the "configured imgproxy endpoint is down" warning.
     */
    public const IMGPROXY_DOWN_KEY = 'imgproxy_down';

    /**
     * #92: notice key for the "background pre-warm blocked because
     * WP-Cron is disabled" warning. Capability-independent (the notice
     * is about the host's cron config, not the rewrite capability), so
     * its dismissal is stored against NOTICE_STATE_ACTIVE — mirroring
     * the co-install / multisite / no-encoder keyed-dismiss pattern.
     * Re-surfaces when a new blocked attempt re-sets the transient flag
     * after the 1h TTL expires.
     */
    public const CRON_DISABLED_KEY = 'prewarm_cron_disabled';

    /**
     * #92: transient flag set by PrewarmRestController when an async
     * pre-warm is attempted while WP-Cron is disabled. The notice
     * renders only while this flag is live (1h TTL), so it surfaces
     * the limitation right after a blocked attempt rather than
     * permanently nagging every admin page load.
     */
    public const CRON_BLOCKED_TRANSIENT = 'oxpulse_prewarm_cron_blocked';

    private OptionSettingsRepository $repository;
    private ImageTransformer $imageTransformer;
    private ImgproxyHealthCache $imgproxyHealthCache;

    /**
     * Tracks whether the inline JS has been emitted once per request
     * so multiple notices share a single <script> block.
     */
    private static bool $scriptEmitted = false;

    public function __construct(
        ?OptionSettingsRepository $repository = null,
        ?ImageTransformer $imageTransformer = null,
        ?ImgproxyHealthCache $imgproxyHealthCache = null,
    ) {
        $this->repository = $repository ?? new OptionSettingsRepository();
        $this->imageTransformer = $imageTransformer ?? new ImageTransformer();
        $this->imgproxyHealthCache = $imgproxyHealthCache ?? new ImgproxyHealthCache();
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /**
     * Resolve the dismiss-state value a notice key must be stored
     * against (and checked against) so the dismiss side and the render
     * gate agree. Used by BOTH render() (the gate) and
     * CapabilityRestController::handleDismiss() (the store) — the
     * single source of truth that prevents the co-install "never
     * dismissable" bug (#57 review MAJOR).
     *
     *  - Co-install keys (COINSTALL_KEY_PREFIX) → NOTICE_STATE_ACTIVE
     *    (capability-independent; stays dismissed while the same
     *    plugin set persists, re-notifies on a set change via a new
     *    key).
     *  - Capability keys → the live rewrite capability
     *    ('yes'|'no'|'unknown'), so a capability flip re-surfaces the
     *    notice.
     */
    public static function noticeDismissState(string $noticeKey, OptionSettingsRepository $repository): string
    {
        if (str_starts_with($noticeKey, self::COINSTALL_KEY_PREFIX)) {
            return self::NOTICE_STATE_ACTIVE;
        }
        // #87: the multisite notice is capability-independent — dismiss
        // against the fixed ACTIVE marker (same shape as co-install).
        if ($noticeKey === self::MULTISITE_KEY) {
            return self::NOTICE_STATE_ACTIVE;
        }
        // #90: the no-encoder notice is capability-independent (it is about
        // the host's encoder extensions, not the rewrite capability).
        if ($noticeKey === self::NO_ENCODER_KEY) {
            return self::NOTICE_STATE_ACTIVE;
        }
        // #90: the imgproxy-down notice is keyed on the live health state
        // so the render gate can auto-clear the dismissal when health
        // recovers to 'up'.
        if ($noticeKey === self::IMGPROXY_DOWN_KEY) {
            return 'down';
        }
        // #92: the cron-disabled notice is capability-independent (it is
        // about the host's WP-Cron config, not the rewrite capability).
        if ($noticeKey === self::CRON_DISABLED_KEY) {
            return self::NOTICE_STATE_ACTIVE;
        }
        return $repository->loadRewriteCapability();
    }

    /**
     * Render the admin notices. Guarded to admin + manage_options;
     * a no-op otherwise (and when LocalBackend is not active or the
     * capability is 'yes').
     */
    public function render(): void
    {
        if (!function_exists('is_admin') || !is_admin()) {
            return;
        }
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        // #92: the cron-disabled notice is independent of the delivery
        // backend (it is about the host's WP-Cron config, not LocalBackend
        // vs imgproxy), so it is evaluated BEFORE the delivery branching
        // and can render in any backend context. Gated on the transient
        // flag set by PrewarmRestController when a blocked attempt occurs.
        $this->maybeRenderCronDisabledNotice();

        $delivery = $this->repository->loadDeliveryConfig();

        // #87: multisite + LocalBackend-active (endpoint empty → LocalBackend
        // would be the tier) → render the multisite-unsupported notice and
        // skip the capability/encoder notice (LocalBackend won't run on
        // multisite). The co-install notice still applies (competing plugins
        // are relevant regardless of the delivery backend).
        if (function_exists('is_multisite') && is_multisite() && $delivery->isLocalBackendActive()) {
            $this->maybeRenderMultisiteNotice();
            $this->maybeRenderCoInstallNotice();
            return;
        }

        if (!$delivery->isLocalBackendActive()) {
            // Imgproxy backend — the capability/encoder notices are irrelevant.
            // Render imgproxy-down warning if health is 'down', then the
            // co-install notice (it applies regardless of delivery backend).
            $this->maybeRenderImgproxyDownNotice();
            $this->maybeRenderCoInstallNotice();
            return;
        }

        // #90: LocalBackend needs a local encoder. Warn when neither WebP
        // nor AVIF encoding is available (independent of rewrite capability).
        $this->maybeRenderNoEncoderNotice();

        $capability = $this->repository->loadRewriteCapabilityOrNull();
        // Render ONLY when capability is NOT 'yes' ('no' or null/unknown).
        if ($capability === 'yes') {
            $this->maybeRenderCoInstallNotice();
            return;
        }

        $env = $this->detectEnvironment();
        $notice = $this->buildCapabilityNotice($capability ?? 'unknown', $env);
        if ($notice !== null) {
            $stateForDismiss = self::noticeDismissState($notice['key'], $this->repository);
            if (!$this->repository->isNoticeDismissed($notice['key'], $stateForDismiss)) {
                echo wp_kses_post($notice['html']);
                $this->emitInlineScript();
            }
        }

        $this->maybeRenderCoInstallNotice();
    }

    /**
     * Build the capability-fallback notice HTML for the given
     * environment + capability state. Returns null when no notice
     * applies (capability 'yes' — guarded by the caller).
     *
     * Returns the raw HTML (pre-wp_kses); render() escapes it. Tests
     * assert on this string for content (snippet form, perf numbers,
     * LCP framing).
     *
     * @param string $capability 'yes'|'no'|'unknown'
     * @param string $env        'nginx'|'apache'|'litespeed'|'unknown'
     * @return array{key:string,class:string,html:string}|null
     */
    public function buildCapabilityNotice(string $capability, string $env): ?array
    {
        if ($capability === 'yes') {
            return null;
        }

        $restUrl  = rest_url('oxpulse/v1/capability/reprobe');
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce    = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        switch ($env) {
            case 'nginx':
                return $this->buildNginxNotice($capability, $restUrl, $dismissUrl, $nonce);
            case 'apache':
                return $this->buildApacheNotice($capability, $restUrl, $dismissUrl, $nonce);
            case 'litespeed':
                return $this->buildLitespeedNotice($capability, $restUrl, $dismissUrl, $nonce);
            default:
                return $this->buildUnknownNotice($capability, $restUrl, $dismissUrl, $nonce);
        }
    }

    /**
     * Detect the server environment from SERVER_SOFTWARE.
     *
     * @return string 'nginx'|'apache'|'litespeed'|'unknown'
     */
    public function detectEnvironment(): string
    {
        $server = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (!is_string($server) || $server === '') {
            return 'unknown';
        }
        if (stripos($server, 'nginx') !== false) {
            return 'nginx';
        }
        if (stripos($server, 'litespeed') !== false) {
            return 'litespeed';
        }
        if (stripos($server, 'apache') !== false) {
            return 'apache';
        }
        return 'unknown';
    }

    /**
     * Build the nginx snippet for THIS site — paths derived from
     * home_url() so a subdirectory install gets a correct snippet.
     * Uses `[0-9a-f]+` (NOT a `{16}` quantifier — unquoted `{` breaks
     * nginx config-load, bug #40). Mirrors the README nginx section +
     * HtaccessGenerator.php semantics.
     *
     * @return string The raw nginx config snippet (no HTML wrapping).
     */
    public function buildNginxSnippet(): string
    {
        $cachePath = rtrim((string) parse_url(home_url('/wp-content/cache/oxpulse/'), PHP_URL_PATH), '/');
        if ($cachePath === '') {
            $cachePath = '/wp-content/cache/oxpulse';
        }
        $endpointPath = rtrim((string) parse_url(home_url('/wp-content/oxpulse-img.php'), PHP_URL_PATH), '/');

        return "# Belt-and-braces: never execute scripts inside the cache dir.
location ~* ^{$cachePath}/.*\.(php|phtml)$ { deny all; }

# Serve existing cache files directly; on miss, route to the endpoint
# with the key extracted from the filename.
location ~* ^{$cachePath}/([0-9a-f]+)/(.+)\.(webp|avif)$ {
    add_header Vary Accept;
    try_files \$uri {$endpointPath}?k=\$2;
}";
    }

    /**
     * The perf-quantification copy (G adjustment #2 — MANDATORY).
     * States the concrete cost of the ?k= PHP fallback vs static
     * serving + the LCP/Core Web Vitals framing.
     */
    public function perfCopy(): string
    {
        return __(
            'The PHP fallback runs PHP for every image (~50-200ms TTFB per image) versus ~5-20ms for static file serving. On an image-heavy page this compounds and can push Largest Contentful Paint past Google\'s 2.5s "good" threshold when an image is the LCP element — the difference between meeting and missing Core Web Vitals, not merely cosmetic.',
            'oxpulse-imager'
        );
    }

    /**
     * Detect active competing WebP/optimization plugins.
     *
     * @return array<string,string> slug => label, for active plugins.
     */
    public function detectCompetingPlugins(): array
    {
        if (!function_exists('is_plugin_active')) {
            return [];
        }
        $active = [];
        foreach (self::COMPETING_PLUGINS as $slug => $label) {
            if (is_plugin_active($slug)) {
                $active[$slug] = $label;
            }
        }
        return $active;
    }

    /**
     * Build the co-install notice HTML (or null when no competitor
     * is active). Dismissable, keyed per detected plugin set so a
     * newly-installed competitor re-notifies.
     *
     * @return array{key:string,class:string,html:string}|null
     */
    public function buildCoInstallNotice(): ?array
    {
        $active = $this->detectCompetingPlugins();
        if ($active === []) {
            return null;
        }
        $setHash = md5(implode(',', array_keys($active)));
        $key = self::COINSTALL_KEY_PREFIX . $setHash;
        // Labels are escaped together with $body at the esc_html() below;
        // an inner array_map('esc_html', …) here would double-escape.
        $labels = implode(', ', $active);
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        $body = sprintf(
            /* translators: %s: list of detected competing plugin names. */
            __('Another image-optimization plugin is active (%s). Running two delivery layers can double-rewrite images — pick one to avoid conflicts.', 'oxpulse-imager'),
            $labels
        );

        $html = '<div class="notice notice-info is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr($key) . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p>' . esc_html($body) . '</p>'
            . '</div>';

        return ['key' => $key, 'class' => 'info', 'html' => $html];
    }

    /**
     * #87: Build the multisite LocalBackend-unsupported notice. Mirrors
     * buildLitespeedNotice() — a dismissable info notice — but WITHOUT a
     * "Re-test capability" button (the fix is "configure an imgproxy
     * endpoint", not a re-probe). Keyed MULTISITE_KEY, capability-
     * independent dismissal (NOTICE_STATE_ACTIVE, like co-install).
     *
     * @return array{key:string,class:string,html:string}
     */
    public function buildMultisiteNotice(): array
    {
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        $heading = __(
            'OXPulse Imager: LocalBackend delivery is not supported on WordPress Multisite. Configure an imgproxy endpoint to enable optimized delivery.',
            'oxpulse-imager'
        );

        $html = '<div class="notice notice-info is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr(self::MULTISITE_KEY) . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '</div>';

        return ['key' => self::MULTISITE_KEY, 'class' => 'info', 'html' => $html];
    }

    /**
     * #90: Build the no-encoder notice. Warns when LocalBackend is active
     * but the host cannot encode WebP or AVIF, so images are served
     * unoptimized. Dismissable, keyed NO_ENCODER_KEY.
     *
     * @return array{key:string,class:string,html:string}
     */
    public function buildNoEncoderNotice(): array
    {
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        $heading = __(
            'OXPulse Imager: no image encoder (Imagick or GD with WebP/AVIF support) is available on this server, so images are served unoptimized. Ask your host to enable the Imagick or GD PHP extension.',
            'oxpulse-imager'
        );

        $html = '<div class="notice notice-warning is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr(self::NO_ENCODER_KEY) . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '</div>';

        return ['key' => self::NO_ENCODER_KEY, 'class' => 'warning', 'html' => $html];
    }

    /**
     * #90: Build the imgproxy-unreachable notice. Warns when an imgproxy
     * endpoint is configured but the cached health is 'down'. Dismissable,
     * keyed IMGPROXY_DOWN_KEY, but the dismissal is auto-cleared when health
     * returns to 'up' so a later outage re-notifies.
     *
     * @return array{key:string,class:string,html:string}
     */
    public function buildImgproxyDownNotice(): array
    {
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        $heading = __(
            'OXPulse Imager: the configured imgproxy endpoint is currently unreachable; original images are being served until it recovers.',
            'oxpulse-imager'
        );

        $html = '<div class="notice notice-warning is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr(self::IMGPROXY_DOWN_KEY) . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '</div>';

        return ['key' => self::IMGPROXY_DOWN_KEY, 'class' => 'warning', 'html' => $html];
    }

    /**
     * #92: Build the "background pre-warm blocked because WP-Cron is
     * disabled" notice. Dismissable warning, keyed CRON_DISABLED_KEY
     * (capability-independent dismissal via NOTICE_STATE_ACTIVE, like
     * co-install / multisite / no-encoder). Points the operator at the
     * two working alternatives: the `wp oxpulse warm` WP-CLI command
     * (synchronous, no WP-Cron needed) or a real system cron hitting
     * wp-cron.php. Renders only while the CRON_BLOCKED_TRANSIENT flag
     * is live (set on a blocked attempt, 1h TTL) so it surfaces right
     * after the attempt rather than permanently nagging.
     *
     * @return array{key:string,class:string,html:string}
     */
    public function buildCronDisabledNotice(): array
    {
        $dismissUrl = rest_url('oxpulse/v1/capability/dismiss');
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        $heading = __(
            'OXPulse Imager: background pre-warm requires WP-Cron, which is disabled on this site (DISABLE_WP_CRON). Run it via WP-CLI (`wp oxpulse warm`) or enable a system cron hitting wp-cron.php.',
            'oxpulse-imager'
        );

        $html = '<div class="notice notice-warning is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr(self::CRON_DISABLED_KEY) . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '</div>';

        return ['key' => self::CRON_DISABLED_KEY, 'class' => 'warning', 'html' => $html];
    }

    /**
     * #90: Render the no-encoder notice when LocalBackend is active and the
     * host can encode neither webp nor avif.
     */
    private function maybeRenderNoEncoderNotice(): void
    {
        if ($this->imageTransformer->supportsWebp() || $this->imageTransformer->supportsAvif()) {
            return;
        }
        $notice = $this->buildNoEncoderNotice();
        $stateForDismiss = self::noticeDismissState($notice['key'], $this->repository);
        if (!$this->repository->isNoticeDismissed($notice['key'], $stateForDismiss)) {
            echo wp_kses_post($notice['html']);
            $this->emitInlineScript();
        }
    }

    /**
     * #90: Render the imgproxy-down notice when an endpoint is configured
     * and the cached health is 'down'. Auto-clears any stored dismissal
     * when health recovers to 'up' so a later outage re-notifies.
     */
    private function maybeRenderImgproxyDownNotice(): void
    {
        if ($this->imgproxyHealthCache->read() === 'down') {
            $notice = $this->buildImgproxyDownNotice();
            $stateForDismiss = self::noticeDismissState($notice['key'], $this->repository);
            if (!$this->repository->isNoticeDismissed($notice['key'], $stateForDismiss)) {
                echo wp_kses_post($notice['html']);
                $this->emitInlineScript();
            }
            return;
        }

        // Health recovered (or was never down) — clear any imgproxy-down
        // dismissal so the next outage re-notifies.
        $this->clearImgproxyDownDismissal();
    }

    /**
     * #90: Remove a stored imgproxy-down dismissal. Called by the render
     * path when ImgproxyHealthCache::read() is no longer 'down'.
     */
    private function clearImgproxyDownDismissal(): void
    {
        $dismissed = $this->repository->loadDismissedNotices();
        if (isset($dismissed[self::IMGPROXY_DOWN_KEY])) {
            unset($dismissed[self::IMGPROXY_DOWN_KEY]);
            update_option(OptionSettingsRepository::OPTION_ADMIN_NOTICE_DISMISSED, $dismissed);
        }
    }

    // ─── environment-specific notice builders ────────────────────────

    private function buildNginxNotice(string $capability, string $restUrl, string $dismissUrl, string $nonce): array
    {
        $snippet = $this->buildNginxSnippet();
        $perf = esc_html($this->perfCopy());

        $heading = __(
            'OXPulse Imager is serving optimized images via a PHP fallback. For best performance, add this nginx rule to your server block and reload nginx:',
            'oxpulse-imager'
        );

        $html = '<div class="notice notice-warning is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr('capability_nginx') . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '<pre class="oxpulse-nginx-snippet">' . esc_html($snippet) . '</pre>'
            . '<p>' . $perf . '</p>'
            . '<p><button type="button" class="button button-secondary oxpulse-retest-btn" '
            . 'data-oxpulse-retest-url="' . esc_url($restUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '" '
            . 'data-oxpulse-testing-label="' . esc_attr__('Testing…', 'oxpulse-imager') . '">'
            . esc_html__('Re-test capability', 'oxpulse-imager')
            . '</button></p>'
            . '</div>';

        return ['key' => 'capability_nginx', 'class' => 'warning', 'html' => $html];
    }

    private function buildApacheNotice(string $capability, string $restUrl, string $dismissUrl, string $nonce): array
    {
        if ($capability === 'no') {
            $heading = __(
                'OXPulse Imager images work via the PHP fallback. For faster static delivery, ask your host to enable AllowOverride All for the cache directory (wp-content/cache/oxpulse/) — or it becomes automatic once the rewrite probe can run.',
                'oxpulse-imager'
            );
            $class = 'warning';
        } else {
            $heading = __(
                'OXPulse Imager could not verify whether .htaccess rewrite is available. The probe result is authoritative — click "Re-test capability" to retry.',
                'oxpulse-imager'
            );
            $class = 'info';
        }
        $perf = esc_html($this->perfCopy());

        $html = '<div class="notice notice-' . $class . ' is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr('capability_apache') . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '<p>' . $perf . '</p>'
            . '<p><button type="button" class="button button-secondary oxpulse-retest-btn" '
            . 'data-oxpulse-retest-url="' . esc_url($restUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '" '
            . 'data-oxpulse-testing-label="' . esc_attr__('Testing…', 'oxpulse-imager') . '">'
            . esc_html__('Re-test capability', 'oxpulse-imager')
            . '</button></p>'
            . '</div>';

        return ['key' => 'capability_apache', 'class' => $class, 'html' => $html];
    }

    private function buildLitespeedNotice(string $capability, string $restUrl, string $dismissUrl, string $nonce): array
    {
        $heading = __(
            'OXPulse Imager detected LiteSpeed. The rewrite-capability probe result is authoritative — if images are served via the PHP fallback, click "Re-test capability" to retry.',
            'oxpulse-imager'
        );
        $perf = esc_html($this->perfCopy());

        $html = '<div class="notice notice-info is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr('capability_litespeed') . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '<p>' . $perf . '</p>'
            . '<p><button type="button" class="button button-secondary oxpulse-retest-btn" '
            . 'data-oxpulse-retest-url="' . esc_url($restUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '" '
            . 'data-oxpulse-testing-label="' . esc_attr__('Testing…', 'oxpulse-imager') . '">'
            . esc_html__('Re-test capability', 'oxpulse-imager')
            . '</button></p>'
            . '</div>';

        return ['key' => 'capability_litespeed', 'class' => 'info', 'html' => $html];
    }

    private function buildUnknownNotice(string $capability, string $restUrl, string $dismissUrl, string $nonce): array
    {
        $heading = __(
            'OXPulse Imager could not verify whether clean cache URLs can be served. The probe result is authoritative — click "Re-test capability" to retry.',
            'oxpulse-imager'
        );
        $perf = esc_html($this->perfCopy());

        $html = '<div class="notice notice-info is-dismissible oxpulse-notice" '
            . 'data-oxpulse-notice-key="' . esc_attr('capability_unknown') . '" '
            . 'data-oxpulse-dismiss-url="' . esc_url($dismissUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '">'
            . '<p><strong>' . esc_html__('OXPulse Imager', 'oxpulse-imager') . '</strong> — '
            . esc_html($heading) . '</p>'
            . '<p>' . $perf . '</p>'
            . '<p><button type="button" class="button button-secondary oxpulse-retest-btn" '
            . 'data-oxpulse-retest-url="' . esc_url($restUrl) . '" '
            . 'data-oxpulse-nonce="' . esc_attr($nonce) . '" '
            . 'data-oxpulse-testing-label="' . esc_attr__('Testing…', 'oxpulse-imager') . '">'
            . esc_html__('Re-test capability', 'oxpulse-imager')
            . '</button></p>'
            . '</div>';

        return ['key' => 'capability_unknown', 'class' => 'info', 'html' => $html];
    }

    /**
     * Render the co-install notice if a competitor is active and not
     * dismissed for the current plugin set.
     */
    private function maybeRenderCoInstallNotice(): void
    {
        $notice = $this->buildCoInstallNotice();
        if ($notice === null) {
            return;
        }
        // Co-install dismissal is keyed per plugin set; the stored
        // state is the capability-independent NOTICE_STATE_ACTIVE
        // marker (resolved via noticeDismissState()) so a set change
        // (new key) re-notifies automatically.
        if (!$this->repository->isNoticeDismissed($notice['key'], self::noticeDismissState($notice['key'], $this->repository))) {
            echo wp_kses_post($notice['html']);
            $this->emitInlineScript();
        }
    }

    /**
     * #87: Render the multisite LocalBackend-unsupported notice when not
     * already dismissed. Capability-independent dismissal (NOTICE_STATE_ACTIVE),
     * mirroring maybeRenderCoInstallNotice().
     */
    private function maybeRenderMultisiteNotice(): void
    {
        $notice = $this->buildMultisiteNotice();
        if (!$this->repository->isNoticeDismissed($notice['key'], self::noticeDismissState($notice['key'], $this->repository))) {
            echo wp_kses_post($notice['html']);
            $this->emitInlineScript();
        }
    }

    /**
     * #92: Render the cron-disabled notice when a blocked async pre-warm
     * attempt set the CRON_BLOCKED_TRANSIENT flag (1h TTL) and the notice
     * has not been dismissed. Capability-independent dismissal
     * (NOTICE_STATE_ACTIVE). Renders in every delivery-backend context
     * because WP-Cron config is independent of the delivery backend.
     */
    private function maybeRenderCronDisabledNotice(): void
    {
        if (!function_exists('get_transient') || get_transient(self::CRON_BLOCKED_TRANSIENT) !== true) {
            return;
        }
        $notice = $this->buildCronDisabledNotice();
        if (!$this->repository->isNoticeDismissed($notice['key'], self::noticeDismissState($notice['key'], $this->repository))) {
            echo wp_kses_post($notice['html']);
            $this->emitInlineScript();
        }
    }

    /**
     * Emit the minimal inline vanilla-JS for the Re-test + dismiss
     * buttons. Emitted once per request (static guard). No React
     * dependency — works without the admin SPA build.
     */
    private function emitInlineScript(): void
    {
        if (self::$scriptEmitted) {
            return;
        }
        self::$scriptEmitted = true;

        $js = <<<'JS'
(function(){
  function post(url, nonce, body){
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body || {})
    }).then(function(r){ return r.json(); });
  }
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.oxpulse-retest-btn');
    if (btn) {
      btn.disabled = true;
      var orig = btn.textContent;
      btn.textContent = btn.getAttribute('data-oxpulse-testing-label') || 'Testing…';
      post(btn.getAttribute('data-oxpulse-retest-url'),
            btn.getAttribute('data-oxpulse-nonce'))
        .then(function(){ window.location.reload(); })
        .catch(function(){ btn.disabled = false; btn.textContent = orig; });
      return;
    }
    var dismiss = e.target.closest('.oxpulse-notice .notice-dismiss');
    if (dismiss) {
      var box = dismiss.closest('.oxpulse-notice');
      if (box) {
        post(box.getAttribute('data-oxpulse-dismiss-url'),
             box.getAttribute('data-oxpulse-nonce'),
             { noticeKey: box.getAttribute('data-oxpulse-notice-key') });
      }
    }
  });
})();
JS;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS is a static literal (no user input, no interpolation), not HTML.
        echo '<script>' . $js . '</script>';
    }
}
