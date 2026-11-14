<?php
/**
 * Archive tables: one mirror table per WooCommerce order table, plus an index of archived orders.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and maintains the archive tables.
 *
 * Each archive table is created with CREATE TABLE ... LIKE from its WooCommerce source table, so it has the same
 * columns and keys. When WooCommerce later adds a column to a source table, sync_columns() adds it to the archive.
 */
class Orderloft_Schema {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'orderloft_db_version';

	/**
	 * Activation: create the index table and the archive tables.
	 */
	public static function install() {
		self::create_index_table();
		foreach ( self::table_map() as $table ) {
			self::ensure_archive_table( $table['source'], $table['archive'] );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( false === get_option( Orderloft_Settings::OPTION ) ) {
			add_option( Orderloft_Settings::OPTION, Orderloft_Settings::defaults(), '', false );
		}
	}

	/**
	 * Run install again after an update that changes the schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Name of the archived-orders index table.
	 *
	 * @return string
	 */
	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'orderloft_index';
	}

	/**
	 * Create the index table, which lists archived orders with a snapshot for search, display and export.
	 */
	public static function create_index_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::index_table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			order_id bigint(20) unsigned NOT NULL,
			order_number varchar(100) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT '',
			currency varchar(10) NOT NULL DEFAULT '',
			total decimal(26,8) NOT NULL DEFAULT 0,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			billing_email varchar(320) NOT NULL DEFAULT '',
			billing_name varchar(255) NOT NULL DEFAULT '',
			date_created_gmt datetime NULL,
			archived_gmt datetime NULL,
			child_ids text NULL,
			storage varchar(20) NOT NULL DEFAULT '',
			summary longtext NULL,
			PRIMARY KEY  (order_id),
			KEY customer_id (customer_id),
			KEY billing_email (billing_email(191)),
			KEY date_created_gmt (date_created_gmt),
			KEY archived_gmt (archived_gmt)
			) {$collate};"
		);
	}

	/**
	 * Every WooCommerce table that holds part of an order, with its archive table and how its rows are selected.
	 *
	 * Kinds:
	 * - hpos_orders: HPOS orders table, selected by id.
	 * - hpos_child:  HPOS addresses, operational data and meta, selected by order_id.
	 * - posts:       wp_posts order rows (legacy storage or HPOS compatibility copies), by ID and order post types.
	 *                HPOS placeholder rows (shop_order_placehold) are never moved: they keep the order IDs reserved.
	 * - postmeta:    wp_postmeta of those posts.
	 * - items:       order items, by order_id.
	 * - itemmeta:    order item meta, by the moved items.
	 * - comments:    order notes, by comment_post_ID and comment_type.
	 * - commentmeta: meta of the moved notes.
	 *
	 * Only tables that exist on this site are returned.
	 *
	 * @return array[] Each: source, archive, kind.
	 */
	public static function table_map() {
		global $wpdb;
		$p   = $wpdb->prefix;
		$map = array(
			array( "{$p}wc_orders", "{$p}orderloft_orders", 'hpos_orders' ),
			array( "{$p}wc_order_addresses", "{$p}orderloft_order_addresses", 'hpos_child' ),
			array( "{$p}wc_order_operational_data", "{$p}orderloft_order_operational_data", 'hpos_child' ),
			array( "{$p}wc_orders_meta", "{$p}orderloft_orders_meta", 'hpos_child' ),
			array( $wpdb->posts, "{$p}orderloft_posts", 'posts' ),
			array( $wpdb->postmeta, "{$p}orderloft_postmeta", 'postmeta' ),
			array( "{$p}woocommerce_order_items", "{$p}orderloft_order_items", 'items' ),
			array( "{$p}woocommerce_order_itemmeta", "{$p}orderloft_order_itemmeta", 'itemmeta' ),
			array( $wpdb->comments, "{$p}orderloft_comments", 'comments' ),
			array( $wpdb->commentmeta, "{$p}orderloft_commentmeta", 'commentmeta' ),
		);
		$out = array();
		foreach ( $map as $row ) {
			if ( self::table_exists( $row[0] ) ) {
				$out[] = array(
					'source'  => $row[0],
					'archive' => $row[1],
					'kind'    => $row[2],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Create the archive table from its source if needed, then add any columns the source gained since.
	 *
	 * @param string $source  Source table.
	 * @param string $archive Archive table.
	 */
	public static function ensure_archive_table( $source, $archive ) {
		global $wpdb;
		if ( ! self::table_exists( $archive ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating the plugin's own archive table.
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $archive, $source ) );
			return;
		}
		self::sync_columns( $source, $archive );
	}

	/**
	 * Add columns that exist in the source table but not in the archive table.
	 *
	 * @param string $source  Source table.
	 * @param string $archive Archive table.
	 */
	public static function sync_columns( $source, $archive ) {
		global $wpdb;
		$src = self::columns( $source, true );
		$dst = self::columns( $archive );
		foreach ( $src as $name => $col ) {
			if ( in_array( $name, $dst, true ) ) {
				continue;
			}
			// Only plain column types such as bigint(20) unsigned, varchar(100), decimal(26,8) or longtext are copied.
			if ( ! preg_match( '/^[a-z]+(\(\d+(,\d+)?\))?( unsigned)?$/i', $col['type'] ) ) {
				continue;
			}
			$type = esc_sql( $col['type'] );
			// Columns added later are created as NULL-able without defaults, so existing archived rows stay valid.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Type matched the whitelist pattern above.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i ' . $type . ' NULL', $archive, $name ) );
		}
	}

	/**
	 * Column names of a table (or name => definition when $details is true).
	 *
	 * @param string $table   Table name.
	 * @param bool   $details Return type details too.
	 * @return array
	 */
	public static function columns( $table, $details = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( $details ) {
				$out[ $row['Field'] ] = array(
					'type' => $row['Type'],
					'null' => $row['Null'],
				);
			} else {
				$out[] = $row['Field'];
			}
		}
		return $out;
	}

	/**
	 * Tables whose engine does not support transactions. Orderloft refuses to run when any order table is in this list.
	 *
	 * @return string[]
	 */
	public static function non_transactional_tables() {
		global $wpdb;
		$names = array();
		foreach ( self::table_map() as $t ) {
			$names[] = $t['source'];
			$names[] = $t['archive'];
		}
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", $names ), ARRAY_A );
		$bad  = array();
		foreach ( (array) $rows as $row ) {
			if ( 'innodb' !== strtolower( (string) $row['ENGINE'] ) ) {
				$bad[] = $row['TABLE_NAME'];
			}
		}
		return $bad;
	}

	/**
	 * Drop all archive tables. Only used by uninstall when the archive is empty.
	 */
	public static function drop_all() {
		global $wpdb;
		$tables   = wp_list_pluck( self::table_map(), 'archive' );
		$tables[] = self::index_table();
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
