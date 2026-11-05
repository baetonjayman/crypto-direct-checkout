<?php
/**
 * The payment box shown on the order-received page:
 * amount, wallet address, QR code, timer and live status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDC_Pay_Page {

	public static function render( $order_id ) {
		$order = wc_get_order( $order_id );
		$chain = CDC_Chains::for_order( $order );
		if ( ! $chain ) {
			return;
		}
		$amount = (int) $order->get_meta( '_cdc_amount_micro' );
		$wallet = (string) $order->get_meta( '_cdc_wallet' );
		if ( ! $amount || ! $wallet ) {
			return;
		}

		$status     = CDC_Payments::page_status( $order );
		$expires_in = max( 0, (int) $order->get_meta( '_cdc_expires' ) - time() );
		$total_secs = max( 60, (int) $order->get_meta( '_cdc_expires' ) - (int) $order->get_meta( '_cdc_created' ) );
		$exact      = $chain->format( $amount );
		$round_u    = $chain->rounded_units( $amount );
		$rounded    = null === $round_u ? null : $chain->format( $round_u );
		$symbol     = $chain->symbol();
		$rate       = (float) $order->get_meta( '_cdc_rate' );
		$txid       = (string) $order->get_meta( '_cdc_txid' );
		$qr_value   = $chain->qr_value( $wallet, $amount );
		$qr_amount  = $qr_value !== $wallet;

		wp_enqueue_style( 'cdc-pay', CDC_URL . 'assets/css/pay.css', array(), CDC_VERSION );
		wp_enqueue_script( 'cdc-qrcode', CDC_URL . 'assets/js/qrcode.js', array(), '1.5.2', true );
		wp_enqueue_script( 'cdc-pay', CDC_URL . 'assets/js/pay.js', array( 'cdc-qrcode' ), CDC_VERSION, true );

		$config = array(
			'statusUrl'  => rest_url( 'cdc/v1/order/' . $order->get_id() ),
			'txidUrl'    => rest_url( 'cdc/v1/order/' . $order->get_id() . '/txid' ),
			'key'        => $order->get_order_key(),
			'status'     => $status,
			'expiresIn'  => $expires_in,
			'totalSecs'  => $total_secs,
			'txidRegex'  => $chain->txid_js_pattern(),
			'network'    => $chain->network(),
			'headings'   => array(
				'waiting' => 'Complete your payment',
				'seen'    => 'Complete your payment',
				'expired' => 'Order not paid',
			),
		);
		?>
		<section class="cdc-pay" data-state="<?php echo esc_attr( $status ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>" aria-labelledby="cdc-heading">
			<div class="cdc-inner">

			<header class="cdc-head">
				<div class="cdc-pill" role="status" aria-live="polite">
					<span class="cdc-dot" aria-hidden="true"></span>
					<span class="cdc-pill-text" data-cdc="pill">
						<?php echo esc_html( self::pill_text( $status ) ); ?>
					</span>
				</div>
				<div class="cdc-timer" data-cdc="timer" aria-label="Time left to pay">--:--</div>
			</header>
			<div class="cdc-progress" aria-hidden="true"><span data-cdc="bar"></span></div>

			<!-- Waiting / expired view -->
			<div class="cdc-body cdc-view-pay">
				<h2 id="cdc-heading" class="screen-reader-text">Pay with <?php echo esc_html( $chain->label() ); ?></h2>

				<div class="cdc-grid">
					<div class="cdc-card">
						<div class="cdc-line">
							<div class="cdc-line-main">
								<span class="cdc-label">Send exactly</span>
								<span class="cdc-amount"><span data-cdc="amount"><?php echo esc_html( $exact ); ?></span> <small><?php echo esc_html( $symbol ); ?></small></span>
								<?php if ( $rate ) : ?>
									<span class="cdc-sub"><?php echo esc_html( sprintf( '≈ %s %s · price locked', number_format( (float) $order->get_total(), 2 ), $order->get_currency() ) ); ?></span>
								<?php endif; ?>
							</div>
							<button type="button" class="cdc-copy" data-copy="<?php echo esc_attr( $exact ); ?>" aria-label="Copy amount">
								<?php echo self::copy_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span>Copy</span>
							</button>
						</div>
						<div class="cdc-line">
							<div class="cdc-line-main">
								<span class="cdc-label">To this address <span class="cdc-net"><?php echo esc_html( $chain->network() ); ?></span></span>
								<code class="cdc-address" aria-label="<?php echo esc_attr( $wallet ); ?>"><?php echo self::address_groups( $wallet ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code>
							</div>
							<button type="button" class="cdc-copy" data-copy="<?php echo esc_attr( $wallet ); ?>" aria-label="Copy wallet address">
								<?php echo self::copy_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span>Copy</span>
							</button>
						</div>
						<p class="cdc-hint">Send the exact amount — the last digits identify your order. After pasting, check the <strong>bold first and last 4</strong> characters match.</p>
					</div>

					<div class="cdc-scan" data-cdc="scan">
						<button type="button" class="cdc-scan-toggle" aria-expanded="false" aria-controls="cdc-qr-panel">
							<?php echo self::qr_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span>Show QR code</span>
						</button>
						<div class="cdc-scan-panel" id="cdc-qr-panel">
							<div class="cdc-qr" data-cdc="qr" data-value="<?php echo esc_attr( $qr_value ); ?>" role="img" aria-label="QR code for this payment"></div>
							<span class="cdc-scan-cap"><?php echo esc_html( $qr_amount ? 'Scan with your wallet — amount included' : 'Scan for the address, then enter the amount' ); ?></span>
						</div>
						<?php if ( $qr_amount ) : ?>
							<a class="cdc-btn cdc-open-wallet" href="<?php echo esc_url( $qr_value, array( 'bitcoin' ) ); ?>">Open in wallet app</a>
						<?php endif; ?>
					</div>
				</div>

				<p class="cdc-warn">
					<?php echo self::warn_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span>Send only <strong><?php echo esc_html( $symbol ); ?></strong> on <strong><?php echo esc_html( $chain->network() ); ?></strong>. Anything else is lost.</span>
				</p>

				<p class="cdc-seen-note">
					<strong>We can see your payment.</strong> It's waiting for the network to confirm it. <?php echo esc_html( $chain->confirm_time_text() ); ?>. You can close this page — your order updates automatically.
				</p>

				<p class="cdc-expired-note">
					Time ran out, so this order was cancelled. If you already sent the payment, enter your transaction ID below and we'll match it.
				</p>

				<?php if ( null !== $rounded ) : ?>
				<details class="cdc-more">
					<summary>Your app won't accept 4 decimals?</summary>
					<p>Send <strong><?php echo esc_html( $rounded . ' ' . $symbol ); ?></strong> instead, then enter your transaction ID below. The store will check it by hand, so it can take a little longer.</p>
				</details>
				<?php endif; ?>

				<details class="cdc-more" data-cdc="txid-box">
					<summary>Already sent it? Enter your transaction ID</summary>
					<form class="cdc-txid" data-cdc="txid-form" novalidate>
						<label class="screen-reader-text" for="cdc-txid-input">Transaction ID</label>
						<input id="cdc-txid-input" name="txid" type="text" inputmode="text" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $chain->txid_placeholder() ); ?>" required>
						<button type="submit" class="cdc-btn">
							<span class="cdc-btn-label">Check payment</span>
							<span class="cdc-spinner" aria-hidden="true"></span>
						</button>
					</form>
					<p class="cdc-msg" data-cdc="txid-msg" aria-live="polite"></p>
				</details>
			</div>

			<!-- Paid view -->
			<div class="cdc-body cdc-view-paid">
				<div class="cdc-check" aria-hidden="true">
					<svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg>
				</div>
				<h2 class="cdc-title">Payment received</h2>
				<p class="cdc-paid-text" data-cdc="paid-text">
					<?php echo esc_html( 'late_paid' === $status ? 'We found your payment. The store will check it and confirm your order shortly.' : 'Your ' . $symbol . ' payment is confirmed on the blockchain. Your order is being processed.' ); ?>
				</p>
				<?php if ( $txid ) : ?>
					<a class="cdc-link" href="<?php echo esc_url( $chain->explorer_tx_url( $txid ) ); ?>" target="_blank" rel="noopener">View transaction</a>
				<?php endif; ?>
			</div>

			<noscript><p class="cdc-msg">Turn on JavaScript to see live payment status. You can also refresh this page after paying.</p></noscript>
			</div>
		</section>
		<?php
	}

	public static function pill_text( $status ) {
		$map = array(
			'waiting'   => 'Waiting for your payment',
			'paid'      => 'Paid',
			'late_paid' => 'Paid — awaiting store check',
			'expired'   => 'Time ran out',
			'seen'      => 'Payment seen — confirming',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : '';
	}


	/** Address in blocks of 4 so it's easy to check. Copying the text gives no spaces. */
	public static function address_groups( $address ) {
		$address = (string) $address;
		if ( strlen( $address ) <= 8 ) {
			return '<span class="cdc-g cdc-key">' . esc_html( $address ) . '</span>';
		}
		// The first 4 and last 4 characters stand out — they're what people check.
		$out = '<span class="cdc-g cdc-key">' . esc_html( substr( $address, 0, 4 ) ) . '</span>';
		foreach ( str_split( substr( $address, 4, -4 ), 4 ) as $g ) {
			$out .= '<span class="cdc-g">' . esc_html( $g ) . '</span>';
		}
		return $out . '<span class="cdc-g cdc-key">' . esc_html( substr( $address, -4 ) ) . '</span>';
	}

	private static function qr_icon() {
		return '<svg class="cdc-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v7h-7"/></svg>';
	}

	private static function warn_icon() {
		return '<svg class="cdc-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.5"/></svg>';
	}

	private static function copy_icon() {
		return '<svg class="cdc-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15V6.5A2.5 2.5 0 0 1 7.5 4H15"/></svg>';
	}
}
