<?php
namespace WCNMI\Tests\Fakes;

/**
 * An order, with the WC_Order methods the gateway uses.
 *
 * Meta behaves like WC_Data: get_meta() returns '' when missing, and changes are only "saved" when save()
 * is called, so tests can assert that a gateway saves what it changes.
 */
class FakeOrder {

	public $meta = array();

	public $saved_meta = array();

	public $notes = array();

	public $save_count = 0;

	public $user_id = 0;

	public $needs_payment = false;

	public $last_renewal_order = null;

	public $payment_method = '';

	public $payment_method_title = '';

	private static $next_id = 100;

	private $id;

	public function __construct( array $meta = array(), $user_id = 0 ) {
		$this->id         = self::$next_id++;
		$this->meta       = $meta;
		$this->saved_meta = $meta;
		$this->user_id    = $user_id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_user_id() {
		return $this->user_id;
	}

	public $total = 0;

	public $total_tax = '0.00';

	public $shipping_total = '0.00';

	public $total_refunded = 0;

	public $currency = 'USD';

	public $order_number = '';

	public $transaction_id = '';

	public $status = 'pending';

	public $items = array();

	/**
	 * Billing and shipping fields, e.g. billing_first_name or shipping_city, read by get_billing_*() / get_shipping_*().
	 *
	 * @var array
	 */
	public $address = array();

	public function get_total() {
		return $this->total;
	}

	public function get_total_tax() {
		return $this->total_tax;
	}

	public function get_shipping_total() {
		return $this->shipping_total;
	}

	public function get_total_refunded() {
		return $this->total_refunded;
	}

	public function get_currency() {
		return $this->currency;
	}

	public function get_order_number() {
		return '' !== $this->order_number ? $this->order_number : (string) $this->id;
	}

	public function get_transaction_id() {
		return $this->transaction_id;
	}

	public function set_transaction_id( $transaction_id ) {
		$this->transaction_id = $transaction_id;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		$this->save();
	}

	public function get_items() {
		return $this->items;
	}

	public function __call( $name, $arguments ) {
		if ( preg_match( '/^get_((billing|shipping)_\w+)$/', $name, $matches ) ) {
			return $this->address[ $matches[1] ] ?? '';
		}
		throw new \BadMethodCallException( "FakeOrder::$name() is not faked." );
	}

	public $paid = false;

	public function payment_complete( $transaction_id = '' ) {
		$this->paid = true;
		if ( $transaction_id ) {
			$this->transaction_id = $transaction_id;
		}
		$this->status = 'processing';
		return true;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function set_payment_method_title( $title ) {
		$this->payment_method_title = $title;
	}

	public function meta_exists( $key ) {
		return array_key_exists( $key, $this->meta );
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save() {
		$this->save_count++;
		$this->saved_meta = $this->meta;
		return $this->id;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function needs_payment() {
		return $this->needs_payment;
	}

	public function get_last_order( $return_fields = 'ids', $order_types = array() ) {
		return $this->last_renewal_order;
	}
}
