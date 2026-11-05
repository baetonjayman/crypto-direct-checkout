<?php
/**
 * Small "USDT payment" box on the order edit screen for the store owner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Admin {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
	}

	public static function add_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'cdc-payment', 'Crypto payment', array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		$chain = $order ? CDC_Chains::for_order( $order ) : null;
		if ( ! $chain ) {
			echo '<p>Not paid with crypto.</p>';
			return;
		}
		echo '<p><strong>Network:</strong> ' . esc_html( $chain->network() ) . '</p>';

		$expected = (int) $order->get_meta( '_cdc_amount_micro' );
		$received = (int) $order->get_meta( '_cdc_received_micro' );
		$txid     = (string) $order->get_meta( '_cdc_txid' );
		$wallet   = (string) $order->get_meta( '_cdc_wallet' );

		echo '<p><strong>Asked for:</strong> ' . esc_html( $chain->format( $expected ) . ' ' . $chain->symbol() ) . '</p>';
		$rate = (float) $order->get_meta( '_cdc_rate' );
		if ( $rate ) {
			echo '<p><strong>Locked price:</strong> 1 ' . esc_html( $chain->symbol() ) . ' = ' . esc_html( number_format( $rate, 2 ) . ' ' . $order->get_currency() ) . '</p>';
		}
		if ( $order->get_meta( '_cdc_seen' ) && ! $txid ) {
			echo '<p><strong>Payment seen</strong>, waiting for confirmation.</p>';
		}
		if ( $txid ) {
			echo '<p><strong>Received:</strong> ' . esc_html( $chain->format( $received ) . ' ' . $chain->symbol() ) . '</p>';
			echo '<p><a href="' . esc_url( $chain->explorer_tx_url( $txid ) ) . '" target="_blank" rel="noopener">View transaction</a></p>';
		} else {
			echo '<p><strong>Received:</strong> nothing yet</p>';
		}
		if ( $order->get_meta( '_cdc_review' ) ) {
			echo '<p style="color:#b45309"><strong>Check needed.</strong> Not the exact unique amount — confirm this payment is from this customer before shipping.</p>';
		}
		if ( $order->get_meta( '_cdc_late' ) ) {
			echo '<p style="color:#b45309"><strong>Paid late.</strong> Check stock before processing.</p>';
		}
		echo '<p style="word-break:break-all;color:#646970">To wallet: ' . esc_html( $wallet ) . '</p>';
	}
}
