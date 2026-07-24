<?php
/**
 * Admin settings page — thin shell for the React SPA.
 *
 * Registers the settings page under Settings > OXPulse Imager and
 * enqueues the self-contained React admin bundle. The SPA mounts
 * into #oxpulse-admin-root and talks to /wp-json/oxpulse/v1/options
 * via the OptionsRestController.
 *
 * Ported from UTM Linker (includes/Admin/AdminPage.php). No classic
 * form fallback — a mount-failure notice script tells the operator
 * what to do if the bundle fails to load.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Delivery\DeliveryBackendFactory;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\WordPress\AssetManifest;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;

final class SettingsPage
{
    public const PAGE_SLUG = 'oxpulse-imager';
    public const NONCE_ACTION = 'oxpulse_imager_settings';
    public const OPTION_GROUP = 'oxpulse_imager_settings_group';

    private OptionSettingsRepository $repository;
    private ImageTransformer $imageTransformer;
    private ImgproxyHealthCache $imgproxyHealthCache;
    private CapabilityTester $capabilityTester;

    public function __construct(
        ?OptionSettingsRepository $repository = null,
        ?ImageTransformer $imageTransformer = null,
        ?ImgproxyHealthCache $imgproxyHealthCache = null,
        ?CapabilityTester $capabilityTester = null,
    ) {
        $this->repository = $repository ?? new OptionSettingsRepository();
        $this->imageTransformer = $imageTransformer ?? new ImageTransformer();
        $this->imgproxyHealthCache = $imgproxyHealthCache ?? new ImgproxyHealthCache();
        $this->capabilityTester = $capabilityTester ?? new CapabilityTester(null, $this->repository);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('OXPulse Imager', 'oxpulse-imager'),
            __('OXPulse Imager', 'oxpulse-imager'),
            OXPULSE_IMAGER_CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Render the settings page — mount root for the React SPA plus a
     * PHP-rendered active-delivery status line (so the operator can see
     * the chosen backend/health path before the SPA loads).
     *
     * An `<h1>` precedes the root div (a11y): a bare mount root leaves
     * no heading landmark for a screen reader to orient on before the
     * SPA mounts (or if it never does). `screen-reader-text` keeps it
     * from visually duplicating the SPA's own in-root headings.
     */
    public function render(): void
    {
        if (!current_user_can(OXPULSE_IMAGER_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oxpulse-imager'), 403);
        }
        ?>
        <h1 class="screen-reader-text"><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p class="oxpulse-delivery-status">
            <strong><?php echo esc_html__('Active delivery:', 'oxpulse-imager'); ?></strong>
            <?php echo esc_html($this->buildDeliveryStatusLine()); ?>
        </p>
        <div id="oxpulse-admin-root"></div>
        <?php
    }

    /**
     * #90: Build the one-line active delivery-path label for the admin
     * settings page. Uses cached health + capability signals only (no
     * network I/O on the render path).
     *
     * Possible labels:
     *   - Active delivery: imgproxy (AVIF via Accept)
     *   - Active delivery: imgproxy (AVIF)
     *   - Active delivery: imgproxy (WebP)
     *   - Active delivery: LocalBackend clean-URL (.webp/.avif)
     *   - Active delivery: LocalBackend ?k= fallback
     *   - Active delivery: Passthrough (no optimization)
     */
    public function buildDeliveryStatusLine(): string
    {
        // Gate 5 (ProFeatures::ADMIN_STATUS): under free, the detailed
        // delivery-status readout is Pro — replace it with an HONEST
        // basic line that reflects the actual selected backend (FIX 4:
        // the previous "Active delivery: active" was dishonest — it
        // said "active" even when delivery was passthrough/no-op).
        // Reuses DeliveryBackendFactory::select() (the canonical
        // selection path) so the status line never drifts from the
        // real front-end backend. No detailed diagnostics (AVIF/WebP
        // format, clean-URL/?k=) — just the backend type.
        if (!ServiceRegistrar::isPro()) {
            return $this->freeDeliveryStatusLine();
        }

        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        if (!$delivery->isLocalBackendActive() && $this->imgproxyHealthCache->read() === 'up') {
            return $this->imgproxyLabel($delivery);
        }

        $localApplicable = $signing !== null
            && $delivery->sourceMode === 'http'
            && (!function_exists('is_multisite') || !is_multisite());

        if ($localApplicable && ($this->imageTransformer->supportsWebp() || $this->imageTransformer->supportsAvif())) {
            return $this->capabilityTester->rewriteAvailable()
                ? __('Active delivery: LocalBackend clean-URL (.webp/.avif)', 'oxpulse-imager')
                : __('Active delivery: LocalBackend ?k= fallback', 'oxpulse-imager');
        }

        return __('Active delivery: Passthrough (no optimization)', 'oxpulse-imager');
    }

    /**
     * Honest but basic delivery-status line for free users (FIX 4).
     *
     * Queries the actual selected backend via DeliveryBackendFactory::
     * select() and returns a basic label reflecting the backend type —
     * no detailed diagnostics (format, clean-URL/?k=). This is honest:
     * "Active delivery: imgproxy" when imgproxy is selected, "Active
     * delivery: local (WebP)" when LocalBackend is selected, "Active
     * delivery: passthrough (no optimization)" when no backend is
     * selected (null = preserve original URLs).
     */
    private function freeDeliveryStatusLine(): string
    {
        $delivery = $this->repository->loadDeliveryConfig();
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );
        $signing = $this->repository->loadSigningConfig();
        $backend = DeliveryBackendFactory::select($delivery, $signing);

        if ($backend instanceof ImgproxyBackend) {
            return __('Active delivery: imgproxy', 'oxpulse-imager');
        }
        if ($backend instanceof LocalBackend) {
            return __('Active delivery: local (WebP)', 'oxpulse-imager');
        }
        return __('Active delivery: passthrough (no optimization)', 'oxpulse-imager');
    }

    private function imgproxyLabel(DeliveryConfig $delivery): string
    {
        switch ($delivery->outputFormat) {
            case 'avif':
                return __('Active delivery: imgproxy (AVIF)', 'oxpulse-imager');
            case 'webp':
                return __('Active delivery: imgproxy (WebP)', 'oxpulse-imager');
            case 'jpeg':
                return __('Active delivery: imgproxy (JPEG)', 'oxpulse-imager');
            default:
                return __('Active delivery: imgproxy (AVIF via Accept)', 'oxpulse-imager');
        }
    }

    /**
     * Build the license state object localized to the SPA as
     * `window.oxpulseAdmin.license`. All reads are guarded so the SPA
     * never breaks when the Freemius SDK is absent (deploy shipped
     * without freemius/) — every field degrades to a free-safe default
     * and no method is ever called on a null SDK instance.
     *
     * Fields:
     *   - isPro           (bool)   — LicenseGate::isPro() (includes grandfathering).
     *   - plan            (string) — 'pro' | 'free' (LicenseGate::planName()).
     *   - upgradeUrl      (string) — Freemius checkout URL, '' when SDK absent.
     *   - accountUrl      (string) — Freemius account URL, '' when SDK absent.
     *   - isGrandfathered (bool)   — pre-Freemius install keeps every feature.
     *
     * The SPA uses isPro + isGrandfathered to pick the plan pill
     * ("Pro" / "Pro · included" / "Free") and upgradeUrl/accountUrl for
     * the CTA. This is UX only — the real enforcement is the backend
     * isPro() gates (PR #110); this block only MIRRORS that state.
     *
     * @return array{isPro: bool, plan: string, upgradeUrl: string, accountUrl: string, isGrandfathered: bool}
     */
    public function buildLicenseData(): array
    {
        $gate = function_exists('oxpulse_license_gate') ? oxpulse_license_gate() : null;
        $isPro = $gate !== null ? $gate->isPro() : false;
        $plan = $gate !== null ? $gate->planName() : 'free';

        $fs = function_exists('oxpulse_fs') ? oxpulse_fs() : null;
        $upgradeUrl = ($fs !== null && method_exists($fs, 'get_upgrade_url'))
            ? esc_url_raw($fs->get_upgrade_url())
            : '';
        $accountUrl = ($fs !== null && method_exists($fs, 'get_account_url'))
            ? esc_url_raw($fs->get_account_url())
            : '';

        return [
            'isPro'           => $isPro,
            'plan'            => $plan,
            'upgradeUrl'      => $upgradeUrl,
            'accountUrl'      => $accountUrl,
            'isGrandfathered' => (bool) get_option('oxpulse_grandfathered'),
        ];
    }

    /**
     * Enqueue the React admin bundle on this page only.
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $pluginUrl = defined('OXPULSE_IMAGER_PLUGIN_URL')
            ? OXPULSE_IMAGER_PLUGIN_URL
            : plugin_dir_url(dirname(__DIR__, 4) . '/oxpulse-imager.php');

        // Filesystem path to the plugin root (where languages/ lives).
        // wp_set_script_translations() resolves
        // {$pluginPath}/languages/{$domain}-{$locale}-{$domain}.json.
        $pluginPath = defined('OXPULSE_IMAGER_DIR')
            ? OXPULSE_IMAGER_DIR
            : plugin_dir_path(dirname(__DIR__, 4) . '/oxpulse-imager.php');

        $version = defined('OXPULSE_IMAGER_VERSION') ? OXPULSE_IMAGER_VERSION : '1.0.0';

        // CSS (optional — Vite may not emit it if the bundle has no CSS).
        $cssFile = AssetManifest::resolve('admin-app.css');
        if ($cssFile !== 'admin-app.css') {
            wp_enqueue_style(
                'oxpulse-admin-app',
                $pluginUrl . 'assets/css/' . $cssFile,
                [],
                $version
            );
        }

        // JS — self-contained bundle (React + react-dom + @wordpress/i18n bundled in).
        wp_enqueue_script(
            'oxpulse-admin-app',
            $pluginUrl . 'assets/js/' . AssetManifest::resolve('admin-app.js'),
            [],
            $version,
            true
        );

        // Load translations for the admin SPA. WordPress looks up
        // {$languagesPath}/{$domain}-{$locale}-{$handle}.json (the
        // JS-specific translation file generated from the .po by
        // build/make-json.mjs) and inlines it as a <script> before our
        // bundle, populating @wordpress/i18n's locale data registry.
        // $path must point at the languages/ directory itself.
        wp_set_script_translations('oxpulse-admin-app', 'oxpulse-imager', $pluginPath . '/languages');

        wp_localize_script(
            'oxpulse-admin-app',
            'oxpulseAdmin',
            [
                'restUrl'   => esc_url_raw(rest_url('oxpulse/v1/options')),
                'nonce'     => wp_create_nonce('wp_rest'),
                'version'   => $version,
                'uploadsUrl' => esc_url_raw(wp_upload_dir()['baseurl']),
                'mountFailureMessage' => __(
                    'OXPulse Imager admin failed to load. Try a hard refresh; if it still does not load, check for caching plugins or contact your site administrator.',
                    'oxpulse-imager'
                ),
                'license'   => $this->buildLicenseData(),
            ]
        );

        wp_add_inline_script('oxpulse-admin-app', self::getMountFailureNoticeScript());
    }

    /**
     * Mount-failure notice inline script: if #oxpulse-admin-root is
     * still empty after a grace period, inject a .notice.notice-error
     * telling the operator what to do.
     */
    private static function getMountFailureNoticeScript(): string
    {
        return <<<'JS'
( function () {
	var GRACE_MS = 3000;
	var FALLBACK_MESSAGE = 'OXPulse Imager admin failed to load.';
	var MESSAGE = ( window.oxpulseAdmin && window.oxpulseAdmin.mountFailureMessage ) || FALLBACK_MESSAGE;
	var notice = null;

	function showNotice( root ) {
		if ( notice ) { return; }
		notice = document.createElement( 'div' );
		notice.className = 'notice notice-error';
		notice.setAttribute( 'role', 'alert' );
		var message = document.createElement( 'p' );
		message.textContent = MESSAGE;
		notice.appendChild( message );
		root.parentNode.insertBefore( notice, root );
	}

	function hideNotice() {
		if ( notice && notice.parentNode ) {
			notice.parentNode.removeChild( notice );
		}
		notice = null;
	}

	function watch( root ) {
		var observer = new MutationObserver( function () {
			if ( root.childElementCount > 0 ) {
				hideNotice();
				observer.disconnect();
			}
		} );
		observer.observe( root, { childList: true } );

		window.setTimeout( function () {
			if ( root.childElementCount === 0 ) {
				showNotice( root );
			}
		}, GRACE_MS );
	}

	function init() {
		var root = document.getElementById( 'oxpulse-admin-root' );
		if ( ! root ) { return; }
		watch( root );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
JS;
    }
}
