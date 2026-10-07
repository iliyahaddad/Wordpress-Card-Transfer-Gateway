<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Optional OCR (OCR.space). OCR output is evidence for the reviewer only; it never approves an order.
 * OCR settings live on the Iran gateway and apply to every manual-transfer receipt.
 */
final class OCR {
	public static function settings() {
		$s = Helpers::get_gateway_settings( Helpers::IRAN_ID );
		return array(
			'provider' => $s['ocr_provider'] ?? 'none',
			'key'      => defined( 'WCCTC_OCR_API_KEY' ) ? (string) WCCTC_OCR_API_KEY : (string) ( $s['ocr_api_key'] ?? '' ),
			'language' => ! empty( $s['ocr_language'] ) ? sanitize_key( $s['ocr_language'] ) : 'eng',
		);
	}

	public static function enabled() {
		$s = self::settings();
		return 'ocr_space' === $s['provider'] && '' !== $s['key'];
	}

	/** Cron callback: OCR runs in the background so checkout is never blocked by a third-party API. */
	public static function run_for_order( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		$att  = Receipts::get_attachment( $order );
		$path = $att ? Receipts::attachment_path( $att ) : '';
		if ( ! $path ) {
			return;
		}
		$res     = self::process( $path, $att['mime'] );
		$signals = $res['matches'];
		if ( 'complete' === $res['status'] ) {
			$signals['checks'] = self::cross_check( $res['text'], $order );
		}
		$order->update_meta_data( Helpers::META_OCR_STATUS, $res['status'] );
		$order->update_meta_data( Helpers::META_OCR_TEXT, substr( sanitize_textarea_field( $res['text'] ), 0, 10000 ) );
		$order->update_meta_data( Helpers::META_OCR_SIGNALS, wp_json_encode( $signals ) );
		if ( 'complete' === $res['status'] ) {
			$checks = $signals['checks'];
			$order->add_order_note( sprintf(
				/* translators: 1: reference found yes/no, 2: card, 3: amount */
				__( 'Receipt OCR finished. Reference: %1$s, recipient card: %2$s, amount: %3$s. OCR is only a hint; verify the funds yourself.', 'wc-card-transfer-gateway' ),
				$checks['reference'] ? '✔' : '✖',
				$checks['recipient_card'] ? '✔' : '✖',
				$checks['amount'] ? '✔' : '✖'
			) );
		}
		$order->save();
		Database::audit( 'ocr_finished', array( 'status' => $res['status'] ), $order->get_id(), 'receipt' );
	}

	public static function process( $path, $mime ) {
		$s = self::settings();
		if ( 'ocr_space' !== $s['provider'] || '' === $s['key'] ) {
			return array( 'status' => 'not_configured', 'text' => '', 'matches' => array() );
		}
		$bytes = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $bytes || strlen( $bytes ) > 5 * MB_IN_BYTES ) {
			return array( 'status' => 'failed', 'text' => '', 'matches' => array( 'error' => 'Receipt is unavailable or too large.' ) );
		}
		$body = array(
			'language'          => $s['language'],
			'isOverlayRequired' => 'false',
			'base64Image'       => 'data:' . $mime . ';base64,' . base64_encode( $bytes ),
		);
		if ( 'application/pdf' === $mime ) {
			$body['filetype'] = 'PDF';
		}
		$response = wp_remote_post( 'https://api.ocr.space/parse/image', array(
			'timeout' => 45,
			'headers' => array( 'apikey' => sanitize_text_field( $s['key'] ) ),
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'failed', 'text' => '', 'matches' => array( 'error' => $response->get_error_message() ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! empty( $data['IsErroredOnProcessing'] ) || empty( $data['ParsedResults'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['ErrorMessage'] ) ? implode( '; ', (array) $data['ErrorMessage'] ) : 'OCR provider returned no text.';
			return array( 'status' => 'failed', 'text' => '', 'matches' => array( 'error' => sanitize_text_field( $msg ) ) );
		}
		$text = '';
		foreach ( $data['ParsedResults'] as $result ) {
			$text .= "\n" . ( $result['ParsedText'] ?? '' );
		}
		$text = trim( $text );
		return array( 'status' => 'complete', 'text' => $text, 'matches' => self::extract_signals( $text ) );
	}

	public static function extract_signals( $text ) {
		$text    = Helpers::normalize_digits( $text );
		$signals = array();
		if ( preg_match( '/(?:IR\d{2}[A-Z0-9]{10,})/i', $text, $m ) ) {
			$signals['iban_candidate'] = strtoupper( $m[0] );
		}
		if ( preg_match( '/\b(?:\d[ -]?){15}\d\b/', $text, $m ) ) {
			$signals['card_candidate'] = preg_replace( '/\D+/', '', $m[0] );
		}
		if ( preg_match( '/(?:amount|total|مبلغ|جمع)[^\d]{0,30}([\d,\.٬،]+)/iu', $text, $m ) ) {
			$signals['amount_candidate'] = $m[1];
		}
		return $signals;
	}

	/** Compare OCR text with what the order expects. Each flag is a hint, not proof. */
	public static function cross_check( $text, $order ) {
		$norm    = Helpers::normalize_digits( $text );
		$plain   = preg_replace( '/(?<=\d)[,٬،](?=\d)/u', '', $norm );
		$digits  = preg_replace( '/\D+/', '', $norm );
		$compact = strtolower( preg_replace( '/[\s\-_.\/]+/', '', $norm ) );

		$ref_key   = Helpers::canonical_reference( $order->get_meta( Helpers::META_REFERENCE, true ) );
		$reference = strlen( $ref_key ) >= 6 && false !== strpos( $compact, $ref_key );

		$s    = Helpers::public_settings( $order->get_payment_method() );
		$card = preg_replace( '/\D+/', '', Helpers::normalize_digits( $s['recipient_card'] ) );
		$recipient = 16 === strlen( $card ) && false !== strpos( $digits, $card );

		$total      = (float) $order->get_total();
		$candidates = array( (string) (int) round( $total ) );
		$currency   = strtoupper( $order->get_currency() );
		if ( 'IRT' === $currency ) {
			$candidates[] = (string) ( (int) round( $total ) * 10 ); // Toman shown as Rial on the receipt.
		} elseif ( 'IRR' === $currency ) {
			$candidates[] = (string) (int) round( $total / 10 );
		}
		if ( 2 === Helpers::currency_exponent( $currency ) ) {
			$candidates[] = number_format( $total, 2, '.', '' );
		}
		$amount = false;
		foreach ( array_unique( $candidates ) as $c ) {
			if ( (int) $c > 0 && preg_match( '/(?<![\d.])' . preg_quote( $c, '/' ) . '(?![\d])/', $plain ) ) {
				$amount = true;
				break;
			}
		}
		return array( 'reference' => $reference, 'recipient_card' => $recipient, 'amount' => $amount );
	}
}
