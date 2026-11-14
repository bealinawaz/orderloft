<?php
/**
 * Background runs through Action Scheduler (bundled with WooCommerce).
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the daily automatic run and processes archive runs in small batches.
 */
class Orderloft_Scheduler {

	const GROUP      = 'orderloft';
	const DAILY_HOOK = 'orderloft_daily';
	const BATCH_HOOK = 'orderloft_batch';
	const RUN_OPTION = 'orderloft_run';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::DAILY_HOOK, array( __CLASS__, 'start_run' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
		add_action( 'update_option_' . Orderloft_Settings::OPTION, array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Schedule or remove the daily run to match the "auto" setting.
	 */
	public static function sync_schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$auto      = 'yes' === Orderloft_Settings::get( 'auto' );
		$scheduled = as_next_scheduled_action( self::DAILY_HOOK, array(), self::GROUP );
		if ( $auto && ! $scheduled ) {
			// 03:00 site time tomorrow, when stores are usually quiet.
			$next = ( new DateTime( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp();
			as_schedule_recurring_action( $next, DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP );
		} elseif ( ! $auto && $scheduled ) {
			as_unschedule_all_actions( self::DAILY_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Start a run: reset today's counters and queue the first batch.
	 *
	 * @param string $source auto or manual.
	 */
	public static function start_run( $source = 'auto' ) {
		$source = 'manual' === $source ? 'manual' : 'auto';
		if ( self::is_running() ) {
			return;
		}
		update_option(
			self::RUN_OPTION,
			array(
				'status'   => 'running',
				'source'   => $source,
				'started'  => time(),
				'updated'  => time(),
				'finished' => 0,
				'archived' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'error'    => '',
			),
			false
		);
		as_enqueue_async_action( self::BATCH_HOOK, array(), self::GROUP );
	}

	/**
	 * Whether a run is in progress.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$run = self::last_run();
		// A run that has not reported for an hour is treated as stopped, so a crash can't block runs forever.
		return 'running' === $run['status'] && ( time() - (int) $run['updated'] ) < HOUR_IN_SECONDS;
	}

	/**
	 * State of the current or last run.
	 *
	 * @return array
	 */
	public static function last_run() {
		$run = get_option( self::RUN_OPTION, array() );
		return wp_parse_args(
			is_array( $run ) ? $run : array(),
			array(
				'status'   => 'idle',
				'source'   => '',
				'started'  => 0,
				'updated'  => 0,
				'finished' => 0,
				'archived' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'error'    => '',
			)
		);
	}

	/**
	 * Process one batch and queue the next one until nothing matches or the daily limit is reached.
	 */
	public static function process_batch() {
		$settings = Orderloft_Settings::all();
		$run      = self::last_run();
		if ( 'running' !== $run['status'] ) {
			return;
		}
		$room  = max( 0, (int) $settings['daily_limit'] - (int) $run['archived'] );
		$limit = min( (int) $settings['batch_size'], $room );

		$stats = $limit > 0 ? Orderloft_Archiver::run_batch( $settings, $limit ) : array(
			'archived' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'error'    => '',
		);

		$run['archived'] += $stats['archived'];
		$run['skipped']  += $stats['skipped'];
		$run['failed']   += $stats['failed'];
		$run['updated']   = time();
		if ( $stats['error'] ) {
			$run['error'] = $stats['error'];
		}

		$touched = $stats['archived'] + $stats['skipped'] + $stats['failed'];
		$more    = $limit > 0 && $touched >= $limit && $run['archived'] < (int) $settings['daily_limit'];
		if ( $more ) {
			as_enqueue_async_action( self::BATCH_HOOK, array(), self::GROUP );
		} else {
			$run['status']   = 'done';
			$run['finished'] = time();
		}
		update_option( self::RUN_OPTION, $run, false );
	}

	/**
	 * Stop a running run (the current batch finishes first).
	 */
	public static function stop_run() {
		$run = self::last_run();
		if ( 'running' === $run['status'] ) {
			$run['status']   = 'stopped';
			$run['finished'] = time();
			update_option( self::RUN_OPTION, $run, false );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Remove every scheduled action (deactivation).
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::DAILY_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::BATCH_HOOK, array(), self::GROUP );
		}
	}
}
