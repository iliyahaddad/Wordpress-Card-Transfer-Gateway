<?php
namespace WCCTC\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WCCTC\Helpers;
use WCCTC\Receipts;

defined( 'ABSPATH' ) || exit;

final class MethodType extends AbstractPaymentMethodType {
	private $gateway_id;
	private $gateway;

	public function __construct( $gateway_id ) {
		$this->gateway_id = $gateway_id;
		$this->name       = $gateway_id;
	}

	public function initialize() {
		$this->settings = Helpers::get_gateway_settings( $this->gateway_id );
	}

	private function gateway() {
		if ( ! $this->gateway ) {
			$gateways      = WC()->payment_gateways()->payment_gateways();
			$this->gateway = $gateways[ $this->gateway_id ] ?? null;
		}
		return $this->gateway;
	}

	public function is_active() {
		$gw = $this->gateway();
		if ( ! $gw || 'yes' !== $gw->enabled ) {
			return false;
		}
		$s = $gw->get_public_settings();
		return ! ( 'stripe' === $s['mode'] && '' === Helpers::stripe_settings()['stripe_secret_key'] );
	}

	public function get_payment_method_script_handles() {
		// One shared script registers both methods; register the handle only once.
		if ( ! wp_script_is( 'wcctc-checkout-blocks', 'registered' ) ) {
			wp_register_script(
				'wcctc-checkout-blocks',
				WCCTC_URL . 'assets/js/checkout-block.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
				WCCTC_VERSION,
				true
			);
		}
		return array( 'wcctc-checkout-blocks' );
	}

	public function get_payment_method_style_handles() {
		if ( ! wp_style_is( 'wcctc-checkout-blocks', 'registered' ) ) {
			wp_register_style( 'wcctc-checkout-blocks', WCCTC_URL . 'assets/css/checkout.css', array(), WCCTC_VERSION );
		}
		return array( 'wcctc-checkout-blocks' );
	}

	public function get_payment_method_data() {
		$gw = $this->gateway();
		$s  = $gw && method_exists( $gw, 'get_public_settings' ) ? $gw->get_public_settings() : Helpers::public_settings( $this->gateway_id );
		return array(
			'title'             => $s['title'],
			'description'       => $s['description'],
			'mode'              => $s['mode'] ?? 'manual',
			'rows'              => Helpers::destination_rows( $s ),
			'instructions'      => wp_kses_post( $s['instructions'] ),
			'referenceLabel'    => $s['reference_label'],
			'referenceHelp'     => $s['reference_help'],
			'referenceRequired' => 'yes' === $s['reference_required'],
			'senderName'        => 'yes' === $s['sender_name'],
			'senderLast4'       => 'yes' === $s['sender_last4'],
			'receiptRequired'   => 'yes' === $s['receipt_required'],
			'allowedCurrencies' => array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) $s['allowed_currencies'] ) ) ) ) ),
			'restUrl'           => esc_url_raw( rest_url( 'wcctc/v1/receipt' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'maxBytes'          => Receipts::max_bytes(),
			'i18n'              => array(
				'refRequired'     => __( 'Please enter the bank transfer reference.', 'wc-card-transfer-gateway' ),
				'last4Invalid'    => __( 'Sender card last 4 digits must be exactly four digits.', 'wc-card-transfer-gateway' ),
				'receiptRequired' => __( 'Please upload the transfer receipt.', 'wc-card-transfer-gateway' ),
				'tooLarge'        => __( 'Receipt must be 5 MB or smaller.', 'wc-card-transfer-gateway' ),
				'uploading'       => __( 'Uploading…', 'wc-card-transfer-gateway' ),
				'uploaded'        => __( 'Receipt attached:', 'wc-card-transfer-gateway' ),
				'failed'          => __( 'Receipt upload failed.', 'wc-card-transfer-gateway' ),
				'stripeNote'      => __( 'You will be redirected to Stripe to complete the payment securely.', 'wc-card-transfer-gateway' ),
				'destination'     => __( 'Transfer destination', 'wc-card-transfer-gateway' ),
				'senderName'      => __( 'Sender name', 'wc-card-transfer-gateway' ),
				'last4Label'      => __( 'Last 4 digits of the sender card', 'wc-card-transfer-gateway' ),
				'receiptLabel'    => __( 'Transfer receipt', 'wc-card-transfer-gateway' ),
			),
			'supports'          => array( 'features' => array( 'products', 'refunds' ) ),
		);
	}
}
