<?php
/**
 * Contact synchronisation from WordPress/WooCommerce to Chatwoot.
 *
 * Nothing here performs network I/O on the request that triggered it: every
 * change is queued and processed in the background.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

/**
 * Outbound contact sync.
 */
class Sync {

	const ACTION_SYNC_CONTACT = 'cws_sync_contact';
	const ACTION_GROUP        = 'chatwoot-woocommerce-sync';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'user_register', array( $this, 'queue' ), 20 );
		add_action( 'profile_update', array( $this, 'queue' ), 20 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'queue' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'queue_from_order' ), 20, 4 );

		add_action( self::ACTION_SYNC_CONTACT, array( $this, 'run' ), 10, 1 );
		add_action( self::ACTION_SYNC_CONTACT . '_fallback', array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Queue a user for synchronisation.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function queue( $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return;
		}

		$args = array( $user_id );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_SYNC_CONTACT, $args, self::ACTION_GROUP, true );
			return;
		}

		$hook = self::ACTION_SYNC_CONTACT . '_fallback';
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 10, $hook, $args );
		}
	}

	/**
	 * Queue the customer behind an order when its status changes.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param object $order    Order object.
	 * @return void
	 */
	public function queue_from_order( $order_id, $from, $to, $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			$this->queue( $user_id );
		}
	}

	/**
	 * Push a user to Chatwoot.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function run( $user_id ): void {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return;
		}

		$email = Identity::identifier_for_user( $user_id );
		if ( '' === $email ) {
			return;
		}

		$attributes = array(
			'wordpress_user_id' => $user_id,
			'locale'            => get_user_locale( $user_id ),
		);

		$country = $this->country();
		if ( '' !== $country ) {
			$attributes['country_code'] = $country;
		}

		$stats = $this->order_stats( $user_id, $user->user_email );
		$attributes = array_merge( $attributes, $stats );

		// Skip the round trip when nothing meaningful changed.
		$payload_hash = md5( wp_json_encode( array( $email, Identity::name_for_user( $user_id ), Identity::phone_for_user( $user_id ), $attributes ) ) );
		if ( get_user_meta( $user_id, Identity::META_SYNC_HASH, true ) === $payload_hash ) {
			return;
		}

		$result = Identity::resolve(
			$email,
			Identity::name_for_user( $user_id ),
			Identity::phone_for_user( $user_id ),
			$attributes,
			$user_id
		);

		if ( ! is_wp_error( $result ) ) {
			update_user_meta( $user_id, Identity::META_SYNC_HASH, $payload_hash );
		}
	}

	/**
	 * Visitor country, when the edge provides it.
	 *
	 * @return string
	 */
	private function country(): string {
		$header = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : '';
		return preg_match( '/^[A-Z]{2}$/', $header ) ? $header : '';
	}

	/**
	 * Order statistics shown to agents inside Chatwoot.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $email   Billing e-mail, to catch guest orders.
	 * @return array<string, mixed>
	 */
	private function order_stats( int $user_id, string $email ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array();
		$paid_statuses = array_map(
			static fn( $status ) => 'wc-' === substr( (string) $status, 0, 3 ) ? substr( (string) $status, 3 ) : (string) $status,
			$paid_statuses
		);
		$paid_statuses = array_values( array_unique( array_merge( $paid_statuses, array( 'processing', 'completed' ) ) ) );

		$orders = wc_get_orders(
			array(
				'limit'          => 100,
				'customer_id'    => $user_id,
				'status'         => $paid_statuses,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'type'           => 'shop_order',
			)
		);

		if ( ! $orders ) {
			return array(
				'orders_count' => 0,
			);
		}

		// Totals are only summed within a currency: adding raw order totals
		// across currencies produces a number that means nothing.
		$by_currency = array();
		foreach ( $orders as $order ) {
			$currency                 = (string) $order->get_currency();
			$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0.0 ) + (float) $order->get_total();
		}
		arsort( $by_currency );
		$latest = $orders[0];

		$stats = array(
			'orders_count'     => count( $orders ),
			'last_order_date'  => $latest->get_date_created() ? $latest->get_date_created()->date( 'Y-m-d' ) : '',
			'last_order_total' => (float) $latest->get_total(),
			'currency'         => $latest->get_currency(),
		);

		if ( 1 === count( $by_currency ) ) {
			$stats['lifetime_value'] = round( (float) reset( $by_currency ), 2 );
		} else {
			$parts = array();
			foreach ( $by_currency as $currency => $sum ) {
				$parts[] = number_format( $sum, 2, '.', '' ) . ' ' . $currency;
			}
			$stats['lifetime_value'] = implode( ' + ', $parts );
		}

		return $stats;
	}
}
