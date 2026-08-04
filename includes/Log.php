<?php
/**
 * Lightweight request log, capped and self-pruning.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

/**
 * API call log.
 */
class Log {

	const RETENTION_DAYS = 14;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cws_log';
	}

	/**
	 * Create the log table.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				method VARCHAR(10) NOT NULL DEFAULT '',
				endpoint VARCHAR(190) NOT NULL DEFAULT '',
				status VARCHAR(10) NOT NULL DEFAULT '',
				detail TEXT NULL,
				PRIMARY KEY (id),
				KEY created_at (created_at),
				KEY status (status)
			) {$charset};"
		);
	}

	/**
	 * Record an API call.
	 *
	 * @param string $method   HTTP verb.
	 * @param string $endpoint Endpoint path.
	 * @param string $status   'ok' or 'error'.
	 * @param string $detail   Response code or error message.
	 * @return void
	 */
	public static function write( string $method, string $endpoint, string $status, string $detail = '' ): void {
		global $wpdb;

		// Only failures are worth keeping by default; successes would grow the
		// table without adding information.
		if ( 'ok' === $status && ! apply_filters( 'cws_log_successes', false ) ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'method'     => substr( $method, 0, 10 ),
				'endpoint'   => substr( $endpoint, 0, 190 ),
				'status'     => substr( $status, 0, 10 ),
				'detail'     => substr( $detail, 0, 2000 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Prune occasionally rather than on every write.
		if ( 0 === wp_rand( 0, 99 ) ) {
			self::prune();
		}
	}

	/**
	 * Delete entries older than the retention window.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}
