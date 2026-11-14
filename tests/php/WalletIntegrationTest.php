<?php
namespace WCNMI\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WC_NMI_PCI;
use WC_NMI_Wallet_Integration;
use WCAPGP_Processor_Integration;
use WCAPGP_Wallet_Payment;

/**
 * NMI as the processor for the WooCommerce Apple Pay and Google Pay extension.
 *
 * NMI supplies its own WCAPGP_Processor_Integration through the extension's wcapgp_processor_integration
 * filter, instead of the extension's legacy one (see wc-apple-pay-google-pay/docs/INTEGRATION.md).
 * Ported from the Enterprise plugin; Pro has no subscriptions, so it doesn't offer wallets for them.
 */
class WalletIntegrationTest extends TestCase {

	private const WALLET_TOKEN = '{"signature":"TEST","protocolVersion":"ECv2"}';

	protected function tearDown(): void {
		// Count Brain Monkey's hook expectations as assertions.
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	private function card_gateway( array $properties = array() ): WC_Gateway_NMI {
		return $this->gateway( WC_Gateway_NMI::class, array_merge( array(
			'id'          => 'nmi',
			'enabled'     => 'yes',
			'private_key' => 'secret',
			'legacy_auth' => false,
			'supports'    => array( 'products', 'refunds', 'wcapgp_apple_pay', 'wcapgp_google_pay' ),
		), $properties ) );
	}

	private function integration( WC_Gateway_NMI $gateway ): WC_NMI_Wallet_Integration {
		$integration = WC_NMI_PCI::wallet_integration( null, $gateway, 'wcapgp_google_pay' );
		$this->assertInstanceOf( WC_NMI_Wallet_Integration::class, $integration );

		return $integration;
	}

	public function test_nmi_supplies_its_own_integration(): void {
		$gateway     = $this->card_gateway();
		$integration = $this->integration( $gateway );

		$this->assertInstanceOf( WCAPGP_Processor_Integration::class, $integration );
		$this->assertSame( $gateway, $integration->get_gateway() );
	}

	public function test_other_gateways_keep_their_integration(): void {
		$other = new \stdClass();

		$this->assertNull( WC_NMI_PCI::wallet_integration( null, $other, 'wcapgp_google_pay' ) );
		$this->assertSame( 'theirs', WC_NMI_PCI::wallet_integration( 'theirs', $other, 'wcapgp_google_pay' ) );
	}

	public function test_the_plugin_registers_the_integration(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'plugin_basename' )->returnArg();
		if ( ! defined( 'WC_VERSION' ) ) {
			define( 'WC_VERSION', '10.0.0' );
		}
		Filters\expectAdded( 'wcapgp_processor_integration' )->once()->with( array( WC_NMI_PCI::class, 'wallet_integration' ), 10, 3 );

		$this->gateway( WC_NMI_PCI::class )->init_environment();
	}

	public function test_it_is_ready_with_a_private_key_even_when_the_card_form_is_off(): void {
		$this->assertTrue( $this->integration( $this->card_gateway() )->is_ready( 'wcapgp_google_pay' ) );
		$this->assertTrue( $this->integration( $this->card_gateway( array( 'enabled' => 'no' ) ) )->is_ready( 'wcapgp_apple_pay' ) );
	}

	public function test_it_is_not_ready_without_a_usable_private_key(): void {
		$this->assertFalse( $this->integration( $this->card_gateway( array( 'private_key' => '' ) ) )->is_ready( 'wcapgp_google_pay' ) );
		$this->assertFalse( $this->integration( $this->card_gateway( array( 'legacy_auth' => true ) ) )->is_ready( 'wcapgp_google_pay' ) );
	}

	public function test_wallets_are_not_offered_for_subscriptions(): void {
		$this->assertFalse( $this->integration( $this->card_gateway() )->supports_subscriptions() );
	}

	public function test_google_pay_tokenizes_for_nmi(): void {
		$this->assertSame( array( 'gateway' => 'gatewayservices' ), $this->integration( $this->card_gateway() )->get_google_pay_params() );
	}

	public function test_apple_merchant_validation_uses_the_extensions_certificate(): void {
		$this->assertNull( $this->integration( $this->card_gateway() )->validate_apple_merchant( 'https://apple-pay-gateway.apple.com/paymentservices/startSession' ) );
	}

	public function test_the_wallet_payment_reaches_nmi_without_post_data(): void {
		$gateway = new class() extends WC_Gateway_NMI {
			public $seen = array();

			public function __construct() {}

			public function process_payment( $order_id ) {
				$this->seen[] = array( $order_id, $this->get_posted_wallet_payment_details() );
				return array( 'result' => 'success' );
			}
		};
		$gateway->id = 'nmi';

		$result = $this->integration( $gateway )->process_payment(
			new WCAPGP_Wallet_Payment( 'wcapgp_apple_pay', base64_encode( self::WALLET_TOKEN ) ),
			15
		);

		$this->assertSame( array( 'result' => 'success' ), $result );
		$this->assertSame( array( array( 15, array( 'applepay_payment_data' => self::WALLET_TOKEN ) ) ), $gateway->seen );
		// Only for that payment.
		$this->assertNull( $gateway->get_posted_wallet_payment_details() );
	}

	public function test_the_wallet_payment_is_forgotten_when_nmi_throws(): void {
		$gateway = new class() extends WC_Gateway_NMI {
			public function __construct() {}

			public function process_payment( $order_id ) {
				throw new \Exception( 'Declined' );
			}
		};
		$gateway->id = 'nmi';

		try {
			$this->integration( $gateway )->process_payment( new WCAPGP_Wallet_Payment( 'wcapgp_google_pay', base64_encode( self::WALLET_TOKEN ) ), 15 );
			$this->fail( 'The exception should reach the extension.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Declined', $e->getMessage() );
		}

		$this->assertNull( $gateway->get_posted_wallet_payment_details() );
	}
}
