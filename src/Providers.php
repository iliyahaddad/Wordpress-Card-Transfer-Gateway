<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {
	public function create_payment( \WC_Order $order, $attempt = 1 );
	public function refund( \WC_Order $order, $amount, $reason = '', $sequence = 0 );
	public function handle_webhook( $raw_body, $signature_header );
}

final class StripeProvider implements ProviderInterface {
	private $secret;
	private $webhook_secret;

	public function __construct( $settings = array() ) {
		$this->secret         = trim( (string) ( $settings['stripe_secret_key'] ?? '' ) );
		$this->webhook_secret = trim( (string) ( $settings['stripe_webhook_secret'] ?? '' ) );
	}

	public function configured() {
		return '' !== $this->secret;
	}

	public static function valid_secret_format( $key ) {
		return (bool) preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', trim( (string) $key ) );
	}

	private function request( $method, $endpoint, $body = array(), $idempotency = '' ) {
		$headers = array( 'Authorization' => 'Bearer ' . $this->secret );
		if ( $idempotency ) {
			$headers['Idempotency-Key'] = substr( $idempotency, 0, 255 );
		}
		$args = array( 'method' => strtoupper( $method ), 'timeout' => 30, 'headers' => $headers );
		if ( 'GET' !== $args['method'] ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( 'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Stripe request failed.', 'wc-card-transfer-gateway' );
			return new \WP_Error( 'wcctc_stripe_error', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public function create_payment( \WC_Order $order, $attempt = 1 ) {
		if ( ! $this->configured() ) {
			return new \WP_Error( 'wcctc_stripe_unconfigured', __( 'Stripe secret key is not configured.', 'wc-card-transfer-gateway' ) );
		}
		$currency = strtolower( $order->get_currency() );
		$amount   = Helpers::to_minor_units( $order->get_total(), $currency );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'wcctc_invalid_amount', __( 'Invalid order amount.', 'wc-card-transfer-gateway' ) );
		}
		$label = sprintf( /* translators: %s: order number */ __( 'WooCommerce order #%s', 'wc-card-transfer-gateway' ), $order->get_order_number() );
		$body  = array(
			'mode'                => 'payment',
			'success_url'         => add_query_arg( array( 'wcctc-stripe-return' => 1, 'order_id' => $order->get_id(), 'key' => $order->get_order_key() ), home_url( '/' ) ),
			'cancel_url'          => $order->get_checkout_payment_url(),
			'client_reference_id' => (string) $order->get_id(),
			'line_items[0][price_data][currency]'                     => $currency,
			'line_items[0][price_data][product_data][name]'           => $label,
			'line_items[0][price_data][unit_amount]'                  => $amount,
			'line_items[0][quantity]'                                 => 1,
			'metadata[order_id]'  => (string) $order->get_id(),
			'metadata[order_key]' => $order->get_order_key(),
			// Copied to the PaymentIntent so payment_intent.* webhooks can find the order.
			'payment_intent_data[metadata][order_id]'  => (string) $order->get_id(),
			'payment_intent_data[metadata][order_key]' => $order->get_order_key(),
			'payment_intent_data[description]'         => $label,
		);
		if ( $order->get_billing_email() ) {
			$body['customer_email'] = $order->get_billing_email();
		}
		$idem = 'wcctc_' . $order->get_id() . '_' . md5( $order->get_order_key() . '|' . $amount . '|' . $currency ) . '_' . (int) $attempt;
		return $this->request( 'POST', 'checkout/sessions', $body, $idem );
	}

	public function retrieve_session( $session_id ) {
		return $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	}

	/** An open session for the same amount/currency can be reused when the customer retries. */
	public function session_reusable( \WC_Order $order, $session ) {
		return is_array( $session )
			&& 'open' === ( $session['status'] ?? '' )
			&& ! empty( $session['url'] )
			&& strtolower( (string) ( $session['currency'] ?? '' ) ) === strtolower( $order->get_currency() )
			&& (int) ( $session['amount_total'] ?? -1 ) === Helpers::to_minor_units( $order->get_total(), $order->get_currency() );
	}

	public function refund( \WC_Order $order, $amount, $reason = '', $sequence = 0 ) {
		if ( ! $this->configured() ) {
			return new \WP_Error( 'wcctc_stripe_unconfigured', __( 'Stripe secret key is not configured.', 'wc-card-transfer-gateway' ) );
		}
		$intent = $order->get_meta( Helpers::META_PROVIDER_PAYMENT_ID, true );
		if ( ! $intent ) {
			$sid = $order->get_meta( Helpers::META_PROVIDER_SESSION_ID, true );
			if ( $sid ) {
				$session = $this->retrieve_session( $sid );
				if ( ! is_wp_error( $session ) && ! empty( $session['payment_intent'] ) ) {
					$intent = $session['payment_intent'];
					$order->update_meta_data( Helpers::META_PROVIDER_PAYMENT_ID, sanitize_text_field( $intent ) );
					$order->save();
				}
			}
		}
		if ( ! $intent ) {
			return new \WP_Error( 'wcctc_no_payment_id', __( 'No provider payment ID is stored for this order.', 'wc-card-transfer-gateway' ) );
		}
		$body = array( 'payment_intent' => $intent );
		if ( null !== $amount && '' !== $amount ) {
			$body['amount'] = Helpers::to_minor_units( $amount, $order->get_currency() );
		}
		if ( $reason ) {
			$body['metadata[reason]'] = sanitize_text_field( $reason );
		}
		$body['metadata[order_id]'] = (string) $order->get_id();
		// The sequence (number of refunds that already exist) keeps two identical partial refunds distinct.
		$idem = 'wcctc_refund_' . $order->get_id() . '_' . (int) $sequence . '_' . md5( (string) $amount );
		return $this->request( 'POST', 'refunds', $body, $idem );
	}

	/**
	 * Verify the Stripe-Signature header and decode the event.
	 *
	 * @param string $raw_body         Exact request body.
	 * @param string $signature_header Value of the Stripe-Signature header.
	 * @return array|\WP_Error
	 */
	public function handle_webhook( $raw_body, $signature_header ) {
		if ( ! $this->webhook_secret ) {
			return new \WP_Error( 'wcctc_webhook_secret_missing', __( 'Webhook signing secret is not configured.', 'wc-card-transfer-gateway' ) );
		}
		$signature_header = is_array( $signature_header ) ? (string) reset( $signature_header ) : (string) $signature_header;
		if ( '' === $signature_header ) {
			return new \WP_Error( 'wcctc_signature_missing', __( 'Missing Stripe signature.', 'wc-card-transfer-gateway' ) );
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $signature_header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = absint( $kv[1] );
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1]; // Several v1 values can be present during secret rotation.
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > 300 ) {
			return new \WP_Error( 'wcctc_signature_expired', __( 'Invalid or expired Stripe webhook signature.', 'wc-card-transfer-gateway' ) );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $this->webhook_secret );
		$valid    = false;
		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return new \WP_Error( 'wcctc_signature_invalid', __( 'Invalid Stripe webhook signature.', 'wc-card-transfer-gateway' ) );
		}
		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new \WP_Error( 'wcctc_invalid_event', __( 'Invalid webhook payload.', 'wc-card-transfer-gateway' ) );
		}
		return $event;
	}
}
