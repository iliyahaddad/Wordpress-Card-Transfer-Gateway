<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the payment gateways and the customer-facing output (thank-you page,
 * order details, e-mails). The gateway classes themselves live in src/Gateways.
 */
final class Gateway {
	public static function register() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateways' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'stripe_return' ) );
		add_action( 'woocommerce_thankyou_' . Helpers::IRAN_ID, array( __CLASS__, 'thankyou' ) );
		add_action( 'woocommerce_thankyou_' . Helpers::INTERNATIONAL_ID, array( __CLASS__, 'thankyou' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_instructions' ), 10, 4 );
	}

	public static function add_gateways( $methods ) {
		$methods[] = Gateways\Iran::class;
		$methods[] = Gateways\International::class;
		return $methods;
	}

	public static function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}
		$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' );
		if ( $on_checkout || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) {
			wp_enqueue_style( 'wcctc-checkout', WCCTC_URL . 'assets/css/checkout.css', array(), WCCTC_VERSION );
		}
		if ( $on_checkout ) {
			wp_enqueue_script( 'wcctc-checkout', WCCTC_URL . 'assets/js/checkout.js', array( 'jquery' ), WCCTC_VERSION, true );
			wp_localize_script( 'wcctc-checkout', 'wcctcCheckout', array(
				'restUrl'  => esc_url_raw( rest_url( 'wcctc/v1/receipt' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxBytes' => Receipts::max_bytes(),
				'i18n'     => array(
					'uploading' => __( 'Uploading…', 'wc-card-transfer-gateway' ),
					'uploaded'  => __( 'Receipt attached:', 'wc-card-transfer-gateway' ),
					'failed'    => __( 'Receipt upload failed.', 'wc-card-transfer-gateway' ),
					'tooLarge'  => __( 'Receipt must be 5 MB or smaller.', 'wc-card-transfer-gateway' ),
				),
			) );
		}
	}

	/** Customer returns from Stripe Checkout. The webhook is the source of truth for payment. */
	public static function stripe_return() {
		if ( empty( $_GET['wcctc-stripe-return'] ) || empty( $_GET['order_id'] ) || empty( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$order = wc_get_order( absint( $_GET['order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || ! Helpers::is_stripe_order( $order ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( ! $order->is_paid() && ! $order->get_meta( Helpers::META_STRIPE_RETURNED, true ) ) {
			$order->update_meta_data( Helpers::META_STRIPE_RETURNED, 'yes' );
			$order->add_order_note( __( 'Customer returned from Stripe Checkout. Waiting for webhook confirmation.', 'wc-card-transfer-gateway' ) );
			$order->save();
		}
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Helpers::is_ours( $order->get_payment_method() ) ) {
			return;
		}
		echo '<section class="wcctc-thankyou">';
		if ( Helpers::is_stripe_order( $order ) ) {
			if ( $order->is_paid() ) {
				echo '<p>' . esc_html__( 'Your payment was confirmed. Thank you!', 'wc-card-transfer-gateway' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'We are waiting for Stripe to confirm your payment. This page does not change the order status; you will receive an e-mail once it is confirmed.', 'wc-card-transfer-gateway' ) . '</p>';
			}
		} elseif ( $order->is_paid() ) {
			echo '<p>' . esc_html__( 'Your transfer was verified. Thank you!', 'wc-card-transfer-gateway' ) . '</p>';
		} else {
			$s = Helpers::public_settings( $order->get_payment_method() );
			echo '<h2>' . esc_html__( 'Transfer details', 'wc-card-transfer-gateway' ) . '</h2>';
			echo '<p>' . wp_kses_post( sprintf( /* translators: %s: formatted amount */ __( 'Please transfer exactly %s.', 'wc-card-transfer-gateway' ), $order->get_formatted_order_total() ) ) . '</p>';
			echo wp_kses_post( Helpers::destination_html( $s ) );
			$ref = $order->get_meta( Helpers::META_REFERENCE, true );
			if ( $ref ) {
				echo '<p>' . esc_html__( 'Your reference:', 'wc-card-transfer-gateway' ) . ' <bdi>' . esc_html( $ref ) . '</bdi></p>';
			}
			echo '<p>' . esc_html__( 'Your order will be processed after we verify the transfer.', 'wc-card-transfer-gateway' ) . '</p>';
		}
		echo '</section>';
	}

	/** Runs on both the thank-you page and the My Account order view. */
	public static function order_details( $order ) {
		if ( $order instanceof \WC_Order ) {
			Receipts::render_upload_form( $order );
		}
	}

	public static function email_instructions( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! Helpers::is_manual_order( $order ) || $order->is_paid() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		$s = Helpers::public_settings( $order->get_payment_method() );
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Transfer destination', 'wc-card-transfer-gateway' ) . "\n";
			foreach ( Helpers::destination_rows( $s ) as $label => $value ) {
				echo esc_html( $label . ' ' . $value ) . "\n";
			}
			echo esc_html( wp_strip_all_tags( $s['instructions'] ) ) . "\n";
			return;
		}
		echo wp_kses_post( Helpers::destination_html( $s ) );
	}
}
