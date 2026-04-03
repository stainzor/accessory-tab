<?php
/**
 * Plugin Name: Accessory Tab for WooCommerce
 * Description: Visar tillbehör direkt på produktsidan med produktkort (bild, pris, lagerstatus, "Lägg till"-knapp). Admin: lägg till tillbehör via SKU eller produktsök.
 * Author: HB
 * Version: 2.24.1
 * License: GPLv2 or later
 * Text Domain: sijab-tillbehor
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// GitHub auto-updater — checks for new releases automatically.
require_once __DIR__ . '/vendor/plugin-update-checker/load-v5p6.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$sijab_tillbehor_updater = PucFactory::buildUpdateChecker(
	'https://github.com/stainzor/accessory-tab/',
	__FILE__,
	'accessory-tab'
);
$sijab_tillbehor_updater->setBranch( 'main' );
$sijab_tillbehor_updater->getVcsApi()->enableReleaseAssets();

// Authenticate with GitHub token for private repo.
$sijab_gh_token = get_option( 'sijab_tillbehor_github_token', '' );
if ( ! empty( $sijab_gh_token ) ) {
	$sijab_tillbehor_updater->setAuthentication( $sijab_gh_token );
}

class SIJAB_Tillbehor {

	const META_KEY      = '_sijab_accessories_ids';
	const BUNDLE_META   = '_sijab_bundle_items';
	const BUNDLE_FLAG   = '_sijab_is_bundle';
	const VERSION       = '2.24.1';
	const OPTION        = 'sijab_tillbehor_settings';
	const STATS_TABLE   = 'sijab_acc_stats';

	/** @var array|null Cached settings. */
	private $settings = null;

	public function __construct() {
		// Frontend hooks — registered dynamically based on placement setting.
		add_action( 'wp', [ $this, 'register_frontend_hooks' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

		// Admin: produktredigerare — tillbehör.
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_admin_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_admin_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_accessories' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_css' ] );

		// Admin: paketprodukter.
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_bundle_admin_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_bundle_admin_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_bundle_data' ] );

		// AI-generering.
		add_action( 'wp_ajax_sijab_generate_bundle_content', [ $this, 'ajax_generate_bundle_content' ] );
		add_action( 'wp_ajax_sijab_suggest_accessories', [ $this, 'ajax_suggest_accessories' ] );
		add_action( 'wp_ajax_sijab_save_acc_category', [ $this, 'ajax_save_acc_category' ] );
		add_action( 'wp_ajax_sijab_get_product_prices', [ $this, 'ajax_get_product_prices' ] );

		// Frontend: paketprodukter.
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_bundle_section' ], 35 );

		// Settings page under WooCommerce menu.
		add_action( 'admin_menu', [ $this, 'register_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_migration' ] );

		// AJAX: variable product add-to-cart from accessory card.
		add_action( 'wp_ajax_sijab_add_to_cart', [ $this, 'ajax_add_to_cart' ] );
		add_action( 'wp_ajax_nopriv_sijab_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

		// Stats: AJAX tracking + cleanup cron.
		add_action( 'wp_ajax_sijab_acc_track', [ $this, 'ajax_track_event' ] );
		add_action( 'wp_ajax_nopriv_sijab_acc_track', [ $this, 'ajax_track_event' ] );
		add_action( 'sijab_acc_stats_cleanup', [ $this, 'cleanup_old_stats' ] );

		// Shop: enable filtering/sorting bundles.
		add_action( 'woocommerce_product_query', [ $this, 'handle_bundle_filter' ] );
		add_filter( 'woocommerce_catalog_orderby', [ $this, 'add_bundle_sorting_option' ] );

		// Admin: filter by bundle in product list.
		add_filter( 'woocommerce_product_filters', [ $this, 'add_bundle_type_filter' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_products_by_bundle' ], 1 );
		add_filter( 'request', [ $this, 'intercept_bundle_request' ], 1 );

		// Order tracking: tag cart items added via accessory plugin.
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'tag_cart_item' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_accessory_meta_to_order' ], 10, 4 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'record_accessory_purchases' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_accessory_purchases' ] );
	}

	// ──────────────────────────────────────────────────────────────
	// Settings
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get plugin settings with defaults.
	 */
	public function get_settings(): array {
		if ( $this->settings !== null ) return $this->settings;

		$defaults = [
			'placement'    => 'before_tabs',    // before_tabs | after_summary | after_tabs
			'title_format' => 'with_name',      // with_name | simple | custom
			'custom_title' => '',
			'columns'      => 4,
			'max_visible'  => 3,                // How many cards to show before "Visa alla"
			'layout'       => 'horizontal',     // horizontal | grid
		];

		$saved = get_option( self::OPTION, [] );
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );

		return $this->settings;
	}

	/**
	 * Register the settings submenu under WooCommerce.
	 */
	public function register_settings_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Accessory Tab — Tillbehör', 'sijab-tillbehor' ),
			__( 'Tillbehör', 'sijab-tillbehor' ),
			'manage_woocommerce',
			'sijab-tillbehor',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Register settings fields.
	 */
	public function register_settings(): void {
		register_setting( 'sijab_tillbehor', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
		] );

		// GitHub token stored separately.
		register_setting( 'sijab_tillbehor', 'sijab_tillbehor_github_token', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		// OpenAI API key + model.
		register_setting( 'sijab_tillbehor', 'sijab_openai_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'sijab_tillbehor', 'sijab_openai_model', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'gpt-4o',
		] );
	}

	/**
	 * Sanitize settings on save.
	 */
	public function sanitize_settings( $input ): array {
		$clean = [];
		$valid_placements = [ 'before_tabs', 'after_summary' ];
		$valid_titles     = [ 'with_name', 'simple', 'custom' ];

		$clean['placement']    = in_array( $input['placement'] ?? '', $valid_placements, true ) ? $input['placement'] : 'before_tabs';
		$clean['title_format'] = in_array( $input['title_format'] ?? '', $valid_titles, true ) ? $input['title_format'] : 'with_name';
		$clean['custom_title'] = sanitize_text_field( $input['custom_title'] ?? '' );
		$clean['columns']      = max( 2, min( 6, absint( $input['columns'] ?? 4 ) ) );
		$clean['max_visible']  = max( 1, min( 12, absint( $input['max_visible'] ?? 3 ) ) );
		$valid_layouts         = [ 'horizontal', 'grid' ];
		$clean['layout']       = in_array( $input['layout'] ?? '', $valid_layouts, true ) ? $input['layout'] : 'horizontal';

		// Clear cached settings.
		$this->settings = null;

		// Purge page caches so the new placement takes effect immediately.
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		return $clean;
	}

	/**
	 * Render the unified admin page (all tabs).
	 */
	public function render_admin_page(): void {
		$s        = $this->get_settings();
		$gh_token = get_option( 'sijab_tillbehor_github_token', '' );
		$ai_key   = get_option( 'sijab_openai_api_key', '' );
		$ai_model = get_option( 'sijab_openai_model', 'gpt-4o' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accessory Tab — Tillbehör', 'sijab-tillbehor' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="statistik"><?php esc_html_e( 'Statistik', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="visning"><?php esc_html_e( 'Visning', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="api"><?php esc_html_e( 'API-inställningar', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="verktyg"><?php esc_html_e( 'Verktyg', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="om"><?php esc_html_e( 'Om', 'sijab-tillbehor' ); ?></a>
			</h2>

			<!-- ── Flik: Statistik ────────────────────── -->
			<?php $this->render_stats_panel(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'sijab_tillbehor' ); ?>

				<!-- ── Flik: Visning ─────────────────────────── -->
				<div id="sijab-tab-visning" class="sijab-tab-panel">
						<table class="form-table" role="presentation">

							<tr>
								<th scope="row"><?php esc_html_e( 'Placering', 'sijab-tillbehor' ); ?></th>
								<td>
									<fieldset>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[placement]" value="after_summary" <?php checked( $s['placement'], 'after_summary' ); ?> />
											<?php esc_html_e( 'Under "Lägg i kundvagn" (i produktinfo-kolumnen)', 'sijab-tillbehor' ); ?>
										</label>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[placement]" value="before_tabs" <?php checked( $s['placement'], 'before_tabs' ); ?> />
											<?php esc_html_e( 'Ovanför flikarna (full bredd)', 'sijab-tillbehor' ); ?>
										</label>
									</fieldset>
									<p class="description"><?php esc_html_e( 'Välj var tillbehörssektionen ska visas på produktsidan.', 'sijab-tillbehor' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Rubrik', 'sijab-tillbehor' ); ?></th>
								<td>
									<fieldset>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[title_format]" value="with_name" <?php checked( $s['title_format'], 'with_name' ); ?> />
											<?php esc_html_e( '"Tillbehör till [Produktnamn]"', 'sijab-tillbehor' ); ?>
										</label>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[title_format]" value="simple" <?php checked( $s['title_format'], 'simple' ); ?> />
											<?php esc_html_e( '"Tillbehör"', 'sijab-tillbehor' ); ?>
										</label>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[title_format]" value="custom" <?php checked( $s['title_format'], 'custom' ); ?> />
											<?php esc_html_e( 'Egen rubrik:', 'sijab-tillbehor' ); ?>
											<input type="text" name="<?php echo self::OPTION; ?>[custom_title]" value="<?php echo esc_attr( $s['custom_title'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'T.ex. Komplettera med...', 'sijab-tillbehor' ); ?>" />
										</label>
									</fieldset>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Layout', 'sijab-tillbehor' ); ?></th>
								<td>
									<fieldset>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[layout]" value="horizontal" <?php checked( $s['layout'], 'horizontal' ); ?> />
											<?php esc_html_e( 'Kompakt horisontell (Dustin-stil)', 'sijab-tillbehor' ); ?>
										</label>
										<label style="display:block; margin-bottom:8px;">
											<input type="radio" name="<?php echo self::OPTION; ?>[layout]" value="grid" <?php checked( $s['layout'], 'grid' ); ?> />
											<?php esc_html_e( 'Bildrutnät (stora kort med bild)', 'sijab-tillbehor' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="sijab_columns"><?php esc_html_e( 'Antal kolumner', 'sijab-tillbehor' ); ?></label></th>
								<td>
									<select name="<?php echo self::OPTION; ?>[columns]" id="sijab_columns">
										<?php for ( $i = 2; $i <= 6; $i++ ) : ?>
											<option value="<?php echo $i; ?>" <?php selected( $s['columns'], $i ); ?>><?php echo $i; ?></option>
										<?php endfor; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Max antal kort per rad. Används bara för bildrutnät-layout.', 'sijab-tillbehor' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="sijab_max_visible"><?php esc_html_e( 'Synliga tillbehör', 'sijab-tillbehor' ); ?></label></th>
								<td>
									<select name="<?php echo self::OPTION; ?>[max_visible]" id="sijab_max_visible">
										<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
											<option value="<?php echo $i; ?>" <?php selected( $s['max_visible'], $i ); ?>><?php echo $i; ?></option>
										<?php endfor; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Antal tillbehör som visas direkt. Resten döljs bakom "Visa alla tillbehör".', 'sijab-tillbehor' ); ?></p>
								</td>
							</tr>

						</table>
					<?php submit_button( __( 'Spara inställningar', 'sijab-tillbehor' ) ); ?>
				</div>

				<!-- ── Flik: API-inställningar ───────────────── -->
				<div id="sijab-tab-api" class="sijab-tab-panel" style="display:none; padding-bottom:8px;">

					<!-- GitHub -->
					<div style="margin-top:24px; padding:20px 24px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; max-width:700px;">
							<h3 style="margin:0 0 4px; display:flex; align-items:center; gap:8px;">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.335-1.755-1.335-1.755-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 21.795 24 17.295 24 12c0-6.63-5.37-12-12-12"/></svg>
								GitHub
							</h3>
							<p style="margin:0 0 16px; color:#646970; font-size:13px;"><?php esc_html_e( 'Används för automatiska plugin-uppdateringar från GitHub-repot.', 'sijab-tillbehor' ); ?></p>
							<table class="form-table" role="presentation" style="margin:0;">
								<tr>
									<th scope="row" style="width:180px;"><label for="sijab_gh_token"><?php esc_html_e( 'Personal access token', 'sijab-tillbehor' ); ?></label></th>
									<td>
										<input type="password" name="sijab_tillbehor_github_token" id="sijab_gh_token" value="<?php echo esc_attr( $gh_token ); ?>" class="regular-text" autocomplete="off" />
										<p class="description"><?php esc_html_e( 'Classic token med "repo"-rättighet. Skapa på github.com → Settings → Developer settings → Personal access tokens.', 'sijab-tillbehor' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

					<!-- OpenAI -->
					<div style="margin-top:16px; padding:20px 24px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; max-width:700px;">
							<h3 style="margin:0 0 4px; display:flex; align-items:center; gap:8px;">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.677l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>
								OpenAI
							</h3>
							<p style="margin:0 0 16px; color:#646970; font-size:13px;"><?php esc_html_e( 'Används för AI-generering av produkttitel, beskrivning och kollage-bild för paketprodukter.', 'sijab-tillbehor' ); ?></p>
							<table class="form-table" role="presentation" style="margin:0;">
								<tr>
									<th scope="row" style="width:180px;"><label for="sijab_openai_key"><?php esc_html_e( 'API-nyckel', 'sijab-tillbehor' ); ?></label></th>
									<td>
										<input type="password" name="sijab_openai_api_key" id="sijab_openai_key" value="<?php echo esc_attr( $ai_key ); ?>" class="regular-text" autocomplete="off" />
										<p class="description"><?php esc_html_e( 'Skapa nyckeln på platform.openai.com → API keys.', 'sijab-tillbehor' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" style="width:180px;"><label for="sijab_openai_model"><?php esc_html_e( 'GPT-modell', 'sijab-tillbehor' ); ?></label></th>
									<td>
										<?php
										$models = [
											'gpt-4o'      => 'GPT-4o (rekommenderad)',
											'gpt-4o-mini' => 'GPT-4o Mini (snabbare, billigare)',
											'gpt-4-turbo' => 'GPT-4 Turbo',
											'gpt-4'       => 'GPT-4',
											'gpt-3.5-turbo' => 'GPT-3.5 Turbo (billigast)',
											'o4-mini'     => 'o4-mini (reasoning)',
											'o3'          => 'o3 (reasoning, dyrast)',
										];
										?>
										<select name="sijab_openai_model" id="sijab_openai_model">
											<?php foreach ( $models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $ai_model, $val ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Används för AI-förslag på tillbehör och paketgenerering.', 'sijab-tillbehor' ); ?></p>
									</td>
								</tr>
							</table>
							<?php if ( $ai_key ) : ?>
								<p style="margin:12px 0 0; font-size:12px; color:#46b450;">&#10003; <?php esc_html_e( 'API-nyckel sparad', 'sijab-tillbehor' ); ?></p>
							<?php else : ?>
								<p style="margin:12px 0 0; font-size:12px; color:#646970;"><?php esc_html_e( 'Ingen nyckel sparad ännu.', 'sijab-tillbehor' ); ?></p>
							<?php endif; ?>
						</div>

				<p style="margin-top:20px;">
					<?php submit_button( __( 'Spara inställningar', 'sijab-tillbehor' ), 'primary', 'submit', false ); ?>
				</p>
			</div><!-- end API panel -->

			</form><!-- end settings form -->

			<!-- ── Flik: Verktyg (egen form för migrering) ── -->
			<div id="sijab-tab-verktyg" class="sijab-tab-panel" style="display:none;">
				<?php $this->render_migration_section(); ?>
			</div><!-- end Verktyg panel -->

			<!-- ── Flik: Om ─────────────────────────── -->
			<div id="sijab-tab-om" class="sijab-tab-panel" style="display:none;">
				<div style="margin-top:16px; background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:24px; max-width:600px;">
					<h3 style="margin-top:0;">Accessory Tab for WooCommerce</h3>
					<table class="form-table" style="margin:0;">
						<tr>
							<th style="padding:8px 10px 8px 0; width:140px;">Version</th>
							<td style="padding:8px 0;"><strong><?php echo esc_html( self::VERSION ); ?></strong></td>
						</tr>
						<tr>
							<th style="padding:8px 10px 8px 0;">Utvecklare</th>
							<td style="padding:8px 0;">SIJAB</td>
						</tr>
						<tr>
							<th style="padding:8px 10px 8px 0;">Licens</th>
							<td style="padding:8px 0;">GPLv2 or later</td>
						</tr>
						<tr>
							<th style="padding:8px 10px 8px 0;">Versionshistorik</th>
							<td style="padding:8px 0;"><a href="https://github.com/stainzor/accessory-tab/releases" target="_blank">Se alla releaser på GitHub &rarr;</a></td>
						</tr>
					</table>
				</div>
			</div><!-- end Om panel -->

		</div><!-- end .wrap -->

		<script>
		(function($) {
			var storageKey = 'sijab_settings_tab';
			var tabs       = $('.sijab-nav-tab');
			var panels     = $('.sijab-tab-panel');

			function activateTab(id) {
				tabs.removeClass('nav-tab-active');
				panels.hide();
				tabs.filter('[data-tab="' + id + '"]').addClass('nav-tab-active');
				$('#sijab-tab-' + id).show();
				try { localStorage.setItem(storageKey, id); } catch(e) {}
			}

			// Restore last active tab (default: statistik).
			var saved = '';
			try { saved = localStorage.getItem(storageKey) || ''; } catch(e) {}
			var hash = window.location.hash.replace('#sijab-tab-', '');
			activateTab( hash || saved || 'statistik' );

			tabs.on('click', function(e) {
				e.preventDefault();
				activateTab($(this).data('tab'));
			});

			// Period filter for stats — reload page with period param, preserve tab.
			$(document).on('click', '.sijab-period-btn', function(e) {
				e.preventDefault();
				var period = $(this).data('period');
				var url = window.location.pathname + '?page=sijab-tillbehor&period=' + period + '#sijab-tab-statistik';
				window.location.href = url;
			});
		}(jQuery));
		</script>
		<?php
	}

	/**
	 * Render migration section on settings page.
	 */
	public function render_migration_section(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'woocommerce_bundled_items';
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		$result = get_transient( 'sijab_migration_result' );
		delete_transient( 'sijab_migration_result' );
		?>
		<hr style="margin: 32px 0;" />
		<h2><?php esc_html_e( 'Migrera Product Bundles → Korsförsäljning', 'sijab-tillbehor' ); ?></h2>
		<p><?php esc_html_e( 'Kopierar produktkopplingar från WooCommerce Product Bundles till korsförsäljningsfältet så att Accessory Tab kan visa dem automatiskt. Befintliga korsförsäljningar bevaras.', 'sijab-tillbehor' ); ?></p>

		<?php if ( $result ) : ?>
			<div class="notice notice-success inline">
				<p><strong><?php esc_html_e( 'Migrering klar!', 'sijab-tillbehor' ); ?></strong></p>
				<p>
					<?php echo sprintf( esc_html__( 'Behandlade produkter: %d', 'sijab-tillbehor' ), $result['total'] ); ?><br>
					<?php echo sprintf( esc_html__( 'Uppdaterade produkter: %d', 'sijab-tillbehor' ), $result['updated'] ); ?><br>
					<?php echo sprintf( esc_html__( 'Inga ändringar behövdes: %d', 'sijab-tillbehor' ), $result['skipped'] ); ?>
				</p>
				<?php if ( ! empty( $result['details'] ) ) : ?>
					<table class="widefat striped" style="margin-top: 12px; max-width: 600px;">
						<thead><tr><th><?php esc_html_e( 'Produkt', 'sijab-tillbehor' ); ?></th><th><?php esc_html_e( 'Tillagda (ID)', 'sijab-tillbehor' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $result['details'] as $bundle_id => $added_ids ) : ?>
								<tr>
									<td><a href="<?php echo get_edit_post_link( $bundle_id ); ?>"><?php echo esc_html( get_the_title( $bundle_id ) ); ?></a></td>
									<td><?php echo implode( ', ', array_map( 'absint', $added_ids ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $exists ) : ?>
			<p class="description" style="color: #999;"><?php esc_html_e( 'WooCommerce Product Bundles är inte installerat — migrering ej tillgänglig.', 'sijab-tillbehor' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'sijab_run_migration', 'sijab_migration_nonce' ); ?>
				<?php submit_button( __( 'Kör migrering', 'sijab-tillbehor' ), 'secondary', 'sijab_run_migration', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handle migration POST.
	 */
	public function handle_migration(): void {
		if ( ! isset( $_POST['sijab_run_migration'] ) ) return;
		if ( ! check_admin_referer( 'sijab_run_migration', 'sijab_migration_nonce' ) ) return;
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_bundled_items';

		$rows = $wpdb->get_results( "SELECT bundle_id, product_id FROM {$table} ORDER BY bundle_id ASC", ARRAY_A );

		$bundles = [];
		foreach ( (array) $rows as $row ) {
			$bundles[ (int) $row['bundle_id'] ][] = (int) $row['product_id'];
		}

		$total = count( $bundles ); $updated = 0; $skipped = 0; $details = [];

		foreach ( $bundles as $bundle_id => $product_ids ) {
			$existing = get_post_meta( $bundle_id, '_crosssells', true );
			if ( ! is_array( $existing ) ) $existing = [];

			$merged = array_values( array_filter( array_unique( array_map( 'absint', array_merge( $existing, $product_ids ) ) ), fn( $id ) => $id > 0 && $id !== $bundle_id ) );
			$added  = array_diff( $merged, $existing );

			if ( empty( $added ) ) { $skipped++; continue; }

			update_post_meta( $bundle_id, '_crosssells', $merged );
			$updated++;
			$details[ $bundle_id ] = array_values( $added );
		}

		set_transient( 'sijab_migration_result', compact( 'total', 'updated', 'skipped', 'details' ), 60 );
		wp_redirect( add_query_arg( [ 'page' => 'sijab-tillbehor-settings', 'migrated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ──────────────────────────────────────────────────────────────
	// Frontend — Dynamic hook registration
	// ──────────────────────────────────────────────────────────────

	/**
	 * Register the render hook based on the placement setting.
	 * Called on 'wp' so is_product() works.
	 */
	public function register_frontend_hooks(): void {
		if ( ! is_product() ) return;

		$s = $this->get_settings();

		switch ( $s['placement'] ) {
			case 'after_summary':
				// Inside product summary column, after add-to-cart (priority 35).
				add_action( 'woocommerce_single_product_summary', [ $this, 'render_accessories_section' ], 35 );
				break;

			case 'before_tabs':
			default:
				// Before tabs (priority 7, before woocommerce_output_product_data_tabs at 10).
				add_action( 'woocommerce_after_single_product_summary', [ $this, 'render_accessories_section' ], 7 );
				break;
		}
	}

	// ──────────────────────────────────────────────────────────────
	// Frontend — Render
	// ──────────────────────────────────────────────────────────────

	/**
	 * Render the accessories section.
	 */
	public function render_accessories_section(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) return;

		$ids = $this->get_accessory_ids( $product->get_id() );
		if ( empty( $ids ) ) return;

		$accessories = [];
		foreach ( $ids as $id ) {
			$acc = wc_get_product( $id );
			if ( $acc && $acc->is_visible() ) {
				$accessories[] = $acc;
			}
		}
		if ( empty( $accessories ) ) return;

		$s           = $this->get_settings();
		$title       = $this->get_section_title( $product );
		$cols        = (int) $s['columns'];
		$max_visible = (int) $s['max_visible'];
		$layout      = $s['layout'];
		$total       = count( $accessories );
		$has_hidden  = $total > $max_visible;
		$layout_class = 'horizontal' === $layout ? 'sijab-accessories-section--horizontal' : 'sijab-accessories-section--grid';
		?>
		<section class="sijab-accessories-section <?php echo esc_attr( $layout_class ); ?>" style="--sijab-columns: <?php echo $cols; ?>;">
			<h2 class="sijab-accessories-section__title"><?php echo esc_html( $title ); ?></h2>
			<div class="sijab-accessories-section__list">
				<?php foreach ( $accessories as $i => $acc ) : ?>
					<?php $hidden = $has_hidden && $i >= $max_visible; ?>
					<div class="sijab-acc-item<?php echo $hidden ? ' sijab-acc-item--hidden' : ''; ?>">
						<?php $this->render_accessory_card( $acc ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $has_hidden ) : ?>
				<div class="sijab-accessories-section__footer">
					<a href="#" class="sijab-show-all-link" data-show="<?php esc_attr_e( 'Visa alla tillbehör', 'sijab-tillbehor' ); ?> (<?php echo $total; ?>)" data-hide="<?php esc_attr_e( 'Visa färre', 'sijab-tillbehor' ); ?>">
						<?php printf( esc_html__( 'Visa alla tillbehör (%d)', 'sijab-tillbehor' ), $total ); ?>
					</a>
				</div>
			<?php endif; ?>
			<?php
			$cat_id = (int) get_post_meta( $product->get_id(), '_sijab_acc_category_id', true );
			if ( $cat_id ) :
				$cat = get_term( $cat_id, 'product_cat' );
				if ( $cat && ! is_wp_error( $cat ) ) :
					$cat_url = get_term_link( $cat, 'product_cat' );
			?>
				<div class="sijab-accessories-section__category-link">
					<a href="<?php echo esc_url( $cat_url ); ?>">
						<?php printf( esc_html__( 'Se alla %s →', 'sijab-tillbehor' ), esc_html( $cat->name ) ); ?>
					</a>
				</div>
			<?php endif; endif; ?>
		</section>
		<?php
	}

	/**
	 * Get the section title based on settings.
	 */
	private function get_section_title( WC_Product $product ): string {
		$s = $this->get_settings();

		switch ( $s['title_format'] ) {
			case 'simple':
				return __( 'Tillbehör', 'sijab-tillbehor' );

			case 'custom':
				return ! empty( $s['custom_title'] ) ? $s['custom_title'] : __( 'Tillbehör', 'sijab-tillbehor' );

			case 'with_name':
			default:
				return sprintf( __( 'Tillbehör till %s', 'sijab-tillbehor' ), $product->get_name() );
		}
	}

	/**
	 * Render a single accessory product card.
	 */
	private function render_accessory_card( WC_Product $acc ): void {
		$id          = $acc->get_id();
		$link        = get_permalink( $id );
		$title       = $acc->get_name();
		$image_id    = $acc->get_image_id();
		$image_url   = $image_id
			? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
			: wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$price_html  = $acc->get_price_html();
		$sku         = $acc->get_sku();
		$is_simple   = $acc->is_type( 'simple' ) && $acc->is_purchasable() && $acc->is_in_stock();
		$is_variable = $acc->is_type( 'variable' );

		// Stock badge — variable products start empty (updated by JS on variant select).
		$stock_status = $is_variable ? '' : $acc->get_stock_status();
		switch ( $stock_status ) {
			case 'instock':     $stock_label = __( 'I lager', 'sijab-tillbehor' ); break;
			case 'onbackorder': $stock_label = __( 'Beställningsvara', 'sijab-tillbehor' ); break;
			case '':            $stock_label = ''; break;
			default:            $stock_label = __( 'Slut i lager', 'sijab-tillbehor' );
		}
		?>
		<div class="sijab-acc-card" data-accessory-id="<?php echo absint( $id ); ?>">
			<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__image">
				<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
			</a>
			<div class="sijab-acc-card__body">
				<div class="sijab-acc-card__details">
					<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__name">
						<?php echo esc_html( $title ); ?>
					</a>
					<?php if ( $price_html ) : ?>
						<div class="sijab-acc-card__price"><?php echo $price_html; ?></div>
					<?php endif; ?>
				</div>
				<div class="sijab-acc-card__meta">
					<span class="sijab-acc-card__stock<?php echo $stock_status ? ' sijab-acc-card__stock--' . esc_attr( $stock_status ) : ''; ?>">
						<?php echo esc_html( $stock_label ); ?>
					</span>
					<?php if ( $sku ) : ?>
						<span class="sijab-acc-card__sku"><?php echo esc_html( 'Art.nr: ' . $sku ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $is_simple ) : ?>
					<div class="sijab-acc-card__right">
						<div class="sijab-acc-card__qty-row">
							<div class="sijab-acc-card__qty">
								<button type="button" class="sijab-qty-btn sijab-qty-minus" aria-label="<?php esc_attr_e( 'Minska antal', 'sijab-tillbehor' ); ?>">−</button>
								<input type="number" class="sijab-qty-input" value="1" min="1" step="1" aria-label="<?php esc_attr_e( 'Antal', 'sijab-tillbehor' ); ?>" />
								<button type="button" class="sijab-qty-btn sijab-qty-plus" aria-label="<?php esc_attr_e( 'Öka antal', 'sijab-tillbehor' ); ?>">+</button>
							</div>
							<a href="<?php echo esc_url( add_query_arg( 'sijab_acc_parent', $GLOBALS['product']->get_id(), $acc->add_to_cart_url() ) ); ?>"
							   data-quantity="1"
							   class="button sijab-acc-atc-btn add_to_cart_button ajax_add_to_cart sijab-acc-atc"
							   data-product_id="<?php echo absint( $id ); ?>"
							   data-product_sku="<?php echo esc_attr( $sku ); ?>"
							   data-sijab_acc_parent="<?php echo absint( $GLOBALS['product']->get_id() ); ?>"
							   aria-label="<?php echo esc_attr( $acc->add_to_cart_description() ); ?>"
							   rel="nofollow">
								<?php esc_html_e( 'Lägg till', 'sijab-tillbehor' ); ?>
							</a>
						</div>
					</div>

				<?php elseif ( $is_variable ) : ?>
					<?php $variations = $acc->get_available_variations(); ?>
					<div class="sijab-acc-card__right sijab-acc-card__right--variable">
						<div class="sijab-acc-card__var-row">
							<select class="sijab-var-select"
							        data-product-id="<?php echo absint( $id ); ?>"
							        aria-label="<?php esc_attr_e( 'Välj variant', 'sijab-tillbehor' ); ?>">
								<option value=""><?php esc_html_e( 'Välj variant...', 'sijab-tillbehor' ); ?></option>
								<?php foreach ( $variations as $v ) :
									$attr_parts = [];
									foreach ( $v['attributes'] as $attr_key => $attr_val ) {
										if ( ! $attr_val ) continue;
										$taxonomy = str_replace( 'attribute_', '', $attr_key );
										if ( taxonomy_exists( $taxonomy ) ) {
											$term         = get_term_by( 'slug', $attr_val, $taxonomy );
											$attr_parts[] = $term ? $term->name : ucfirst( str_replace( '-', ' ', $attr_val ) );
										} else {
											$attr_parts[] = $attr_val;
										}
									}
									$v_label       = ! empty( $attr_parts ) ? implode( ' / ', $attr_parts ) : '#' . $v['variation_id'];
									$v_stock       = $v['is_in_stock'] ? 'instock' : 'outofstock';
									$v_stock_label = $v['is_in_stock'] ? __( 'I lager', 'sijab-tillbehor' ) : __( 'Slut i lager', 'sijab-tillbehor' );
									?>
									<option value="<?php echo absint( $v['variation_id'] ); ?>"
									        data-price-html="<?php echo esc_attr( $v['price_html'] ); ?>"
									        data-stock="<?php echo esc_attr( $v_stock ); ?>"
									        data-stock-label="<?php echo esc_attr( $v_stock_label ); ?>"
									        data-purchasable="<?php echo ( $v['is_purchasable'] && $v['is_in_stock'] ) ? '1' : '0'; ?>"
									        data-attributes="<?php echo esc_attr( wp_json_encode( $v['attributes'] ) ); ?>"
									        <?php echo ! $v['is_purchasable'] ? 'disabled' : ''; ?>>
										<?php echo esc_html( $v_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sijab-acc-card__qty-row" style="margin-top:6px;">
							<div class="sijab-acc-card__qty">
								<button type="button" class="sijab-qty-btn sijab-qty-minus" aria-label="<?php esc_attr_e( 'Minska antal', 'sijab-tillbehor' ); ?>">−</button>
								<input type="number" class="sijab-qty-input sijab-var-qty-input" value="1" min="1" step="1" aria-label="<?php esc_attr_e( 'Antal', 'sijab-tillbehor' ); ?>" />
								<button type="button" class="sijab-qty-btn sijab-qty-plus" aria-label="<?php esc_attr_e( 'Öka antal', 'sijab-tillbehor' ); ?>">+</button>
							</div>
							<button type="button"
							        class="button sijab-acc-atc-btn sijab-var-atc-btn"
							        data-parent-id="<?php echo absint( $id ); ?>"
							        disabled>
								<?php esc_html_e( 'Lägg till', 'sijab-tillbehor' ); ?>
							</button>
						</div>
					</div>

				<?php else : ?>
					<div class="sijab-acc-card__right">
						<div class="sijab-acc-card__qty-row">
							<a href="<?php echo esc_url( $link ); ?>" class="button sijab-acc-atc-btn">
								<?php esc_html_e( 'Visa produkt', 'sijab-tillbehor' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue frontend CSS on single product pages that have accessories.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_product() ) return;

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product ) return;

		$ids        = $this->get_accessory_ids( $product->get_id() );
		$has_bundle = (bool) get_post_meta( $product->get_id(), self::BUNDLE_FLAG, true );
		if ( empty( $ids ) && ! $has_bundle ) return;

		wp_enqueue_style(
			'sijab-tillbehor-frontend',
			plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css',
			[],
			self::VERSION
		);

		wp_enqueue_script(
			'sijab-tillbehor-frontend',
			plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js',
			[],
			self::VERSION,
			true
		);

		wp_localize_script( 'sijab-tillbehor-frontend', 'sijabAccStats', [
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'parent_id' => $product->get_id(),
			'nonce'     => wp_create_nonce( 'sijab_add_to_cart' ),
		] );
	}

	// ──────────────────────────────────────────────────────────────
	// Admin — Produktredigerare
	// ──────────────────────────────────────────────────────────────

	public function add_admin_tab( $tabs ) {
		$tabs['sijab_accessories'] = [
			'label'    => __( 'Tillbehör', 'sijab-tillbehor' ),
			'target'   => 'sijab_accessories_data',
			'class'    => [ 'show_if_simple', 'show_if_variable', 'show_if_grouped', 'show_if_external' ],
			'priority' => 80,
		];
		return $tabs;
	}

	public function render_admin_panel(): void {
		global $post;
		$saved_ids = $this->get_accessory_ids( $post->ID );
		?>
		<div id="sijab_accessories_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php wp_nonce_field( 'sijab_save_accessories', 'sijab_accessories_nonce' ); ?>

				<!-- Sök och lägg till -->
				<p class="form-field">
					<label for="sijab_acc_search"><?php esc_html_e( 'Lägg till tillbehör (sök på namn/SKU)', 'sijab-tillbehor' ); ?></label>
					<select class="wc-product-search" style="width:60%;" id="sijab_acc_search" data-placeholder="<?php esc_attr_e( 'Sök produkter…', 'sijab-tillbehor' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
					</select>
					<button type="button" class="button" id="sijab_acc_add_btn" style="vertical-align:middle;"><?php esc_html_e( 'Lägg till', 'sijab-tillbehor' ); ?></button>
				</p>

				<p class="form-field">
					<label for="sijab_accessories_skus"><?php esc_html_e( 'Lägg till via SKU (kommaseparerat)', 'sijab-tillbehor' ); ?></label>
					<textarea id="sijab_accessories_skus" name="sijab_accessories_skus" rows="2" style="width:60%;" placeholder="EX123, EX456, EX789"></textarea>
				</p>

				<!-- AI-förslag -->
				<?php if ( get_option( 'sijab_openai_api_key', '' ) ) : ?>
				<div class="form-field" style="padding: 5px 20px 10px;">
					<?php wp_nonce_field( 'sijab_suggest_accessories', 'sijab_suggest_nonce' ); ?>
					<button type="button" class="button button-secondary" id="sijab_ai_suggest_btn" data-product-id="<?php echo absint( $post->ID ); ?>">
						✨ <?php esc_html_e( 'Föreslå tillbehör med AI', 'sijab-tillbehor' ); ?>
					</button>
					<span id="sijab_ai_spinner" class="spinner" style="float:none; margin-top:0;"></span>
					<div id="sijab_ai_results" style="display:none; margin-top:12px;">
						<p style="font-weight:600; margin-bottom:8px;">AI-förslag — välj vilka du vill lägga till:</p>
						<div id="sijab_ai_results_list"></div>
						<p style="margin-top:8px;">
							<button type="button" class="button button-primary" id="sijab_ai_add_selected"><?php esc_html_e( 'Lägg till valda', 'sijab-tillbehor' ); ?></button>
							<button type="button" class="button" id="sijab_ai_dismiss"><?php esc_html_e( 'Stäng', 'sijab-tillbehor' ); ?></button>
						</p>
					</div>
				</div>
				<?php endif; ?>

				<!-- Kategori-länk -->
				<p class="form-field" style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
					<label for="sijab_acc_category" style="flex-shrink:0;"><?php esc_html_e( 'Länka till tillbehörskategori', 'sijab-tillbehor' ); ?></label>
					<?php
					$saved_cat = (int) get_post_meta( $post->ID, '_sijab_acc_category_id', true );
					$categories = get_terms( [
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'orderby'    => 'name',
					] );
					?>
					<select name="sijab_acc_category_id" id="sijab_acc_category" style="width:40%; min-width:200px;">
						<option value=""><?php esc_html_e( '— Ingen kategori —', 'sijab-tillbehor' ); ?></option>
						<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $cat ) : ?>
							<option value="<?php echo absint( $cat->term_id ); ?>" <?php selected( $saved_cat, $cat->term_id ); ?>>
								<?php echo esc_html( $cat->name ); ?> (<?php echo absint( $cat->count ); ?>)
							</option>
						<?php endforeach; endif; ?>
					</select>
					<button type="button" class="button" id="sijab_save_category_btn" style="flex-shrink:0;"><?php esc_html_e( 'Spara kategori', 'sijab-tillbehor' ); ?></button>
					<span id="sijab_cat_status" style="font-size:12px; color:#46b450; display:none;">✓ Sparad</span>
				</p>
				<p class="form-field" style="margin-top:-10px;">
					<label>&nbsp;</label>
					<span class="description"><?php esc_html_e( 'Visar en "Se alla tillbehör"-länk under tillbehörskorten.', 'sijab-tillbehor' ); ?></span>
				</p>

				<!-- Sortable lista -->
				<div class="form-field" style="padding: 5px 20px 10px;">
					<label style="display:block; margin-bottom:8px;"><?php esc_html_e( 'Tillbehör (dra för att ändra ordning)', 'sijab-tillbehor' ); ?></label>
					<ul id="sijab_acc_sortable" style="margin:0; padding:0; list-style:none;">
						<?php if ( ! empty( $saved_ids ) ) :
							foreach ( $saved_ids as $pid ) :
								$p = wc_get_product( $pid );
								if ( ! $p ) continue;
								$thumb = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
								?>
								<li class="sijab-acc-sortable-item" data-id="<?php echo absint( $pid ); ?>">
									<input type="hidden" name="sijab_accessories_ids[]" value="<?php echo absint( $pid ); ?>" />
									<span class="sijab-acc-drag-handle" title="<?php esc_attr_e( 'Dra för att sortera', 'sijab-tillbehor' ); ?>">☰</span>
									<img src="<?php echo esc_url( $thumb ); ?>" width="32" height="32" style="object-fit:contain; vertical-align:middle; margin-right:8px; border-radius:3px; border:1px solid #ddd;" />
									<span class="sijab-acc-item-name"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></span>
									<a href="#" class="sijab-acc-remove" title="<?php esc_attr_e( 'Ta bort', 'sijab-tillbehor' ); ?>">Ta bort</a>
								</li>
								<?php
							endforeach;
						endif; ?>
					</ul>
					<p id="sijab_acc_empty_msg" style="color:#999; font-style:italic; <?php echo ! empty( $saved_ids ) ? 'display:none;' : ''; ?>"><?php esc_html_e( 'Inga tillbehör tillagda ännu.', 'sijab-tillbehor' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function save_product_accessories( $product ): void {
		if ( ! isset( $_POST['sijab_accessories_nonce'] ) || ! wp_verify_nonce( $_POST['sijab_accessories_nonce'], 'sijab_save_accessories' ) ) return;
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) return;

		$ids = [];

		if ( isset( $_POST['sijab_accessories_ids'] ) && is_array( $_POST['sijab_accessories_ids'] ) ) {
			foreach ( $_POST['sijab_accessories_ids'] as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0 && wc_get_product( $id ) ) $ids[] = $id;
			}
		}

		if ( ! empty( $_POST['sijab_accessories_skus'] ) ) {
			$sku_str = sanitize_text_field( wp_unslash( $_POST['sijab_accessories_skus'] ) );
			$sku_arr = array_filter( array_map( 'trim', explode( ',', $sku_str ) ) );
			foreach ( $sku_arr as $sku ) {
				$pid = wc_get_product_id_by_sku( $sku );
				if ( $pid && wc_get_product( $pid ) ) $ids[] = absint( $pid );
			}
		}

		$ids = array_values( array_unique( array_diff( array_filter( array_map( 'absint', $ids ) ), [ $product->get_id() ] ) ) );

		if ( empty( $ids ) ) {
			delete_post_meta( $product->get_id(), self::META_KEY );
		} else {
			update_post_meta( $product->get_id(), self::META_KEY, $ids );
		}

		// Save accessory category link.
		$cat_id = absint( $_POST['sijab_acc_category_id'] ?? 0 );
		if ( $cat_id && term_exists( $cat_id, 'product_cat' ) ) {
			update_post_meta( $product->get_id(), '_sijab_acc_category_id', $cat_id );
		} else {
			delete_post_meta( $product->get_id(), '_sijab_acc_category_id' );
		}
	}

	public function enqueue_admin_css( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) return;
		$screen = get_current_screen();
		if ( empty( $screen ) || 'product' !== $screen->id ) return;

		$css = '
			/* ── Bundle admin row ── */
			.sijab-bundle-row {
				display: flex !important;
				flex-direction: row !important;
				flex-wrap: nowrap !important;
				align-items: center !important;
				gap: 10px !important;
				padding: 8px 12px !important;
				margin: 0 12px 6px !important;
				border: 1px solid #dcdcde !important;
				border-radius: 6px !important;
				background: #f9f9f9 !important;
			}
			/* Product dropdown — takes remaining space */
			.sijab-brow-product {
				flex: 1 1 auto !important;
				min-width: 0 !important;
				max-width: none !important;
			}
			.sijab-brow-product .select2-container { width: 100% !important; }
			.sijab-brow-product select { width: 100% !important; }
			/* Actions: pill + st + remove — fixed to right */
			.sijab-brow-actions {
				display: flex !important;
				flex-direction: row !important;
				flex-wrap: nowrap !important;
				align-items: center !important;
				gap: 6px !important;
				flex: 0 0 auto !important;
				white-space: nowrap !important;
			}
			/* Pill qty container */
			.sijab-bqty-pill {
				display: inline-flex !important;
				flex-direction: row !important;
				flex-wrap: nowrap !important;
				align-items: center !important;
				border: 1px solid #8c8f94 !important;
				border-radius: 99px !important;
				overflow: hidden !important;
				background: #fff !important;
				height: 32px !important;
				flex: 0 0 auto !important;
				width: auto !important;
				max-width: none !important;
			}
			/* +/- buttons */
			.sijab-bqty-pill > button.sijab-bqty-btn,
			.woocommerce_options_panel .sijab-bqty-pill > button.sijab-bqty-btn {
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				width: 30px !important;
				min-width: 30px !important;
				max-width: 30px !important;
				height: 100% !important;
				border: none !important;
				background: transparent !important;
				font-size: 16px !important;
				color: #50575e !important;
				cursor: pointer !important;
				padding: 0 !important;
				margin: 0 !important;
				line-height: 1 !important;
				box-shadow: none !important;
				float: none !important;
				flex: 0 0 30px !important;
			}
			.sijab-bqty-pill > button.sijab-bqty-btn:hover { background: #f0f0f0 !important; color: #1d2327 !important; }
			/* Qty number input — MUST stay 46px despite WC overrides */
			.sijab-bqty-pill > input.sijab-bqty-input,
			.woocommerce_options_panel .sijab-bqty-pill > input.sijab-bqty-input,
			.woocommerce_options_panel input.sijab-bqty-input[type="number"],
			#woocommerce-product-data input.sijab-bqty-input[type="number"] {
				width: 46px !important;
				min-width: 46px !important;
				max-width: 46px !important;
				height: 30px !important;
				padding: 0 !important;
				margin: 0 !important;
				border: none !important;
				border-left: 1px solid #dcdcde !important;
				border-right: 1px solid #dcdcde !important;
				border-radius: 0 !important;
				font-size: 14px !important;
				font-weight: 600 !important;
				text-align: center !important;
				display: block !important;
				visibility: visible !important;
				opacity: 1 !important;
				background: #fff !important;
				box-shadow: none !important;
				outline: none !important;
				float: none !important;
				flex: 0 0 46px !important;
				-moz-appearance: textfield !important;
			}
			input.sijab-bqty-input::-webkit-inner-spin-button,
			input.sijab-bqty-input::-webkit-outer-spin-button { -webkit-appearance: none !important; margin: 0 !important; }
			.sijab-bqty-unit {
				font-size: 13px !important;
				font-weight: 600 !important;
				color: #50575e !important;
				flex: 0 0 auto !important;
			}
			a.sijab-bundle-remove {
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				width: 28px !important;
				min-width: 28px !important;
				height: 28px !important;
				border-radius: 50% !important;
				color: #a00 !important;
				font-size: 14px !important;
				text-decoration: none !important;
				transition: all 0.15s !important;
				flex: 0 0 28px !important;
			}
			a.sijab-bundle-remove:hover {
				background: #d63638 !important;
				color: #fff !important;
			}

			/* ── Bundle pricing box ── */
			.sijab-pricing-box {
				margin: 8px 12px 12px !important;
				padding: 12px 16px !important;
				border: 1px solid #dcdcde !important;
				border-radius: 8px !important;
				background: #f9f9f9 !important;
			}
			.sijab-pricing-row {
				display: flex !important;
				justify-content: space-between !important;
				align-items: center !important;
				padding: 6px 0 !important;
				font-size: 13px !important;
			}
			.sijab-pricing-row + .sijab-pricing-row {
				border-top: 1px solid #eee !important;
			}
			.sijab-pricing-label {
				font-weight: 500 !important;
				color: #50575e !important;
			}
			.sijab-pricing-value {
				font-weight: 600 !important;
				color: #1d2327 !important;
				display: flex !important;
				align-items: center !important;
				gap: 6px !important;
			}
			.sijab-pricing-total {
				background: #e8f5e9 !important;
				margin: 4px -16px !important;
				padding: 10px 16px !important;
				border-radius: 6px !important;
				font-size: 15px !important;
			}
			.sijab-pricing-total .sijab-pricing-value {
				color: #2e7d32 !important;
				font-size: 16px !important;
			}
			.sijab-discount-input-wrap {
				display: inline-flex !important;
				align-items: center !important;
				border: 1px solid #8c8f94 !important;
				border-radius: 4px !important;
				overflow: hidden !important;
				background: #fff !important;
			}
			.woocommerce_options_panel input.sijab-discount-input[type="number"],
			#woocommerce-product-data input.sijab-discount-input[type="number"] {
				width: 60px !important;
				min-width: 60px !important;
				max-width: 60px !important;
				height: 30px !important;
				padding: 0 4px !important;
				margin: 0 !important;
				border: none !important;
				border-radius: 0 !important;
				text-align: right !important;
				font-size: 14px !important;
				font-weight: 600 !important;
				background: #fff !important;
				box-shadow: none !important;
				-moz-appearance: textfield !important;
			}
			input.sijab-discount-input::-webkit-inner-spin-button,
			input.sijab-discount-input::-webkit-outer-spin-button { -webkit-appearance: none !important; }
			.sijab-discount-pct {
				padding: 0 8px !important;
				font-weight: 600 !important;
				color: #50575e !important;
				background: #f0f0f0 !important;
				height: 30px !important;
				line-height: 30px !important;
			}
			.sijab-discount-amount {
				color: #d63638 !important;
				font-size: 13px !important;
			}
			.sijab-pricing-actions {
				display: flex !important;
				align-items: center !important;
				gap: 10px !important;
				padding: 10px 0 2px !important;
				border-top: 1px solid #eee !important;
				margin-top: 4px !important;
			}
			.sijab-price-status {
				font-size: 13px !important;
				font-style: italic !important;
			}

			#sijab_accessories_data .form-field label { width: 220px; }
			#sijab_acc_sortable { margin: 0; padding: 0; list-style: none; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; }
			#sijab_acc_sortable:empty { border: none; }
			#sijab_acc_sortable .sijab-acc-sortable-item {
				display: flex; align-items: center; gap: 10px;
				padding: 8px 14px; margin: 0;
				background: #fff; border-bottom: 1px solid #eee;
				transition: background 0.15s;
			}
			#sijab_acc_sortable .sijab-acc-sortable-item:last-child { border-bottom: none; }
			#sijab_acc_sortable .sijab-acc-sortable-item:hover { background: #f7fafc; }
			#sijab_acc_sortable .sijab-acc-sortable-item:nth-child(even) { background: #fafbfc; }
			#sijab_acc_sortable .sijab-acc-sortable-item:nth-child(even):hover { background: #f0f5fa; }
			#sijab_acc_sortable .sijab-acc-sortable-item.ui-sortable-helper {
				box-shadow: 0 2px 8px rgba(0,0,0,0.12); background: #fff; border-radius: 4px;
			}
			#sijab_acc_sortable .sijab-acc-sortable-item.ui-sortable-placeholder {
				visibility: visible !important; background: #f0f6ff; border: 1px dashed #4a90d9;
			}
			.sijab-acc-drag-handle {
				font-size: 16px; color: #ccc; cursor: grab; flex-shrink: 0;
				padding: 0 2px; line-height: 1;
			}
			.sijab-acc-drag-handle:hover { color: #888; }
			.sijab-acc-drag-handle:active { cursor: grabbing; color: #555; }
			#sijab_acc_sortable .sijab-acc-sortable-item img {
				width: 36px; height: 36px; object-fit: contain;
				border-radius: 4px; border: 1px solid #e8e8e8; flex-shrink: 0;
				background: #fff;
			}
			.sijab-acc-item-name {
				flex: 1; font-size: 13px; color: #333; font-weight: 500;
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
			}
			.sijab-acc-remove {
				flex-shrink: 0; font-size: 11px; color: #a00; text-decoration: none;
				padding: 3px 8px; border-radius: 3px; border: 1px solid transparent;
				transition: all 0.15s; font-weight: 500;
			}
			.sijab-acc-remove:hover { color: #fff; background: #d63638; border-color: #d63638; text-decoration: none; }
		';
		// Output CSS via admin_head since wp_add_inline_style doesn't work
		// when WooCommerce has already printed the stylesheet.
		add_action( 'admin_head', function() use ( $css ) {
			echo '<style id="sijab-admin-bundle-css">' . $css . '</style>';
		} );

		// Sortable JS
		wp_enqueue_script( 'jquery-ui-sortable' );
		$js = "
		jQuery(function($){
			var sortable = $('#sijab_acc_sortable');

			// Make list sortable
			sortable.sortable({
				handle: '.sijab-acc-drag-handle',
				placeholder: 'ui-sortable-placeholder',
				axis: 'y',
				cursor: 'grabbing',
				tolerance: 'pointer',
				containment: 'parent',
				forcePlaceholderSize: true,
				start: function(e, ui) {
					ui.placeholder.height(ui.item.outerHeight());
				}
			});

			// Add from search
			$('#sijab_acc_add_btn').on('click', function(){
				var sel = $('#sijab_acc_search');
				var id = sel.val();
				if (!id) return;
				if (sortable.find('.sijab-acc-sortable-item[data-id=\"'+id+'\"]').length) {
					alert('" . esc_js( __( 'Denna produkt finns redan i listan.', 'sijab-tillbehor' ) ) . "');
					sel.val(null).trigger('change');
					return;
				}
				var text = sel.find('option:selected').text();
				var li = '<li class=\"sijab-acc-sortable-item\" data-id=\"'+id+'\">'
					+ '<input type=\"hidden\" name=\"sijab_accessories_ids[]\" value=\"'+id+'\" />'
					+ '<span class=\"sijab-acc-drag-handle\" title=\"Dra f\\u00f6r att sortera\">\\u2630</span>'
					+ '<img src=\"" . esc_js( wc_placeholder_img_src( 'thumbnail' ) ) . "\" />'
					+ '<span class=\"sijab-acc-item-name\">' + $('<span>').text(text).html() + '</span>'
					+ '<a href=\"#\" class=\"sijab-acc-remove\" title=\"Ta bort\">Ta bort</a>'
					+ '</li>';
				sortable.append(li);
				sortable.sortable('refresh');
				$('#sijab_acc_empty_msg').hide();
				sel.val(null).trigger('change');
			});

			// Remove item
			$(document).on('click', '.sijab-acc-remove', function(e){
				e.preventDefault();
				$(this).closest('.sijab-acc-sortable-item').slideUp(200, function(){
					$(this).remove();
					sortable.sortable('refresh');
					if (sortable.find('.sijab-acc-sortable-item').length === 0) {
						$('#sijab_acc_empty_msg').show();
					}
				});
			});

			// AI Suggest accessories
			$('#sijab_ai_suggest_btn').on('click', function(){
				var btn = $(this);
				var spinner = $('#sijab_ai_spinner');
				btn.prop('disabled', true);
				spinner.addClass('is-active');
				$('#sijab_ai_results').hide();

				$.post(ajaxurl, {
					action: 'sijab_suggest_accessories',
					nonce: $('#sijab_suggest_nonce').val(),
					product_id: btn.data('product-id')
				}, function(resp) {
					spinner.removeClass('is-active');
					btn.prop('disabled', false);
					if (!resp.success) {
						alert(resp.data || 'Fel vid AI-förfrågan.');
						return;
					}
					var products = resp.data.products;
					if (!products.length) {
						alert('Inga matchande produkter hittades i butiken.');
						return;
					}
					var html = '<div style=\"max-height:400px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; padding:8px; background:#f9f9f9;\">';
					var currentKw = '';
					products.forEach(function(p) {
						if (p.keyword !== currentKw) {
							currentKw = p.keyword;
							html += '<div style=\"margin:8px 0 4px; padding:4px 0; font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #eee;\">' + $('<span>').text(currentKw).html() + '</div>';
						}
						var safeName = $('<span>').text(p.name).html();
						var safePrice = $('<span>').text(p.price).html();
						html += '<label style=\"display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e0e0e0; border-radius:4px; margin-bottom:4px; background:#fff; cursor:pointer;\">'
							+ '<input type=\"checkbox\" class=\"sijab-ai-check\" value=\"' + p.id + '\" data-name=\"' + safeName + '\" data-thumb=\"' + p.thumb + '\" checked style=\"flex-shrink:0;\" />'
							+ '<img src=\"' + p.thumb + '\" style=\"width:40px; height:40px; object-fit:contain; border-radius:3px; border:1px solid #ddd; flex-shrink:0;\" />'
							+ '<span style=\"flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px;\">' + safeName + '</span>'
							+ '<span style=\"flex-shrink:0; color:#555; font-size:12px; white-space:nowrap; margin-left:8px;\">' + safePrice + '</span>'
							+ '</label>';
					});
					html += '</div>';
					$('#sijab_ai_results_list').html(html);
					$('#sijab_ai_results').slideDown();
				}).fail(function() {
					spinner.removeClass('is-active');
					btn.prop('disabled', false);
					alert('Nätverksfel. Försök igen.');
				});
			});

			// Add selected AI suggestions to sortable list
			$('#sijab_ai_add_selected').on('click', function(){
				$('.sijab-ai-check:checked').each(function(){
					var id = $(this).val();
					var name = $(this).data('name');
					var thumb = $(this).data('thumb');
					if (sortable.find('.sijab-acc-sortable-item[data-id=\"'+id+'\"]').length) return;
					var li = '<li class=\"sijab-acc-sortable-item\" data-id=\"'+id+'\">'
						+ '<input type=\"hidden\" name=\"sijab_accessories_ids[]\" value=\"'+id+'\" />'
						+ '<span class=\"sijab-acc-drag-handle\" title=\"Dra f\\u00f6r att sortera\">\\u2630</span>'
						+ '<img src=\"' + thumb + '\" />'
						+ '<span class=\"sijab-acc-item-name\">' + name + '</span>'
						+ '<a href=\"#\" class=\"sijab-acc-remove\" title=\"Ta bort\">Ta bort</a>'
						+ '</li>';
					sortable.append(li);
				});
				sortable.sortable('refresh');
				$('#sijab_acc_empty_msg').hide();
				$('#sijab_ai_results').slideUp();
			});

			// Dismiss AI results
			$('#sijab_ai_dismiss').on('click', function(){
				$('#sijab_ai_results').slideUp();
			});

			// Save accessory category via AJAX
			$('#sijab_save_category_btn').on('click', function(){
				var btn = $(this);
				var catId = $('#sijab_acc_category').val();
				var status = $('#sijab_cat_status');
				btn.prop('disabled', true).text('" . esc_js( __( 'Sparar…', 'sijab-tillbehor' ) ) . "');
				status.hide();
				$.post(ajaxurl, {
					action: 'sijab_save_acc_category',
					nonce: $('#sijab_accessories_nonce').val(),
					product_id: " . absint( $post->ID ) . ",
					category_id: catId
				}, function(resp) {
					btn.prop('disabled', false).text('" . esc_js( __( 'Spara kategori', 'sijab-tillbehor' ) ) . "');
					if (resp.success) {
						status.text('✓ Sparad').css('color', '#46b450').show();
						setTimeout(function(){ status.fadeOut(); }, 3000);
					} else {
						status.text('✗ Fel').css('color', '#a00').show();
					}
				}).fail(function() {
					btn.prop('disabled', false).text('" . esc_js( __( 'Spara kategori', 'sijab-tillbehor' ) ) . "');
					status.text('✗ Nätverksfel').css('color', '#a00').show();
				});
			});
		});
		";
		wp_add_inline_script( 'jquery-ui-sortable', $js );
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	private function get_accessory_ids( $product_id ): array {
		// Manuellt tillagda via Accessory Tab-admin.
		$manual_ids = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! is_array( $manual_ids ) ) $manual_ids = [];

		// Korsförsäljning via WooCommerce-metoden — fungerar för alla produkttyper inkl. variabla.
		$product       = wc_get_product( $product_id );
		$crosssell_ids = $product ? $product->get_cross_sell_ids() : [];

		$ids = array_merge( $manual_ids, $crosssell_ids );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ), function( $id ) {
			return $id > 0;
		} ) ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Bundle — Admin
	// ──────────────────────────────────────────────────────────────

	public function add_bundle_admin_tab( $tabs ): array {
		$tabs['sijab_bundle'] = [
			'label'    => __( 'Paket', 'sijab-tillbehor' ),
			'target'   => 'sijab_bundle_data',
			'class'    => [ 'show_if_simple' ],
			'priority' => 81,
		];
		return $tabs;
	}

	public function render_bundle_admin_panel(): void {
		global $post;
		$is_bundle = (bool) get_post_meta( $post->ID, self::BUNDLE_FLAG, true );
		$items     = get_post_meta( $post->ID, self::BUNDLE_META, true );
		if ( ! is_array( $items ) ) $items = [];
		?>
		<div id="sijab_bundle_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php wp_nonce_field( 'sijab_save_bundle', 'sijab_bundle_nonce' ); ?>
				<p class="form-field">
					<label for="sijab_is_bundle"><?php esc_html_e( 'Aktivera paket', 'sijab-tillbehor' ); ?></label>
					<input type="checkbox" id="sijab_is_bundle" name="sijab_is_bundle" value="1" <?php checked( $is_bundle ); ?> />
					<span class="description"><?php esc_html_e( 'Markera produkten som ett paket och visa ingående delar på produktsidan.', 'sijab-tillbehor' ); ?></span>
				</p>
			</div>

			<div class="options_group" id="sijab_bundle_items_wrap" style="<?php echo $is_bundle ? '' : 'display:none;'; ?>">
				<p style="padding: 10px 12px 0; font-weight: 600;"><?php esc_html_e( 'Ingående produkter', 'sijab-tillbehor' ); ?></p>

				<div id="sijab_bundle_rows">
					<?php foreach ( $items as $i => $item ) :
						$p = wc_get_product( $item['product_id'] ?? 0 );
						if ( ! $p ) continue;
						?>
						<div class="sijab-bundle-row">
							<div class="sijab-brow-product">
								<select class="wc-product-search" name="sijab_bundle_items[<?php echo $i; ?>][product_id]" data-placeholder="<?php esc_attr_e( 'Sök produkt…', 'sijab-tillbehor' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="false">
									<option value="<?php echo absint( $item['product_id'] ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
								</select>
							</div>
							<div class="sijab-brow-actions">
								<div class="sijab-bqty-pill">
									<button type="button" class="sijab-bqty-btn sijab-bqty-minus">−</button>
									<input type="number" name="sijab_bundle_items[<?php echo $i; ?>][qty_default]" value="<?php echo absint( $item['qty_default'] ?? 1 ); ?>" min="1" class="sijab-bqty-input" />
									<button type="button" class="sijab-bqty-btn sijab-bqty-plus">+</button>
								</div>
								<span class="sijab-bqty-unit">st</span>
								<a href="#" class="sijab-bundle-remove" title="<?php esc_attr_e( 'Ta bort', 'sijab-tillbehor' ); ?>">✕</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<p style="padding: 8px 12px;">
					<button type="button" class="button" id="sijab_add_bundle_row"><?php esc_html_e( '+ Lägg till produkt', 'sijab-tillbehor' ); ?></button>
				</p>
			</div>

			<?php
			$bundle_discount = floatval( get_post_meta( $post->ID, '_sijab_bundle_discount', true ) );
			$current_price   = get_post_meta( $post->ID, '_regular_price', true );
			?>
			<div id="sijab_bundle_pricing" class="options_group" style="<?php echo $is_bundle ? '' : 'display:none;'; ?>">
				<p style="padding: 10px 12px 0; font-weight: 600;"><?php esc_html_e( 'Paketpris', 'sijab-tillbehor' ); ?></p>

				<div class="sijab-pricing-box">
					<div class="sijab-pricing-row">
						<span class="sijab-pricing-label"><?php esc_html_e( 'Summa artiklar:', 'sijab-tillbehor' ); ?></span>
						<span class="sijab-pricing-value" id="sijab_bundle_subtotal">—</span>
					</div>
					<div class="sijab-pricing-row">
						<span class="sijab-pricing-label">
							<?php esc_html_e( 'Rabatt:', 'sijab-tillbehor' ); ?>
						</span>
						<span class="sijab-pricing-value">
							<div class="sijab-discount-input-wrap">
								<input type="number" id="sijab_bundle_discount" name="sijab_bundle_discount" value="<?php echo esc_attr( $bundle_discount ?: '' ); ?>" min="0" max="100" step="0.5" placeholder="0" class="sijab-discount-input" />
								<span class="sijab-discount-pct">%</span>
							</div>
							<span id="sijab_bundle_discount_amount" class="sijab-discount-amount"></span>
						</span>
					</div>
					<div class="sijab-pricing-row sijab-pricing-total">
						<span class="sijab-pricing-label"><?php esc_html_e( 'Paketpris:', 'sijab-tillbehor' ); ?></span>
						<span class="sijab-pricing-value" id="sijab_bundle_total">—</span>
					</div>
					<div class="sijab-pricing-row">
						<span class="sijab-pricing-label"><?php esc_html_e( 'Nuvarande pris:', 'sijab-tillbehor' ); ?></span>
						<span class="sijab-pricing-value" id="sijab_current_price"><?php echo $current_price ? wc_price( $current_price ) : '—'; ?></span>
					</div>
					<div class="sijab-pricing-actions">
						<button type="button" class="button button-primary" id="sijab_set_bundle_price"><?php esc_html_e( 'Sätt paketpris', 'sijab-tillbehor' ); ?></button>
						<span id="sijab_price_status" class="sijab-price-status"></span>
					</div>
				</div>
			</div>

			<div id="sijab_ai_section" class="options_group" style="<?php echo $is_bundle ? '' : 'display:none;'; ?>">
				<p style="padding: 10px 12px 0; font-weight: 600;"><?php esc_html_e( 'AI-genererat innehåll', 'sijab-tillbehor' ); ?></p>
				<p style="padding: 6px 12px 4px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
					<button type="button" class="button button-primary" id="sijab_generate_ai_btn">
						<?php esc_html_e( 'Generera med AI', 'sijab-tillbehor' ); ?>
					</button>
					<span id="sijab_ai_spinner" class="spinner" style="float:none; display:none;"></span>
					<span id="sijab_ai_status" style="font-style:italic; color:#666; font-size:13px;"></span>
				</p>
				<?php if ( ! get_option( 'sijab_openai_api_key', '' ) ) : ?>
					<p class="description" style="padding: 0 12px 12px; color:#a00;">
						<?php esc_html_e( 'Ange en OpenAI API-nyckel under WooCommerce → Tillbehör → Inställningar.', 'sijab-tillbehor' ); ?>
					</p>
				<?php else : ?>
					<p class="description" style="padding: 0 12px 12px;">
						<?php esc_html_e( 'Genererar produkttitel, beskrivning, kort beskrivning och kollage-bild baserat på ingående produkter. Inget sparas förrän du klickar Uppdatera.', 'sijab-tillbehor' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(function($) {
			var rowIndex = <?php echo max( count( $items ), 1 ); ?>;

			$('#sijab_is_bundle').on('change', function() {
				$('#sijab_bundle_items_wrap, #sijab_ai_section, #sijab_bundle_pricing').toggle(this.checked);
			});

			// ── Bundle pricing calculator ──
			var priceCache = {};
			var bundleNonce = '<?php echo wp_create_nonce( 'sijab_save_bundle' ); ?>';
			var currencySymbol = '<?php echo esc_js( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>';

			function formatPrice(amount) {
				return parseFloat(amount).toLocaleString('sv-SE', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + currencySymbol;
			}

			function recalcBundlePrice() {
				var rows = $('#sijab_bundle_rows .sijab-bundle-row');
				if (!rows.length) {
					$('#sijab_bundle_subtotal').text('\u2014');
					$('#sijab_bundle_total').text('\u2014');
					$('#sijab_bundle_discount_amount').text('');
					return;
				}

				// Collect product IDs we need prices for
				var needFetch = [];
				var allIds = [];
				rows.each(function() {
					var sel = $(this).find('select.wc-product-search');
					var pid = sel.val();
					if (pid) {
						allIds.push({ id: parseInt(pid), qty: parseInt($(this).find('.sijab-bqty-input').val()) || 1 });
						if (!priceCache[pid]) needFetch.push(pid);
					}
				});

				if (!allIds.length) {
					$('#sijab_bundle_subtotal').text('\u2014');
					$('#sijab_bundle_total').text('\u2014');
					return;
				}

				function doCalc() {
					var subtotal = 0;
					allIds.forEach(function(item) {
						var p = priceCache[item.id];
						if (p) subtotal += p.price * item.qty;
					});

					var discount = parseFloat($('#sijab_bundle_discount').val()) || 0;
					var discountAmount = subtotal * (discount / 100);
					var total = subtotal - discountAmount;

					$('#sijab_bundle_subtotal').text(formatPrice(subtotal));
					$('#sijab_bundle_total').text(formatPrice(Math.round(total * 100) / 100));
					if (discount > 0 && subtotal > 0) {
						$('#sijab_bundle_discount_amount').text('-' + formatPrice(Math.round(discountAmount * 100) / 100));
					} else {
						$('#sijab_bundle_discount_amount').text('');
					}
				}

				if (needFetch.length) {
					$('#sijab_bundle_subtotal').text('<?php echo esc_js( __( 'Hämtar priser…', 'sijab-tillbehor' ) ); ?>');
					$.post(ajaxurl, {
						action: 'sijab_get_product_prices',
						nonce: bundleNonce,
						product_ids: needFetch.join(',')
					}, function(res) {
						if (res.success) {
							$.each(res.data, function(id, info) {
								priceCache[id] = info;
							});
						}
						doCalc();
					});
				} else {
					doCalc();
				}
			}

			// "Sätt paketpris" button — copies calculated price to WC regular price field
			$('#sijab_set_bundle_price').on('click', function() {
				var totalText = $('#sijab_bundle_total').text().replace(/[^\d,.\-]/g, '').replace(',', '.');
				var total = parseFloat(totalText);
				if (isNaN(total) || total <= 0) {
					$('#sijab_price_status').css('color', '#a00').text('<?php echo esc_js( __( 'Inget pris att sätta.', 'sijab-tillbehor' ) ); ?>');
					return;
				}
				// Set WooCommerce regular price field
				$('#_regular_price').val(total.toFixed(2));
				$('#sijab_current_price').html(formatPrice(total));
				$('#sijab_price_status').css('color', '#46b450').text('<?php echo esc_js( __( 'Pris satt! Klicka Uppdatera för att spara.', 'sijab-tillbehor' ) ); ?>');
			});

			// Trigger recalc on changes
			$(document).on('change', '#sijab_bundle_rows select.wc-product-search', recalcBundlePrice);
			$(document).on('change input', '.sijab-bqty-input', recalcBundlePrice);
			$(document).on('click', '.sijab-bqty-minus, .sijab-bqty-plus', function() { setTimeout(recalcBundlePrice, 50); });
			$(document).on('click', '.sijab-bundle-remove', function() { setTimeout(recalcBundlePrice, 50); });
			$('#sijab_bundle_discount').on('input change', recalcBundlePrice);

			// Initial calculation on page load
			<?php if ( $is_bundle && ! empty( $items ) ) : ?>
			// Pre-populate price cache from PHP
			<?php
			$price_data = [];
			foreach ( $items as $item ) {
				$p = wc_get_product( $item['product_id'] ?? 0 );
				if ( $p ) {
					$price_data[ $item['product_id'] ] = [
						'price'         => floatval( $p->get_price() ),
						'regular_price' => floatval( $p->get_regular_price() ),
						'name'          => $p->get_name(),
					];
				}
			}
			?>
			priceCache = <?php echo wp_json_encode( $price_data ); ?>;
			recalcBundlePrice();
			<?php endif; ?>

			$('#sijab_generate_ai_btn').on('click', function() {
				var btn    = $(this);
				var spin   = $('#sijab_ai_spinner');
				var status = $('#sijab_ai_status');
				var postId = <?php echo absint( $post->ID ); ?>;

				btn.prop('disabled', true);
				spin.show();
				status.css('color', '#666').text('<?php echo esc_js( __( 'Genererar — kan ta 10–20 sekunder…', 'sijab-tillbehor' ) ); ?>');

				$.post(ajaxurl, {
					action:     'sijab_generate_bundle_content',
					nonce:      '<?php echo wp_create_nonce( 'sijab_generate_bundle' ); ?>',
					product_id: postId
				}, function(res) {
					btn.prop('disabled', false);
					spin.hide();

					if (!res.success) {
						status.css('color', '#a00').text(res.data || '<?php echo esc_js( __( 'Fel uppstod.', 'sijab-tillbehor' ) ); ?>');
						return;
					}

					var d = res.data;
					status.css('color', '#46b450').text('<?php echo esc_js( __( 'Klart! Granska innehållet och klicka Uppdatera för att spara.', 'sijab-tillbehor' ) ); ?>');

					// Title
					if (d.title) {
						$('#title').val(d.title);
					}

					// Description (main content)
					if (d.description) {
						if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
							tinymce.get('content').setContent(d.description);
						} else {
							$('#content').val(d.description);
						}
					}

					// Short description / excerpt
					if (d.short_description) {
						if (typeof tinymce !== 'undefined' && tinymce.get('excerpt')) {
							tinymce.get('excerpt').setContent('<p>' + $('<span>').text(d.short_description).html() + '</p>');
						} else {
							$('#excerpt').val(d.short_description);
						}
					}

					// Featured image (collage)
					if (d.collage_id) {
						$.post(ajaxurl, {
							action:                          'set-post-thumbnail',
							post_id:                         postId,
							thumbnail_id:                    d.collage_id,
							'_ajax_nonce-set-post-thumbnail': '<?php echo wp_create_nonce( 'set_post_thumbnail-' . absint( $post->ID ) ); ?>'
						}, function(html) {
							$('#postimagediv .inside').html(html);
						});
					} else if (d.collage_error) {
						status.css('color', '#b45309').text('<?php echo esc_js( __( 'Text klar — bild misslyckades: ', 'sijab-tillbehor' ) ); ?>' + d.collage_error);
					}
				}).fail(function() {
					btn.prop('disabled', false);
					spin.hide();
					status.css('color', '#a00').text('<?php echo esc_js( __( 'Nätverksfel. Försök igen.', 'sijab-tillbehor' ) ); ?>');
				});
			});

			$('#sijab_add_bundle_row').on('click', function() {
				var html = '<div class="sijab-bundle-row">'
					+ '<div class="sijab-brow-product">'
					+ '<select class="wc-product-search" name="sijab_bundle_items[' + rowIndex + '][product_id]" data-placeholder="<?php esc_attr_e( 'Sök produkt…', 'sijab-tillbehor' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="false"></select>'
					+ '</div>'
					+ '<div class="sijab-brow-actions">'
					+ '<div class="sijab-bqty-pill"><button type="button" class="sijab-bqty-btn sijab-bqty-minus">\u2212</button>'
					+ '<input type="number" name="sijab_bundle_items[' + rowIndex + '][qty_default]" value="1" min="1" class="sijab-bqty-input" />'
					+ '<button type="button" class="sijab-bqty-btn sijab-bqty-plus">+</button></div>'
					+ '<span class="sijab-bqty-unit">st</span>'
					+ '<a href="#" class="sijab-bundle-remove" title="Ta bort">\u2715</a>'
					+ '</div>'
					+ '</div>';
				$('#sijab_bundle_rows').append(html);
				$(document.body).trigger('wc-enhanced-select-init');
				rowIndex++;
			});

			// Bundle qty +/- buttons.
			$(document).on('click', '.sijab-bqty-minus, .sijab-bqty-plus', function() {
				var pill  = $(this).closest('.sijab-bqty-pill');
				var input = pill.find('.sijab-bqty-input');
				var val   = parseInt(input.val(), 10) || 1;
				var min   = parseInt(input.attr('min'), 10) || 1;
				if ($(this).hasClass('sijab-bqty-minus')) {
					val = Math.max(min, val - 1);
				} else {
					val = val + 1;
				}
				input.val(val);
			});

			$(document).on('click', '.sijab-bundle-remove', function() {
				$(this).closest('.sijab-bundle-row').remove();
			});
		});
		</script>
		<?php
	}

	public function save_bundle_data( $product ): void {
		if ( ! isset( $_POST['sijab_bundle_nonce'] ) || ! wp_verify_nonce( $_POST['sijab_bundle_nonce'], 'sijab_save_bundle' ) ) return;
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) return;

		$is_bundle = ! empty( $_POST['sijab_is_bundle'] );
		update_post_meta( $product->get_id(), self::BUNDLE_FLAG, $is_bundle ? '1' : '' );

		$items = [];
		if ( $is_bundle && ! empty( $_POST['sijab_bundle_items'] ) && is_array( $_POST['sijab_bundle_items'] ) ) {
			foreach ( $_POST['sijab_bundle_items'] as $row ) {
				$pid = absint( $row['product_id'] ?? 0 );
				if ( ! $pid || ! wc_get_product( $pid ) ) continue;
				$items[] = [
					'product_id'  => $pid,
					'qty_default' => max( 1, absint( $row['qty_default'] ?? 1 ) ),
				];
			}
		}

		update_post_meta( $product->get_id(), self::BUNDLE_META, $items );

		// Save bundle discount percentage.
		$discount = isset( $_POST['sijab_bundle_discount'] ) ? floatval( $_POST['sijab_bundle_discount'] ) : 0;
		$discount = max( 0, min( 100, $discount ) );
		update_post_meta( $product->get_id(), '_sijab_bundle_discount', $discount ? $discount : '' );
	}

	/**
	 * AJAX: Return prices for given product IDs (for bundle pricing calculator).
	 */
	public function ajax_get_product_prices(): void {
		check_ajax_referer( 'sijab_save_bundle', 'nonce' );
		$ids = array_map( 'absint', explode( ',', sanitize_text_field( $_POST['product_ids'] ?? '' ) ) );
		$prices = [];
		foreach ( $ids as $id ) {
			if ( ! $id ) continue;
			$p = wc_get_product( $id );
			if ( ! $p ) continue;
			$prices[ $id ] = [
				'price'         => floatval( $p->get_price() ),
				'regular_price' => floatval( $p->get_regular_price() ),
				'name'          => $p->get_name(),
			];
		}
		wp_send_json_success( $prices );
	}

	// ──────────────────────────────────────────────────────────────
	// AI Content Generation
	// ──────────────────────────────────────────────────────────────

	public function ajax_generate_bundle_content(): void {
		check_ajax_referer( 'sijab_generate_bundle', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( 'Inget produkt-ID.' );
		}

		$items = $this->get_bundle_items( $product_id );
		if ( empty( $items ) ) {
			wp_send_json_error( 'Paketet har inga ingående produkter.' );
		}

		$api_key = get_option( 'sijab_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( 'Ingen OpenAI API-nyckel konfigurerad. Ange den under WooCommerce → Tillbehör → Inställningar.' );
		}

		// Collect component data.
		$lines = [];
		foreach ( $items as $item ) {
			$p = wc_get_product( $item['product_id'] );
			if ( ! $p ) continue;
			$desc  = mb_substr( wp_strip_all_tags( $p->get_description() ), 0, 300 );
			$line  = sprintf( '- %s (antal: %d, SKU: %s, pris: %s kr)', $p->get_name(), $item['qty_default'], $p->get_sku() ?: '–', $p->get_price() );
			if ( $desc ) $line .= "\n  Beskrivning: " . $desc;
			$lines[] = $line;
		}

		$components_text = implode( "\n", $lines );

		$prompt = "Du är en copywriter för ett B2B-företag som säljer professionell utrustning. " .
		          "Nedan finns ingående produkter i ett paket. Skriv på svenska.\n\n" .
		          "Ingående produkter:\n{$components_text}\n\n" .
		          "Returnera ett JSON-objekt med exakt dessa nycklar:\n" .
		          "- title: Kort produkttitel för paketet (max 80 tecken)\n" .
		          "- description: Lång produktbeskrivning som HTML (2-3 stycken med <p>-taggar)\n" .
		          "- short_description: Kort beskrivning för excerpt (1-2 meningar, ren text utan HTML)\n" .
		          "- seo_text: SEO meta description (max 160 tecken, ren text)";

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'model'           => get_option( 'sijab_openai_model', 'gpt-4o' ),
				'response_format' => [ 'type' => 'json_object' ],
				'messages'        => [
					[ 'role' => 'user', 'content' => $prompt ],
				],
				'max_tokens'      => 1000,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			wp_send_json_error( $body['error']['message'] ?? 'Fel från OpenAI.' );
		}

		$raw     = $body['choices'][0]['message']['content'] ?? '';
		$content = json_decode( $raw, true );

		if ( ! is_array( $content ) ) {
			wp_send_json_error( 'Kunde inte tolka svar från OpenAI.' );
		}

		// Generate collage image.
		$collage = $this->generate_bundle_collage( $product_id, $items );

		wp_send_json_success( [
			'title'             => sanitize_text_field( $content['title'] ?? '' ),
			'description'       => wp_kses_post( $content['description'] ?? '' ),
			'short_description' => sanitize_text_field( $content['short_description'] ?? '' ),
			'seo_text'          => sanitize_text_field( $content['seo_text'] ?? '' ),
			'collage_id'        => $collage['id'] ?? 0,
			'collage_url'       => $collage['url'] ?? '',
			'collage_error'     => $collage['error'] ?? '',
		] );
	}

	/**
	 * AI: Suggest accessories for a product.
	 */
	public function ajax_suggest_accessories(): void {
		check_ajax_referer( 'sijab_suggest_accessories', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( 'Produkt hittades inte.' );
		}

		$api_key = get_option( 'sijab_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( 'Ingen OpenAI API-nyckel konfigurerad. Ange den under Tillbehör → API-inställningar.' );
		}

		// Build product context.
		$prod_name = $product->get_name();
		$prod_desc = mb_substr( wp_strip_all_tags( $product->get_description() ), 0, 500 );
		$prod_cats = [];
		$terms     = get_the_terms( $product_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) $prod_cats[] = $t->name;
		}
		$cats_str = implode( ', ', $prod_cats );

		// Get all product names in store for matching context.
		$prompt = "Du är en expert på B2B-produkter och tillbehör. En kund säljer professionell utrustning.\n\n" .
		          "Produkt: {$prod_name}\n" .
		          ( $cats_str ? "Kategorier: {$cats_str}\n" : '' ) .
		          ( $prod_desc ? "Beskrivning: {$prod_desc}\n" : '' ) .
		          "\nFöreslå 5-10 typer av tillbehör som passar till denna produkt. " .
		          "Svara med ett JSON-objekt med nyckeln \"keywords\" som innehåller en array av korta svenska sökord (1-3 ord per sökord). " .
		          "Sökorden ska matcha typiska produktnamn i en webbutik. Var specifik, inte generisk.\n" .
		          "Exempel: {\"keywords\": [\"pump\", \"slang\", \"adapter\", \"munstycke\"]}";

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'model'           => get_option( 'sijab_openai_model', 'gpt-4o' ),
				'response_format' => [ 'type' => 'json_object' ],
				'messages'        => [
					[ 'role' => 'user', 'content' => $prompt ],
				],
				'max_tokens' => 300,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['error'] ) ) {
			wp_send_json_error( $body['error']['message'] ?? 'Fel från OpenAI.' );
		}

		$raw      = $body['choices'][0]['message']['content'] ?? '';
		$content  = json_decode( $raw, true );
		$keywords = $content['keywords'] ?? [];

		if ( empty( $keywords ) || ! is_array( $keywords ) ) {
			wp_send_json_error( 'AI:n kunde inte föreslå tillbehör.' );
		}

		// Search WooCommerce products matching the keywords.
		$existing_ids = $this->get_accessory_ids( $product_id );
		$found        = [];
		$seen_ids     = array_flip( $existing_ids );
		$seen_ids[ $product_id ] = true;

		foreach ( $keywords as $kw ) {
			$kw = sanitize_text_field( $kw );
			if ( empty( $kw ) ) continue;

			$query = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => $kw,
				'posts_per_page' => 5,
				'fields'         => 'ids',
			] );

			foreach ( $query->posts as $pid ) {
				$pid = (int) $pid;
				if ( isset( $seen_ids[ $pid ] ) ) continue;
				$seen_ids[ $pid ] = true;

				$p = wc_get_product( $pid );
				if ( ! $p || ! $p->is_visible() ) continue;

				$thumb_id  = $p->get_image_id();
				$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

				$found[] = [
					'id'       => $pid,
					'name'     => wp_strip_all_tags( $p->get_name() ),
					'sku'      => $p->get_sku(),
					'price'    => wp_strip_all_tags( html_entity_decode( $p->get_price_html(), ENT_QUOTES, 'UTF-8' ) ),
					'thumb'    => $thumb_url,
					'keyword'  => $kw,
				];
			}
			wp_reset_postdata();
		}

		wp_send_json_success( [
			'keywords' => $keywords,
			'products' => $found,
		] );
	}

	/**
	 * Load a GD image resource from a local file path, with correct handling
	 * for JPEG, PNG, GIF and WebP.
	 *
	 * @return \GdImage|false
	 */
	private function gd_from_path( string $filepath ) {
		if ( ! file_exists( $filepath ) ) return false;

		$info = @getimagesize( $filepath );
		if ( ! $info ) return false;

		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $filepath ) : false;
			case IMAGETYPE_PNG:
				if ( ! function_exists( 'imagecreatefrompng' ) ) return false;
				$img = @imagecreatefrompng( $filepath );
				// Flatten PNG transparency onto white background.
				if ( $img ) {
					$w  = imagesx( $img );
					$h  = imagesy( $img );
					$bg = imagecreatetruecolor( $w, $h );
					imagefill( $bg, 0, 0, imagecolorallocate( $bg, 255, 255, 255 ) );
					imagecopy( $bg, $img, 0, 0, 0, 0, $w, $h );
					imagedestroy( $img );
					$img = $bg;
				}
				return $img;
			case IMAGETYPE_GIF:
				return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $filepath ) : false;
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filepath ) : false;
			default:
				return false;
		}
	}

	private function generate_bundle_collage( int $product_id, array $items ): array {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return [ 'error' => 'PHP GD-tillägget är inte aktiverat på servern.' ];
		}

		$images = [];
		foreach ( $items as $item ) {
			if ( count( $images ) >= 4 ) break;
			$p = wc_get_product( $item['product_id'] );
			if ( ! $p || ! $p->get_image_id() ) continue;

			$file = get_attached_file( $p->get_image_id() );
			$gd   = ( $file && file_exists( $file ) ) ? $this->gd_from_path( $file ) : false;

			// Fall back to downloading the image if local path failed.
			if ( ! $gd ) {
				$url      = wp_get_attachment_image_url( $p->get_image_id(), 'large' );
				$response = $url ? wp_remote_get( $url, [ 'timeout' => 15 ] ) : null;
				if ( $response && ! is_wp_error( $response ) ) {
					$tmp = wp_tempnam( 'sijab-collage-' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions
					file_put_contents( $tmp, wp_remote_retrieve_body( $response ) );
					$gd = $this->gd_from_path( $tmp );
					@unlink( $tmp );
				}
			}

			if ( $gd ) $images[] = $gd;
		}

		if ( empty( $images ) ) {
			return [ 'error' => 'Inga bilder hittades för ingående produkter (kontrollera att produkterna har produktbilder).' ];
		}

		$cell  = 400;
		$count = count( $images );
		$cols  = $count > 1 ? 2 : 1;
		$rows  = (int) ceil( $count / $cols );

		$canvas = imagecreatetruecolor( $cols * $cell, $rows * $cell );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefill( $canvas, 0, 0, $white );

		foreach ( $images as $i => $gd ) {
			$col   = $i % $cols;
			$row   = (int) floor( $i / $cols );
			$sw    = imagesx( $gd );
			$sh    = imagesy( $gd );
			$scale = min( $cell / $sw, $cell / $sh );
			$dw    = max( 1, (int) round( $sw * $scale ) );
			$dh    = max( 1, (int) round( $sh * $scale ) );
			$dx    = $col * $cell + (int) round( ( $cell - $dw ) / 2 );
			$dy    = $row * $cell + (int) round( ( $cell - $dh ) / 2 );
			imagecopyresampled( $canvas, $gd, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
			imagedestroy( $gd );
		}

		$upload   = wp_upload_dir();
		$filename = 'bundle-collage-' . $product_id . '-' . time() . '.jpg';
		$filepath = trailingslashit( $upload['path'] ) . $filename;
		$fileurl  = trailingslashit( $upload['url'] ) . $filename;

		if ( ! imagejpeg( $canvas, $filepath, 90 ) ) {
			imagedestroy( $canvas );
			return [ 'error' => 'Kunde inte spara kollage-bilden (kontrollera skrivrättigheter till uploads-mappen).' ];
		}
		imagedestroy( $canvas );

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => 'image/jpeg',
			'post_title'     => get_the_title( $product_id ) . ' — kollage',
			'post_content'   => '',
			'post_status'    => 'inherit',
		], $filepath, $product_id );

		if ( is_wp_error( $attach_id ) ) {
			return [ 'error' => 'wp_insert_attachment misslyckades: ' . $attach_id->get_error_message() ];
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $filepath ) );

		return [ 'id' => $attach_id, 'url' => $fileurl ];
	}

	// ──────────────────────────────────────────────────────────────
	// Bundle — Frontend
	// ──────────────────────────────────────────────────────────────

	public function render_bundle_section(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) return;
		if ( ! get_post_meta( $product->get_id(), self::BUNDLE_FLAG, true ) ) return;

		$items = $this->get_bundle_items( $product->get_id() );
		if ( empty( $items ) ) return;
		?>
		<section class="sijab-accessories-section sijab-accessories-section--horizontal sijab-bundle-section">
			<h2 class="sijab-accessories-section__title"><?php esc_html_e( 'Paketet innehåller', 'sijab-tillbehor' ); ?></h2>
			<div class="sijab-accessories-section__list">
				<?php foreach ( $items as $item ) :
					$p = wc_get_product( $item['product_id'] );
					if ( ! $p ) continue;
					$img     = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
					$link    = get_permalink( $p->get_id() );
					$qty     = absint( $item['qty_default'] ?? 1 );
					?>
					<div class="sijab-acc-item">
						<div class="sijab-acc-card">
							<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__image">
								<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $p->get_name() ); ?>" />
							</a>
							<div class="sijab-acc-card__body">
								<div class="sijab-acc-card__details">
									<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__name"><?php echo esc_html( $p->get_name() ); ?></a>
								</div>
								<?php if ( $p->get_sku() ) : ?>
									<div class="sijab-acc-card__meta">
										<span class="sijab-acc-card__sku"><?php echo esc_html( 'Art.nr: ' . $p->get_sku() ); ?></span>
									</div>
								<?php endif; ?>
								<div class="sijab-acc-card__right sijab-bundle-qty-badge">
									<span class="sijab-bundle-qty"><?php echo $qty; ?> <?php esc_html_e( 'st', 'sijab-tillbehor' ); ?></span>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	// ──────────────────────────────────────────────────────────────
	// AJAX: Save accessory category (instant save without page reload)
	// ──────────────────────────────────────────────────────────────

	public function ajax_save_acc_category(): void {
		check_ajax_referer( 'sijab_save_accessories', 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$cat_id     = absint( $_POST['category_id'] ?? 0 );

		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( $cat_id && term_exists( $cat_id, 'product_cat' ) ) {
			update_post_meta( $product_id, '_sijab_acc_category_id', $cat_id );
		} else {
			delete_post_meta( $product_id, '_sijab_acc_category_id' );
		}

		wp_send_json_success( [ 'saved' => true ] );
	}

	// ──────────────────────────────────────────────────────────────
	// Shop: Bundle filter/sorting
	// ──────────────────────────────────────────────────────────────

	/**
	 * Add "Paket" sorting option to WooCommerce catalog orderby dropdown.
	 */
	public function add_bundle_sorting_option( array $options ): array {
		$options['bundles_first'] = __( 'Paket först', 'sijab-tillbehor' );
		return $options;
	}

	/**
	 * Handle bundle filter/sorting in product query.
	 */
	public function handle_bundle_filter( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) return;

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : '';

		if ( 'bundles_first' === $orderby ) {
			$query->set( 'meta_key', self::BUNDLE_FLAG );
			$query->set( 'orderby', [ 'meta_value' => 'DESC', 'title' => 'ASC' ] );
		}

		// Also support ?bundle_only=1 to show only bundles.
		if ( ! empty( $_GET['bundle_only'] ) ) {
			$query->set( 'meta_query', array_merge(
				$query->get( 'meta_query' ) ?: [],
				[
					[
						'key'     => self::BUNDLE_FLAG,
						'value'   => '1',
						'compare' => '=',
					],
				]
			) );
		}
	}

	// ──────────────────────────────────────────────────────────────
	// Admin: Bundle type filter in product list
	// ──────────────────────────────────────────────────────────────

	/**
	 * Add "Paket" option to the product type filter dropdown in admin product list.
	 */
	public function add_bundle_type_filter( string $output ): string {
		$selected = $this->bundle_filter_active ? ' selected="selected"' : '';
		$option   = '<option value="sijab_bundle"' . $selected . '>' . esc_html__( 'Paket', 'sijab-tillbehor' ) . '</option>';

		// Find the product type <select> reliably by looking for value="simple"
		// which ONLY exists in the product type dropdown (WooCommerce built-in types).
		$marker_pos = strpos( $output, 'value="simple"' );
		if ( $marker_pos !== false ) {
			// Find the </select> that closes THIS select element.
			$close_pos = strpos( $output, '</select>', $marker_pos );
			if ( $close_pos !== false ) {
				$output = substr_replace( $output, $option . '</select>', $close_pos, strlen( '</select>' ) );
			}
		}

		return $output;
	}

	/**
	 * Early intercept: remove product_type=sijab_bundle from $_GET
	 * and set meta_query BEFORE WooCommerce processes it.
	 *
	 * Strategy: We use a class property to remember that bundle filter
	 * is active, then permanently remove product_type from $_GET so WC
	 * never sees it. The dropdown selected state uses the class property.
	 */
	private $bundle_filter_active = false;

	public function filter_products_by_bundle( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'product' ) return;
		if ( $this->bundle_filter_active ) {
			// Already processed via intercept_bundle_request — just add meta_query.
			$meta_query = $query->get( 'meta_query' ) ?: [];
			$meta_query[] = [
				'key'     => self::BUNDLE_FLAG,
				'value'   => '1',
				'compare' => '=',
			];
			$query->set( 'meta_query', $meta_query );
			return;
		}
		if ( ! isset( $_GET['product_type'] ) || $_GET['product_type'] !== 'sijab_bundle' ) return;

		$this->bundle_filter_active = true;

		// Permanently remove from $_GET so WC never processes it as taxonomy.
		unset( $_GET['product_type'] );
		unset( $_REQUEST['product_type'] );

		$meta_query = $query->get( 'meta_query' ) ?: [];
		$meta_query[] = [
			'key'     => self::BUNDLE_FLAG,
			'value'   => '1',
			'compare' => '=',
		];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Intercept 'request' filter (runs before WC's query_filters) to
	 * permanently remove product_type=sijab_bundle from $_GET.
	 */
	public function intercept_bundle_request( array $query_vars ): array {
		if ( ! is_admin() ) return $query_vars;
		if ( ! isset( $_GET['product_type'] ) || $_GET['product_type'] !== 'sijab_bundle' ) return $query_vars;
		if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'product' ) return $query_vars;

		$this->bundle_filter_active = true;

		// Permanently remove so WC's WC_Admin_List_Table_Products::query_filters()
		// never sees it and never adds a tax_query for non-existent type.
		unset( $_GET['product_type'] );
		unset( $_REQUEST['product_type'] );
		unset( $query_vars['product_type'] );

		return $query_vars;
	}

	// ──────────────────────────────────────────────────────────────
	// AJAX: Add to cart (variable products from accessory cards)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Custom AJAX add-to-cart handler for variable products displayed in accessory cards.
	 * More reliable than WC's built-in wc-ajax=add_to_cart on single product pages.
	 */
	public function ajax_add_to_cart(): void {
		$product_id   = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$quantity     = max( 1, absint( $_POST['quantity'] ?? 1 ) );

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Ogiltigt produkt-ID.', 'sijab-tillbehor' ) ] );
		}

		// Collect variation attributes.
		$variations = [];
		foreach ( $_POST as $key => $value ) {
			if ( strpos( $key, 'attribute_' ) === 0 ) {
				$variations[ sanitize_title( wp_unslash( $key ) ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}

		// Tag for order tracking.
		if ( ! empty( $_POST['sijab_acc_parent'] ) ) {
			$_REQUEST['sijab_acc_parent'] = absint( $_POST['sijab_acc_parent'] );
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations );

		if ( $cart_item_key ) {
			// Return updated cart fragments.
			ob_start();
			wc_print_notices();
			$notices = ob_get_clean();

			$data = [
				'cart_hash'      => WC()->cart->get_cart_hash(),
				'cart_item_key'  => $cart_item_key,
				'fragments'      => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
				'cart_quantity'  => WC()->cart->get_cart_contents_count(),
			];

			wp_send_json_success( $data );
		} else {
			// Get WC notices for error message.
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$msg = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ?? $notices[0] ) : __( 'Kunde inte lägga till i varukorgen.', 'sijab-tillbehor' );
			wp_send_json_error( [ 'message' => $msg ] );
		}
	}

	// ──────────────────────────────────────────────────────────────
	// Statistics
	// ──────────────────────────────────────────────────────────────

	/**
	 * Create the stats database table.
	 */
	public static function create_stats_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::STATS_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_product_id bigint(20) unsigned NOT NULL,
			accessory_product_id bigint(20) unsigned NOT NULL,
			event_type varchar(30) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY parent_product_id (parent_product_id),
			KEY accessory_product_id (accessory_product_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'sijab_acc_stats_db_version', '1.0' );
	}

	/**
	 * AJAX handler for tracking accessory events.
	 */
	public function ajax_track_event(): void {
		$valid_types = [ 'add_to_cart', 'view_product', 'product_click' ];

		$parent_id    = absint( $_POST['parent_id'] ?? 0 );
		$accessory_id = absint( $_POST['accessory_id'] ?? 0 );
		$event_type   = sanitize_text_field( $_POST['event_type'] ?? '' );

		if ( ! $parent_id || ! $accessory_id || ! in_array( $event_type, $valid_types, true ) ) {
			wp_send_json_error( 'Invalid data', 400 );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::STATS_TABLE,
			[
				'parent_product_id'    => $parent_id,
				'accessory_product_id' => $accessory_id,
				'event_type'           => $event_type,
				'created_at'           => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		wp_send_json_success();
	}

	/**
	 * Cron: delete stats older than 1 year.
	 */
	public function cleanup_old_stats(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . self::STATS_TABLE . " WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) )
		) );
	}

	// ──────────────────────────────────────────────────────────────
	// Order tracking
	// ──────────────────────────────────────────────────────────────

	/**
	 * Tag cart item with accessory info when added via the plugin.
	 */
	public function tag_cart_item( array $cart_item_data, int $product_id ): array {
		if ( ! empty( $_REQUEST['sijab_acc_parent'] ) ) {
			$cart_item_data['_sijab_acc_parent'] = absint( $_REQUEST['sijab_acc_parent'] );
		}
		return $cart_item_data;
	}

	/**
	 * Save accessory parent ID to order line item meta.
	 */
	public function save_accessory_meta_to_order( $item, $cart_item_key, $values, $order ): void {
		if ( ! empty( $values['_sijab_acc_parent'] ) ) {
			$item->add_meta_data( '_sijab_acc_parent', absint( $values['_sijab_acc_parent'] ), true );
		}
	}

	/**
	 * Record purchases in stats table when order is completed/processing.
	 */
	public function record_accessory_purchases( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Prevent double-counting.
		if ( $order->get_meta( '_sijab_acc_purchases_recorded' ) ) return;

		global $wpdb;
		$table = $wpdb->prefix . self::STATS_TABLE;

		foreach ( $order->get_items() as $item ) {
			$parent_id = (int) $item->get_meta( '_sijab_acc_parent' );
			if ( ! $parent_id ) continue;

			$product_id = $item->get_product_id();

			$wpdb->insert( $table, [
				'parent_product_id'    => $parent_id,
				'accessory_product_id' => $product_id,
				'event_type'           => 'purchase',
				'created_at'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
			], [ '%d', '%d', '%s', '%s' ] );
		}

		$order->update_meta_data( '_sijab_acc_purchases_recorded', 1 );
		$order->save();
	}

	/**
	 * Schedule daily cleanup cron.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'sijab_acc_stats_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'sijab_acc_stats_cleanup' );
		}
	}

	/**
	 * Unschedule cleanup cron on deactivation.
	 */
	public static function unschedule_cleanup(): void {
		$ts = wp_next_scheduled( 'sijab_acc_stats_cleanup' );
		if ( $ts ) wp_unschedule_event( $ts, 'sijab_acc_stats_cleanup' );
	}

	/**
	 * Render the statistics admin page.
	 */
	/**
	 * Render the statistics panel (embedded in admin page).
	 */
	private function render_stats_panel(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::STATS_TABLE;

		// Period filter.
		$period = sanitize_text_field( $_GET['period'] ?? '30d' );
		$periods = [
			'7d'  => '7 dagar',
			'30d' => '30 dagar',
			'90d' => '90 dagar',
			'1yr' => '1 år',
		];
		if ( ! isset( $periods[ $period ] ) ) $period = '30d';

		switch ( $period ) {
			case '7d':  $days = 7; break;
			case '90d': $days = 90; break;
			case '1yr': $days = 365; break;
			default:    $days = 30;
		}
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Summary counts.
		$total_clicks   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type != 'purchase' AND created_at >= %s", $since ) );
		$add_to_cart    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'add_to_cart' AND created_at >= %s", $since ) );
		$purchases      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'purchase' AND created_at >= %s", $since ) );
		$view_product   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'view_product' AND created_at >= %s", $since ) );
		$product_click  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'product_click' AND created_at >= %s", $since ) );
		$conversion     = $add_to_cart > 0 ? round( $purchases / $add_to_cart * 100, 1 ) : 0;

		// Accessories chosen per parent product.
		$per_parent = $wpdb->get_results( $wpdb->prepare(
			"SELECT parent_product_id, accessory_product_id,
				SUM( event_type = 'add_to_cart' ) AS atc,
				SUM( event_type = 'purchase' ) AS purchases,
				SUM( event_type = 'view_product' ) AS vp,
				SUM( event_type = 'product_click' ) AS pc,
				COUNT(*) AS total
			FROM {$table}
			WHERE created_at >= %s
			GROUP BY parent_product_id, accessory_product_id
			ORDER BY parent_product_id, total DESC",
			$since
		) );

		$grouped = [];
		foreach ( $per_parent as $row ) {
			$pid = (int) $row->parent_product_id;
			if ( ! isset( $grouped[ $pid ] ) ) $grouped[ $pid ] = [ 'rows' => [], 'total' => 0, 'atc' => 0, 'purchases' => 0 ];
			$grouped[ $pid ]['rows'][] = $row;
			$grouped[ $pid ]['total']     += (int) $row->total;
			$grouped[ $pid ]['atc']       += (int) $row->atc;
			$grouped[ $pid ]['purchases'] += (int) $row->purchases;
		}
		uasort( $grouped, function( $a, $b ) { return $b['total'] - $a['total']; } );

		// Daily trend.
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day,
				SUM( event_type = 'add_to_cart' ) AS atc,
				SUM( event_type = 'purchase' ) AS purchases,
				SUM( event_type = 'view_product' ) AS vp,
				SUM( event_type = 'product_click' ) AS pc
			FROM {$table}
			WHERE created_at >= %s
			GROUP BY DATE(created_at)
			ORDER BY day ASC",
			$since
		) );

		$max_daily = 1;
		foreach ( $daily as $d ) {
			$day_total = (int) $d->atc + (int) $d->purchases + (int) $d->vp + (int) $d->pc;
			if ( $day_total > $max_daily ) $max_daily = $day_total;
		}
		?>
		<div id="sijab-tab-statistik" class="sijab-tab-panel">

			<!-- Period filter -->
			<div style="margin: 16px 0;">
				<?php foreach ( $periods as $key => $label ) :
					$class = ( $key === $period ) ? 'button button-primary' : 'button';
				?>
					<a href="#" class="sijab-period-btn <?php echo esc_attr( $class ); ?>" data-period="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<!-- Summary boxes -->
			<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px;">
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px; min-width:130px;">
					<div style="font-size:28px; font-weight:700; color:#2e7d32;"><?php echo number_format_i18n( $purchases ); ?></div>
					<div style="color:#50575e;">Köp via tillbehör</div>
				</div>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px; min-width:130px;">
					<div style="font-size:28px; font-weight:700; color:#00a32a;"><?php echo number_format_i18n( $add_to_cart ); ?></div>
					<div style="color:#50575e;">Lägg i varukorg</div>
				</div>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px; min-width:130px;">
					<div style="font-size:28px; font-weight:700; color:#1e73be;"><?php echo $conversion; ?>%</div>
					<div style="color:#50575e;">Konvertering</div>
				</div>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 20px; min-width:130px;">
					<div style="font-size:28px; font-weight:700; color:#e67e22;"><?php echo number_format_i18n( $total_clicks ); ?></div>
					<div style="color:#50575e;">Totala klick</div>
				</div>
			</div>

			<!-- Daily trend bar chart -->
			<?php if ( $daily ) : ?>
			<h3>Daglig trend</h3>
			<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; margin-bottom:24px; overflow-x:auto;">
				<div style="display:flex; align-items:flex-end; gap:2px; height:180px; min-width:<?php echo count( $daily ) * 18; ?>px;">
					<?php foreach ( $daily as $d ) :
						$atc  = (int) $d->atc;
						$purch = (int) $d->purchases;
						$vp   = (int) $d->vp;
						$pc   = (int) $d->pc;
						$h_purch = round( $purch / $max_daily * 160 );
						$h_atc   = round( $atc / $max_daily * 160 );
						$h_vp    = round( $vp / $max_daily * 160 );
						$h_pc    = round( $pc / $max_daily * 160 );
					?>
						<div style="flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; min-width:14px;"
						     title="<?php echo esc_attr( $d->day . ': ' . $purch . ' köp, ' . $atc . ' varukorg, ' . ( $vp + $pc ) . ' klick' ); ?>">
							<?php if ( $pc ) : ?><div style="width:100%; max-width:16px; height:<?php echo $h_pc; ?>px; background:#e67e22; border-radius:2px 2px 0 0;"></div><?php endif; ?>
							<?php if ( $vp ) : ?><div style="width:100%; max-width:16px; height:<?php echo $h_vp; ?>px; background:#9b59b6;"></div><?php endif; ?>
							<?php if ( $atc ) : ?><div style="width:100%; max-width:16px; height:<?php echo $h_atc; ?>px; background:#00a32a;"></div><?php endif; ?>
							<?php if ( $purch ) : ?><div style="width:100%; max-width:16px; height:<?php echo $h_purch; ?>px; background:#2e7d32; border-radius:0 0 2px 2px;"></div><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="display:flex; justify-content:space-between; margin-top:6px; font-size:11px; color:#999;">
					<span><?php echo esc_html( $daily[0]->day ?? '' ); ?></span>
					<span><?php echo esc_html( end( $daily )->day ?? '' ); ?></span>
				</div>
				<div style="margin-top:8px; font-size:12px;">
					<span style="display:inline-block; width:12px; height:12px; background:#2e7d32; border-radius:2px; vertical-align:middle;"></span> Köp
					<span style="display:inline-block; width:12px; height:12px; background:#00a32a; border-radius:2px; vertical-align:middle; margin-left:12px;"></span> Varukorg
					<span style="display:inline-block; width:12px; height:12px; background:#9b59b6; border-radius:2px; vertical-align:middle; margin-left:12px;"></span> Visa produkt
					<span style="display:inline-block; width:12px; height:12px; background:#e67e22; border-radius:2px; vertical-align:middle; margin-left:12px;"></span> Produktklick
				</div>
			</div>
			<?php endif; ?>

			<!-- Accessories chosen per parent product -->
			<h3>Tillbehör som valts till via artikel</h3>
			<?php if ( $grouped ) : ?>
				<?php foreach ( $grouped as $pid => $group ) :
					$parent = wc_get_product( $pid );
					$parent_name = $parent ? $parent->get_name() : '#' . $pid;
					$parent_url  = $parent ? get_edit_post_link( $pid ) : '';
				?>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; margin-bottom:16px;">
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
						<h4 style="margin:0; font-size:14px;">
							<?php echo $parent_url ? '<a href="' . esc_url( $parent_url ) . '">' . esc_html( $parent_name ) . '</a>' : esc_html( $parent_name ); ?>
						</h4>
						<span style="color:#50575e; font-size:13px;">
							<strong style="color:#2e7d32;"><?php echo number_format_i18n( $group['purchases'] ); ?> köp</strong> &middot; <?php echo number_format_i18n( $group['atc'] ); ?> varukorg &middot; <?php echo number_format_i18n( $group['total'] ); ?> totala
						</span>
					</div>
					<table class="widefat striped" style="margin:0;">
						<thead>
							<tr>
								<th>Tillbehör</th>
								<th>Köp</th>
								<th>Varukorg</th>
								<th>Visa produkt</th>
								<th>Produktklick</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $group['rows'] as $row ) :
								$acc = wc_get_product( $row->accessory_product_id );
								$acc_name = $acc ? $acc->get_name() : '#' . $row->accessory_product_id;
								$acc_url  = $acc ? get_edit_post_link( $row->accessory_product_id ) : '';
							?>
							<tr>
								<td><?php echo $acc_url ? '<a href="' . esc_url( $acc_url ) . '">' . esc_html( $acc_name ) . '</a>' : esc_html( $acc_name ); ?></td>
								<td><strong style="color:#2e7d32;"><?php echo number_format_i18n( (int) $row->purchases ); ?></strong></td>
								<td><?php echo number_format_i18n( (int) $row->atc ); ?></td>
								<td><?php echo number_format_i18n( (int) $row->vp ); ?></td>
								<td><?php echo number_format_i18n( (int) $row->pc ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:24px; text-align:center; color:#999;">
					Ingen data ännu för vald period.
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_bundle_items( int $product_id ): array {
		$items = get_post_meta( $product_id, self::BUNDLE_META, true );
		return is_array( $items ) ? $items : [];
	}
}

// Activation: create stats table + schedule cron.
register_activation_hook( __FILE__, function() {
	SIJAB_Tillbehor::create_stats_table();
	SIJAB_Tillbehor::schedule_cleanup();
} );

// Deactivation: unschedule cron.
register_deactivation_hook( __FILE__, function() {
	SIJAB_Tillbehor::unschedule_cleanup();
} );

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) return;

	new SIJAB_Tillbehor();

	// Create/upgrade stats table without reactivation.
	$db_ver = get_option( 'sijab_acc_stats_db_version', '0' );
	if ( version_compare( $db_ver, '1.0', '<' ) ) {
		SIJAB_Tillbehor::create_stats_table();
		SIJAB_Tillbehor::schedule_cleanup();
	}
} );
