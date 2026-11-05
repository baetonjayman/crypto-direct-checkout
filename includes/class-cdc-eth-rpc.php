<?php
/**
 * Reads Ethereum through a standard node connection (JSON-RPC).
 *
 * Works with any Ethereum node: a free public one by default, or the
 * store's own node / provider (Alchemy, Infura, QuickNode...). It only READS
 * public blockchain data and never touches money.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Eth_RPC {

	const DEFAULT_URL    = 'https://ethereum-rpc.publicnode.com';
	const USDT_CONTRACT  = '0xdac17f958d2ee523a2206206994597c13d831ec7';
	const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
	const CONFIRMATIONS  = 12;    // ~2.5 minutes; deep enough that the block won't be undone.
	const CHUNK          = 1000;  // Blocks per log query (public nodes limit ranges).
	const MAX_RANGE      = 8000;  // ~26 hours of blocks.

	private $url;

	public function __construct( $url = '' ) {
		$url       = trim( (string) $url );
		$this->url = '' !== $url ? $url : self::DEFAULT_URL;
	}

	/** Latest block number, or WP_Error. */
	public function block_number() {
		$r = $this->call( 'eth_blockNumber', array() );
		return is_wp_error( $r ) ? $r : self::hex_int( $r );
	}

	/**
	 * Confirmed incoming USDT transfers to $wallet from block $from on.
	 *
	 * @return array|WP_Error [ [ 'txid', 'value_micro', 'pos' (block) ], ... ]
	 */
	public function get_incoming_transfers( $wallet, $from ) {
		$latest = $this->block_number();
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		$to   = $latest - self::CONFIRMATIONS;
		$from = max( (int) $from, $to - self::MAX_RANGE );
		if ( $to < $from ) {
			return array();
		}

		$topic_to = '0x' . str_repeat( '0', 24 ) . CDC_Eth_Address::to_hex20( $wallet );
		$logs     = array();
		for ( $start = $from; $start <= $to; $start += self::CHUNK ) {
			$end = min( $to, $start + self::CHUNK - 1 );
			$r   = $this->call(
				'eth_getLogs',
				array(
					array(
						'fromBlock' => '0x' . dechex( $start ),
						'toBlock'   => '0x' . dechex( $end ),
						'address'   => self::USDT_CONTRACT,
						'topics'    => array( self::TRANSFER_TOPIC, null, $topic_to ),
					),
				)
			);
			if ( is_wp_error( $r ) ) {
				if ( empty( $logs ) ) {
					return $r;
				}
				break; // Keep what we have; the next check continues.
			}
			$logs = array_merge( $logs, is_array( $r ) ? $r : array() );
		}
		return self::parse_logs( $logs, $wallet );
	}

	/**
	 * Looks up one transaction and checks it is a confirmed, successful USDT
	 * transfer into $wallet.
	 *
	 * @return array|WP_Error [ 'value_micro', 'pos' (block) ]
	 */
	public function get_tx_transfer( $txid, $wallet ) {
		$receipt = $this->call( 'eth_getTransactionReceipt', array( $txid ) );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		$result = self::parse_receipt( $receipt, $wallet );
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'cdc_' . $result['error'], $result['error'] );
		}
		$latest = $this->block_number();
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		if ( $latest - $result['pos'] < self::CONFIRMATIONS ) {
			return new WP_Error( 'cdc_needs_confirmations', 'needs_confirmations' );
		}
		return $result;
	}

	/* ---------- Pure parsing (no network, unit-tested) ---------- */

	/**
	 * Sums USDT Transfer logs to $wallet per transaction.
	 */
	public static function parse_logs( $logs, $wallet ) {
		$want = '0x' . str_repeat( '0', 24 ) . CDC_Eth_Address::to_hex20( $wallet );
		$by   = array();
		foreach ( (array) $logs as $log ) {
			if ( ! is_array( $log ) || ! empty( $log['removed'] ) ) {
				continue; // "removed" = undone by a chain reorganisation.
			}
			$addr   = isset( $log['address'] ) ? strtolower( $log['address'] ) : '';
			$topics = isset( $log['topics'] ) && is_array( $log['topics'] ) ? array_map( 'strtolower', $log['topics'] ) : array();
			$txid   = isset( $log['transactionHash'] ) ? strtolower( (string) $log['transactionHash'] ) : '';
			if ( self::USDT_CONTRACT !== $addr || count( $topics ) < 3 || self::TRANSFER_TOPIC !== $topics[0] || $want !== $topics[2] ) {
				continue;
			}
			if ( ! self::is_txid( $txid ) ) {
				continue;
			}
			$amount = CDC_Tron_API::hex_to_micro( self::strip0x( isset( $log['data'] ) ? $log['data'] : '' ) );
			if ( null === $amount || $amount <= 0 ) {
				continue;
			}
			if ( ! isset( $by[ $txid ] ) ) {
				$by[ $txid ] = array(
					'txid'        => $txid,
					'value_micro' => 0,
					'pos'         => self::hex_int( isset( $log['blockNumber'] ) ? $log['blockNumber'] : '0x0' ),
				);
			}
			$by[ $txid ]['value_micro'] += $amount;
		}
		return array_values( $by );
	}

	public static function parse_receipt( $receipt, $wallet ) {
		if ( ! is_array( $receipt ) || empty( $receipt['blockNumber'] ) ) {
			return array( 'error' => 'not_confirmed_yet' ); // Unknown or still pending.
		}
		if ( ! isset( $receipt['status'] ) || '0x1' !== strtolower( $receipt['status'] ) ) {
			return array( 'error' => 'failed' );
		}
		$logs  = isset( $receipt['logs'] ) && is_array( $receipt['logs'] ) ? $receipt['logs'] : array();
		$found = false;
		foreach ( $logs as $log ) {
			$addr   = isset( $log['address'] ) ? strtolower( $log['address'] ) : '';
			$topics = isset( $log['topics'] ) && is_array( $log['topics'] ) ? $log['topics'] : array();
			if ( self::USDT_CONTRACT === $addr && isset( $topics[0] ) && self::TRANSFER_TOPIC === strtolower( $topics[0] ) ) {
				$found = true;
			}
		}
		if ( ! $found ) {
			return array( 'error' => 'not_usdt' );
		}
		$hash = strtolower( isset( $receipt['transactionHash'] ) ? (string) $receipt['transactionHash'] : '' );
		foreach ( $logs as &$log ) {
			$log['transactionHash'] = $hash; // Same tx for all its logs.
		}
		unset( $log );
		$parsed = self::parse_logs( $logs, $wallet );
		if ( empty( $parsed ) ) {
			return array( 'error' => 'wrong_wallet' );
		}
		return array(
			'value_micro' => $parsed[0]['value_micro'],
			'pos'         => self::hex_int( $receipt['blockNumber'] ),
		);
	}

	public static function is_txid( $txid ) {
		return is_string( $txid ) && 1 === preg_match( '/^0x[0-9a-f]{64}$/', $txid );
	}

	public static function hex_int( $hex ) {
		$hex = self::strip0x( (string) $hex );
		if ( '' === $hex || strlen( $hex ) > 15 || ! ctype_xdigit( $hex ) ) {
			return 0;
		}
		return (int) hexdec( $hex );
	}

	private static function strip0x( $s ) {
		$s = strtolower( (string) $s );
		return 0 === strpos( $s, '0x' ) ? substr( $s, 2 ) : $s;
	}

	/* ---------- HTTP ---------- */

	private function call( $method, $params ) {
		$response = wp_remote_post(
			$this->url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => $method,
						'params'  => $params,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return new WP_Error( 'cdc_rate_limited', 'The Ethereum node is limiting requests (HTTP 429). Use your own node URL (for example a free Alchemy or Infura key) to avoid this' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'cdc_http', 'Ethereum node returned HTTP ' . $code );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'cdc_json', 'Ethereum node sent an unreadable reply' );
		}
		if ( isset( $json['error'] ) ) {
			$msg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : 'unknown error';
			return new WP_Error( 'cdc_rpc', 'Ethereum node error: ' . substr( wp_strip_all_tags( $msg ), 0, 200 ) );
		}
		return array_key_exists( 'result', $json ) ? $json['result'] : new WP_Error( 'cdc_json', 'Ethereum node sent no result' );
	}
}
