<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class REST {
	private const HANDLED = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'checkout.session.expired',
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'charge.refunded',
		'charge.dispute.created',
	);

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'wcctc/v1', '/stripe/webhook', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'stripe_webhook' ),
			'permission_callback' => '__return_true', // Authenticated by the Stripe signature.
		) );
		register_rest_route( 'wcctc/v1', '/receipt', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( Receipts::class, 'rest_upload' ),
			'permission_callback' => array( __CLASS__, 'receipt_permission' ),
		) );
	}

	/**
	 * Receipt uploads remain available to guests, but require the REST nonce
	 * issued by our checkout UI to prevent cross-site upload/CSRF abuse.
	 */
	public static function receipt_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'wcctc_rest_nonce', __( 'Invalid REST nonce.', 'wc-card-transfer-gateway' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function stripe_webhook( $request ) {
		$raw      = $request->get_body();
		$provider = new StripeProvider( Helpers::stripe_settings() );
		// get_header() normalizes the name; get_headers()['stripe-signature'] would never match (WP uses underscores).
		$event = $provider->handle_webhook( $raw, $request->get_header( 'stripe-signature' ) );
		if ( is_wp_error( $event ) ) {
			Database::audit( 'webhook_rejected', array( 'error' => $event->get_error_code() ), 0, 'webhook', 'stripe' );
			return new \WP_Error( $event->get_error_code(), $event->get_error_message(), array( 'status' => 400 ) );
		}

		$type  = (string) $event['type'];
		$ledger = Database::webhook_begin( 'stripe', $event['id'], $type, hash( 'sha256', $raw ) );
		if ( $ledger['done'] ) {
			return rest_ensure_response( array( 'received' => true, 'duplicate' => true ) );
		}
		if ( ! in_array( $type, self::HANDLED, true ) ) {
			Database::webhook_finish( $ledger['id'] );
			return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
		}

		$order_id = 0;
		try {
			$order = self::resolve_order( $event );
			if ( ! $order ) {
				// Typically another store sharing the same Stripe account. Acknowledge so Stripe does not retry forever.
				Database::webhook_finish( $ledger['id'] );
				return rest_ensure_response( array( 'received' => true, 'ignored' => true ) );
			}
			$order_id = $order->get_id();
			self::apply_event( $order, $type, (array) ( $event['data']['object'] ?? array() ) );
			Database::webhook_finish( $ledger['id'], $order_id );
			Database::audit( 'stripe_webhook', array( 'type' => $type ), $order_id, 'webhook', $event['id'] );
			return rest_ensure_response( array( 'received' => true ) );
		} catch ( \UnexpectedValueException $e ) {
			// Permanent problem (e.g. amount mismatch): keep the order unpaid, record it, do not make Stripe retry.
			Database::webhook_finish( $ledger['id'], $order_id, $e->getMessage() );
			Database::audit( 'stripe_webhook_mismatch', array( 'type' => $type, 'error' => $e->getMessage() ), $order_id, 'webhook', $event['id'] );
			return rest_ensure_response( array( 'received' => true, 'error' => 'rejected' ) );
		} catch ( \Throwable $e ) {
			Database::webhook_finish( $ledger['id'], $order_id, $e->getMessage() );
			return new \WP_Error( 'wcctc_webhook_failed', __( 'Webhook processing failed.', 'wc-card-transfer-gateway' ), array( 'status' => 500 ) );
		}
	}

	private static function order_by_meta( $key, $value ) {
		if ( ! $value ) {
			return null;
		}
		$ids = wc_get_orders( array(
			'limit'          => 1,
			'return'         => 'ids',
			'payment_method' => Helpers::INTERNATIONAL_ID,
			'meta_query'     => array( array( 'key' => $key, 'value' => $value ) ),
		) );
		return $ids ? wc_get_order( $ids[0] ) : null;
	}

	private static function resolve_order( $event ) {
		$type = $event['type'];
		$obj  = (array) ( $event['data']['object'] ?? array() );
		$meta = (array) ( $obj['metadata'] ?? array() );
		$order = null;

		$order_id = absint( $meta['order_id'] ?? ( $obj['client_reference_id'] ?? 0 ) );
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				// The order key must match, otherwise this is another store's order that happens to share the ID.
				$key = (string) ( $meta['order_key'] ?? '' );
				if ( '' !== $key && ! hash_equals( $order->get_order_key(), $key ) ) {
					return null;
				}
			}
		}
		if ( ! $order && 0 === strpos( $type, 'checkout.session.' ) && ! empty( $obj['id'] ) ) {
			$order = self::order_by_meta( Helpers::META_PROVIDER_SESSION_ID, $obj['id'] );
		}
		if ( ! $order ) {
			$intent = 0 === strpos( $type, 'payment_intent.' ) ? ( $obj['id'] ?? '' ) : ( $obj['payment_intent'] ?? '' );
			$order  = self::order_by_meta( Helpers::META_PROVIDER_PAYMENT_ID, $intent );
		}
		if ( ! $order || Helpers::INTERNATIONAL_ID !== $order->get_payment_method() ) {
			return null;
		}
		return $order;
	}

	private static function verify_amount( $order, $amount, $currency ) {
		if ( strtolower( (string) $currency ) !== strtolower( $order->get_currency() ) ) {
			throw new \UnexpectedValueException( 'Currency mismatch: ' . $currency );
		}
		$expected = Helpers::to_minor_units( $order->get_total(), $order->get_currency() );
		if ( (int) $amount !== $expected ) {
			throw new \UnexpectedValueException( sprintf( 'Amount mismatch: received %d, expected %d', (int) $amount, $expected ) );
		}
	}

	private static function mark_paid( $order, $intent, $label ) {
		if ( $intent ) {
			$order->update_meta_data( Helpers::META_PROVIDER_PAYMENT_ID, sanitize_text_field( $intent ) );
		}
		$order->update_meta_data( Helpers::META_PROVIDER, 'stripe' );
		$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'paid' );
		if ( ! $order->is_paid() ) {
			$order->payment_complete( (string) $intent );
			$order->add_order_note( $label );
		}
		$order->save();
	}

	private static function apply_event( $order, $type, $obj ) {
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$status = (string) ( $obj['payment_status'] ?? '' );
				if ( 'paid' === $status || 'no_payment_required' === $status ) {
					self::verify_amount( $order, $obj['amount_total'] ?? 0, $obj['currency'] ?? '' );
					if ( ! empty( $obj['id'] ) ) {
						$order->update_meta_data( Helpers::META_PROVIDER_SESSION_ID, sanitize_text_field( $obj['id'] ) );
					}
					self::mark_paid( $order, $obj['payment_intent'] ?? '', __( 'Stripe Checkout payment confirmed.', 'wc-card-transfer-gateway' ) );
				} else {
					$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'awaiting_funds' );
					$order->add_order_note( __( 'Stripe Checkout completed; waiting for the payment method to confirm funds.', 'wc-card-transfer-gateway' ) );
					$order->save();
				}
				break;

			case 'payment_intent.succeeded':
				self::verify_amount( $order, $obj['amount_received'] ?? ( $obj['amount'] ?? 0 ), $obj['currency'] ?? '' );
				self::mark_paid( $order, $obj['id'] ?? '', __( 'Stripe payment succeeded.', 'wc-card-transfer-gateway' ) );
				break;

			case 'checkout.session.async_payment_failed':
			case 'payment_intent.payment_failed':
				if ( ! $order->is_paid() ) {
					$msg = (string) ( $obj['last_payment_error']['message'] ?? '' );
					$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'failed' );
					$order->update_status( 'failed', __( 'Stripe payment failed.', 'wc-card-transfer-gateway' ) . ( $msg ? ' ' . sanitize_text_field( $msg ) : '' ) );
					$order->save();
				}
				break;

			case 'checkout.session.expired':
				if ( ! $order->is_paid() ) {
					$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'expired' );
					$order->add_order_note( __( 'Stripe Checkout session expired without payment.', 'wc-card-transfer-gateway' ) );
					$order->save();
				}
				break;

			case 'charge.refunded':
				$order->add_order_note( sprintf(
					/* translators: %s: refunded amount */
					__( 'Stripe reports a refund (total refunded on charge: %s). Check WooCommerce refunds if it was issued from the Stripe dashboard.', 'wc-card-transfer-gateway' ),
					wc_price( ( (int) ( $obj['amount_refunded'] ?? 0 ) ) / pow( 10, Helpers::currency_exponent( $order->get_currency() ) ), array( 'currency' => $order->get_currency() ) )
				) );
				break;

			case 'charge.dispute.created':
				$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'disputed' );
				$order->add_order_note( __( 'Stripe dispute opened for this payment. Review it in the Stripe dashboard before the evidence deadline.', 'wc-card-transfer-gateway' ) );
				$order->save();
				break;
		}
	}
}
