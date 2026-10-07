<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class Receipts {
	private const ALLOWED = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/webp'      => 'webp',
		'application/pdf' => 'pdf',
	);

	public static function register() {
		add_action( 'admin_post_wcctc_download_receipt', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_wcctc_upload_receipt', array( __CLASS__, 'customer_upload' ) );
		add_action( 'admin_post_nopriv_wcctc_upload_receipt', array( __CLASS__, 'customer_upload' ) );
	}

	public static function max_bytes() {
		return (int) apply_filters( 'wcctc_max_receipt_bytes', 5 * MB_IN_BYTES );
	}

	/* ---------------------------------------------------------------- Storage */

	public static function private_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'wcctc-private';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::protect( $dir );
		$tmp = $dir . '/tmp';
		if ( ! is_dir( $tmp ) ) {
			wp_mkdir_p( $tmp );
		}
		return $dir;
	}

	private static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	public static function attachment_path( $attachment ) {
		if ( ! is_array( $attachment ) ) {
			return '';
		}
		$dir = self::private_dir();
		if ( $dir && ! empty( $attachment['file'] ) ) {
			$path = $dir . '/' . basename( $attachment['file'] );
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		// Backwards compatibility with v2.0.0 which stored absolute paths.
		if ( $dir && ! empty( $attachment['path'] ) ) {
			$real = realpath( $attachment['path'] );
			$base = realpath( $dir );
			if ( $real && $base && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
				return $real;
			}
		}
		return '';
	}

	public static function get_attachment( $order ) {
		$raw = $order->get_meta( Helpers::META_RECEIPT_ID, true );
		if ( ! $raw ) {
			return null;
		}
		$data = json_decode( base64_decode( $raw ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function delete_files( $order ) {
		$att  = self::get_attachment( $order );
		$path = $att ? self::attachment_path( $att ) : '';
		if ( $path ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		foreach ( array( Helpers::META_RECEIPT_ID, Helpers::META_RECEIPT_HASH, Helpers::META_DUPLICATE_FLAG ) as $meta ) {
			$order->delete_meta_data( $meta );
		}
	}

	/* ------------------------------------------------------------- Validation */

	private static function error_message( $code ) {
		$map = array(
			'wcctc_upload_error' => __( 'Receipt upload failed.', 'wc-card-transfer-gateway' ),
			'wcctc_upload_size'  => __( 'Receipt must be 5 MB or smaller.', 'wc-card-transfer-gateway' ),
			'wcctc_upload_type'  => __( 'Only JPG, PNG, WebP or PDF receipts are allowed.', 'wc-card-transfer-gateway' ),
			'wcctc_duplicate_receipt' => __( 'This receipt file has already been used for another order. Please upload the correct receipt.', 'wc-card-transfer-gateway' ),
			'wcctc_storage'      => __( 'Could not store the receipt. Please try again later.', 'wc-card-transfer-gateway' ),
			'wcctc_token'        => __( 'The uploaded receipt expired. Please upload it again.', 'wc-card-transfer-gateway' ),
		);
		return $map[ $code ] ?? $map['wcctc_upload_error'];
	}

	private static function err( $code ) {
		return new \WP_Error( $code, self::error_message( $code ) );
	}

	/** Validate a file on disk and describe it. @return array|\WP_Error */
	private static function inspect( $path, $original_name, $size ) {
		if ( (int) $size > self::max_bytes() || filesize( $path ) > self::max_bytes() ) {
			return self::err( 'wcctc_upload_size' );
		}
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$f = finfo_open( FILEINFO_MIME_TYPE );
			if ( $f ) {
				$mime = (string) finfo_file( $f, $path );
				finfo_close( $f );
			}
		}
		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $path );
		}
		if ( ! isset( self::ALLOWED[ $mime ] ) ) {
			return self::err( 'wcctc_upload_type' );
		}
		if ( 0 === strpos( $mime, 'image/' ) && false === @getimagesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::err( 'wcctc_upload_type' );
		}
		if ( 'application/pdf' === $mime ) {
			$fh   = fopen( $path, 'rb' );
			$head = $fh ? fread( $fh, 5 ) : '';
			if ( $fh ) {
				fclose( $fh );
			}
			if ( '%PDF-' !== $head ) {
				return self::err( 'wcctc_upload_type' );
			}
		}
		$hash = hash_file( 'sha256', $path );
		if ( ! $hash ) {
			return self::err( 'wcctc_upload_error' );
		}
		return array(
			'path' => $path,
			'mime' => $mime,
			'ext'  => self::ALLOWED[ $mime ],
			'name' => sanitize_file_name( $original_name ),
			'hash' => $hash,
		);
	}

	/** Validate an entry of $_FILES. @return array|\WP_Error */
	public static function prepare_direct( $file ) {
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return self::err( 'wcctc_upload_error' );
		}
		$info = self::inspect( $file['tmp_name'], (string) ( $file['name'] ?? 'receipt' ), (int) ( $file['size'] ?? 0 ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$info['uploaded'] = true;
		return $info;
	}

	/* ------------------------------------------- Pre-upload (checkout / blocks) */

	public static function store_temp( $file ) {
		$info = self::prepare_direct( $file );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( Helpers::find_duplicate_hash( $info['hash'] ) ) {
			return self::err( 'wcctc_duplicate_receipt' );
		}
		$dir = self::private_dir();
		if ( ! $dir ) {
			return self::err( 'wcctc_storage' );
		}
		$token  = bin2hex( random_bytes( 16 ) );
		$target = $dir . '/tmp/' . $token . '.' . $info['ext'];
		if ( ! move_uploaded_file( $info['path'], $target ) ) {
			return self::err( 'wcctc_storage' );
		}
		set_transient( 'wcctc_tmp_' . $token, array(
			'file' => basename( $target ),
			'mime' => $info['mime'],
			'ext'  => $info['ext'],
			'name' => $info['name'],
			'hash' => $info['hash'],
		), 2 * HOUR_IN_SECONDS );
		return array( 'token' => $token, 'name' => $info['name'] );
	}

	/** @return array|\WP_Error */
	public static function get_temp( $token ) {
		$token = (string) $token;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return self::err( 'wcctc_token' );
		}
		$data = get_transient( 'wcctc_tmp_' . $token );
		$dir  = self::private_dir();
		if ( ! is_array( $data ) || ! $dir || ! is_file( $dir . '/tmp/' . basename( $data['file'] ) ) ) {
			return self::err( 'wcctc_token' );
		}
		$data['path']     = $dir . '/tmp/' . basename( $data['file'] );
		$data['uploaded'] = false;
		$data['token']    = $token;
		return $data;
	}

	public static function cleanup_temp() {
		$dir = self::private_dir();
		if ( ! $dir ) {
			return;
		}
		foreach ( (array) glob( $dir . '/tmp/*' ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - 3 * HOUR_IN_SECONDS ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	public static function rest_upload( $request ) {
		$enabled = false;
		foreach ( array( Helpers::IRAN_ID, Helpers::INTERNATIONAL_ID ) as $id ) {
			$s = Helpers::get_gateway_settings( $id );
			if ( isset( $s['enabled'] ) && 'yes' === $s['enabled'] ) {
				$enabled = true;
			}
		}
		if ( ! $enabled ) {
			return new \WP_Error( 'wcctc_disabled', __( 'Receipt upload is not available.', 'wc-card-transfer-gateway' ), array( 'status' => 403 ) );
		}
		$rl_key = 'wcctc_rl_' . md5( Helpers::client_ip() );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= 20 ) {
			return new \WP_Error( 'wcctc_rate_limited', __( 'Too many uploads. Please try again later.', 'wc-card-transfer-gateway' ), array( 'status' => 429 ) );
		}
		set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );

		$files  = $request->get_file_params();
		$result = self::store_temp( $files['receipt'] ?? array() );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	/* ------------------------------------------------------------- Attaching */

	/**
	 * Move a validated receipt into the private folder and attach it to the order.
	 *
	 * @return array|\WP_Error Attachment data.
	 */
	public static function attach( $order, $info ) {
		$dup = Helpers::find_duplicate_hash( $info['hash'], $order->get_id() );
		if ( $dup ) {
			$order->update_meta_data( Helpers::META_DUPLICATE_FLAG, $dup );
			$order->save();
			Database::audit( 'duplicate_receipt_detected', array( 'duplicate_order_id' => $dup, 'hash' => $info['hash'] ), $order->get_id() );
			return self::err( 'wcctc_duplicate_receipt' ); // Never reveal the other order number to the customer.
		}
		$dir = self::private_dir();
		if ( ! $dir ) {
			return self::err( 'wcctc_storage' );
		}
		$filename = 'order-' . $order->get_id() . '-' . wp_generate_password( 20, false, false ) . '.' . $info['ext'];
		$target   = $dir . '/' . $filename;
		if ( ! empty( $info['uploaded'] ) ) {
			$moved = move_uploaded_file( $info['path'], $target );
		} else {
			$moved = @rename( $info['path'], $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $moved && @copy( $info['path'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $info['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$moved = true;
			}
		}
		if ( ! $moved ) {
			return self::err( 'wcctc_storage' );
		}
		$old     = self::get_attachment( $order );
		$old_path = $old ? self::attachment_path( $old ) : '';

		$attachment = array(
			'file'        => $filename,
			'name'        => $info['name'],
			'mime'        => $info['mime'],
			'hash'        => $info['hash'],
			'uploaded_at' => current_time( 'mysql', true ),
		);
		$ocr_on = OCR::enabled();
		$order->update_meta_data( Helpers::META_RECEIPT_ID, base64_encode( wp_json_encode( $attachment ) ) );
		$order->update_meta_data( Helpers::META_RECEIPT_HASH, $info['hash'] );
		$order->update_meta_data( Helpers::META_OCR_STATUS, $ocr_on ? 'queued' : 'disabled' );
		$order->delete_meta_data( Helpers::META_OCR_TEXT );
		$order->delete_meta_data( Helpers::META_OCR_SIGNALS );
		$order->delete_meta_data( Helpers::META_DUPLICATE_FLAG );
		$order->save();

		if ( $old_path && basename( $old_path ) !== $filename ) {
			@unlink( $old_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! empty( $info['token'] ) ) {
			delete_transient( 'wcctc_tmp_' . $info['token'] );
		}
		if ( $ocr_on ) {
			wp_schedule_single_event( time() + 5, 'wcctc_process_ocr', array( $order->get_id() ) );
		}
		$order->add_order_note( __( 'Customer uploaded a transfer receipt.', 'wc-card-transfer-gateway' ) );
		Database::audit( 'receipt_uploaded', array( 'hash' => $info['hash'], 'mime' => $info['mime'], 'ocr' => $ocr_on ? 'queued' : 'disabled' ), $order->get_id(), 'receipt', $info['hash'] );
		return $attachment;
	}

	/* ------------------------------------------------- Customer upload (later) */

	public static function can_render_upload( $order ) {
		return $order && Helpers::is_manual_order( $order ) && ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) );
	}

	private static function can_customer_upload( $order, $key ) {
		if ( ! self::can_render_upload( $order ) ) {
			return false;
		}
		if ( is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() ) {
			return true;
		}
		return '' !== $key && hash_equals( $order->get_order_key(), $key );
	}

	public static function render_upload_form( $order ) {
		if ( ! self::can_render_upload( $order ) ) {
			return;
		}
		$notice = isset( $_GET['wcctc_notice'] ) ? sanitize_key( wp_unslash( $_GET['wcctc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'ok' === $notice ) {
			echo '<div class="woocommerce-message wcctc-notice" role="status">' . esc_html__( 'Receipt uploaded. It will be reviewed shortly.', 'wc-card-transfer-gateway' ) . '</div>';
		} elseif ( $notice ) {
			echo '<div class="woocommerce-error wcctc-notice" role="alert">' . esc_html( self::error_message( $notice ) ) . '</div>';
		}
		$att = self::get_attachment( $order );
		echo '<section class="wcctc-upload"><h3>' . esc_html__( 'Transfer receipt', 'wc-card-transfer-gateway' ) . '</h3>';
		if ( $att ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: file name */ __( 'Current receipt: %s', 'wc-card-transfer-gateway' ), $att['name'] ) ) . '</p>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wcctc_upload_receipt">';
		echo '<input type="hidden" name="order_id" value="' . absint( $order->get_id() ) . '">';
		echo '<input type="hidden" name="key" value="' . esc_attr( $order->get_order_key() ) . '">';
		wp_nonce_field( 'wcctc_upload_receipt_' . $order->get_id(), '_wpnonce', false );
		echo '<p><input type="file" name="wcctc_receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required> ';
		echo '<button type="submit" class="button">' . esc_html( $att ? __( 'Replace receipt', 'wc-card-transfer-gateway' ) : __( 'Upload receipt', 'wc-card-transfer-gateway' ) ) . '</button></p>';
		echo '<small>' . esc_html__( 'JPG, PNG, WebP or PDF; maximum 5 MB. Do not upload card credentials.', 'wc-card-transfer-gateway' ) . '</small>';
		echo '</form></section>';
	}

	public static function customer_upload() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wcctc_upload_receipt_' . $order_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wc-card-transfer-gateway' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! self::can_customer_upload( $order, $key ) ) {
			wp_die( esc_html__( 'Not authorized.', 'wc-card-transfer-gateway' ), '', array( 'response' => 403 ) );
		}
		$info = self::prepare_direct( $_FILES['wcctc_receipt'] ?? array() ); // phpcs:ignore WordPress.Security
		$res  = is_wp_error( $info ) ? $info : self::attach( $order, $info );
		$code = is_wp_error( $res ) ? $res->get_error_code() : 'ok';

		$back = $order->get_user_id() && is_user_logged_in() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
		wp_safe_redirect( add_query_arg( 'wcctc_notice', $code, remove_query_arg( 'wcctc_notice', $back ) ) );
		exit;
	}

	/* ---------------------------------------------------------- Admin download */

	public static function download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'wc-card-transfer-gateway' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'wcctc_download_receipt_' . $order_id );
		$order = wc_get_order( $order_id );
		$att   = $order ? self::get_attachment( $order ) : null;
		$path  = $att ? self::attachment_path( $att ) : '';
		if ( ! $path ) {
			wp_die( esc_html__( 'Receipt not found.', 'wc-card-transfer-gateway' ), '', array( 'response' => 404 ) );
		}
		Database::audit( 'receipt_downloaded', array(), $order_id, 'receipt', $order->get_meta( Helpers::META_RECEIPT_HASH, true ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $att['mime'] );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $att['name'] ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
