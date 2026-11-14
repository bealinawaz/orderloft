<?php
/**
 * Moves orders between WooCommerce's order tables and the archive tables.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive and restore engine.
 *
 * An order is archived by copying every row that belongs to it (the order, its refunds, addresses, meta, items,
 * item meta, notes and, where present, the wp_posts copies) into the archive tables and deleting the originals,
 * all inside one database transaction. Restoring does the reverse. Row IDs are kept, so a restored order is
 * byte-for-byte the order that was archived. WooCommerce's delete hooks are never fired, so WooCommerce Analytics
 * (wc_order_stats and the lookup tables) keeps counting archived orders.
 */
class Orderloft_Archiver {

	/**
	 * Order meta key marking orders the archiver skipped, so batches don't pick them again.
	 */
	const SKIP_META = '_orderloft_skip';

	/**
	 * Column lists cached for the current request.
	 *
	 * @var array
	 */
	private static $columns = array();

	/**
	 * Whether archive tables were checked in this request.
	 *
	 * @var bool
	 */
	private static $tables_ready = false;

	/**
	 * Which order storage is active.
	 *
	 * @return string hpos, hpos_sync or posts.
	 */
	public static function storage_mode() {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( ! $hpos ) {
			return 'posts';
		}
		return 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) ? 'hpos_sync' : 'hpos';
	}

	/**
	 * GMT cutoff date: orders created before it are old enough to archive.
	 *
	 * @param int $months Age in months.
	 * @return int Timestamp.
	 */
	public static function cutoff_timestamp( $months ) {
		return strtotime( '-' . absint( $months ) . ' months', time() );
	}

	/**
	 * Query args for orders that match the archive rules.
	 *
	 * @param array $settings Settings.
	 * @param int   $limit    Max IDs.
	 * @return array
	 */
	private static function candidate_query_args( $settings, $limit ) {
		$statuses = array_map(
			function ( $s ) {
				return 'wc-' . $s;
			},
			(array) $settings['statuses']
		);
		return array(
			'type'         => 'shop_order',
			'status'       => $statuses ? $statuses : array( 'wc-orderloft-none' ),
			'date_created' => '<' . self::cutoff_timestamp( $settings['age_months'] ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'limit'        => $limit,
			'return'       => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Excludes orders already skipped; runs in background batches.
			'meta_query'   => array(
				array(
					'key'     => self::SKIP_META,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * IDs of orders that match the archive rules, oldest first.
	 *
	 * @param array $settings Settings.
	 * @param int   $limit    Max IDs.
	 * @return int[]
	 */
	public static function find_candidates( $settings, $limit ) {
		if ( empty( $settings['statuses'] ) ) {
			return array();
		}
		return array_map( 'absint', (array) wc_get_orders( self::candidate_query_args( $settings, $limit ) ) );
	}

	/**
	 * How many orders match the archive rules right now.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function count_candidates( $settings ) {
		if ( empty( $settings['statuses'] ) ) {
			return 0;
		}
		$args             = self::candidate_query_args( $settings, 1 );
		$args['paginate'] = true;
		$result           = wc_get_orders( $args );
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Why an order should not be archived, or '' when it may be.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Settings.
	 * @return string
	 */
	public static function skip_reason( $order, $settings ) {
		$reason = '';
		if ( $order->get_parent_id() ) {
			// Child orders (for example multi-vendor sub-orders) stay with their parent.
			$reason = 'child_order';
		} elseif ( 'yes' === $settings['skip_subscriptions'] && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
			foreach ( (array) $subscriptions as $subscription ) {
				if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
					$reason = 'active_subscription';
					break;
				}
			}
		}

		/**
		 * Filters whether an order is skipped. Return a non-empty reason to keep the order in the live tables.
		 *
		 * @param string   $reason   Current reason ('' to archive).
		 * @param WC_Order $order    The order.
		 * @param array    $settings Orderloft settings.
		 */
		return (string) apply_filters( 'orderloft_skip_order', $reason, $order, $settings );
	}

	/**
	 * Archive a batch of orders that match the rules.
	 *
	 * @param array $settings Settings.
	 * @param int   $limit    How many orders to process.
	 * @return array Counts: archived, skipped, failed, and the last error message.
	 */
	public static function run_batch( $settings, $limit ) {
		$stats = array(
			'archived' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'error'    => '',
		);
		foreach ( self::find_candidates( $settings, $limit ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$reason = self::skip_reason( $order, $settings );
			if ( '' !== $reason ) {
				$order->update_meta_data( self::SKIP_META, $reason );
				$order->save_meta_data();
				++$stats['skipped'];
				continue;
			}
			$result = self::archive_order( $order_id );
			if ( is_wp_error( $result ) ) {
				++$stats['failed'];
				$stats['error'] = $result->get_error_message();
				// Mark it so one broken order can't block every later batch. Restore-free: the order is untouched.
				$order->update_meta_data( self::SKIP_META, 'error' );
				$order->save_meta_data();
				continue;
			}
			++$stats['archived'];
		}
		return $stats;
	}

	/**
	 * Archive one order with its refunds.
	 *
	 * @param int $order_id Order ID.
	 * @return true|WP_Error
	 */
	public static function archive_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'orderloft_not_order', __( 'This is not an order that can be archived.', 'orderloft' ) );
		}
		$check = self::preflight();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$refund_ids = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refund_ids[] = $refund->get_id();
		}

		$summary = self::snapshot( $order );
		$ids     = array_merge( array( $order->get_id() ), $refund_ids );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction around the move.
		// The note is written inside the transaction, so it disappears too if the move is undone.
		/* translators: %s: date the order was archived. */
		$order->add_order_note( sprintf( __( 'Archived by Orderloft on %s.', 'orderloft' ), wp_date( get_option( 'date_format' ) ) ) );
		$moved = self::move_rows( $ids, true );
		if ( ! is_wp_error( $moved ) ) {
			$inserted = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing the plugin's own index table.
				Orderloft_Schema::index_table(),
				array(
					'order_id'         => $order->get_id(),
					'order_number'     => (string) $order->get_order_number(),
					'status'           => $order->get_status(),
					'currency'         => $order->get_currency(),
					'total'            => (float) $order->get_total(),
					'customer_id'      => (int) $order->get_customer_id(),
					'billing_email'    => (string) $order->get_billing_email(),
					'billing_name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'date_created_gmt' => $order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ) : null,
					'archived_gmt'     => current_time( 'mysql', true ),
					'child_ids'        => implode( ',', $refund_ids ),
					'storage'          => self::storage_mode(),
					'summary'          => wp_json_encode( $summary ),
				),
				array( '%d', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				$moved = new WP_Error( 'orderloft_index', $wpdb->last_error );
			}
		}
		if ( is_wp_error( $moved ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Undo a failed move.
			self::clear_caches( $ids, $order->get_customer_id() );
			return $moved;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finish the move.
		self::clear_caches( $ids, $order->get_customer_id() );

		/**
		 * Fires after an order and its refunds were moved to the archive.
		 *
		 * @param int   $order_id   Order ID.
		 * @param int[] $refund_ids Refund IDs moved with it.
		 */
		do_action( 'orderloft_order_archived', $order_id, $refund_ids );
		return true;
	}

	/**
	 * Restore one archived order with its refunds.
	 *
	 * @param int $order_id Order ID.
	 * @return true|WP_Error
	 */
	public static function restore_order( $order_id ) {
		global $wpdb;
		$row = self::get_archived( $order_id );
		if ( ! $row ) {
			return new WP_Error( 'orderloft_not_archived', __( 'This order is not in the archive.', 'orderloft' ) );
		}
		$check = self::preflight();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$ids = array_merge( array( (int) $row->order_id ), array_filter( array_map( 'absint', explode( ',', (string) $row->child_ids ) ) ) );

		$conflict = self::find_id_conflict( $ids );
		if ( $conflict ) {
			/* translators: %d: order ID. */
			return new WP_Error( 'orderloft_conflict', sprintf( __( 'Order #%d already exists in the live order tables, so it was not restored.', 'orderloft' ), $conflict ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction around the move.
		$moved = self::move_rows( $ids, false );
		if ( ! is_wp_error( $moved ) ) {
			$deleted = $wpdb->delete( Orderloft_Schema::index_table(), array( 'order_id' => (int) $row->order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own index table.
			if ( false === $deleted ) {
				$moved = new WP_Error( 'orderloft_index', $wpdb->last_error );
			}
		}
		if ( is_wp_error( $moved ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Undo a failed move.
			return $moved;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finish the move.
		self::clear_caches( $ids, (int) $row->customer_id );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			// Keep it out of the next automatic run, otherwise it would be archived again tonight.
			$order->update_meta_data( self::SKIP_META, 'restored' );
			$order->save_meta_data();
			/* translators: %s: date the order was restored. */
			$order->add_order_note( sprintf( __( 'Restored from the Orderloft archive on %s.', 'orderloft' ), wp_date( get_option( 'date_format' ) ) ) );
		} elseif ( 'posts' !== self::storage_mode() ) {
			return new WP_Error(
				'orderloft_sync',
				__( 'The order was restored to the order storage it was archived from. WooCommerce now uses a different order storage, so sync orders under WooCommerce > Settings > Advanced > Features to see it.', 'orderloft' )
			);
		}

		/**
		 * Fires after an archived order was restored.
		 *
		 * @param int $order_id Order ID.
		 */
		do_action( 'orderloft_order_restored', $order_id );
		return true;
	}

	/**
	 * Checks that must pass before any move.
	 *
	 * @return true|WP_Error
	 */
	public static function preflight() {
		if ( ! self::$tables_ready ) {
			foreach ( Orderloft_Schema::table_map() as $table ) {
				Orderloft_Schema::ensure_archive_table( $table['source'], $table['archive'] );
			}
			self::$tables_ready = true;
		}
		$bad = Orderloft_Schema::non_transactional_tables();
		if ( $bad ) {
			return new WP_Error(
				'orderloft_engine',
				/* translators: %s: comma-separated table names. */
				sprintf( __( 'Orderloft only works with InnoDB tables, so that every move can be undone if something fails. These tables use another engine: %s', 'orderloft' ), implode( ', ', $bad ) )
			);
		}
		return true;
	}

	/**
	 * An ID from the list that already exists as a live order, or 0.
	 *
	 * @param int[] $ids Order and refund IDs.
	 * @return int
	 */
	private static function find_id_conflict( $ids ) {
		global $wpdb;
		$in = implode( ',', array_map( 'absint', $ids ) );
		foreach ( Orderloft_Schema::table_map() as $table ) {
			if ( 'hpos_orders' === $table['kind'] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in holds absint() values.
				$hit = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id IN ($in) LIMIT 1", $table['source'] ) );
			} elseif ( 'posts' === $table['kind'] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in holds absint() values.
				$hit = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE ID IN ($in) AND post_type IN ('shop_order','shop_order_refund') LIMIT 1", $table['source'] ) );
			} else {
				continue;
			}
			if ( $hit ) {
				return (int) $hit;
			}
		}
		return 0;
	}

	/**
	 * Copy every row of the given orders to the other side and delete the originals. Must run inside a transaction.
	 *
	 * @param int[] $ids        Order and refund IDs.
	 * @param bool  $to_archive True to archive, false to restore.
	 * @return true|WP_Error
	 */
	private static function move_rows( $ids, $to_archive ) {
		global $wpdb;
		$ids = array_map( 'absint', $ids );
		$in  = implode( ',', $ids );
		$map = array();
		foreach ( Orderloft_Schema::table_map() as $table ) {
			$map[ $table['kind'] . ':' . $table['source'] ] = array(
				'kind' => $table['kind'],
				'from' => $to_archive ? $table['source'] : $table['archive'],
				'to'   => $to_archive ? $table['archive'] : $table['source'],
			);
		}
		$from_of = function ( $kind ) use ( $map ) {
			foreach ( $map as $t ) {
				if ( $kind === $t['kind'] ) {
					return $t['from'];
				}
			}
			return '';
		};

		$plan = array();
		foreach ( $map as $key => $t ) {
			switch ( $t['kind'] ) {
				case 'hpos_orders':
					$where = "id IN ($in)";
					break;
				case 'posts':
					$where = "ID IN ($in) AND post_type IN ('shop_order','shop_order_refund')";
					break;
				case 'postmeta':
					$where = 'post_id IN (SELECT ID FROM `' . esc_sql( $from_of( 'posts' ) ) . "` WHERE ID IN ($in) AND post_type IN ('shop_order','shop_order_refund'))";
					break;
				case 'itemmeta':
					$where = 'order_item_id IN (SELECT order_item_id FROM `' . esc_sql( $from_of( 'items' ) ) . "` WHERE order_id IN ($in))";
					break;
				case 'comments':
					$where = "comment_post_ID IN ($in) AND comment_type = 'order_note'";
					break;
				case 'commentmeta':
					$where = 'comment_id IN (SELECT comment_ID FROM `' . esc_sql( $from_of( 'comments' ) ) . "` WHERE comment_post_ID IN ($in) AND comment_type = 'order_note')";
					break;
				default: // hpos_child, items.
					$where = "order_id IN ($in)";
			}
			$plan[ $key ] = $t + array( 'where' => $where );
		}

		// Copy everything first; meta rows are selected through their parent rows, which still exist at this point.
		foreach ( $plan as $key => $t ) {
			$cols = array_values( array_intersect( self::cols( $t['from'] ), self::cols( $t['to'] ) ) );
			$ph   = implode( ',', array_fill( 0, count( $cols ), '%i' ) );
			// Column names are passed as %i identifiers; the WHERE clause holds only absint() IDs and fixed SQL.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$copied = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (' . $ph . ') SELECT ' . $ph . ' FROM %i WHERE ' . $t['where'], array_merge( array( $t['to'] ), $cols, $cols, array( $t['from'] ) ) ) );
			if ( false === $copied ) {
				return new WP_Error( 'orderloft_copy', $wpdb->last_error );
			}
			$plan[ $key ]['copied'] = (int) $copied;
		}

		// Then delete: meta first (their WHERE reads the parent tables), then everything else.
		$order = array();
		foreach ( $plan as $key => $t ) {
			$order[ in_array( $t['kind'], array( 'itemmeta', 'commentmeta', 'postmeta' ), true ) ? 0 : 1 ][] = $key;
		}
		ksort( $order );
		foreach ( $order as $keys ) {
			foreach ( $keys as $key ) {
				$t = $plan[ $key ];
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are absint(), table names come from table_map().
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$t['where']}", $t['from'] ) );
				if ( false === $deleted ) {
					return new WP_Error( 'orderloft_delete', $wpdb->last_error );
				}
				if ( (int) $deleted !== $t['copied'] ) {
					// Something changed between copy and delete: abort so nothing is lost.
					return new WP_Error( 'orderloft_mismatch', __( 'Row counts did not match while moving the order, so the move was undone.', 'orderloft' ) );
				}
			}
		}
		return true;
	}

	/**
	 * Column names of a table, cached per request.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	private static function cols( $table ) {
		if ( ! isset( self::$columns[ $table ] ) ) {
			self::$columns[ $table ] = Orderloft_Schema::columns( $table );
		}
		return self::$columns[ $table ];
	}

	/**
	 * Clear WooCommerce and WordPress caches for moved orders.
	 *
	 * @param int[] $ids         Order and refund IDs.
	 * @param int   $customer_id Customer user ID.
	 */
	private static function clear_caches( $ids, $customer_id ) {
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'orders' );
			clean_post_cache( $id );
			if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) && function_exists( 'wc_get_container' ) ) {
				try {
					wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
				} catch ( \Throwable $e ) {
					unset( $e ); // Cache class not available in this WooCommerce version.
				}
			}
		}
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCountCache::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCountCache::class )->flush( 'shop_order' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		if ( $customer_id ) {
			foreach ( array( 'wc_order_count', 'wc_last_order', 'wc_money_spent' ) as $meta ) {
				delete_user_meta( $customer_id, $meta );
			}
		}
		wc_delete_shop_order_transients();
	}

	/**
	 * Read-only summary of an order, stored with the archive for display, search and export.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function snapshot( $order ) {
		$items = array();
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping', 'coupon' ) ) as $item ) {
			$row = array(
				'type'  => $item->get_type(),
				'name'  => $item->get_name(),
				'qty'   => method_exists( $item, 'get_quantity' ) ? (float) $item->get_quantity() : 1,
				'total' => (float) $item->get_total(),
			);
			if ( 'line_item' === $item->get_type() && $item->get_product() ) {
				$row['sku'] = $item->get_product()->get_sku();
			}
			$items[] = $row;
		}
		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refunds[] = array(
				'id'     => $refund->get_id(),
				'amount' => (float) $refund->get_amount(),
				'reason' => $refund->get_reason(),
				'date'   => $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : null,
			);
		}
		return array(
			'number'         => (string) $order->get_order_number(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => (float) $order->get_total(),
			'subtotal'       => (float) $order->get_subtotal(),
			'shipping'       => (float) $order->get_shipping_total(),
			'tax'            => (float) $order->get_total_tax(),
			'discount'       => (float) $order->get_discount_total(),
			'refunded'       => (float) $order->get_total_refunded(),
			'payment'        => $order->get_payment_method_title(),
			'created'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null,
			'paid'           => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : null,
			'completed'      => $order->get_date_completed() ? $order->get_date_completed()->getTimestamp() : null,
			'customer_id'    => $order->get_customer_id(),
			'billing'        => $order->get_formatted_billing_address(),
			'billing_email'  => $order->get_billing_email(),
			'billing_phone'  => $order->get_billing_phone(),
			'shipping_addr'  => $order->get_formatted_shipping_address(),
			'customer_note'  => $order->get_customer_note(),
			'items'          => $items,
			'refunds'        => $refunds,
			'transaction_id' => $order->get_transaction_id(),
		);
	}

	/**
	 * One archived order's index row.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null
	 */
	public static function get_archived( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Orderloft_Schema::index_table(), $order_id ) );
	}

	/**
	 * Number of archived orders.
	 *
	 * @return int
	 */
	public static function archived_count() {
		global $wpdb;
		if ( ! Orderloft_Schema::table_exists( Orderloft_Schema::index_table() ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Orderloft_Schema::index_table() ) );
	}
}
