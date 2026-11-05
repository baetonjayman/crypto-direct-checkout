<?php
/**
 * Tron (TRC20) wallet address helpers.
 *
 * A Tron address is Base58Check: 21 bytes (0x41 + 20 bytes) plus a 4-byte
 * checksum. If one character is wrong, the checksum fails, so a typo can
 * never be saved as the store's wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Tron_Address {

	const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	public static function is_valid( $address ) {
		return null !== self::decode( $address );
	}

	/**
	 * The 20-byte account id as 40 lowercase hex characters (no 41 prefix),
	 * the form used inside TRC20 transfer logs. Null if invalid.
	 */
	public static function to_hex20( $address ) {
		$payload = self::decode( $address );
		return null === $payload ? null : bin2hex( substr( $payload, 1 ) );
	}

	/**
	 * Returns the 21-byte payload, or null if the address is not valid.
	 */
	private static function decode( $address ) {
		$address = trim( (string) $address );

		if ( 34 !== strlen( $address ) || 'T' !== $address[0] ) {
			return null;
		}

		$bytes = self::base58_decode( $address );
		if ( null === $bytes || 25 !== strlen( $bytes ) ) {
			return null;
		}

		$payload  = substr( $bytes, 0, 21 );
		$checksum = substr( $bytes, 21, 4 );

		if ( "\x41" !== $payload[0] ) {
			return null;
		}

		$expected = substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 );

		return hash_equals( $expected, $checksum ) ? $payload : null;
	}

	/**
	 * Base58 decode using plain byte math (no GMP/BCMath needed on the host).
	 */
	public static function base58_decode( $input ) {
		$bytes = array();

		$len = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$value = strpos( self::ALPHABET, $input[ $i ] );
			if ( false === $value ) {
				return null;
			}
			$carry = $value;
			$count = count( $bytes );
			for ( $j = 0; $j < $count; $j++ ) {
				$carry      += $bytes[ $j ] * 58;
				$bytes[ $j ] = $carry & 0xff;
				$carry     >>= 8;
			}
			while ( $carry > 0 ) {
				$bytes[] = $carry & 0xff;
				$carry >>= 8;
			}
		}

		// Leading '1' characters are leading zero bytes.
		for ( $i = 0; $i < $len && '1' === $input[ $i ]; $i++ ) {
			$bytes[] = 0;
		}

		$out = '';
		foreach ( array_reverse( $bytes ) as $b ) {
			$out .= chr( $b );
		}
		return $out;
	}
}
