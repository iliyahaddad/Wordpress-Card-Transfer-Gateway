# Tests

* `php tests/unit.php` — dependency-free checks of pure logic (Luhn, digit normalisation, minor units, duplicate keys, Stripe signature verification incl. key rotation).
* `./bin-lint.sh` — `php -l` on every PHP file and `node --check` on every JS file (also run in CI on PHP 7.4/8.1/8.3).

## Manual checklist before a release

- [ ] Classic checkout: choose Iran gateway, attach receipt → status "Receipt attached", place order → order is *on-hold*.
- [ ] Block checkout: same flow, and with receipt required but no file → error shown, no order completed.
- [ ] Same reference / same receipt file on a second order is rejected without revealing the other order number.
- [ ] Guest uses the upload form on the thank-you page; logged-in user uses it from My Account → View order.
- [ ] Admin: Approve from the order screen (AJAX) → order becomes *processing*; a second approve is refused; reject after approve is refused.
- [ ] Stripe test mode: pay with 4242…, webhook marks order paid; replay of the same event is acknowledged as duplicate; a failed delivery is processed on retry.
- [ ] Partial refunds of equal amounts both reach Stripe.
- [ ] HPOS on and off.
