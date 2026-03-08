<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente HTTP simple para la API de Conekta v2.
 * Obtiene tipo de tarjeta (crédito/débito), marca, últimos 4 dígitos y comisión real de Conekta.
 * Los resultados se cachean en transients 24h para evitar llamadas repetidas.
 */
class RC_Conekta_Client {

	private string $api_key;
	private string $base_url = 'https://api.conekta.io';

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Obtiene los datos del pago desde Conekta API.
	 *
	 * @param string $conekta_id  ID de order (ord_xxx) o charge (ch_xxx) de Conekta.
	 * @return array|null  Keys: account_type (credit|debit), brand, last4, fee (MXN float), fee_source. Null si falla.
	 */
	public function get_payment_info( string $conekta_id ): ?array {
		if ( empty( $conekta_id ) ) {
			return null;
		}

		$cache_key = 'rc_ck_' . md5( $conekta_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			// '' significa "consultado y no encontrado" — no reintentar hasta que expire (1h)
			return ( $cached !== '' ) ? $cached : null;
		}

		// Rutas según el tipo de ID
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

		// No encontrado — cachear vacío 1h para no repetir
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * Hace la llamada HTTP y extrae los datos del pago.
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

		// Charge endpoint: payment_method y fee en raíz
		if ( isset( $body['payment_method'] ) && isset( $body['amount'] ) ) {
			$pm  = $body['payment_method'];
			$fee = isset( $body['fee'] ) ? round( $body['fee'] / 100, 2 ) : null; // centavos → MXN
			return $this->build_result( $pm, $fee );
		}

		// Order endpoint: charges.data[0]
		if ( isset( $body['charges']['data'][0] ) ) {
			$charge = $body['charges']['data'][0];
			$pm     = $charge['payment_method'] ?? null;
			$fee    = isset( $charge['fee'] ) ? round( $charge['fee'] / 100, 2 ) : null;
			if ( $pm ) {
				return $this->build_result( $pm, $fee );
			}
		}

		return null;
	}

	private function build_result( array $pm, ?float $fee ): array {
		// 'type' es 'debit'|'credit' (campo explícito de Conekta).
		// 'account_type' puede ser un string del banco, ej. 'SWITCH BANAMEX' — no usar para clasificar.
		$raw_type = strtolower( $pm['type'] ?? $pm['account_type'] ?? 'credit' );
		$type     = str_contains( $raw_type, 'debit' ) ? 'debit' : 'credit';

		return [
			'account_type' => $type,
			'brand'        => strtolower( $pm['brand'] ?? '' ),
			'last4'        => $pm['last4'] ?? '',
			'name'         => $pm['name'] ?? '',
			'fee'          => $fee,
			'fee_source'   => $fee !== null ? 'conekta' : 'calculated',
		];
	}
}
