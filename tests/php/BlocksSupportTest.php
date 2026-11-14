<?php
namespace WCNMI\Tests;

use WC_NMI_PCI_Blocks_Support;

require_once dirname( __DIR__, 2 ) . '/tests/php/stubs/blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wc-nmi-blocks-support.php';

/**
 * Whether the checkout block offers NMI.
 */
class BlocksSupportTest extends TestCase {

	public function active_provider(): array {
		$keys = array( 'enabled' => 'yes', 'private_key' => 'key', 'public_key' => 'pub' );

		return array(
			'configured'              => array( $keys, true ),
			'disabled'                => array( array_merge( $keys, array( 'enabled' => 'no' ) ), false ),
			'no tokenization key'     => array( array_merge( $keys, array( 'public_key' => '' ) ), false ),
			'no private key'          => array( array_merge( $keys, array( 'private_key' => '' ) ), false ),
			'username/password site'  => array( array_merge( $keys, array( 'api_keys' => 'no' ) ), false ),
		);
	}

	/**
	 * @dataProvider active_provider
	 */
	public function test_the_block_is_active_only_with_both_keys_and_no_legacy_auth( array $settings, bool $active ): void {
		$blocks = $this->gateway( WC_NMI_PCI_Blocks_Support::class );
		( function ( $settings ) {
			$this->settings = $settings;
		} )->call( $blocks, $settings );

		$this->assertSame( $active, $blocks->is_active() );
	}
}
