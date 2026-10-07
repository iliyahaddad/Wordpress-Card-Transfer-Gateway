<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class Helpers {
	public const IRAN_ID              = 'wcctc_iran';
	public const INTERNATIONAL_ID     = 'wcctc_international';
	public const META_REFERENCE       = '_wcctc_reference';
	public const META_REFERENCE_KEY   = '_wcctc_reference_key';
	public const META_SENDER_NAME     = '_wcctc_sender_name';
	public const META_SENDER_LAST4    = '_wcctc_sender_last4';
	public const META_REVIEW_STATUS   = '_wcctc_review_status';
	public const META_REVIEWED_BY     = '_wcctc_reviewed_by';
	public const META_REVIEWED_AT     = '_wcctc_reviewed_at';
	public const META_REVIEW_NOTE     = '_wcctc_review_note';
	public const META_APPROVED_ONCE   = '_wcctc_approved_once';
	public const META_RECEIPT_ID      = '_wcctc_receipt_id';
	public const META_RECEIPT_HASH    = '_wcctc_receipt_hash';
	public const META_OCR_STATUS      = '_wcctc_ocr_status';
	public const META_OCR_TEXT        = '_wcctc_ocr_text';
	public const META_OCR_SIGNALS     = '_wcctc_ocr_signals';
	public const META_DUPLICATE_FLAG  = '_wcctc_duplicate_flag';
	public const META_PROVIDER        = '_wcctc_provider';
	public const META_PROVIDER_SESSION_ID = '_wcctc_provider_session_id';
	public const META_PROVIDER_PAYMENT_ID = '_wcctc_provider_payment_id';
	public const META_PROVIDER_STATUS = '_wcctc_provider_status';
	public const META_STRIPE_ATTEMPT  = '_wcctc_stripe_attempt';
	public const META_STRIPE_RETURNED = '_wcctc_stripe_returned';

	/* ---------------------------------------------------------------- Privacy */

	public static function register_privacy_hooks() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'privacy_erasers' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'privacy_exporters' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
	}

	public static function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'WC Card Transfer Gateway',
			wp_kses_post( wpautop( __( 'When you pay by manual transfer we store the transfer reference, the optional sender name and last four card digits, and the receipt file you upload (in a private folder). If OCR is enabled by the store, the receipt image is sent to the configured OCR provider. If you pay with Stripe, card details are entered on Stripe and never reach this site.', 'wc-card-transfer-gateway' ) ) )
		);
	}

	public static function privacy_erasers( $erasers ) {
		$erasers['wcctc-transfer-data'] = array(
			'eraser_friendly_name' => __( 'WooCommerce transfer payment data', 'wc-card-transfer-gateway' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public static function privacy_exporters( $exporters ) {
		$exporters['wcctc-transfer-data'] = array(
			'exporter_friendly_name' => __( 'WooCommerce transfer payment data', 'wc-card-transfer-gateway' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function erase_personal_data( $email_address, $page = 1 ) {
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		$orders = wc_get_orders( array( 'limit' => 50, 'paged' => (int) $page, 'billing_email' => $email_address, 'return' => 'objects' ) );
		foreach ( $orders as $order ) {
			if ( ! self::is_ours( $order->get_payment_method() ) ) {
				continue;
			}
			Receipts::delete_files( $order );
			foreach ( array( self::META_REFERENCE, self::META_REFERENCE_KEY, self::META_SENDER_NAME, self::META_SENDER_LAST4, self::META_OCR_TEXT, self::META_OCR_SIGNALS, self::META_OCR_STATUS ) as $meta ) {
				$order->delete_meta_data( $meta );
			}
			$order->save();
			Database::anonymize_order( $order->get_id() );
			$result['items_removed'] = true;
		}
		$result['done'] = count( $orders ) < 50;
		return $result;
	}

	public static function export_personal_data( $email_address, $page = 1 ) {
		$data   = array();
		$orders = wc_get_orders( array( 'limit' => 50, 'paged' => (int) $page, 'billing_email' => $email_address, 'return' => 'objects' ) );
		foreach ( $orders as $order ) {
			$receipt = Receipts::get_attachment( $order );
			$values  = array(
				'reference' => $order->get_meta( self::META_REFERENCE, true ),
				'sender'    => $order->get_meta( self::META_SENDER_NAME, true ),
				'last4'     => $order->get_meta( self::META_SENDER_LAST4, true ),
				'receipt'   => $receipt ? $receipt['name'] : '',
			);
			if ( ! array_filter( $values ) ) {
				continue;
			}
			$data[] = array(
				'group_id'    => 'wcctc-order-' . $order->get_id(),
				'group_label' => __( 'Transfer payment', 'wc-card-transfer-gateway' ),
				'data'        => array(
					array( 'name' => __( 'Order', 'wc-card-transfer-gateway' ), 'value' => '#' . $order->get_order_number() ),
					array( 'name' => __( 'Reference', 'wc-card-transfer-gateway' ), 'value' => $values['reference'] ),
					array( 'name' => __( 'Sender name', 'wc-card-transfer-gateway' ), 'value' => $values['sender'] ),
					array( 'name' => __( 'Sender card last 4', 'wc-card-transfer-gateway' ), 'value' => $values['last4'] ),
					array( 'name' => __( 'Receipt file', 'wc-card-transfer-gateway' ), 'value' => $values['receipt'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 50 );
	}

	/* --------------------------------------------------------------- Settings */

	public static function is_ours( $method_id ) {
		return in_array( $method_id, array( self::IRAN_ID, self::INTERNATIONAL_ID ), true );
	}

	public static function is_stripe_order( $order ) {
		return $order && self::INTERNATIONAL_ID === $order->get_payment_method() && 'stripe' === $order->get_meta( self::META_PROVIDER, true );
	}

	public static function is_manual_order( $order ) {
		return $order && self::is_ours( $order->get_payment_method() ) && ! self::is_stripe_order( $order );
	}

	public static function gateway_label( $id ) {
		return self::IRAN_ID === $id
			? __( 'Iran card-to-card transfer', 'wc-card-transfer-gateway' )
			: __( 'International payment / transfer', 'wc-card-transfer-gateway' );
	}

	public static function get_gateway_settings( $gateway_id ) {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function default_settings( $id ) {
		$iran = self::IRAN_ID === $id;
		return array(
			'enabled'            => 'no',
			'title'              => self::gateway_label( $id ),
			'description'        => $iran
				? __( 'Transfer the exact amount to the receiving card, then submit your bank reference and receipt.', 'wc-card-transfer-gateway' )
				: __( 'Choose manual transfer or automated Stripe Checkout.', 'wc-card-transfer-gateway' ),
			'recipient_name'     => '',
			'recipient_card'     => '',
			'bank_name'          => '',
			'account_number'     => '',
			'swift'              => '',
			'instructions'       => '',
			'reference_label'    => $iran ? __( 'Bank tracking / transaction reference', 'wc-card-transfer-gateway' ) : __( 'Transfer reference', 'wc-card-transfer-gateway' ),
			'reference_help'     => __( 'Used for reconciliation; it is not proof of payment by itself.', 'wc-card-transfer-gateway' ),
			'reference_required' => 'yes',
			'sender_name'        => 'yes',
			'sender_last4'       => 'no',
			'receipt_required'   => 'no',
			'allowed_currencies' => '',
			'international_mode' => 'manual',
		);
	}

	/** Settings with defaults applied (safe to call before the admin ever saved the form). */
	public static function public_settings( $id ) {
		$out = array_merge( self::default_settings( $id ), array_intersect_key( self::get_gateway_settings( $id ), self::default_settings( $id ) ) );
		foreach ( $out as $k => $v ) {
			if ( '' === $v && in_array( $k, array( 'title', 'reference_label' ), true ) ) {
				$out[ $k ] = self::default_settings( $id )[ $k ];
			}
		}
		return $out;
	}

	/** Stripe credentials; constants in wp-config.php take precedence over stored options. */
	public static function stripe_settings() {
		$opts   = self::get_gateway_settings( self::INTERNATIONAL_ID );
		$secret = defined( 'WCCTC_STRIPE_SECRET_KEY' ) ? (string) WCCTC_STRIPE_SECRET_KEY : (string) ( $opts['stripe_secret_key'] ?? '' );
		$hook   = defined( 'WCCTC_STRIPE_WEBHOOK_SECRET' ) ? (string) WCCTC_STRIPE_WEBHOOK_SECRET : (string) ( $opts['stripe_webhook_secret'] ?? '' );
		return array( 'stripe_secret_key' => $secret, 'stripe_webhook_secret' => $hook );
	}

	public static function destination_rows( $s ) {
		$rows = array(
			__( 'Name:', 'wc-card-transfer-gateway' )            => $s['recipient_name'],
			__( 'Card:', 'wc-card-transfer-gateway' )            => self::display_card_number( $s['recipient_card'] ),
			__( 'Bank:', 'wc-card-transfer-gateway' )            => $s['bank_name'],
			__( 'Account / IBAN:', 'wc-card-transfer-gateway' )  => $s['account_number'],
			__( 'SWIFT/BIC:', 'wc-card-transfer-gateway' )       => $s['swift'],
		);
		return array_filter( $rows, 'strlen' );
	}

	public static function destination_html( $s ) {
		$html = '<div class="wcctc-recipient"><strong>' . esc_html__( 'Transfer destination', 'wc-card-transfer-gateway' ) . '</strong>';
		foreach ( self::destination_rows( $s ) as $label => $value ) {
			$html .= '<p><span>' . esc_html( $label ) . '</span> <bdi class="wcctc-value">' . esc_html( $value ) . '</bdi></p>';
		}
		$html .= '</div>';
		if ( $s['instructions'] ) {
			$html .= '<div class="wcctc-instructions">' . wpautop( wp_kses_post( $s['instructions'] ) ) . '</div>';
		}
		return $html;
	}

	/* ------------------------------------------------------------ Sanitizing */

	public static function normalize_digits( $value ) {
		return str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array_merge( range( '0', '9' ), range( '0', '9' ) ),
			(string) $value
		);
	}

	/** Input must still be slashed ($_POST); output is clean ASCII. */
	public static function sanitize_reference( $value ) {
		$value = self::normalize_digits( wp_unslash( (string) $value ) );
		return trim( substr( preg_replace( '/[^A-Za-z0-9\-_.\/ ]/', '', $value ), 0, 120 ) );
	}

	/** Spaces/punctuation/case-insensitive key used for duplicate detection. */
	public static function canonical_reference( $value ) {
		return strtolower( preg_replace( '/[\s\-_.\/]+/', '', self::normalize_digits( (string) $value ) ) );
	}

	public static function sanitize_last4( $value ) {
		$value = preg_replace( '/\D+/', '', self::normalize_digits( wp_unslash( (string) $value ) ) );
		return 4 === strlen( $value ) ? $value : '';
	}

	public static function display_card_number( $value ) {
		$digits = preg_replace( '/\D+/', '', self::normalize_digits( $value ) );
		return 16 === strlen( $digits ) ? implode( '-', str_split( $digits, 4 ) ) : (string) $value;
	}

	public static function luhn_valid( $digits ) {
		$digits = preg_replace( '/\D+/', '', (string) $digits );
		$sum    = 0;
		$alt    = false;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$n = (int) $digits[ $i ];
			if ( $alt ) {
				$n *= 2;
				if ( $n > 9 ) {
					$n -= 9;
				}
			}
			$sum += $n;
			$alt  = ! $alt;
		}
		return strlen( $digits ) > 1 && 0 === $sum % 10;
	}

	public static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/* -------------------------------------------------------------- Currency */

	public static function currency_exponent( $currency ) {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true ) ) {
			return 0;
		}
		if ( in_array( $currency, array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ), true ) ) {
			return 3;
		}
		return 2; // ISK is treated as a two-decimal currency by Stripe.
	}

	public static function to_minor_units( $amount, $currency ) {
		$exp   = self::currency_exponent( $currency );
		$minor = (int) round( (float) $amount * pow( 10, $exp ) );
		if ( 3 === $exp ) {
			$minor = (int) ( round( $minor / 10 ) * 10 ); // Stripe requires the last digit to be 0.
		}
		return $minor;
	}

	/* ------------------------------------------------------------ Duplicates */

	private static function first_id( $args, $exclude ) {
		$args['limit']  = 1;
		$args['return'] = 'ids';
		if ( $exclude ) {
			$args['exclude'] = array( (int) $exclude );
		}
		$ids = wc_get_orders( $args );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function find_duplicate_reference( $reference, $exclude_order_id = 0 ) {
		$key = self::canonical_reference( $reference );
		if ( '' === $key ) {
			return 0;
		}
		return self::first_id( array(
			'status'     => array( 'pending', 'processing', 'on-hold', 'completed' ),
			'meta_query' => array( array( 'key' => self::META_REFERENCE_KEY, 'value' => $key ) ),
		), $exclude_order_id );
	}

	public static function find_duplicate_hash( $hash, $exclude_order_id = 0 ) {
		if ( ! $hash ) {
			return 0;
		}
		return self::first_id( array(
			'status'     => array( 'pending', 'processing', 'on-hold', 'completed', 'failed', 'refunded' ),
			'meta_query' => array( array( 'key' => self::META_RECEIPT_HASH, 'value' => $hash ) ),
		), $exclude_order_id );
	}

	/* --------------------------------------------------------------- Review */

	/**
	 * @return true|\WP_Error
	 */
	public static function review_order( $order, $decision, $note = '', $notify_customer = false ) {
		if ( ! $order || ! self::is_manual_order( $order ) ) {
			return new \WP_Error( 'wcctc_not_manual', __( 'This is not a manual transfer order.', 'wc-card-transfer-gateway' ) );
		}
		$decision = in_array( $decision, array( 'approved', 'rejected', 'pending' ), true ) ? $decision : 'pending';
		$note     = sanitize_textarea_field( (string) $note );
		$current  = $order->get_meta( self::META_REVIEW_STATUS, true );

		if ( 'approved' === $decision ) {
			if ( 'yes' === $order->get_meta( self::META_APPROVED_ONCE, true ) ) {
				return new \WP_Error( 'wcctc_already_approved', __( 'This order was already approved.', 'wc-card-transfer-gateway' ) );
			}
		} elseif ( 'approved' === $current || $order->is_paid() ) {
			return new \WP_Error( 'wcctc_paid', __( 'A paid or approved order cannot be rejected or reset. Use a refund instead.', 'wc-card-transfer-gateway' ) );
		}

		$order->update_meta_data( self::META_REVIEW_STATUS, $decision );
		$order->update_meta_data( self::META_REVIEWED_BY, get_current_user_id() );
		$order->update_meta_data( self::META_REVIEWED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( self::META_REVIEW_NOTE, $note );

		$suffix = $note ? ' ' . $note : '';
		if ( 'approved' === $decision ) {
			$order->update_meta_data( self::META_APPROVED_ONCE, 'yes' );
			if ( ! $order->is_paid() ) {
				$order->payment_complete( (string) $order->get_meta( self::META_REFERENCE, true ) );
			}
			$order->add_order_note( __( 'Transfer payment manually verified and approved by an administrator.', 'wc-card-transfer-gateway' ) . $suffix, $notify_customer ? 1 : 0, true );
		} elseif ( 'rejected' === $decision ) {
			$order->update_status( 'failed', __( 'Transfer payment rejected during manual verification.', 'wc-card-transfer-gateway' ) . $suffix, true );
			if ( $notify_customer && $note ) {
				$order->add_order_note( $note, 1, true );
			}
		} else {
			$order->update_status( 'on-hold', __( 'Transfer payment remains pending manual verification.', 'wc-card-transfer-gateway' ) . $suffix, true );
		}
		$order->save();
		Database::audit( 'manual_review', array( 'decision' => $decision, 'note' => $note ), $order->get_id() );
		return true;
	}
}
