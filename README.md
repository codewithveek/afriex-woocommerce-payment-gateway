# Afriex Gateway for WooCommerce

A WordPress plugin that adds Afriex as a payment method in WooCommerce. Customers pay by bank transfer into an Afriex-generated account, and the order moves to a paid status automatically when Afriex confirms the deposit.

Merchant-facing documentation lives in [readme.txt](readme.txt), which is also what the WordPress.org plugin directory renders. This file is for people working on the plugin.

## Building

The block checkout bundle is **not** committed. Without it the gateway works on the classic shortcode checkout but is invisible on the Cart & Checkout blocks, so a release ZIP must be built before it ships.

```bash
npm install
npm run build      # writes assets/js/build/
npm run start      # watch mode
```

`webpack.config.js` swaps the default dependency-extraction plugin for WooCommerce's. That is what makes `@woocommerce/blocks-registry` and `@woocommerce/settings` resolve to the `wc.*` globals WooCommerce prints on the page, rather than being bundled — they are intentionally not installed as dependencies.

## Linting

```bash
npm run lint:js
composer install && composer lint    # PHPCS, WordPress coding standards
```

CI runs both, plus `php -l` across PHP 7.4 through 8.3, and fails if the block bundle does not build.

## Layout

| Path | Role |
|---|---|
| `afriex-gateway-for-woocommerce.php` | Header, bootstrap, HPOS + blocks compatibility declarations, cron wiring |
| `includes/class-afriex-gateway.php` | `WC_Payment_Gateway` subclass — classic checkout, settings, payment instructions |
| `includes/class-afriex-blocks-support.php` | `AbstractPaymentMethodType` subclass — block checkout registration |
| `includes/class-afriex-api-client.php` | Thin REST client over `wp_remote_*` |
| `includes/class-afriex-webhook-handler.php` | REST route, RSA-SHA256 verification, idempotency |
| `includes/class-afriex-reconciler.php` | Applies transaction state to orders; the hourly sweep |
| `includes/class-afriex-order-meta.php` | HPOS-safe order meta access |
| `includes/class-afriex-status-mapper.php` | Afriex status → plugin action |
| `includes/class-afriex-logger.php` | `WC_Logger` wrapper with redaction |

## Things worth knowing before changing this code

**There is no Afriex PHP SDK.** `@afriex/sdk` is Node/TypeScript; WooCommerce is PHP. Every call goes directly against the REST API through the WordPress HTTP API, and webhook signatures are verified with `openssl_verify()`. Bundling Guzzle is not an option — WordPress.org review scrutinises bundled dependencies. `Afriex_Api_Client` is deliberately the only class that knows about HTTP, so a future PHP SDK can replace it without touching anything else.

**Both checkouts, one processing path.** The JS module's `name` routes back to `Afriex_Gateway::process_payment()`. The blocks class reads the classic gateway's settings and availability rather than duplicating them, so the two checkouts cannot drift.

**Orders start at `on-hold`, and that has a trap.** `WC_Order::needs_payment()` returns true only for `pending` and `failed`. An on-hold order reports `false` from creation, so any expiry or cancellation logic gated behind it never runs — the failure mode that left Mollie's bank-transfer orders stranded indefinitely. Expiry here is decided from Afriex transaction status and the account's own expiry window, never inferred from `needs_payment()`, and `Afriex_Reconciler::sweep()` is the backstop for a webhook that never arrives.

**Order meta only through the CRUD API.** `$order->get_meta()` / `$order->update_meta_data()`, never `get_post_meta()`. `wc_get_orders()` for queries, never `WP_Query` against `shop_order`. `Afriex_Order_Meta` is the one place this has to hold.

**Verification is the permission check.** It runs in the route's `permission_callback`, so a forged request is rejected before any order lookup or write happens.

**Amount mismatches are never auto-completed.** An underpayment silently fulfilled is a loss; an overpayment silently kept is a dispute. Both get a note and a human.

## Open questions for the Afriex team

These are assumptions the code makes that should be confirmed against the live API. Each is isolated so confirming or correcting it touches one place.

1. **Staging base URL.** `Afriex_Api_Client` assumes `https://sandbox.api.afriex.com/api/v1`. The `woocommerce_afriex_api_base_url` filter exists so this can be corrected without patching the plugin.
2. **Deposit webhook payload shape.** Order lookup matches `data.destinationId` against the stored payment method id, falling back to `paymentMethodId`, then to a reference match for pool collection. If deposit payloads key this differently from disbursements, `Afriex_Webhook_Handler::find_order()` is the place to fix.
3. **Transaction list filtering.** The sweep calls `GET /transaction?destinationId=…` to find deposits for an order that never received a webhook. If that filter is not supported, the sweep needs a different query.
4. **Sandbox coverage.** Virtual accounts and pool accounts are documented as production-only. If they cannot be exercised in sandbox, pre-launch testing is materially limited.
5. **Pool account references.** Pool collection assumes the order number can be used as the reference and echoed back on the deposit. Confirm how Afriex expects a pool reference to be set and returned.
6. **Refunds.** Deferred until the Afriex refund path is confirmed as API-accessible. A `REFUNDED` status currently adds an order note rather than creating a WooCommerce refund record.

## Roadmap

- **Phase 1** (this release) — dedicated virtual account, classic + block checkout, HPOS, webhook confirmation, reconciliation sweep, amount-mismatch guard.
- **Phase 2** (this release) — pool account collection.
- **Phase 3** — order-received and email polish: copy-to-clipboard account number, QR code where the destination bank supports it.
- **Phase 4** — refunds.
