<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Financial/order records are NOT deleted by default.
// To fully purge plugin tables and private receipt files on uninstall, define
// WCCTC_REMOVE_DATA as true in wp-config.php BEFORE deleting the plugin.
delete_option( 'wcctc_db_version' );
wp_clear_scheduled_hook( 'wcctc_cleanup' );

if ( defined( 'WCCTC_REMOVE_DATA' ) && WCCTC_REMOVE_DATA ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wcctc_audit_log' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wcctc_webhook_events' );

	$upload = wp_upload_dir();
	$dir    = trailingslashit( $upload['basedir'] ) . 'wcctc-private';
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}
}
