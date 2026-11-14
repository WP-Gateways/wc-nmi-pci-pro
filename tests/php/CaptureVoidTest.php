<?php
namespace WCNMI\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WC_NMI_PCI;
use WCNMI\Tests\Fakes\FakeOrder;
use WP_Error;

/**
 * Capturing an authorization when the order status changes, and voiding it on cancellation, on NMI's v5 API.
 */
class CaptureVoidTest extends TestCase {

	/** @var FakeOrder */
	private $order;

	/** @var WC_Gateway_NMI The registered gateway. */
	private $nmi;

	protected function setUp(): void {
		parent::setUp();

		$this->order                 = new FakeOrder( array( '_nmi_charge_id' => '12803215126', '_nmi_charge_captured' => 'no' ) );
		$this->order->payment_method = 'nmi';
		$this->order->total          = '12.00';
		$this->order->transaction_id = '12803215126';
		$this->order->status         = 'on-hold';

		$this->nmi = $this->gateway( WC_Gateway_NMI::class, array(
			'id'                       => 'nmi',
			'testmode'                 => true,
			'private_key'              => 'test_private_key',
			'legacy_auth'              => false,
			'debugging'                => false,
			'capture_on_status_change' => true,
		) );

		$gateways = new class( array( 'nmi' => $this->nmi ) ) {
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

		Functions\when( 'wc_get_order' )->justReturn( $this->order );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'wc_price' )->alias( function ( $amount ) {
			return '$' . $amount;
		} );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_format_decimal' )->alias( function ( $number, $dp = false ) {
			return false === $dp ? (string) $number : number_format( (float) $number, $dp, '.', '' );
		} );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
	}

	private function plugin() {
		return $this->gateway( WC_NMI_PCI::class );
	}

	// -----------------------------------------------------------------------
	// Capture.
	// -----------------------------------------------------------------------

	public function test_the_registered_gateway_is_used(): void {
		// Building a new WC_Gateway_NMI registered its hooks and admin notices again.
		$this->assertSame( $this->nmi, $this->plugin()->get_gateway() );
	}

	public function test_an_authorization_is_captured_for_the_total_less_refunds(): void {
		$this->order->total_refunded = 2.5;
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'amount' => '9.50' ) ) ) );

		$this->plugin()->capture_payment( $this->order->get_id(), $this->order );

		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215126/capture', $this->nmi_requests[0]['url'] );
		$this->assertSame( array( 'amount' => '9.50' ), $this->nmi_requests[0]['body'] );
		$this->assertSame( 'yes', $this->order->saved_meta['_nmi_charge_captured'] );
		$this->assertSame( '12803215126', $this->order->saved_meta['NMI Payment ID'] );
		$this->assertSame( '12803215126', $this->order->get_transaction_id() );
		$this->assertStringContainsString( 'NMI charge captured for $9.50 (Charge ID: 12803215126).', end( $this->order->notes ) );
	}

	public function failed_capture_provider(): array {
		return array(
			'gateway error'      => array( array( 200, array( 'object' => 'transaction', 'id' => '12803215126', 'response' => '3', 'response_text' => 'Transaction already captured', 'response_code' => '300' ) ) ),
			'empty body'         => array( array( 200, '' ) ),
			'transaction, no id' => array( array( 200, array( 'object' => 'transaction', 'id' => '', 'response' => '1' ) ) ),
			'timeout'            => array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ),
		);
	}

	/**
	 * @dataProvider failed_capture_provider
	 */
	public function test_a_failed_capture_never_marks_the_order_captured( $response ): void {
		$this->fake_nmi( $response );

		$this->plugin()->capture_payment( $this->order->get_id(), $this->order );

		$this->assertSame( 'no', $this->order->get_meta( '_nmi_charge_captured' ) );
		$this->assertArrayNotHasKey( 'NMI Payment ID', $this->order->meta );
		$this->assertSame( 'failed', $this->order->get_status() );
		$this->assertSame( 'yes', $this->order->saved_meta['_nmi_capture_failed'] );
	}

	public function test_a_second_failed_capture_only_adds_a_note(): void {
		$this->order->meta['_nmi_capture_failed'] = 'yes';
		$this->order->status                      = 'processing';
		$this->fake_nmi( array( 200, '' ) );

		$this->plugin()->capture_payment( $this->order->get_id(), $this->order );

		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertStringContainsString( 'Unable to capture charge!', end( $this->order->notes ) );
	}

	public function not_captured_provider(): array {
		return array(
			'already captured'       => array( array( '_nmi_charge_captured' => 'yes' ), 'nmi', true ),
			'no charge'              => array( array( '_nmi_charge_id' => '' ), 'nmi', true ),
			'other payment method'   => array( array(), 'cod', true ),
			'capture on change off'  => array( array(), 'nmi', false ),
		);
	}

	/**
	 * @dataProvider not_captured_provider
	 */
	public function test_nothing_is_captured_when_not_needed( array $meta, string $payment_method, bool $capture_on_status_change ): void {
		$this->order->meta                     = array_merge( $this->order->meta, $meta );
		$this->order->payment_method           = $payment_method;
		$this->nmi->capture_on_status_change   = $capture_on_status_change;
		$this->fake_nmi();

		$this->plugin()->capture_payment( $this->order->get_id(), $this->order );

		$this->assertSame( array(), $this->nmi_requests );
	}

	public function test_the_v5_args_filter_gets_the_capture_endpoint(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );
		Filters\expectApplied( 'wc_nmi_v5_request_args' )->once()->with( array( 'amount' => '12.00' ), $this->order, 'capture' )->andReturnFirstArg();
		Filters\expectApplied( 'wc_nmi_request_args' )->never();

		$this->plugin()->capture_payment( $this->order->get_id(), $this->order );

		$this->assertCount( 1, $this->nmi_requests );
	}

	// -----------------------------------------------------------------------
	// Void.
	// -----------------------------------------------------------------------

	public function test_an_authorization_is_voided_with_an_empty_body(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'status' => 'canceled' ) ) ) );

		$this->plugin()->cancel_payment( $this->order->get_id() );

		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215126/void', $this->nmi_requests[0]['url'] );
		$this->assertSame( '{}', $this->nmi_requests[0]['raw_body'] );
		$this->assertArrayNotHasKey( '_nmi_charge_captured', $this->order->saved_meta );
		$this->assertArrayNotHasKey( '_nmi_charge_id', $this->order->saved_meta );
		$this->assertStringContainsString( '12803215126', end( $this->order->notes ) );
	}

	public function test_the_charge_id_is_voided_even_when_the_transaction_id_differs(): void {
		$this->order->transaction_id = '';
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'status' => 'canceled' ) ) ) );

		$this->plugin()->cancel_payment( $this->order->get_id() );

		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215126/void', $this->nmi_requests[0]['url'] );
	}

	public function test_a_failed_void_keeps_the_charge_and_adds_a_note(): void {
		$this->fake_nmi( array( 200, '' ) );

		$this->plugin()->cancel_payment( $this->order->get_id() );

		$this->assertSame( '12803215126', $this->order->get_meta( '_nmi_charge_id' ) );
		$this->assertStringContainsString( 'Unable to', end( $this->order->notes ) );
	}

	public function test_a_captured_charge_is_not_voided(): void {
		$this->order->meta['_nmi_charge_captured'] = 'yes';
		$this->fake_nmi();

		$this->plugin()->cancel_payment( $this->order->get_id() );

		$this->assertSame( array(), $this->nmi_requests );
	}
}
