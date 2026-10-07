<?php
namespace WCCTC;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const PAGE = 'wcctc-transfers';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_wcctc_review', array( __CLASS__, 'review_post' ) );
		add_action( 'admin_post_wcctc_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'wp_ajax_wcctc_review', array( __CLASS__, 'review_ajax' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCCTC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		$settings = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' );
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Transfers', 'wc-card-transfer-gateway' ) . '</a>',
			'<a href="' . esc_url( $settings . Helpers::IRAN_ID ) . '">' . esc_html__( 'Iran gateway', 'wc-card-transfer-gateway' ) . '</a>',
			'<a href="' . esc_url( $settings . Helpers::INTERNATIONAL_ID ) . '">' . esc_html__( 'International gateway', 'wc-card-transfer-gateway' ) . '</a>'
		);
		return $links;
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Transfer payments', 'wc-card-transfer-gateway' ), __( 'Transfer payments', 'wc-card-transfer-gateway' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function order_screen_ids() {
		$ids = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		return $ids;
	}

	public static function assets( $hook ) {
		$screen   = get_current_screen();
		$is_order = $screen && in_array( $screen->id, self::order_screen_ids(), true );
		if ( false === strpos( (string) $hook, self::PAGE ) && ! $is_order ) {
			return;
		}
		wp_enqueue_style( 'wcctc-admin', WCCTC_URL . 'assets/css/admin.css', array(), WCCTC_VERSION );
		if ( $is_order ) {
			wp_enqueue_script( 'wcctc-admin', WCCTC_URL . 'assets/js/admin.js', array(), WCCTC_VERSION, true );
			wp_localize_script( 'wcctc-admin', 'wcctcAdmin', array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'confirmReject' => __( 'Reject this transfer? The order will be marked as failed.', 'wc-card-transfer-gateway' ),
				'failed'        => __( 'Request failed. Please try again.', 'wc-card-transfer-gateway' ),
			) );
		}
	}

	/* ------------------------------------------------------------ Meta box */

	public static function meta_box() {
		foreach ( self::order_screen_ids() as $screen ) {
			add_meta_box( 'wcctc-review', __( 'Transfer payment review', 'wc-card-transfer-gateway' ), array( __CLASS__, 'box' ), $screen, 'normal', 'high' );
		}
	}

	private static function status_label( $status ) {
		$labels = array(
			'pending'  => __( 'Pending', 'wc-card-transfer-gateway' ),
			'approved' => __( 'Approved', 'wc-card-transfer-gateway' ),
			'rejected' => __( 'Rejected', 'wc-card-transfer-gateway' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! Helpers::is_ours( $order->get_payment_method() ) ) {
			echo '<p>' . esc_html__( 'This order was not paid with a transfer gateway.', 'wc-card-transfer-gateway' ) . '</p>';
			return;
		}
		if ( Helpers::is_stripe_order( $order ) ) {
			echo '<p><strong>' . esc_html__( 'Stripe', 'wc-card-transfer-gateway' ) . '</strong> — ' . esc_html( (string) $order->get_meta( Helpers::META_PROVIDER_STATUS, true ) ) . '</p>';
			echo '<p><code>' . esc_html( (string) $order->get_meta( Helpers::META_PROVIDER_PAYMENT_ID, true ) ) . '</code></p>';
			return;
		}
		$status  = $order->get_meta( Helpers::META_REVIEW_STATUS, true ) ?: 'pending';
		$receipt = Receipts::get_attachment( $order );
		$id      = $order->get_id();

		echo '<p>' . esc_html__( 'Status:', 'wc-card-transfer-gateway' ) . ' <span class="wcctc-badge ' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span></p>';
		echo '<p><strong>' . esc_html__( 'Reference:', 'wc-card-transfer-gateway' ) . '</strong> <bdi>' . esc_html( (string) $order->get_meta( Helpers::META_REFERENCE, true ) ) . '</bdi>';
		$sender = $order->get_meta( Helpers::META_SENDER_NAME, true );
		$last4  = $order->get_meta( Helpers::META_SENDER_LAST4, true );
		if ( $sender ) {
			echo ' | <strong>' . esc_html__( 'Sender:', 'wc-card-transfer-gateway' ) . '</strong> ' . esc_html( $sender );
		}
		if ( $last4 ) {
			echo ' | <strong>' . esc_html__( 'Card last 4:', 'wc-card-transfer-gateway' ) . '</strong> ' . esc_html( $last4 );
		}
		echo '</p>';
		echo '<p><strong>' . esc_html__( 'Expected amount:', 'wc-card-transfer-gateway' ) . '</strong> ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';

		$dup = (int) $order->get_meta( Helpers::META_DUPLICATE_FLAG, true );
		if ( $dup ) {
			echo '<div class="wcctc-alert is-danger">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'The same receipt file was submitted for another order: %s', 'wc-card-transfer-gateway' ), self::order_link( $dup ) ) ) . '</div>';
		}
		$ref_dup = Helpers::find_duplicate_reference( (string) $order->get_meta( Helpers::META_REFERENCE, true ), $id );
		if ( $ref_dup ) {
			echo '<div class="wcctc-alert is-danger">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'The same reference is used by another order: %s', 'wc-card-transfer-gateway' ), self::order_link( $ref_dup ) ) ) . '</div>';
		}

		if ( $receipt ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=wcctc_download_receipt&order_id=' . $id ), 'wcctc_download_receipt_' . $id );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Download receipt', 'wc-card-transfer-gateway' ) . '</a> <small>' . esc_html( $receipt['name'] ) . '</small></p>';
		} else {
			echo '<div class="wcctc-alert">' . esc_html__( 'No receipt has been uploaded yet.', 'wc-card-transfer-gateway' ) . '</div>';
		}
		self::render_ocr( $order );

		if ( 'approved' === $status || $order->is_paid() ) {
			echo '<p><em>' . esc_html__( 'This order is approved/paid. Use a refund for any reversal.', 'wc-card-transfer-gateway' ) . '</em></p>';
			return;
		}
		echo '<div class="wcctc-review" data-order="' . esc_attr( $id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wcctc_review_' . $id ) ) . '">';
		echo '<textarea rows="3" placeholder="' . esc_attr__( 'Internal note (optional)', 'wc-card-transfer-gateway' ) . '"></textarea>';
		echo '<label><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'Send the note to the customer', 'wc-card-transfer-gateway' ) . '</label><br><br>';
		echo '<button type="button" class="button button-primary" data-decision="approved">' . esc_html__( 'Approve payment', 'wc-card-transfer-gateway' ) . '</button> ';
		echo '<button type="button" class="button" data-decision="rejected">' . esc_html__( 'Reject', 'wc-card-transfer-gateway' ) . '</button> ';
		echo '<button type="button" class="button" data-decision="pending">' . esc_html__( 'Keep pending', 'wc-card-transfer-gateway' ) . '</button>';
		echo '<span class="wcctc-review-msg" role="alert"></span></div>';
	}

	private static function render_ocr( $order ) {
		$ocr = $order->get_meta( Helpers::META_OCR_STATUS, true );
		if ( ! $ocr || 'disabled' === $ocr ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'OCR:', 'wc-card-transfer-gateway' ) . '</strong> ' . esc_html( $ocr ) . ' <small>' . esc_html__( '(a hint only; verify the funds in your bank)', 'wc-card-transfer-gateway' ) . '</small></p>';
		$signals = json_decode( (string) $order->get_meta( Helpers::META_OCR_SIGNALS, true ), true );
		if ( is_array( $signals ) && isset( $signals['checks'] ) ) {
			$c = $signals['checks'];
			echo '<p class="wcctc-checks"><span>' . ( $c['reference'] ? '✔' : '✖' ) . ' ' . esc_html__( 'Reference', 'wc-card-transfer-gateway' ) . '</span><span>' . ( $c['recipient_card'] ? '✔' : '✖' ) . ' ' . esc_html__( 'Recipient card', 'wc-card-transfer-gateway' ) . '</span><span>' . ( $c['amount'] ? '✔' : '✖' ) . ' ' . esc_html__( 'Amount', 'wc-card-transfer-gateway' ) . '</span></p>';
		}
		$text = (string) $order->get_meta( Helpers::META_OCR_TEXT, true );
		if ( $text ) {
			echo '<details><summary>' . esc_html__( 'Recognized text', 'wc-card-transfer-gateway' ) . '</summary><div class="wcctc-ocr-text">' . esc_html( $text ) . '</div></details>';
		}
	}

	private static function order_link( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . absint( $order_id );
	}

	/* -------------------------------------------------------------- Actions */

	private static function do_review( $order_id, $decision, $note, $notify ) {
		$order = wc_get_order( $order_id );
		return Helpers::review_order( $order, $decision, $note, $notify );
	}

	public static function review_ajax() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_ajax_referer( 'wcctc_review_' . $order_id, '_wpnonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not authorized.', 'wc-card-transfer-gateway' ) ), 403 );
		}
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '';
		$notify   = ! empty( $_POST['notify'] ) && '0' !== $_POST['notify'];
		$result   = self::do_review( $order_id, $decision, $note, $notify );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Saved.', 'wc-card-transfer-gateway' ) ) );
	}

	public static function review_post() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( 'wcctc_review_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'wc-card-transfer-gateway' ), '', array( 'response' => 403 ) );
		}
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$result   = self::do_review( $order_id, $decision, '', false );
		$back     = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE );
		$back     = add_query_arg( 'wcctc_msg', is_wp_error( $result ) ? 'error' : 'ok', remove_query_arg( 'wcctc_msg', $back ) );
		wp_safe_redirect( $back );
		exit;
	}

	private static function csv_cell( $value ) {
		$value = (string) $value;
		// Prevent spreadsheet formula injection from customer-supplied values.
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'wc-card-transfer-gateway' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wcctc_export' );
		$orders = wc_get_orders( array( 'limit' => 5000, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => Helpers::META_PROVIDER, 'compare' => 'EXISTS' ) ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wcctc-transfers-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'order', 'date', 'gateway', 'provider', 'total', 'currency', 'wc_status', 'review_status', 'reference', 'sender', 'receipt', 'ocr' ) );
		foreach ( $orders as $o ) {
			$att = Receipts::get_attachment( $o );
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array(
				$o->get_order_number(),
				$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
				$o->get_payment_method(),
				$o->get_meta( Helpers::META_PROVIDER, true ),
				$o->get_total(),
				$o->get_currency(),
				$o->get_status(),
				$o->get_meta( Helpers::META_REVIEW_STATUS, true ),
				$o->get_meta( Helpers::META_REFERENCE, true ),
				$o->get_meta( Helpers::META_SENDER_NAME, true ),
				$att ? $att['name'] : '',
				$o->get_meta( Helpers::META_OCR_STATUS, true ),
			) ) );
		}
		fclose( $out );
		Database::audit( 'export_csv', array( 'rows' => count( $orders ) ) );
		exit;
	}

	/* ------------------------------------------------------------ Dashboard */

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs = array(
			'pending'  => __( 'Pending', 'wc-card-transfer-gateway' ),
			'approved' => __( 'Approved', 'wc-card-transfer-gateway' ),
			'rejected' => __( 'Rejected', 'wc-card-transfer-gateway' ),
			'all'      => __( 'All', 'wc-card-transfer-gateway' ),
			'audit'    => __( 'Audit log', 'wc-card-transfer-gateway' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'pending';
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap"><h1>' . esc_html__( 'Transfer payments', 'wc-card-transfer-gateway' ) . '</h1>';
		$msg = isset( $_GET['wcctc_msg'] ) ? sanitize_key( wp_unslash( $_GET['wcctc_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'ok' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'wc-card-transfer-gateway' ) . '</p></div>';
		} elseif ( 'error' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The review could not be saved.', 'wc-card-transfer-gateway' ) . '</p></div>';
		}
		$failures = Database::webhook_failures( 5 );
		if ( $failures ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d Stripe webhook event failed to process. Stripe will retry it; see the audit log.', '%d Stripe webhook events failed to process. Stripe will retry them; see the audit log.', count( $failures ), 'wc-card-transfer-gateway' ), count( $failures ) ) ) . '</p></div>';
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'audit' === $tab ) {
			self::render_audit( $paged );
		} else {
			self::render_orders( $tab, $paged );
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=wcctc_export' ), 'wcctc_export' );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'wc-card-transfer-gateway' ) . '</a></p>';
		}
		echo '</div>';
	}

	private static function render_orders( $tab, $paged ) {
		$args = array(
			'limit'          => 20,
			'paged'          => $paged,
			'paginate'       => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
			// Every order that went through one of our gateways has this marker (works with HPOS and legacy storage).
			'meta_query'     => array( array( 'key' => Helpers::META_PROVIDER, 'compare' => 'EXISTS' ) ),
		);
		if ( 'all' !== $tab ) {
			$args['meta_query'][] = array( 'key' => Helpers::META_REVIEW_STATUS, 'value' => $tab );
		}
		$result = wc_get_orders( $args );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Order', 'wc-card-transfer-gateway' ), __( 'Date', 'wc-card-transfer-gateway' ), __( 'Total', 'wc-card-transfer-gateway' ), __( 'Reference', 'wc-card-transfer-gateway' ), __( 'Review', 'wc-card-transfer-gateway' ), __( 'Receipt', 'wc-card-transfer-gateway' ), __( 'Actions', 'wc-card-transfer-gateway' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $result->orders ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No orders found.', 'wc-card-transfer-gateway' ) . '</td></tr>';
		}
		foreach ( $result->orders as $order ) {
			$id     = $order->get_id();
			$status = Helpers::is_stripe_order( $order ) ? 'stripe' : ( $order->get_meta( Helpers::META_REVIEW_STATUS, true ) ?: 'pending' );
			echo '<tr><td>' . wp_kses_post( self::order_link( $id ) ) . ' ' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ) . '</td>';
			echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '<td><bdi>' . esc_html( (string) $order->get_meta( Helpers::META_REFERENCE, true ) ) . '</bdi></td>';
			echo '<td><span class="wcctc-badge ' . esc_attr( $status ) . '">' . esc_html( 'stripe' === $status ? 'Stripe' : self::status_label( $status ) ) . '</span></td>';
			echo '<td>' . ( Receipts::get_attachment( $order ) ? '✔' : '—' ) . ( $order->get_meta( Helpers::META_DUPLICATE_FLAG, true ) ? ' <span class="wcctc-badge rejected">' . esc_html__( 'duplicate', 'wc-card-transfer-gateway' ) . '</span>' : '' ) . '</td><td>';
			echo '<a class="button" href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Review', 'wc-card-transfer-gateway' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $paged, (int) $result->max_num_pages, 'tab=' . $tab );
	}

	private static function pagination( $paged, $max, $query ) {
		if ( $max <= 1 ) {
			return;
		}
		echo '<p class="tablenav-pages">';
		echo wp_kses_post( paginate_links( array(
			'base'      => admin_url( 'admin.php?page=' . self::PAGE . '&' . $query . '&paged=%#%' ),
			'format'    => '',
			'current'   => $paged,
			'total'     => $max,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		) ) );
		echo '</p>';
	}

	private static function render_audit( $paged ) {
		$filter = isset( $_GET['audit_action'] ) ? sanitize_key( wp_unslash( $_GET['audit_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;
		$total  = Database::audit_count( $filter );
		$rows   = Database::audit_rows( $per, ( $paged - 1 ) * $per, $filter );

		echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="audit">';
		echo '<select name="audit_action"><option value="">' . esc_html__( 'All actions', 'wc-card-transfer-gateway' ) . '</option>';
		foreach ( Database::audit_actions() as $a ) {
			echo '<option value="' . esc_attr( $a ) . '"' . selected( $filter, $a, false ) . '>' . esc_html( $a ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'wc-card-transfer-gateway' ) . '</button></form>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date (UTC)', 'wc-card-transfer-gateway' ) . '</th><th>' . esc_html__( 'Order', 'wc-card-transfer-gateway' ) . '</th><th>' . esc_html__( 'User', 'wc-card-transfer-gateway' ) . '</th><th>' . esc_html__( 'Action', 'wc-card-transfer-gateway' ) . '</th><th>' . esc_html__( 'Details', 'wc-card-transfer-gateway' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->created_at ) . '</td><td>' . ( $row->order_id ? wp_kses_post( self::order_link( $row->order_id ) ) : '—' ) . '</td><td>' . esc_html( $row->actor_id ? (string) $row->actor_id : '—' ) . '</td><td>' . esc_html( $row->action ) . '</td><td><code>' . esc_html( wp_trim_words( (string) $row->details, 30, '…' ) ) . '</code></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No entries.', 'wc-card-transfer-gateway' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $paged, (int) ceil( $total / $per ), 'tab=audit&audit_action=' . rawurlencode( $filter ) );
	}
}
