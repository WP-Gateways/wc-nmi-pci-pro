<?php
/**
 * Stand-ins for the WooCommerce classes the gateways extend or call.
 *
 * They mirror the parts of the real API the gateway uses (woocommerce/includes/abstracts).
 */

// ---------------------------------------------------------------------------
// WordPress.
// ---------------------------------------------------------------------------

class WP_Error {

	private $code;

	private $message;

	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// ---------------------------------------------------------------------------
// Payment gateways.
// ---------------------------------------------------------------------------

abstract class WC_Payment_Gateway {

	public $id = '';

	public $supports = array();

	public $settings = array();

	public function get_option( $key, $empty_value = null ) {
		return $this->settings[ $key ] ?? $empty_value;
	}

	public function supports( $feature ) {
		return in_array( $feature, $this->supports, true );
	}

	public function get_return_url( $order = null ) {
		return 'https://example.com/checkout/order-received/';
	}

	public function get_option_key() {
		return 'woocommerce_' . $this->id . '_settings';
	}

	/**
	 * WooCommerce saves the posted fields into $this->settings and the option; tests set $this->settings directly.
	 */
	public function process_admin_options() {
		return true;
	}
}

class WC_Payment_Gateway_CC extends WC_Payment_Gateway {}

// ---------------------------------------------------------------------------
// Logging.
// ---------------------------------------------------------------------------

class WC_Log_Handler_File {

	public static function get_log_file_path( $handle ) {
		return '/tmp/wc-logs/' . $handle . '.log';
	}

	public static function get_log_file_name( $handle ) {
		return $handle . '-2026-11-13-0123456789abcdef.log';
	}
}

// ---------------------------------------------------------------------------
// Geolocation.
// ---------------------------------------------------------------------------

class WC_Geolocation {

	public static function get_ip_address() {
		return '203.0.113.10';
	}
}
