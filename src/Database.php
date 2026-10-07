<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class Database {
	public const AUDIT_TABLE   = 'wcctc_audit_log';
	public const WEBHOOK_TABLE = 'wcctc_webhook_events';

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$audit    = $wpdb->prefix . self::AUDIT_TABLE;
		$webhooks = $wpdb->prefix . self::WEBHOOK_TABLE;

		$sql = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NULL,
			actor_id bigint(20) unsigned NULL,
			action varchar(80) NOT NULL,
			entity varchar(80) NOT NULL DEFAULT '',
			entity_id varchar(191) NOT NULL DEFAULT '',
			ip_address varchar(64) NOT NULL DEFAULT '',
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};
		CREATE TABLE {$webhooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(50) NOT NULL,
			event_id varchar(191) NOT NULL,
			event_type varchar(100) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NULL,
			payload_hash char(64) NOT NULL DEFAULT '',
			processed tinyint(1) NOT NULL DEFAULT 0,
			error_message text NULL,
			created_at datetime NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_event (provider,event_id),
			KEY order_id (order_id),
			KEY processed (processed)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'wcctc_db_version', WCCTC_VERSION, false );
	}

	public static function audit( $action, $details = array(), $order_id = 0, $entity = 'order', $entity_id = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::AUDIT_TABLE,
			array(
				'order_id'   => $order_id ? absint( $order_id ) : null,
				'actor_id'   => get_current_user_id() ? get_current_user_id() : null,
				'action'     => sanitize_key( $action ),
				'entity'     => sanitize_key( $entity ),
				'entity_id'  => sanitize_text_field( (string) $entity_id ),
				'ip_address' => Helpers::client_ip(),
				'details'    => wp_json_encode( $details ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function audit_rows( $limit = 100, $offset = 0, $action = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::AUDIT_TABLE;
		if ( $action ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d OFFSET %d", sanitize_key( $action ), absint( $limit ), absint( $offset ) ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", absint( $limit ), absint( $offset ) ) );
	}

	public static function audit_count( $action = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::AUDIT_TABLE;
		if ( $action ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", sanitize_key( $action ) ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function audit_actions() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT action FROM ' . $wpdb->prefix . self::AUDIT_TABLE . ' ORDER BY action ASC' );
	}

	/** Privacy eraser: remove IP/details tied to an order while keeping the audit trail itself. */
	public static function anonymize_order( $order_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . self::AUDIT_TABLE,
			array( 'ip_address' => '', 'details' => wp_json_encode( array( 'anonymized' => true ) ) ),
			array( 'order_id' => absint( $order_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Start (or resume) processing of a webhook event.
	 * An event only counts as "done" once it was processed successfully, so a failed
	 * delivery that Stripe retries is processed again instead of being dropped.
	 *
	 * @return array{id:int,done:bool}
	 */
	public static function webhook_begin( $provider, $event_id, $event_type, $payload_hash ) {
		global $wpdb;
		$table    = $wpdb->prefix . self::WEBHOOK_TABLE;
		$provider = sanitize_key( $provider );
		$event_id = sanitize_text_field( $event_id );

		$find = function () use ( $wpdb, $table, $provider, $event_id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT id, processed FROM {$table} WHERE provider = %s AND event_id = %s LIMIT 1", $provider, $event_id ) );
		};

		$row = $find();
		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'provider'     => $provider,
					'event_id'     => $event_id,
					'event_type'   => sanitize_text_field( $event_type ),
					'payload_hash' => sanitize_text_field( $payload_hash ),
					'processed'    => 0,
					'created_at'   => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			$row = $find(); // Also covers the race where a parallel delivery inserted first.
		}
		return array(
			'id'   => $row ? (int) $row->id : 0,
			'done' => $row ? 1 === (int) $row->processed : false,
		);
	}

	public static function webhook_finish( $id, $order_id = 0, $error = '' ) {
		global $wpdb;
		if ( ! $id ) {
			return;
		}
		$wpdb->update(
			$wpdb->prefix . self::WEBHOOK_TABLE,
			array(
				'processed'     => $error ? 0 : 1,
				'order_id'      => $order_id ? absint( $order_id ) : null,
				'error_message' => sanitize_text_field( (string) $error ),
				'processed_at'  => $error ? null : current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function webhook_failures( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::WEBHOOK_TABLE . ' WHERE processed = 0 ORDER BY id DESC LIMIT %d', absint( $limit ) ) );
	}

	/** Housekeeping: webhook ledger rows older than 90 days are not needed for replay protection (Stripe stops retrying after 3 days). */
	public static function purge() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . self::WEBHOOK_TABLE . ' WHERE created_at < %s AND processed = 1', $cut ) );
	}
}
