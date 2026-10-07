<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		Database::install();
		Receipts::private_dir();
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wcctc_cleanup' );
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( 'wcctc_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wcctc_cleanup' );
		}
	}

	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( version_compare( (string) get_option( 'wcctc_db_version', '0' ), WCCTC_VERSION, '<' ) ) {
			Database::install();
		}
		self::schedule();

		// These extend WooCommerce classes, so they can only be loaded now.
		require_once WCCTC_DIR . 'src/Gateways/Base.php';

		Gateway::register();
		Admin::register();
		Blocks::register();
		Helpers::register_privacy_hooks();
		Receipts::register();
		REST::register();

		add_action( 'wcctc_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'wcctc_process_ocr', array( OCR::class, 'run_for_order' ) );
	}

	public static function cleanup() {
		Receipts::cleanup_temp();
		Database::purge();
	}
}
