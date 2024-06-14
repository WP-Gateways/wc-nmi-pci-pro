<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

defined( 'ABSPATH' ) || exit;

/**
 * WC_NMI_PCI_Blocks_Support class.
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_NMI_PCI_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = 'nmi';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_nmi_settings', [] );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && ! empty( $this->settings['public_key'] );
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {

		$asset_path   = WC_NMI_PCI_PLUGIN_PATH . '/build/index.asset.php';
		$version      = WC_NMI_PCI_VERSION;
		$dependencies = [];
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}

		$js_params = $this->get_gateway_javascript_params();

		if( ! empty( $js_params['public_key'] ) ) {
			wp_enqueue_script( 'nmi-collect-js', 'https://secure.nmi.com/token/Collect.js', '', null, true );
			$dependencies = array_merge( [ 'nmi-collect-js' ], $dependencies );
		}

		wp_enqueue_style(
			'wc-nmi-blocks-checkout-style',
			WC_NMI_PCI_PLUGIN_URL . '/build/style-index.css',
			[],
			$version
		);

		wp_register_script(
			'wc-nmi-blocks-integration',
			WC_NMI_PCI_PLUGIN_URL . '/build/index.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations(
			'wc-nmi-blocks-integration',
			'wc-nmi'
		);

		return [ 'wc-nmi-blocks-integration' ];
	}


	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		// We need to call array_merge_recursive so the blocks 'button' setting doesn't overwrite
		// what's provided from the gateway or payment request configuration.
		return array_replace_recursive(
			$this->get_gateway_javascript_params(),
			// Blocks-specific options
			[
				'title'                          => $this->get_title(),
				'icons'                          => $this->get_icons(),
				'supports'                       => $this->get_supported_features(),
				'showSavedCards'                 => $this->get_show_saved_cards(),
				'showSaveOption'                 => $this->get_show_save_option(),
				'isAdmin'                        => is_admin(),
			]
		);
	}

	/**
	 * Returns the NMI Payment Gateway JavaScript configuration object.
	 *
	 * @return array  the JS configuration from the NMI Payment Gateway.
	 */
	private function get_gateway_javascript_params() {
		$js_configuration = [];

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( isset( $gateways['nmi'] ) ) {
			$js_configuration = $gateways['nmi']->javascript_params();
		}

		return apply_filters(
			'wc_nmi_params',
			$js_configuration
		);
	}

	/**
	 * Determine if store allows cards to be saved during checkout.
	 *
	 * @return bool True if merchant allows shopper to save card (payment method) during checkout.
	 */
	private function get_show_saved_cards() {
		//return isset( $this->settings['saved_cards'] ) ? 'yes' === $this->settings['saved_cards'] : false;
		return false;
	}

	/**
	 * Determine if the checkbox to enable the user to save their payment method should be shown.
	 *
	 * @return bool True if the save payment checkbox should be displayed to the user.
	 */
	private function get_show_save_option() {
		$saved_cards = $this->get_show_saved_cards();
		return apply_filters( 'wc_nmi_display_save_payment_method_checkbox', filter_var( $saved_cards, FILTER_VALIDATE_BOOLEAN ) );
	}

	/**
	 * Returns the title string to use in the UI (customisable via admin settings screen).
	 *
	 * @return string Title / label string
	 */
	private function get_title() {
		return isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / Debit Card', 'wc-nmi' );
	}

	/**
	 * Return the icons urls.
	 *
	 * @return array Arrays of icons metadata.
	 */
	private function get_icons() {
		$allowed_card_types = $this->settings['allowed_card_types'];
		if( in_array( 'visa', $allowed_card_types ) ) {
			$icons_src['visa'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ),
				'alt' => __( 'Visa', 'wc-nmi' ),
			];
		}
		if( in_array( 'mastercard', $allowed_card_types ) ) {
			$icons_src['mastercard'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ),
				'alt' => __( 'Mastercard', 'wc-nmi' ),
			];
		}
		if( in_array( 'amex', $allowed_card_types ) ) {
			$icons_src['amex'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.svg' ),
				'alt' => __( 'American Express', 'wc-nmi' ),
			];
		}

		if ( 'USD' === get_woocommerce_currency() ) {
			if( in_array( 'discover', $allowed_card_types ) ) {
				$icons_src['discover'] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover.svg' ),
					'alt' => _x( 'Discover', 'Name of credit card', 'wc-nmi' ),
				];
			}
			if( in_array( 'jcb', $allowed_card_types ) ) {
				$icons_src['jcb']      = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb.svg' ),
					'alt' => __( 'JCB', 'wc-nmi' ),
				];
			}
			if( in_array( 'diners-club', $allowed_card_types ) ) {
				$icons_src['diners'] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners.svg' ),
					'alt' => __( 'Diners', 'wc-nmi' ),
				];
			}
		}
		return $icons_src;
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( isset( $gateways['nmi'] ) ) {
			$gateway = $gateways['nmi'];
			return array_filter( $gateway->supports, [ $gateway, 'supports' ] );
		}
		return [];
	}
}
