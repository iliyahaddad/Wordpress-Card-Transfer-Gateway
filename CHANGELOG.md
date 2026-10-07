# Changelog

## 2.1.1
### Fixed
- Protected the public receipt REST pre-upload with the WordPress REST nonce to prevent cross-site upload/CSRF abuse while retaining guest checkout support.


## 2.1.1
### Fixed
- Corrected CSV export column alignment (provider/total/currency were previously shifted).
- Protected the public receipt REST pre-upload with the WordPress REST nonce to prevent cross-site upload/CSRF abuse while retaining guest checkout support.


## 2.1.0
### Fixed (critical)
- Fatal error on load: gateway/blocks classes extended WooCommerce classes before WooCommerce was loaded (this plugin loads first alphabetically). They are now loaded after WooCommerce.
- Stripe webhooks were always rejected: `get_headers()['stripe-signature']` never matches (WordPress normalises to `stripe_signature`). Now uses `get_header()`; supports multiple `v1` signatures. Removed the broken legacy `wc-api` endpoint.
- Receipt upload at classic checkout never worked (AJAX checkout does not send files) and `receipt_required` blocked every order. Receipts are now pre-uploaded via REST with a token.
- Review buttons were inside WooCommerce's order `<form>` (nested forms): clicking Approve saved the order instead. Now AJAX.
- Failed webhook deliveries were recorded as "seen" and dropped on Stripe's retry; the ledger now only treats successfully processed events as done.
- `payment_intent.succeeded` could not find the order (metadata only on the Session); metadata is now copied to the PaymentIntent. Unrelated/foreign events are acknowledged instead of failing forever.
- Re-submitting after a failed attempt was blocked by the order's own reference (self-duplicate).
- Validation (`validate_fields`) is not called by the Block checkout — validation now also runs in `process_payment`.
- Block integration: undefined `reference_help` index, double registration of payment methods, editor crash (`eventRegistration` undefined).
- Approved orders could later be rejected/failed; orders of other payment methods could be reviewed; any order owner could upload to any order/status.
- Stripe: identical partial refunds were de-duplicated by Stripe; currency exponent list corrected; cancel URL no longer cancels the order; reused/expired Checkout sessions handled; refunds follow the *order's* provider, not the current gateway mode.
- `.htaccess` for Apache 2.4; DB upgrade routine never re-ran; uninstall deleted the wrong option.
- OCR: ran synchronously at checkout (30 s+ block), `wp_kses_post` on OCR text, missing PDF type.

### Added
- Guest receipt upload with order key, inline upload form on thank-you/order pages, payment instructions in the on-hold e-mail (HTML + plain text).
- OCR cross-check (reference / recipient card / amount), async via WP-Cron, configurable language.
- Customer-visible rejection notes, CSV export, paginated dashboard and audit log with filter, dispute/refund/expiry/async-payment Stripe events.
- Credentials via `wp-config.php` constants, card-number checksum validation, allowed-currency setting, Persian (fa_IR) translation, privacy policy text, improved GDPR eraser, cleanup cron.

## 2.0.0
Initial release.
