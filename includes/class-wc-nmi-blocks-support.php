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

	public function __construct() {
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'add_error_message_to_payment_result' ], 8, 2 );
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_nmi_settings', [] );
	}

	/**
	 * Card gateway instance, looked up once per request.
	 *
	 * @var WC_Gateway_NMI|false|null
	 */
	private $card_gateway = null;

	/**
	 * Cached JS configuration from the card gateway.
	 *
	 * @var array|null
	 */
	private $js_params = null;

	/**
	 * Get the card gateway, which holds the API keys and settings, even when it is disabled.
	 *
	 * @return WC_Gateway_NMI|null
	 */
	private function get_card_gateway() {
		if ( null === $this->card_gateway ) {
			$gateways           = WC()->payment_gateways->payment_gateways();
			$this->card_gateway = $gateways['nmi'] ?? false;
		}
		return $this->card_gateway ?: null;
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		$legacy_auth = class_exists( 'WC_Gateway_NMI' ) ? WC_Gateway_NMI::settings_use_legacy_auth( $this->settings ) : ( isset( $this->settings['api_keys'] ) && 'no' === $this->settings['api_keys'] );

		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && ! empty( $this->settings['public_key'] ) && ! empty( $this->settings['private_key'] ) && ! $legacy_auth;
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
			$card_gateway = $this->get_card_gateway();

			if ( $card_gateway ) {
				$collect_js_url = $card_gateway->testmode ? 'https://sandbox.nmi.com/token/Collect.js' : 'https://secure.nmi.com/token/Collect.js';
				wp_register_script( 'nmi-collect-js', $collect_js_url, '', null, true );
				add_filter( 'script_loader_tag', [ __CLASS__, 'add_tokenization_key_to_collect_js' ], 20, 2 );

				$dependencies = array_merge( [ 'nmi-collect-js' ], $dependencies );
			}
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
	 * Add the tokenization key to the Collect.js script tag.
	 *
	 * The gateway class only adds it on the page set as the checkout page in WooCommerce
	 * settings, so a checkout block on any other page would load Collect.js without a key.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 *
	 * @return string
	 */
	public static function add_tokenization_key_to_collect_js( $tag, $handle ) {
		if ( 'nmi-collect-js' !== $handle || false !== strpos( $tag, 'data-tokenization-key' ) ) {
			return $tag;
		}

		$settings = get_option( 'woocommerce_nmi_settings', [] );
		if ( empty( $settings['public_key'] ) ) {
			return $tag;
		}

		return str_replace( ' src', ' data-tokenization-key="' . esc_attr( $settings['public_key'] ) . '" src', $tag );
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
				'description'                    => $this->get_description(),
				'icons'                          => $this->get_icons(),
				'supports'                       => $this->get_supported_features(),
				'showSavedCards'                 => $this->get_show_saved_cards(),
				'showSaveOption'                 => $this->get_show_save_option(),
				'isAdmin'                        => is_admin(),
				// Logs Collect.js responses to the browser console, when Gateway Debug is on.
				'debug'                          => $this->is_debug_enabled(),
			]
		);
	}

	/**
	 * Returns the NMI Payment Gateway JavaScript configuration object.
	 *
	 * @return array  the JS configuration from the NMI Payment Gateway.
	 */
	private function get_gateway_javascript_params() {
		if ( null !== $this->js_params ) {
			return $this->js_params;
		}

		$js_configuration = [];
		$card_gateway     = $this->get_card_gateway();
		if ( $card_gateway ) {
			$js_configuration = $card_gateway->javascript_params();
		}

		$this->js_params = apply_filters(
			'wc_nmi_params',
			$js_configuration
		);

		return $this->js_params;
	}

	/**
	 * Whether Gateway Debug (logging and debugging) is on in the gateway settings.
	 *
	 * @return bool
	 */
	private function is_debug_enabled() {
		return ! empty( $this->settings['logging'] ) && 'yes' === $this->settings['logging'] && ! empty( $this->settings['debugging'] ) && 'yes' === $this->settings['debugging'];
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
	 * The gateway description as HTML, as classic checkout shows it. In sandbox mode it includes the sandbox mode
	 * notice and test card instructions.
	 *
	 * @return string
	 */
	private function get_description() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( ! isset( $gateways['nmi'] ) ) {
			return '';
		}

		$description = trim( (string) $gateways['nmi']->get_description() );

		return '' === $description ? '' : wpautop( wptexturize( wp_kses_post( $description ) ) );
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
		// Same default as the gateway's allowed_card_types setting, for when the settings were never saved.
		$allowed_card_types = (array) ( $this->settings['allowed_card_types'] ?? [ 'visa', 'mastercard', 'discover', 'amex' ] );

		// Same card types and order as WC_Gateway_NMI::get_icon() on the classic checkout.
		$card_icons = [
			'visa'        => [ 'id' => 'visa', 'file' => 'visa.svg', 'alt' => __( 'Visa', 'wc-nmi' ) ],
			'mastercard'  => [ 'id' => 'mastercard', 'file' => 'mastercard.svg', 'alt' => __( 'Mastercard', 'wc-nmi' ) ],
			'amex'        => [ 'id' => 'amex', 'file' => 'amex.svg', 'alt' => __( 'American Express', 'wc-nmi' ) ],
			'discover'    => [ 'id' => 'discover', 'file' => 'discover.svg', 'alt' => _x( 'Discover', 'Name of credit card', 'wc-nmi' ) ],
			'diners-club' => [ 'id' => 'diners', 'file' => 'diners.svg', 'alt' => __( 'Diners Club', 'wc-nmi' ) ],
			'jcb'         => [ 'id' => 'jcb', 'file' => 'jcb.svg', 'alt' => __( 'JCB', 'wc-nmi' ) ],
			'maestro'     => [ 'id' => 'maestro', 'file' => 'maestro.svg', 'alt' => __( 'Maestro', 'wc-nmi' ) ],
		];

		$icons_src = [];
		foreach ( $card_icons as $card_type => $icon ) {
			if ( in_array( $card_type, $allowed_card_types, true ) ) {
				$icons_src[ $icon['id'] ] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/' . $icon['file'] ),
					'alt' => $icon['alt'],
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
		$gateway = $this->get_card_gateway();
		if ( $gateway ) {
			return array_filter( $gateway->supports, [ $gateway, 'supports' ] );
		}
		return [];
	}

	/**
	 * Add error message to the payment result for block checkout
	 * woocommerce_rest_checkout_process_payment_with_context action.
	 *
	 * @param PaymentContext $context Holds context for the payment.
	 * @param PaymentResult  $result  Result object for the payment.
	 */
	public function add_error_message_to_payment_result( PaymentContext $context, PaymentResult &$result ) {

		if( $this->name === $context->payment_method ) {
			// Hook into NMI error processing so that we can capture the error to payment details.
			// This error would have been registered via wc_add_notice() and thus is not helpful for block checkout processing.
			add_action(
				'wc_gateway_nmi_process_payment_error',
				function( $error ) use ( &$result ) {
					$payment_details                 = $result->payment_details;
					$payment_details['errorMessage'] = wp_strip_all_tags( $error->getMessage() );
					$result->set_payment_details( $payment_details );
				}
			);
		}

	}
}
