# 1.1.2
## 2026-10-05

1. [](#fixed)
    * Critical fix: server-side order_completed events were rejected by the MetriXs API since 1.1.1 (the event payload no longer matched the ingest schema). The payload now mirrors the tracker shape (n/u/d/p with commerce props). Revenue tracking works again for every payment method, including redirect methods like iDEAL. Orders with very many items no longer drop the whole event (items prop is truncated to the first item instead).

# 1.1.1
## 2026-09-16

1. [](#new)
    * Updated the bundled tracker script (v1.2.0): custom-event properties that look like personal data (PII) are now removed automatically before they are stored, and property limits (max 30 props, key <= 300 chars, value <= 2000 chars) are enforced. Privacy: MetriXs still stores no personal data.

# 1.1.0
## 2026-09-14

1. [](#new)
    * One-click connect: a "Connect with MetriXs" button on the module config page opens the MetriXs dashboard, where you create (or open) your account and the shop is added, connected and verified automatically. No API key copying. The manual API-key flow works unchanged.

# 1.0.0
## 2026-09-02

1. [](#new)
    * Initial release: opt-in tracker injection (off until you connect), automatic shop verification via challenge/response (no DNS records needed), logged-in customer exclusion, server-side WooCommerce-style order tracking.
