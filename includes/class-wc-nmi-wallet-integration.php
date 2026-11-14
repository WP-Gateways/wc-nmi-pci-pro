<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NMI as the processor for the WooCommerce Apple Pay and Google Pay extension.
 *
 * Loaded from WC_NMI::wallet_integration(), only while the extension (and so this interface) exists.
 *
 * @see wc-apple-pay-google-pay/docs/INTEGRATION.md
 */
class WC_NMI_Wallet_Integration implements WCAPGP_Processor_Integration {

	/**
	 * @var WC_Gateway_NMI
	 */
	private $gateway;

	/**
	 * @param WC_Gateway_NMI $gateway NMI card gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function get_gateway() {
		return $this->gateway;
	}

	/**
	 * Ready with API keys, even when NMI's own card form is switched off.
	 */
	public function is_ready( $wallet_id ) {
		return $this->gateway->is_configured();
	}

	/**
	 * Pro has no subscriptions, so wallets are not offered for them (no wcapgp_subscriptions support).
	 */
	public function supports_subscriptions() {
		return $this->gateway->supports( 'wcapgp_subscriptions' );
	}

	public function get_google_pay_params() {
		return $this->gateway->get_params_google_pay();
	}

	/**
	 * NMI has no merchant validation of its own; the extension uses its Apple merchant certificate.
	 */
	public function validate_apple_merchant( $validation_url ) {
		return null;
	}

	public function process_payment( WCAPGP_Wallet_Payment $payment, $order_id, $retry = true ) {
		return $this->gateway->process_wallet_payment( $payment, $order_id, $retry );
	}
}
