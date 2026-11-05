<?php
/**
 * Connects blockchain checks to WooCommerce orders for every supported chain:
 * unique amounts, automatic detection, timer expiry, transaction-ID backup,
 * and the small web endpoints the payment page talks to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Payments {

	const CRON_HOOK    = 'cdc_check_payments';
	const LATE_WINDOW  = DAY_IN_SECONDS;
	const POLL_SECONDS = 15;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_all_checks' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_until_timer_ends' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'show_box_on_view_order' ), 5 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'received_text' ), 20, 2 );
		add_filter( 'woocommerce_endpoint_order-received_title', array( __CLASS__, 'received_title' ), 20 );
	}

	/** The crypto order on the order-received page, if any. */
	private static function current_received_order() {
		global $wp;
		$id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$o  = $id ? wc_get_order( $id ) : null;
		return ( $o && CDC_Chains::for_order( $o ) ) ? $o : null;
	}

	/** Like status_of(), but a waiting order whose timer has run out counts as expired. */
	public static function page_status( WC_Order $order ) {
		$status = self::status_of( $order );
		if ( 'waiting' === $status && (int) $order->get_meta( '_cdc_expires' ) < time() ) {
			return 'expired';
		}
		return $status;
	}

	/** Don't say "order received" before the money has arrived. */
	public static function received_text( $text, $order ) {
		if ( ! $order instanceof WC_Order || ! CDC_Chains::for_order( $order ) ) {
			return $text;
		}
		switch ( self::page_status( $order ) ) {
			case 'waiting':
				return 'Your order is reserved. Complete your payment below to confirm it.';
			case 'seen':
				return 'We can see your payment. Your order is confirmed as soon as the network confirms it.';
			case 'expired':
				return 'This order was not paid in time.';
			case 'late_paid':
				return 'Thank you. We received your payment — the store will confirm your order shortly.';
			default:
				return 'Thank you. Your payment was received and your order is confirmed.';
		}
	}

	public static function received_title( $title ) {
		$order = self::current_received_order();
		if ( ! $order ) {
			return $title;
		}
		$status = self::page_status( $order );
		if ( 'waiting' === $status || 'seen' === $status ) {
			return 'Complete your payment';
		}
		return 'expired' === $status ? 'Order not paid' : $title;
	}

	/**
	 * WooCommerce's own "Pay" button doesn't make sense for crypto: the payment box is how you pay.
	 * On the order-received page it's removed; elsewhere (My Account) it opens the payment box.
	 */
	public static function order_actions( $actions, $order ) {
		if ( ! isset( $actions['pay'] ) || ! CDC_Chains::for_order( $order ) ) {
			return $actions;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			unset( $actions['pay'] );
		} else {
			$actions['pay']['url'] = $order->get_checkout_order_received_url();
		}
		return $actions;
	}

	/** My Account → Orders → View also shows the payment box. */
	public static function show_box_on_view_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && CDC_Chains::for_order( $order ) ) {
			CDC_Pay_Page::render( $order_id );
		}
	}

	/** The order already has a running payment on this coin — keep its amount and timer. */
	public static function still_valid( WC_Order $order, CDC_Chain $chain ) {
		return $chain->id() === (string) $order->get_meta( '_cdc_chain' )
			&& 'pending' === $order->get_status()
			&& (int) $order->get_meta( '_cdc_amount_micro' ) > 0
			&& (int) $order->get_meta( '_cdc_expires' ) > time()
			&& $chain->same_address( $order->get_meta( '_cdc_wallet' ), $chain->wallet() );
	}

	/* ---------- Cron ---------- */

	public static function cron_schedule( $schedules ) {
		$schedules['cdc_every_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Crypto Direct Checkout)',
		);
		return $schedules;
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'cdc_every_minute', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function run_all_checks() {
		foreach ( CDC_Chains::all() as $chain ) {
			self::run_check( $chain );
		}
	}

	/**
	 * WooCommerce's "hold stock" cleanup must not cancel our orders while
	 * the customer's payment timer is still running.
	 */
	public static function keep_until_timer_ends( $cancel, $order ) {
		if ( CDC_Chains::for_order( $order ) ) {
			$expires = (int) $order->get_meta( '_cdc_expires' );
			if ( ( $expires && time() < $expires ) || $order->get_meta( '_cdc_seen' ) ) {
				return false;
			}
		}
		return $cancel;
	}

	/* ---------- New order ---------- */

	/**
	 * Gives a new order its unique USDT amount, start position and deadline.
	 *
	 * @return true|WP_Error
	 */
	public static function setup_order( WC_Order $order, CDC_Chain $chain ) {
		$wallet = $chain->wallet();
		if ( ! $chain->is_valid_address( $wallet ) ) {
			return new WP_Error( 'cdc_wallet', $chain->label() . ' payments are not set up on this store yet.' );
		}

		if ( ! $chain->supports_currency( $order->get_currency() ) ) {
			return new WP_Error( 'cdc_currency', $chain->label() . ' is not available for this currency.' );
		}
		$base = $chain->base_amount( $order );
		if ( is_wp_error( $base ) ) {
			return new WP_Error( 'cdc_price', 'We can\'t get the ' . $chain->symbol() . ' price right now. Please try again in a minute or choose another payment method.' );
		}
		if ( $base <= 0 ) {
			return new WP_Error( 'cdc_total', 'This order has no amount to pay.' );
		}

		$start = $chain->start_position();
		if ( is_wp_error( $start ) ) {
			return new WP_Error( 'cdc_chain', 'We can\'t reach the ' . $chain->network() . ' network right now. Please try again in a minute or choose another payment method.' );
		}

		// Also avoid amounts of recently cancelled orders, since a late payment can still arrive for them.
		$taken = array();
		foreach ( array_merge( self::open_orders( $chain ), self::recently_cancelled( $chain ) ) as $open ) {
			$taken[] = (int) $open->get_meta( '_cdc_amount_micro' );
		}
		$amount = CDC_Matcher::unique_amount( $base, $taken, $chain->unique_step(), $chain->unique_slots() );
		if ( null === $amount ) {
			return new WP_Error( 'cdc_busy', 'Too many people are paying this exact amount right now. Please try again in a few minutes.' );
		}

		$now = time();
		$order->update_meta_data( '_cdc_chain', $chain->id() );
		$order->update_meta_data( '_cdc_amount_micro', (string) $amount );
		$order->update_meta_data( '_cdc_wallet', $wallet );
		$order->update_meta_data( '_cdc_start_pos', (string) $start );
		$order->update_meta_data( '_cdc_created', (string) $now );
		$order->update_meta_data( '_cdc_expires', (string) ( $now + $chain->minutes() * MINUTE_IN_SECONDS ) );
		$rate = $chain->last_rate();
		if ( $rate ) {
			$order->update_meta_data( '_cdc_rate', (string) $rate );
		}
		$order->add_order_note(
			sprintf(
				'Waiting for %s %s to %s.%s',
				$chain->format( $amount ),
				$chain->symbol(),
				$wallet,
				$rate ? sprintf( ' Price locked at 1 %s = %s %s.', $chain->symbol(), number_format( $rate, 2, '.', '' ), $order->get_currency() ) : ''
			)
		);
		$order->save();

		return true;
	}

	/**
	 * At most 5 new crypto orders per visitor IP per hour (all chains together).
	 */
	public static function allow_new_order_from_ip() {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
		if ( '' === $ip ) {
			return true;
		}
		$key   = 'cdc_ip_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------- Finding orders ---------- */

	/** Pending orders still waiting for payment on this chain. */
	public static function open_orders( CDC_Chain $chain ) {
		return wc_get_orders(
			array(
				'payment_method' => $chain->gateway_id(),
				'status'         => array( 'pending' ),
				'limit'          => 200,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
	}

	/** Cancelled in the last 24h without payment — a late payment can still arrive. */
	public static function recently_cancelled( CDC_Chain $chain ) {
		$orders = wc_get_orders(
			array(
				'payment_method' => $chain->gateway_id(),
				'status'         => array( 'cancelled' ),
				'date_modified'  => '>' . ( time() - self::LATE_WINDOW ),
				'limit'          => 200,
			)
		);
		return array_values(
			array_filter(
				$orders,
				function ( $o ) {
					return '' === (string) $o->get_meta( '_cdc_txid' ) && '' !== (string) $o->get_meta( '_cdc_amount_micro' );
				}
			)
		);
	}

	/* ---------- The automatic check ---------- */

	/**
	 * Runs every minute (and when a customer is watching the payment page).
	 * Expires overdue orders, then looks for matching payments on the blockchain.
	 */
	public static function run_check( CDC_Chain $chain ) {
		if ( get_transient( 'cdc_backoff_' . $chain->id() ) ) {
			return; // The blockchain service asked us to slow down.
		}
		$wallet = $chain->wallet();
		if ( ! $chain->is_valid_address( $wallet ) ) {
			return;
		}
		if ( ! self::lock( $chain ) ) {
			return;
		}
		try {
			$now     = time();
			$open    = self::open_orders( $chain );
			$waiting = array_merge( $open, self::recently_cancelled( $chain ) );
			if ( empty( $waiting ) ) {
				return;
			}

			$rows  = array();
			$by_id = array();
			$min   = PHP_INT_MAX;
			foreach ( $waiting as $order ) {
				if ( ! $chain->same_address( $order->get_meta( '_cdc_wallet' ), $wallet ) ) {
					continue; // Wallet was changed since this order — never match it to the new one.
				}
				$start  = (int) $order->get_meta( '_cdc_start_pos' );
				$rows[] = array(
					'id'           => $order->get_id(),
					'amount_micro' => (int) $order->get_meta( '_cdc_amount_micro' ),
					'start'        => $start,
				);
				$by_id[ $order->get_id() ] = $order;
				$min                       = min( $min, $start );
			}

			// 1. Look at the blockchain FIRST, so a payment sent in the last seconds still counts.
			$transfers = empty( $rows ) ? array() : $chain->incoming( $wallet, $min );
			$api_ok    = ! is_wp_error( $transfers );
			if ( ! $api_ok ) {
				if ( 'cdc_rate_limited' === $transfers->get_error_code() ) {
					set_transient( 'cdc_backoff_' . $chain->id(), 1, 90 );
				}
				update_option( 'cdc_last_error_' . $chain->id(), $transfers->get_error_message() . ' (' . gmdate( 'Y-m-d H:i' ) . ' UTC)', false );
			} else {
				delete_option( 'cdc_last_error_' . $chain->id() );
				self::mark_seen( $chain, $open, $chain->last_unconfirmed() );
			}

			// 2. Then expire orders whose timer ran out and whose payment isn't on its way.
			foreach ( $open as $order ) {
				$seen    = '' !== (string) $order->get_meta( '_cdc_seen' );
				$created = (int) $order->get_meta( '_cdc_created' );
				if ( $seen && $now - $created < self::LATE_WINDOW ) {
					continue; // Payment is on its way; wait for confirmation.
				}
				$expires = (int) $order->get_meta( '_cdc_expires' );
				if ( $expires && $now > $expires ) {
					$order->update_meta_data( '_cdc_expired', '1' );
					$order->update_status( 'cancelled', 'Crypto payment timer ran out. If the customer still pays within 24 hours, the order moves to "On hold" automatically.' );
				}
			}

			// 3. Confirmed payments pay their orders (a just-expired one becomes "On hold — paid late").
			if ( $api_ok && ! empty( $rows ) ) {
				$matches = CDC_Matcher::match( $rows, $transfers, self::used_txids( $chain ), $chain->position_grace() );
				foreach ( $matches as $order_id => $t ) {
					if ( self::txid_taken( $chain, $t['txid'] ) ) {
						continue;
					}
					self::apply_payment( $chain, $by_id[ $order_id ], $t['txid'], $t['value_micro'], 'found automatically' );
				}
			}
		} finally {
			self::unlock( $chain );
		}
	}

	/**
	 * A payment with an order's exact amount is on the network but not confirmed yet:
	 * tell the customer, and stop the timer from cancelling the order.
	 */
	private static function mark_seen( CDC_Chain $chain, array $pending, array $unconfirmed ) {
		if ( empty( $unconfirmed ) ) {
			return;
		}
		$used = array_flip( self::used_txids( $chain ) );
		foreach ( $pending as $order ) {
			if ( '' !== (string) $order->get_meta( '_cdc_seen' ) ) {
				continue;
			}
			foreach ( $unconfirmed as $u ) {
				if ( isset( $used[ $u['txid'] ] ) || (int) $u['value_micro'] !== (int) $order->get_meta( '_cdc_amount_micro' ) ) {
					continue;
				}
				$order->update_meta_data( '_cdc_seen', $u['txid'] );
				$order->save();
				$order->add_order_note( sprintf( 'Payment of %s %s seen on the network, waiting for confirmation. Transaction: %s', $chain->format( $u['value_micro'] ), $chain->symbol(), esc_html( $u['txid'] ) ) );
				$used[ $u['txid'] ] = true;
				break;
			}
		}
	}

	/* ---------- Backup: customer pastes their transaction ID ---------- */

	/**
	 * @return array [ 'ok' => bool, 'message' => string ]
	 */
	public static function verify_txid( WC_Order $order, $raw_txid ) {
		$chain = CDC_Chains::for_order( $order );
		if ( ! $chain ) {
			return self::reply( false, 'This order was not paid with crypto.' );
		}
		$txid = $chain->normalize_txid( $raw_txid );
		if ( null === $txid ) {
			return self::reply( false, 'That isn\'t a valid transaction ID for ' . $chain->network() . '. Copy it from your wallet\'s transaction details.' );
		}
		if ( self::is_paid( $order ) ) {
			return self::reply( true, 'This order is already paid.' );
		}
		if ( ! self::can_still_receive( $order ) ) {
			return self::reply( false, 'This order can no longer receive a payment. Please contact the store.' );
		}
		if ( in_array( $txid, self::used_txids( $chain ), true ) ) {
			return self::reply( false, 'This transaction was already used for another order.' );
		}

		$result = $chain->tx_transfer( $txid, $order->get_meta( '_cdc_wallet' ) );
		if ( is_wp_error( $result ) ) {
			$messages = array(
				'cdc_not_confirmed_yet'   => 'We can\'t see this transaction confirmed yet. ' . $chain->confirm_time_text() . ' — please try again shortly.',
				'cdc_needs_confirmations' => 'Payment found, but it isn\'t confirmed yet. ' . $chain->confirm_time_text() . ' — please try again shortly.',
				'cdc_failed'              => 'This transaction failed on the blockchain, so no money arrived. Please check your wallet.',
				'cdc_not_usdt'            => 'This transaction is not a ' . $chain->label() . ' payment.',
				'cdc_wrong_wallet'        => 'This transaction did not send ' . $chain->symbol() . ' to this store\'s wallet address.',
			);
			$code = $result->get_error_code();
			return self::reply( false, isset( $messages[ $code ] ) ? $messages[ $code ] : 'We couldn\'t reach the blockchain just now. Please try again in a minute.' );
		}

		$start = (int) $order->get_meta( '_cdc_start_pos' );
		if ( $result['pos'] < $start - $chain->position_grace() ) {
			return self::reply( false, 'This transaction happened before the order was placed, so it can\'t pay for it.' );
		}

		$expected = (int) $order->get_meta( '_cdc_amount_micro' );
		$others   = array();
		foreach ( array_merge( self::open_orders( $chain ), self::recently_cancelled( $chain ) ) as $other ) {
			if ( $other->get_id() !== $order->get_id() ) {
				$others[] = (int) $other->get_meta( '_cdc_amount_micro' );
			}
		}
		$decision = $chain->txid_decision( $result['value_micro'], $expected, $others );
		if ( 'too_low' === $decision ) {
			return self::reply(
				false,
				sprintf(
					'This transaction sent %1$s %3$s, but the order needs %2$s %3$s. Please contact the store about the difference.',
					$chain->format( $result['value_micro'] ),
					$chain->format( $expected ),
					$chain->symbol()
				)
			);
		}
		if ( 'mismatch' === $decision ) {
			return self::reply( false, 'The amount in this transaction doesn\'t match this order. If you sent it for this order, please contact the store.' );
		}

		if ( ! self::lock( $chain ) ) {
			return self::reply( false, 'Busy checking payments — please try again in a few seconds.' );
		}
		try {
			$order = wc_get_order( $order->get_id() ); // Fresh copy after locking.
			if ( self::is_paid( $order ) ) {
				return self::reply( true, 'This order is already paid.' );
			}
			if ( self::txid_taken( $chain, $txid ) ) {
				return self::reply( false, 'This transaction was already used for another order.' );
			}
			self::apply_payment( $chain, $order, $txid, $result['value_micro'], 'from the transaction ID the customer entered', 'review' === $decision );
		} finally {
			self::unlock( $chain );
		}

		return 'review' === $decision
			? self::reply( true, 'Payment found. The store will confirm it shortly.' )
			: self::reply( true, 'Payment confirmed. Thank you!' );
	}

	/**
	 * Double check: the quick list AND the orders themselves.
	 */
	private static function txid_taken( CDC_Chain $chain, $txid ) {
		if ( in_array( $txid, self::used_txids( $chain ), true ) ) {
			return true;
		}
		$found = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_cdc_txid',
						'value' => $txid,
					),
				),
			)
		);
		return ! empty( $found );
	}

	/* ---------- Marking an order paid ---------- */

	private static function apply_payment( CDC_Chain $chain, WC_Order $order, $txid, $value_micro, $how, $needs_review = false ) {
		self::remember_txid( $chain, $txid );

		$order->update_meta_data( '_cdc_txid', $txid );
		$order->update_meta_data( '_cdc_received_micro', (string) $value_micro );
		$order->set_transaction_id( $txid );
		$order->save();

		$note = sprintf(
			'Received %s %s (%s). Transaction: <a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_html( $chain->format( $value_micro ) ),
			esc_html( $chain->symbol() ),
			esc_html( $how ),
			esc_url( $chain->explorer_tx_url( $txid ) ),
			esc_html( $txid )
		);

		$late = 'cancelled' === $order->get_status();
		if ( $late || $needs_review ) {
			$reasons = array();
			if ( $late ) {
				$order->update_meta_data( '_cdc_late', '1' );
				$reasons[] = '<strong>Paid after the timer ran out.</strong> Check stock.';
			}
			if ( $needs_review ) {
				$order->update_meta_data( '_cdc_review', '1' );
				$reasons[] = '<strong>Not the exact unique amount</strong>, so this payment could belong to another customer. Before shipping, confirm with the customer (for example, the sending wallet address in the block explorer).';
			}
			$order->save();
			$order->update_status( 'on-hold', $note . '<br>' . implode( '<br>', $reasons ) . '<br>Then move the order to Processing.' );
			return;
		}

		$order->add_order_note( $note );
		$order->payment_complete( $txid );
	}

	public static function is_paid( WC_Order $order ) {
		return '' !== (string) $order->get_meta( '_cdc_txid' ) || $order->is_paid();
	}

	private static function can_still_receive( WC_Order $order ) {
		if ( 'pending' === $order->get_status() ) {
			return true;
		}
		if ( 'cancelled' === $order->get_status() ) {
			$modified = $order->get_date_modified();
			return $modified && ( time() - $modified->getTimestamp() ) < self::LATE_WINDOW;
		}
		return false;
	}

	/* ---------- Which transactions are already used ---------- */

	public static function used_txids( CDC_Chain $chain ) {
		$used = get_option( 'cdc_used_txids_' . $chain->id(), array() );
		return is_array( $used ) ? $used : array();
	}

	private static function remember_txid( CDC_Chain $chain, $txid ) {
		$used   = self::used_txids( $chain );
		$used[] = $txid;
		$used   = array_slice( array_values( array_unique( $used ) ), -5000 );
		update_option( 'cdc_used_txids_' . $chain->id(), $used, false );
	}

	/* ---------- Lock so two checks never run at once ---------- */

	private static function lock( CDC_Chain $chain ) {
		$key = 'cdc_check_lock_' . $chain->id();
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && time() - $since > 60 ) { // Stale lock from a crashed run.
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( CDC_Chain $chain ) {
		delete_option( 'cdc_check_lock_' . $chain->id() );
	}

	/* ---------- What the payment page asks ---------- */

	public static function status_of( WC_Order $order ) {
		$status = $order->get_status();
		if ( self::is_paid( $order ) ) {
			$held = $order->get_meta( '_cdc_late' ) || $order->get_meta( '_cdc_review' );
			return 'on-hold' === $status && $held ? 'late_paid' : 'paid';
		}
		if ( 'pending' === $status ) {
			return $order->get_meta( '_cdc_seen' ) ? 'seen' : 'waiting';
		}
		return 'expired';
	}

	public static function register_routes() {
		register_rest_route(
			'cdc/v1',
			'/order/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'cdc/v1',
			'/order/(?P<id>\d+)/txid',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_txid' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Only someone with the order's secret key (in the customer's own link)
	 * can see or act on the order.
	 */
	private static function order_from_request( WP_REST_Request $req ) {
		$order = wc_get_order( (int) $req['id'] );
		$key   = (string) $req->get_param( 'key' );
		if ( ! $order || ! CDC_Chains::for_order( $order ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	public static function rest_status( WP_REST_Request $req ) {
		$order = self::order_from_request( $req );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( 'pending' === $order->get_status() || 'cancelled' === $order->get_status() ) {
			$chain    = CDC_Chains::for_order( $order );
			$throttle = 'cdc_poll_' . $order->get_id();
			$shared   = 'cdc_recent_' . $chain->id(); // One check per chain every 10 s, however many customers are waiting.
			if ( ! get_transient( $throttle ) && ! get_transient( $shared ) ) {
				set_transient( $throttle, 1, self::POLL_SECONDS );
				set_transient( $shared, 1, 10 );
				self::run_check( $chain );
				$order = wc_get_order( $order->get_id() );
			}
		}

		return new WP_REST_Response(
			array(
				'status'     => self::page_status( $order ),
				'expires_in' => max( 0, (int) $order->get_meta( '_cdc_expires' ) - time() ),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	public static function rest_txid( WP_REST_Request $req ) {
		$order = self::order_from_request( $req );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$limit_key = 'cdc_txtries_' . $order->get_id();
		$tries     = (int) get_transient( $limit_key );
		if ( $tries >= 6 ) {
			return new WP_REST_Response( self::reply( false, 'Too many tries. Please wait 10 minutes or contact the store.' ), 429 );
		}
		set_transient( $limit_key, $tries + 1, 10 * MINUTE_IN_SECONDS );

		$result           = self::verify_txid( $order, $req->get_param( 'txid' ) );
		$result['status'] = self::page_status( wc_get_order( $order->get_id() ) );
		return new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	private static function reply( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => $message,
		);
	}
}
