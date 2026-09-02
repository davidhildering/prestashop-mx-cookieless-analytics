# MX Cookieless Analytics for PrestaShop

Cookieless, privacy-friendly web analytics for [PrestaShop](https://prestashop.com). The official MetriXs module.

- **No cookies, no consent banner.** MetriXs sets no cookies and stores no personal data or persistent identifiers, so no consent is required under the ePrivacy Directive / GDPR cookie rules.
- **Opt-in, off by default.** Installing the module loads nothing and sends nothing. Tracking starts only after you paste your site API key and enable tracking.
- **Automatic verification.** No DNS records, no theme edits: the module proves domain ownership to MetriXs itself.
- **EU-hosted.** Data lives on MetriXs servers in Germany and never leaves the EU.
- **Server-side order tracking.** `order_completed` is sent from your server the moment an order is validated, so redirect-based payment methods (iDEAL, Carte Bancaire, bank transfers) are captured. Revenue, AOV, conversion rate, funnel, and top products appear in your MetriXs dashboard.
- **Logged-in customer exclusion.** On by default, so your own test orders and account visits never pollute your stats.

## Installation

1. On the [releases page](https://github.com/davidhildering/prestashop-mx-cookieless-analytics/releases), download the **module ZIP from the release's "Assets" section** — the file named `mx-cookieless-analytics-X.Y.Z.zip`.
2. ⚠️ Do **not** use the "Source code (zip)" that GitHub lists next to it: that archive contains the repository, not the installable module.
3. In your shop's back office, go to **Modules → Module Manager → Upload a module** and upload the downloaded ZIP.
4. Click **Configure**.

## Setup

1. Create a free account at [app.metrixs.eu](https://app.metrixs.eu) and add your shop.
2. Create a site API key in the MetriXs dashboard: **Settings → Sites → API keys**.
3. In **Modules → Module Manager → MX Cookieless Analytics → Configure**, paste the API key, enable tracking, and save.
4. The module verifies your shop automatically. When the page shows "shop verified", tracking is live.

Your dashboard is at [app.metrixs.eu](https://app.metrixs.eu): visitors, pageviews, sources, geography, devices, bounce rate, and revenue.

## Configuration

| Option | Default | Description |
|---|---|---|
| `MXCOAN_ENABLED` | `0` | Master switch. Nothing is injected until this is on **and** the shop is connected. |
| `MXCOAN_API_KEY` | — | Site-scoped API key (`mtx_live_…`) from the MetriXs dashboard. Grants analytics access for this one shop only, revocable at any time. |
| `MXCOAN_API_BASE` | `https://app.metrixs.eu` | MetriXs endpoint. Only change for self-hosted setups. |
| `MXCOAN_DOMAIN` | *(auto)* | Shop domain used for tracking. Leave empty to auto-detect. |
| `MXCOAN_EXCLUDE_LOGGED_IN` | `1` | Skip tracking for logged-in customer accounts. |
| `MXCOAN_CONNECTED` | `0` | Set automatically by the verification flow. Do not edit by hand. |
| `MXCOAN_CHALLENGE` | — | Verification token. Set automatically. Do not edit by hand. |

The tracker script is served from `app.metrixs.eu` with a versioned URL and loaded asynchronously (`defer`), so it works fine alongside full-page caching and CDN setups.

## Disconnecting

Turn off **Enable tracking** to pause instantly. Uninstalling the module also notifies MetriXs (the shop is marked unverified, ingestion stops). To disconnect while keeping the module, revoke the API key in the MetriXs dashboard (**Settings → Sites → API keys**).

## Privacy

What MetriXs does **not** do: no cookies, no visitor IP storage (daily-rotating salted hash only), no cross-day visitor identification, no data outside the EU. Details: [metrixs.eu/data-policy](https://metrixs.eu/data-policy).

## License

MIT. See [LICENSE](LICENSE).
