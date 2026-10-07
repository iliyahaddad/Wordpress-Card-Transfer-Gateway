<?php
/**
 * Dependency-free unit checks for pure helper logic (no WordPress needed).
 * Run: php tests/unit.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WCCTC_VERSION', 'test' );
define( 'MB_IN_BYTES', 1048576 );
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function absint( $v ) { return abs( (int) $v ); }
function __( $s ) { return $s; }
class WP_Error {
	public $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require __DIR__ . '/../src/Helpers.php';
require __DIR__ . '/../src/Providers.php';

use WCCTC\Helpers;
use WCCTC\StripeProvider;

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

check( 'luhn known valid (4111...)', Helpers::luhn_valid( '4111111111111111' ) );
check( 'luhn known invalid', ! Helpers::luhn_valid( '4111111111111112' ) );
check( 'persian digits normalized', Helpers::normalize_digits( '۱۲۳۴' ) === '1234' );
check( 'arabic digits normalized', Helpers::normalize_digits( '٠١٢٣' ) === '0123' );
check( 'reference key ignores spaces/case/punct', Helpers::canonical_reference( 'AB-12 34.cd' ) === 'ab1234cd' );
check( 'sanitize_reference strips junk', Helpers::sanitize_reference( 'ab<script>12' ) === 'abscript12' );
check( 'last4 ok', Helpers::sanitize_last4( '۱۲۳۴' ) === '1234' );
check( 'last4 rejects 3 digits', Helpers::sanitize_last4( '123' ) === '' );
check( 'minor units USD', Helpers::to_minor_units( 19.99, 'usd' ) === 1999 );
check( 'minor units JPY', Helpers::to_minor_units( 500, 'JPY' ) === 500 );
check( 'minor units KWD multiple of 10', Helpers::to_minor_units( 1.2345, 'KWD' ) % 10 === 0 );
check( 'card formatting', Helpers::display_card_number( '6037997212345674' ) === '6037-9972-1234-5674' );

$secret = 'whsec_test';
$body   = json_encode( array( 'id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => array( 'object' => array() ) ) );
$t      = time();
$sig    = hash_hmac( 'sha256', $t . '.' . $body, $secret );
$p      = new StripeProvider( array( 'stripe_secret_key' => 'sk_test_x', 'stripe_webhook_secret' => $secret ) );
check( 'webhook: valid signature', is_array( $p->handle_webhook( $body, "t=$t,v1=$sig" ) ) );
check( 'webhook: rotated secrets (2 v1)', is_array( $p->handle_webhook( $body, "t=$t,v1=deadbeef,v1=$sig" ) ) );
check( 'webhook: bad signature', is_wp_error( $p->handle_webhook( $body, "t=$t,v1=deadbeef" ) ) );
check( 'webhook: missing header', is_wp_error( $p->handle_webhook( $body, '' ) ) );
check( 'webhook: stale timestamp', is_wp_error( $p->handle_webhook( $body, 't=' . ( $t - 4000 ) . ',v1=' . hash_hmac( 'sha256', ( $t - 4000 ) . '.' . $body, $secret ) ) ) );
check( 'stripe key format', StripeProvider::valid_secret_format( 'sk_test_abc123' ) && ! StripeProvider::valid_secret_format( 'pk_test_abc' ) );

exit( $fails ? 1 : 0 );
