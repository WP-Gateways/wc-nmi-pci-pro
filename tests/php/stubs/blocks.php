<?php
/**
 * Stand-ins for the WooCommerce Blocks classes the blocks integration extends or uses.
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

abstract class AbstractPaymentMethodType {

	protected $name = '';

	protected $settings = array();

	public function get_setting( $name, $default = '' ) {
		return $this->settings[ $name ] ?? $default;
	}
}
