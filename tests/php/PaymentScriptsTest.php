<?php
namespace WCNMI\Tests;

use Brain\Monkey\Functions;
use WC_Gateway_NMI;

/**
 * Where the classic checkout loads Collect.js and nmi.js.
 */
class PaymentScriptsTest extends TestCase {

	private $enqueued = array();

	protected function setUp(): void {
		parent::setUp();

		$this->enqueued = array();
		$enqueued       = &$this->enqueued;
		Functions\when( 'wp_enqueue_script' )->alias( function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		} );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_add_payment_method_page' )->justReturn( false );
		Functions\when( 'is_checkout_pay_page' )->justReturn( false );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'has_block' )->justReturn( false );
	}

	private function nmi() {
		return $this->gateway( WC_Gateway_NMI::class, array( 'public_key' => 'pub', 'testmode' => true ) );
	}

	public function test_the_classic_checkout_loads_collect_js_and_the_checkout_script(): void {
		$gateway = $this->getMockBuilder( WC_Gateway_NMI::class )->disableOriginalConstructor()->onlyMethods( array( 'javascript_params' ) )->getMock();
		$gateway->method( 'javascript_params' )->willReturn( array() );
		$gateway->public_key = 'pub';
		$gateway->testmode   = true;

		$gateway->payment_scripts();

		$this->assertSame( array( 'nmi-collect-js', 'woocommerce_nmi' ), $this->enqueued );
	}

	public function test_nothing_is_loaded_on_the_order_received_page(): void {
		// is_checkout() is true on the order received endpoint, but there's nothing to pay there.
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$this->nmi()->payment_scripts();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_nothing_is_loaded_on_a_block_checkout_page(): void {
		Functions\when( 'has_block' )->justReturn( true );

		$this->nmi()->payment_scripts();

		$this->assertSame( array(), $this->enqueued );
	}
}
