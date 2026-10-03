<?php
/**
 * Admin log viewer: Tools -> QC Twenty Sync.
 */

defined( 'ABSPATH' ) || exit;

class QC_Twenty_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_qc_twenty_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_qc_twenty_backfill', array( __CLASS__, 'handle_backfill' ) );
	}

	public static function menu() {
		add_management_page(
			'QC Twenty Sync',
			'QC Twenty Sync',
			'manage_options',
			'qc-twenty-sync',
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'qc_twenty_retry' );

		$event_id = isset( $_GET['event_id'] ) ? sanitize_text_field( wp_unslash( $_GET['event_id'] ) ) : '';
		if ( $event_id ) {
			QC_Twenty_Log::update( $event_id, array( 'status' => 'pending' ) );
			QC_Twenty_Sync::process( $event_id );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=qc-twenty-sync' ) );
		exit;
	}

	/** Dry-run (default) or live backfill of customers, 40 per batch. */
	public static function handle_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'qc_twenty_backfill' );

		$live   = isset( $_POST['live'] ) && '1' === $_POST['live'];
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// A live user costs ~10 API calls and a 6s pause, so keep a request under
		// typical 60-120s server limits. A dry run is cheap.
		$size   = $live ? 8 : 40;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids    = get_users(
			array(
				'fields'       => 'ID',
				'number'       => $size,
				'offset'       => $offset,
				'orderby'      => 'ID',
				'role__not_in' => array( 'administrator', 'shop_manager', 'editor' ),
			)
		);

		$rows = QC_Twenty_Sync::backfill_customers( array_map( 'intval', $ids ), ! $live );
		set_transient(
			'qc_twenty_backfill_' . get_current_user_id(),
			array( 'rows' => $rows, 'live' => $live, 'next' => ( $size === count( $ids ) ) ? $offset + $size : 0 ),
			600
		);

		wp_safe_redirect( admin_url( 'tools.php?page=qc-twenty-sync' ) );
		exit;
	}

	private static function render_backfill() {
		$res = get_transient( 'qc_twenty_backfill_' . get_current_user_id() );
		echo '<h2>Backfill customers</h2><p>Links existing Twenty Companies to WordPress users and fills blank fields. Dry run first (40 users per batch); live runs do 8 users per batch.</p>';
		foreach ( array( 0 => 'Dry run', 1 => 'Run live' ) as $live => $label ) {
			echo '<form method="post" style="display:inline-block;margin-right:8px" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'qc_twenty_backfill' );
			echo '<input type="hidden" name="action" value="qc_twenty_backfill"><input type="hidden" name="live" value="' . (int) $live . '">';
			// Each mode continues from its own last batch, so a dry run never skips users in a live run.
			$continues = $res && (bool) $res['live'] === (bool) $live && $res['next'];
			echo '<input type="hidden" name="offset" value="' . ( $continues ? (int) $res['next'] : 0 ) . '">';
			submit_button( $label . ( $continues ? ' (next batch)' : ' (first batch)' ), $live ? 'primary' : 'secondary', '', false );
			echo '</form>';
		}
		if ( $res ) {
			echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>WP ID</th><th>' . ( $res['live'] ? 'Result' : 'Plan' ) . '</th></tr></thead><tbody>';
			foreach ( $res['rows'] as $r ) {
				echo '<tr><td>' . (int) $r['wp_id'] . '</td><td>' . esc_html( $r['result'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	public static function render() {
		$views = QC_Twenty_Log::views();
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'default'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view  = isset( $views[ $view ] ) ? $view : 'default';
		$rows  = QC_Twenty_Log::recent( 100, $view );
		echo '<div class="wrap"><h1>QC Twenty Sync</h1>';
		echo '<p>Queued pushes to Twenty CRM. Key is read from the <code>QC_TWENTY_API_KEY</code> constant.</p>';

		if ( ! defined( 'QC_TWENTY_API_KEY' ) ) {
			echo '<div class="notice notice-error"><p><strong>QC_TWENTY_API_KEY is not defined.</strong> Pushes will fail.</p></div>';
		}

		self::render_backfill();
		echo '<h2>Event log</h2><p>';
		$links = array();
		foreach ( $views as $slug => $label ) {
			$url     = esc_url( add_query_arg( array( 'page' => 'qc-twenty-sync', 'view' => $slug ), admin_url( 'tools.php' ) ) );
			$links[] = $slug === $view ? '<strong>' . esc_html( $label ) . '</strong>' : '<a href="' . $url . '">' . esc_html( $label ) . '</a>';
		}
		echo implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		echo '<p class="description">Showing the latest 100. Finished rows are deleted after 30 days, failed rows after 90.</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'When (UTC)', 'Event', 'WP ID', 'Status', 'Tries', 'Code', 'Last error', '' ) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="8">No events yet.</td></tr>';
		}

		foreach ( $rows as $r ) {
			$retry = wp_nonce_url(
				admin_url( 'admin-post.php?action=qc_twenty_retry&event_id=' . rawurlencode( $r['event_id'] ) ),
				'qc_twenty_retry'
			);
			echo '<tr>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $r['event_type'] ) . '</td>';
			echo '<td>' . (int) $r['wp_object_id'] . '</td>';
			echo '<td>' . esc_html( $r['status'] ) . '</td>';
			echo '<td>' . (int) $r['attempts'] . '</td>';
			echo '<td>' . esc_html( (string) $r['response_code'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['last_error'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $retry ) . '">Retry</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}

QC_Twenty_Admin::init();
