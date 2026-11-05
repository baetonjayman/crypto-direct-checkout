<?php
/**
 * Pure logic for amounts and matching payments to orders.
 * No WordPress calls here, so it can be unit-tested on its own.
 *
 * Amounts are stored as whole "micro" units: 1 USDT = 1,000,000 micro.
 * This avoids rounding errors from decimal numbers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Matcher {

	const MICRO          = 1000000;
	const OFFSET_STEP    = 100;   // 0.0001 USDT.
	const OFFSET_SLOTS   = 99;    // Up to +0.0099 USDT.

	/**
	 * Store total (e.g. "25.00") to micro units, rounded to the cent.
	 */
	public static function total_to_micro( $total ) {
		$cents = (int) round( (float) $total * 100 );
		return $cents * 10000;
	}

	/**
	 * Picks a small unique extra amount so two orders with the same price
	 * never ask for the exact same USDT amount.
	 *
	 * @param int   $base_micro Order total in micro.
	 * @param int[] $taken      Amounts already requested by open orders.
	 * @return int|null The unique amount, or null if every slot is busy.
	 */
	public static function unique_amount( $base_micro, array $taken, $step = self::OFFSET_STEP, $slot_count = self::OFFSET_SLOTS ) {
		$taken = array_flip( array_map( 'intval', $taken ) );
		$slots = range( 1, $slot_count );
		shuffle( $slots );
		foreach ( $slots as $slot ) {
			$amount = $base_micro + $slot * $step;
			if ( ! isset( $taken[ $amount ] ) ) {
				return $amount;
			}
		}
		return null;
	}

	/**
	 * 25003700 -> "25.0037". Always at least 2 decimals.
	 */
	public static function format( $micro ) {
		$micro  = (int) $micro;
		$whole  = intdiv( $micro, self::MICRO );
		$frac   = str_pad( (string) ( $micro % self::MICRO ), 6, '0', STR_PAD_LEFT );
		$frac   = rtrim( $frac, '0' );
		if ( strlen( $frac ) < 2 ) {
			$frac = str_pad( $frac, 2, '0' );
		}
		return $whole . '.' . $frac;
	}

	/**
	 * Any coin: 1234 with 8 decimals -> "0.00001234". Keeps at least $min decimals.
	 */
	public static function format_units( $units, $decimals, $min = 2 ) {
		$units = (int) $units;
		$div   = (int) pow( 10, $decimals );
		$whole = intdiv( $units, $div );
		$frac  = rtrim( str_pad( (string) ( $units % $div ), $decimals, '0', STR_PAD_LEFT ), '0' );
		if ( strlen( $frac ) < $min ) {
			$frac = str_pad( $frac, $min, '0' );
		}
		return '' === $frac ? (string) $whole : $whole . '.' . $frac;
	}

	/**
	 * Rounded UP to the cent, for wallets that only allow 2 decimals.
	 */
	public static function round_up_cents( $micro ) {
		$cents = (int) ceil( $micro / 10000 );
		return $cents * 10000;
	}

	/**
	 * Decides whether a payment the customer points to (by transaction ID)
	 * can pay this order.
	 *
	 * Security: every order's exact amount has non-zero digits below the cent
	 * (e.g. 25.0037). So a payment with digits below the cent that is NOT this
	 * order's amount belongs to someone else's order — rejecting it stops a
	 * thief from claiming another customer's payment.
	 *
	 * @return string exact | review | too_low | mismatch
	 */
	public static function txid_decision( $value_micro, $expected_micro ) {
		$value_micro    = (int) $value_micro;
		$expected_micro = (int) $expected_micro;

		if ( $value_micro === $expected_micro ) {
			return 'exact';
		}
		if ( $value_micro < $expected_micro ) {
			return 'too_low';
		}
		if ( 0 === $value_micro % 10000 ) {
			// Whole cents (e.g. rounded up because the wallet only allows 2 decimals).
			// Can't be told apart from another customer's rounded payment, so a human checks it.
			return 'review';
		}
		return 'mismatch';
	}

	/**
	 * Matches blockchain transfers to waiting orders by exact amount.
	 *
	 * "Position" is when something happened on the chain: milliseconds on
	 * Tron, block number on Ethereum. A payment only counts for an order if
	 * it happened at or after the order's start position (minus $grace).
	 *
	 * @param array $orders    [ [ 'id', 'amount_micro', 'start' ], ... ]
	 * @param array $transfers [ [ 'txid', 'value_micro', 'pos' ], ... ]
	 * @param array $used      Transaction IDs already linked to an order.
	 * @param int   $grace     Allowed difference in position units.
	 * @return array order_id => transfer
	 */
	public static function match( array $orders, array $transfers, array $used, $grace = 0 ) {
		$used = array_flip( $used );

		usort(
			$transfers,
			function ( $a, $b ) {
				return $a['pos'] <=> $b['pos'];
			}
		);
		usort(
			$orders,
			function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);

		$result = array();
		foreach ( $transfers as $t ) {
			if ( isset( $used[ $t['txid'] ] ) ) {
				continue;
			}
			foreach ( $orders as $o ) {
				if ( isset( $result[ $o['id'] ] ) ) {
					continue;
				}
				if ( (int) $o['amount_micro'] !== (int) $t['value_micro'] ) {
					continue;
				}
				if ( $t['pos'] < $o['start'] - $grace ) {
					continue; // Payment happened before the order existed.
				}
				$result[ $o['id'] ]   = $t;
				$used[ $t['txid'] ]   = true;
				break;
			}
		}
		return $result;
	}
}
