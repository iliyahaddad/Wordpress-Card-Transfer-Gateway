<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

/** Registers the Checkout Block integration. The payment-method classes are loaded lazily (they extend WooCommerce Blocks classes). */
final class Blocks {
	public static function register() {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'registry' ) );
	}

	public static function registry( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once WCCTC_DIR . 'src/Blocks/MethodType.php';
		$registry->register( new Blocks\MethodType( Helpers::IRAN_ID ) );
		$registry->register( new Blocks\MethodType( Helpers::INTERNATIONAL_ID ) );
	}
}
