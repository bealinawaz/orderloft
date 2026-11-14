<?php
/**
 * WP-CLI commands.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive and restore WooCommerce orders from the command line.
 */
class Orderloft_CLI {

	/**
	 * Show order storage, archive size and how many orders match the rules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp orderloft status
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function status( $args, $assoc_args ) {
		$settings = Orderloft_Settings::all();
		WP_CLI::line( 'Order storage:    ' . Orderloft_Archiver::storage_mode() );
		WP_CLI::line( 'Archived orders:  ' . Orderloft_Archiver::archived_count() );
		WP_CLI::line( 'Ready to archive: ' . Orderloft_Archiver::count_candidates( $settings ) );
		WP_CLI::line( 'Rules:            older than ' . $settings['age_months'] . ' months, statuses ' . implode( ', ', (array) $settings['statuses'] ) );
		WP_CLI::line( 'Automatic:        ' . $settings['auto'] );
		$check = Orderloft_Archiver::preflight();
		if ( is_wp_error( $check ) ) {
			WP_CLI::warning( $check->get_error_message() );
		}
		unset( $args, $assoc_args );
	}

	/**
	 * Archive orders that match the rules.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<months>]
	 * : Override the age rule (months).
	 *
	 * [--status=<statuses>]
	 * : Override the statuses, comma-separated (for example completed,cancelled).
	 *
	 * [--limit=<number>]
	 * : Most orders to archive. Default: all matching orders.
	 *
	 * [--batch=<number>]
	 * : Orders per batch. Default: 100.
	 *
	 * [--dry-run]
	 * : Only count the matching orders.
	 *
	 * ## EXAMPLES
	 *
	 *     wp orderloft archive --older-than=24 --dry-run
	 *     wp orderloft archive --status=completed,refunded --limit=5000
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function archive( $args, $assoc_args ) {
		$settings = Orderloft_Settings::all();
		if ( isset( $assoc_args['older-than'] ) ) {
			$settings['age_months'] = max( 1, absint( $assoc_args['older-than'] ) );
		}
		if ( isset( $assoc_args['status'] ) ) {
			$settings['statuses'] = array_filter( array_map( 'sanitize_key', explode( ',', $assoc_args['status'] ) ) );
		}
		$total = Orderloft_Archiver::count_candidates( $settings );
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : $total;
		$limit = min( $limit, $total );
		WP_CLI::line( "Matching orders: {$total}" );
		if ( isset( $assoc_args['dry-run'] ) || ! $limit ) {
			return;
		}
		$check = Orderloft_Archiver::preflight();
		if ( is_wp_error( $check ) ) {
			WP_CLI::error( $check->get_error_message() );
		}
		$batch    = isset( $assoc_args['batch'] ) ? max( 1, absint( $assoc_args['batch'] ) ) : 100;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Archiving', $limit );
		$totals   = array(
			'archived' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);
		while ( ( $totals['archived'] + $totals['skipped'] + $totals['failed'] ) < $limit ) {
			$left  = $limit - ( $totals['archived'] + $totals['skipped'] + $totals['failed'] );
			$stats = Orderloft_Archiver::run_batch( $settings, min( $batch, $left ) );
			$done  = $stats['archived'] + $stats['skipped'] + $stats['failed'];
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += $stats[ $key ];
			}
			for ( $i = 0; $i < $done; $i++ ) {
				$progress->tick();
			}
			if ( $stats['error'] ) {
				WP_CLI::warning( $stats['error'] );
			}
			if ( ! $done ) {
				break;
			}
		}
		$progress->finish();
		WP_CLI::success( sprintf( 'Archived %d, skipped %d, failed %d.', $totals['archived'], $totals['skipped'], $totals['failed'] ) );
	}

	/**
	 * Restore archived orders.
	 *
	 * ## OPTIONS
	 *
	 * [<order-id>...]
	 * : IDs of archived orders.
	 *
	 * [--all]
	 * : Restore every archived order.
	 *
	 * ## EXAMPLES
	 *
	 *     wp orderloft restore 1234 1235
	 *     wp orderloft restore --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function restore( $args, $assoc_args ) {
		global $wpdb;
		$ids = array_map( 'absint', $args );
		if ( isset( $assoc_args['all'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i ORDER BY order_id', Orderloft_Schema::index_table() ) ) );
		}
		if ( ! $ids ) {
			WP_CLI::error( 'Give order IDs or --all.' );
		}
		$ok = 0;
		foreach ( $ids as $id ) {
			$result = Orderloft_Archiver::restore_order( $id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
			} else {
				++$ok;
			}
		}
		WP_CLI::success( sprintf( 'Restored %d of %d.', $ok, count( $ids ) ) );
	}
}
