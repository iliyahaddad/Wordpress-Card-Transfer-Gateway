<?php
namespace WCCTC\Gateways;

use WCCTC\Database;
use WCCTC\Helpers;
use WCCTC\Receipts;
use WCCTC\StripeProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour. Loaded only after WooCommerce is available (see Plugin::boot()).
 */
abstract class Base extends \WC_Payment_Gateway {
	/** 'iran' or 'international' */
	protected $mode = 'iran';

	public function __construct() {
		$this->id                 = 'wcctc_' . $this->mode;
		$this->method_title       = Helpers::gateway_label( $this->id );
		$this->method_description = __( 'Customers pay by transfer and a store manager verifies the payment.', 'wc-card-transfer-gateway' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$s                 = Helpers::public_settings( $this->id );
		$this->title       = $s['title'];
		$this->description = $s['description'];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/* ----------------------------------------------------------- Admin form */

	protected function common_fields() {
		$d = Helpers::default_settings( $this->id );
		return array(
			'enabled'            => array( 'title' => __( 'Enable/Disable', 'wc-card-transfer-gateway' ), 'type' => 'checkbox', 'label' => __( 'Enable this gateway', 'wc-card-transfer-gateway' ), 'default' => 'no' ),
			'title'              => array( 'title' => __( 'Title', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => $d['title'], 'desc_tip' => true, 'description' => __( 'Shown to customers at checkout.', 'wc-card-transfer-gateway' ) ),
			'description'        => array( 'title' => __( 'Description', 'wc-card-transfer-gateway' ), 'type' => 'textarea', 'default' => $d['description'] ),
			'allowed_currencies' => array( 'title' => __( 'Allowed currencies', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '', 'description' => __( 'Optional, comma separated (e.g. IRR, IRT). Leave empty to allow every store currency.', 'wc-card-transfer-gateway' ), 'desc_tip' => true ),
			'recipient_name'     => array( 'title' => __( 'Recipient name', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '' ),
			'recipient_card'     => array( 'title' => __( 'Recipient card number', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '' ),
			'bank_name'          => array( 'title' => __( 'Bank name', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '' ),
			'account_number'     => array( 'title' => __( 'Account number / IBAN', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '' ),
			'instructions'       => array( 'title' => __( 'Extra instructions', 'wc-card-transfer-gateway' ), 'type' => 'textarea', 'default' => '' ),
			'reference_label'    => array( 'title' => __( 'Reference field label', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => $d['reference_label'] ),
			'reference_help'     => array( 'title' => __( 'Reference help text', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => $d['reference_help'] ),
			'reference_required' => array( 'title' => __( 'Reference required', 'wc-card-transfer-gateway' ), 'type' => 'checkbox', 'label' => __( 'Require a bank/transfer reference', 'wc-card-transfer-gateway' ), 'default' => 'yes' ),
			'sender_name'        => array( 'title' => __( 'Sender name', 'wc-card-transfer-gateway' ), 'type' => 'checkbox', 'label' => __( 'Ask for the sender name', 'wc-card-transfer-gateway' ), 'default' => 'yes' ),
			'sender_last4'       => array( 'title' => __( 'Sender card last 4', 'wc-card-transfer-gateway' ), 'type' => 'checkbox', 'label' => __( 'Ask for the last four digits of the sender card', 'wc-card-transfer-gateway' ), 'default' => 'no' ),
			'receipt_required'   => array( 'title' => __( 'Receipt required', 'wc-card-transfer-gateway' ), 'type' => 'checkbox', 'label' => __( 'Require a receipt upload at checkout', 'wc-card-transfer-gateway' ), 'default' => 'no', 'description' => __( 'When disabled, customers can still upload the receipt later from the order page.', 'wc-card-transfer-gateway' ) ),
		);
	}

	public function validate_recipient_card_field( $key, $value ) {
		$value  = is_null( $value ) ? '' : wp_kses_post( trim( stripslashes( $value ) ) );
		$digits = preg_replace( '/\D+/', '', Helpers::normalize_digits( $value ) );
		if ( '' !== $digits && 16 === strlen( $digits ) && ! Helpers::luhn_valid( $digits ) ) {
			\WC_Admin_Settings::add_error( __( 'The recipient card number does not pass the checksum test. Please double-check it.', 'wc-card-transfer-gateway' ) );
		}
		return $value;
	}

	/* ---------------------------------------------------------- Availability */

	protected function is_stripe_mode() {
		return Helpers::INTERNATIONAL_ID === $this->id && 'stripe' === ( Helpers::public_settings( $this->id )['international_mode'] ?? 'manual' );
	}

	protected function stripe() {
		return new StripeProvider( Helpers::stripe_settings() );
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$allowed = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) Helpers::public_settings( $this->id )['allowed_currencies'] ) ) ) );
		if ( $allowed && ! in_array( strtoupper( get_woocommerce_currency() ), $allowed, true ) ) {
			return false;
		}
		if ( $this->is_stripe_mode() && ! $this->stripe()->configured() ) {
			return false;
		}
		return true;
	}

	public function get_public_settings() {
		$s                = Helpers::public_settings( $this->id );
		$s['mode']        = $this->is_stripe_mode() ? 'stripe' : 'manual';
		$s['description'] = $this->description;
		return $s;
	}

	/* ------------------------------------------------------------- Checkout */

	public function payment_fields() {
		$s = $this->get_public_settings();
		if ( $s['description'] ) {
			echo wp_kses_post( wpautop( wptexturize( $s['description'] ) ) );
		}
		if ( 'stripe' === $s['mode'] ) {
			echo '<p class="wcctc-note">' . esc_html__( 'You will be redirected to Stripe to complete the payment securely.', 'wc-card-transfer-gateway' ) . '</p>';
			return;
		}
		echo '<div class="wcctc-payment-box">';
		echo wp_kses_post( Helpers::destination_html( $s ) );

		$req = 'yes' === $s['reference_required'];
		echo '<p class="form-row form-row-wide"><label for="wcctc-reference">' . esc_html( $s['reference_label'] ) . ( $req ? ' <abbr class="required" title="required">*</abbr>' : '' ) . '</label>';
		echo '<input type="text" class="input-text" id="wcctc-reference" name="wcctc_reference" maxlength="120" autocomplete="off" dir="ltr"><small>' . esc_html( $s['reference_help'] ) . '</small></p>';

		if ( 'yes' === $s['sender_name'] ) {
			echo '<p class="form-row form-row-wide"><label for="wcctc-sender-name">' . esc_html__( 'Sender name', 'wc-card-transfer-gateway' ) . '</label><input type="text" class="input-text" id="wcctc-sender-name" name="wcctc_sender_name" maxlength="120"></p>';
		}
		if ( 'yes' === $s['sender_last4'] ) {
			echo '<p class="form-row form-row-wide"><label for="wcctc-sender-last4">' . esc_html__( 'Last 4 digits of the sender card', 'wc-card-transfer-gateway' ) . '</label><input type="text" class="input-text" id="wcctc-sender-last4" name="wcctc_sender_last4" inputmode="numeric" maxlength="4" pattern="[0-9۰-۹]{4}" autocomplete="off" dir="ltr"></p>';
		}
		$rr = 'yes' === $s['receipt_required'];
		echo '<p class="form-row form-row-wide"><label for="wcctc-receipt">' . esc_html__( 'Transfer receipt', 'wc-card-transfer-gateway' ) . ( $rr ? ' <abbr class="required" title="required">*</abbr>' : '' ) . '</label>';
		echo '<input type="file" id="wcctc-receipt" accept="image/jpeg,image/png,image/webp,application/pdf">';
		echo '<input type="hidden" id="wcctc-receipt-token" name="wcctc_receipt_token" value="">';
		echo '<small class="wcctc-upload-status" role="status" aria-live="polite"></small></p>';
		echo '</div>';
	}

	private function read_submission() {
		// WooCommerce verifies its own checkout nonce / the Store API token before this runs.
		// phpcs:disable WordPress.Security.NonceVerification
		return array(
			'reference' => isset( $_POST['wcctc_reference'] ) ? Helpers::sanitize_reference( $_POST['wcctc_reference'] ) : '',
			'name'      => isset( $_POST['wcctc_sender_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['wcctc_sender_name'] ) ), 0, 120 ) : '',
			'last4_raw' => isset( $_POST['wcctc_sender_last4'] ) ? trim( (string) wp_unslash( $_POST['wcctc_sender_last4'] ) ) : '',
			'last4'     => isset( $_POST['wcctc_sender_last4'] ) ? Helpers::sanitize_last4( $_POST['wcctc_sender_last4'] ) : '',
			'token'     => isset( $_POST['wcctc_receipt_token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['wcctc_receipt_token'] ) ) : '',
			'has_file'  => ! empty( $_FILES['wcctc_receipt']['name'] ),
		);
		// phpcs:enable
	}

	/** @return true|\WP_Error */
	private function validate_submission( $data, $exclude_order_id = 0 ) {
		$s = Helpers::public_settings( $this->id );
		if ( 'yes' === $s['reference_required'] && '' === $data['reference'] ) {
			return new \WP_Error( 'ref', __( 'Please enter the bank transfer reference.', 'wc-card-transfer-gateway' ) );
		}
		if ( '' !== $data['reference'] && Helpers::find_duplicate_reference( $data['reference'], $exclude_order_id ) ) {
			return new \WP_Error( 'dup', __( 'This transfer reference was already submitted. Please check it and try again.', 'wc-card-transfer-gateway' ) );
		}
		if ( 'yes' === $s['sender_last4'] && '' !== $data['last4_raw'] && '' === $data['last4'] ) {
			return new \WP_Error( 'last4', __( 'Sender card last 4 digits must be exactly four digits.', 'wc-card-transfer-gateway' ) );
		}
		if ( '' !== $data['token'] ) {
			$temp = Receipts::get_temp( $data['token'] );
			if ( is_wp_error( $temp ) ) {
				return $temp;
			}
		} elseif ( 'yes' === $s['receipt_required'] && ! $data['has_file'] ) {
			return new \WP_Error( 'receipt', __( 'Please upload the transfer receipt.', 'wc-card-transfer-gateway' ) );
		}
		return true;
	}

	/** Classic checkout only; the Store API (blocks) does not call this, so process_payment() validates again. */
	public function validate_fields() {
		if ( $this->is_stripe_mode() ) {
			return true;
		}
		$awaiting = function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'order_awaiting_payment' ) ) : 0;
		$result   = $this->validate_submission( $this->read_submission(), $awaiting );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	/* -------------------------------------------------------------- Payment */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		return $this->is_stripe_mode() ? $this->process_stripe( $order ) : $this->process_manual( $order );
	}

	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	private function process_manual( \WC_Order $order ) {
		$data  = $this->read_submission();
		$valid = $this->validate_submission( $data, $order->get_id() );
		if ( is_wp_error( $valid ) ) {
			return $this->fail( $valid->get_error_message() );
		}
		$s = Helpers::public_settings( $this->id );

		$order->update_meta_data( Helpers::META_PROVIDER, 'manual' );
		$order->update_meta_data( Helpers::META_REVIEW_STATUS, 'pending' );
		$order->update_meta_data( Helpers::META_REFERENCE, $data['reference'] );
		$order->update_meta_data( Helpers::META_REFERENCE_KEY, Helpers::canonical_reference( $data['reference'] ) );
		if ( 'yes' === $s['sender_name'] && '' !== $data['name'] ) {
			$order->update_meta_data( Helpers::META_SENDER_NAME, $data['name'] );
		}
		if ( 'yes' === $s['sender_last4'] && '' !== $data['last4'] ) {
			$order->update_meta_data( Helpers::META_SENDER_LAST4, $data['last4'] );
		}

		$info = null;
		if ( '' !== $data['token'] ) {
			$info = Receipts::get_temp( $data['token'] );
		} elseif ( $data['has_file'] ) {
			$info = Receipts::prepare_direct( $_FILES['wcctc_receipt'] ); // phpcs:ignore WordPress.Security
		}
		if ( is_wp_error( $info ) ) {
			$order->save();
			return $this->fail( $info->get_error_message() );
		}
		if ( $info ) {
			$attached = Receipts::attach( $order, $info );
			if ( is_wp_error( $attached ) ) {
				return $this->fail( $attached->get_error_message() );
			}
		}

		$order->update_status( 'on-hold', __( 'Awaiting manual verification of the transfer payment.', 'wc-card-transfer-gateway' ) );
		Database::audit( 'manual_payment_submitted', array( 'has_receipt' => (bool) $info ), $order->get_id() );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
	}

	private function process_stripe( \WC_Order $order ) {
		$stripe  = $this->stripe();
		$session = null;
		$sid     = $order->get_meta( Helpers::META_PROVIDER_SESSION_ID, true );
		if ( $sid ) {
			$existing = $stripe->retrieve_session( $sid );
			if ( ! is_wp_error( $existing ) && $stripe->session_reusable( $order, $existing ) ) {
				$session = $existing;
			}
		}
		if ( ! $session ) {
			$attempt = (int) $order->get_meta( Helpers::META_STRIPE_ATTEMPT, true ) + 1;
			$session = $stripe->create_payment( $order, $attempt );
			if ( is_wp_error( $session ) ) {
				$order->add_order_note( 'Stripe: ' . $session->get_error_message() );
				Database::audit( 'provider_error', array( 'error' => $session->get_error_message() ), $order->get_id(), 'payment' );
				return $this->fail( __( 'Could not start the Stripe payment. Please try again or choose another payment method.', 'wc-card-transfer-gateway' ) );
			}
			$order->update_meta_data( Helpers::META_STRIPE_ATTEMPT, $attempt );
		}
		if ( empty( $session['url'] ) ) {
			return $this->fail( __( 'Stripe did not return a payment page. Please try again.', 'wc-card-transfer-gateway' ) );
		}
		$order->update_meta_data( Helpers::META_PROVIDER, 'stripe' );
		$order->update_meta_data( Helpers::META_PROVIDER_SESSION_ID, sanitize_text_field( $session['id'] ?? '' ) );
		$order->update_meta_data( Helpers::META_PROVIDER_STATUS, 'redirected' );
		$order->save();
		Database::audit( 'stripe_session_created', array(), $order->get_id(), 'payment', $session['id'] ?? '' );
		return array( 'result' => 'success', 'redirect' => esc_url_raw( $session['url'] ) );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'wcctc_refund', __( 'Order not found.', 'wc-card-transfer-gateway' ) );
		}
		if ( Helpers::is_stripe_order( $order ) ) {
			if ( null === $amount || (float) $amount <= 0 ) {
				return new \WP_Error( 'wcctc_refund', __( 'Enter a refund amount greater than zero.', 'wc-card-transfer-gateway' ) );
			}
			$result = $this->stripe()->refund( $order, $amount, $reason, count( $order->get_refunds() ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$order->add_order_note( sprintf( /* translators: 1: amount 2: Stripe refund id */ __( 'Refunded %1$s via Stripe (refund %2$s).', 'wc-card-transfer-gateway' ), wc_price( $amount, array( 'currency' => $order->get_currency() ) ), sanitize_text_field( $result['id'] ?? '' ) ) );
			Database::audit( 'refund_created', array( 'amount' => $amount ), $order->get_id(), 'refund', $result['id'] ?? '' );
			return true;
		}
		$order->add_order_note( sprintf( /* translators: %s: amount */ __( 'Manual transfer refund of %s recorded. Send the money back to the customer yourself.', 'wc-card-transfer-gateway' ), wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) ) . ( $reason ? ' ' . sanitize_text_field( $reason ) : '' ) );
		Database::audit( 'manual_refund_recorded', array( 'amount' => $amount ), $order->get_id() );
		return true;
	}
}

class Iran extends Base {
	protected $mode = 'iran';

	public function init_form_fields() {
		$fields = $this->common_fields();
		$fields['ocr_provider'] = array( 'title' => __( 'Receipt OCR', 'wc-card-transfer-gateway' ), 'type' => 'select', 'default' => 'none', 'options' => array( 'none' => __( 'Disabled', 'wc-card-transfer-gateway' ), 'ocr_space' => 'OCR.space' ), 'description' => __( 'Optional hint for reviewers; applies to all manual receipts. The receipt image is sent to the provider.', 'wc-card-transfer-gateway' ), 'desc_tip' => true );
		$fields['ocr_api_key']  = array( 'title' => __( 'OCR API key', 'wc-card-transfer-gateway' ), 'type' => 'password', 'default' => '', 'description' => __( 'Or define WCCTC_OCR_API_KEY in wp-config.php.', 'wc-card-transfer-gateway' ) );
		$fields['ocr_language'] = array( 'title' => __( 'OCR language', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => 'eng', 'description' => __( 'OCR.space language code, e.g. eng or ara. Check the provider for supported languages.', 'wc-card-transfer-gateway' ) );
		$this->form_fields = $fields;
	}
}

class International extends Base {
	protected $mode = 'international';

	public function init_form_fields() {
		$fields = $this->common_fields();
		$fields['swift'] = array( 'title' => __( 'SWIFT/BIC', 'wc-card-transfer-gateway' ), 'type' => 'text', 'default' => '' );
		$fields['international_mode'] = array( 'title' => __( 'Mode', 'wc-card-transfer-gateway' ), 'type' => 'select', 'default' => 'manual', 'options' => array( 'manual' => __( 'Manual bank transfer', 'wc-card-transfer-gateway' ), 'stripe' => __( 'Stripe Checkout (automatic)', 'wc-card-transfer-gateway' ) ) );
		$fields['stripe_secret_key'] = array( 'title' => __( 'Stripe secret key', 'wc-card-transfer-gateway' ), 'type' => 'password', 'default' => '', 'description' => __( 'sk_… or restricted rk_… key. Or define WCCTC_STRIPE_SECRET_KEY in wp-config.php.', 'wc-card-transfer-gateway' ) );
		$fields['stripe_webhook_secret'] = array( 'title' => __( 'Stripe webhook secret', 'wc-card-transfer-gateway' ), 'type' => 'password', 'default' => '', 'description' => sprintf( /* translators: %s: URL */ __( 'Webhook endpoint: %s — events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, payment_intent.succeeded, payment_intent.payment_failed, charge.refunded, charge.dispute.created. Or define WCCTC_STRIPE_WEBHOOK_SECRET.', 'wc-card-transfer-gateway' ), esc_url( rest_url( 'wcctc/v1/stripe/webhook' ) ) ) );
		$this->form_fields = $fields;
	}

	public function validate_stripe_secret_key_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( wc_clean( wp_unslash( $value ) ) );
		if ( '' !== $value && ! StripeProvider::valid_secret_format( $value ) ) {
			\WC_Admin_Settings::add_error( __( 'The Stripe secret key must start with sk_test_, sk_live_, rk_test_ or rk_live_. The previous value was kept.', 'wc-card-transfer-gateway' ) );
			return $this->get_option( 'stripe_secret_key' );
		}
		return $value;
	}
}
