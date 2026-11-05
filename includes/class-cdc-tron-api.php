<?php
/**
 * Reads the Tron blockchain through TronGrid.
 *
 * This only LOOKS at public blockchain data. It never holds, moves or
 * touches money — payments go straight from the customer to the store's
 * own wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Tron_API {

	const BASE            = 'https://api.trongrid.io';
	const USDT_CONTRACT   = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
	const USDT_HEX20      = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';
	const TRANSFER_TOPIC  = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
	const MAX_PAGES       = 3;

	private $api_key;

	public function __construct( $api_key = '' ) {
		$this->api_key = trim( (string) $api_key );
	}

	/**
	 * Confirmed incoming USDT transfers to $wallet since $min_ts_ms.
	 *
	 * @return array|WP_Error List of [ 'txid', 'value_micro', 'pos' (ms) ].
	 */
	public function get_incoming_transfers( $wallet, $min_ts_ms ) {
		$url = add_query_arg(
			array(
				'only_to'          => 'true',
				'only_confirmed'   => 'true',
				'contract_address' => self::USDT_CONTRACT,
				'min_timestamp'    => (int) $min_ts_ms,
				'limit'            => 200,
			),
			self::BASE . '/v1/accounts/' . rawurlencode( $wallet ) . '/transactions/trc20'
		);

		$all = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$json = $this->request( 'GET', $url );
			if ( is_wp_error( $json ) ) {
				if ( 0 === $page ) {
					return $json;
				}
				break; // Keep the pages we already have; the next check continues.
			}
			$all = array_merge( $all, self::parse_transfers( $json, $wallet ) );

			$next = isset( $json['meta']['fingerprint'] ) ? (string) $json['meta']['fingerprint'] : '';
			if ( '' === $next ) {
				break;
			}
			$url = add_query_arg( 'fingerprint', rawurlencode( $next ), $url );
			usleep( 400000 ); // Be gentle: TronGrid throttles bursts without an API key.
		}
		return $all;
	}

	/**
	 * Looks up one transaction by its ID and checks it is a confirmed,
	 * successful USDT transfer into $wallet.
	 *
	 * @return array|WP_Error [ 'value_micro', 'pos' (ms) ].
	 */
	public function get_tx_transfer( $txid, $wallet ) {
		$json = $this->request( 'POST', self::BASE . '/walletsolidity/gettransactioninfobyid', array( 'value' => $txid ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$result = self::parse_tx_info( $json, CDC_Tron_Address::to_hex20( $wallet ) );
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'cdc_' . $result['error'], $result['error'] );
		}
		return $result;
	}

	/* ---------- Pure parsing (no network, unit-tested) ---------- */

	public static function parse_transfers( $json, $wallet ) {
		$out = array();
		if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return $out;
		}
		foreach ( $json['data'] as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$token = isset( $t['token_info']['address'] ) ? $t['token_info']['address'] : '';
			$to    = isset( $t['to'] ) ? $t['to'] : '';
			$type  = isset( $t['type'] ) ? $t['type'] : 'Transfer';
			$value = isset( $t['value'] ) ? (string) $t['value'] : '';
			$txid  = isset( $t['transaction_id'] ) ? strtolower( (string) $t['transaction_id'] ) : '';

			if ( self::USDT_CONTRACT !== $token || $to !== $wallet || 'Transfer' !== $type ) {
				continue;
			}
			if ( ! ctype_digit( $value ) || strlen( $value ) > 15 || ! self::is_txid( $txid ) ) {
				continue;
			}
			$out[] = array(
				'txid'        => $txid,
				'value_micro' => (int) $value,
				'pos'         => isset( $t['block_timestamp'] ) ? (int) $t['block_timestamp'] : 0,
			);
		}
		return $out;
	}

	public static function parse_tx_info( $json, $wallet_hex20 ) {
		if ( ! is_array( $json ) || empty( $json['id'] ) ) {
			return array( 'error' => 'not_confirmed_yet' );
		}
		if ( ! isset( $json['receipt']['result'] ) ) {
			return array( 'error' => 'not_usdt' ); // Plain TRX transfers have no contract result.
		}
		if ( 'SUCCESS' !== $json['receipt']['result'] ) {
			return array( 'error' => 'failed' );
		}
		if ( empty( $json['log'] ) || ! is_array( $json['log'] ) ) {
			return array( 'error' => 'not_usdt' );
		}

		$total = 0;
		$found = false;
		foreach ( $json['log'] as $log ) {
			$addr   = isset( $log['address'] ) ? strtolower( substr( $log['address'], -40 ) ) : '';
			$topics = isset( $log['topics'] ) && is_array( $log['topics'] ) ? $log['topics'] : array();
			if ( self::USDT_HEX20 !== $addr || count( $topics ) < 3 || self::TRANSFER_TOPIC !== strtolower( $topics[0] ) ) {
				continue;
			}
			$found = true;
			if ( strtolower( substr( $topics[2], -40 ) ) !== $wallet_hex20 ) {
				continue;
			}
			$amount = self::hex_to_micro( isset( $log['data'] ) ? $log['data'] : '' );
			if ( null === $amount ) {
				continue;
			}
			$total += $amount;
		}

		if ( ! $found ) {
			return array( 'error' => 'not_usdt' );
		}
		if ( $total <= 0 ) {
			return array( 'error' => 'wrong_wallet' );
		}
		return array(
			'value_micro' => $total,
			'pos'         => isset( $json['blockTimeStamp'] ) ? (int) $json['blockTimeStamp'] : 0,
		);
	}

	public static function hex_to_micro( $hex ) {
		$hex = ltrim( strtolower( (string) $hex ), '0' );
		if ( '' === $hex ) {
			return 0;
		}
		if ( strlen( $hex ) > 13 || ! ctype_xdigit( $hex ) ) {
			return null; // Absurdly large or malformed.
		}
		return (int) hexdec( $hex );
	}

	public static function is_txid( $txid ) {
		return is_string( $txid ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $txid );
	}

	/* ---------- HTTP ---------- */

	private function request( $method, $url, $body = null ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $this->api_key ) {
			$headers['TRON-PRO-API-KEY'] = $this->api_key;
		}
		$args = array(
			'timeout' => 10,
			'headers' => $headers,
		);
		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $body );
			$response                = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return new WP_Error( 'cdc_rate_limited', 'TronGrid is limiting requests (HTTP 429). Add a free TronGrid API key to avoid this' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'cdc_http', 'TronGrid returned HTTP ' . $code );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : new WP_Error( 'cdc_json', 'TronGrid sent an unreadable reply' );
	}
}
