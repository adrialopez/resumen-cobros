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

	// Rango del mes completo en timestamps (más fiable con HPOS)
	$ts_from = mktime( 0,  0,  0,  $month, 1,                                   $year );
	$ts_to   = mktime( 23, 59, 59, $month, (int) date( 't', $ts_from ),          $year );

	// Configuración del plugin
	$api_key          = get_option( 'rc_conekta_api_key', '' );
	$card_methods_raw = get_option( 'rc_card_methods', 'conekta' );
	$cash_methods_raw = get_option( 'rc_cash_methods', 'cod' );
	$iva_rate         = floatval( get_option( 'rc_iva_rate', 0.16 ) );

	$card_methods = array_filter( array_map( 'trim', explode( ',', $card_methods_raw ) ) );
	$cash_methods = array_filter( array_map( 'trim', explode( ',', $cash_methods_raw ) ) );

	// Pedidos del mes — estados base + estados extra configurados
	$extra_statuses_raw = get_option( 'rc_extra_statuses', '' );
	$extra_statuses     = array_filter( array_map( 'trim', explode( ',', $extra_statuses_raw ) ) );
	$order_statuses     = array_values( array_unique( array_merge( [ 'completed', 'processing' ], $extra_statuses ) ) );

	$wc_orders = wc_get_orders( [
		'limit'        => -1,
		'status'       => $order_statuses,
		'date_created' => $ts_from . '...' . $ts_to,
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

			// Usar fee real de Conekta (null si no disponible — no se calcula)
			$row['conekta_fee'] = ( $conekta_info !== null && $conekta_info['fee'] !== null )
				? $conekta_info['fee']
				: null;
			$row['fee_source']  = ( $row['conekta_fee'] !== null ) ? 'conekta' : 'none';
			$row['bbva_net']    = ( $row['conekta_fee'] !== null )
				? round( $row['total'] - $row['conekta_fee'], 2 )
				: null;

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

	$ts_from = mktime( 0,  0,  0,  $month, 1,                                  $year );
	$ts_to   = mktime( 23, 59, 59, $month, (int) date( 't', $ts_from ),         $year );

	$wc_orders = wc_get_orders( [
		'limit'        => -1,
		'date_created' => $ts_from . '...' . $ts_to,
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
	// Siempre intentar Conekta API primero si está disponible — es la fuente de verdad
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

	// Fallback 1: meta guardada previamente (puede ser incorrecta si fue el fallback default)
	$cached_type = $order->get_meta( '_rc_card_type' );
	if ( in_array( $cached_type, [ 'credito', 'debito' ], true ) ) {
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

// ---------------------------------------------------------------------------
// AJAX: diagnóstico de un pedido — muestra meta Conekta y respuesta de API
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_rc_diagnose_order', 'rc_ajax_diagnose_order' );

function rc_ajax_diagnose_order() {
	check_ajax_referer( 'rc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$order_input = sanitize_text_field( wp_unslash( $_POST['order_id'] ?? '' ) );
	$order = null;

	// Por ID numérico interno de WC
	if ( is_numeric( $order_input ) ) {
		$order = wc_get_order( (int) $order_input );
	}

	// Por número de pedido (ej. 2026-0090) — wc_get_orders no hace match exacto con HPOS,
	// por eso buscamos varios candidatos y verificamos el número manualmente.
	if ( ! $order ) {
		$candidates = wc_get_orders( [
			'order_number' => $order_input,
			'limit'        => 20,
			'status'       => 'any',
			'return'       => 'objects',
		] );
		foreach ( $candidates as $candidate ) {
			if ( $candidate->get_order_number() === $order_input ) {
				$order = $candidate;
				break;
			}
		}
	}

	if ( ! $order ) {
		wp_send_json_error( [ 'message' => 'Pedido no encontrado: ' . $order_input ] );
	}

	// Recopilar toda la info relevante
	$info = [
		'wc_id'          => $order->get_id(),
		'wc_number'      => $order->get_order_number(),
		'payment_method' => $order->get_payment_method(),
		'payment_title'  => $order->get_payment_method_title(),
		'transaction_id' => $order->get_transaction_id(),
		'status'         => $order->get_status(),
		'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
		'total'          => $order->get_total(),
	];

	// Meta relacionada con Conekta
	$conekta_meta = [];
	$all_meta     = $order->get_meta_data();
	foreach ( $all_meta as $meta ) {
		$data = $meta->get_data();
		$key  = $data['key'];
		$val  = is_string( $data['value'] ) ? $data['value'] : json_encode( $data['value'] );

		// Mostrar meta de Conekta o de este plugin, y meta con valores que parecen IDs Conekta
		if (
			str_contains( strtolower( $key ), 'conekta' ) ||
			str_starts_with( $key, '_rc_' ) ||
			str_starts_with( (string) $val, 'ord_' ) ||
			str_starts_with( (string) $val, 'ch_' )
		) {
			$conekta_meta[ $key ] = $val;
		}
	}
	$info['conekta_meta'] = $conekta_meta;
	$info['found_conekta_id'] = rc_find_conekta_id( $order );

	// Llamada a Conekta API si hay key y ID
	$api_key    = get_option( 'rc_conekta_api_key', '' );
	$conekta_id = $info['found_conekta_id'];

	if ( $api_key && $conekta_id ) {
		// Limpiar transient para forzar llamada fresca
		delete_transient( 'rc_ck_' . md5( $conekta_id ) );

		// Hacer llamada directa para exponer código HTTP y cuerpo raw (útil para depurar 401/404)
		$base_url  = 'https://api.conekta.io';
		$endpoints = str_starts_with( $conekta_id, 'ch_' )
			? [ '/charges/' . urlencode( $conekta_id ) ]
			: [ '/orders/' . urlencode( $conekta_id ), '/charges/' . urlencode( $conekta_id ) ];

		$raw_calls = [];

		$do_fetch = function( string $ep ) use ( $base_url, $api_key, &$raw_calls ): ?array {
			$response = wp_remote_get( $base_url . $ep, [
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/vnd.conekta-v2.2.0+json',
					'Content-Type'  => 'application/json',
				],
				'timeout' => 15,
			] );
			if ( is_wp_error( $response ) ) {
				$raw_calls[ $ep ] = [ 'error' => $response->get_error_message() ];
				return null;
			}
			$http_code = wp_remote_retrieve_response_code( $response );
			$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
			$raw_calls[ $ep ] = [ 'http_code' => $http_code, 'body' => $decoded ];
			return ( $http_code === 200 && $decoded ) ? $decoded : null;
		};

		foreach ( $endpoints as $ep ) {
			$body = $do_fetch( $ep );
			// Si es respuesta de order, también buscar el charge interno para ver fee
			if ( $body && isset( $body['charges']['data'][0]['id'] ) ) {
				$charge_id = $body['charges']['data'][0]['id'];
				$do_fetch( '/charges/' . urlencode( $charge_id ) );
			}
		}
		$info['api_raw'] = $raw_calls;

		// También intentar parsear como antes para ver resultado limpio
		$client               = new RC_Conekta_Client( $api_key );
		$api_result           = $client->get_payment_info( $conekta_id );
		$info['api_response'] = $api_result ?: 'null — la API no devolvió datos (ver api_raw para detalles)';
	} elseif ( ! $api_key ) {
		$info['api_response'] = 'No hay API key configurada.';
	} else {
		$info['api_response'] = 'No se encontró ID de Conekta en el pedido.';
	}

	wp_send_json_success( $info );
}
