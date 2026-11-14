<?php
/**
 * PHPUnit bootstrap.
 *
 * These are true unit tests: WordPress and WooCommerce are not loaded. The WooCommerce classes the plugin
 * uses are stubbed (tests/php/stubs), and gateways are created without their constructors, so the real
 * plugin methods run in isolation. Brain Monkey stubs WordPress functions per test.
 *
 * Anything that needs a real WooCommerce request (checkout, Store API, a gateway's constructor) belongs in
 * an integration or e2e test instead.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );

// Brain Monkey's add_action() etc., needed while the main plugin file registers its hooks below.
Brain\Monkey\setUp();

// WordPress functions the plugin calls while loading or handling a request, with their WordPress behaviour.
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'wc_clean' ) ) {
	function wc_clean( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wc_clean', $value );
		}
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : $value;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	// WordPress lowercases, strips tags and accents, and turns spaces and other characters into hyphens.
	function sanitize_title( $title ) {
		$title = strtolower( strip_tags( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
		return trim( preg_replace( '/-+/', '-', $title ), '-' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	// Like WordPress: the URL includes the folder of $plugin (a plugin file), if it's in one.
	function plugins_url( $path = '', $plugin = '' ) {
		$url    = 'https://example.com/wp-content/plugins';
		$folder = $plugin ? basename( dirname( str_replace( '\\', '/', $plugin ) ) ) : '';
		if ( '' !== $folder && '.' !== $folder ) {
			$url .= '/' . $folder;
		}
		return $url . '/' . ltrim( $path, '/' );
	}
}

require_once __DIR__ . '/stubs/woocommerce.php';
require_once __DIR__ . '/stubs/wcapgp.php';

// The main plugin file defines the WC_NMI_PCI class and the plugin constants.
require_once dirname( __DIR__, 2 ) . '/gateway.php';

require_once WC_NMI_PCI_PLUGIN_PATH . '/includes/class-wc-gateway-nmi.php';

// Forget the hooks registered while loading; each test starts clean.
Brain\Monkey\tearDown();
