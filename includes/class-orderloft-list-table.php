<?php
/**
 * Archived orders list.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists archived orders with search, paging and bulk restore.
 */
class Orderloft_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'archived_order',
				'plural'   => 'archived_orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'order'        => __( 'Order', 'orderloft' ),
			'date_created' => __( 'Date', 'orderloft' ),
			'status'       => __( 'Status', 'orderloft' ),
			'total'        => __( 'Total', 'orderloft' ),
			'archived'     => __( 'Archived', 'orderloft' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'order'        => array( 'order_id', false ),
			'date_created' => array( 'date_created_gmt', true ),
			'total'        => array( 'total', false ),
			'archived'     => array( 'archived_gmt', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'orderloft_restore' => __( 'Restore to live orders', 'orderloft' ) );
	}

	/**
	 * Current search term.
	 *
	 * @return string
	 */
	public static function search_term() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search of the list.
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	}

	/**
	 * Archived orders matching a search term (all when the term is empty).
	 *
	 * @param string $search  Search term.
	 * @param string $orderby Sort column key.
	 * @param string $order   ASC or DESC.
	 * @param int    $limit   Rows.
	 * @param int    $offset  Offset.
	 * @param bool   $all     Return every column (for export) instead of the list columns.
	 * @return array Rows and total.
	 */
	public static function query( $search, $orderby = 'archived_gmt', $order = 'DESC', $limit = 30, $offset = 0, $all = false ) {
		global $wpdb;
		$term  = ltrim( $search, '#' );
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$table = Orderloft_Schema::index_table();
		$sort  = self::sort_column( $orderby );
		$dir   = 'ASC' === $order ? 1 : -1;
		// Static SQL: the search condition matches every row when the term is empty, and the sort column is picked
		// with CASE from a whitelisted key; the direction is a +1/-1 multiplier.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR order_number LIKE %s OR billing_email LIKE %s OR billing_name LIKE %s OR order_id = %d )", $table, $term, $like, $like, $like, absint( $term ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR order_number LIKE %s OR billing_email LIKE %s OR billing_name LIKE %s OR order_id = %d )
				ORDER BY ( CASE %s WHEN 'order_id' THEN order_id WHEN 'total' THEN total WHEN 'date_created_gmt' THEN UNIX_TIMESTAMP( date_created_gmt ) ELSE UNIX_TIMESTAMP( archived_gmt ) END ) * %d, order_id DESC
				LIMIT %d OFFSET %d",
				$table,
				$term,
				$like,
				$like,
				$like,
				absint( $term ),
				$sort,
				$dir,
				$limit,
				$offset
			),
			$all ? ARRAY_A : OBJECT
		);
		// phpcs:enable
		return array( (array) $rows, $total );
	}

	/**
	 * Whitelisted sort column.
	 *
	 * @param string $key Requested column.
	 * @return string
	 */
	private static function sort_column( $key ) {
		switch ( $key ) {
			case 'order_id':
				return 'order_id';
			case 'date_created_gmt':
				return 'date_created_gmt';
			case 'total':
				return 'total';
			default:
				return 'archived_gmt';
		}
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		global $wpdb;
		$per_page = 30;
		$page     = $this->get_pagenum();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'archived_gmt';
		$order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		list( $rows, $total ) = self::query( self::search_term(), $orderby, $order, $per_page, ( $page - 1 ) * $per_page );

		$this->items           = (array) $rows;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="order_ids[]" value="' . esc_attr( $item->order_id ) . '" />';
	}

	/**
	 * Order column with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_order( $item ) {
		$view    = Orderloft_Admin::url(
			array(
				'tab'  => 'archived',
				'view' => $item->order_id,
			)
		);
		$restore = wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_restore&order_id=' . absint( $item->order_id ) ), 'orderloft_restore_' . absint( $item->order_id ) );
		$name    = $item->billing_name ? $item->billing_name : $item->billing_email;
		$actions = array(
			'view'    => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'orderloft' ) . '</a>',
			'restore' => '<a href="' . esc_url( $restore ) . '">' . esc_html__( 'Restore', 'orderloft' ) . '</a>',
		);
		return '<a class="row-title" href="' . esc_url( $view ) . '">#' . esc_html( $item->order_number ) . ' ' . esc_html( $name ) . '</a>' . $this->row_actions( $actions );
	}

	/**
	 * Other columns.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'date_created':
				return $item->date_created_gmt ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->date_created_gmt . ' UTC' ) ) ) : '';
			case 'status':
				return '<mark class="order-status status-' . esc_attr( $item->status ) . '"><span>' . esc_html( wc_get_order_status_name( $item->status ) ) . '</span></mark>';
			case 'total':
				return wp_kses_post( wc_price( $item->total, array( 'currency' => $item->currency ) ) );
			case 'archived':
				return $item->archived_gmt ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->archived_gmt . ' UTC' ) ) ) : '';
		}
		return '';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No archived orders found.', 'orderloft' );
	}
}
