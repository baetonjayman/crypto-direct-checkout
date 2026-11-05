<?php
/**
 * Makes each crypto payment option appear in WooCommerce's newer block
 * checkout (the default checkout on new stores).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CDC_Blocks extends AbstractPaymentMethodType {

	public function __construct( $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ]->is_available();
	}

	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( 'cdc-blocks', 'registered' ) ) {
			wp_register_script(
				'cdc-blocks',
				CDC_URL . 'assets/js/blocks.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
				CDC_VERSION,
				true
			);
			wp_add_inline_script( 'cdc-blocks', 'window.cdcMethods = ' . wp_json_encode( CDC_Chains::gateway_ids() ) . ';', 'before' );
		}
		return array( 'cdc-blocks' );
	}

	public function get_payment_method_data() {
		$chain = CDC_Chains::for_gateway( $this->name );
		return array(
			'title'       => $this->get_setting( 'title', 'Pay with ' . ( $chain ? $chain->label() : 'crypto' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'supports'    => array( 'products' ),
		);
	}
}
