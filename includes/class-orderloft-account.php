<?php
/**
 * Shows archived orders to customers under My Account > Orders.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only list of a customer's archived orders.
 */
class Orderloft_Account {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_after_account_orders', array( __CLASS__, 'render' ) );
	}

	/**
	 * Archived orders of a customer, newest first.
	 *
	 * @param int $customer_id User ID.
	 * @return object[]
	 */
	public static function for_customer( $customer_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT order_id, order_number, status, currency, total, date_created_gmt FROM %i WHERE customer_id = %d ORDER BY date_created_gmt DESC LIMIT 500', Orderloft_Schema::index_table(), $customer_id ) );
	}

	/**
	 * Print the table below the customer's live orders.
	 */
	public static function render() {
		if ( 'yes' !== Orderloft_Settings::get( 'customer_view' ) || ! is_user_logged_in() ) {
			return;
		}
		$rows = self::for_customer( get_current_user_id() );
		if ( ! $rows ) {
			return;
		}
		?>
		<h3 class="orderloft-archived-title"><?php esc_html_e( 'Older orders', 'orderloft' ); ?></h3>
		<p class="orderloft-archived-note"><?php esc_html_e( 'These orders are archived. Contact us if you need details of one of them.', 'orderloft' ); ?></p>
		<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders orderloft-archived-orders">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'orderloft' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'orderloft' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'orderloft' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'orderloft' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Order', 'orderloft' ); ?>">#<?php echo esc_html( $row->order_number ); ?></td>
						<td data-title="<?php esc_attr_e( 'Date', 'orderloft' ); ?>"><?php echo esc_html( $row->date_created_gmt ? wp_date( get_option( 'date_format' ), strtotime( $row->date_created_gmt . ' UTC' ) ) : '' ); ?></td>
						<td data-title="<?php esc_attr_e( 'Status', 'orderloft' ); ?>"><?php echo esc_html( wc_get_order_status_name( $row->status ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Total', 'orderloft' ); ?>"><?php echo wp_kses_post( wc_price( $row->total, array( 'currency' => $row->currency ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
