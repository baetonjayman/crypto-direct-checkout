<?php
/**
 * Ethereum (ERC20) wallet address helpers.
 *
 * Ethereum addresses hide a typo check in their capital letters (EIP-55).
 * Checking it needs the Keccak-256 hash, which PHP doesn't include, so a
 * small pure-PHP version is here. A typo in the store's wallet would lose
 * every payment, so we refuse any address whose check fails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Eth_Address {

	/**
	 * Valid only with correct EIP-55 capital letters.
	 */
	public static function is_valid( $address ) {
		$address = trim( (string) $address );
		if ( 1 !== preg_match( '/^0x[0-9a-fA-F]{40}$/', $address ) ) {
			return false;
		}
		return self::checksum( $address ) === $address;
	}

	/**
	 * True when the address is all lowercase/uppercase, so its typo check can't be verified.
	 */
	public static function lacks_checksum( $address ) {
		$hex = substr( trim( (string) $address ), 2 );
		return 1 === preg_match( '/^0x[0-9a-fA-F]{40}$/', trim( (string) $address ) ) && ( strtolower( $hex ) === $hex || strtoupper( $hex ) === $hex );
	}

	/** The correctly capitalised (EIP-55) form. */
	public static function checksum( $address ) {
		$hex  = strtolower( substr( trim( (string) $address ), 2 ) );
		$hash = self::keccak256( $hex );
		$out  = '0x';
		for ( $i = 0; $i < 40; $i++ ) {
			$c    = $hex[ $i ];
			$out .= ( ctype_alpha( $c ) && hexdec( $hash[ $i ] ) >= 8 ) ? strtoupper( $c ) : $c;
		}
		return $out;
	}

	/** Lowercase 40-hex form used inside transfer logs. */
	public static function to_hex20( $address ) {
		return strtolower( substr( trim( (string) $address ), 2 ) );
	}

	/* ---------- Keccak-256 (the original Keccak padding used by Ethereum) ---------- */

	private static $rc = null;

	private static function rc() {
		if ( null === self::$rc ) {
			$hex = array(
				'0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000',
				'000000000000808b', '0000000080000001', '8000000080008081', '8000000000008009',
				'000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
				'000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003',
				'8000000000008002', '8000000000000080', '000000000000800a', '800000008000000a',
				'8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
			);
			self::$rc = array();
			foreach ( $hex as $h ) {
				self::$rc[] = unpack( 'J', hex2bin( $h ) )[1];
			}
		}
		return self::$rc;
	}

	private static function rotl( $x, $n ) {
		if ( 0 === $n ) {
			return $x;
		}
		return ( $x << $n ) | ( ( $x >> ( 64 - $n ) ) & ( ( 1 << $n ) - 1 ) );
	}

	private static function keccak_f( array &$s ) {
		static $rot = array(
			0, 1, 62, 28, 27,
			36, 44, 6, 55, 20,
			3, 10, 43, 25, 39,
			41, 45, 15, 21, 8,
			18, 2, 61, 56, 14,
		);
		$rc = self::rc();
		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			$c = array();
			for ( $x = 0; $x < 5; $x++ ) {
				$c[ $x ] = $s[ $x ] ^ $s[ $x + 5 ] ^ $s[ $x + 10 ] ^ $s[ $x + 15 ] ^ $s[ $x + 20 ];
			}
			for ( $x = 0; $x < 5; $x++ ) {
				$d = $c[ ( $x + 4 ) % 5 ] ^ self::rotl( $c[ ( $x + 1 ) % 5 ], 1 );
				for ( $y = 0; $y < 25; $y += 5 ) {
					$s[ $y + $x ] ^= $d;
				}
			}
			// Rho and Pi.
			$b = array();
			for ( $x = 0; $x < 5; $x++ ) {
				for ( $y = 0; $y < 5; $y++ ) {
					$b[ $y + 5 * ( ( 2 * $x + 3 * $y ) % 5 ) ] = self::rotl( $s[ $x + 5 * $y ], $rot[ $x + 5 * $y ] );
				}
			}
			// Chi.
			for ( $y = 0; $y < 25; $y += 5 ) {
				for ( $x = 0; $x < 5; $x++ ) {
					$s[ $y + $x ] = $b[ $y + $x ] ^ ( ( ~$b[ $y + ( $x + 1 ) % 5 ] ) & $b[ $y + ( $x + 2 ) % 5 ] );
				}
			}
			// Iota.
			$s[0] ^= $rc[ $round ];
		}
	}

	/** Keccak-256 of a string, as 64 lowercase hex characters. */
	public static function keccak256( $msg ) {
		$rate = 136;
		$msg  = (string) $msg;
		$msg .= "\x01";
		$msg .= str_repeat( "\x00", ( $rate - strlen( $msg ) % $rate ) % $rate );
		$msg[ strlen( $msg ) - 1 ] = chr( ord( $msg[ strlen( $msg ) - 1 ] ) | 0x80 );

		$s = array_fill( 0, 25, 0 );
		foreach ( str_split( $msg, $rate ) as $block ) {
			for ( $i = 0; $i < 17; $i++ ) {
				$s[ $i ] ^= unpack( 'P', substr( $block, $i * 8, 8 ) )[1];
			}
			self::keccak_f( $s );
		}

		$out = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$out .= pack( 'P', $s[ $i ] );
		}
		return bin2hex( $out );
	}
}
