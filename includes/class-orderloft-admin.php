<?php
/**
 * Admin screens: WooCommerce > Order Archive.
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview, archived orders, settings, the order-screen box and redirects for archived order links.
 */
class Orderloft_Admin {

	const PAGE = 'orderloft';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_archived_order_links' ) );
		add_action( 'admin_post_orderloft_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_orderloft_stop', array( __CLASS__, 'handle_stop' ) );
		add_action( 'admin_post_orderloft_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_orderloft_archive_one', array( __CLASS__, 'handle_archive_one' ) );
		add_action( 'admin_post_orderloft_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_orderloft_reset_skips', array( __CLASS__, 'handle_reset_skips' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_box' ), 30 );
		add_action( 'admin_notices', array( __CLASS__, 'orders_screen_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ORDERLOFT_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/**
	 * Admin URL of the plugin page.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'orderloft' ) . '</a>' );
		return $links;
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Order Archive', 'orderloft' ),
			__( 'Order Archive', 'orderloft' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
		add_action( 'load-' . self::$hook, array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Stylesheet on the plugin page.
	 *
	 * @param string $hook Current page hook.
	 */
	public static function assets( $hook ) {
		if ( $hook === self::$hook ) {
			wp_enqueue_style( 'orderloft-admin', plugins_url( 'assets/admin.css', ORDERLOFT_FILE ), array(), ORDERLOFT_VERSION );
		}
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings() {
		register_setting(
			'orderloft',
			Orderloft_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Orderloft_Settings', 'sanitize' ),
				'default'           => Orderloft_Settings::defaults(),
			)
		);
	}

	/**
	 * Store a notice for the current user, shown on the next page load.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Message.
	 */
	private static function flash( $type, $message ) {
		set_transient( 'orderloft_notice_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	/**
	 * Print and clear the stored notice.
	 */
	private static function print_flash() {
		$key    = 'orderloft_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	/**
	 * Stop unless the user may manage WooCommerce and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'orderloft' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Start a manual run.
	 */
	public static function handle_run() {
		self::guard( 'orderloft_run' );
		$check = Orderloft_Archiver::preflight();
		if ( is_wp_error( $check ) ) {
			self::flash( 'error', $check->get_error_message() );
		} elseif ( Orderloft_Scheduler::is_running() ) {
			self::flash( 'info', __( 'A run is already in progress.', 'orderloft' ) );
		} else {
			Orderloft_Scheduler::start_run( 'manual' );
			self::flash( 'success', __( 'Archiving started. It runs in the background in small batches, so you can leave this page.', 'orderloft' ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Stop a run.
	 */
	public static function handle_stop() {
		self::guard( 'orderloft_stop' );
		Orderloft_Scheduler::stop_run();
		self::flash( 'info', __( 'Archiving stopped after the current batch.', 'orderloft' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Clear the skip marks so skipped orders are checked again.
	 */
	public static function handle_reset_skips() {
		self::guard( 'orderloft_reset_skips' );
		$ids = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'limit'    => -1,
				'return'   => 'ids',
				'meta_key' => Orderloft_Archiver::SKIP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-triggered one-off.
			)
		);
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete_meta_data( Orderloft_Archiver::SKIP_META );
				$order->save_meta_data();
			}
		}
		/* translators: %d: number of orders. */
		self::flash( 'success', sprintf( _n( '%d skipped order will be checked again on the next run.', '%d skipped orders will be checked again on the next run.', count( (array) $ids ), 'orderloft' ), count( (array) $ids ) ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Restore one order.
	 */
	public static function handle_restore() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'orderloft_restore_' . $order_id );
		$result = Orderloft_Archiver::restore_order( $order_id );
		if ( is_wp_error( $result ) ) {
			self::flash( 'orderloft_sync' === $result->get_error_code() ? 'warning' : 'error', $result->get_error_message() );
			wp_safe_redirect( self::url( array( 'tab' => 'archived' ) ) );
			exit;
		}
		/* translators: %d: order ID. */
		self::flash( 'success', sprintf( __( 'Order #%d was restored to your live orders.', 'orderloft' ), $order_id ) );
		$order = wc_get_order( $order_id );
		wp_safe_redirect( $order ? $order->get_edit_order_url() : self::url( array( 'tab' => 'archived' ) ) );
		exit;
	}

	/**
	 * Archive one order from its edit screen.
	 */
	public static function handle_archive_one() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'orderloft_archive_' . $order_id );
		$result = Orderloft_Archiver::archive_order( $order_id );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			$order = wc_get_order( $order_id );
			wp_safe_redirect( $order ? $order->get_edit_order_url() : self::url() );
			exit;
		}
		/* translators: %d: order ID. */
		self::flash( 'success', sprintf( __( 'Order #%d was archived.', 'orderloft' ), $order_id ) );
		wp_safe_redirect(
			self::url(
				array(
					'tab'  => 'archived',
					'view' => $order_id,
				)
			)
		);
		exit;
	}

	/**
	 * Bulk restore from the archived orders list.
	 */
	public static function handle_bulk() {
		$action = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce is checked below before acting.
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && 'orderloft_restore' === $_REQUEST[ $key ] ) {
				$action = 'orderloft_restore';
			}
		}
		if ( 'orderloft_restore' !== $action || empty( $_REQUEST['order_ids'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		self::guard( 'bulk-archived_orders' );
		$ids    = array_map( 'absint', (array) wp_unslash( $_REQUEST['order_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in guard() above.
		$done   = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			$result = Orderloft_Archiver::restore_order( $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$done;
			}
		}
		/* translators: %d: number of orders. */
		$message = sprintf( _n( '%d order was restored.', '%d orders were restored.', $done, 'orderloft' ), $done );
		if ( $errors ) {
			$message .= ' ' . implode( ' ', array_unique( $errors ) );
		}
		self::flash( $errors ? 'warning' : 'success', $message );
		wp_safe_redirect( self::url( array( 'tab' => 'archived' ) ) );
		exit;
	}

	/**
	 * CSV export of the archived orders (all, or the current search).
	 */
	public static function handle_export() {
		self::guard( 'orderloft_export' );
		list( $rows ) = Orderloft_List_Table::query( Orderloft_List_Table::search_term(), 'date_created_gmt', 'ASC', 1000000, 0, true );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=orderloft-archived-orders-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the CSV download.
		fputcsv( $out, array( 'order_id', 'order_number', 'date_created_gmt', 'status', 'total', 'currency', 'customer_id', 'billing_name', 'billing_email', 'items', 'archived_gmt' ) );
		foreach ( (array) $rows as $row ) {
			$summary = json_decode( (string) $row['summary'], true );
			$items   = array();
			foreach ( is_array( $summary ) && isset( $summary['items'] ) ? $summary['items'] : array() as $item ) {
				if ( 'line_item' === ( $item['type'] ?? '' ) ) {
					$items[] = $item['name'] . ' x ' . $item['qty'];
				}
			}
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array( $row['order_id'], $row['order_number'], $row['date_created_gmt'], $row['status'], $row['total'], $row['currency'], $row['customer_id'], $row['billing_name'], $row['billing_email'], implode( '; ', $items ), $row['archived_gmt'] )
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the output stream.
		exit;
	}

	/**
	 * Prevent spreadsheet formula injection in CSV cells.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $value ) ) ? "'" . $value : $value;
	}

	/**
	 * Archived order edit links (from emails, notes or bookmarks) open the archived copy instead of an error.
	 */
	public static function redirect_archived_order_links() {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
		$id = 0;
		if ( 'admin.php' === $pagenow && isset( $_GET['page'], $_GET['action'], $_GET['id'] ) && 'wc-orders' === $_GET['page'] && 'edit' === $_GET['action'] ) {
			$id = absint( $_GET['id'] );
		} elseif ( 'post.php' === $pagenow && isset( $_GET['post'], $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			$id = absint( $_GET['post'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $id || ! current_user_can( 'manage_woocommerce' ) || wc_get_order( $id ) || ! Orderloft_Archiver::get_archived( $id ) ) {
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'tab'  => 'archived',
					'view' => $id,
				)
			)
		);
		exit;
	}

	/**
	 * A line on the Orders screen pointing to the archive.
	 */
	public static function orders_screen_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$ids = array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' );
		if ( ! in_array( $screen->id, $ids, true ) ) {
			return;
		}
		// Messages from a restore that redirected to the order screen.
		self::print_flash();
		// The archive line belongs on the order list only, not on a single order's edit screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current screen.
		if ( 'shop_order' === $screen->id || isset( $_GET['action'] ) ) {
			return;
		}
		$count = Orderloft_Archiver::archived_count();
		if ( ! $count ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: %s: number of archived orders. */
			esc_html( sprintf( _n( '%s older order is in the archive.', '%s older orders are in the archive.', $count, 'orderloft' ), number_format_i18n( $count ) ) ),
			esc_url( self::url( array( 'tab' => 'archived' ) ) ),
			esc_html__( 'Search archived orders', 'orderloft' )
		);
	}

	/**
	 * "Archive this order" box on the order edit screen.
	 */
	public static function add_order_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'orderloft-archive', __( 'Order archive', 'orderloft' ), array( __CLASS__, 'render_order_box' ), $screen, 'side', 'low' );
		}
	}

	/**
	 * Box contents.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_order_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! $order->get_id() || 'auto-draft' === $order->get_status() ) {
			echo '<p>' . esc_html__( 'Save the order before archiving it.', 'orderloft' ) . '</p>';
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_archive_one&order_id=' . $order->get_id() ), 'orderloft_archive_' . $order->get_id() );
		echo '<p>' . esc_html__( 'Move this order and its refunds out of your live orders. You can restore it at any time.', 'orderloft' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Archive this order', 'orderloft' ) . '</a></p>';
	}

	/**
	 * Page router.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview' => __( 'Overview', 'orderloft' ),
			'archived' => __( 'Archived orders', 'orderloft' ),
			'settings' => __( 'Settings', 'orderloft' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap orderloft">';
		echo '<h1>' . esc_html__( 'Order Archive', 'orderloft' ) . '</h1>';
		self::print_flash();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( array( 'tab' => $key ) ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		if ( 'archived' === $tab && $view ) {
			self::render_view( $view );
		} elseif ( 'archived' === $tab ) {
			self::render_archived();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	/**
	 * Human label of the order storage.
	 *
	 * @return string
	 */
	private static function storage_label() {
		$labels = array(
			'hpos'      => __( 'High-performance order storage (HPOS)', 'orderloft' ),
			'hpos_sync' => __( 'HPOS with compatibility mode (orders are also copied to the posts table)', 'orderloft' ),
			'posts'     => __( 'WordPress posts storage (legacy)', 'orderloft' ),
		);
		return $labels[ Orderloft_Archiver::storage_mode() ];
	}

	/**
	 * Overview tab.
	 */
	private static function render_overview() {
		$settings   = Orderloft_Settings::all();
		$check      = Orderloft_Archiver::preflight();
		$candidates = Orderloft_Archiver::count_candidates( $settings );
		$archived   = Orderloft_Archiver::archived_count();
		$live       = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);
		$skipped    = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
				'meta_key' => Orderloft_Archiver::SKIP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin overview count.
			)
		);
		$run        = Orderloft_Scheduler::last_run();
		$statuses   = array_map( 'wc_get_order_status_name', (array) $settings['statuses'] );

		if ( is_wp_error( $check ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $check->get_error_message() ) . '</p></div>';
		}
		?>
		<div class="orderloft-cards">
			<div class="orderloft-card"><span class="orderloft-num"><?php echo esc_html( number_format_i18n( (int) $live->total ) ); ?></span><span><?php esc_html_e( 'Live orders', 'orderloft' ); ?></span></div>
			<div class="orderloft-card"><span class="orderloft-num"><?php echo esc_html( number_format_i18n( $archived ) ); ?></span><span><?php esc_html_e( 'Archived orders', 'orderloft' ); ?></span></div>
			<div class="orderloft-card orderloft-card-accent"><span class="orderloft-num"><?php echo esc_html( number_format_i18n( $candidates ) ); ?></span><span><?php esc_html_e( 'Ready to archive', 'orderloft' ); ?></span></div>
		</div>

		<h2><?php esc_html_e( 'Your archive rules', 'orderloft' ); ?></h2>
		<p>
			<?php
			if ( $statuses ) {
				echo esc_html(
					sprintf(
						/* translators: 1: number of months, 2: list of order statuses. */
						_n( 'Orders older than %1$d month with status: %2$s.', 'Orders older than %1$d months with status: %2$s.', (int) $settings['age_months'], 'orderloft' ),
						(int) $settings['age_months'],
						implode( ', ', $statuses )
					)
				);
			} else {
				esc_html_e( 'No order statuses are selected, so nothing will be archived.', 'orderloft' );
			}
			echo ' ';
			echo 'yes' === $settings['auto'] ? esc_html__( 'Automatic archiving runs every night.', 'orderloft' ) : esc_html__( 'Automatic archiving is off.', 'orderloft' );
			?>
			<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Change rules', 'orderloft' ); ?></a>
		</p>

		<div class="orderloft-actions">
			<?php if ( Orderloft_Scheduler::is_running() ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_stop' ), 'orderloft_stop' ) ); ?>"><?php esc_html_e( 'Stop archiving', 'orderloft' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Refresh progress', 'orderloft' ); ?></a>
			<?php else : ?>
				<a class="button button-primary<?php echo ( $candidates && ! is_wp_error( $check ) ) ? '' : ' disabled'; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_run' ), 'orderloft_run' ) ); ?>">
					<?php
					/* translators: %s: number of orders. */
					echo esc_html( sprintf( _n( 'Archive %s order now', 'Archive %s orders now', $candidates, 'orderloft' ), number_format_i18n( min( $candidates, (int) $settings['daily_limit'] ) ) ) );
					?>
				</a>
			<?php endif; ?>
		</div>
		<?php if ( $candidates > (int) $settings['daily_limit'] ) : ?>
			<p class="description">
				<?php
				/* translators: %s: daily limit. */
				echo esc_html( sprintf( __( 'Up to %s orders are archived per run (your daily limit). The rest follow on later runs.', 'orderloft' ), number_format_i18n( (int) $settings['daily_limit'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Last run', 'orderloft' ); ?></h2>
		<?php if ( $run['started'] ) : ?>
			<table class="widefat striped orderloft-run">
				<tbody>
					<tr><th><?php esc_html_e( 'Status', 'orderloft' ); ?></th><td><?php echo esc_html( self::run_status_label( $run ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Started', 'orderloft' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['started'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Archived', 'orderloft' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $run['archived'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Skipped', 'orderloft' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $run['skipped'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Failed', 'orderloft' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $run['failed'] ) . ( $run['error'] ? ' — ' . $run['error'] : '' ) ); ?></td></tr>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing has been archived yet.', 'orderloft' ); ?></p>
		<?php endif; ?>

		<?php if ( (int) $skipped->total > 0 ) : ?>
			<p>
				<?php
				/* translators: %s: number of orders. */
				echo esc_html( sprintf( _n( '%s order was skipped (it has an active subscription, belongs to another order, was restored, or failed to move).', '%s orders were skipped (they have an active subscription, belong to another order, were restored, or failed to move).', (int) $skipped->total, 'orderloft' ), number_format_i18n( (int) $skipped->total ) ) );
				?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_reset_skips' ), 'orderloft_reset_skips' ) ); ?>"><?php esc_html_e( 'Check them again', 'orderloft' ); ?></a>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'How archiving works', 'orderloft' ); ?></h2>
		<ul class="orderloft-facts">
			<li><?php esc_html_e( 'Each order moves with its refunds, items, notes, addresses and meta into archive tables in the same database, inside one transaction. If anything fails, nothing changes.', 'orderloft' ); ?></li>
			<li><?php esc_html_e( 'WooCommerce Analytics keeps counting archived orders, so your revenue reports stay complete.', 'orderloft' ); ?></li>
			<li><?php esc_html_e( 'Restore any order with one click. It comes back with the same ID, number and history.', 'orderloft' ); ?></li>
			<li><?php esc_html_e( 'Customers still see archived orders under My Account > Orders, as a read-only list.', 'orderloft' ); ?></li>
		</ul>
		<p class="description">
			<?php
			/* translators: %s: name of the order storage. */
			echo esc_html( sprintf( __( 'Order storage on this site: %s.', 'orderloft' ), self::storage_label() ) );
			?>
		</p>
		<?php
	}

	/**
	 * Label for the run status.
	 *
	 * @param array $run Run state.
	 * @return string
	 */
	private static function run_status_label( $run ) {
		$source = 'manual' === $run['source'] ? __( 'manual', 'orderloft' ) : __( 'automatic', 'orderloft' );
		switch ( $run['status'] ) {
			case 'running':
				/* translators: %s: manual or automatic. */
				return sprintf( __( 'Running (%s)', 'orderloft' ), $source );
			case 'stopped':
				/* translators: %s: manual or automatic. */
				return sprintf( __( 'Stopped (%s)', 'orderloft' ), $source );
			default:
				/* translators: %s: manual or automatic. */
				return sprintf( __( 'Finished (%s)', 'orderloft' ), $source );
		}
	}

	/**
	 * Archived orders tab.
	 */
	private static function render_archived() {
		$table = new Orderloft_List_Table();
		$table->prepare_items();
		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'orderloft_export',
					's'      => Orderloft_List_Table::search_term(),
				),
				admin_url( 'admin-post.php' )
			),
			'orderloft_export'
		);
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="archived" />
			<?php $table->search_box( __( 'Search archived orders', 'orderloft' ), 'orderloft-search' ); ?>
		</form>
		<p><a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export to CSV', 'orderloft' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( self::url( array( 'tab' => 'archived' ) ) ); ?>">
			<?php $table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Read-only view of one archived order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function render_view( $order_id ) {
		$row = Orderloft_Archiver::get_archived( $order_id );
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'This order is not in the archive.', 'orderloft' ) . '</p>';
			return;
		}
		$s        = json_decode( (string) $row->summary, true );
		$s        = is_array( $s ) ? $s : array();
		$currency = array( 'currency' => $row->currency );
		$date     = function ( $ts ) {
			return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts ) : '—';
		};
		$restore  = wp_nonce_url( admin_url( 'admin-post.php?action=orderloft_restore&order_id=' . absint( $row->order_id ) ), 'orderloft_restore_' . absint( $row->order_id ) );
		?>
		<p><a href="<?php echo esc_url( self::url( array( 'tab' => 'archived' ) ) ); ?>">&larr; <?php esc_html_e( 'All archived orders', 'orderloft' ); ?></a></p>
		<div class="orderloft-view">
			<div class="orderloft-view-head">
				<h2>
					<?php
					/* translators: %s: order number. */
					echo esc_html( sprintf( __( 'Order #%s', 'orderloft' ), $row->order_number ) );
					?>
					<mark class="order-status status-<?php echo esc_attr( $row->status ); ?>"><span><?php echo esc_html( wc_get_order_status_name( $row->status ) ); ?></span></mark>
				</h2>
				<a class="button button-primary" href="<?php echo esc_url( $restore ); ?>"><?php esc_html_e( 'Restore to live orders', 'orderloft' ); ?></a>
			</div>
			<p class="description">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Archived on %s. This is a read-only copy; restore the order to edit it.', 'orderloft' ), $date( strtotime( $row->archived_gmt . ' UTC' ) ) ) );
				?>
			</p>
			<div class="orderloft-view-grid">
				<div>
					<h3><?php esc_html_e( 'Details', 'orderloft' ); ?></h3>
					<p>
						<strong><?php esc_html_e( 'Created', 'orderloft' ); ?>:</strong> <?php echo esc_html( $date( $s['created'] ?? 0 ) ); ?><br />
						<strong><?php esc_html_e( 'Paid', 'orderloft' ); ?>:</strong> <?php echo esc_html( $date( $s['paid'] ?? 0 ) ); ?><br />
						<strong><?php esc_html_e( 'Completed', 'orderloft' ); ?>:</strong> <?php echo esc_html( $date( $s['completed'] ?? 0 ) ); ?><br />
						<strong><?php esc_html_e( 'Payment method', 'orderloft' ); ?>:</strong> <?php echo esc_html( $s['payment'] ?? '' ); ?><br />
						<?php if ( ! empty( $s['transaction_id'] ) ) : ?>
							<strong><?php esc_html_e( 'Transaction ID', 'orderloft' ); ?>:</strong> <?php echo esc_html( $s['transaction_id'] ); ?><br />
						<?php endif; ?>
						<strong><?php esc_html_e( 'Customer', 'orderloft' ); ?>:</strong>
						<?php
						if ( ! empty( $row->customer_id ) ) {
							$user = get_userdata( (int) $row->customer_id );
							echo $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : esc_html__( 'Deleted user', 'orderloft' );
						} else {
							esc_html_e( 'Guest', 'orderloft' );
						}
						?>
					</p>
				</div>
				<div>
					<h3><?php esc_html_e( 'Billing', 'orderloft' ); ?></h3>
					<p><?php echo wp_kses_post( $s['billing'] ?? '' ); ?><br /><?php echo esc_html( $row->billing_email ); ?><br /><?php echo esc_html( $s['billing_phone'] ?? '' ); ?></p>
				</div>
				<div>
					<h3><?php esc_html_e( 'Shipping', 'orderloft' ); ?></h3>
					<p><?php echo ! empty( $s['shipping_addr'] ) ? wp_kses_post( $s['shipping_addr'] ) : esc_html__( 'No shipping address.', 'orderloft' ); ?></p>
				</div>
			</div>
			<?php if ( ! empty( $s['customer_note'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Customer note', 'orderloft' ); ?>:</strong> <?php echo esc_html( $s['customer_note'] ); ?></p>
			<?php endif; ?>
			<table class="widefat striped orderloft-items">
				<thead><tr><th><?php esc_html_e( 'Item', 'orderloft' ); ?></th><th><?php esc_html_e( 'Qty', 'orderloft' ); ?></th><th><?php esc_html_e( 'Total', 'orderloft' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( isset( $s['items'] ) ? (array) $s['items'] : array() as $item ) : ?>
						<tr>
							<td>
								<?php
								echo esc_html( $item['name'] );
								if ( ! empty( $item['sku'] ) ) {
									echo ' <span class="description">(' . esc_html( $item['sku'] ) . ')</span>';
								}
								if ( 'line_item' !== $item['type'] ) {
									echo ' <span class="description">' . esc_html( ucfirst( $item['type'] ) ) . '</span>';
								}
								?>
							</td>
							<td><?php echo 'line_item' === $item['type'] ? esc_html( wc_stock_amount( $item['qty'] ) ) : ''; ?></td>
							<td><?php echo wp_kses_post( wc_price( $item['total'], $currency ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr><th colspan="2"><?php esc_html_e( 'Shipping', 'orderloft' ); ?></th><td><?php echo wp_kses_post( wc_price( $s['shipping'] ?? 0, $currency ) ); ?></td></tr>
					<tr><th colspan="2"><?php esc_html_e( 'Tax', 'orderloft' ); ?></th><td><?php echo wp_kses_post( wc_price( $s['tax'] ?? 0, $currency ) ); ?></td></tr>
					<tr><th colspan="2"><?php esc_html_e( 'Order total', 'orderloft' ); ?></th><td><strong><?php echo wp_kses_post( wc_price( $row->total, $currency ) ); ?></strong></td></tr>
					<?php if ( ! empty( $s['refunded'] ) ) : ?>
						<tr><th colspan="2"><?php esc_html_e( 'Refunded', 'orderloft' ); ?></th><td>-<?php echo wp_kses_post( wc_price( $s['refunded'], $currency ) ); ?></td></tr>
					<?php endif; ?>
				</tfoot>
			</table>
			<?php if ( ! empty( $s['refunds'] ) ) : ?>
				<h3><?php esc_html_e( 'Refunds', 'orderloft' ); ?></h3>
				<ul>
					<?php foreach ( $s['refunds'] as $refund ) : ?>
						<li><?php echo wp_kses_post( wc_price( $refund['amount'], $currency ) ); ?> — <?php echo esc_html( $date( $refund['date'] ) ); ?><?php echo $refund['reason'] ? ' — ' . esc_html( $refund['reason'] ) : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings() {
		$s     = Orderloft_Settings::all();
		$field = Orderloft_Settings::OPTION;
		?>
		<form method="post" action="options.php" class="orderloft-settings">
			<?php settings_fields( 'orderloft' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="orderloft-age"><?php esc_html_e( 'Archive orders older than', 'orderloft' ); ?></label></th>
					<td>
						<input id="orderloft-age" type="number" min="1" max="240" name="<?php echo esc_attr( $field ); ?>[age_months]" value="<?php echo esc_attr( $s['age_months'] ); ?>" class="small-text" /> <?php esc_html_e( 'months', 'orderloft' ); ?>
						<p class="description"><?php esc_html_e( 'Counted from the date the order was placed.', 'orderloft' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'With these statuses', 'orderloft' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( Orderloft_Settings::archivable_statuses() as $slug => $label ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['statuses'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label><br />
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Usually only finished orders: Completed, Refunded, Cancelled and Failed.', 'orderloft' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic archiving', 'orderloft' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[auto]" value="1" <?php checked( 'yes', $s['auto'] ); ?> /> <?php esc_html_e( 'Archive matching orders every night at 03:00 (site time)', 'orderloft' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="orderloft-daily"><?php esc_html_e( 'Orders per run', 'orderloft' ); ?></label></th>
					<td>
						<input id="orderloft-daily" type="number" min="10" max="100000" name="<?php echo esc_attr( $field ); ?>[daily_limit]" value="<?php echo esc_attr( $s['daily_limit'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'The most orders archived in one run. Large first archives continue on the following runs.', 'orderloft' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="orderloft-batch"><?php esc_html_e( 'Batch size', 'orderloft' ); ?></label></th>
					<td>
						<input id="orderloft-batch" type="number" min="5" max="500" name="<?php echo esc_attr( $field ); ?>[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Orders moved per background task. Lower it on slow hosting.', 'orderloft' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Subscriptions', 'orderloft' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[skip_subscriptions]" value="1" <?php checked( 'yes', $s['skip_subscriptions'] ); ?> /> <?php esc_html_e( 'Keep orders linked to a subscription that is not cancelled or expired (WooCommerce Subscriptions)', 'orderloft' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Customers', 'orderloft' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[customer_view]" value="1" <?php checked( 'yes', $s['customer_view'] ); ?> /> <?php esc_html_e( 'Show archived orders to customers under My Account > Orders (read-only)', 'orderloft' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
