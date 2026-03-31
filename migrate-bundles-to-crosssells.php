<?php
/**
 * Plugin Name: Accessory Tab — Migrera Product Bundles till Korsförsäljning
 * Description: Engångsskript som kopierar WooCommerce Product Bundles-kopplingar till korsförsäljningsfältet. Avinstallera efter användning.
 * Author: SIJAB
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
	add_management_page(
		'Migrera Product Bundles',
		'Migrera Bundles',
		'manage_woocommerce',
		'sijab-migrate-bundles',
		'sijab_migrate_bundles_page'
	);
} );

function sijab_migrate_bundles_page() {
	$result = null;

	if ( isset( $_POST['sijab_run_migration'] ) && check_admin_referer( 'sijab_migrate_bundles' ) ) {
		$result = sijab_run_migration();
	}
	?>
	<div class="wrap">
		<h1>Migrera Product Bundles → Korsförsäljning</h1>
		<p>Det här skriptet kopierar alla produktkopplingar från WooCommerce Product Bundles till korsförsäljningsfältet (<code>_crosssells</code>) på respektive produkt.</p>
		<p>Befintliga korsförsäljningar bevaras — inga data skrivs över, bara kompletteras.</p>
		<p><strong>Avinstallera pluginet efter att migreringen är klar.</strong></p>

		<?php if ( $result ) : ?>
			<div class="notice notice-success">
				<p><strong>Migrering klar!</strong></p>
				<p>Behandlade produkter: <strong><?php echo $result['total']; ?></strong></p>
				<p>Uppdaterade produkter: <strong><?php echo $result['updated']; ?></strong></p>
				<p>Redan uppdaterade / inga ändringar: <strong><?php echo $result['skipped']; ?></strong></p>
			</div>
			<?php if ( ! empty( $result['details'] ) ) : ?>
				<h2>Detaljer</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Produkt</th>
							<th>Tillagda tillbehör (ID)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['details'] as $bundle_id => $added_ids ) : ?>
							<tr>
								<td><a href="<?php echo get_edit_post_link( $bundle_id ); ?>"><?php echo esc_html( get_the_title( $bundle_id ) ); ?> (#<?php echo $bundle_id; ?>)</a></td>
								<td><?php echo implode( ', ', $added_ids ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'sijab_migrate_bundles' ); ?>
			<?php submit_button( 'Kör migrering', 'primary large', 'sijab_run_migration' ); ?>
		</form>
	</div>
	<?php
}

function sijab_run_migration(): array {
	global $wpdb;

	$table = $wpdb->prefix . 'woocommerce_bundled_items';

	// Kontrollera att tabellen finns.
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
		return [
			'total'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'details' => [],
			'error'   => 'Product Bundles-tabellen hittades inte. Är pluginet installerat?',
		];
	}

	// Hämta alla bundle_id → product_id-kopplingar.
	$rows = $wpdb->get_results(
		"SELECT bundle_id, product_id FROM {$table} ORDER BY bundle_id ASC",
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return [ 'total' => 0, 'updated' => 0, 'skipped' => 0, 'details' => [] ];
	}

	// Gruppera per bundle.
	$bundles = [];
	foreach ( $rows as $row ) {
		$bundles[ (int) $row['bundle_id'] ][] = (int) $row['product_id'];
	}

	$total   = count( $bundles );
	$updated = 0;
	$skipped = 0;
	$details = [];

	foreach ( $bundles as $bundle_id => $product_ids ) {
		// Hämta befintliga korsförsäljningar.
		$existing = get_post_meta( $bundle_id, '_crosssells', true );
		if ( ! is_array( $existing ) ) $existing = [];

		// Slå ihop och deduplicera.
		$merged = array_values( array_unique( array_map( 'absint', array_merge( $existing, $product_ids ) ) ) );
		$merged = array_filter( $merged, fn( $id ) => $id > 0 && $id !== $bundle_id );
		$merged = array_values( $merged );

		// Hitta vad som faktiskt är nytt.
		$added = array_diff( $merged, $existing );

		if ( empty( $added ) ) {
			$skipped++;
			continue;
		}

		update_post_meta( $bundle_id, '_crosssells', $merged );
		$updated++;
		$details[ $bundle_id ] = array_values( $added );
	}

	return compact( 'total', 'updated', 'skipped', 'details' );
}
