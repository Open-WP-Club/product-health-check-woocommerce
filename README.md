# WooCommerce Product Health Check

A WordPress plugin that scans your WooCommerce products for common data issues and displays them in a clean admin dashboard.

## Features

- Scans all published products and variations in batches of 50
- Results are cached for 24 hours (transient) — no slow reloads on revisit
- Choose which checks to run before each scan
- Filter results by issue type
- Export issues as CSV (Product ID, Name, Issue Type, Severity, Detail, Last Modified, Edit URL)
- Export all product SKUs as a comma-separated list
- Full/SKU-only view toggle
- Responsive admin UI with mobile-friendly filter drawer
- WP-CLI support for automation and CI/CD pipelines

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
   git clone https://github.com/Open-WP-Club/product-health-check-woocommerce wc-product-health-check
   ```
2. Activate the plugin from **Plugins → Installed Plugins** in WordPress admin.
3. Navigate to **WooCommerce → Product Health** to run your first scan.

## Usage

### Running a scan

1. Select the checks you want to run using the checkboxes at the top of the page.
2. Click **Run Scan** — results load from cache if available (up to 24 hours old).
3. Click **Clear Cache & Re-scan** to force a fresh scan regardless of cache.

### Filtering results

Use the **Filter** dropdown to show only a specific issue type. Filtering is instant and client-side — no page reload. On mobile the filter is accessible via the **Filter issues** toggle button in the toolbar.

### Exporting to CSV

Click **Export CSV** in the toolbar to download all issues as a CSV file, compatible with Excel (UTF-8 BOM included).

### Exporting SKUs

After a scan, a **SKU Export** section appears below the results table with all non-empty SKUs from scanned products and variations. Click **Copy to clipboard** to copy the full comma-separated list.

## WP-CLI

The plugin registers a `wp product-health` command for running scans from the command line.

```bash
# Full scan, table output
wp product-health scan

# Force a fresh scan ignoring the cache
wp product-health scan --force

# Show only critical issues
wp product-health scan --severity=critical

# Run specific checks only
wp product-health scan --checks=empty_sku,empty_price

# Export as CSV
wp product-health scan --format=csv > issues.csv

# Output as JSON
wp product-health scan --format=json

# Return the issue count only (useful in scripts)
wp product-health scan --format=count
```

**Available flags:**

| Flag | Default | Description |
|---|---|---|
| `--force` | — | Bypass the transient cache and run a fresh scan |
| `--checks=<list>` | all | Comma-separated check types to run |
| `--format=<format>` | `table` | Output format: `table`, `json`, `csv`, `count` |
| `--severity=<level>` | `info` | Minimum severity to report: `info`, `warning`, `critical` |

**CI/CD:** The command exits with code `1` if any critical issues are found, `0` otherwise — ready to use as a deploy gate in GitHub Actions or similar pipelines.

```yaml
# Example GitHub Actions step
- name: Product health check
  run: wp product-health scan --format=count --severity=critical
```

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
