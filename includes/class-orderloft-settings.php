<?php
/**
 * Settings storage and defaults.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the plugin settings (one option, an array).
 */
class Orderloft_Settings {

	const OPTION = 'orderloft_settings';

	/**
	 * Default settings. Only closed statuses are archived by default, so nothing a shop still works on disappears.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'age_months'         => 12,
			'statuses'           => array( 'completed', 'refunded', 'cancelled', 'failed' ),
			'auto'               => 'no',
			'batch_size'         => 50,
			'daily_limit'        => 2000,
			'skip_subscriptions' => 'yes',
			'customer_view'      => 'yes',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Statuses that may be chosen for archiving: every registered order status except drafts.
	 *
	 * @return array slug (without wc- prefix) => label
	 */
	public static function archivable_statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = 'wc-' === substr( $slug, 0, 3 ) ? substr( $slug, 3 ) : $slug;
			if ( in_array( $slug, array( 'checkout-draft', 'draft', 'auto-draft' ), true ) ) {
				continue;
			}
			$out[ $slug ] = $label;
		}
		return $out;
	}

	/**
	 * Sanitize settings submitted from the settings form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$allowed  = array_keys( self::archivable_statuses() );

		$statuses = isset( $input['statuses'] ) ? array_map( 'sanitize_key', (array) $input['statuses'] ) : array();
		$statuses = array_values( array_intersect( $statuses, $allowed ) );

		return array(
			'age_months'         => isset( $input['age_months'] ) ? max( 1, min( 240, absint( $input['age_months'] ) ) ) : $defaults['age_months'],
			'statuses'           => $statuses,
			'auto'               => ! empty( $input['auto'] ) ? 'yes' : 'no',
			'batch_size'         => isset( $input['batch_size'] ) ? max( 5, min( 500, absint( $input['batch_size'] ) ) ) : $defaults['batch_size'],
			'daily_limit'        => isset( $input['daily_limit'] ) ? max( 10, min( 100000, absint( $input['daily_limit'] ) ) ) : $defaults['daily_limit'],
			'skip_subscriptions' => ! empty( $input['skip_subscriptions'] ) ? 'yes' : 'no',
			'customer_view'      => ! empty( $input['customer_view'] ) ? 'yes' : 'no',
		);
	}
}
