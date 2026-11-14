<?php
namespace WCNMI\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WCNMI\Tests\Fakes\FakeOrder;
use WP_Error;

/**
 * process_refund() on NMI's v5 Payments API.
 */
class RefundTest extends TestCase {

	/** @var FakeOrder */
	private $order;

	protected function setUp(): void {
		parent::setUp();

		$this->order                 = new FakeOrder();
		$this->order->total          = '10.00';
		$this->order->transaction_id = '12803215416';

		Functions\when( 'wc_get_order' )->justReturn( $this->order );
		Functions\when( 'wc_price' )->alias( function ( $amount ) {
			return '$' . $amount;
		} );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_format_decimal' )->alias( function ( $number, $dp = false ) {
			return false === $dp ? (string) $number : number_format( (float) $number, $dp, '.', '' );
		} );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
	}

	private function nmi() {
		return $this->gateway( WC_Gateway_NMI::class, array(
			'id'          => 'nmi',
			'testmode'    => true,
			'private_key' => 'test_private_key',
			'legacy_auth' => false,
			'debugging'   => false,
		) );
	}

	public function test_a_refund_is_sent_for_the_charge_with_only_the_amount(): void {
		// NMI's refund response is a new transaction with its own ID and a negative amount.
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'id' => '12803215753', 'amount' => '-5.00', 'auth_code' => '' ) ) ) );

		$result = $this->nmi()->process_refund( $this->order->get_id(), '5.00', 'Damaged' );

		$this->assertTrue( $result );
		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215416/refund', $this->nmi_requests[0]['url'] );
		$this->assertSame( 'POST', $this->nmi_requests[0]['method'] );
		$this->assertSame( array( 'amount' => '5.00' ), $this->nmi_requests[0]['body'] );
		$this->assertSame( 'Refunded $5.00 - Refund ID: 12803215753 - Reason: Damaged', end( $this->order->notes ) );
	}

	public function test_the_refund_amount_is_rounded_to_the_currency_decimals(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'id' => '12803215753' ) ) ) );

		$this->nmi()->process_refund( $this->order->get_id(), 3.3300000000000001 );

		$this->assertSame( array( 'amount' => '3.33' ), $this->nmi_requests[0]['body'] );
	}

	public function test_a_declined_refund_returns_the_error(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'response' => '3', 'response_text' => 'Refund amount may not exceed the transaction balance', 'response_code' => '300' ) ) ) );

		$result = $this->nmi()->process_refund( $this->order->get_id(), '50.00' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'Refund amount may not exceed the transaction balance', $result->get_error_message() );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_a_refund_response_without_an_id_is_an_error(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'id' => '' ) ) ) );

		$result = $this->nmi()->process_refund( $this->order->get_id(), '5.00' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	public function nothing_to_refund_provider(): array {
		return array(
			'no transaction ID' => array( '', '5.00' ),
			'zero amount'       => array( '12803215416', '0' ),
			'negative amount'   => array( '12803215416', '-1.00' ),
		);
	}

	/**
	 * @dataProvider nothing_to_refund_provider
	 */
	public function test_nothing_is_sent_without_a_transaction_or_amount( string $transaction_id, string $amount ): void {
		$this->order->transaction_id = $transaction_id;
		$this->fake_nmi();

		$this->assertFalse( $this->nmi()->process_refund( $this->order->get_id(), $amount ) );
		$this->assertSame( array(), $this->nmi_requests );
	}

	public function test_the_v5_args_filter_gets_the_refund_endpoint(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'id' => '12803215753' ) ) ) );
		Filters\expectApplied( 'wc_nmi_v5_request_args' )->once()->with( array( 'amount' => '5.00' ), $this->order, 'refund' )->andReturnFirstArg();
		Filters\expectApplied( 'wc_nmi_request_args' )->never();

		$this->nmi()->process_refund( $this->order->get_id(), '5.00' );

		$this->assertCount( 1, $this->nmi_requests );
	}
}
