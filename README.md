# Dodo Payments for WHMCS 8.x / 9.x

Version **1.0.0** — generic third-party gateway module using Dodo Payments Checkout Sessions and signed webhooks.

## Architecture

This is an **invoice-driven** gateway. WHMCS remains the system that owns products, billing cycles, renewals, invoice totals, coupons, taxes, and service provisioning. Dodo Payments is used to collect the **exact outstanding WHMCS invoice amount**.

For each WHMCS invoice currency, create **one reusable Dodo Single Payment product** with **Pay What You Want** enabled. You do **not** create a Dodo product for every WHMCS product/service.

This means a WHMCS installation with 100 products but 4 currencies needs 4 Dodo PWYW products, not 100.

### Recurring WHMCS services

This module does **not** create Dodo subscriptions and does not perform automatic card-on-file rebilling. For a WHMCS product with recurring billing, WHMCS creates the renewal invoice on schedule and the customer pays that new invoice through Dodo.

If true automatic recurring charging is required, that is a separate tokenized/subscription integration and is outside this module's design.

## Upload

Upload the contents of `modules/` into the matching WHMCS `modules/` directory:

- `modules/gateways/dodopay.php`
- `modules/gateways/dodopay/lib.php`
- `modules/gateways/callback/dodopay.php`

No Composer package or vendor directory is required.

## Required Dodo product settings

Create one product per currency in **Test mode** and again in **Live mode**. Each mapped product must have:

- Pricing Type: **Single Payment**
- Currency: exactly the same 3-letter currency as the WHMCS invoice
- **Pay What You Want: ON**
- Minimum price: at or below the smallest invoice you intend to collect in that currency
- Maximum price: optional; if set, it must be high enough for your largest invoice
- **Tax Inclusive Pricing: ON**
- **Purchasing Power Parity / adaptive pricing: OFF**
- Do not depend on Dodo discount codes to alter a WHMCS invoice payment
- Choose the **correct Dodo tax category** for the offering (`digital_products`, `saas`, `e_book`, or `edtech`). The module intentionally does not guess or hardcode a tax category. If one WHMCS installation mixes sales that Dodo would classify into different tax categories, confirm the appropriate architecture with Dodo/tax counsel before production; one proxy product per currency assumes the chosen category correctly represents the invoice being collected.

The gateway sends the exact WHMCS invoice amount in the Dodo `product_cart.amount` field. It also disables currency selection, discount-code entry, and addon editing for the checkout session.

## Dodo Dashboard setup

### 1. Test mode first

Enable Dodo **Test Mode** in the dashboard.

### 2. Create PWYW products

Under **Products**, create one product per WHMCS currency using the settings above. Note each product ID (`pdt_...`).

Example map format:

```text
USD=pdt_example_usd
EUR=pdt_example_eur
GBP=pdt_example_gbp
```

### 3. Create a write-enabled API key

Go to **Developer > API Keys > Add API Key**. The module must create Checkout Sessions and refunds, so the API key needs **write access**.

Copy the Test API key into the module's **Test API Key** field.

### 4. Create the webhook

Go to **Developer > Webhooks > Add Webhook**.

Endpoint URL:

```text
https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/dodopay.php
```

Subscribe to:

```text
payment.succeeded
```

Copy that endpoint's **Secret Key** into the module's **Test Webhook Secret** field.

### 5. Configure WHMCS

In WHMCS admin, activate **Dodo Payments** under Payment Gateways and configure:

- Environment: `Test`
- WHMCS Instance ID: a unique 8-64 character identifier for this installation, e.g. `whmcs_a8f14e45fceea167`
- Test API Key
- Test Webhook Secret
- Test Product Map

The **WHMCS Instance ID must be different on every WHMCS website**, especially when several sites share one Dodo business/account. It is not a secret; it is a routing identifier carried in signed payment metadata.

### 6. Test end-to-end

Create/pay a real WHMCS test invoice and confirm all of the following:

1. The Dodo checkout amount and currency exactly match the WHMCS invoice.
2. The checkout succeeds in Dodo Test Mode.
3. Dodo delivers `payment.succeeded` to the callback URL with HTTP 200.
4. The WHMCS invoice becomes Paid.
5. The WHMCS transaction ID is the Dodo `payment_id`.
6. A second/retried webhook does not create a duplicate payment.
7. Test a partial refund and a full refund from WHMCS if you intend to use refunds.

### 7. Repeat in Live mode

Switch the Dodo dashboard to **Live Mode** and repeat the setup with separate live products, API key, and webhook endpoint secret. Put those values into the module's Live fields, then change **Environment** to `Live` only after test checkout/webhook validation is complete.

## Rules

- Syntax: one `CURRENCY=PRODUCT_ID` per line.
- Currency must be an ISO-style 3-letter code.
- Blank lines are ignored.
- Lines beginning with `#` or `;` are ignored.
- The module intentionally has no website/company/product-catalog hardcoding and no four-currency allow-list.
- The Dodo product itself is validated before checkout: one-time, PWYW, tax-inclusive, PPP/adaptive-pricing off, matching currency, and minimum price not above the invoice amount.

## Security and payment integrity

- Checkout metadata includes the WHMCS gateway name, installation ID, invoice ID, currency, and exact minor-unit amount.
- Webhook signatures are validated using the Standard Webhooks HMAC-SHA256 format and a 5-minute timestamp tolerance.
- A signed webhook for another WHMCS installation is acknowledged and ignored.
- `payment.succeeded` must match the original checkout currency and exact minor-unit amount before WHMCS is credited.
- Dodo `payment_id` is used as the WHMCS transaction ID, so WHMCS rejects duplicate callbacks.
- API keys and webhook secrets are never placed in browser HTML.
- cURL SSL peer and hostname verification stay enabled.

## Refund

The module supports full and partial refunds through WHMCS:

- It retrieves `/payments/{payment_id}/line-items` first.
- A partial refund sends Dodo's original `items_id` as `item_id`, amount in minor units, and `tax_inclusive: true`.
- A full refund omits `items` and refunds the remaining refundable balance.
- Dodo refund statuses other than `succeeded` are returned to WHMCS as an error with a warning **not to retry until the Dodo refund status has been checked**, preventing accidental duplicate refund attempts.

## Upgrade

Version 1.0.0 keeps the existing Test/Live API key, webhook-secret, product-map and button-text setting names. It adds one required field: **WHMCS Instance ID**.

Notable changes:

- Removed the old USD/INR/GBP/EUR-only dynamic-currency restriction.
- Added safe multi-WHMCS webhook routing via Instance ID.
- Generic currency mapping now drives support.
- Valid customer object is sent only when WHMCS supplies a valid email.
- Existing-customer duplication is reduced (`always_create_new_customer=false`).
- Saved payment methods are not exposed by this invoice checkout.
- Improved API error handling and logging.
- Updated refund behavior against the current Dodo line-item/refund API shape.

## Note

Dodo is the Merchant of Record for Dodo transactions and can generate its own tax/receipt documents. WHMCS also maintains its own invoice. Configure your accounting/tax workflow so customers and bookkeeping staff understand which document is the commercial/tax record in your jurisdiction.
