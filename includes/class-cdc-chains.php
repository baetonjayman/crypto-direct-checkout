<?php
/**
 * One class per supported coin/network. The rest of the plugin only talks
 * to this interface, so adding a coin means adding a class here.
 *
 * "Position" = when something happened on that chain (ms on Tron, block
 * number on Ethereum). Payments only count if they happened at or after the
 * order's start position.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CDC_Chain {

	/** Short id stored on orders, e.g. "trc20". */
	abstract public function id();

	/** WooCommerce payment method id. */
	abstract public function gateway_id();

	/** "USDT (TRC20)". */
	abstract public function label();

	/** "Tron (TRC20)" — shown in the network warning. */
	abstract public function network();

	abstract public function is_valid_address( $address );

	/** Clean a pasted transaction ID; null if it can't be one. */
	abstract public function normalize_txid( $txid );

	/** Regex (JavaScript) for a quick check in the browser. */
	abstract public function txid_js_pattern();

	abstract public function txid_placeholder();

	abstract public function explorer_tx_url( $txid );

	/** Start position for a new order, or WP_Error. */
	abstract public function start_position();

	/** Allowed position difference (clock skew etc.). */
	abstract public function position_grace();

	/** @return array|WP_Error [ [ 'txid', 'value_micro', 'pos' ], ... ] */
	abstract public function incoming( $wallet, $from_pos );

	/** @return array|WP_Error [ 'value_micro', 'pos' ] */
	abstract public function tx_transfer( $txid, $wallet );

	/** Short note on how long confirmation takes. */
	abstract public function confirm_time_text();

	public function same_address( $a, $b ) {
		return (string) $a === (string) $b;
	}

	/* ---------- Money: defaults are for USDT (6 decimals, 1 USDT = 1 USD) ---------- */

	public function symbol() {
		return 'USDT';
	}

	public function decimals() {
		return 6;
	}

	/** 25003700 -> "25.0037" (USDT) or 1234 -> "0.00001234" (BTC). */
	public function format( $units ) {
		return CDC_Matcher::format_units( $units, $this->decimals(), 2 );
	}

	/** Smallest step and how many unique extra amounts we can hand out. */
	public function unique_step() {
		return 100; // 0.0001 USDT.
	}

	public function unique_slots() {
		return 99;  // Up to +0.0099 USDT.
	}

	public function supports_currency( $currency ) {
		return 'USD' === $currency;
	}

	public function currency_problem( $currency ) {
		return 'Your store currency is ' . $currency . '. USDT payments only work when the store currency is US Dollar (USD), because 1 USDT = 1 USD. Change it under WooCommerce → Settings → General.';
	}

	/** Order total in this coin's smallest units, or WP_Error. */
	public function base_amount( WC_Order $order ) {
		return CDC_Matcher::total_to_micro( $order->get_total() );
	}

	/** Exchange rate used for the last base_amount() call (null when 1:1). */
	public function last_rate() {
		return null;
	}

	/** What the QR code contains. */
	public function qr_value( $wallet, $units ) {
		return $wallet;
	}

	/** Amount for wallets that can't send all the decimals, or null if not needed. */
	public function rounded_units( $units ) {
		return CDC_Matcher::round_up_cents( $units );
	}

	/** Can a pasted transaction ID pay this order? exact | review | too_low | mismatch */
	public function txid_decision( $value, $expected, array $other_open_amounts ) {
		if ( in_array( (int) $value, array_map( 'intval', $other_open_amounts ), true ) && (int) $value !== (int) $expected ) {
			return 'mismatch'; // It's exactly another waiting order's amount.
		}
		return CDC_Matcher::txid_decision( $value, $expected );
	}

	/** Payments seen on the network but not yet confirmed (from the last incoming() call). */
	public function last_unconfirmed() {
		return array();
	}

	public function checkout_description() {
		return 'Pay with USDT on the ' . $this->network() . ' network. You will see the wallet address and exact amount on the next page.';
	}

	/** This chain's saved settings. */
	public function settings() {
		$s = get_option( 'woocommerce_' . $this->gateway_id() . '_settings', array() );
		return is_array( $s ) ? $s : array();
	}

	public function setting( $key, $default = '' ) {
		$s = $this->settings();
		return isset( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : $default;
	}

	public function wallet() {
		return $this->setting( 'wallet_address' );
	}

	public function minutes() {
		return max( 10, min( 180, (int) $this->setting( 'payment_window', '30' ) ) );
	}

	public function is_enabled() {
		return 'yes' === $this->setting( 'enabled', 'no' );
	}

	public function is_ready() {
		return $this->is_valid_address( $this->wallet() );
	}
}

class CDC_Chain_TRC20 extends CDC_Chain {

	public function id() {
		return 'trc20';
	}
	public function gateway_id() {
		return 'crypto_direct_checkout';
	}
	public function label() {
		return 'USDT (TRC20)';
	}
	public function network() {
		return 'Tron (TRC20)';
	}
	public function is_valid_address( $address ) {
		return CDC_Tron_Address::is_valid( $address );
	}
	public function normalize_txid( $txid ) {
		$txid = strtolower( trim( (string) $txid ) );
		if ( 0 === strpos( $txid, '0x' ) ) {
			$txid = substr( $txid, 2 );
		}
		return CDC_Tron_API::is_txid( $txid ) ? $txid : null;
	}
	public function txid_js_pattern() {
		return '^(0x)?[0-9a-fA-F]{64}$';
	}
	public function txid_placeholder() {
		return '64-character transaction ID (TXID)';
	}
	public function explorer_tx_url( $txid ) {
		return 'https://tronscan.org/#/transaction/' . rawurlencode( $txid );
	}
	public function start_position() {
		return (int) ( microtime( true ) * 1000 );
	}
	public function position_grace() {
		return 120000; // 2 minutes of clock difference.
	}
	private function api() {
		return new CDC_Tron_API( $this->setting( 'trongrid_api_key' ) );
	}
	public function incoming( $wallet, $from_pos ) {
		return $this->api()->get_incoming_transfers( $wallet, $from_pos - $this->position_grace() );
	}
	public function tx_transfer( $txid, $wallet ) {
		return $this->api()->get_tx_transfer( $txid, $wallet );
	}
	public function confirm_time_text() {
		return 'Tron usually confirms within a minute';
	}
}

class CDC_Chain_ERC20 extends CDC_Chain {

	public function id() {
		return 'erc20';
	}
	public function gateway_id() {
		return 'cdc_usdt_erc20';
	}
	public function label() {
		return 'USDT (ERC20)';
	}
	public function network() {
		return 'Ethereum (ERC20)';
	}
	public function is_valid_address( $address ) {
		return CDC_Eth_Address::is_valid( $address );
	}
	public function same_address( $a, $b ) {
		return strtolower( (string) $a ) === strtolower( (string) $b );
	}
	public function normalize_txid( $txid ) {
		$txid = strtolower( trim( (string) $txid ) );
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $txid ) ) {
			$txid = '0x' . $txid;
		}
		return CDC_Eth_RPC::is_txid( $txid ) ? $txid : null;
	}
	public function txid_js_pattern() {
		return '^(0x)?[0-9a-fA-F]{64}$';
	}
	public function txid_placeholder() {
		return 'Transaction hash (0x… 66 characters)';
	}
	public function explorer_tx_url( $txid ) {
		return 'https://etherscan.io/tx/' . rawurlencode( $txid );
	}
	private function rpc() {
		return new CDC_Eth_RPC( $this->setting( 'rpc_url' ) );
	}
	public function start_position() {
		return $this->rpc()->block_number();
	}
	public function position_grace() {
		return 0; // Block numbers don't drift.
	}
	public function incoming( $wallet, $from_pos ) {
		return $this->rpc()->get_incoming_transfers( $wallet, $from_pos );
	}
	public function tx_transfer( $txid, $wallet ) {
		return $this->rpc()->get_tx_transfer( $txid, $wallet );
	}
	public function confirm_time_text() {
		return 'Ethereum needs about 3 minutes (12 blocks) to confirm';
	}
}

class CDC_Chain_BTC extends CDC_Chain {

	private $rate        = null;
	private $unconfirmed = array();

	public function id() {
		return 'btc';
	}
	public function gateway_id() {
		return 'cdc_btc';
	}
	public function label() {
		return 'Bitcoin (BTC)';
	}
	public function network() {
		return 'Bitcoin';
	}
	public function symbol() {
		return 'BTC';
	}
	public function decimals() {
		return 8;
	}
	public function format( $units ) {
		return CDC_Matcher::format_units( $units, 8, 8 );
	}
	public function unique_step() {
		return 1; // 1 satoshi.
	}
	public function unique_slots() {
		return 999;
	}
	public function is_valid_address( $address ) {
		return CDC_Btc_Address::is_valid( $address );
	}
	public function same_address( $a, $b ) {
		return CDC_Btc_Address::normalize( $a ) === CDC_Btc_Address::normalize( $b );
	}
	public function normalize_txid( $txid ) {
		$txid = strtolower( trim( (string) $txid ) );
		return CDC_Btc_API::is_txid( $txid ) ? $txid : null;
	}
	public function txid_js_pattern() {
		return '^[0-9a-fA-F]{64}$';
	}
	public function txid_placeholder() {
		return 'Transaction ID (64 letters and numbers)';
	}
	public function explorer_tx_url( $txid ) {
		return 'https://mempool.space/tx/' . rawurlencode( $txid );
	}
	private function api() {
		return new CDC_Btc_API( $this->setting( 'api_url' ) );
	}
	public function start_position() {
		$h = $this->api()->tip_height();
		return is_wp_error( $h ) ? $h : $h + 1; // Payments must be in a block mined after the order.
	}
	public function position_grace() {
		return 0;
	}
	public function supports_currency( $currency ) {
		return in_array( $currency, CDC_Btc_API::CURRENCIES, true );
	}
	public function currency_problem( $currency ) {
		return 'Your store currency is ' . $currency . '. Bitcoin payments work with ' . implode( ', ', CDC_Btc_API::CURRENCIES ) . '.';
	}
	public function base_amount( WC_Order $order ) {
		$price = $this->api()->price( $order->get_currency() );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		$this->rate = $price;
		return (int) ceil( ( (float) $order->get_total() / $price ) * 100000000 );
	}
	public function last_rate() {
		return $this->rate;
	}
	public function qr_value( $wallet, $units ) {
		return 'bitcoin:' . $wallet . '?amount=' . $this->format( $units ); // BIP21: wallets fill in the amount.
	}
	public function rounded_units( $units ) {
		return null; // Bitcoin wallets all accept 8 decimals.
	}
	public function txid_decision( $value, $expected, array $other_open_amounts ) {
		$value    = (int) $value;
		$expected = (int) $expected;
		if ( $value === $expected ) {
			return 'exact';
		}
		if ( $value < $expected ) {
			return 'too_low';
		}
		if ( in_array( $value, array_map( 'intval', $other_open_amounts ), true ) ) {
			return 'mismatch'; // Exactly another waiting order's amount: someone else's payment.
		}
		return 'review'; // Overpaid: a person checks it.
	}
	public function incoming( $wallet, $from_pos ) {
		$r = $this->api()->address_payments( $wallet, $from_pos );
		if ( is_wp_error( $r ) ) {
			$this->unconfirmed = array();
			return $r;
		}
		$this->unconfirmed = $r['unconfirmed'];
		return $r['confirmed'];
	}
	public function last_unconfirmed() {
		return $this->unconfirmed;
	}
	public function tx_transfer( $txid, $wallet ) {
		return $this->api()->get_tx_transfer( $txid, $wallet );
	}
	public function confirm_time_text() {
		return 'Bitcoin needs 1 confirmation, usually about 10 minutes';
	}
	public function checkout_description() {
		return 'Pay with Bitcoin straight to the store\'s wallet. You will see the exact amount and a QR code on the next page.';
	}
}

class CDC_Chains {

	private static $all = null;

	/** @return CDC_Chain[] keyed by gateway id */
	public static function all() {
		if ( null === self::$all ) {
			self::$all = array();
			foreach ( array( new CDC_Chain_TRC20(), new CDC_Chain_ERC20(), new CDC_Chain_BTC() ) as $c ) {
				self::$all[ $c->gateway_id() ] = $c;
			}
		}
		return self::$all;
	}

	/** @return CDC_Chain|null */
	public static function for_gateway( $gateway_id ) {
		$all = self::all();
		return isset( $all[ $gateway_id ] ) ? $all[ $gateway_id ] : null;
	}

	/** @return CDC_Chain|null */
	public static function for_order( $order ) {
		return $order instanceof WC_Order ? self::for_gateway( $order->get_payment_method() ) : null;
	}

	public static function gateway_ids() {
		return array_keys( self::all() );
	}
}
