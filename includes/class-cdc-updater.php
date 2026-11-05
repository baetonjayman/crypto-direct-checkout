<?php
/**
 * Automatic updates from GitHub, protected by a digital signature.
 *
 * 1. WordPress asks the "Update URI" host (github.com) for news about this plugin.
 * 2. We read update.json from the public repo: version, download link, SHA-256, signature.
 * 3. Before WordPress installs anything, we download the zip ourselves and check that
 *    its SHA-256 matches AND that the signature was made with the owner's private key
 *    (kept offline, never on GitHub). Any mismatch blocks the update.
 *
 * So even someone who takes over the GitHub account cannot push code to stores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Updater {

	const SLUG         = 'crypto-direct-checkout';
	const REPO_URL     = 'https://github.com/baetonjayman/crypto-direct-checkout';
	const MANIFEST_URL = 'https://raw.githubusercontent.com/baetonjayman/crypto-direct-checkout/main/update/update.json';
	const PUBLIC_KEY   = 'r8DP0tQelfaRc/Chw98JkZI+CYds2aU7lMVnZkP3bRs=';
	const CACHE        = 'cdc_update_manifest';
	const ALLOWED_HOST = 'raw.githubusercontent.com';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	public static function basename() {
		return plugin_basename( CDC_FILE );
	}

	public static function clear_cache() {
		delete_transient( self::CACHE );
	}

	/* ---------- Reading update.json ---------- */

	/**
	 * @return array|null Valid manifest, or null.
	 */
	public static function manifest( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}
		$response = wp_remote_get(
			add_query_arg( 't', time(), self::MANIFEST_URL ), // Skip stale caches.
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$data = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = self::validate( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}
		set_transient( self::CACHE, $data ? $data : array(), $data ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	/** Checks every field; anything odd = no update. */
	public static function validate( $m ) {
		if ( ! is_array( $m ) ) {
			return null;
		}
		foreach ( array( 'version', 'package', 'sha256', 'signature' ) as $k ) {
			if ( empty( $m[ $k ] ) || ! is_string( $m[ $k ] ) ) {
				return null;
			}
		}
		if ( 1 !== preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $m['version'] ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $m['sha256'] ) ) {
			return null;
		}
		$sig = base64_decode( $m['signature'], true );
		if ( false === $sig || 64 !== strlen( $sig ) ) {
			return null;
		}
		$parts = wp_parse_url( $m['package'] );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) || self::ALLOWED_HOST !== $parts['host'] ) {
			return null;
		}
		return array(
			'version'      => $m['version'],
			'package'      => $m['package'],
			'sha256'       => $m['sha256'],
			'signature'    => $m['signature'],
			'requires'     => isset( $m['requires'] ) ? (string) $m['requires'] : '6.0',
			'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
			'tested'       => isset( $m['tested'] ) ? (string) $m['tested'] : '',
			'changelog'    => isset( $m['changelog'] ) ? (string) $m['changelog'] : '',
		);
	}

	/** The exact text the owner signs: plugin|version|sha256. */
	public static function signed_message( $version, $sha256 ) {
		return self::SLUG . '|' . $version . '|' . $sha256;
	}

	public static function signature_ok( $version, $sha256, $signature_b64, $public_b64 = self::PUBLIC_KEY ) {
		$sig = base64_decode( (string) $signature_b64, true );
		$pk  = base64_decode( (string) $public_b64, true );
		if ( false === $sig || false === $pk || 64 !== strlen( $sig ) || 32 !== strlen( $pk ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, self::signed_message( $version, $sha256 ), $pk );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/* ---------- Telling WordPress about the update ---------- */

	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		// "Check again" on Dashboard → Updates should really check again.
		$force = is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification
		$m     = self::manifest( $force );
		if ( ! $m ) {
			return $update;
		}
		// The signature is checked again at download time; this early check just hides bogus updates.
		if ( ! self::signature_ok( $m['version'], $m['sha256'], $m['signature'] ) ) {
			return $update;
		}
		return array(
			'id'           => self::REPO_URL,
			'slug'         => self::SLUG,
			'version'      => $m['version'],
			'url'          => self::REPO_URL,
			'package'      => $m['package'],
			'tested'       => $m['tested'],
			'requires_php' => $m['requires_php'],
		);
	}

	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Crypto Direct Checkout',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => 'Banesto',
			'homepage'      => self::REPO_URL,
			'requires'      => $m['requires'],
			'requires_php'  => $m['requires_php'],
			'tested'        => $m['tested'],
			'download_link' => $m['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $m['changelog'] ) ),
			),
		);
	}

	/* ---------- The safety check before anything is installed ---------- */

	public static function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply ) {
			return $reply; // Another plugin already handled it.
		}
		$ours = ( isset( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'] );
		$m    = self::manifest( true );
		if ( ! $ours && ( ! $m || $package !== $m['package'] ) ) {
			return $reply; // Not our plugin.
		}
		if ( ! $m || $package !== $m['package'] ) {
			return new WP_Error( 'cdc_update_mismatch', 'Crypto Direct Checkout update blocked: the download link doesn\'t match the published update. Nothing was changed.' );
		}
		if ( version_compare( $m['version'], CDC_VERSION, '<=' ) ) {
			return new WP_Error( 'cdc_update_old', 'Crypto Direct Checkout update blocked: it is not newer than the installed version. Nothing was changed.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 60 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$hash = hash_file( 'sha256', $file );
		if ( ! $hash || ! hash_equals( $m['sha256'], $hash ) || ! self::signature_ok( $m['version'], $hash, $m['signature'] ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'cdc_bad_signature', 'Crypto Direct Checkout update blocked: the file is not signed by the plugin\'s author. Nothing was changed. Please contact the plugin author.' );
		}
		return $file;
	}
}
