<?php
/**
 * Plugin Name: Accessory Tab for WooCommerce
 * Description: Visar tillbehör direkt på produktsidan med produktkort (bild, pris, lagerstatus, "Lägg till"-knapp). Admin: lägg till tillbehör via SKU eller produktsök.
 * Author: HB
 * Version: 2.15.4
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
	const VERSION       = '2.15.4';
	const OPTION        = 'sijab_tillbehor_settings';

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

		// Frontend: paketprodukter.
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_bundle_section' ], 35 );

		// Settings page under WooCommerce menu.
		add_action( 'admin_menu', [ $this, 'register_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_migration' ] );
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
			__( 'Tillbehör — Inställningar', 'sijab-tillbehor' ),
			__( 'Tillbehör', 'sijab-tillbehor' ),
			'manage_woocommerce',
			'sijab-tillbehor-settings',
			[ $this, 'render_settings_page' ]
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

		// OpenAI API key.
		register_setting( 'sijab_tillbehor', 'sijab_openai_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
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
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$s        = $this->get_settings();
		$gh_token = get_option( 'sijab_tillbehor_github_token', '' );
		$ai_key   = get_option( 'sijab_openai_api_key', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accessory Tab — Inställningar', 'sijab-tillbehor' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="visning"><?php esc_html_e( 'Visning', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="api"><?php esc_html_e( 'API-inställningar', 'sijab-tillbehor' ); ?></a>
				<a href="#" class="nav-tab sijab-nav-tab" data-tab="verktyg"><?php esc_html_e( 'Verktyg', 'sijab-tillbehor' ); ?></a>
			</h2>

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
										<p class="description"><?php esc_html_e( 'Skapa nyckeln på platform.openai.com → API keys. Modell: gpt-4o.', 'sijab-tillbehor' ); ?></p>
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
				<p style="margin-top:16px;" class="description">
					<?php printf( esc_html__( 'Pluginversion: %s', 'sijab-tillbehor' ), '<strong>' . self::VERSION . '</strong>' ); ?>
					&nbsp;|&nbsp; <a href="https://github.com/stainzor/accessory-tab/releases" target="_blank"><?php esc_html_e( 'Versionshistorik', 'sijab-tillbehor' ); ?></a>
				</p>
				<?php $this->render_migration_section(); ?>
			</div><!-- end Verktyg panel -->

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

			// Restore last active tab.
			var saved = '';
			try { saved = localStorage.getItem(storageKey) || ''; } catch(e) {}
			var hash = window.location.hash.replace('#sijab-tab-', '');
			activateTab( hash || saved || 'visning' );

			tabs.on('click', function(e) {
				e.preventDefault();
				activateTab($(this).data('tab'));
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
		$id           = $acc->get_id();
		$link         = get_permalink( $id );
		$title        = $acc->get_name();
		$image_id     = $acc->get_image_id();
		$image_url    = $image_id
			? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
			: wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$price_html   = $acc->get_price_html();
		$sku          = $acc->get_sku();
		$is_simple    = $acc->is_type( 'simple' ) && $acc->is_purchasable() && $acc->is_in_stock();
		$stock_status = $acc->get_stock_status();

		switch ( $stock_status ) {
			case 'instock':     $stock_label = __( 'I lager', 'sijab-tillbehor' ); break;
			case 'onbackorder': $stock_label = __( 'Beställningsvara', 'sijab-tillbehor' ); break;
			default:            $stock_label = __( 'Slut i lager', 'sijab-tillbehor' );
		}
		?>
		<div class="sijab-acc-card">
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
					<span class="sijab-acc-card__stock sijab-acc-card__stock--<?php echo esc_attr( $stock_status ); ?>">
						<?php echo esc_html( $stock_label ); ?>
					</span>
					<?php if ( $sku ) : ?>
						<span class="sijab-acc-card__sku"><?php echo esc_html( 'Art.nr: ' . $sku ); ?></span>
					<?php endif; ?>
				</div>
				<div class="sijab-acc-card__right">
					<?php if ( $is_simple ) : ?>
						<div class="sijab-acc-card__qty-row">
							<div class="sijab-acc-card__qty">
								<button type="button" class="sijab-qty-btn sijab-qty-minus" aria-label="<?php esc_attr_e( 'Minska antal', 'sijab-tillbehor' ); ?>">−</button>
								<input type="number" class="sijab-qty-input" value="1" min="1" step="1" aria-label="<?php esc_attr_e( 'Antal', 'sijab-tillbehor' ); ?>" />
								<button type="button" class="sijab-qty-btn sijab-qty-plus" aria-label="<?php esc_attr_e( 'Öka antal', 'sijab-tillbehor' ); ?>">+</button>
							</div>
							<a href="<?php echo esc_url( $acc->add_to_cart_url() ); ?>"
							   data-quantity="1"
							   class="button sijab-acc-atc-btn add_to_cart_button ajax_add_to_cart sijab-acc-atc"
							   data-product_id="<?php echo absint( $id ); ?>"
							   data-product_sku="<?php echo esc_attr( $sku ); ?>"
							   aria-label="<?php echo esc_attr( $acc->add_to_cart_description() ); ?>"
							   rel="nofollow">
								<?php esc_html_e( 'Lägg till', 'sijab-tillbehor' ); ?>
							</a>
						</div>
					<?php else : ?>
						<div class="sijab-acc-card__qty-row">
							<a href="<?php echo esc_url( $link ); ?>" class="button sijab-acc-atc-btn">
								<?php esc_html_e( 'Visa produkt', 'sijab-tillbehor' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
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
	}

	public function enqueue_admin_css( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) return;
		$screen = get_current_screen();
		if ( empty( $screen ) || 'product' !== $screen->id ) return;

		$css = '
			#sijab_accessories_data .form-field label { width: 220px; }
			#sijab_acc_sortable { margin: 0; padding: 0; list-style: none; }
			#sijab_acc_sortable .sijab-acc-sortable-item {
				display: flex; align-items: center; gap: 8px;
				padding: 10px 12px; margin-bottom: -1px;
				background: #fff; border: 1px solid #ddd;
				transition: background 0.15s;
			}
			#sijab_acc_sortable .sijab-acc-sortable-item:first-child { border-radius: 4px 4px 0 0; }
			#sijab_acc_sortable .sijab-acc-sortable-item:last-child { border-radius: 0 0 4px 4px; }
			#sijab_acc_sortable .sijab-acc-sortable-item:only-child { border-radius: 4px; }
			#sijab_acc_sortable .sijab-acc-sortable-item:hover { background: #f9f9f9; }
			#sijab_acc_sortable .sijab-acc-sortable-item.ui-sortable-helper {
				box-shadow: 0 2px 8px rgba(0,0,0,0.12); background: #fff; border-radius: 4px;
			}
			#sijab_acc_sortable .sijab-acc-sortable-item.ui-sortable-placeholder {
				visibility: visible !important; background: #f0f6ff; border: 1px dashed #4a90d9;
			}
			.sijab-acc-drag-handle {
				font-size: 16px; color: #bbb; cursor: grab; flex-shrink: 0;
				padding: 0 2px; line-height: 1;
			}
			.sijab-acc-drag-handle:active { cursor: grabbing; }
			#sijab_acc_sortable .sijab-acc-sortable-item img {
				width: 36px; height: 36px; object-fit: contain;
				border-radius: 3px; border: 1px solid #eee; flex-shrink: 0;
			}
			.sijab-acc-item-name {
				flex: 1; font-size: 13px; color: #333;
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
			}
			.sijab-acc-remove {
				flex-shrink: 0; font-size: 12px; color: #a00; text-decoration: none;
				padding: 2px 6px; border-radius: 3px; transition: all 0.15s;
			}
			.sijab-acc-remove:hover { color: #fff; background: #a00; text-decoration: none; }
		';
		wp_add_inline_style( 'woocommerce_admin_styles', $css );

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
						<div class="sijab-bundle-row" style="display:flex; align-items:center; gap:8px; padding:8px 12px; border-bottom:1px solid #eee;">
							<select class="wc-product-search" name="sijab_bundle_items[<?php echo $i; ?>][product_id]" style="width:40%;" data-placeholder="<?php esc_attr_e( 'Sök produkt…', 'sijab-tillbehor' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="false">
								<option value="<?php echo absint( $item['product_id'] ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
							</select>
							<label style="font-size:12px; white-space:nowrap;"><?php esc_html_e( 'Standard:', 'sijab-tillbehor' ); ?>
								<input type="number" name="sijab_bundle_items[<?php echo $i; ?>][qty_default]" value="<?php echo absint( $item['qty_default'] ?? 1 ); ?>" min="1" style="width:50px;" />
							</label>
							<label style="font-size:12px; white-space:nowrap;"><?php esc_html_e( 'Min:', 'sijab-tillbehor' ); ?>
								<input type="number" name="sijab_bundle_items[<?php echo $i; ?>][qty_min]" value="<?php echo absint( $item['qty_min'] ?? 1 ); ?>" min="1" style="width:50px;" />
							</label>
							<label style="font-size:12px; white-space:nowrap;"><?php esc_html_e( 'Max:', 'sijab-tillbehor' ); ?>
								<input type="number" name="sijab_bundle_items[<?php echo $i; ?>][qty_max]" value="<?php echo absint( $item['qty_max'] ?? 0 ); ?>" min="0" style="width:50px;" placeholder="∞" />
							</label>
							<button type="button" class="button sijab-bundle-remove" style="flex-shrink:0;"><?php esc_html_e( 'Ta bort', 'sijab-tillbehor' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>

				<p style="padding: 8px 12px;">
					<button type="button" class="button" id="sijab_add_bundle_row"><?php esc_html_e( '+ Lägg till produkt', 'sijab-tillbehor' ); ?></button>
				</p>
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
				$('#sijab_bundle_items_wrap, #sijab_ai_section').toggle(this.checked);
			});

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
				var html = '<div class="sijab-bundle-row" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #eee;">'
					+ '<select class="wc-product-search" name="sijab_bundle_items[' + rowIndex + '][product_id]" style="width:40%;" data-placeholder="<?php esc_attr_e( 'Sök produkt…', 'sijab-tillbehor' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="false"></select>'
					+ '<label style="font-size:12px;white-space:nowrap;"><?php esc_html_e( 'Standard:', 'sijab-tillbehor' ); ?> <input type="number" name="sijab_bundle_items[' + rowIndex + '][qty_default]" value="1" min="1" style="width:50px;" /></label>'
					+ '<label style="font-size:12px;white-space:nowrap;"><?php esc_html_e( 'Min:', 'sijab-tillbehor' ); ?> <input type="number" name="sijab_bundle_items[' + rowIndex + '][qty_min]" value="1" min="1" style="width:50px;" /></label>'
					+ '<label style="font-size:12px;white-space:nowrap;"><?php esc_html_e( 'Max:', 'sijab-tillbehor' ); ?> <input type="number" name="sijab_bundle_items[' + rowIndex + '][qty_max]" value="0" min="0" style="width:50px;" placeholder="∞" /></label>'
					+ '<button type="button" class="button sijab-bundle-remove"><?php esc_html_e( 'Ta bort', 'sijab-tillbehor' ); ?></button>'
					+ '</div>';
				$('#sijab_bundle_rows').append(html);
				$(document.body).trigger('wc-enhanced-select-init');
				rowIndex++;
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
					'qty_min'     => max( 1, absint( $row['qty_min'] ?? 1 ) ),
					'qty_max'     => absint( $row['qty_max'] ?? 0 ),
				];
			}
		}

		update_post_meta( $product->get_id(), self::BUNDLE_META, $items );
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
				'model'           => 'gpt-4o',
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

		$total_regular = 0.0;
		foreach ( $items as $item ) {
			$p = wc_get_product( $item['product_id'] );
			if ( ! $p ) continue;
			$total_regular += (float) $p->get_price() * $item['qty_default'];
		}

		$bundle_price = (float) $product->get_price();
		$savings      = $total_regular - $bundle_price;
		?>
		<section class="sijab-accessories-section sijab-accessories-section--horizontal sijab-bundle-section">
			<h2 class="sijab-accessories-section__title"><?php esc_html_e( 'Paketet innehåller', 'sijab-tillbehor' ); ?></h2>
			<div class="sijab-accessories-section__list">
				<?php foreach ( $items as $item ) :
					$p = wc_get_product( $item['product_id'] );
					if ( ! $p ) continue;
					$img        = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
					$link       = get_permalink( $p->get_id() );
					$line_price = (float) $p->get_price() * $item['qty_default'];
					?>
					<div class="sijab-acc-item">
						<div class="sijab-acc-card">
							<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__image">
								<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $p->get_name() ); ?>" />
							</a>
							<div class="sijab-acc-card__body">
								<div class="sijab-acc-card__details">
									<a href="<?php echo esc_url( $link ); ?>" class="sijab-acc-card__name"><?php echo esc_html( $p->get_name() ); ?></a>
									<?php if ( $line_price > 0 ) : ?>
										<div class="sijab-acc-card__price"><?php echo wc_price( $line_price ); ?></div>
									<?php endif; ?>
								</div>
								<?php if ( $p->get_sku() ) : ?>
									<div class="sijab-acc-card__meta">
										<span class="sijab-acc-card__sku"><?php echo esc_html( 'Art.nr: ' . $p->get_sku() ); ?></span>
									</div>
								<?php endif; ?>
								<div class="sijab-acc-card__right sijab-bundle-qty-badge">
									<span class="sijab-bundle-qty"><?php echo absint( $item['qty_default'] ); ?> <?php esc_html_e( 'st', 'sijab-tillbehor' ); ?></span>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $total_regular > 0 && $savings > 0.01 ) : ?>
				<div class="sijab-bundle-section__summary">
					<span class="sijab-bundle-section__regular"><?php esc_html_e( 'Ordinarie värde:', 'sijab-tillbehor' ); ?> <del><?php echo wc_price( $total_regular ); ?></del></span>
					<span class="sijab-bundle-section__saving"><?php printf( esc_html__( 'Du sparar %s', 'sijab-tillbehor' ), wc_price( $savings ) ); ?></span>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function get_bundle_items( int $product_id ): array {
		$items = get_post_meta( $product_id, self::BUNDLE_META, true );
		return is_array( $items ) ? $items : [];
	}
}

add_action( 'plugins_loaded', function() {
	if ( class_exists( 'WooCommerce' ) ) new SIJAB_Tillbehor();
} );
