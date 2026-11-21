<?php
/*
Plugin Name: Secure PCI Gateway for NMI and WooCommerce
Plugin URI: https://github.com/WP-Gateways/wc-nmi-pci-pro
Description: A PCI compliant payment gateway for NMI. An NMI account and a server with cURL, SSL support, and a valid SSL certificate is required (for security reasons) for this gateway to function. Requires WC 3.3+
Version: 2.0.0
Author: Pledged Plugins
Author URI: https://pledgedplugins.com
Text Domain: wc-nmi
Domain Path: /languages
Requires at least: 4.7
Requires PHP: 7.0
WC requires at least: 3.3
WC tested up to: 11.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce

	Copyright: © Pledged Plugins.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_NMI_PCI_VERSION', '2.0.0' );
define( 'WC_NMI_PCI_TEMPLATE_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/' );
define( 'WC_NMI_PCI_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_NMI_PCI_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_NMI_PCI_MAIN_FILE', __FILE__ );

/**
 * Main NMI class which sets the gateway up for us
 */
class WC_NMI_PCI {

	/**
     * @var WC_NMI_PCI Singleton The reference the *Singleton* instance of this class
     */
    private static $instance;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return WC_NMI_PCI Singleton The *Singleton* instance.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Notices (array)
     * @var array
	 */
    public $notices = array();

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action( 'before_woocommerce_init', function() {
			// Declaring HPOS feature compatibility
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
			// Declaring cart and checkout blocks compatibility
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		} );

		add_action( 'admin_init', array( $this, 'check_environment' ), 11 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
		add_action( 'plugins_loaded', array( $this, 'init_environment' ), 11 );
	}

	/**
	 * NMI's integration with the WooCommerce Apple Pay and Google Pay extension (wcapgp_processor_integration filter).
	 *
	 * @param WCAPGP_Processor_Integration|null $integration Integration found so far.
	 * @param object                            $gateway     Processor gateway.
	 * @param string                            $wallet_id   Wallet gateway ID.
	 * @return WCAPGP_Processor_Integration|null
	 */
	public static function wallet_integration( $integration, $gateway, $wallet_id ) {
		if ( ! $gateway instanceof WC_Gateway_NMI || ! interface_exists( 'WCAPGP_Processor_Integration' ) ) {
			return $integration;
		}

		require_once WC_NMI_PCI_PLUGIN_PATH . '/includes/class-wc-nmi-wallet-integration.php';

		return new WC_NMI_Wallet_Integration( $gateway );
	}

	public function settings_url() {
        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi' );
    }

	/**
	 * Add relevant links to plugins page
	 * @param  array $links
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi' ) . '">' . __( 'Settings', 'wc-nmi' ) . '</a>',
			'<a href="https://pledgedplugins.com/support/" target="_blank">' . __( 'Support', 'wc-nmi' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	/**
     * Initialize the gateway. Called very early - in the context of the plugins_loaded action
     *
     * @since 1.0.0
     */
    public function init_gateways() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        // Includes
		if ( is_admin() ) {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-nmi-privacy.php' );
		}

        include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-nmi.php' );

		// Hooked once here: the gateway can be constructed several times per request.
		add_action( 'admin_notices', array( 'WC_Gateway_NMI', 'display_admin_notices' ) );

        add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );

    }

	/**
	 * Register the gateway for use
	 */
	public function add_gateways( $methods ) {
		$methods[] = 'WC_Gateway_NMI';
		return $methods;
	}

	/**
	 * Init localisations and files
	 */
	public function init_environment() {

        if( class_exists( 'WC_NMI_License_Updates' ) ) {
            return;
        }

        // Init the gateway itself
        $this->init_gateways();

        require_once( 'includes/class-wc-gateway-nmi-logger.php' );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ), 11 );
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
		add_filter( 'wcapgp_processor_integration', array( __CLASS__, 'wallet_integration' ), 10, 3 );

		if( version_compare( WC_VERSION, '8.4.0', '<' ) ) {
			add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ), 10, 2 );
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ), 10, 2 );
		} else {
			add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ), 10, 3 );
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ), 10, 3 );
		}

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );

	}

	/**
     * Allow this class and other classes to add slug keyed notices (to avoid duplication)
     */
    public function add_admin_notice( $slug, $class, $message ) {
        $this->notices[ $slug ] = array(
            'class'   => $class,
            'message' => $message,
        );
    }

	/**
     * The backup sanity check, in case the plugin is activated in a weird way,
     * or the environment changes after activation. Also handles upgrade routines.
     */
    public function check_environment() {
        $environment_warning = self::get_environment_warning();

        if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
            $this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
            return;
        }

        if ( ! defined( 'IFRAME_REQUEST' ) && ( WC_NMI_PCI_VERSION !== get_option( 'wc_nmi_pci_version', '3.0.0' ) ) ) {
            $this->install();

            do_action( 'woocommerce_nmi_updated' );
        }

		if ( ! class_exists( 'WC_Gateway_NMI' ) ) {
			return;
		}

		$settings = get_option( 'woocommerce_nmi_settings' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$legacy_auth = WC_Gateway_NMI::settings_use_legacy_auth( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
		$setting_link = esc_url( $this->settings_url() );

		if ( $legacy_auth ) {
			$this->add_admin_notice( 'legacy_auth', 'notice notice-error', sprintf( __( 'NMI needs your attention. This version uses NMI\'s new Payments API, which no longer accepts a gateway username and password. NMI is switched off at checkout, and captures, voids and refunds will fail, until you enter your Private Key (and Public Tokenization Key) and save the settings. You can create the keys in your NMI account under Settings > Security Keys. <a href="%s">Open NMI settings</a>', 'wc-nmi' ), $setting_link ) );
		}

		$setting_prompt = empty( $settings['private_key'] ) && ! $legacy_auth;
		if ( $setting_prompt && ! ( isset( $_GET['page'], $_GET['section'] ) && 'wc-settings' === $_GET['page'] && 'nmi' === $_GET['section'] ) ) {
			$this->add_admin_notice( 'prompt_connect', 'notice notice-warning', sprintf( __( 'NMI is almost ready. To get started, <a href="%s">set your NMI account keys</a>.', 'wc-nmi' ), $setting_link ) );
		}

		if ( has_filter( 'wc_nmi_request_args' ) ) {
			$this->add_admin_notice( 'legacy_filters', 'notice notice-warning', __( 'Custom code on this site uses the wc_nmi_request_args filter, which NMI no longer runs: request fields changed with NMI\'s new Payments API. Move that code to the wc_nmi_v5_request_args filter.', 'wc-nmi' ) );
		}
    }

	/**
     * Updates the plugin version in db
     *
     * @since 3.1.0
     * @version 3.1.0
     * @return bool
     */
    private static function _update_plugin_version() {
        delete_option( 'wc_nmi_pci_version' );
        update_option( 'wc_nmi_pci_version', WC_NMI_PCI_VERSION );

        return true;
    }

	/**
     * Handles upgrade routines.
     *
     * @since 3.1.0
     * @version 3.1.0
     */
    public function install() {
        if ( ! defined( 'WC_NMI_INSTALLING' ) ) {
            define( 'WC_NMI_INSTALLING', true );
        }

		$main_settings = get_option( 'woocommerce_nmi_settings' );
		if ( ! is_array( $main_settings ) ) {
			$main_settings = array();
		}
		if( !isset( $main_settings['api_keys'] ) && isset( $main_settings['username'] ) && !empty( $main_settings['username'] ) ) {
			$main_settings['api_keys'] = 'no';
		}
		// The old username/password aren't used any more once the site authenticates with API keys.
		if ( ! isset( $main_settings['api_keys'] ) || 'no' !== $main_settings['api_keys'] ) {
			unset( $main_settings['username'], $main_settings['password'], $main_settings['api_keys'] );
		}
		update_option( 'woocommerce_nmi_settings', $main_settings );

		$this->_update_plugin_version();
    }

    /**
     * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
     * found or false if the environment has no problems.
     */
    static function get_environment_warning() {

        if ( ! defined( 'WC_VERSION' ) ) {
            return __( 'WooCommerce NMI extension requires WooCommerce to be activated to work.', 'wc-nmi' );
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return __( 'WooCommerce NMI - cURL is not installed.', 'wc-nmi' );
        }

        if( class_exists( 'WC_NMI_License_Updates' ) ) {
            return __( 'There are two WooCommerce NMI extensions installed from Pledged Plugins which can cause conflicts. Please deactivate one of them.', 'wc-nmi' );
        }

        return false;
    }

    /**
	 * Display any notices we've collected thus far (e.g. for connection, disconnection)
	 */
	public function admin_notices() {

		foreach ( (array) $this->notices as $notice ) {
			echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
			echo '</p></div>';
		}
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'wc-nmi', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * The registered NMI gateway, which has the latest settings, or a new one if WooCommerce hasn't loaded it.
	 *
	 * Creating a gateway registers its hooks and admin notices, so the registered one is used when it exists.
	 *
	 * @return WC_Gateway_NMI
	 */
	public function get_gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways['nmi'] ) && $gateways['nmi'] instanceof WC_Gateway_NMI ) {
			return $gateways['nmi'];
		}

		return new WC_Gateway_NMI();
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param int $order_id
	 * @param $order
	 * @param $status_transition
	 */
	public function capture_payment( $order_id, $order, $status_transition = array() ) {

		if ( $order->get_payment_method() == 'nmi' ) {
			$charge   = $order->get_meta( '_nmi_charge_id' );
			$captured = $order->get_meta( '_nmi_charge_captured' );

			$gateway = $this->get_gateway();

			if ( apply_filters( 'wc_nmi_capture_on_status_change', $gateway->capture_on_status_change, $order, $status_transition ) && $charge && $captured == 'no' ) {

				$gateway->log( "Info: Beginning capture payment for order $order_id for the amount of {$order->get_total()}" );

				$order_total = $order->get_total();

				if ( 0 < $order->get_total_refunded() ) {
					$order_total = $order_total - $order->get_total_refunded();
				}

				$args = array(
					'amount' => wc_format_decimal( $order_total, wc_get_price_decimals() ),
				);
				$args = apply_filters( 'wc_nmi_v5_request_args', $args, $order, 'capture' );

				$response = $gateway->nmi_request( "POST:{$charge}/capture", $args );

				// Only a response with the transaction ID is a capture: anything else must not mark the order captured.
				if ( ! is_wp_error( $response ) && empty( $response['id'] ) ) {
					$response = new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ), $response );
				}

				if ( is_wp_error( $response ) ) {
					if( $order->get_meta( '_nmi_capture_failed' ) == 'yes' ) {
						$order->add_order_note( sprintf( __( "<strong>Unable to capture charge!</strong> Please <strong>DO NOT FULFIL THE ORDER</strong> if the amount cannot be captured in the gateway account manually or by changing the status. In that case, set status to Failed manually and do not fulfil. \n\nNMI failure reason: %s \n\n", 'wc-nmi' ), $response->get_error_message() ) );
					} else {
						$order->update_status( 'failed', sprintf( __( "<strong>Unable to capture charge!</strong> The order status is set to <strong>Failed</strong> the first time to draw your attention. If the next attempt fails, your intended order status will still take place. \n\nPlease double-check that the amount is captured in the gateway account before fulfilling the order. \n\nNMI failure reason: %s \n\n", 'wc-nmi' ), $response->get_error_message() ) );
						$order->update_meta_data( '_nmi_capture_failed', 'yes' );
						$order->save();
					}
				} else {
					$complete_message = sprintf( __( 'NMI charge captured for %s (Charge ID: %s).', 'wc-nmi' ), wc_price( $args['amount'], array( 'currency' => $order->get_currency() ) ), $response['id'] );
					$order->add_order_note( $complete_message );
					$gateway->log( 'Success: ' . wp_strip_all_tags( $complete_message ) );

					$order->update_meta_data( '_nmi_charge_captured', 'yes' );
					$order->update_meta_data( 'NMI Payment ID', $response['id'] );

					$order->set_transaction_id( $response['id'] );
					$order->save();
				}

			}
		}
	}

	/**
	 * Cancel pre-auth on refund/cancellation
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( $order->get_payment_method() == 'nmi' ) {
			$charge	  = $order->get_meta( '_nmi_charge_id' ) ? $order->get_meta( '_nmi_charge_id' ) : $order->get_transaction_id();
			$captured = $order->get_meta( '_nmi_charge_captured' );

			if ( $charge && $captured != 'yes' ) {

				$gateway = $this->get_gateway();

				$gateway->log( "Info: Beginning cancel payment for order $order_id for the amount of {$order->get_total()}" );

				$args = apply_filters( 'wc_nmi_v5_request_args', array(), $order, 'void' );

				$response = $gateway->nmi_request( "POST:{$charge}/void", $args );

				if ( ! is_wp_error( $response ) && empty( $response['id'] ) ) {
					$response = new WP_Error( 'invalid_response', __( 'There was an error with the gateway response.', 'wc-nmi' ), $response );
				}

				if ( is_wp_error( $response ) ) {
					$order->add_order_note( __( 'Unable to refund charge!', 'wc-nmi' ) . ' ' . $response->get_error_message() );
				} else {
					$cancel_message = sprintf( __( 'NMI charge refunded (Charge ID: %s).', 'wc-nmi' ), $response['id'] );
					$order->add_order_note( $cancel_message );
					$gateway->log( "Success: $cancel_message" );

					$order->delete_meta_data( '_nmi_charge_captured' );
					$order->delete_meta_data( '_nmi_charge_id' );

					$order->save();
				}
			}

		}
	}

}
$GLOBALS['wc_nmi_pci'] = WC_NMI_PCI::get_instance();

// Hook in Blocks integration. This action is called in a callback on plugins loaded, so current NMI plugin class
// implementation is too late.
add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_nmi_woocommerce_block_support' );

function woocommerce_gateway_nmi_woocommerce_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once dirname( __FILE__ ) . '/includes/class-wc-nmi-blocks-support.php';
		// priority is important here because this ensures this integration is
		// registered before the WooCommerce Blocks built-in NMI registration.
		// Blocks code has a check in place to only register if 'nmi' is not
		// already registered.
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {

				$container = Automattic\WooCommerce\Blocks\Package::container();
				// registers as shared instance.
				$container->register(
					WC_NMI_PCI_Blocks_Support::class,
					function() {
						return new WC_NMI_PCI_Blocks_Support();
					}
				);
				$payment_method_registry->register(
					$container->get( WC_NMI_PCI_Blocks_Support::class )
				);
			},
			5
		);
	}
}
