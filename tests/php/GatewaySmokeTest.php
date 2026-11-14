<?php
namespace WCNMI\Tests;

/**
 * Smoke test: the PCI gateway loads and its basic helpers work without WordPress.
 */
class GatewaySmokeTest extends TestCase {

	public function test_card_type_is_detected_from_the_number() {
		$gateway = $this->gateway( 'WC_Gateway_NMI' );

		$this->assertSame( 'visa', $gateway->get_card_type( '4111111111111111', 'pattern', 'name' ) );
		$this->assertSame( 'mastercard', $gateway->get_card_type( '5555555555554444', 'pattern', 'name' ) );
	}

	public function test_gateway_supports_refunds() {
		$gateway = $this->gateway( 'WC_Gateway_NMI', array( 'supports' => array( 'products', 'refunds' ) ) );

		$this->assertTrue( $gateway->supports( 'refunds' ) );
		$this->assertFalse( $gateway->supports( 'subscriptions' ) );
	}
}
