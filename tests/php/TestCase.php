<?php
namespace WCNMI\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Base test case: Brain Monkey, a clean request and a helper to build gateways.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		$_POST = array();
		$_GET  = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A gateway without running its constructor (which needs WooCommerce), with the given properties.
	 *
	 * @param string $class      Gateway class.
	 * @param array  $properties Property values, e.g. id or allowed_card_types.
	 * @return object
	 */
	protected function gateway( string $class, array $properties = array() ) {
		$gateway = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		foreach ( array_merge( array( 'logging' => false ), $properties ) as $name => $value ) {
			$gateway->$name = $value;
		}

		return $gateway;
	}

	/**
	 * Requests sent to the fake NMI API by fake_nmi(): url, method, headers, body (decoded JSON) and raw_body.
	 *
	 * @var array
	 */
	protected $nmi_requests = array();

	/**
	 * Fake NMI's API behind wp_remote_request(). Each response answers one request, in order:
	 * an array( status, body ) pair, where an array body is sent as JSON, or a WP_Error for a transport error.
	 * Requests after the last queued response get a 200 with an empty JSON object.
	 *
	 * @param array|WP_Error ...$responses
	 */
	protected function fake_nmi( ...$responses ) {
		$this->nmi_requests = array();
		$requests           = &$this->nmi_requests;

		Monkey\Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) use ( &$requests, &$responses ) {
			$requests[] = array(
				'url'      => $url,
				'method'   => $args['method'] ?? 'GET',
				'headers'  => $args['headers'] ?? array(),
				'raw_body' => $args['body'] ?? null,
				'body'     => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			);

			$response = $responses ? array_shift( $responses ) : array( 200, '{}' );
			if ( $response instanceof \WP_Error ) {
				return $response;
			}

			list( $status, $body ) = $response;
			return array(
				'response' => array( 'code' => $status ),
				'body'     => is_array( $body ) ? json_encode( $body ) : $body,
			);
		} );

		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
			return is_array( $response ) ? $response['response']['code'] : '';
		} );

		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
			return is_array( $response ) ? $response['body'] : '';
		} );
	}

	/**
	 * A v5 transaction response body, as NMI returns it (all values are strings).
	 *
	 * @param array $overrides
	 * @return array
	 */
	protected function nmi_transaction( array $overrides = array() ) {
		return array_merge( array(
			'object'          => 'transaction',
			'id'              => '12803215126',
			'type'            => 'cc',
			'amount'          => '10.00',
			'currency'        => 'USD',
			'auth_code'       => '123456',
			'avs_response'    => 'Z',
			'cvv_response'    => 'M',
			'status'          => 'pendingsettlement',
			'response'        => '1',
			'response_text'   => 'SUCCESS',
			'response_code'   => '100',
			'payment_details' => array(
				'card_number'    => '411111******1111',
				'card_exp'       => '1230',
				'card_type'      => 'Visa',
				'card_bin'       => '411111',
				'digital_wallet' => '',
			),
		), $overrides );
	}

	/**
	 * Call a protected or private method.
	 *
	 * @param object $object
	 * @param string $method
	 * @param mixed  ...$args
	 * @return mixed
	 */
	protected function call( $object, string $method, ...$args ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $object, ...$args );
	}
}
