<?php
/**
 * Plugin Name:          Crypto Direct Checkout
 * Description:          Accept USDT (Tron, Ethereum) and Bitcoin straight into your own wallet. No middleman holds the money. Payments are detected automatically.
 * Version:              1.3.6
 * Author:               Banesto
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Update URI:           https://github.com/baetonjayman/crypto-direct-checkout
 * Text Domain:          crypto-direct-checkout
 * License:              GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CDC_VERSION', '1.3.6' );
define( 'CDC_FILE', __FILE__ );
define( 'CDC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CDC_URL', plugin_dir_url( __FILE__ ) );

require_once CDC_PATH . 'includes/class-cdc-tron-address.php';
require_once CDC_PATH . 'includes/class-cdc-matcher.php';
require_once CDC_PATH . 'includes/class-cdc-tron-api.php';
require_once CDC_PATH . 'includes/class-cdc-eth-address.php';
require_once CDC_PATH . 'includes/class-cdc-eth-rpc.php';
require_once CDC_PATH . 'includes/class-cdc-btc-address.php';
require_once CDC_PATH . 'includes/class-cdc-btc-api.php';
require_once CDC_PATH . 'includes/class-cdc-chains.php';
require_once CDC_PATH . 'includes/class-cdc-payments.php';
require_once CDC_PATH . 'includes/class-cdc-updater.php';

CDC_Updater::init();

register_deactivation_hook( __FILE__, array( 'CDC_Payments', 'clear_cron' ) );

// Tell WooCommerce this plugin works with its newer order storage and block checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Crypto Direct Checkout</strong> needs WooCommerce. Please install and activate WooCommerce first.</p></div>';
				}
			);
			return;
		}

		require_once CDC_PATH . 'includes/class-cdc-gateway.php';
		require_once CDC_PATH . 'includes/class-cdc-pay-page.php';
		require_once CDC_PATH . 'includes/class-cdc-admin.php';

		CDC_Payments::init();
		CDC_Admin::init();

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'CDC_Gateway';
				$gateways[] = 'CDC_Gateway_ERC20';
				$gateways[] = 'CDC_Gateway_BTC';
				return $gateways;
			}
		);

		// Block checkout support.
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}
				require_once CDC_PATH . 'includes/class-cdc-blocks.php';
				foreach ( CDC_Chains::gateway_ids() as $gateway_id ) {
					$registry->register( new CDC_Blocks( $gateway_id ) );
				}
			}
		);
	}
);

// "Settings" link on the Plugins page.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}
);
