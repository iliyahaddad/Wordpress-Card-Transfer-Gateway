# WC Card Transfer Gateway

Two WooCommerce payment gateways:

1. **Iran card-to-card** — customer pays by transfer, submits the bank reference and (optionally) a receipt; a store manager verifies and approves.
2. **International** — either the same manual flow (bank/IBAN/SWIFT) or **Stripe Checkout** with signed webhooks and refunds.

Requires WordPress 6.4+, WooCommerce 8.9+, PHP 7.4+. Compatible with HPOS and the Cart/Checkout **blocks** as well as the classic shortcode checkout.

## Install & configure

1. Upload the `wcctc` folder to `wp-content/plugins/` (or the zip via *Plugins → Add New*) and activate.
2. *WooCommerce → Settings → Payments* → configure **Iran card-to-card** and/or **International**.
3. Review payments under *WooCommerce → Transfer payments* or on each order's *Transfer payment review* box.

### Stripe
* Set *Mode = Stripe Checkout* and enter the secret key (or define `WCCTC_STRIPE_SECRET_KEY` in `wp-config.php`).
* Create a webhook endpoint pointing at `https://YOUR-SITE/wp-json/wcctc/v1/stripe/webhook` with the events listed in the gateway settings and put the signing secret in the settings (or `WCCTC_STRIPE_WEBHOOK_SECRET`).
* The webhook is the only thing that marks an order paid; the customer's return URL never does.
* Note: security plugins that block unauthenticated REST requests must allow `/wcctc/v1/*`.

### Receipts & OCR
* Receipts (JPG/PNG/WebP/PDF ≤ 5 MB, content-sniffed) are stored in `uploads/wcctc-private/` behind deny rules (Apache 2.2/2.4, IIS). On **nginx** add `location ~ ^/wp-content/uploads/wcctc-private/ { deny all; }`.
* Uploads happen via a REST pre-upload (nonce-protected and rate limited) so they work in both checkouts; customers (including guests with the order key) can also upload later from the thank-you / order page.
* Optional OCR via OCR.space runs in the background (WP-Cron) and only shows hints (reference, recipient card, amount found in the receipt). It never approves anything. Your OCR provider receives the receipt image.

### Constants (wp-config.php)
`WCCTC_STRIPE_SECRET_KEY`, `WCCTC_STRIPE_WEBHOOK_SECRET`, `WCCTC_OCR_API_KEY`, `WCCTC_REMOVE_DATA` (drop tables and receipt files on uninstall), filter `wcctc_max_receipt_bytes`.

## Security notes
Webhook signature check (multi-secret, 5-min tolerance) · idempotent event ledger that retries failed events · amount/currency/order-key verification · private receipt storage · duplicate reference & receipt-hash detection (without leaking other order numbers) · capability + nonce checks · CSV formula-injection protection · GDPR exporter/eraser (erases files, OCR text, IPs).

## فارسی (خلاصه)
افزونه دو درگاه دارد: «کارت‌به‌کارت ایران» با ثبت شماره پیگیری و رسید و تأیید دستی مدیر، و «بین‌المللی» (انتقال دستی یا Stripe Checkout). رسید از طریق REST و پیش از ثبت سفارش بارگذاری می‌شود و در چکاوت کلاسیک و بلوکی کار می‌کند؛ مشتری می‌تواند بعداً هم از صفحه سفارش رسید بفرستد. تأیید/رد پرداخت در باکس «بررسی پرداخت انتقالی» صفحه سفارش یا منوی ووکامرس → «پرداخت‌های انتقالی» انجام می‌شود. ترجمه فارسی داخل پوشه `languages` است.
