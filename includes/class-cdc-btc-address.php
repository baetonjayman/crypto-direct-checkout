<?php
/**
 * Bitcoin address checks.
 *
 * Supports all common mainnet address styles, each with its built-in typo check:
 *   bc1q… (native SegWit, bech32), bc1p… (Taproot, bech32m),
 *   1…    (legacy, Base58Check), 3… (script/nested SegWit, Base58Check).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Btc_Address {

	const CHARSET  = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	const BECH32   = 1;
	const BECH32M  = 0x2bc830a3;

	public static function is_valid( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return false;
		}
		$lower = strtolower( $address );
		if ( 0 === strpos( $lower, 'bc1' ) ) {
			return self::valid_segwit( $address );
		}
		if ( '1' === $address[0] || '3' === $address[0] ) {
			return self::valid_base58( $address );
		}
		return false;
	}

	/** Form used for comparing: bech32 addresses are case-insensitive. */
	public static function normalize( $address ) {
		$address = trim( (string) $address );
		return 0 === strpos( strtolower( $address ), 'bc1' ) ? strtolower( $address ) : $address;
	}

	private static function valid_base58( $address ) {
		$len = strlen( $address );
		if ( $len < 26 || $len > 35 ) {
			return false;
		}
		$bytes = CDC_Tron_Address::base58_decode( $address );
		if ( null === $bytes || 25 !== strlen( $bytes ) ) {
			return false;
		}
		$version = ord( $bytes[0] );
		if ( ( '1' === $address[0] && 0x00 !== $version ) || ( '3' === $address[0] && 0x05 !== $version ) ) {
			return false;
		}
		$payload = substr( $bytes, 0, 21 );
		$check   = substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 );
		return hash_equals( $check, substr( $bytes, 21, 4 ) );
	}

	private static function polymod( array $values ) {
		$gen = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
		$chk = 1;
		foreach ( $values as $v ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $v;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= $gen[ $i ];
				}
			}
		}
		return $chk;
	}

	private static function hrp_expand( $hrp ) {
		$out = array();
		$len = strlen( $hrp );
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) >> 5;
		}
		$out[] = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) & 31;
		}
		return $out;
	}

	private static function valid_segwit( $address ) {
		// Mixed case is not allowed in bech32.
		if ( strtolower( $address ) !== $address && strtoupper( $address ) !== $address ) {
			return false;
		}
		$address = strtolower( $address );
		$len     = strlen( $address );
		if ( $len < 14 || $len > 90 ) {
			return false;
		}
		$pos = strrpos( $address, '1' );
		if ( false === $pos || 'bc' !== substr( $address, 0, $pos ) || $pos + 7 > $len ) {
			return false;
		}
		$data = array();
		for ( $i = $pos + 1; $i < $len; $i++ ) {
			$v = strpos( self::CHARSET, $address[ $i ] );
			if ( false === $v ) {
				return false;
			}
			$data[] = $v;
		}
		$const = self::polymod( array_merge( self::hrp_expand( 'bc' ), $data ) );
		$words = array_slice( $data, 0, -6 );
		if ( empty( $words ) ) {
			return false;
		}
		$version = $words[0];
		if ( $version > 16 ) {
			return false;
		}
		// Version 0 must use bech32, version 1+ must use bech32m (BIP-350).
		if ( ( 0 === $version && self::BECH32 !== $const ) || ( $version > 0 && self::BECH32M !== $const ) ) {
			return false;
		}
		$program = self::convert_bits( array_slice( $words, 1 ), 5, 8, false );
		if ( null === $program ) {
			return false;
		}
		$plen = count( $program );
		if ( $plen < 2 || $plen > 40 ) {
			return false;
		}
		if ( 0 === $version && 20 !== $plen && 32 !== $plen ) {
			return false;
		}
		return true;
	}

	private static function convert_bits( array $data, $from, $to, $pad ) {
		$acc    = 0;
		$bits   = 0;
		$ret    = array();
		$maxv   = ( 1 << $to ) - 1;
		foreach ( $data as $value ) {
			if ( $value < 0 || ( $value >> $from ) ) {
				return null;
			}
			$acc   = ( $acc << $from ) | $value;
			$bits += $from;
			while ( $bits >= $to ) {
				$bits -= $to;
				$ret[] = ( $acc >> $bits ) & $maxv;
			}
		}
		if ( $pad ) {
			if ( $bits ) {
				$ret[] = ( $acc << ( $to - $bits ) ) & $maxv;
			}
		} elseif ( $bits >= $from || ( ( $acc << ( $to - $bits ) ) & $maxv ) ) {
			return null;
		}
		return $ret;
	}
}
