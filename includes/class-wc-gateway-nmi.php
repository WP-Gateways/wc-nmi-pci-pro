<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_NMI class.
 *
 * @extends WC_Payment_Gateway
 */

class WC_Gateway_NMI extends WC_Payment_Gateway_CC {

	public $testmode;
	public $capture;
	public $capture_on_status_change;
	public $private_key;
	public $public_key;
	public $logging;
	public $debugging;
	public $line_items;
	public $allowed_card_types;
	public $customer_receipt;

	/**
	 * Whether the saved settings still use the old gateway username/password ("api_keys" off), which v5 doesn't accept.
	 *
	 * @var bool
	 */
	public $legacy_auth = false;

	/**
	 * Apple Pay / Google Pay payment being processed through process_wallet_payment().
	 *
	 * @var WCAPGP_Wallet_Payment|null
	 */
	private $wallet_payment = null;

	const NMI_REQUEST_URL_LIVE    = 'https://secure.nmi.com/api/v5/payments/';
	const NMI_REQUEST_URL_SANDBOX = 'https://sandbox.nmi.com/api/v5/payments/';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                   = 'nmi';
		$this->method_title         = __( 'NMI', 'wc-nmi' );
		$this->method_description 	= __( 'NMI works by adding credit card fields on the checkout and then sending the details to the gateway for processing the transactions.', 'wc-nmi' ) . '<h3>' . __( 'Upgrade to Enterprise', 'wc-nmi' ) . '</h3>' . sprintf( __( 'Enterprise version is a full blown plugin that provides support for <strong>processing subscriptions, pre-orders, one click upsells and payments via saved cards or eCheck accounts</strong>. It also has an option to <strong>enable 3D Secure 2 card verification</strong> and make your site Strong Customer Authentication (SCA) compliant. Some of these functionalities require <strong>other premium plugins</strong> that are mentioned on the page linked below. No card details or other sensitive data is stored on your site.<br/><br/><a href="%s" target="_blank">Click here</a> to upgrade to Enterprise version or to know more about it.', 'wc-nmi' ), 'https://pledgedplugins.com/products/nmi-payment-gateway-woocommerce/' );
		$this->has_fields           = true;
		$this->supports             = array(
			'products',
			'refunds',
			'wcapgp_apple_pay',
			'wcapgp_google_pay',
		);

		// Load the form fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		$this->legacy_auth = self::settings_use_legacy_auth( $this->settings );

		// Get setting values.
		$this->title       		  		= $this->get_option( 'title' );
		$this->description 		  		= $this->get_option( 'description' );
		$this->enabled     		  		= $this->get_option( 'enabled' );
		$this->testmode    		  		= $this->get_option( 'testmode' ) === 'yes';
		$this->capture     		  		= $this->get_option( 'capture', 'yes' ) === 'yes';
		$this->capture_on_status_change = $this->get_option( 'capture_on_status_change', 'yes' ) === 'yes';
		$this->private_key	   			= $this->get_option( 'private_key' );
		$this->public_key	   			= $this->get_option( 'public_key' );
		$this->logging     		  		= $this->get_option( 'logging' ) === 'yes';
		$this->debugging   		  		= $this->get_option( 'debugging' ) === 'yes';
		$this->line_items  		  		= $this->get_option( 'line_items' ) === 'yes';
		$this->allowed_card_types 		= $this->get_option( 'allowed_card_types', array() );
		$this->customer_receipt   		= $this->get_option( 'customer_receipt' ) === 'yes';

		if ( $this->testmode ) {
			$this->description .= ' ' . sprintf( __( '<br /><br /><strong>SANDBOX MODE ENABLED</strong><br /> In sandbox mode, you can use the card number 4111111111111111 with any CVC and a valid expiration date or check <a href="%s">NMI\'s testing documentation</a> for more card numbers.', 'wc-nmi' ), 'https://docs.nmi.com/reference/testing-methods' );
			$this->description  = trim( $this->description );
		}

		// Hooks
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon() {
		$icon = '';
		if( in_array( 'visa', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" />';
		}
		if( in_array( 'mastercard', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="Mastercard" width="32" />';
		}
		if( in_array( 'amex', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.svg' ) . '" alt="Amex" width="32" />';
		}
		if( in_array( 'discover', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover.svg' ) . '" alt="Discover" width="32" />';
		}
		if( in_array( 'diners-club', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners.svg' ) . '" alt="Diners Club" width="32" />';
		}
		if( in_array( 'jcb', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb.svg' ) . '" alt="JCB" width="32" />';
		}
		if( in_array( 'maestro', $this->allowed_card_types ) ) {
			$icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/maestro.svg' ) . '" alt="Maestro" width="32" />';
		}
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Shows the admin notices of the registered gateway, which has the latest settings
	 * (WooCommerce re-creates its gateways after the settings are saved).
	 */
	public static function display_admin_notices() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways['nmi'] ) && $gateways['nmi'] instanceof self ) {
			$gateways['nmi']->admin_notices();
		}
	}

	/**
	 * Check if SSL is enabled and notify the user
	 */
	public function admin_notices() {
		if ( $this->enabled == 'no' ) {
			return;
		}

		// Check required fields. Sites on the old username/password are covered by the legacy auth notice of the main plugin.
		if ( ! $this->legacy_auth && ! $this->private_key ) {
			echo '<div class="error"><p>' . sprintf( __( 'NMI error: Please enter your Private Key <a href="%s">here</a>', 'wc-nmi' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi' ) ) . '</p></div>';
			return;
		}

		// Without a Public Tokenization Key the card fields post through this server (Direct Post).
		if ( ! $this->public_key ) {
			echo '<div class="notice notice-warning"><p>' . sprintf( __( 'NMI is using Direct Post because no Public Tokenization Key is set, so card numbers are sent through your server. This increases your PCI compliance scope. Please add a Public Tokenization Key <a href="%s">here</a> to use Collect.js instead.', 'wc-nmi' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi' ) ) . '</p></div>';
		}

		// Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected
		if ( ! wc_checkout_is_https() ) {
			echo '<div class="notice notice-warning"><p>' . sprintf( __( 'NMI is enabled, but a SSL certificate is not detected. Your checkout may not be secure! Please ensure your server has a valid <a href="%1$s" target="_blank">SSL certificate</a>', 'wc-nmi' ), 'https://en.wikipedia.org/wiki/Transport_Layer_Security' ) . '</p></div>';
 		}

	}

    public function admin_options() { ?>
		<script>
            //alert(123);
            jQuery( function( $ ) {
                'use strict';

                /**
                 * Object to handle NMI admin functions.
                 */
                let wc_nmi_admin = {
                    /**
                     * Initialize.
                     */
                    init: function() {
						$( document.body ).on( 'change', '#woocommerce_nmi_capture', function() {
                            let field_capture_on_status_change = $( '#woocommerce_nmi_capture_on_status_change' ).parents( 'tr' ).eq( 0 );
                            if ( $( this ).is( ':checked' ) ) {
                                field_capture_on_status_change.hide();
                            } else {
                                field_capture_on_status_change.show();
                            }
                        });

						$( '#woocommerce_nmi_capture' ).change();
                    }
                };

                wc_nmi_admin.init();
            } );
        </script>
		<?php parent::admin_options();
	}

	/**
	 * Check if this gateway is enabled
	 */
	public function is_available() {
		if ( $this->enabled == "yes" ) {
			if ( is_add_payment_method_page() ) {
				return false;
			}
			return $this->is_configured();
		}
		return parent::is_available();
	}

	/**
	 * Whether NMI can take payments: a private key is set and the settings no longer use the old username/password.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->private_key ) && ! $this->legacy_auth;
	}

	/**
	 * Whether these saved settings use the old gateway username/password ("api_keys" off), which NMI's v5 API doesn't accept.
	 *
	 * @param mixed $settings Saved gateway settings.
	 * @return bool
	 */
	public static function settings_use_legacy_auth( $settings ) {
		return is_array( $settings ) && isset( $settings['api_keys'] ) && 'no' === $settings['api_keys'];
	}

	/**
	 * Save the settings. Once a private key is saved, the old username/password settings are deleted
	 * and the site is no longer locked.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( ! empty( $this->settings['private_key'] ) && ( isset( $this->settings['api_keys'] ) || isset( $this->settings['username'] ) || isset( $this->settings['password'] ) ) ) {
			unset( $this->settings['api_keys'], $this->settings['username'], $this->settings['password'] );
			update_option( $this->get_option_key(), $this->settings );
			$this->legacy_auth = false;
		}

		return $saved;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters( 'wc_nmi_settings', array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'wc-nmi' ),
				'label'       => __( 'Enable NMI', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title' => array(
				'title'       => __( 'Title', 'wc-nmi' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'wc-nmi' ),
				'default'     => __( 'Credit card (NMI)', 'wc-nmi' )
			),
			'description' => array(
				'title'       => __( 'Description', 'wc-nmi' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'wc-nmi' ),
				'default'     => __( 'Pay with your credit card via NMI.', 'wc-nmi' )
			),
			'testmode' => array(
				'title'       => __( 'Sandbox Mode', 'wc-nmi' ),
				'label'       => __( 'Place the payment gateway in sandbox mode. This will display test information on the checkout page.', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( '<strong>NOTE: Sandbox mode is not the same as Test mode.</strong> If your login URL says <code>sandbox.nmi.com</code>, check this option. If it says <code>secure.nmi.com</code> leave the option unchecked, even if it is a test account, and no live payments will take place.', 'wc-nmi' ),
				'default'     => 'yes'
			),
            'private_key' => array(
				'title'       => __( 'Private Key', 'wc-nmi' ),
				'type'        => 'password',
				'description' => __( 'Required. Used for authenticating all transactions. Find or create it in your NMI account under Settings > Security Keys, and make sure it has "API" permission enabled.', 'wc-nmi' ),
				'default'     => ''
			),
            'public_key' => array(
				'title'       => __( 'Public Tokenization Key', 'wc-nmi' ),
				'type'        => 'text',
				'description' => __( 'Used for Collect.js tokenization for PCI compliance. Leave it empty ONLY if you are facing Javascript issues at checkout. Without it, the card fields post through your server (Direct Post).', 'wc-nmi' ),
				'default'     => ''
			),
			'capture' => array(
				'title'       => __( 'Capture', 'wc-nmi' ),
				'label'       => __( 'Capture charge immediately', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( 'Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later.', 'wc-nmi' ),
				'default'     => 'yes'
			),
			'capture_on_status_change' => array(
				'title'       => '',
				'label'       => __( 'Capture authorized transaction on status change', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( 'Whether or not to capture the authorized transaction when you change the order status from "On Hold" to "Processing" or "Completed". Disable if you prefer to capture transactions in the gateway dashboard.', 'wc-nmi' ),
				'default'     => 'yes'
			),
			'logging' => array(
				'title'       => __( 'Logging', 'wc-nmi' ),
				'label'       => __( 'Log debug messages', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => sprintf( __( 'Save debug messages to the WooCommerce System Status log file <code>%s</code>.', 'wc-nmi' ), WC_Log_Handler_File::get_log_file_path( 'wc-nmi' ) ),
				'default'     => 'no'
			),
			'debugging' => array(
				'title'       => __( 'Gateway Debug', 'wc-nmi' ),
				'label'       => __( 'Log gateway requests and response to the WooCommerce System Status log.', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( '<strong>CAUTION! Enabling this option will write full gateway requests and responses to the logs, including customer names, addresses and email addresses. Card numbers are masked to the last 4 digits and the CVV is hidden.</strong> Do not turn this on unless you have a problem processing credit cards. You must only ever enable it temporarily for troubleshooting or to send requested information to the plugin author. It must be disabled straight away after the issues are resolved and the plugin logs should be deleted.', 'wc-nmi' ) . ' ' . sprintf( __( '<a target="_blank" href="%s">Click here</a> to check and delete the full log file.', 'wc-nmi' ), esc_url( self::get_log_url() ) ),
				'default'     => 'no'
			),
			'line_items' => array(
				'title'       => __( 'Line Items', 'wc-nmi' ),
				'label'       => __( 'Enable Line Items', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( 'Add line item data to description sent to the gateway (eg. Item x qty).', 'wc-nmi' ),
				'default'     => 'no'
			),
			'allowed_card_types' => array(
				'title'       => __( 'Allowed Card types', 'wc-nmi' ),
				'class'       => 'wc-enhanced-select',
				'type'        => 'multiselect',
				'description' => __( 'Select the card types you want to allow payments from.', 'wc-nmi' ),
				'default'     => array( 'visa','mastercard','discover','amex' ),
				'options'	  => array(
					'visa' 			=> __( 'Visa', 'wc-nmi' ),
					'mastercard' 	=> __( 'MasterCard', 'wc-nmi' ),
					'discover' 		=> __( 'Discover', 'wc-nmi' ),
					'amex' 			=> __( 'American Express', 'wc-nmi' ),
					'diners-club' 	=> __( 'Diners Club', 'wc-nmi' ),
					'jcb' 			=> __( 'JCB', 'wc-nmi' ),
					'maestro' 		=> __( 'Maestro', 'wc-nmi' ),
				),
			),
			'customer_receipt' => array(
				'title'       => __( 'Receipt', 'wc-nmi' ),
				'label'       => __( 'Send Gateway Receipt', 'wc-nmi' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, the customer will be sent an email receipt from NMI.', 'wc-nmi' ),
				'default'     => 'no'
			),
		) );
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		echo '<div class="nmi_new_card" id="nmi-payment-data">';

		if ( $this->description ) {
			echo apply_filters( 'wc_nmi_description', wpautop( wp_kses_post( $this->description ) ) );
		}

		if( $this->public_key ) {
            $this->collect_js_form();
        } else {
            $this->form();
        }

        echo '</div>';
	}

    public function collect_js_form() {
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
            <!-- Used to display form errors -->
            <div class="nmi-source-errors" role="alert"></div>

            <div class="form-row form-row-wide">
                <label for="nmi-card-number-element"><?php esc_html_e( 'Card Number', 'wc-nmi' ); ?> <span class="required">*</span></label>
                <div class="nmi-card-group">
                    <div id="nmi-card-number-element" class="wc-nmi-elements-field">
                    <!-- a NMI Element will be inserted here. -->
                    </div>

                    <i class="nmi-credit-card-brand nmi-card-brand" alt="Credit Card"></i>
                </div>
            </div>

            <div class="form-row form-row-first">
                <label for="nmi-card-expiry-element"><?php esc_html_e( 'Expiry Date', 'wc-nmi' ); ?> <span class="required">*</span></label>

                <div id="nmi-card-expiry-element" class="wc-nmi-elements-field">
                <!-- a NMI Element will be inserted here. -->
                </div>
            </div>

            <div class="form-row form-row-last">
                <label for="nmi-card-cvc-element"><?php esc_html_e( 'Card Code (CVC)', 'wc-nmi' ); ?> <span class="required">*</span></label>
                <div id="nmi-card-cvc-element" class="wc-nmi-elements-field">
                <!-- a NMI Element will be inserted here. -->
                </div>
            </div>
            <div class="clear"></div>

			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

    public function payment_scripts() {
		add_filter( 'script_loader_tag', array( $this, 'add_public_key_to_js' ), 10, 2 );

		if ( ! $this->public_key || ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) ) {
			return;
		}

		// is_checkout() is true on the order received page too, where there's nothing to pay.
		if ( is_order_received_page() ) {
			return;
		}

		// The checkout block loads its own scripts (WC_NMI_Blocks_Support).
		if ( self::is_block_checkout_page() ) {
			return;
		}

		$collect_js_url = $this->testmode ? 'https://sandbox.nmi.com/token/Collect.js' : 'https://secure.nmi.com/token/Collect.js';
		wp_enqueue_script( 'nmi-collect-js', $collect_js_url, '', null, true );
		wp_enqueue_script( 'woocommerce_nmi', plugins_url( 'assets/js/nmi.js', WC_NMI_PCI_MAIN_FILE ), array( 'nmi-collect-js' ), WC_NMI_PCI_VERSION, true );

		wp_localize_script( 'woocommerce_nmi', 'wc_nmi_params', apply_filters( 'wc_nmi_params', $this->javascript_params() ) );
	}

	public function javascript_params() {
		$nmi_params = array(
			'public_key'           	=> $this->public_key,
			'allowed_card_types'   	=> $this->allowed_card_types,
			'i18n_terms'           	=> __( 'Please accept the terms and conditions first', 'wc-nmi' ),
			'i18n_required_fields'	=> __( 'Please fill in required checkout fields first', 'wc-nmi' ),
			'no_card_number_error'  => __( 'Enter a card number.', 'wc-nmi' ),
			'no_card_expiry_error'  => __( 'Enter an expiry date.', 'wc-nmi' ),
			'no_cvv_error'          => __( 'CVC code is required.', 'wc-nmi' ),
			'card_number_error' 	=> __( 'Invalid card number.', 'wc-nmi' ),
			'card_expiry_error' 	=> __( 'Invalid card expiry date.', 'wc-nmi' ),
			'card_cvc_error' 		=> __( 'Invalid card CVC.', 'wc-nmi' ),
			'placeholder_cvc'	 	=> __( 'CVC', 'woocommerce' ),
			'placeholder_expiry' 	=> __( 'MM / YY', 'woocommerce' ),
			'card_disallowed_error' => __( 'Card Type Not Accepted.', 'wc-nmi' ),
			'error_ref' 			=> __( '(Ref: [ref])', 'wc-nmi' ),
			'timeout_error' 		=> __( 'The tokenization did not respond in the expected timeframe. Please make sure the fields are correctly filled in and submit the form again.', 'wc-nmi' ),
			'collect_js_error' 		=> __( 'Collect.js could not be loaded. Please try a different payment method or contact the administrator.', 'wc-nmi' ),
		);
		$nmi_params['debug']       = $this->logging && $this->debugging;
		$nmi_params['is_checkout'] = ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no'; // wpcs: csrf ok.

		return $nmi_params;
	}

    public function add_public_key_to_js( $tag, $handle ) {
       if ( 'nmi-collect-js' !== $handle ) return $tag;
       return str_replace( ' src', ' data-tokenization-key="' . $this->public_key . '" src', $tag );
    }

	/**
	 * The Collect.js response posted by the checkout, or false.
	 *
	 * The classic checkout posts every payment method's fields, so a token from the NMI card fields can be present
	 * when the customer pays with Apple Pay / Google Pay. The wallet payment takes priority.
	 *
	 * @return array|false
	 */
	public function get_nmi_js_response() {
		if ( ! isset( $_POST['nmi_js_response'] ) || $this->get_posted_wallet_payment_details() ) {
			return false;
		}
		$response = json_decode( wc_clean( wp_unslash( $_POST['nmi_js_response'] ) ), 1 );
		if ( ! is_array( $response ) ) {
			return false;
		}
		// Collect.js sometimes returns the card under lookupData only.
		if ( empty( $response['card'] ) && ! empty( $response['lookupData']['card'] ) ) {
			$response['card'] = $response['lookupData']['card'];
		}
		return $response;
	}

	/**
	 * Apple Pay / Google Pay payment data posted by the WooCommerce Apple Pay and Google Pay extension,
	 * as NMI payment_details, or null when the payment isn't a wallet payment.
	 *
	 * @return array|null
	 */
	public function get_posted_wallet_payment_details() {
		if ( $this->wallet_payment ) {
			$key = $this->wallet_payment->is_apple_pay() ? 'applepay_payment_data' : 'googlepay_payment_data';
			return array( $key => $this->wallet_payment->get_decoded_token() );
		}
		if ( ! empty( $_POST['wcapgp_google_pay_payment_data'] ) ) {
			return array( 'googlepay_payment_data' => base64_decode( wc_clean( wp_unslash( $_POST['wcapgp_google_pay_payment_data'] ) ) ) );
		}
		if ( ! empty( $_POST['wcapgp_apple_pay_payment_data'] ) ) {
			return array( 'applepay_payment_data' => base64_decode( wc_clean( wp_unslash( $_POST['wcapgp_apple_pay_payment_data'] ) ) ) );
		}
		return null;
	}

	/**
	 * Processes a payment made with the WooCommerce Apple Pay and Google Pay extension.
	 *
	 * @param WCAPGP_Wallet_Payment $payment  Wallet payment.
	 * @param int                   $order_id Order ID.
	 * @param bool                  $retry    Unused; the extension's processor contract passes it.
	 * @return array
	 */
	public function process_wallet_payment( $payment, $order_id, $retry = true ) {
		$this->wallet_payment = $payment;

		try {
			return $this->process_payment( $order_id );
		} finally {
			$this->wallet_payment = null;
		}
	}

	/**
	 * Process the payment
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		$this->log( "Info: Beginning processing payment for order $order_id for the amount of {$order->get_total()}" );

		$response = false;

		try {
			if ( $wallet_payment_details = $this->get_posted_wallet_payment_details() ) {
				// Apple Pay / Google Pay: the card details come from NMI's response.
				$payment_details = $wallet_payment_details;

			} elseif ( $js_response = $this->get_nmi_js_response() ) {
				$this->validate_js_card_type( $js_response );

				$payment_details = array( 'payment_token' => $js_response['token'] ?? '' );

				if ( ! empty( $js_response['card']['number'] ) ) {
					$card_last4 = substr( $js_response['card']['number'], -4 );
					$card_type  = $this->get_js_card_type( $js_response );
				}

			} else {
				// Direct Post: card fields on the page when there's no Public Tokenization Key.
				if ( empty( $_POST['nmi-card-number'] ) || empty( $_POST['nmi-card-expiry'] ) || empty( $_POST['nmi-card-cvc'] ) ) {
					throw new Exception( __( 'Credit card details cannot be left incomplete.', 'wc-nmi' ) );
				}

				$card_number = str_replace( ' ', '', wc_clean( $_POST['nmi-card-number'] ) );
				$card_type   = $this->get_card_type( $card_number, 'pattern', 'name' );

				// Check for card type supported or not
				if ( ! in_array( $card_type, $this->allowed_card_types ) ) {
					$this->log( sprintf( __( 'Card type being used is not one of supported types in plugin settings: %s', 'wc-nmi' ), $card_type ) );
					throw new Exception( __( 'Card Type Not Accepted', 'wc-nmi' ) );
				}

				$expiry     = $this->parse_card_expiry( wc_clean( $_POST['nmi-card-expiry'] ) );
				$card_last4 = substr( $card_number, -4 );

				$payment_details = array(
					'card_number' => $card_number,
					'card_exp'    => $expiry[0] . $expiry[1],
					'card_cvv'    => wc_clean( $_POST['nmi-card-cvc'] ),
				);
			}

			$description = sprintf( __( '%s - Order %s', 'wc-nmi' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );

			if ( $this->line_items ) {
				$description .= ' (' . $this->get_line_items( $order ) . ')';
			}

			$payment_args = array(
				'amount'             => $order->get_total(),
				'currency'           => $this->get_payment_currency( $order_id ),
				'customer_receipt'   => (bool) $this->customer_receipt,
				'industry'           => 'ecommerce',
				'payment_details'    => $payment_details,
				'order_details'      => array(
					'order_description' => $description,
					'id'                => $order->get_order_number(),
					'tax'               => $order->get_total_tax(),
					'shipping'          => $order->get_shipping_total(),
					'ip_address'        => WC_Geolocation::get_ip_address(),
				),
				'billing_address'    => array(
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
					'address1'   => $order->get_billing_address_1(),
					'address2'   => $order->get_billing_address_2(),
					'city'       => $order->get_billing_city(),
					'state'      => $order->get_billing_state(),
					'country'    => $order->get_billing_country(),
					'zip'        => $order->get_billing_postcode(),
					'email'      => $order->get_billing_email(),
					'phone'      => $order->get_billing_phone(),
					'company'    => $order->get_billing_company(),
				),
			);

			// Shipping address fields, only those the order has.
			$shipping_address = array_filter( array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'company'    => $order->get_shipping_company(),
				'address1'   => $order->get_shipping_address_1(),
				'address2'   => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'country'    => $order->get_shipping_country(),
				'zip'        => $order->get_shipping_postcode(),
			) );
			if ( $shipping_address ) {
				$payment_args['shipping_address'] = $shipping_address;
			}

			$trans_type = $this->capture ? 'sale' : 'auth';

			$payment_args = apply_filters( 'wc_nmi_v5_request_args', $payment_args, $order, $trans_type );

			$response = $this->nmi_request( "POST:{$trans_type}", $payment_args );

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			if ( empty( $response['id'] ) || ! isset( $response['response'] ) || (int) $response['response'] !== 1 ) {
				throw new Exception( __( 'There was an error with the gateway response.', 'wc-nmi' ) );
			}

			// NMI's response has the card that was charged: wallet payments have no card fields on the page, and the
			// Collect.js card details are posted by the browser, so they can't be trusted.
			if ( $response_card = $this->get_card_details_from_response( $response ) ) {
				$card_last4 = $response_card['last4'];
				$card_type  = '' !== $response_card['type'] ? $response_card['type'] : ( $card_type ?? '' );
			}

			// A Collect.js token for a card type the store doesn't accept (e.g. with an edited card type in the posted
			// response) is voided rather than kept.
			$void_failed = false;
			if ( ! empty( $js_response ) && ! empty( $response_card['type'] ) && ! in_array( $response_card['type'], $this->allowed_card_types, true ) ) {
				if ( $this->void_disallowed_card_payment( $order, $response['id'], $response_card['type'] ) ) {
					throw new Exception( __( 'Card Type Not Accepted', 'wc-nmi' ) );
				}
				// The shopper was charged: keep the charge on the order so it can be refunded, and hold it for review.
				$void_failed = true;
			}

			// Store charge ID
			$order->update_meta_data( '_nmi_charge_id', $response['id'] );
			$order->update_meta_data( '_nmi_authorization_code', $response['auth_code'] ?? '' );
			$order->update_meta_data( '_nmi_card_last4', $card_last4 ?? '' );
			$order->update_meta_data( '_nmi_card_type', $card_type ?? '' );

			$order->set_transaction_id( $response['id'] );

			if ( $void_failed ) {

				$order->update_meta_data( '_nmi_charge_captured', 'sale' === $trans_type ? 'yes' : 'no' );
				$order->update_status( 'on-hold', sprintf( __( 'The card type is not accepted and NMI could not void the payment (Charge ID: %s). Please review this order and refund it if needed.', 'wc-nmi' ), $response['id'] ) );
				$this->log( "Info: order {$order_id} put on hold, the payment of a disallowed card type could not be voided." );

			} elseif ( 'sale' === $trans_type ) {

				// Store captured value
				$order->update_meta_data( '_nmi_charge_captured', 'yes' );
				$order->update_meta_data( 'NMI Payment ID', $response['id'] );
				$order->save();

				// Payment complete
				$order->payment_complete( $response['id'] );

				// Add order note
				$complete_message = sprintf( __( 'NMI charge completed for %s (Charge ID: %s).', 'wc-nmi' ), wc_price( $payment_args['amount'], array( 'currency' => $order->get_currency() ) ), $response['id'] );
				$order->add_order_note( $complete_message );
				$this->log( 'Success: ' . wp_strip_all_tags( $complete_message ) );

			} else {

				// Store captured value
				$order->update_meta_data( '_nmi_charge_captured', 'no' );

				if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					wc_reduce_stock_levels( $order_id );
				}

				// Mark as on-hold
				$authorized_message = sprintf( __( 'NMI charge authorized for %s (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'wc-nmi' ), wc_price( $payment_args['amount'], array( 'currency' => $order->get_currency() ) ), $response['id'] );
				$order->update_status( 'on-hold', $authorized_message . "\n" );
				$this->log( 'Success: ' . wp_strip_all_tags( $authorized_message ) );

			}

			$order->save();

			// Remove cart
			WC()->cart->empty_cart();

			do_action( 'wc_gateway_nmi_process_payment', $response, $order );

			// Return thank you page redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} catch ( Exception $e ) {
			wc_add_notice( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ), 'error' );
			$this->log( sprintf( __( 'Gateway Error: %s', 'wc-nmi' ), $e->getMessage() ) );

			$this->add_failure_order_note( $order, $response, $e );

			do_action( 'wc_gateway_nmi_process_payment_error', $e, $order );

			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Void a payment NMI approved for a card type the store doesn't accept.
	 *
	 * @param WC_Order $order
	 * @param string   $transaction_id
	 * @param string   $card_type
	 * @return bool Whether the payment was voided.
	 */
	protected function void_disallowed_card_payment( $order, $transaction_id, $card_type ) {
		$this->log( sprintf( __( 'Card type being used is not one of supported types in plugin settings: %s', 'wc-nmi' ), $card_type ) );

		$void = $this->nmi_request( "POST:{$transaction_id}/void" );

		if ( is_wp_error( $void ) || empty( $void['id'] ) ) {
			$reason = is_wp_error( $void ) ? $void->get_error_message() : __( 'There was an error with the gateway response.', 'wc-nmi' );
			$order->add_order_note( sprintf( __( 'NMI approved a %1$s card, which is not an accepted card type, but the payment could not be voided (Charge ID: %2$s). Please void or refund it in your NMI account. NMI failure reason: %3$s', 'wc-nmi' ), $card_type, $transaction_id, $reason ) );
			$this->log( "Gateway Error: void of {$transaction_id} failed: {$reason}" );
			return false;
		}

		$order->add_order_note( sprintf( __( 'NMI approved a %1$s card, which is not an accepted card type, so the payment was voided (Charge ID: %2$s).', 'wc-nmi' ), $card_type, $transaction_id ) );
		return true;
	}

	/**
	 * Add an order note describing why an NMI request failed.
	 *
	 * @param WC_Order $order
	 * @param mixed $payment_response WP_Error returned by nmi_request(), if any.
	 * @param Exception $e
	 *
	 * @return void
	 */
	protected function add_failure_order_note( $order, $payment_response, $e ) {
		$response = is_wp_error( $payment_response ) ? $payment_response->get_error_data() : null;

		if ( isset( $response['type'] ) && in_array( $response['type'], array( 'generalError', 'inputError', 'validationError', 'internalError', 'authenticationError' ) ) ) {
			$error_code = $response['error_code'] ?? $response['errorCode'] ?? '';
			$order->add_order_note( trim( sprintf( __( "NMI failure reason: %s", 'wc-nmi' ), $error_code . ' - ' . ( $response['message'] ?? '' ) ) ) );
		} elseif ( isset( $response['object'] ) && 'transaction' === $response['object'] ) {
			$order->add_order_note( sprintf( __( 'NMI failure reason: %s', 'wc-nmi' ), ( $response['response_code'] ?? '' ) . ' - ' . ( $response['response_text'] ?? '' ) ) );
		} else {
			$order->add_order_note( sprintf( __( "NMI failure reason: %s", 'wc-nmi' ), $e->getMessage() ) );
		}
	}

	/**
	 * Check that the card in a Collect.js response is one of the allowed card types.
	 *
	 * The checkout scripts check this too, but that can be bypassed, so it's enforced here as well.
	 *
	 * @param array $js_response Decoded Collect.js response.
	 *
	 * @throws Exception If the card type isn't allowed.
	 */
	public function validate_js_card_type( $js_response ) {
		// Collect.js leaves the type empty when it can't identify the card; the gateway decides then.
		if ( empty( $js_response['card']['type'] ) ) {
			return;
		}

		$type = $this->get_js_card_type( $js_response );

		if ( ! in_array( $type, $this->allowed_card_types, true ) ) {
			$this->log( sprintf( __( 'Card type being used is not one of supported types in plugin settings: %s', 'wc-nmi' ), $type ) );
			throw new Exception( __( 'Card Type Not Accepted', 'wc-nmi' ) );
		}
	}

	/**
	 * The card type of a Collect.js response, as a setting name (e.g. 'visa').
	 *
	 * @param array $js_response
	 * @return string
	 */
	protected function get_js_card_type( $js_response ) {
		$type = (string) ( $js_response['card']['type'] ?? '' );

		// Collect.js calls Diners Club "diners"; the setting uses "diners-club".
		return 'diners' === $type ? 'diners-club' : $type;
	}

	/**
	 * Card details from an NMI v5 transaction response.
	 *
	 * Wallet payments have no card fields on the page, so the order's card details come from the gateway's
	 * response (payment_details.card_number is masked, e.g. 411111******1111).
	 *
	 * @param array|null $response NMI v5 transaction response.
	 *
	 * @return array|null With last4 and type (e.g. 'visa'), or null if unavailable.
	 */
	public function get_card_details_from_response( $response ) {
		$details = is_array( $response ) && is_array( $response['payment_details'] ?? null ) ? $response['payment_details'] : array();
		$number  = (string) ( $details['card_number'] ?? '' );

		if ( strlen( $number ) < 4 ) {
			return null;
		}

		// card_type is a label such as "Visa"; fall back to the card number prefix.
		$type = ! empty( $details['card_type'] ) ? $this->get_card_type( $details['card_type'], 'label', 'name' ) : false;
		if ( ! $type ) {
			$type = $this->get_card_type( ! empty( $details['card_bin'] ) ? $details['card_bin'] : $number, 'pattern', 'name' );
		}

		return array(
			'last4' => substr( $number, -4 ),
			'type'  => $type ? $type : '',
		);
	}

	/**
	 * Split a card expiry date entered as "MM / YY" or "MM / YYYY".
	 *
	 * @param string $expiry
	 * @return array Two-digit month and two-digit year.
	 */
	public function parse_card_expiry( $expiry ) {
		$parts = array_map( 'trim', explode( '/', (string) $expiry ) );
		$month = str_pad( $parts[0], 2, '0', STR_PAD_LEFT );
		$year  = isset( $parts[1] ) ? substr( $parts[1], -2 ) : '';

		return array( $month, $year );
	}

	/**
	 * Send a request to NMI's v5 Payments API.
	 *
	 * @param string $endpoint "METHOD:endpoint", e.g. "POST:sale" or "POST:{transaction_id}/refund". A bare endpoint is a POST.
	 * @param array  $args     Request body.
	 * @return array|WP_Error The decoded response, or an error (declines included).
	 */
	function nmi_request( $endpoint, $args = array() ) {

		// Without a private key (or while the saved settings still use the old username/password) nothing is sent.
		if ( ! $this->is_configured() ) {
			$error_message = __( 'NMI is not configured. Please enter your Private Key in the NMI settings.', 'wc-nmi' );
			return new WP_Error( 'not_configured', apply_filters( 'woocommerce_nmi_error_message', $error_message, array() ) );
		}

		$gateway_debug = ( $this->logging && $this->debugging );

		if ( false === strpos( $endpoint, ':' ) ) {
			$endpoint = 'POST:' . $endpoint;
		}
		list( $request_method, $endpoint ) = explode( ':', $endpoint, 2 );

		$request_url = $this->testmode ? self::NMI_REQUEST_URL_SANDBOX : self::NMI_REQUEST_URL_LIVE;
		$request_url = apply_filters( 'wc_nmi_request_url', $request_url . $endpoint, $endpoint );

		// A filter written for the old API returns a transact.php URL (e.g. a reseller's gateway host): keep the host, use v5.
		if ( false !== strpos( $request_url, '/api/transact.php' ) ) {
			$request_url = preg_replace( '#/api/transact\.php.*$#', '/api/v5/payments/' . $endpoint, $request_url );
		}

		if ( isset( $args['currency'] ) && empty( $args['currency'] ) ) {
			$args['currency'] = get_woocommerce_currency();
		}

		foreach ( array( 'billing_address', 'shipping_address' ) as $address ) {
			if ( ! empty( $args[ $address ]['country'] ) && empty( $args[ $address ]['state'] ) ) {
				$args[ $address ]['state'] = 'NA';
			}
			// NMI rejects an empty phone, which is optional at checkout and in wallet payment sheets.
			if ( isset( $args[ $address ]['phone'] ) && '' === trim( (string) $args[ $address ]['phone'] ) ) {
				unset( $args[ $address ]['phone'] );
			}
		}

		// Setting custom timeout for the HTTP request
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		$api_args = array(
			'method'  => $request_method,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $this->private_key,
			),
		);

		if ( ! in_array( $request_method, array( 'GET', 'DELETE' ) ) ) {
			// An empty body must still be a JSON object: json_encode( array() ) is "[]".
			$api_args['body'] = $args ? json_encode( $args ) : '{}';
		}

		$response = wp_remote_request( $request_url, $api_args );

		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		$result = $this->parse_nmi_response( $response );

		// Saving to Log here
		if ( $gateway_debug ) {
			$message = sprintf( "\nPosting to: \n%s %s\nRequest: \n%s\nResponse: \n%s", $request_method, $request_url, print_r( $this->get_loggable_args( $args ), 1 ), print_r( $result, 1 ) );
			WC_NMI_Logger::log( $message );
		}

		return $result;
	}

	/**
	 * Turn an NMI v5 HTTP response into the decoded transaction or a WP_Error.
	 *
	 * A transaction body is judged by its "response" (1 approved, 2 declined, 3 error) whatever the HTTP status;
	 * other non-2xx responses carry an error body ({type, error_code, message, details}).
	 *
	 * @param array|WP_Error $response wp_remote_request() result.
	 * @return array|WP_Error
	 */
	protected function parse_nmi_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );

		if ( isset( $body['object'] ) && 'transaction' === $body['object'] ) {

			if ( empty( $body['response'] ) || ! in_array( (int) $body['response'], array( 1, 2, 3 ), true ) ) {
				$error_message = __( 'There was an error with the gateway response.', 'wc-nmi' );
				return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_nmi_error_message', $error_message, $body ), $body );
			}

			if ( 2 === (int) $body['response'] ) {
				$error_message = '<!-- Error: ' . ( $body['response_code'] ?? '' ) . ' --> ' . __( 'Your card has been declined.', 'wc-nmi' );
				return new WP_Error( 'decline_response', apply_filters( 'woocommerce_nmi_error_message', $error_message, $body ), $body );
			}

			if ( 3 === (int) $body['response'] ) {
				$error_message = '<!-- Error: ' . ( $body['response_code'] ?? '' ) . ' --> ' . ( $body['response_text'] ?? '' );
				return new WP_Error( 'error_response', apply_filters( 'woocommerce_nmi_error_message', $error_message, $body ), $body );
			}

			return $body;
		}

		// Error data is the decoded error body, never the raw HTTP response.
		$error_data = is_array( $body ) ? $body : array( 'http_code' => $response_code );

		if ( 401 === $response_code ) {
			$error_message = $response_code . ' - ' . __( 'Unauthorized request. The merchant account credentials appear to be invalid.', 'wc-nmi' );
			return new WP_Error( $response_code, apply_filters( 'woocommerce_nmi_error_message', $error_message, $error_data ), $error_data );
		}

		if ( $response_code < 200 || $response_code >= 300 ) {
			if ( isset( $body['message'] ) ) {
				$error_message = ( $body['error_code'] ?? $body['errorCode'] ?? $response_code ) . ' - ' . $body['message'];

				$details = array();
				foreach ( (array) ( $body['details'] ?? array() ) as $detail ) {
					if ( ! empty( $detail['message'] ) ) {
						$details[] = $detail['message'];
					}
				}
				if ( $details ) {
					$error_message .= ' (' . implode( '; ', $details ) . ')';
				}
			} else {
				$error_message = $response_code . ' - ' . __( 'There was an error with the gateway response.', 'wc-nmi' );
			}
			return new WP_Error( $response_code, apply_filters( 'woocommerce_nmi_error_message', $error_message, $error_data ), $error_data );
		}

		// A success status needs a body to act on: an empty one must not mark an order paid or captured.
		if ( empty( $body ) || ! is_array( $body ) ) {
			$error_message = __( 'There was an error with the gateway response.', 'wc-nmi' );
			return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_nmi_error_message', $error_message, $error_data ), $error_data );
		}

		return $body;
	}

	/**
	 * Request args for the debug log, with the card number and CVV masked (Direct Post sends them).
	 *
	 * @param array $args
	 * @return array
	 */
	protected function get_loggable_args( $args ) {
		if ( ! empty( $args['payment_details']['card_number'] ) ) {
			$number = preg_replace( '/\D/', '', $args['payment_details']['card_number'] );
			$args['payment_details']['card_number'] = str_repeat( '*', max( 0, strlen( $number ) - 4 ) ) . substr( $number, -4 );
		}
		if ( ! empty( $args['payment_details']['card_cvv'] ) ) {
			$args['payment_details']['card_cvv'] = str_repeat( '*', strlen( $args['payment_details']['card_cvv'] ) );
		}
		return $args;
	}

	function get_line_items( $order ) {
		$line_items = array();
		// order line items
		foreach ( $order->get_items() as $item ) {
			$line_items[] = $item->get_name() . ' x ' .$item->get_quantity();
		}
		return implode( ', ', $line_items );
	}

	/**
	 * Refund a charge
	 * @param  int $order_id
	 * @param  float $amount
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() || $amount <= 0 ) {
			return false;
		}

		$this->log( "Info: Beginning refund for order $order_id for the amount of {$amount}" );

		$args = array(
			'amount' => wc_format_decimal( $amount, wc_get_price_decimals() ),
		);
		$args = apply_filters( 'wc_nmi_v5_request_args', $args, $order, 'refund' );

		$response = $this->nmi_request( 'POST:' . $order->get_transaction_id() . '/refund', $args );

		if ( ! is_wp_error( $response ) && empty( $response['id'] ) ) {
			$response = new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ), $response );
		}

		if ( is_wp_error( $response ) ) {
			$this->log( "Gateway Error: " . $response->get_error_message() );
			return $response;
		}

		// The refund is a new NMI transaction with its own ID.
		$refund_message = sprintf( __( 'Refunded %s - Refund ID: %s - Reason: %s', 'wc-nmi' ), wc_price( $args['amount'], array( 'currency' => $order->get_currency() ) ), $response['id'], $reason );
		$order->add_order_note( $refund_message );
		$order->save();

		$this->log( 'Success: ' . wp_strip_all_tags( $refund_message ) );
		return true;
	}

    public function http_request_timeout( $timeout_value ) {
		return 45; // 45 seconds. Too much for production, only for testing.
	}

	function get_card_type( $value, $field = 'pattern', $return = 'label' ) {
		$card_types = array(
			array(
				'label' => 'American Express',
				'name' => 'amex',
				'pattern' => '/^3[47]/',
				'valid_length' => '[15]'
			),
			array(
				'label' => 'JCB',
				'name' => 'jcb',
				'pattern' => '/^35(2[89]|[3-8][0-9])/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Discover',
				'name' => 'discover',
				'pattern' => '/^(6011|622(12[6-9]|1[3-9][0-9]|[2-8][0-9]{2}|9[0-1][0-9]|92[0-5]|64[4-9])|65)/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'MasterCard',
				'name' => 'mastercard',
				'pattern' => '/^5[1-5]/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Visa',
				'name' => 'visa',
				'pattern' => '/^4/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Maestro',
				'name' => 'maestro',
				'pattern' => '/^(5018|5020|5038|6304|6759|676[1-3])/',
				'valid_length' => '[12, 13, 14, 15, 16, 17, 18, 19]'
			),
			array(
				'label' => 'Diners Club',
				'name' => 'diners-club',
				'pattern' => '/^3[0689]/',
				'valid_length' => '[14]'
			),
		);

		foreach( $card_types as $type ) {
			$compare = $type[$field];
			if ( ( $field == 'pattern' && preg_match( $compare, $value, $match ) ) || $compare == $value ) {
				return $type[$return];
			}
		}

		return false;

	}

	/**
	 * Get payment currency, either from current order or WC settings
	 *
	 * @since 4.1.0
	 * @return string three-letter currency code
	 */
	function get_payment_currency( $order_id = false ) {
 		$currency = get_woocommerce_currency();
		$order_id = ! $order_id ? $this->get_checkout_pay_page_order_id() : $order_id;

 		// Gets currency for the current order, that is about to be paid for
 		if ( $order_id ) {
 			$order    = wc_get_order( $order_id );
 			$currency = $order->get_currency();
 		}
 		return $currency;
 	}

	/**
	 * Returns the order_id if on the checkout pay page
	 *
	 * @since 3.3
	 * @return int order identifier
	 */
	public function get_checkout_pay_page_order_id() {
		global $wp;
		return isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
	}

	/**
	 * Send the request to NMI's API
	 *
	 * @since 2.6.10
	 *
	 * @param string $message
	 */
	public function log( $message ) {
		if ( $this->logging ) {
			WC_NMI_Logger::log( $message );
		}
	}

	public function get_tokens() {
		return array();
	}

	public function get_log_url() {
		$log_file_name = WC_Log_Handler_File::get_log_file_name( 'woocommerce-gateway-nmi' );
		$name_array    = explode( '-', $log_file_name );
		if ( ! empty( $name_array[6] ) ) {
			unset( $name_array[6] );
		}
		return admin_url( 'admin.php?page=wc-status&tab=logs&view=single_file&file_id=' . implode( '-', $name_array ) );
	}

	public function get_params_google_pay() {
		return apply_filters( 'wc_nmi_params_google_pay', array(
			'gateway' => 'gatewayservices'
		) );
	}

	/**
	 * Whether the current page renders the checkout block, which has its own payment scripts.
	 *
	 * The pay page and Add payment method page still use the classic forms, even with the checkout block.
	 *
	 * @return bool
	 */
	public static function is_block_checkout_page() {
		if ( is_checkout_pay_page() || is_add_payment_method_page() || ! function_exists( 'has_block' ) ) {
			return false;
		}
		return has_block( 'woocommerce/checkout' );
	}

}
