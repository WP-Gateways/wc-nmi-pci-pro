<?php
namespace WCNMI\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WCNMI\Tests\Fakes\FakeOrder;

/**
 * process_payment() on NMI's v5 Payments API: Collect.js tokens, Direct Post card fields and
 * Apple Pay / Google Pay data from the wallet extension.
 */
class ProcessPaymentTest extends TestCase {

	/** @var FakeOrder */
	private $order;

	/** @var array Notices added with wc_add_notice(). */
	private $notices = array();

	/** @var bool */
	private $cart_emptied = false;

	protected function setUp(): void {
		parent::setUp();

		$this->order               = new FakeOrder();
		$this->order->total        = '10.00';
		$this->order->total_tax    = '0.50';
		$this->order->shipping_total = '2.00';
		$this->order->order_number = '1001';
		$this->order->address      = array(
			'billing_first_name' => 'Jane',
			'billing_last_name'  => 'Buyer',
			'billing_company'    => '',
			'billing_address_1'  => '1 Main St',
			'billing_address_2'  => '',
			'billing_city'       => 'Austin',
			'billing_state'      => 'TX',
			'billing_country'    => 'US',
			'billing_postcode'   => '77777',
			'billing_email'      => 'jane@example.com',
			'billing_phone'      => '5551234567',
		);

		$order = $this->order;
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Shop' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'wc_price' )->alias( function ( $amount ) {
			return '$' . $amount;
		} );
		Functions\when( 'wc_reduce_stock_levels' )->justReturn( null );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$this->notices = array();
		$notices       = &$this->notices;
		Functions\when( 'wc_add_notice' )->alias( function ( $message ) use ( &$notices ) {
			$notices[] = $message;
		} );

		$this->cart_emptied = false;
		$emptied            = &$this->cart_emptied;
		$cart               = new class( $emptied ) {
			private $emptied;
			public function __construct( &$emptied ) {
				$this->emptied = &$emptied;
			}
			public function empty_cart() {
				$this->emptied = true;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	private function nmi( array $properties = array() ) {
		return $this->gateway( WC_Gateway_NMI::class, array_merge( array(
			'id'                 => 'nmi',
			'testmode'           => true,
			'private_key'        => 'test_private_key',
			'legacy_auth'        => false,
			'capture'            => true,
			'line_items'         => false,
			'customer_receipt'   => true,
			'debugging'          => false,
			'allowed_card_types' => array( 'visa', 'mastercard', 'discover', 'amex' ),
		), $properties ) );
	}

	private function post_collect_js_token( array $card = array( 'number' => '411111******1111', 'type' => 'visa' ) ) {
		$_POST['nmi_js_response'] = json_encode( array( 'token' => 'tok_123', 'card' => $card ) );
	}

	// -----------------------------------------------------------------------
	// Payment details.
	// -----------------------------------------------------------------------

	public function test_a_collect_js_token_is_charged_as_a_sale(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$request = $this->nmi_requests[0];
		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/sale', $request['url'] );
		$this->assertSame( array( 'payment_token' => 'tok_123' ), $request['body']['payment_details'] );

		$this->assertSame( 'success', $result['result'] );
		$this->assertTrue( $this->order->paid );
		$this->assertTrue( $this->cart_emptied );
		$this->assertSame( '12803215126', $this->order->get_transaction_id() );
		$this->assertSame( '12803215126', $this->order->saved_meta['_nmi_charge_id'] );
		$this->assertSame( '123456', $this->order->saved_meta['_nmi_authorization_code'] );
		$this->assertSame( 'yes', $this->order->saved_meta['_nmi_charge_captured'] );
		$this->assertSame( '12803215126', $this->order->saved_meta['NMI Payment ID'] );
		$this->assertSame( '1111', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( 'visa', $this->order->saved_meta['_nmi_card_type'] );
		$this->assertStringContainsString( 'NMI charge completed for $10.00 (Charge ID: 12803215126).', end( $this->order->notes ) );
	}

	public function test_the_card_is_read_from_lookup_data_when_the_token_has_none(): void {
		// Collect.js sometimes puts the card under lookupData only.
		$_POST['nmi_js_response'] = json_encode( array(
			'token'      => 'tok_123',
			'lookupData' => array( 'card' => array( 'number' => '555555******4444', 'type' => 'mastercard' ) ),
		) );
		// A response without card details, so the stored card comes from the token.
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'payment_details' => array() ) ) ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( '4444', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( 'mastercard', $this->order->saved_meta['_nmi_card_type'] );
	}

	public function test_a_collect_js_card_of_a_disallowed_type_is_refused_before_charging(): void {
		// The checkout script checks the card type too, but that can be bypassed.
		$this->post_collect_js_token( array( 'number' => '378282*****0005', 'type' => 'amex' ) );
		$this->fake_nmi();

		$result = $this->nmi( array( 'allowed_card_types' => array( 'visa' ) ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( array(), $this->nmi_requests );
		$this->assertStringContainsString( 'Card Type Not Accepted', $this->notices[0] );
	}

	public function test_a_disallowed_card_behind_an_edited_token_is_voided_and_refused(): void {
		// The posted Collect.js JSON can be edited in the browser; NMI's response has the real card type.
		$this->post_collect_js_token( array( 'number' => '411111******1111', 'type' => 'visa' ) );
		$this->fake_nmi(
			array( 200, $this->nmi_transaction( array( 'payment_details' => array( 'card_number' => '378282*****0005', 'card_type' => 'American Express', 'card_bin' => '378282' ) ) ) ),
			array( 200, $this->nmi_transaction( array( 'status' => 'canceled' ) ) )
		);

		$result = $this->nmi( array( 'allowed_card_types' => array( 'visa' ) ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertCount( 2, $this->nmi_requests );
		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215126/void', $this->nmi_requests[1]['url'] );
		$this->assertFalse( $this->order->paid );
		$this->assertSame( 'failed', $this->order->get_status() );
		$this->assertSame( '', $this->order->get_transaction_id() );
		$this->assertStringContainsString( 'Card Type Not Accepted', $this->notices[0] );
	}

	public function test_a_disallowed_card_that_cannot_be_voided_keeps_the_charge_on_hold_for_review(): void {
		// The shopper was charged: the order keeps the charge so it can be refunded, and isn't left for a second payment.
		$this->post_collect_js_token( array( 'number' => '411111******1111', 'type' => 'visa' ) );
		$this->fake_nmi(
			array( 200, $this->nmi_transaction( array( 'payment_details' => array( 'card_number' => '378282*****0005', 'card_type' => 'American Express' ) ) ) ),
			array( 200, '' )
		);

		$result = $this->nmi( array( 'allowed_card_types' => array( 'visa' ) ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertFalse( $this->order->paid );
		$this->assertSame( '12803215126', $this->order->get_transaction_id() );
		$this->assertSame( '12803215126', $this->order->saved_meta['_nmi_charge_id'] );
		$this->assertSame( 'yes', $this->order->saved_meta['_nmi_charge_captured'] );
		$this->assertStringContainsString( 'could not be voided', implode( "\n", $this->order->notes ) );
	}

	public function test_a_disallowed_authorization_that_cannot_be_voided_is_kept_uncaptured(): void {
		$this->post_collect_js_token( array( 'number' => '411111******1111', 'type' => 'visa' ) );
		$this->fake_nmi(
			array( 200, $this->nmi_transaction( array( 'payment_details' => array( 'card_number' => '378282*****0005', 'card_type' => 'American Express' ) ) ) ),
			array( 500, array( 'message' => 'Server error' ) )
		);

		$result = $this->nmi( array( 'allowed_card_types' => array( 'visa' ), 'capture' => false ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 'no', $this->order->saved_meta['_nmi_charge_captured'] );
		$this->assertSame( '12803215126', $this->order->get_transaction_id() );
	}

	public function test_the_card_details_stored_come_from_nmis_response(): void {
		$this->post_collect_js_token( array( 'number' => '411111******1111', 'type' => 'visa' ) );
		$this->fake_nmi( array( 200, $this->nmi_transaction( array(
			'payment_details' => array( 'card_number' => '555555******4444', 'card_type' => 'MasterCard', 'card_bin' => '555555' ),
		) ) ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertCount( 1, $this->nmi_requests );
		$this->assertSame( '4444', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( 'mastercard', $this->order->saved_meta['_nmi_card_type'] );
	}

	public function test_direct_post_card_fields_are_sent_as_payment_details(): void {
		$_POST['nmi-card-number'] = '4111 1111 1111 1111';
		$_POST['nmi-card-expiry'] = '12 / 30';
		$_POST['nmi-card-cvc']    = '999';
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame(
			array( 'card_number' => '4111111111111111', 'card_exp' => '1230', 'card_cvv' => '999' ),
			$this->nmi_requests[0]['body']['payment_details']
		);
		$this->assertSame( '1111', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( 'visa', $this->order->saved_meta['_nmi_card_type'] );
	}

	public function test_a_four_digit_expiry_year_is_shortened(): void {
		$_POST['nmi-card-number'] = '4111111111111111';
		$_POST['nmi-card-expiry'] = '1 / 2030';
		$_POST['nmi-card-cvc']    = '999';
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( '0130', $this->nmi_requests[0]['body']['payment_details']['card_exp'] );
	}

	public function incomplete_card_provider(): array {
		return array(
			'no number' => array( array( 'nmi-card-expiry' => '12 / 30', 'nmi-card-cvc' => '999' ) ),
			'no expiry' => array( array( 'nmi-card-number' => '4111111111111111', 'nmi-card-cvc' => '999' ) ),
			'no CVC'    => array( array( 'nmi-card-number' => '4111111111111111', 'nmi-card-expiry' => '12 / 30' ) ),
		);
	}

	/**
	 * @dataProvider incomplete_card_provider
	 */
	public function test_incomplete_direct_post_card_fields_are_refused( array $post ): void {
		$_POST = $post;
		$this->fake_nmi();

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( array(), $this->nmi_requests );
		$this->assertStringContainsString( 'Credit card details cannot be left incomplete.', $this->notices[0] );
	}

	public function test_a_direct_post_card_of_a_disallowed_type_is_refused_before_charging(): void {
		$_POST['nmi-card-number'] = '378282246310005';
		$_POST['nmi-card-expiry'] = '12 / 30';
		$_POST['nmi-card-cvc']    = '9999';
		$this->fake_nmi();

		$result = $this->nmi( array( 'allowed_card_types' => array( 'visa' ) ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( array(), $this->nmi_requests );
	}

	public function wallet_provider(): array {
		return array(
			'Google Pay' => array( 'wcapgp_google_pay_payment_data', 'googlepay_payment_data' ),
			'Apple Pay'  => array( 'wcapgp_apple_pay_payment_data', 'applepay_payment_data' ),
		);
	}

	/**
	 * @dataProvider wallet_provider
	 */
	public function test_wallet_payment_data_is_sent_decoded_and_the_card_comes_from_the_response( string $post_key, string $nmi_key ): void {
		$_POST[ $post_key ] = base64_encode( '{"signature":"abc","protocolVersion":"ECv2"}' );
		$this->fake_nmi( array( 200, $this->nmi_transaction( array(
			'payment_details' => array( 'card_number' => '555555******4444', 'card_exp' => '1230', 'card_type' => 'MasterCard', 'card_bin' => '555555' ),
		) ) ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( array( $nmi_key => '{"signature":"abc","protocolVersion":"ECv2"}' ), $this->nmi_requests[0]['body']['payment_details'] );
		$this->assertSame( '4444', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( 'mastercard', $this->order->saved_meta['_nmi_card_type'] );
	}

	public function test_a_wallet_payment_takes_priority_over_a_card_token_on_the_page(): void {
		// The classic checkout posts every payment method's fields, including a token the NMI card fields made.
		$this->post_collect_js_token();
		$_POST['wcapgp_google_pay_payment_data'] = base64_encode( '{"signature":"abc"}' );
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( array( 'googlepay_payment_data' => '{"signature":"abc"}' ), $this->nmi_requests[0]['body']['payment_details'] );
	}

	public function test_a_wallet_payment_without_card_details_in_the_response_still_completes(): void {
		$_POST['wcapgp_apple_pay_payment_data'] = base64_encode( '{"data":"abc"}' );
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'payment_details' => array() ) ) ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( '', $this->order->saved_meta['_nmi_card_last4'] );
		$this->assertSame( '', $this->order->saved_meta['_nmi_card_type'] );
	}

	// -----------------------------------------------------------------------
	// Request args.
	// -----------------------------------------------------------------------

	public function test_the_order_is_sent_as_nested_v5_args(): void {
		$this->post_collect_js_token();
		$this->order->address = array_merge( $this->order->address, array(
			'shipping_first_name' => 'Jim',
			'shipping_last_name'  => 'Receiver',
			'shipping_address_1'  => '2 Side St',
			'shipping_city'       => 'Dallas',
			'shipping_state'      => 'TX',
			'shipping_country'    => 'US',
			'shipping_postcode'   => '75001',
		) );
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$body = $this->nmi_requests[0]['body'];
		$this->assertSame( '10.00', $body['amount'] );
		$this->assertSame( 'USD', $body['currency'] );
		$this->assertTrue( $body['customer_receipt'] );
		$this->assertSame( 'ecommerce', $body['industry'] );
		$this->assertSame( array(
			'order_description' => 'Test Shop - Order 1001',
			'id'                => '1001',
			'tax'               => '0.50',
			'shipping'          => '2.00',
			'ip_address'        => '203.0.113.10',
		), $body['order_details'] );
		$this->assertSame( array(
			'first_name' => 'Jane',
			'last_name'  => 'Buyer',
			'address1'   => '1 Main St',
			'address2'   => '',
			'city'       => 'Austin',
			'state'      => 'TX',
			'country'    => 'US',
			'zip'        => '77777',
			'email'      => 'jane@example.com',
			'phone'      => '5551234567',
			'company'    => '',
		), $body['billing_address'] );
		$this->assertSame( array(
			'first_name' => 'Jim',
			'last_name'  => 'Receiver',
			'address1'   => '2 Side St',
			'city'       => 'Dallas',
			'state'      => 'TX',
			'country'    => 'US',
			'zip'        => '75001',
		), $body['shipping_address'] );

		// transact.php fields v5 rejects ("Unexpected extra parameters").
		foreach ( array( 'orderid', 'type', 'transactionid', 'ipaddress', 'descriptor', 'first_name', 'payment_token', 'tax', 'shipping' ) as $legacy_key ) {
			$this->assertArrayNotHasKey( $legacy_key, $body );
		}
	}

	public function test_an_order_without_a_shipping_address_sends_none(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertArrayNotHasKey( 'shipping_address', $this->nmi_requests[0]['body'] );
	}

	public function test_line_items_are_added_to_the_description_when_enabled(): void {
		$this->post_collect_js_token();
		$this->order->items = array(
			new class() {
				public function get_name() {
					return 'Hoodie';
				}
				public function get_quantity() {
					return 2;
				}
			},
		);
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi( array( 'line_items' => true ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'Test Shop - Order 1001 (Hoodie x 2)', $this->nmi_requests[0]['body']['order_details']['order_description'] );
	}

	public function test_the_v5_args_filter_gets_the_order_and_endpoint(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );
		$order = $this->order;
		Filters\expectApplied( 'wc_nmi_v5_request_args' )
			->once()
			->with( \Mockery::type( 'array' ), $order, 'sale' )
			->andReturnUsing( function ( $args ) {
				$args['merchant_defined_fields'] = array( 'merchant_defined_field_1' => 'abc' );
				return $args;
			} );
		Filters\expectApplied( 'wc_nmi_request_args' )->never();

		$this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( array( 'merchant_defined_field_1' => 'abc' ), $this->nmi_requests[0]['body']['merchant_defined_fields'] );
	}

	// -----------------------------------------------------------------------
	// Outcomes.
	// -----------------------------------------------------------------------

	public function test_without_capture_the_card_is_authorized_and_the_order_put_on_hold(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$result = $this->nmi( array( 'capture' => false ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/auth', $this->nmi_requests[0]['url'] );
		$this->assertSame( 'success', $result['result'] );
		$this->assertFalse( $this->order->paid );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 'no', $this->order->saved_meta['_nmi_charge_captured'] );
		$this->assertSame( '12803215126', $this->order->get_transaction_id() );
		$this->assertStringContainsString( 'NMI charge authorized for $10.00 (Charge ID: 12803215126).', end( $this->order->notes ) );
	}

	public function test_a_decline_fails_the_order_with_the_reason(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200', 'status' => 'failed' ) ) ) );
		Actions\expectDone( 'wc_gateway_nmi_process_payment_error' )->once();

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( array( 'result' => 'fail', 'redirect' => '' ), $result );
		$this->assertSame( 'failed', $this->order->get_status() );
		$this->assertFalse( $this->order->paid );
		$this->assertFalse( $this->cart_emptied );
		$this->assertStringContainsString( 'Your card has been declined.', $this->notices[0] );
		$this->assertStringContainsString( 'NMI failure reason: 200 - DECLINE', implode( "\n", $this->order->notes ) );
	}

	public function test_an_api_error_is_noted_with_its_code(): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 400, array(
			'type'       => 'validationError',
			'error_code' => 'E_INVALID_SUBMISSION',
			'message'    => 'The provided data is invalid.',
			'details'    => array( array( 'fieldName' => 'extraParameters', 'message' => "Unexpected extra parameters found: 'orderid'" ) ),
		) ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertStringContainsString( 'NMI failure reason: E_INVALID_SUBMISSION - The provided data is invalid.', implode( "\n", $this->order->notes ) );
	}

	public function unusable_approval_provider(): array {
		return array(
			'no id'       => array( array( 'id' => '' ) ),
			'response 0'  => array( array( 'response' => '0' ) ),
		);
	}

	/**
	 * @dataProvider unusable_approval_provider
	 */
	public function test_a_response_without_an_approved_transaction_doesnt_mark_the_order_paid( array $overrides ): void {
		$this->post_collect_js_token();
		$this->fake_nmi( array( 200, $this->nmi_transaction( $overrides ) ) );

		$result = $this->nmi()->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertFalse( $this->order->paid );
		$this->assertSame( 'failed', $this->order->get_status() );
		$this->assertSame( '', $this->order->get_transaction_id() );
	}

	public function test_a_site_still_on_username_password_is_not_charged(): void {
		$this->post_collect_js_token();
		$this->fake_nmi();

		$result = $this->nmi( array( 'legacy_auth' => true ) )->process_payment( $this->order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( array(), $this->nmi_requests );
	}
}
