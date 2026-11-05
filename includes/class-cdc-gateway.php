<?php
/**
 * Payment methods WooCommerce lists under Settings → Payments.
 * One per coin/network, all sharing this base class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CDC_Gateway_Base extends WC_Payment_Gateway {

	/** @var CDC_Chain */
	protected $chain;

	abstract protected function chain_fields();

	abstract protected function wallet_help();

	public function __construct() {
		$this->id                 = $this->chain->gateway_id();
		$this->method_title       = 'Crypto Direct Checkout — ' . $this->chain->label();
		$this->method_description = 'Customers pay with ' . $this->chain->label() . ' straight into your own wallet. No company holds the money in between. Payments are detected automatically.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( 'CDC_Pay_Page', 'render' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array_merge(
			array(
				'enabled'        => array(
					'title'   => 'Enable',
					'type'    => 'checkbox',
					'label'   => 'Turn on ' . $this->chain->label() . ' payments',
					'default' => 'no',
				),
				'wallet_address' => array(
					'title'             => 'Your ' . $this->chain->label() . ' wallet address',
					'type'              => 'text',
					'description'       => $this->wallet_help(),
					'default'           => '',
					'custom_attributes' => array(
						'autocomplete' => 'off',
						'spellcheck'   => 'false',
					),
				),
			),
			array(
				'payment_window' => array(
					'title'             => 'Minutes to pay',
					'type'              => 'number',
					'description'       => 'How long the customer has to send the payment (10–180). Payments that arrive late, within 24 hours, still show up as "On hold".',
					'default'           => 30,
					'custom_attributes' => array(
						'min'  => 10,
						'max'  => 180,
						'step' => 5,
					),
				),
				'title'          => array(
					'title'       => 'Name at checkout',
					'type'        => 'text',
					'description' => 'What customers see in the list of payment options.',
					'default'     => 'Pay with ' . $this->chain->label(),
					'desc_tip'    => true,
				),
				'description'    => array(
					'title'       => 'Short note at checkout',
					'type'        => 'textarea',
					'description' => 'Shown under the payment option.',
					'default'     => $this->chain->checkout_description(),
					'desc_tip'    => true,
				),
				'advanced'       => array(
					'title'       => 'Advanced (optional)',
					'type'        => 'title',
					'description' => 'Leave this empty — it works without it. Only fill it in if a "blockchain check failed" message appears above.',
				),
			),
			$this->chain_fields()
		);
	}

	/**
	 * WooCommerce calls this automatically when the wallet field is saved.
	 * A wrong address is rejected and the previous one is kept.
	 */
	public function validate_wallet_address_field( $key, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! $this->chain->is_valid_address( $value ) ) {
			WC_Admin_Settings::add_error( $this->invalid_wallet_message( $value ) );
			return $this->get_option( 'wallet_address' );
		}
		return $value;
	}

	protected function invalid_wallet_message( $value ) {
		return 'That is not a valid ' . $this->chain->network() . ' wallet address. Nothing was changed — please copy it again from your wallet app.';
	}

	public function validate_payment_window_field( $key, $value ) {
		return (string) max( 10, min( 180, (int) $value ) );
	}

	public function admin_options() {
		foreach ( $this->setup_problems() as $p ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $p ) . '</p></div>';
		}
		$error = get_option( 'cdc_last_error_' . $this->chain->id() );
		if ( $error ) {
			echo '<div class="notice notice-error inline"><p><strong>Last blockchain check failed:</strong> ' . esc_html( $error ) . '. Payments will be checked again automatically. If this keeps happening, fill in the Advanced field at the bottom of this page.</p></div>';
		}
		parent::admin_options();
	}

	/** Reasons customers can't see this payment option yet. */
	public function setup_problems() {
		$problems = array();
		if ( ! $this->chain->is_valid_address( $this->get_option( 'wallet_address' ) ) ) {
			$problems[] = 'Add your wallet address below — ' . $this->chain->label() . ' payments stay hidden from customers until you do.';
		}
		if ( ! $this->chain->supports_currency( get_woocommerce_currency() ) ) {
			$problems[] = $this->chain->currency_problem( get_woocommerce_currency() );
		}
		return $problems;
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled || ! empty( $this->setup_problems() ) ) {
			return false;
		}
		return parent::is_available();
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( 'Order not found. Please try again.', 'error' );
			return array( 'result' => 'failure' );
		}

		// Already set up and still running (e.g. the customer pressed "Pay" again): never change the amount.
		if ( CDC_Payments::still_valid( $order, $this->chain ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Stop bots from filling up all unique amounts with fake orders.
		if ( ! CDC_Payments::allow_new_order_from_ip() ) {
			wc_add_notice( 'You have several unpaid crypto orders already. Please pay or wait for them to expire before starting another.', 'error' );
			return array( 'result' => 'failure' );
		}

		$result = CDC_Payments::setup_order( $order, $this->chain );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'pending' !== $order->get_status() ) {
			$order->update_status( 'pending' );
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}

/** USDT on Tron. Keeps the original id so existing settings carry over. */
class CDC_Gateway extends CDC_Gateway_Base {

	public function __construct() {
		$this->chain = CDC_Chains::for_gateway( 'crypto_direct_checkout' );
		parent::__construct();
	}

	protected function wallet_help() {
		return 'Customers pay straight into this address. It starts with "T" and is 34 characters long. Copy it from your wallet app — never type it by hand.';
	}

	protected function chain_fields() {
		return array(
			'trongrid_api_key' => array(
				'title'       => 'TronGrid API key',
				'type'        => 'password',
				'description' => 'Optional. A free key from trongrid.io helps busy stores. It is only used to READ the blockchain.',
				'default'     => '',
			),
		);
	}
}

/** USDT on Ethereum. */
class CDC_Gateway_ERC20 extends CDC_Gateway_Base {

	public function __construct() {
		$this->chain = CDC_Chains::for_gateway( 'cdc_usdt_erc20' );
		parent::__construct();
	}

	protected function wallet_help() {
		return 'Customers pay straight into this address. It starts with "0x" and is 42 characters long. Paste it exactly as your wallet shows it — the mix of capital letters is a built-in typo check.';
	}

	protected function invalid_wallet_message( $value ) {
		if ( CDC_Eth_Address::lacks_checksum( $value ) ) {
			return 'That address has no capital letters, so its built-in typo check can\'t be verified. Nothing was changed — copy it again from your wallet app (MetaMask, Trust Wallet and others show the version with capital letters).';
		}
		return parent::invalid_wallet_message( $value );
	}

	public function validate_rpc_url_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, 'https://' ) || ! wp_http_validate_url( $value ) ) {
			WC_Admin_Settings::add_error( 'The Ethereum node URL must be a secure https:// address. Nothing was changed.' );
			return $this->get_option( 'rpc_url' );
		}
		return esc_url_raw( $value );
	}

	protected function chain_fields() {
		return array(
			'rpc_url' => array(
				'title'       => 'Ethereum node URL',
				'type'        => 'password',
				'description' => 'Optional. Leave empty to use a free public node. Busy stores can paste a free Alchemy or Infura URL (only used to READ the blockchain).',
				'default'     => '',
				'placeholder' => CDC_Eth_RPC::DEFAULT_URL,
			),
		);
	}
}

/** Bitcoin. */
class CDC_Gateway_BTC extends CDC_Gateway_Base {

	public function __construct() {
		$this->chain = CDC_Chains::for_gateway( 'cdc_btc' );
		parent::__construct();
	}

	protected function wallet_help() {
		return 'Customers pay straight into this Bitcoin address (starts with bc1, 1 or 3). Copy it from your wallet app. Note: every order uses this same address, so anyone who sees one payment can look up your others on the blockchain.';
	}

	public function validate_api_url_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, 'https://' ) || ! wp_http_validate_url( $value ) ) {
			WC_Admin_Settings::add_error( 'The Bitcoin data URL must be a secure https:// address. Nothing was changed.' );
			return $this->get_option( 'api_url' );
		}
		return esc_url_raw( untrailingslashit( $value ) );
	}

	protected function chain_fields() {
		return array(
			'api_url' => array(
				'title'       => 'Bitcoin data URL',
				'type'        => 'text',
				'description' => 'Optional. Leave empty to use mempool.space. If you run your own mempool server, paste its API address (ending in /api).',
				'default'     => '',
				'placeholder' => CDC_Btc_API::DEFAULT_URL,
			),
		);
	}
}
