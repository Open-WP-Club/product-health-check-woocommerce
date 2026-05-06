<?php
/**
 * WPHC_CLI_Command
 *
 * Provides a `wp product-health scan` command for running health checks
 * from the command line, useful in CI/CD pipelines and cron jobs.
 *
 * @package WC_Product_Health_Check
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Scans WooCommerce products for common data quality issues.
 */
class WPHC_CLI_Command extends WP_CLI_Command {

	/**
	 * Scan all products and report issues.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Bypass the transient cache and run a fresh scan.
	 *
	 * [--checks=<checks>]
	 * : Comma-separated list of check types to run. Defaults to all checks.
	 * Available: missing_image, empty_sku, no_product_image, empty_price,
	 *            missing_variation_image, out_of_stock_no_quantity
	 *
	 * [--format=<format>]
	 * : Output format. Accepted values: table, json, csv, count.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * [--severity=<severity>]
	 * : Only show issues at or above this severity level: critical, warning, info.
	 * ---
	 * default: info
	 * options:
	 *   - critical
	 *   - warning
	 *   - info
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # Run a full scan and display results as a table.
	 *   $ wp product-health scan
	 *
	 *   # Force a fresh scan, show only critical issues.
	 *   $ wp product-health scan --force --severity=critical
	 *
	 *   # Run only the SKU and price checks and export as CSV.
	 *   $ wp product-health scan --checks=empty_sku,empty_price --format=csv > issues.csv
	 *
	 *   # Return the total number of issues (useful in CI).
	 *   $ wp product-health scan --format=count
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$force    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$format   = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$severity = WP_CLI\Utils\get_flag_value( $assoc_args, 'severity', 'info' );

		$severity_rank = array( 'info' => 0, 'warning' => 1, 'critical' => 2 );
		$min_rank      = $severity_rank[ $severity ] ?? 0;

		// Parse --checks flag.
		$enabled_checks = array();
		$checks_raw     = WP_CLI\Utils\get_flag_value( $assoc_args, 'checks', '' );
		if ( ! empty( $checks_raw ) ) {
			$all_types = WPHC_Health_Checker::all_check_types();
			foreach ( explode( ',', $checks_raw ) as $c ) {
				$c = trim( $c );
				if ( in_array( $c, $all_types, true ) ) {
					$enabled_checks[] = $c;
				} else {
					WP_CLI::warning( sprintf( 'Unknown check type "%s" — skipped.', $c ) );
				}
			}
		}

		WP_CLI::log( 'Scanning products…' );

		$checker = new WPHC_Health_Checker();

		if ( $force ) {
			$checker->clear_cache();
		}

		$data   = $checker->run( $force, $enabled_checks );
		$labels = WPHC_Health_Checker::get_issue_labels();

		// Filter by severity.
		$issues = array_values(
			array_filter(
				$data['issues'],
				function ( $issue ) use ( $severity_rank, $min_rank ) {
					return ( $severity_rank[ $issue['severity'] ] ?? 0 ) >= $min_rank;
				}
			)
		);

		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $issues ) );
			return;
		}

		if ( empty( $issues ) ) {
			WP_CLI::success( sprintf( 'Scanned %d products — no issues found.', $data['scanned'] ) );
			return;
		}

		// Build rows for output.
		$rows = array_map(
			function ( $issue ) use ( $labels ) {
				return array(
					'product_id'   => $issue['product_id'],
					'product_name' => $issue['product_name'],
					'sku'          => $issue['sku'] ?: '—',
					'type'         => $labels[ $issue['type'] ] ?? $issue['type'],
					'severity'     => strtoupper( $issue['severity'] ),
					'detail'       => $issue['detail'],
				);
			},
			$issues
		);

		$fields = array( 'product_id', 'product_name', 'sku', 'type', 'severity', 'detail' );

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			WP_CLI\Utils\format_items( $format, $rows, $fields );
		}

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Scanned %d products · %d issue(s) found.',
				$data['scanned'],
				count( $issues )
			)
		);

		// Exit with error code if there are critical issues — useful for CI.
		$has_critical = ! empty(
			array_filter( $issues, function ( $i ) {
				return 'critical' === $i['severity'];
			} )
		);

		if ( $has_critical ) {
			WP_CLI::halt( 1 );
		}
	}
}

WP_CLI::add_command( 'product-health', 'WPHC_CLI_Command' );
