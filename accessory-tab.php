<?php
/**
 * Plugin Name: Accessory Tab for WooCommerce
 * Description: Visar tillbehör direkt på produktsidan med produktkort (bild, pris, lagerstatus, "Lägg till"-knapp). Admin: lägg till tillbehör via SKU eller produktsök.
 * Author: SIJAB
 * Version: 2.12.1
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

	const META_KEY = '_sijab_accessories_ids';
	const VERSION  = '2.12.1';
	const OPTION   = 'sijab_tillbehor_settings';

	/** @var array|null Cached settings. */
	private $settings = null;

	public function __construct() {
		// Frontend hooks — registered dynamically based on placement setting.
		add_action( 'wp', [ $this, 'register_frontend_hooks' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

		// Admin: produktredigerare.
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_admin_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_admin_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_accessories' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_css' ] );

		// Settings page under WooCommerce menu.
		add_action( 'admin_menu', [ $this, 'register_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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

		// GitHub token stored separately (not part of the main settings array).
		register_setting( 'sijab_tillbehor', 'sijab_tillbehor_github_token', [
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
		$s = $this->get_settings();
		$gh_token = get_option( 'sijab_tillbehor_github_token', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tillbehör — Inställningar', 'sijab-tillbehor' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'sijab_tillbehor' ); ?>

				<h2 class="title"><?php esc_html_e( 'Visning', 'sijab-tillbehor' ); ?></h2>
				<table class="form-table" role="presentation">
					<!-- Placement -->
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

					<!-- Title format -->
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

					<!-- Layout -->
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

					<!-- Columns (only for grid) -->
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

					<!-- Max visible -->
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

				<h2 class="title"><?php esc_html_e( 'Uppdateringar', 'sijab-tillbehor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sijab_gh_token"><?php esc_html_e( 'GitHub-token', 'sijab-tillbehor' ); ?></label></th>
						<td>
							<input type="password" name="sijab_tillbehor_github_token" id="sijab_gh_token" value="<?php echo esc_attr( $gh_token ); ?>" class="regular-text" autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Personal access token (classic) med "repo"-rättighet. Krävs för automatiska uppdateringar från privat GitHub-repo.', 'sijab-tillbehor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Spara inställningar', 'sijab-tillbehor' ) ); ?>
			</form>

			<p class="description" style="margin-top: 24px;">
				<?php printf( esc_html__( 'Pluginversion: %s', 'sijab-tillbehor' ), '<strong>' . self::VERSION . '</strong>' ); ?>
			</p>
		</div>
		<?php
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

		$ids = $this->get_accessory_ids( $product->get_id() );
		if ( empty( $ids ) ) return;

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
}

add_action( 'plugins_loaded', function() {
	if ( class_exists( 'WooCommerce' ) ) new SIJAB_Tillbehor();
} );
