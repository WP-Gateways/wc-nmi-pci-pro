<?php
namespace WCNMI\Tests;

use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WC_NMI_PCI;

/**
 * Moving saved settings off the old gateway username/password, which NMI's v5 API doesn't accept.
 *
 * Saved states (todo.md, section 0):
 *  A. api_keys not 'no' and a private key: works; the stale username/password are deleted.
 *  B. api_keys 'no' (username/password) without a key: locked until a key is saved; credentials kept for a rollback.
 *  C. api_keys 'no' with a hidden stored key: locked too, until the merchant confirms the key and saves.
 */
class SettingsMigrationTest extends TestCase {

	// -----------------------------------------------------------------------
	// Which settings use the old username/password.
	// -----------------------------------------------------------------------

	public function legacy_settings_provider(): array {
		return array(
			'fresh install'              => array( array(), false ),
			'A: API keys'                => array( array( 'api_keys' => 'yes', 'private_key' => 'key' ), false ),
			'A: keys, no toggle saved'   => array( array( 'private_key' => 'key' ), false ),
			'B: username/password'       => array( array( 'api_keys' => 'no', 'username' => 'user', 'password' => 'pass' ), true ),
			'C: username/password + key' => array( array( 'api_keys' => 'no', 'username' => 'user', 'private_key' => 'key' ), true ),
		);
	}

	/**
	 * @dataProvider legacy_settings_provider
	 */
	public function test_settings_using_the_old_username_and_password_are_detected( array $settings, bool $legacy ): void {
		$this->assertSame( $legacy, WC_Gateway_NMI::settings_use_legacy_auth( $settings ) );
	}

	// -----------------------------------------------------------------------
	// Availability.
	// -----------------------------------------------------------------------

	public function availability_provider(): array {
		return array(
			'private key'                      => array( array( 'private_key' => 'key', 'legacy_auth' => false ), true ),
			'no private key'                   => array( array( 'private_key' => '', 'legacy_auth' => false ), false ),
			'username/password site'           => array( array( 'private_key' => '', 'legacy_auth' => true ), false ),
			'username/password site with key'  => array( array( 'private_key' => 'key', 'legacy_auth' => true ), false ),
		);
	}

	/**
	 * @dataProvider availability_provider
	 */
	public function test_the_gateway_is_only_available_with_a_usable_private_key( array $properties, bool $available ): void {
		Functions\when( 'is_add_payment_method_page' )->justReturn( false );

		$gateway = $this->gateway( WC_Gateway_NMI::class, array_merge( array( 'enabled' => 'yes' ), $properties ) );

		$this->assertSame( $available, $gateway->is_available() );
	}

	public function test_the_settings_no_longer_have_username_password_fields(): void {
		Functions\when( 'admin_url' )->returnArg();

		$gateway = $this->gateway( WC_Gateway_NMI::class );
		$gateway->init_form_fields();

		foreach ( array( 'api_keys', 'username', 'password' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $gateway->form_fields );
		}
		$this->assertArrayHasKey( 'private_key', $gateway->form_fields );
		$this->assertArrayHasKey( 'public_key', $gateway->form_fields );
	}

	// -----------------------------------------------------------------------
	// Saving the settings.
	// -----------------------------------------------------------------------

	public function test_saving_a_private_key_removes_the_old_credentials_and_lifts_the_lock(): void {
		$saved = null;
		Functions\when( 'update_option' )->alias( function ( $name, $value ) use ( &$saved ) {
			$saved = array( $name, $value );
			return true;
		} );

		$gateway = $this->gateway( WC_Gateway_NMI::class, array(
			'id'          => 'nmi',
			'legacy_auth' => true,
			'settings'    => array( 'enabled' => 'yes', 'api_keys' => 'no', 'username' => 'user', 'password' => 'pass', 'private_key' => 'new_key' ),
		) );

		$gateway->process_admin_options();

		$this->assertSame( 'woocommerce_nmi_settings', $saved[0] );
		$this->assertSame( array( 'enabled' => 'yes', 'private_key' => 'new_key' ), $saved[1] );
		$this->assertFalse( $gateway->legacy_auth );
	}

	public function test_saving_without_a_private_key_keeps_the_old_credentials(): void {
		// A merchant who rolls back to the previous version still has a working username/password.
		Functions\expect( 'update_option' )->never();

		$gateway = $this->gateway( WC_Gateway_NMI::class, array(
			'id'          => 'nmi',
			'legacy_auth' => true,
			'settings'    => array( 'enabled' => 'yes', 'api_keys' => 'no', 'username' => 'user', 'password' => 'pass', 'private_key' => '' ),
		) );

		$gateway->process_admin_options();

		$this->assertTrue( $gateway->legacy_auth );
	}

	// -----------------------------------------------------------------------
	// Upgrade routine.
	// -----------------------------------------------------------------------

	private function run_install( array $settings ) {
		$saved = null;
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( $settings ) {
			return 'woocommerce_nmi_settings' === $name ? $settings : $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $name, $value ) use ( &$saved ) {
			if ( 'woocommerce_nmi_settings' === $name ) {
				$saved = $value;
			}
			return true;
		} );
		Functions\when( 'delete_option' )->justReturn( true );

		$this->gateway( WC_NMI_PCI::class )->install();

		return $saved;
	}

	public function test_install_deletes_stale_credentials_when_api_keys_are_used(): void {
		$saved = $this->run_install( array( 'enabled' => 'yes', 'api_keys' => 'yes', 'private_key' => 'key', 'username' => 'user', 'password' => 'pass' ) );

		$this->assertSame( array( 'enabled' => 'yes', 'private_key' => 'key' ), $saved );
	}

	public function test_install_keeps_the_credentials_of_a_username_password_site(): void {
		$settings = array( 'enabled' => 'yes', 'api_keys' => 'no', 'username' => 'user', 'password' => 'pass' );

		$saved = $this->run_install( $settings );

		$this->assertSame( $settings, $saved );
	}
}
