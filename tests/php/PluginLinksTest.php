<?php
namespace WCNMI\Tests;

use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WC_NMI_PCI;

/**
 * The Plugins screen links and the gateway settings page text.
 */
class PluginLinksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'plugin_basename' )->alias( function ( $file ) {
			return basename( dirname( $file ) ) . '/' . basename( $file );
		} );
		Functions\when( 'admin_url' )->alias( function ( $path ) {
			return 'https://shop.test/wp-admin/' . $path;
		} );
	}

	private function plugin(): WC_NMI_PCI {
		return ( new \ReflectionClass( WC_NMI_PCI::class ) )->newInstanceWithoutConstructor();
	}

	public function test_plugin_row_gets_a_support_link(): void {
		$links = $this->plugin()->plugin_row_meta( array( 'Version 1.0.0' ), plugin_basename( WC_NMI_PCI_MAIN_FILE ) );

		$this->assertCount( 2, $links );
		$this->assertSame( 'Version 1.0.0', $links[0] );
		$this->assertStringContainsString( 'href="https://pledgedplugins.com/support/"', $links[1] );
		$this->assertStringContainsString( '>Support</a>', $links[1] );
	}

	public function test_other_plugin_rows_are_left_alone(): void {
		$this->assertSame( array( 'Version 1.0' ), $this->plugin()->plugin_row_meta( array( 'Version 1.0' ), 'akismet/akismet.php' ) );
	}

	/**
	 * Under the plugin name: Settings only (on-site). Support is in the row meta.
	 */
	public function test_action_links_have_settings_but_not_support(): void {
		$links = $this->plugin()->plugin_action_links( array( 'deactivate' => 'Deactivate' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( '>Settings</a>', $links[0] );
		$this->assertSame( 'Deactivate', $links['deactivate'] );
		$this->assertStringNotContainsString( 'Support', implode( '', $links ) );
	}

	public function test_row_meta_filter_is_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'plugin_row_meta',\s*array\(\s*\\\$this,\s*'plugin_row_meta'\s*\),\s*10,\s*2\s*\)/",
			file_get_contents( WC_NMI_PCI_MAIN_FILE )
		);
	}

	/**
	 * WooCommerce's Payments list shows the description as plain text, so it is a plain-text summary: the
	 * upgrade pitch would run into it there.
	 */
	public function test_description_is_a_plain_text_summary(): void {
		$description = $this->gateway( WC_Gateway_NMI::class )->get_gateway_description();

		$this->assertStringContainsString( 'credit card', $description );
		$this->assertSame( strip_tags( $description ), $description );
		$this->assertStringNotContainsString( 'Upgrade', $description );
		$this->assertMatchesRegularExpression(
			'/\$this->method_description\s*=\s*\$this->get_gateway_description\(\);/',
			file_get_contents( WC_NMI_PCI_PLUGIN_PATH . '/includes/class-wc-gateway-nmi.php' )
		);
	}

	/**
	 * The settings page shows the upgrade pitch below the description, then leaves the description as it was.
	 */
	public function test_settings_page_adds_the_upgrade_pitch(): void {
		$gateway                     = $this->gateway( WC_Gateway_NMI::class );
		$gateway->method_title       = 'NMI';
		$gateway->method_description = 'NMI description.';

		ob_start();
		$gateway->admin_options();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<h2>NMI</h2>NMI description.<h3>Upgrade to Enterprise</h3>', $html );
		$this->assertStringContainsString( 'https://pledgedplugins.com/products/nmi-payment-gateway-woocommerce/', $html );
		$this->assertSame( 'NMI description.', $gateway->get_method_description() );
	}
}
