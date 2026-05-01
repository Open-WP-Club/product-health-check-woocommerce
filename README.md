# WooCommerce Product Health Check

A WordPress plugin that scans your WooCommerce products for common data issues and displays them in a clean admin dashboard.

## Features

- Scans all published products and variations in batches of 50
- Results are cached for 24 hours (transient) — no slow reloads on revisit
- Choose which checks to run before each scan
- Filter results by issue type
- Export all product SKUs as a comma-separated list

## Checks

| Check | Severity | Description |
|---|---|---|
| Missing Image | Critical | An attached image ID (featured or gallery) no longer exists in the media library |
| No Product Image | Critical | Product has no featured image set at all |
| Empty Price | Critical | Simple/external product has no regular price, or a variable product has no priced variation |
| Empty SKU | Warning | Product or variation has no SKU set |
| Missing Variation Image | Warning | A variation has no image assigned |
| Out of Stock / No Quantity | Warning | Stock management is enabled but stock quantity is not set |

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (active)

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   cd wp-content/plugins/
   git clone https://github.com/gabrielkanev/product-health-check-woocommerce wc-product-health-check
   ```
2. Activate the plugin from **Plugins → Installed Plugins** in WordPress admin.
3. Navigate to **WooCommerce → Product Health** to run your first scan.

## Usage

### Running a scan

1. Select the checks you want to run using the checkboxes at the top of the page.
2. Click **Run Scan** — results load from cache if available (up to 24 hours old).
3. Click **Clear Cache & Re-scan** to force a fresh scan regardless of cache.

### Filtering results

Use the **Filter** dropdown (top right) to show only a specific issue type. Filtering is instant and client-side — no page reload.

### Exporting SKUs

After a scan, a **SKU Export** section appears below the results table. It contains all non-empty SKUs from scanned products and variations, separated by commas. Click **Copy to clipboard** to copy the full list.

## File Structure

```
wc-product-health-check/
├── wc-product-health-check.php   # Plugin bootstrap & constants
├── includes/
│   ├── class-health-checker.php  # Scan logic & checks
│   └── class-admin-page.php      # Admin menu, page render & AJAX handler
└── assets/
    ├── admin.css
    └── admin.js
```

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
