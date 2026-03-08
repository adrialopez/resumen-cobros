<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// AJAX: generar reporte
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_rc_get_report', 'rc_ajax_get_report' );

function rc_ajax_get_report() {
	check_ajax_referer( 'rc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$month = isset( $_GET['month'] ) ? absint( $_GET['month'] ) : (int) date( 'm' );
	$year  = isset( $_GET['year'] )  ? absint( $_GET['year'] )  : (int) date( 'Y' );

	if ( $month < 1 || $month > 12 ) {
		wp_send_json_error( [ 'message' => 'Mes inválido.' ], 400 );
	}

	// Rango del mes completo
	$date_from  = sprintf( '%04d-%02d-01', $year, $month );
	$date_to    = date( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) );

	// Configuración del plugin
	$api_key          = get_option( 'rc_conekta_api_key', '' );
	$card_methods_raw = get_option( 'rc_card_methods', 'conekta' );
	$cash_methods_raw = get_option( 'rc_cash_methods', 'cod' );
	$iva_rate         = floatval( get_option( 'rc_iva_rate', 0.16 ) );
	$comm_credit      = floatval( get_option( 'rc_commission_credit', 0.036 ) );
	$comm_debit       = floatval( get_option( 'rc_commission_debit', 0.029 ) );
	$comm_fixed       = floatval( get_option( 'rc_commission_fixed', 3.0 ) );

	$card_methods = array_filter( array_map( 'trim', explode( ',', $card_methods_raw ) ) );
	$cash_methods = array_filter( array_map( 'trim', explode( ',', $cash_methods_raw ) ) );

	// Pedidos del mes (completados + procesando)
	$wc_orders = wc_get_orders( [
		'limit'        => -1,
		'status'       => [ 'completed', 'processing' ],
		'date_created' => $date_from . '...' . $date_to,
		'orderby'      => 'date',
		'order'        => 'ASC',
		'return'       => 'objects',
	] );

	// Cliente Conekta (solo si hay API key configurada)
	$conekta = ( $api_key !== '' ) ? new RC_Conekta_Client( $api_key ) : null;

	$sections = [
		'credito'  => [],
		'debito'   => [],
		'efectivo' => [],
		'otros'    => [],
	];

	foreach ( $wc_orders as $order ) {
		$pm_id = $order->get_payment_method();
		$row   = rc_build_row( $order, $iva_rate );

		if ( in_array( $pm_id, $cash_methods, true ) ) {
			$sections['efectivo'][] = $row;

		} elseif ( in_array( $pm_id, $card_methods, true ) ) {
			// Determinar débito vs crédito + fee real desde Conekta
			[ $card_type, $conekta_info ] = rc_resolve_card_type( $order, $conekta );

			$row['card_type']    = $card_type;
			$row['brand']        = $order->get_meta( '_rc_card_brand' );
			$row['last4']        = $order->get_meta( '_rc_card_last4' );

			// Usar fee real de Conekta si está disponible, si no calcular
			if ( $conekta_info !== null && $conekta_info['fee'] !== null ) {
				$row['conekta_fee']  = $conekta_info['fee'];
				$row['fee_source']   = 'conekta';
			} else {
				$comm_rate           = ( $card_type === 'debito' ) ? $comm_debit : $comm_credit;
				$row['conekta_fee']  = round( $row['total'] * $comm_rate + $comm_fixed, 2 );
				$row['fee_source']   = 'calculated';
			}
			$row['bbva_net'] = round( $row['total'] - $row['conekta_fee'], 2 );

			$sections[ $card_type ][] = $row;

		} else {
			$sections['otros'][] = $row;
		}
	}

	$months_es = [
		1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
		5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
		9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
	];

	wp_send_json_success( [
		'sections'    => $sections,
		'summary'     => rc_calculate_summary( $sections ),
		'month_label' => strtoupper( $months_es[ $month ] ) . ' DE ' . $year,
		'iva_rate'    => $iva_rate,
		'no_api_key'  => empty( $api_key ),
	] );
}

// ---------------------------------------------------------------------------
// AJAX: limpiar cache de todos los pedidos de un mes (fuerza re-consulta a Conekta)
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_rc_clear_month_cache', 'rc_ajax_clear_month_cache' );

function rc_ajax_clear_month_cache() {
	check_ajax_referer( 'rc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$month = absint( $_POST['month'] ?? 0 );
	$year  = absint( $_POST['year'] ?? 0 );

	if ( ! $month || ! $year ) {
		wp_send_json_error( [ 'message' => 'Mes/año requeridos.' ], 400 );
	}

	$date_from = sprintf( '%04d-%02d-01', $year, $month );
	$date_to   = date( 'Y-m-t', mktime( 0, 0, 0, $month, 1, $year ) );

	$wc_orders = wc_get_orders( [
		'limit'        => -1,
		'date_created' => $date_from . '...' . $date_to,
		'return'       => 'objects',
	] );

	$cleared = 0;
	foreach ( $wc_orders as $order ) {
		// Limpiar meta cacheada de este plugin
		$order->delete_meta_data( '_rc_card_type' );
		$order->delete_meta_data( '_rc_card_brand' );
		$order->delete_meta_data( '_rc_card_last4' );
		$order->save_meta_data();

		// Limpiar transient de Conekta
		$conekta_id = rc_find_conekta_id( $order );
		if ( $conekta_id ) {
			delete_transient( 'rc_ck_' . md5( $conekta_id ) );
		}
		$cleared++;
	}

	wp_send_json_success( [ 'message' => "Cache limpiada en {$cleared} pedidos." ] );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Construye la fila base de un pedido.
 */
function rc_build_row( WC_Order $order, float $iva_rate ): array {
	$total = (float) $order->get_total();
	$tax   = (float) $order->get_total_tax();

	// Si WooCommerce no registró impuesto por separado, lo calculamos
	if ( $tax <= 0 && $iva_rate > 0 ) {
		$base = $total / ( 1 + $iva_rate );
		$tax  = $total - $base;
	} else {
		$base = $total - $tax;
	}

	return [
		'id'             => $order->get_id(),
		'number'         => $order->get_order_number(),
		'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y' ) : '',
		'customer'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'description'    => rc_order_description( $order ),
		'base'           => round( $base, 2 ),
		'iva'            => round( $tax, 2 ),
		'total'          => round( $total, 2 ),
		'payment_method' => $order->get_payment_method_title(),
		'transaction_id' => $order->get_transaction_id(),
		'card_type'      => '',
		'brand'          => '',
		'last4'          => '',
		'conekta_fee'    => 0.0,
		'bbva_net'       => round( $total, 2 ),
	];
}

/**
 * Devuelve una descripción corta del pedido (primer producto o "Venta").
 */
function rc_order_description( WC_Order $order ): string {
	$items = $order->get_items();
	if ( empty( $items ) ) {
		return 'Venta';
	}
	$first = reset( $items );
	$name  = $first->get_name();
	$qty   = count( $items );
	return $qty > 1 ? $name . ' (+' . ( $qty - 1 ) . ')' : $name;
}

/**
 * Determina si un pago con tarjeta es débito o crédito, y devuelve la info de Conekta.
 * Retorna [ string $card_type, ?array $conekta_info ]
 * Prioridad: (1) meta cacheada → (2) meta del plugin Conekta → (3) API de Conekta.
 */
function rc_resolve_card_type( WC_Order $order, ?RC_Conekta_Client $conekta ): array {
	// 1. Meta ya guardada por este plugin (solo tipo, sin fee actualizado)
	$cached_type = $order->get_meta( '_rc_card_type' );
	if ( in_array( $cached_type, [ 'credito', 'debito' ], true ) ) {
		// Si tenemos tipo cacheado, intentar refrescar fee desde API si no lo tenemos
		if ( $conekta ) {
			$conekta_id = rc_find_conekta_id( $order );
			if ( $conekta_id ) {
				$info = $conekta->get_payment_info( $conekta_id );
				if ( $info ) {
					return [ $cached_type, $info ];
				}
			}
		}
		return [ $cached_type, null ];
	}

	// 2. Meta del propio plugin de Conekta
	$meta_keys = [ '_conekta_payment_type', '_conekta_card_type', '_payment_type', '_card_type' ];
	foreach ( $meta_keys as $key ) {
		$val = (string) $order->get_meta( $key );
		if ( $val !== '' ) {
			$type = str_contains( strtolower( $val ), 'debit' ) ? 'debito' : 'credito';
			rc_save_card_type( $order, $type );
			return [ $type, null ];
		}
	}

	// 3. Llamada a Conekta API
	if ( $conekta ) {
		$conekta_id = rc_find_conekta_id( $order );

		if ( $conekta_id !== '' ) {
			$info = $conekta->get_payment_info( $conekta_id );
			if ( $info ) {
				$type = ( $info['account_type'] === 'debit' ) ? 'debito' : 'credito';
				rc_save_card_type( $order, $type, $info['brand'], $info['last4'] );
				return [ $type, $info ];
			}
		}
	}

	// Fallback
	return [ 'credito', null ];
}

/**
 * Busca el ID de Conekta en el pedido usando múltiples estrategias.
 * Revisa meta keys conocidos y también escanea todos los meta buscando patrones ord_/ch_.
 */
function rc_find_conekta_id( WC_Order $order ): string {
	// Meta keys conocidos de los distintos plugins de Conekta para WooCommerce
	$known_keys = [
		'_conekta_order_id',
		'_transaction_id',
		'conekta_order_id',
		'_conekta_charge_id',
		'_conekta_payment_id',
	];

	foreach ( $known_keys as $key ) {
		$val = (string) $order->get_meta( $key );
		if ( $val !== '' && ( str_starts_with( $val, 'ord_' ) || str_starts_with( $val, 'ch_' ) ) ) {
			return $val;
		}
	}

	// transaction_id estándar de WooCommerce (el plugin de Conekta lo usa)
	$txn = (string) $order->get_transaction_id();
	if ( $txn !== '' && ( str_starts_with( $txn, 'ord_' ) || str_starts_with( $txn, 'ch_' ) ) ) {
		return $txn;
	}

	// Último recurso: escanear TODOS los meta buscando cualquier valor ord_xxx o ch_xxx
	$all_meta = $order->get_meta_data();
	foreach ( $all_meta as $meta ) {
		$data = $meta->get_data();
		$val  = (string) ( $data['value'] ?? '' );
		if ( str_starts_with( $val, 'ord_' ) || str_starts_with( $val, 'ch_' ) ) {
			return $val;
		}
	}

	return '';
}

/**
 * Guarda el tipo de tarjeta en el meta del pedido para evitar futuras llamadas API.
 */
function rc_save_card_type( WC_Order $order, string $type, string $brand = '', string $last4 = '' ): void {
	$order->update_meta_data( '_rc_card_type', $type );
	if ( $brand !== '' ) {
		$order->update_meta_data( '_rc_card_brand', $brand );
	}
	if ( $last4 !== '' ) {
		$order->update_meta_data( '_rc_card_last4', $last4 );
	}
	$order->save_meta_data();
}

/**
 * Calcula totales por sección y el gran total.
 */
function rc_calculate_summary( array $sections ): array {
	$totals = [];

	foreach ( $sections as $key => $rows ) {
		$totals[ $key ] = [
			'count'       => count( $rows ),
			'base'        => round( array_sum( array_column( $rows, 'base' ) ), 2 ),
			'iva'         => round( array_sum( array_column( $rows, 'iva' ) ), 2 ),
			'total'       => round( array_sum( array_column( $rows, 'total' ) ), 2 ),
			'conekta_fee' => round( array_sum( array_column( $rows, 'conekta_fee' ) ), 2 ),
			'bbva_net'    => round( array_sum( array_column( $rows, 'bbva_net' ) ), 2 ),
		];
	}

	$grand_total = round(
		( $totals['credito']['total']  ?? 0 ) +
		( $totals['debito']['total']   ?? 0 ) +
		( $totals['efectivo']['total'] ?? 0 ),
		2
	);
	$grand_base = round(
		( $totals['credito']['base']  ?? 0 ) +
		( $totals['debito']['base']   ?? 0 ) +
		( $totals['efectivo']['base'] ?? 0 ),
		2
	);
	$grand_iva = round( $grand_total - $grand_base, 2 );

	$totals['grand'] = [
		'count'       => ( $totals['credito']['count']  ?? 0 ) + ( $totals['debito']['count']   ?? 0 ) + ( $totals['efectivo']['count'] ?? 0 ),
		'base'        => $grand_base,
		'iva'         => $grand_iva,
		'total'       => $grand_total,
		'conekta_fee' => round( ( $totals['credito']['conekta_fee'] ?? 0 ) + ( $totals['debito']['conekta_fee'] ?? 0 ), 2 ),
		'bbva_net'    => round( ( $totals['credito']['bbva_net'] ?? 0 ) + ( $totals['debito']['bbva_net'] ?? 0 ) + ( $totals['efectivo']['total'] ?? 0 ), 2 ),
		'comparacion' => 0,
	];

	return $totals;
}
