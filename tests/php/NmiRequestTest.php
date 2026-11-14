<?php
namespace WCNMI\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WC_Gateway_NMI;
use WP_Error;

/**
 * nmi_request(): NMI's v5 Payments API.
 *
 * Response shapes are the ones the NMI sandbox returned on 2026-11-13 (see todo.md, M0).
 */
class NmiRequestTest extends TestCase {

	private function nmi( array $properties = array() ) {
		return $this->gateway( WC_Gateway_NMI::class, array_merge( array(
			'testmode'    => true,
			'private_key' => 'test_private_key',
			'legacy_auth' => false,
			'debugging'   => false,
		), $properties ) );
	}

	// -----------------------------------------------------------------------
	// Request.
	// -----------------------------------------------------------------------

	public function test_requests_go_to_the_v5_sandbox_or_live_url(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ), array( 200, $this->nmi_transaction() ) );

		$this->nmi( array( 'testmode' => true ) )->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );
		$this->nmi( array( 'testmode' => false ) )->nmi_request( 'POST:auth', array( 'amount' => '10.00' ) );

		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/sale', $this->nmi_requests[0]['url'] );
		$this->assertSame( 'https://secure.nmi.com/api/v5/payments/auth', $this->nmi_requests[1]['url'] );
	}

	public function test_an_endpoint_without_a_method_is_a_post(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->nmi_request( '12803215126/capture', array( 'amount' => '10.00' ) );

		$this->assertSame( 'POST', $this->nmi_requests[0]['method'] );
		$this->assertSame( 'https://sandbox.nmi.com/api/v5/payments/12803215126/capture', $this->nmi_requests[0]['url'] );
	}

	public function test_the_private_key_is_sent_as_a_header_and_the_body_is_json(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$request = $this->nmi_requests[0];
		$this->assertSame( 'test_private_key', $request['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( array( 'amount' => '10.00' ), $request['body'] );
		foreach ( array( 'security_key', 'username', 'password', 'type' ) as $legacy_param ) {
			$this->assertArrayNotHasKey( $legacy_param, $request['body'] );
		}
	}

	public function test_empty_args_are_sent_as_a_json_object(): void {
		// A void has no body; json_encode( array() ) would send "[]", a JSON array.
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'status' => 'canceled' ) ) ) );

		$this->nmi()->nmi_request( 'POST:12803215254/void' );

		$this->assertSame( '{}', $this->nmi_requests[0]['raw_body'] );
	}

	public function test_a_country_without_a_state_sends_na(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->nmi_request( 'POST:sale', array(
			'billing_address'  => array( 'country' => 'GB', 'state' => '' ),
			'shipping_address' => array( 'country' => 'GB' ),
		) );

		$this->assertSame( 'NA', $this->nmi_requests[0]['body']['billing_address']['state'] );
		$this->assertSame( 'NA', $this->nmi_requests[0]['body']['shipping_address']['state'] );
	}

	public function test_an_empty_phone_is_not_sent(): void {
		// NMI rejects an empty phone, which is optional at checkout and in wallet payment sheets.
		$this->fake_nmi( array( 200, $this->nmi_transaction() ), array( 200, $this->nmi_transaction() ) );
		$gateway = $this->nmi();

		$gateway->nmi_request( 'POST:sale', array(
			'billing_address'  => array( 'first_name' => 'Jane', 'phone' => ' ' ),
			'shipping_address' => array( 'first_name' => 'Jane', 'phone' => '' ),
		) );
		$gateway->nmi_request( 'POST:sale', array(
			'billing_address' => array( 'first_name' => 'Jane', 'phone' => '5551234567' ),
		) );

		$this->assertArrayNotHasKey( 'phone', $this->nmi_requests[0]['body']['billing_address'] );
		$this->assertArrayNotHasKey( 'phone', $this->nmi_requests[0]['body']['shipping_address'] );
		$this->assertSame( '5551234567', $this->nmi_requests[1]['body']['billing_address']['phone'] );
	}

	public function test_an_empty_currency_falls_back_to_the_store_currency(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'CAD' );
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00', 'currency' => '' ) );

		$this->assertSame( 'CAD', $this->nmi_requests[0]['body']['currency'] );
	}

	public function test_the_request_url_filter_gets_the_endpoint(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );
		Filters\expectApplied( 'wc_nmi_request_url' )
			->once()
			->with( 'https://sandbox.nmi.com/api/v5/payments/sale', 'sale' )
			->andReturn( 'https://gateway.example.com/api/v5/payments/sale' );

		$this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 'https://gateway.example.com/api/v5/payments/sale', $this->nmi_requests[0]['url'] );
	}

	public function test_a_filtered_transact_php_url_is_moved_to_v5_on_the_same_host(): void {
		// Sites on a reseller's gateway host used the filter to point transact.php there.
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );
		Filters\expectApplied( 'wc_nmi_request_url' )->andReturn( 'https://secure.reseller.com/api/transact.php' );

		$this->nmi()->nmi_request( 'POST:12803215126/refund', array( 'amount' => '5.00' ) );

		$this->assertSame( 'https://secure.reseller.com/api/v5/payments/12803215126/refund', $this->nmi_requests[0]['url'] );
	}

	// -----------------------------------------------------------------------
	// No usable credentials.
	// -----------------------------------------------------------------------

	public function credentials_provider(): array {
		return array(
			'no private key'               => array( array( 'private_key' => '' ) ),
			'username/password site'       => array( array( 'legacy_auth' => true ) ),
			'username/password, key saved' => array( array( 'legacy_auth' => true, 'private_key' => 'stale_key' ) ),
		);
	}

	/**
	 * @dataProvider credentials_provider
	 */
	public function test_nothing_is_sent_without_a_usable_private_key( array $properties ): void {
		$this->fake_nmi();

		$result = $this->nmi( $properties )->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_configured', $result->get_error_code() );
		$this->assertSame( array(), $this->nmi_requests );
	}

	// -----------------------------------------------------------------------
	// Responses.
	// -----------------------------------------------------------------------

	public function test_an_approved_transaction_is_returned_as_an_array(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction() ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertIsArray( $result );
		$this->assertSame( '12803215126', $result['id'] );
		$this->assertSame( '123456', $result['auth_code'] );
	}

	public function test_a_decline_is_an_error_with_the_transaction_as_data(): void {
		$decline = $this->nmi_transaction( array( 'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200', 'status' => 'failed' ) );
		$this->fake_nmi( array( 200, $decline ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '0.90' ) );

		$this->assertSame( 'decline_response', $result->get_error_code() );
		$this->assertSame( '<!-- Error: 200 --> Your card has been declined.', $result->get_error_message() );
		$this->assertSame( $decline, $result->get_error_data() );
	}

	public function test_a_transaction_error_shows_the_response_text(): void {
		$error = $this->nmi_transaction( array( 'response' => '3', 'response_text' => 'Invalid Credit Card Number REFID:123', 'response_code' => '300' ) );
		$this->fake_nmi( array( 200, $error ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 'error_response', $result->get_error_code() );
		$this->assertSame( '<!-- Error: 300 --> Invalid Credit Card Number REFID:123', $result->get_error_message() );
		$this->assertSame( $error, $result->get_error_data() );
	}

	public function test_a_transaction_with_an_unknown_response_is_invalid(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'response' => '0' ) ) ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	public function test_a_transaction_body_is_mapped_whatever_the_http_status(): void {
		$decline = $this->nmi_transaction( array( 'response' => '2', 'response_code' => '200' ) );
		$this->fake_nmi( array( 402, $decline ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '0.90' ) );

		$this->assertSame( 'decline_response', $result->get_error_code() );
		$this->assertSame( $decline, $result->get_error_data() );
	}

	public function test_a_validation_error_shows_its_code_message_and_details(): void {
		$body = array(
			'type'       => 'validationError',
			'error_code' => 'E_INVALID_SUBMISSION',
			'message'    => 'Invalid Input',
			'ref_id'     => '335748978',
			'details'    => array( array( 'fieldName' => 'payment_details', 'message' => 'Payment information is required' ) ),
		);
		$this->fake_nmi( array( 400, $body ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 400, $result->get_error_code() );
		$this->assertSame( 'E_INVALID_SUBMISSION - Invalid Input (Payment information is required)', $result->get_error_message() );
		$this->assertSame( $body, $result->get_error_data() );
	}

	public function test_an_error_body_with_errorcode_is_read_too(): void {
		$this->fake_nmi( array( 403, array( 'type' => 'generalError', 'errorCode' => 'E_FORBIDDEN', 'message' => 'Forbidden' ) ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 403, $result->get_error_code() );
		$this->assertSame( 'E_FORBIDDEN - Forbidden', $result->get_error_message() );
	}

	public function test_a_wrong_key_is_reported_as_invalid_credentials(): void {
		$body = array( 'type' => 'authenticationError', 'error_code' => 'E_AUTHENTICATION_MISSING', 'message' => 'Missing/Invalid Authentication', 'ref_id' => '335748996' );
		$this->fake_nmi( array( 401, $body ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 401, $result->get_error_code() );
		$this->assertSame( '401 - Unauthorized request. The merchant account credentials appear to be invalid.', $result->get_error_message() );
		$this->assertSame( $body, $result->get_error_data() );
	}

	public function test_a_server_error_without_json_keeps_only_the_status_as_data(): void {
		$this->fake_nmi( array( 502, '<html><body>Bad Gateway</body></html>' ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 502, $result->get_error_code() );
		$this->assertSame( '502 - There was an error with the gateway response.', $result->get_error_message() );
		$this->assertSame( array( 'http_code' => 502 ), $result->get_error_data() );
	}

	public function empty_body_provider(): array {
		return array(
			'empty object' => array( '{}' ),
			'empty array'  => array( '[]' ),
			'not json'     => array( 'OK' ),
			'empty'        => array( '' ),
		);
	}

	/**
	 * @dataProvider empty_body_provider
	 */
	public function test_a_success_status_without_a_usable_body_is_invalid( string $body ): void {
		// Otherwise a capture with an empty body would mark the order captured.
		$this->fake_nmi( array( 200, $body ) );

		$result = $this->nmi()->nmi_request( 'POST:12803215126/capture', array( 'amount' => '10.00' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	public function test_a_transport_error_is_passed_through(): void {
		$this->fake_nmi( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '10.00' ) );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_error_messages_go_through_the_error_message_filter(): void {
		$this->fake_nmi( array( 200, $this->nmi_transaction( array( 'response' => '2' ) ) ) );
		Filters\expectApplied( 'woocommerce_nmi_error_message' )->once()->andReturn( 'Custom decline message' );

		$result = $this->nmi()->nmi_request( 'POST:sale', array( 'amount' => '0.90' ) );

		$this->assertSame( 'Custom decline message', $result->get_error_message() );
	}

	// -----------------------------------------------------------------------
	// Debug log.
	// -----------------------------------------------------------------------

	public function test_the_debug_log_masks_card_numbers_and_cvv(): void {
		$args = array(
			'amount'          => '10.00',
			'payment_details' => array( 'card_number' => '4111111111111111', 'card_exp' => '1230', 'card_cvv' => '999' ),
		);

		$logged = $this->call( $this->nmi(), 'get_loggable_args', $args );

		$this->assertSame( '************1111', $logged['payment_details']['card_number'] );
		$this->assertSame( '***', $logged['payment_details']['card_cvv'] );
		$this->assertSame( '1230', $logged['payment_details']['card_exp'] );
		$this->assertSame( '10.00', $logged['amount'] );
	}
}
