<?php
/**
 * Reads the Bitcoin blockchain and BTC price through a mempool.space-style API.
 *
 * Free, no key. A store can point it at its own mempool server. It only READS
 * public data and never touches money.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Btc_API {

	const DEFAULT_URL   = 'https://mempool.space/api';
	const MAX_PAGES     = 3;
	const CONFIRMATIONS = 1;
	const CURRENCIES    = array( 'USD', 'EUR', 'GBP', 'CAD', 'CHF', 'AUD', 'JPY' );

	private $base;

	public function __construct( $base = '' ) {
		$base       = rtrim( trim( (string) $base ), '/' );
		$this->base = '' !== $base ? $base : self::DEFAULT_URL;
	}

	/** Current block height, or WP_Error. */
	public function tip_height() {
		$r = $this->get( '/blocks/tip/height', false );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return ctype_digit( trim( $r ) ) ? (int) trim( $r ) : new WP_Error( 'cdc_json', 'Unreadable block height' );
	}

	/**
	 * BTC price in a fiat currency (cached for 60 seconds).
	 *
	 * @return float|WP_Error
	 */
	public function price( $currency ) {
		$currency = strtoupper( (string) $currency );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			return new WP_Error( 'cdc_currency', 'Unsupported currency' );
		}
		$cache = 'cdc_btc_prices_' . md5( $this->base );
		$json  = get_transient( $cache );
		if ( ! is_array( $json ) ) {
			$json = $this->get( '/v1/prices' );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			set_transient( $cache, $json, 60 );
		}
		$p = isset( $json[ $currency ] ) ? (float) $json[ $currency ] : 0.0;
		return $p > 0 ? $p : new WP_Error( 'cdc_price', 'No BTC price available' );
	}

	/**
	 * Payments to $address: confirmed ones from block $from on, plus unconfirmed ones.
	 *
	 * @return array|WP_Error [ 'confirmed' => [ [txid, value_micro(sats), pos(height)] ], 'unconfirmed' => [ [txid, value_micro] ] ]
	 */
	public function address_payments( $address, $from ) {
		$path  = '/address/' . rawurlencode( $address ) . '/txs';
		$all   = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$txs = $this->get( $path );
			if ( is_wp_error( $txs ) ) {
				if ( 0 === $page ) {
					return $txs;
				}
				break;
			}
			if ( ! is_array( $txs ) || empty( $txs ) ) {
				break;
			}
			$all     = array_merge( $all, $txs );
			$last    = end( $txs );
			$heights = array_filter(
				array_map(
					function ( $t ) {
						return ! empty( $t['status']['confirmed'] ) ? (int) $t['status']['block_height'] : null;
					},
					$txs
				)
			);
			// Stop once we've reached payments older than the oldest order we care about.
			if ( empty( $heights ) || min( $heights ) < $from || empty( $last['txid'] ) ) {
				break;
			}
			$path = '/address/' . rawurlencode( $address ) . '/txs/chain/' . rawurlencode( $last['txid'] );
			usleep( 250000 );
		}
		return self::parse_address_txs( $all, $address, $from );
	}

	/**
	 * One transaction: how much it paid to $address, and whether it is confirmed.
	 *
	 * @return array|WP_Error [ 'value_micro', 'pos' ]
	 */
	public function get_tx_transfer( $txid, $address ) {
		$tx = $this->get( '/tx/' . rawurlencode( $txid ) );
		if ( is_wp_error( $tx ) ) {
			return 'cdc_not_found' === $tx->get_error_code() ? new WP_Error( 'cdc_not_confirmed_yet', 'not_found' ) : $tx;
		}
		$r = self::parse_tx( $tx, $address );
		return isset( $r['error'] ) ? new WP_Error( 'cdc_' . $r['error'], $r['error'] ) : $r;
	}

	/* ---------- Pure parsing (no network, unit-tested) ---------- */

	public static function paid_to( $tx, $address ) {
		$want  = CDC_Btc_Address::normalize( $address );
		$total = 0;
		foreach ( isset( $tx['vout'] ) && is_array( $tx['vout'] ) ? $tx['vout'] : array() as $out ) {
			$a = isset( $out['scriptpubkey_address'] ) ? CDC_Btc_Address::normalize( $out['scriptpubkey_address'] ) : '';
			if ( $a === $want && isset( $out['value'] ) && is_int( $out['value'] ) && $out['value'] > 0 ) {
				$total += $out['value'];
			}
		}
		return $total;
	}

	public static function parse_address_txs( $txs, $address, $from ) {
		$confirmed   = array();
		$unconfirmed = array();
		$seen        = array();
		foreach ( (array) $txs as $tx ) {
			$txid = isset( $tx['txid'] ) ? strtolower( (string) $tx['txid'] ) : '';
			if ( ! self::is_txid( $txid ) || isset( $seen[ $txid ] ) ) {
				continue;
			}
			$seen[ $txid ] = true;
			$value         = self::paid_to( $tx, $address );
			if ( $value <= 0 ) {
				continue; // Outgoing or unrelated.
			}
			if ( ! empty( $tx['status']['confirmed'] ) ) {
				$h = isset( $tx['status']['block_height'] ) ? (int) $tx['status']['block_height'] : 0;
				if ( $h >= $from ) {
					$confirmed[] = array( 'txid' => $txid, 'value_micro' => $value, 'pos' => $h );
				}
			} else {
				$unconfirmed[] = array( 'txid' => $txid, 'value_micro' => $value );
			}
		}
		return array( 'confirmed' => $confirmed, 'unconfirmed' => $unconfirmed );
	}

	public static function parse_tx( $tx, $address ) {
		if ( ! is_array( $tx ) || empty( $tx['txid'] ) ) {
			return array( 'error' => 'not_confirmed_yet' );
		}
		$value = self::paid_to( $tx, $address );
		if ( $value <= 0 ) {
			return array( 'error' => 'wrong_wallet' );
		}
		if ( empty( $tx['status']['confirmed'] ) ) {
			return array( 'error' => 'needs_confirmations' );
		}
		return array(
			'value_micro' => $value,
			'pos'         => (int) $tx['status']['block_height'],
		);
	}

	public static function is_txid( $txid ) {
		return is_string( $txid ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $txid );
	}

	/* ---------- HTTP ---------- */

	private function get( $path, $json = true ) {
		$response = wp_remote_get(
			$this->base . $path,
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => $json ? 'application/json' : 'text/plain' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return new WP_Error( 'cdc_rate_limited', 'The Bitcoin data service is limiting requests (HTTP 429)' );
		}
		if ( 404 === $code || 400 === $code ) {
			return new WP_Error( 'cdc_not_found', 'Not found' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'cdc_http', 'Bitcoin data service returned HTTP ' . $code );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! $json ) {
			return $body;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : new WP_Error( 'cdc_json', 'Bitcoin data service sent an unreadable reply' );
	}
}
