<?php
namespace WCNMI\Tests;

/**
 * The version and minimum requirements are stated in several files, which must agree for a release.
 */
class VersionTest extends TestCase {

	private function file( string $name ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $name );
	}

	private function header( string $file, string $name ): string {
		preg_match( '/^\s*' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/m', $this->file( $file ), $matches );
		return $matches[1] ?? '';
	}

	public function test_the_version_is_the_same_everywhere(): void {
		$version = $this->header( 'gateway.php', 'Version' );

		$this->assertSame( '2.0.0', $version );
		$this->assertSame( $version, WC_NMI_PCI_VERSION );
		$this->assertSame( $version, json_decode( $this->file( 'package.json' ), true )['version'] );
		$this->assertSame( $version, json_decode( $this->file( 'block.json' ), true )['version'] );
		$this->assertMatchesRegularExpression( '/== Changelog ==\s+= ' . preg_quote( $version, '/' ) . ' =/', $this->file( 'readme.txt' ) );
	}

	public function test_the_minimum_requirements_match_in_the_plugin_header_and_readme(): void {
		// The v5 code uses ?? (PHP 7.0). WooCommerce 3.3 needs WordPress 4.7.
		$this->assertSame( '7.0', $this->header( 'gateway.php', 'Requires PHP' ) );
		$this->assertSame( '7.0', $this->header( 'readme.txt', 'Requires PHP' ) );
		$this->assertSame( '4.7', $this->header( 'gateway.php', 'Requires at least' ) );
		$this->assertSame( '4.7', $this->header( 'readme.txt', 'Requires at least' ) );
		$this->assertSame( '3.3', $this->header( 'gateway.php', 'WC requires at least' ) );
	}
}
