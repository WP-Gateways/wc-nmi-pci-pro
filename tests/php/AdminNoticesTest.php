<?php
namespace WCNMI\Tests;

use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WC_NMI_PCI;

/**
 * Admin notices after the move to NMI's v5 API.
 */
class AdminNoticesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
	}

	/**
	 * Run WC_NMI_PCI::check_environment() with these saved gateway settings and return the collected notices.
	 */
	private function environment_notices( $settings ): array {
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $settings ) {
			if ( 'wc_nmi_pci_version' === $name ) {
				return WC_NMI_PCI_VERSION; // No upgrade routine.
			}
			return 'woocommerce_nmi_settings' === $name ? $settings : $default;
		} );

		$plugin = $this->gateway( WC_NMI_PCI::class, array( 'notices' => array() ) );
		$plugin->check_environment();

		unset( $plugin->notices['bad_environment'] ); // WooCommerce isn't loaded in unit tests.
		return $plugin->notices;
	}

	public function test_a_username_password_site_gets_the_legacy_auth_error(): void {
		$notices = $this->environment_notices( array( 'enabled' => 'yes', 'api_keys' => 'no', 'username' => 'user', 'password' => 'pass' ) );

		$this->assertArrayHasKey( 'legacy_auth', $notices );
		$this->assertSame( 'notice notice-error', $notices['legacy_auth']['class'] );
		$this->assertStringContainsString( 'no longer accepts a gateway username and password', $notices['legacy_auth']['message'] );
		$this->assertStringContainsString( 'section=nmi', $notices['legacy_auth']['message'] );
		$this->assertArrayNotHasKey( 'prompt_connect', $notices );
	}

	public function test_a_disabled_username_password_site_gets_no_legacy_auth_error(): void {
		$notices = $this->environment_notices( array( 'enabled' => 'no', 'api_keys' => 'no', 'username' => 'user' ) );

		$this->assertArrayNotHasKey( 'legacy_auth', $notices );
	}

	public function test_a_site_without_a_private_key_is_prompted_for_its_keys(): void {
		$notices = $this->environment_notices( array( 'enabled' => 'yes', 'private_key' => '' ) );

		$this->assertArrayHasKey( 'prompt_connect', $notices );
		$this->assertArrayNotHasKey( 'legacy_auth', $notices );
	}

	public function test_a_configured_site_gets_no_setup_notices(): void {
		$notices = $this->environment_notices( array( 'enabled' => 'yes', 'private_key' => 'key', 'public_key' => 'pub' ) );

		$this->assertSame( array(), $notices );
	}

	public function test_code_still_using_the_old_args_filter_is_reported(): void {
		Functions\when( 'has_filter' )->alias( function ( $hook ) {
			return 'wc_nmi_request_args' === $hook;
		} );

		$notices = $this->environment_notices( array( 'enabled' => 'yes', 'private_key' => 'key' ) );

		$this->assertArrayHasKey( 'legacy_filters', $notices );
		$this->assertStringContainsString( 'wc_nmi_v5_request_args', $notices['legacy_filters']['message'] );
	}

	public function test_check_environment_reads_saved_settings_without_building_a_gateway(): void {
		// Building WC_Gateway_NMI here registered its hooks and notices a second time. Settings that aren't an
		// array (option missing) must not cause warnings either.
		$notices = $this->environment_notices( false );

		$this->assertArrayHasKey( 'prompt_connect', $notices );
	}

	// -----------------------------------------------------------------------
	// Gateway notices.
	// -----------------------------------------------------------------------

	private function gateway_notices( array $properties ): string {
		Functions\when( 'wc_checkout_is_https' )->justReturn( true );

		$gateway = $this->gateway( WC_Gateway_NMI::class, array_merge( array( 'enabled' => 'yes', 'legacy_auth' => false, 'public_key' => 'pub' ), $properties ) );

		ob_start();
		$gateway->admin_notices();
		return ob_get_clean();
	}

	public function test_the_gateway_asks_for_a_missing_private_key(): void {
		$output = $this->gateway_notices( array( 'private_key' => '' ) );

		$this->assertStringContainsString( 'Please enter your Private Key', $output );
		$this->assertStringNotContainsString( 'Username', $output );
		$this->assertStringNotContainsString( 'Password', $output );
	}

	public function test_the_gateway_leaves_username_password_sites_to_the_legacy_auth_notice(): void {
		$output = $this->gateway_notices( array( 'private_key' => '', 'legacy_auth' => true ) );

		$this->assertStringNotContainsString( 'Private Key', $output );
	}

	public function test_the_gateway_warns_when_direct_post_is_used(): void {
		// Without a Public Tokenization Key the card fields post through the site's server.
		$output = $this->gateway_notices( array( 'private_key' => 'key', 'public_key' => '' ) );

		$this->assertStringContainsString( 'Direct Post', $output );
		$this->assertStringContainsString( 'Public Tokenization Key', $output );
	}

	public function test_no_direct_post_warning_with_a_tokenization_key(): void {
		$output = $this->gateway_notices( array( 'private_key' => 'key', 'public_key' => 'pub' ) );

		$this->assertStringNotContainsString( 'Direct Post', $output );
	}

	public function test_gateway_notices_are_shown_once_from_the_registered_gateway(): void {
		// Every new WC_Gateway_NMI hooked admin_notices again, so the notices appeared twice.
		Functions\when( 'wc_checkout_is_https' )->justReturn( true );
		$registered = $this->gateway( WC_Gateway_NMI::class, array( 'enabled' => 'yes', 'legacy_auth' => false, 'private_key' => '', 'public_key' => 'pub' ) );
		$gateways   = new class( array( 'nmi' => $registered ) ) {
			private $gateways;
			public function __construct( $gateways ) {
				$this->gateways = $gateways;
			}
			public function payment_gateways() {
				return $this->gateways;
			}
		};
		Functions\when( 'WC' )->justReturn( new class( $gateways ) {
			private $gateways;
			public function __construct( $gateways ) {
				$this->gateways = $gateways;
			}
			public function payment_gateways() {
				return $this->gateways;
			}
		} );

		ob_start();
		WC_Gateway_NMI::display_admin_notices();
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'Please enter your Private Key' ) );
	}
}
