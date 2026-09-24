# Dodo Payments for WHMCS 8.x / 9.x

This package is a generic third-party WHMCS gateway module that uses Dodo Payments Checkout Sessions and signed webhooks.

## Architecture

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#architecture)

This is an **invoice-driven** gateway. WHMCS remains responsible for products, billing cycles, renewals, invoice totals, coupons, taxes, and service provisioning. Dodo Payments is used only to collect the **exact outstanding WHMCS invoice amount**.

For each WHMCS invoice currency, create **one reusable Dodo Single Payment product** with **Pay What You Want** enabled.

You do **not** need to create a separate Dodo product for every WHMCS product or service.

For example, a WHMCS installation with 100 products and 4 supported currencies requires only 4 Dodo PWYW products.

### Recurring WHMCS services

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#recurring-whmcs-services)

This module does **not** create Dodo subscriptions and does not perform automatic card-on-file rebilling.

For recurring WHMCS products or services, WHMCS generates the renewal invoice according to the configured billing cycle. The customer then pays that invoice through Dodo Payments in the same way as any other WHMCS invoice.

If true automatic recurring charging is required, that requires a separate tokenized/subscription integration and is outside the design of this module.

## Installation

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#installation)

Upload the contents of the included `modules/` directory into the matching WHMCS `modules/` directory while preserving the paths:

* `modules/gateways/dodopay.php`
* `modules/gateways/dodopay/lib.php`
* `modules/gateways/callback/dodopay.php`

No Composer package or vendor directory is required.

## Required Dodo product settings

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#required-dodo-product-settings)

Create one Dodo product per WHMCS invoice currency in **Test Mode** and repeat the same setup separately in **Live Mode**.

Each mapped product must use the following settings:

* Pricing Type: **Single Payment**
* Currency: exactly the same 3-letter currency code as the WHMCS invoice
* **Pay What You Want: ON**
* Minimum price: at or below the smallest invoice you intend to collect in that currency
* Maximum price: optional; if configured, it must be high enough for the largest invoice you intend to collect
* **Tax Inclusive Pricing: ON**
* **Purchasing Power Parity / adaptive pricing: OFF**
* Do not depend on Dodo discount codes to modify the WHMCS invoice amount
* Select the **correct Dodo tax category** for the offering, such as `digital_products`, `saas`, `e_book`, or `edtech`

The module intentionally does not guess or hardcode a Dodo tax category.

If one WHMCS installation contains products that Dodo would classify under different tax categories, confirm the appropriate production architecture with Dodo and/or your tax adviser. The one-proxy-product-per-currency design assumes that the selected Dodo tax category correctly represents the invoice being collected.

The module sends the exact WHMCS invoice amount through Dodo's `product_cart.amount` field.

It also disables currency selection, discount-code entry, and addon editing for the Checkout Session.

## Dodo Dashboard setup

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#dodo-dashboard-setup)

### 1. Enable Test Mode

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#1-enable-test-mode)

Enable **Test Mode** in the Dodo Payments dashboard before configuring the live environment.

### 2. Create PWYW products

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#2-create-pwyw-products)

Under **Products**, create one product for each WHMCS invoice currency using the settings described above.

Note each Dodo product ID in the `pdt_...` format.

Example product map:

```text
USD=pdt_example_usd
EUR=pdt_example_eur
GBP=pdt_example_gbp
```

### 3. Create a write-enabled API key

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#3-create-a-write-enabled-api-key)

Go to:

**Developer > API Keys > Add API Key**

The module creates Checkout Sessions and can process refunds, so the API key requires **write access**.

Copy the Test API key into the module's **Test API Key** field.

### 4. Create the webhook

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#4-create-the-webhook)

Go to:

**Developer > Webhooks > Add Webhook**

Use the following endpoint URL:

```text
https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/dodopay.php
```

Subscribe to:

```text
payment.succeeded
```

Copy the webhook endpoint's **Secret Key** into the module's **Test Webhook Secret** field.

### 5. Configure WHMCS

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#5-configure-whmcs)

In WHMCS admin, activate **Dodo Payments** under Payment Gateways and configure:

* Environment: `Test`
* WHMCS Instance ID
* Test API Key
* Test Webhook Secret
* Test Product Map

The **WHMCS Instance ID** must be a unique 8-64 character identifier for the WHMCS installation.

Example:

```text
whmcs_a8f14e45fceea167
```

The WHMCS Instance ID must be different for every WHMCS website, especially when multiple WHMCS installations use the same Dodo business/account.

The Instance ID is not a secret. It is a routing identifier included in signed payment metadata so that callbacks can be safely matched to the correct WHMCS installation.

### 6. Test end-to-end

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#6-test-end-to-end)

Create and pay a real WHMCS test invoice.

Confirm all of the following:

1. The Dodo checkout amount exactly matches the WHMCS invoice amount.
2. The Dodo checkout currency exactly matches the WHMCS invoice currency.
3. The checkout completes successfully in Dodo Test Mode.
4. Dodo sends `payment.succeeded` to the callback URL and receives HTTP 200.
5. The WHMCS invoice becomes Paid.
6. The WHMCS transaction ID matches the Dodo `payment_id`.
7. A duplicate or retried webhook does not create a second payment.
8. If refunds will be used, test both a partial refund and a full refund from WHMCS.

### 7. Repeat in Live Mode

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#7-repeat-in-live-mode)

Switch the Dodo Payments dashboard to **Live Mode** and repeat the configuration using separate:

* Live products
* Live API key
* Live webhook endpoint secret
* Live product map

Enter these values into the corresponding Live fields in WHMCS.

Change **Environment** to `Live` only after the Test Mode checkout and webhook flow has been validated successfully.

## Product map rules

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#product-map-rules)

The product map follows these rules:

* Use one `CURRENCY=PRODUCT_ID` entry per line.
* Currency must use a 3-letter ISO-style currency code.
* Blank lines are ignored.
* Lines beginning with `#` or `;` are ignored.
* The module contains no website, company, or product-catalog hardcoding.
* The module contains no fixed USD/INR/GBP/EUR allow-list.

Before creating a Checkout Session, the mapped Dodo product is validated to confirm that it is:

* A one-time product
* PWYW-enabled
* Tax-inclusive
* Configured with PPP/adaptive pricing disabled
* Using the same currency as the WHMCS invoice
* Configured with a minimum price that does not exceed the WHMCS invoice amount

## Security and payment integrity

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#security-and-payment-integrity)

The module includes several checks to protect invoice and payment integrity:

* Checkout metadata includes the WHMCS gateway name, WHMCS Instance ID, invoice ID, currency, and exact minor-unit amount.
* Webhook signatures are validated using the Standard Webhooks HMAC-SHA256 format.
* Webhook timestamps use a 5-minute tolerance.
* A valid signed webhook intended for another WHMCS installation is acknowledged and ignored.
* A `payment.succeeded` callback must match the original checkout currency.
* A `payment.succeeded` callback must match the exact original minor-unit amount before WHMCS is credited.
* The Dodo `payment_id` is used as the WHMCS transaction ID so duplicate callbacks are rejected by WHMCS.
* API keys and webhook secrets are never exposed in browser HTML.
* cURL SSL peer and hostname verification remain enabled.

## Refunds

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#refunds)

The module supports full and partial refunds through WHMCS.

For refunds:

* The module first retrieves `/payments/{payment_id}/line-items`.
* A partial refund sends Dodo's original `items_id` as `item_id`.
* Partial refund amounts are sent in minor units with `tax_inclusive: true`.
* A full refund omits `items` and refunds the remaining refundable balance.
* Dodo refund statuses other than `succeeded` are returned to WHMCS as an error.

When a refund does not return `succeeded`, the module also warns **not to retry the refund until the current Dodo refund status has been checked**. This helps prevent accidental duplicate refund attempts.

## Changelog

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#changelog)

Version `1.0.0` keeps the existing Test/Live API key, webhook-secret, product-map, and button-text setting names.

It adds one required field:

* **WHMCS Instance ID**

Other notable changes include:

* Removed the previous USD/INR/GBP/EUR-only dynamic-currency restriction.
* Added safe multi-WHMCS webhook routing through the WHMCS Instance ID.
* Added generic currency mapping.
* Sends a customer object only when WHMCS provides a valid customer email address.
* Reduces unnecessary Dodo customer duplication using `always_create_new_customer=false`.
* Does not expose saved payment methods through this invoice checkout flow.
* Improved API error handling and gateway logging.
* Updated refund handling for the current Dodo line-item and refund API structure.

## Note

[svg](https://github.com/iamsahildhamija/dodopayments-whmcs#note)

Dodo Payments acts as the Merchant of Record for Dodo transactions and may generate its own tax, receipt, or commercial documents.

WHMCS also maintains its own invoice record.

Configure your accounting and tax workflow so that customers and bookkeeping staff clearly understand which document should be treated as the commercial or tax record in the applicable jurisdiction.
