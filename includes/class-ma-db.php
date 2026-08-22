<?php
/**
 * Lead storage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_DB {

	const DB_VERSION = '1.0.0';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'menu_audit_leads';
	}

	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			business varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			website varchar(190) NOT NULL DEFAULT '',
			menu_text longtext NULL,
			source_file varchar(255) NOT NULL DEFAULT '',
			report longtext NULL,
			overall_score tinyint(3) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text NULL,
			email_sent tinyint(1) NOT NULL DEFAULT 0,
			ip varchar(60) NOT NULL DEFAULT '',
			token varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY email (email),
			KEY created_at (created_at),
			KEY token (token)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'menu_audit_db_version', self::DB_VERSION );
		update_option( 'menu_audit_flush_rewrite', 1 );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'menu_audit_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert a lead row, returns the new ID or 0 on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'created_at'  => current_time( 'mysql' ),
				'name'        => '',
				'business'    => '',
				'email'       => '',
				'phone'       => '',
				'website'     => '',
				'menu_text'   => '',
				'source_file' => '',
				'report'      => '',
				'status'      => 'pending',
				'ip'          => self::ip(),
				'token'       => wp_generate_password( 32, false ),
			)
		);

		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * How many submissions from this IP in the last N minutes.
	 */
	public static function recent_count_for_ip( $minutes = 60 ) {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip = %s AND created_at > DATE_SUB(%s, INTERVAL %d MINUTE)",
				self::ip(),
				current_time( 'mysql' ),
				(int) $minutes
			)
		);
	}

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Behind Cloudflare the real visitor IP arrives in this header.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		return substr( $ip, 0, 60 );
	}
}
