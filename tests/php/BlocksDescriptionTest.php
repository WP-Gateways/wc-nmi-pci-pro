<?php
namespace WCNMI\Tests;

use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionMethod;
use WC_NMI_PCI_Blocks_Support;

require_once dirname( __DIR__, 2 ) . '/tests/php/stubs/blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wc-nmi-blocks-support.php';

/**
 * The card block shows the gateway description as classic checkout does, including the sandbox mode notice and
 * the test card instructions the gateway adds to it (as in NMI Enterprise and the CardPointe blocks).
 */
class BlocksDescriptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'wp_kses_post' )->alias(
			function ( $html ) {
				return strip_tags( $html, '<strong><a><p><br>' );
			}
		);
		Functions\when( 'wpautop' )->alias(
			function ( $text ) {
				return '<p>' . str_replace( "\n\n", "</p>\n<p>", $text ) . "</p>\n";
			}
		);
	}

	public function blocks_provider() {
		return array(
			'card'   => array( WC_NMI_PCI_Blocks_Support::class, 'nmi', 'class-wc-nmi-blocks-support.php' ),
		);
	}

	/**
	 * @param string      $class       Blocks support class.
	 * @param string      $id          Its gateway id.
	 * @param string|null $description The gateway's description, or null when the gateway isn't there.
	 * @return string What the block gets.
	 */
	private function description( $class, $id, $description ) {
		$gateways = array();

		if ( null !== $description ) {
			$gateways[ $id ] = $this->gateway_with( $description );
		}

		Functions\when( 'WC' )->justReturn(
			(object) array(
				'payment_gateways' => new class( $gateways ) {
					private $gateways;

					public function __construct( $gateways ) {
						$this->gateways = $gateways;
					}

					public function payment_gateways() {
						return $this->gateways;
					}

					public function get_available_payment_gateways() {
						return $this->gateways;
					}
				},
			)
		);

		$blocks = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( $blocks, 'get_description' );
		$method->setAccessible( true );

		return $method->invoke( $blocks );
	}

	private function gateway_with( $description ) {
		return new class( $description ) {
			public $description;

			public function __construct( $description ) {
				$this->description = $description;
			}

			public function get_description() {
				return $this->description;
			}
		};
	}

	/**
	 * @dataProvider blocks_provider
	 */
	public function test_the_description_is_sent_as_html_with_the_sandbox_notice( $class, $id ) {
		$this->assertSame(
			"<p>Pay with $id.</p>\n<p><strong>SANDBOX MODE ENABLED</strong> Use the test details.alert(1)</p>\n",
			$this->description( $class, $id, "Pay with $id.\n\n<strong>SANDBOX MODE ENABLED</strong> Use the test details.<script>alert(1)</script>" )
		);
	}

	/**
	 * @dataProvider blocks_provider
	 */
	public function test_no_description_is_an_empty_string( $class, $id ) {
		$this->assertSame( '', $this->description( $class, $id, "  \n" ) );
	}

	/**
	 * @dataProvider blocks_provider
	 */
	public function test_no_gateway_is_an_empty_string( $class, $id ) {
		$this->assertSame( '', $this->description( $class, $id, null ) );
	}

	/**
	 * @dataProvider blocks_provider
	 */
	public function test_the_block_data_carries_the_description( $class, $id, $file ) {
		$php = (string) file_get_contents( WC_NMI_PCI_PLUGIN_PATH . '/includes/' . $file );

		$this->assertMatchesRegularExpression( "/'description'\s*=>\s*\\\$this->get_description\(\)/", $php );
	}
}
