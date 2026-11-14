<?php
/**
 * Personal data export and erasure for archived orders.
 *
 * WooCommerce's own exporter and eraser only see live orders. Without this class, archived orders would be left out
 * of a customer's data export and would keep their personal data after an erasure request.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers a personal data exporter and eraser.
 */
class Orderloft_Privacy {

	const PAGE_SIZE = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['orderloft-archived-orders'] = array(
			'exporter_friendly_name' => __( 'Archived orders (Orderloft)', 'orderloft' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['orderloft-archived-orders'] = array(
			'eraser_friendly_name' => __( 'Archived orders (Orderloft)', 'orderloft' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Archived orders that belong to an email address (by billing email or the matching user account).
	 *
	 * @param string $email  Email address.
	 * @param int    $page   Page number.
	 * @param bool   $paged  Whether to page the results.
	 * @return object[]
	 */
	private static function rows_for_email( $email, $page = 1, $paged = true ) {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$user_id = $user ? (int) $user->ID : -1;
		$offset  = $paged ? ( max( 1, (int) $page ) - 1 ) * self::PAGE_SIZE : 0;
		$limit   = $paged ? self::PAGE_SIZE : 1000000;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE billing_email = %s OR customer_id = %d ORDER BY order_id ASC LIMIT %d OFFSET %d', Orderloft_Schema::index_table(), $email, $user_id, $limit, $offset ) );
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$rows = self::rows_for_email( $email, $page );
		$data = array();
		foreach ( $rows as $row ) {
			$s     = json_decode( (string) $row->summary, true );
			$s     = is_array( $s ) ? $s : array();
			$items = array();
			foreach ( isset( $s['items'] ) ? (array) $s['items'] : array() as $item ) {
				if ( 'line_item' === ( $item['type'] ?? '' ) ) {
					$items[] = $item['name'] . ' × ' . $item['qty'];
				}
			}
			$data[] = array(
				'group_id'    => 'orderloft_archived_orders',
				'group_label' => __( 'Archived orders', 'orderloft' ),
				'item_id'     => 'orderloft-order-' . $row->order_id,
				'data'        => array(
					array(
						'name'  => __( 'Order number', 'orderloft' ),
						'value' => $row->order_number,
					),
					array(
						'name'  => __( 'Order date', 'orderloft' ),
						'value' => (string) $row->date_created_gmt,
					),
					array(
						'name'  => __( 'Order total', 'orderloft' ),
						'value' => wc_format_decimal( $row->total, wc_get_price_decimals() ) . ' ' . $row->currency,
					),
					array(
						'name'  => __( 'Items purchased', 'orderloft' ),
						'value' => implode( ', ', $items ),
					),
					array(
						'name'  => __( 'Billing address', 'orderloft' ),
						'value' => wp_strip_all_tags( str_replace( '<br/>', ', ', (string) ( $s['billing'] ?? '' ) ) ),
					),
					array(
						'name'  => __( 'Shipping address', 'orderloft' ),
						'value' => wp_strip_all_tags( str_replace( '<br/>', ', ', (string) ( $s['shipping_addr'] ?? '' ) ) ),
					),
					array(
						'name'  => __( 'Email address', 'orderloft' ),
						'value' => $row->billing_email,
					),
					array(
						'name'  => __( 'Phone number', 'orderloft' ),
						'value' => (string) ( $s['billing_phone'] ?? '' ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Eraser callback. Follows WooCommerce's own setting: when it is set to remove order data on erasure requests,
	 * archived orders are restored and passed to WooCommerce's order eraser, so they are anonymized exactly like
	 * live orders. Otherwise they are kept, as WooCommerce keeps live orders.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$rows     = self::rows_for_email( $email, 1, false );
		if ( ! $rows ) {
			return $response;
		}
		if ( 'yes' !== get_option( 'woocommerce_erasure_request_removes_order_data', 'no' ) || ! class_exists( 'WC_Privacy_Erasers' ) ) {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'Archived orders were kept, because WooCommerce is set to keep order data on erasure requests.', 'orderloft' );
			return $response;
		}
		$restored = 0;
		foreach ( $rows as $row ) {
			if ( true === Orderloft_Archiver::restore_order( (int) $row->order_id ) ) {
				++$restored;
			}
		}
		// WooCommerce's eraser removes the email and customer from each order it handles, so those orders no longer
		// match its own query. Asking for page 1 again until it reports done therefore covers every order.
		$p = 1;
		do {
			$result                     = WC_Privacy_Erasers::order_data_eraser( $email, 1 );
			$response['items_removed']  = $response['items_removed'] || ! empty( $result['items_removed'] );
			$response['items_retained'] = $response['items_retained'] || ! empty( $result['items_retained'] );
			$response['messages']       = array_merge( $response['messages'], (array) $result['messages'] );
			++$p;
		} while ( empty( $result['done'] ) && $p < 1000 );

		/* translators: %d: number of orders. */
		$response['messages'][] = sprintf( _n( '%d archived order was restored and anonymized.', '%d archived orders were restored and anonymized.', $restored, 'orderloft' ), $restored );
		unset( $page );
		return $response;
	}
}
