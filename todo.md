# NMI PCI Pro — pending work

Port of the Enterprise plugin (`woocommerce-gateway-nmi`) changes since October 2026, limited to what PCI Pro offers: card payments (Collect.js, or Direct Post without a tokenization key), refunds, capture, block checkout and Apple Pay / Google Pay through `wc-apple-pay-google-pay`. PCI Pro has **no** saved cards, subscriptions, pre-orders, eCheck, 3DS or upsells, and does **not** get Embedded Checkout (NMI Payment Component), so the Enterprise work on those is left out.

The same list, plus Embedded Checkout, is in NMI Pro's `todo.md`. The two plugins share most of their code, so port each item to both together.

Enterprise commit hashes are given so each item can be read against its source (`git -C ../woocommerce-gateway-nmi show <hash>`). Work test-first; every item names the tests that prove it.

## 0. Decide first: move to the v5 Payments API?

PCI Pro still posts to `transact.php` (form-encoded, username/password or security key). Enterprise moved to `https://{secure|sandbox}.nmi.com/api/v5/payments/` (JSON, security key only) between May and September. Without Embedded Checkout PCI Pro doesn't need v5, but staying on `transact.php` means the two plugins' request code diverges from Pro and Enterprise.

- [ ] **v5 API migration**, if chosen (Enterprise 63220a2, db51782, 13f9fd5, 42fe64f, 0230e4c, 6a8f756; card parts only)
  - `nmi_request( "METHOD:endpoint", $args )` with JSON bodies, the v5 response check (`id`, `response === 1`) and v5 error data.
  - Nested request args (`payment_details`, `order_details`, `billing_address`, `shipping_address`); v5 response field names (`id`, `auth_code`, `response_text`).
  - Capture, void and refund on v5 endpoints.
  - Read the card from `lookupData.card` when the token response has no `card` (42fe64f).
  - Settings: drop the `api_keys` toggle and Username/Password, with an upgrade notice for sites using them.
  - Tests: request URL, method and body shape for sale/auth/capture/refund; error and decline mapping.
- [ ] Once on v5: **omit an empty phone** from billing/shipping addresses (7611a73).

## 1. Testing setup (do first, per test-first rule)

- [ ] PHPUnit 9 + Brain Monkey in `tests/php` (bootstrap, `TestCase::gateway()`, WooCommerce stubs, `FakeOrder`), `composer test` / `npm run test:php`; Jest in `tests/js` with the Babel preset in `jest.config.js` (no `babel.config.js`), `npm test`. Copy from Enterprise 01d3668 / b59b82d, trimmed to the card gateway. Remove the placeholder `tests/Test.php`.
- [ ] Jest test that every translation call in `src/` uses a literal `'wc-nmi'` (Enterprise `tests/js/i18n-domain.test.js`).

## 2. Card gateway fixes

- [ ] **Reject disallowed card types on the server** for Collect.js tokens (`validate_js_card_type()`), not only Direct Post; reset the browser-side card type check for each new token (933b1f8).
- [ ] **Wallet payment takes priority over the card token** (1d76017): `get_nmi_js_response()` returns nothing when Apple Pay / Google Pay data is posted, so a token left by the card fields isn't charged instead. Also unslash and clean it (`wc_clean( wp_unslash() )` instead of `stripslashes()`). Test: wallet priority case in `CardGatewayRequestTest`.
- [ ] **Wallet card details from the response** (64c4d36): `get_posted_wallet_payment_details()` and `get_card_details_from_response()`. PCI Pro saves no `_nmi_card_last4` / `_nmi_card_type` order meta at all yet (Pro does); add it for card and wallet orders. Guard against wallet responses without wallet details (933b1f8).
- [ ] **Response and failure handling** (9787226, 8c86f81): treat a response without a transaction ID / approved code as an error; `add_failure_order_note()` helper; `parse_card_expiry()` helper; `wp_unslash()` on `$_GET['key']` and guard a missing order in the order-pay total.
- [ ] **Admin notices shown twice** (cd8b501): show the notice once from the registered gateway instance; `check_environment()` reads saved settings; `capture_payment()` / `cancel_payment()` use the registered gateway.
- [ ] **Direct Post warning** (933b1f8): admin notice when no Public Tokenization Key is set. PCI Pro falls back to the Direct Post card form (`form()`) without one, which undermines the plugin's "PCI" promise, so the warning matters more here. Consider requiring the key instead.
- [ ] `is_configured()` (keys set), used by `is_available()` and the wallet integration (03c390d).

## 3. Apple Pay / Google Pay processor contract

- [ ] **Integrate with the extension's processor contract** (03c390d): `WC_NMI_Wallet_Integration` through the `wcapgp_processor_integration` filter, loaded only when the extension's interface exists; `is_ready()` via `is_configured()`; `get_google_pay_params()`; `process_wallet_payment()`; `supports_subscriptions()` false. Use `WCAPGP_Payment_Gateway::is_wallet_enabled_for()` when available. Follow `wc-apple-pay-google-pay/docs/INTEGRATION.md`; update its `docs/ROADMAP.md` after the PR. Test: Enterprise `WalletIntegrationTest` with the `wcapgp` stubs.
- Not ported: Apple Pay / Google Pay inside Embedded Checkout (833fc50, 7dcf258).

## 4. Classic checkout script (`assets/js/nmi.js`)

From 933b1f8, eed0b82, cd8b501 and b59b82d, without the 3DS, eCheck, saved-card and Embedded Checkout parts:

- [ ] One `mountFields()` for checkout, pay page and Add payment method, with a change listener and a short-lived MutationObserver safety net.
- [ ] Don't leave the payment section blocked when Collect.js doesn't load or doesn't mount the fields; show an error instead. Better JS error handling (eed0b82).
- [ ] Single `nmi_js_response` hidden field built with `$('<input>').attr()`.
- [ ] Console output only when Gateway Debug is on (`debug` param).
- [ ] Don't enqueue the classic script on block checkout pages or the cart page (it currently loads on `is_cart()`).
- [ ] Escape the tokenization key in `add_public_key_to_js()` (`esc_attr()`, as Pro and Enterprise do).

## 5. Block checkout (`src/`)

- [ ] **Block checkout improvements** (78cc024, card parts): `previews.js`, refactored `payment-processing.js`, shared helpers in `utils.js`; blocks support class changes.
- [ ] Collect.js token request timeout (b59b82d): hand Collect.js's timeout to `handleTimeout()`, with our deferred as a 5s-longer backstop. Test: Jest `utils.test.js` timeout hand-off.
- [ ] No stray "0" when no card icons are enabled (933b1f8).
- [ ] Keep Collect.js iframes inside the field border (cd8b501).
- [ ] Widen the expiry field when the card fields share a row (584db27).
- [ ] Literal `'wc-nmi'` text domain in `src/` instead of `TEXT_DOMAIN` (59e7b39), then regenerate `languages/wc-nmi.pot` with `studio wp i18n make-pot`.

## 6. Repo housekeeping

- [ ] `cubic.yaml` ignoring `node_modules/` and `vendor/`, export-ignored (cc95cda).
- [ ] No `updates/updates.php` here, so the license page hardening doesn't apply.
- [ ] Before release: check minimum WP / WC / PHP requirements against the new code (readme says PHP 5.6; the ported code uses `??`, so PHP 7.0+ at least) and update headers, readme and changelog.
