# Changelog

## 1.0.0 — 2026-09-07

- Removed fixed USD/INR/GBP/EUR currency restriction.
- Added reusable per-installation `WHMCS Instance ID` for safe multi-site webhook routing.
- Preserved invoice-driven design: one Dodo PWYW product per currency, not per WHMCS product.
- Added generic mapped-currency handling and fail-closed Dodo product validation.
- Improved customer/billing payload handling.
- Disabled checkout currency selection, discount entry, addon editing, and saved-payment-method display.
- Avoided forced duplicate Dodo customer creation.
- Preserved Standard Webhooks signature validation and exact amount/currency verification.
- Verified current Dodo partial-refund request shape (`items_id` -> `item_id`, minor amount, tax-inclusive flag).
- Improved API error extraction, safe logging, and amount bounds.
- Added payment webhook payload-type sanity validation and explicit tax-category deployment guidance.

## 1.0.1 — 2026-09-09

- Initial Checkout Sessions integration.
