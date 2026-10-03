<?php
/**
 * Event log for QC Twenty Sync.
 *
 * Every push is recorded here so failures are inspectable.
 */

defined( 'ABSPATH' ) || exit;

class QC_Twenty_Log {

	const TABLE = 'qc_twenty_log';

	/** Create or upgrade the log table. */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id CHAR(36) NOT NULL,
			event_type VARCHAR(40) NOT NULL,
			wp_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			response_code SMALLINT NULL,
			payload LONGTEXT NULL,
			payload_version VARCHAR(10) NOT NULL DEFAULT '1',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY status (status),
			KEY event_type_object (event_type, wp_object_id)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a new event.
	 *
	 * @return string|false event_id on success, false when a duplicate is skipped.
	 */
	public static function add( $event_type, $wp_object_id, array $payload ) {
		global $wpdb;

		$event_id = wp_generate_uuid4();
		$now      = current_time( 'mysql', true );

		$ok = $wpdb->insert(
			self::table(),
			array(
				'event_id'        => $event_id,
				'event_type'      => $event_type,
				'wp_object_id'    => (int) $wp_object_id,
				'status'          => 'pending',
				'attempts'        => 0,
				'payload'         => wp_json_encode( $payload ),
				'payload_version' => '1',
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? $event_id : false;
	}

	/** True when the same event type + object already has a live or done row. */
	public static function is_duplicate( $event_type, $wp_object_id, $hours = 24, $only_open = false ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) );
		// Recomputing events only collapse into one that has not run yet.
		$statuses = $only_open ? "'pending','processing'" : "'pending','processing','done'";

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE event_type = %s AND wp_object_id = %d
				   AND status IN ({$statuses})
				   AND created_at >= %s",
				$event_type,
				(int) $wp_object_id,
				$since
			)
		);

		return ( (int) $count ) > 0;
	}

	public static function get_by_event_id( $event_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE event_id = %s', $event_id ),
			ARRAY_A
		);
	}

	public static function update( $event_id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );

		// Only allow known columns through.
		$allowed = array( 'status', 'attempts', 'last_error', 'response_code', 'payload', 'updated_at' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );

		if ( ! $fields ) {
			return false;
		}

		return $wpdb->update( self::table(), $fields, array( 'event_id' => $event_id ) );
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		return $wpdb->get_results( "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
	}
}
