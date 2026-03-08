<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente HTTP para la API de Conekta v2 y el servicio de reportes.
 * - get_payment_info(): tipo de tarjeta, marca, charge_id interno.
 * - get_month_payments(): comisión y depósito real desde /reports/v1/payments.
 */
class RC_Conekta_Client {

	private string $api_key;
	private string $base_url    = 'https://api.conekta.io';
	private string $reports_url = 'https://services.conekta.com';

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Obtiene los datos del pago desde Conekta API.
	 * Retorna: account_type (credit|debit), brand, last4, charge_id (hex), fee, fee_source.
	 */
	public function get_payment_info( string $conekta_id ): ?array {
		if ( empty( $conekta_id ) ) {
			return null;
		}

		$cache_key = 'rc_ck_' . md5( $conekta_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return ( $cached !== '' ) ? $cached : null;
		}

		$endpoints = str_starts_with( $conekta_id, 'ch_' )
			? [ '/charges/' . urlencode( $conekta_id ) ]
			: [ '/orders/' . urlencode( $conekta_id ), '/charges/' . urlencode( $conekta_id ) ];

		foreach ( $endpoints as $endpoint ) {
			$result = $this->fetch_endpoint( $endpoint );
			if ( $result !== null ) {
				set_transient( $cache_key, $result, DAY_IN_SECONDS );
				return $result;
			}
		}

		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * Obtiene todos los pagos del mes desde el endpoint de reportes de Conekta.
	 * Retorna un array keyed por charge_id → [ commission (MXN), deposit_amount (MXN) ].
	 * Los montos vienen en centavos desde la API y se convierten a MXN.
	 *
	 * @param int $ts_from  Unix timestamp inicio del período.
	 * @param int $ts_to    Unix timestamp fin del período.
	 */
	public function get_month_payments( int $ts_from, int $ts_to ): array {
		$cache_key = 'rc_rpt_' . md5( $ts_from . '_' . $ts_to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$results = [];
		$url     = add_query_arg(
			[
				'from' => $ts_from * 1000, // ms
				'to'   => $ts_to * 1000,
				'size' => 1000,
			],
			$this->reports_url . '/reports/v1/payments'
		);

		while ( $url ) {
			$response = wp_remote_get( $url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/vnd.conekta-v2.2.0+json',
					'Content-Type'  => 'application/json',
				],
				'timeout' => 30,
			] );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! $body || empty( $body['data'] ) ) {
				break;
			}

			foreach ( $body['data'] as $item ) {
				if ( ! empty( $item['charge_id'] ) ) {
					$results[ $item['charge_id'] ] = [
						'commission'     => isset( $item['commission'] )     ? round( $item['commission'] / 100, 2 )     : null,
						'deposit_amount' => isset( $item['deposit_amount'] ) ? round( $item['deposit_amount'] / 100, 2 ) : null,
					];
				}
			}

			$url = ! empty( $body['has_more'] ) ? ( $body['next_page_url'] ?? null ) : null;
		}

		set_transient( $cache_key, $results, HOUR_IN_SECONDS );
		return $results;
	}

	/**
	 * Hace la llamada HTTP y extrae los datos del pago (tipo tarjeta, marca, charge_id).
	 */
	private function fetch_endpoint( string $endpoint ): ?array {
		$response = wp_remote_get(
			$this->base_url . $endpoint,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/vnd.conekta-v2.2.0+json',
					'Content-Type'  => 'application/json',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! $body ) {
			return null;
		}

		// Charge endpoint: payment_method en raíz
		if ( isset( $body['payment_method'] ) && isset( $body['amount'] ) ) {
			$pm        = $body['payment_method'];
			$charge_id = $body['id'] ?? null;
			return $this->build_result( $pm, $charge_id );
		}

		// Order endpoint: charges.data[0]
		if ( isset( $body['charges']['data'][0] ) ) {
			$charge    = $body['charges']['data'][0];
			$pm        = $charge['payment_method'] ?? null;
			$charge_id = $charge['id'] ?? null; // hex charge ID, ej. 69a658c59a5ff900163e56a1
			if ( $pm ) {
				return $this->build_result( $pm, $charge_id );
			}
		}

		return null;
	}

	private function build_result( array $pm, ?string $charge_id ): array {
		// 'type' es 'debit'|'credit' (campo explícito de Conekta).
		// 'account_type' puede ser un string del banco, ej. 'SWITCH BANAMEX'.
		$raw_type = strtolower( $pm['type'] ?? $pm['account_type'] ?? 'credit' );
		$type     = str_contains( $raw_type, 'debit' ) ? 'debit' : 'credit';

		return [
			'account_type' => $type,
			'brand'        => strtolower( $pm['brand'] ?? '' ),
			'last4'        => $pm['last4'] ?? '',
			'name'         => $pm['name'] ?? '',
			'charge_id'    => $charge_id, // ID hexadecimal del cargo (para cruzar con reportes)
			'fee'          => null,        // la fee viene de get_month_payments(), no de aquí
			'fee_source'   => 'reports',
		];
	}
}
