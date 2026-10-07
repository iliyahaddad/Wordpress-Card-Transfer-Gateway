# Architecture

```
wc-card-transfer-gateway.php   bootstrap; loads only classes that do NOT extend WooCommerce classes
src/Plugin.php                 boot order, cron (cleanup, OCR)
src/Gateway.php                registers gateways, thank-you/order-details/e-mail output, Stripe return URL
src/Gateways/Base.php          Base + Iran + International (extend WC_Payment_Gateway; loaded after WooCommerce)
src/Blocks.php, Blocks/        Checkout Block integration (MethodType loaded lazily)
src/Receipts.php               validation, private storage, temp pre-upload, customer upload, admin download
src/OCR.php                    OCR.space client + cross-check (hints only)
src/Providers.php              StripeProvider (sessions, refunds, signature verification)
src/REST.php                   /wcctc/v1/stripe/webhook and /wcctc/v1/receipt
src/Database.php               audit log + webhook ledger
src/Helpers.php                constants, settings, sanitising, duplicates, review workflow, privacy
src/Admin.php                  order meta box (AJAX), dashboard, audit log, CSV export
```

Flows
- **Manual**: checkout → (receipt pre-uploaded, token) → `process_payment` validates → order *on-hold* + `_wcctc_review_status=pending` → admin approves (`Helpers::review_order`) → `payment_complete`.
- **Stripe**: `process_payment` creates/reuses a Checkout Session → redirect → webhook verifies signature, amount, currency and order key → `payment_complete`.
- Every order through our gateways stores `_wcctc_provider` (`manual`/`stripe`); all logic keys off the order's provider, not the gateway's current mode.
