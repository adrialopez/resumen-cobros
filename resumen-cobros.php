<?php
/**
 * Plugin Name: Resumen de Cobros
 * Plugin URI:  https://adria-lopez.com
 * Description: Reporte mensual de cobros dividido por efectivo, tarjeta débito y tarjeta crédito. Integración con Conekta para identificar el tipo de tarjeta.
 * Version:     1.2.1
 * Author:      Adrià López
 * Author URI:  https://adria-lopez.com
 * License:     GPL-2.0+
 * Text Domain: resumen-cobros
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RC_VERSION', '1.2.1' );
define( 'RC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RC_PLUGIN_DIR . 'includes/class-conekta-client.php';
require_once RC_PLUGIN_DIR . 'includes/ajax-handlers.php';

// ---------------------------------------------------------------------------
// Menú de administración
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		__( 'Resumen de Cobros', 'resumen-cobros' ),
		__( 'Resumen de Cobros', 'resumen-cobros' ),
		'manage_woocommerce',
		'resumen-cobros',
		'rc_render_page'
	);

	add_submenu_page(
		'woocommerce',
		__( 'Cobros – Configuración', 'resumen-cobros' ),
		__( 'Cobros – Config', 'resumen-cobros' ),
		'manage_options',
		'resumen-cobros-settings',
		'rc_render_settings'
	);
} );

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$pages = [ 'woocommerce_page_resumen-cobros', 'woocommerce_page_resumen-cobros-settings' ];
	if ( ! in_array( $hook, $pages, true ) ) {
		return;
	}

	wp_enqueue_style(
		'rc-admin',
		RC_PLUGIN_URL . 'assets/css/admin.css',
		[],
		RC_VERSION
	);

	wp_enqueue_script(
		'rc-admin',
		RC_PLUGIN_URL . 'assets/js/admin.js',
		[],
		RC_VERSION,
		true
	);

	wp_localize_script( 'rc-admin', 'rcData', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'rc_nonce' ),
	] );
} );

// ---------------------------------------------------------------------------
// Página principal
// ---------------------------------------------------------------------------
function rc_render_page() {
	$months = [
		1  => 'Enero',    2  => 'Febrero',   3  => 'Marzo',     4  => 'Abril',
		5  => 'Mayo',     6  => 'Junio',      7  => 'Julio',     8  => 'Agosto',
		9  => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
	];
	$cur_month = (int) date( 'm' );
	$cur_year  = (int) date( 'Y' );
	?>
	<div class="wrap rc-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Resumen de Cobros', 'resumen-cobros' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=resumen-cobros-settings' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Configuración', 'resumen-cobros' ); ?>
		</a>

		<div class="rc-toolbar">
			<label class="rc-label">
				<?php esc_html_e( 'Mes', 'resumen-cobros' ); ?>
				<select id="rc-month">
					<?php foreach ( $months as $num => $name ) : ?>
						<option value="<?php echo esc_attr( $num ); ?>" <?php selected( $num, $cur_month ); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="rc-label">
				<?php esc_html_e( 'Año', 'resumen-cobros' ); ?>
				<input type="number" id="rc-year" value="<?php echo esc_attr( $cur_year ); ?>" min="2020" max="2035" style="width:80px;">
			</label>

			<button type="button" id="rc-btn-generar" class="button button-primary">
				<?php esc_html_e( 'Generar Reporte', 'resumen-cobros' ); ?>
			</button>

			<button type="button" id="rc-btn-export" class="button" style="display:none;">
				<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
				<?php esc_html_e( 'Exportar CSV', 'resumen-cobros' ); ?>
			</button>

			<button type="button" id="rc-btn-clear-cache" class="button" title="<?php esc_attr_e( 'Borra el cache de Conekta de todos los pedidos del mes seleccionado y vuelve a consultarlos', 'resumen-cobros' ); ?>">
				<span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
				<?php esc_html_e( 'Limpiar cache Conekta', 'resumen-cobros' ); ?>
			</button>
		</div>

		<div id="rc-loading" class="rc-state" style="display:none;">
			<span class="spinner is-active"></span>
			<?php esc_html_e( 'Cargando datos desde WooCommerce y Conekta…', 'resumen-cobros' ); ?>
		</div>

		<div id="rc-error" class="rc-state rc-error notice notice-error" style="display:none;"></div>

		<div id="rc-report" style="display:none;"></div>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Página de configuración
// ---------------------------------------------------------------------------
function rc_render_settings() {
	if ( isset( $_POST['rc_save'] ) && check_admin_referer( 'rc_settings' ) ) {
		update_option( 'rc_conekta_api_key',     sanitize_text_field( wp_unslash( $_POST['rc_conekta_api_key'] ?? '' ) ) );
		update_option( 'rc_card_methods',        sanitize_text_field( wp_unslash( $_POST['rc_card_methods'] ?? 'conekta' ) ) );
		update_option( 'rc_cash_methods',        sanitize_text_field( wp_unslash( $_POST['rc_cash_methods'] ?? 'cod' ) ) );
		update_option( 'rc_iva_rate',            floatval( str_replace( ',', '.', $_POST['rc_iva_rate'] ?? '16' ) ) / 100 );
		update_option( 'rc_commission_credit',   floatval( str_replace( ',', '.', $_POST['rc_commission_credit'] ?? '3.6' ) ) / 100 );
		update_option( 'rc_commission_debit',    floatval( str_replace( ',', '.', $_POST['rc_commission_debit'] ?? '2.9' ) ) / 100 );
		update_option( 'rc_commission_fixed',    floatval( str_replace( ',', '.', $_POST['rc_commission_fixed'] ?? '3' ) ) );

		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Configuración guardada.', 'resumen-cobros' ) . '</strong></p></div>';
	}

	$api_key      = get_option( 'rc_conekta_api_key', '' );
	$card_methods = get_option( 'rc_card_methods', 'conekta' );
	$cash_methods = get_option( 'rc_cash_methods', 'cod' );
	$iva_rate     = round( floatval( get_option( 'rc_iva_rate', 0.16 ) ) * 100, 2 );
	$comm_credit  = round( floatval( get_option( 'rc_commission_credit', 0.036 ) ) * 100, 3 );
	$comm_debit   = round( floatval( get_option( 'rc_commission_debit', 0.029 ) ) * 100, 3 );
	$comm_fixed   = floatval( get_option( 'rc_commission_fixed', 3 ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Cobros – Configuración', 'resumen-cobros' ); ?></h1>

		<?php
		// Show all active payment methods for reference
		$all_methods = WC()->payment_gateways()->get_available_payment_gateways();
		if ( $all_methods ) :
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Métodos de pago activos en tu tienda:', 'resumen-cobros' ); ?></strong><br>
				<?php
				$method_labels = [];
				foreach ( $all_methods as $id => $gateway ) {
					$method_labels[] = '<code>' . esc_html( $id ) . '</code> — ' . esc_html( $gateway->get_title() );
				}
				echo implode( ' &nbsp;|&nbsp; ', $method_labels );
				?>
			</p>
		</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'rc_settings' ); ?>

			<h2><?php esc_html_e( 'Conekta', 'resumen-cobros' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key privada de Conekta', 'resumen-cobros' ); ?></th>
					<td>
						<input type="password" name="rc_conekta_api_key"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Ej: key_xxxxxxxx — se usa para obtener el tipo de tarjeta (débito/crédito) desde la API de Conekta. El resultado se guarda en el pedido para no repetir llamadas.', 'resumen-cobros' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Comisión crédito (%)', 'resumen-cobros' ); ?></th>
					<td>
						<input type="number" name="rc_commission_credit"
							value="<?php echo esc_attr( $comm_credit ); ?>"
							step="0.001" min="0" max="20" style="width:90px;"> %
						<p class="description"><?php esc_html_e( 'Porcentaje que cobra Conekta por tarjeta crédito (sin incluir el cargo fijo).', 'resumen-cobros' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Comisión débito (%)', 'resumen-cobros' ); ?></th>
					<td>
						<input type="number" name="rc_commission_debit"
							value="<?php echo esc_attr( $comm_debit ); ?>"
							step="0.001" min="0" max="20" style="width:90px;"> %
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cargo fijo por transacción (MXN)', 'resumen-cobros' ); ?></th>
					<td>
						<input type="number" name="rc_commission_fixed"
							value="<?php echo esc_attr( $comm_fixed ); ?>"
							step="0.01" min="0" max="100" style="width:90px;"> MXN
						<p class="description"><?php esc_html_e( 'Conekta cobra un cargo fijo por transacción además del porcentaje.', 'resumen-cobros' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Métodos de pago', 'resumen-cobros' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Métodos con tarjeta (Conekta)', 'resumen-cobros' ); ?></th>
					<td>
						<input type="text" name="rc_card_methods"
							value="<?php echo esc_attr( $card_methods ); ?>"
							class="regular-text">
						<p class="description"><?php esc_html_e( 'IDs separados por coma. Ej: conekta, conekta-card, wc_conekta', 'resumen-cobros' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Métodos en efectivo', 'resumen-cobros' ); ?></th>
					<td>
						<input type="text" name="rc_cash_methods"
							value="<?php echo esc_attr( $cash_methods ); ?>"
							class="regular-text">
						<p class="description"><?php esc_html_e( 'IDs separados por coma. Ej: cod, bacs, cash', 'resumen-cobros' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tasa IVA (%)', 'resumen-cobros' ); ?></th>
					<td>
						<input type="number" name="rc_iva_rate"
							value="<?php echo esc_attr( $iva_rate ); ?>"
							step="0.1" min="0" max="30" style="width:80px;"> %
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Guardar configuración', 'resumen-cobros' ), 'primary', 'rc_save' ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Diagnóstico de pedido', 'resumen-cobros' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Introduce un número de pedido (ej: 2026-0090) para ver qué meta de Conekta tiene guardada y qué devuelve la API. Útil para depurar por qué un pedido no se clasifica bien.', 'resumen-cobros' ); ?></p>
		<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
			<input type="text" id="rc-diag-order" placeholder="<?php esc_attr_e( 'Número de pedido…', 'resumen-cobros' ); ?>" style="width:220px;">
			<button type="button" id="rc-diag-btn" class="button"><?php esc_html_e( 'Diagnosticar', 'resumen-cobros' ); ?></button>
		</div>
		<pre id="rc-diag-result" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;overflow:auto;max-height:400px;display:none;font-size:12px;"></pre>
	</div>
	<?php
}
