<?php
/**
 * WPHC_Admin_Page
 *
 * Registers the WP Admin menu item and renders the Product Health dashboard page.
 * Also handles the AJAX scan endpoint.
 *
 * @package WC_Product_Health_Check
 */

defined( 'ABSPATH' ) || exit;

class WPHC_Admin_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wc-product-health-check';

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	const AJAX_ACTION      = 'wphc_run_scan';
	const AJAX_CSV_ACTION  = 'wphc_export_csv';
	const PAGE_SIZE        = 50;

	/**
	 * Health checker instance.
	 *
	 * @var WPHC_Health_Checker
	 */
	private WPHC_Health_Checker $checker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->checker = new WPHC_Health_Checker();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax_scan' ) );
		add_action( 'wp_ajax_' . self::AJAX_CSV_ACTION, array( $this, 'handle_csv_export' ) );
	}

	/**
	 * Register admin menu under WooCommerce.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Health Check', 'wc-product-health-check' ),
			__( 'Product Health', 'wc-product-health-check' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JS only on this plugin's admin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		// WooCommerce submenu pages have a hook like "woocommerce_page_{slug}".
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wphc-admin',
			WPHC_PLUGIN_URL . 'assets/admin.css',
			array(),
			WPHC_VERSION
		);

		wp_enqueue_script(
			'wphc-admin',
			WPHC_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			WPHC_VERSION,
			true
		);

		wp_localize_script(
			'wphc-admin',
			'wphcData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'csvAction' => self::AJAX_CSV_ACTION,
				'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
				'csvNonce'  => wp_create_nonce( self::AJAX_CSV_ACTION ),
				'pageSize'  => self::PAGE_SIZE,
				'i18n'      => array(
					'scanning'         => __( 'Scanning products…', 'wc-product-health-check' ),
					'scanComplete'     => __( 'Scan complete.', 'wc-product-health-check' ),
					'scanFailed'       => __( 'Scan failed. Please try again.', 'wc-product-health-check' ),
					'noIssues'         => __( 'No issues found.', 'wc-product-health-check' ),
					'noChecksSelected' => __( 'Please select at least one check to run.', 'wc-product-health-check' ),
					'selectAll'        => __( 'Select all', 'wc-product-health-check' ),
					'deselectAll'      => __( 'Deselect all', 'wc-product-health-check' ),
					'skus'             => __( 'SKUs', 'wc-product-health-check' ),
					'pageOf'           => __( 'Page %1$d of %2$d', 'wc-product-health-check' ),
				),
			)
		);
	}

	/**
	 * Handle the AJAX scan request.
	 */
	public function handle_ajax_scan(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-product-health-check' ) ), 403 );
		}

		$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );

		// Sanitize enabled checks from POST.
		$enabled_checks = array();
		if ( ! empty( $_POST['checks'] ) && is_array( $_POST['checks'] ) ) {
			$all_types = WPHC_Health_Checker::all_check_types();
			foreach ( array_map( 'sanitize_key', wp_unslash( $_POST['checks'] ) ) as $check ) {
				if ( in_array( $check, $all_types, true ) ) {
					$enabled_checks[] = $check;
				}
			}
		}

		if ( $force ) {
			$this->checker->clear_cache();
		}

		$data = $this->checker->run( $force, $enabled_checks );

		ob_start();
		$this->render_summary( $data );
		$summary_html = ob_get_clean();

		ob_start();
		$this->render_table( $data );
		$table_html = ob_get_clean();

		$skus = $data['skus'] ?? array();

		wp_send_json_success(
			array(
				'summary'  => $summary_html,
				'table'    => $table_html,
				'total'    => count( $data['issues'] ),
				'skus'     => implode( ', ', array_map( 'esc_html', $skus ) ),
				'skuCount' => count( $skus ),
				'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
			)
		);
	}

	/**
	 * Handle CSV export request.
	 */
	public function handle_csv_export(): void {
		check_ajax_referer( self::AJAX_CSV_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-product-health-check' ), 403 );
		}

		// Always export with all checks — runs scan if full cache not available.
		$data = $this->checker->run( false, array() );

		$labels   = WPHC_Health_Checker::get_issue_labels();
		$filename = 'product-health-check-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$output = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8 compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, array( 'Product ID', 'Product Name', 'Issue Type', 'Severity', 'Detail', 'Last Modified', 'Edit URL' ) );

		foreach ( $data['issues'] as $issue ) {
			fputcsv( $output, array(
				$issue['product_id'],
				$issue['product_name'],
				$labels[ $issue['type'] ] ?? $issue['type'],
				ucfirst( $issue['severity'] ),
				$issue['detail'],
				$issue['last_modified'] ? gmdate( 'Y-m-d H:i', $issue['last_modified'] ) : '',
				$issue['edit_url'],
			) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render the full admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-product-health-check' ) );
		}

		// Load cached data for initial render (don't auto-run a fresh scan).
		$data   = get_transient( WPHC_Health_Checker::TRANSIENT_KEY );
		$labels = WPHC_Health_Checker::get_issue_labels();
		?>
		<div class="wrap wphc-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'WooCommerce Product Health Check', 'wc-product-health-check' ); ?>
			</h1>

			<!-- Checks selector panel -->
			<div class="wphc-checks-panel">
				<strong class="wphc-checks-label"><?php esc_html_e( 'Checks to run:', 'wc-product-health-check' ); ?></strong>
				<div class="wphc-checks-list">
					<?php foreach ( $labels as $key => $label ) : ?>
						<label class="wphc-check-item">
							<input type="checkbox" class="wphc-check" name="wphc_checks[]" value="<?php echo esc_attr( $key ); ?>" checked autocomplete="off" />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<button type="button" id="wphc-toggle-all" class="button-link"><?php esc_html_e( 'Deselect all', 'wc-product-health-check' ); ?></button>
			</div>

			<div class="wphc-toolbar">
				<button id="wphc-run-scan" class="button button-primary">
					<?php esc_html_e( 'Run Scan', 'wc-product-health-check' ); ?>
				</button>
				<button id="wphc-clear-cache" class="button button-secondary">
					<?php esc_html_e( 'Clear Cache &amp; Re-scan', 'wc-product-health-check' ); ?>
				</button>
				<button id="wphc-export-csv" class="button button-secondary" <?php echo ( false === $data ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Export CSV', 'wc-product-health-check' ); ?>
				</button>

				<span id="wphc-spinner" class="wphc-spinner" style="display:none;" aria-hidden="true"></span>
				<span id="wphc-status" class="wphc-status" aria-live="polite"></span>

				<div class="wphc-filter">
					<label for="wphc-filter-type"><?php esc_html_e( 'Filter:', 'wc-product-health-check' ); ?></label>
					<select id="wphc-filter-type">
						<option value="all"><?php esc_html_e( 'All Issues', 'wc-product-health-check' ); ?></option>
						<?php foreach ( $labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div id="wphc-summary-wrap">
				<?php if ( false !== $data ) : ?>
					<?php $this->render_summary( $data ); ?>
				<?php endif; ?>
			</div>

			<div id="wphc-table-wrap">
				<?php if ( false !== $data ) : ?>
					<?php $this->render_table( $data ); ?>
				<?php else : ?>
					<p class="wphc-no-data">
						<?php esc_html_e( 'No scan results yet. Click "Run Scan" to begin.', 'wc-product-health-check' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- SKU Export -->
			<div id="wphc-sku-export" class="wphc-sku-export" <?php echo ( false === $data || empty( $data['skus'] ) ) ? 'style="display:none;"' : ''; ?>>
				<div class="wphc-sku-export-header">
					<strong><?php esc_html_e( 'SKU Export', 'wc-product-health-check' ); ?></strong>
					<span id="wphc-sku-count" class="wphc-sku-count">
						<?php
						if ( false !== $data && ! empty( $data['skus'] ) ) {
							printf(
								/* translators: %d: number of SKUs */
								esc_html__( '%d SKUs', 'wc-product-health-check' ),
								count( $data['skus'] )
							);
						}
						?>
					</span>
					<button type="button" id="wphc-copy-skus" class="button button-secondary button-small">
						<?php esc_html_e( 'Copy to clipboard', 'wc-product-health-check' ); ?>
					</button>
					<span id="wphc-copy-feedback" class="wphc-copy-feedback" style="display:none;">
						<?php esc_html_e( 'Copied!', 'wc-product-health-check' ); ?>
					</span>
				</div>
				<textarea id="wphc-sku-textarea" class="wphc-sku-textarea" readonly><?php
					if ( false !== $data && ! empty( $data['skus'] ) ) {
						echo esc_textarea( implode( ', ', $data['skus'] ) );
					}
				?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the summary cards.
	 *
	 * @param array $data Scan result data.
	 */
	private function render_summary( array $data ): void {
		$labels      = WPHC_Health_Checker::get_issue_labels();
		$total_issues = count( $data['issues'] );
		$scanned_at  = isset( $data['scanned_at'] ) ? $data['scanned_at'] : 0;
		?>
		<div class="wphc-summary">
			<div class="wphc-summary-card wphc-card-total">
				<span class="wphc-card-number"><?php echo esc_html( number_format_i18n( $data['scanned'] ) ); ?></span>
				<span class="wphc-card-label"><?php esc_html_e( 'Products Scanned', 'wc-product-health-check' ); ?></span>
			</div>
			<div class="wphc-summary-card wphc-card-issues">
				<span class="wphc-card-number"><?php echo esc_html( number_format_i18n( $total_issues ) ); ?></span>
				<span class="wphc-card-label"><?php esc_html_e( 'Total Issues', 'wc-product-health-check' ); ?></span>
			</div>
			<?php foreach ( $data['counts'] as $type => $count ) : ?>
				<?php if ( $count > 0 ) : ?>
					<div class="wphc-summary-card wphc-card-type">
						<span class="wphc-card-number"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						<span class="wphc-card-label"><?php echo esc_html( $labels[ $type ] ?? $type ); ?></span>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php if ( $scanned_at > 0 ) : ?>
			<p class="wphc-scanned-at">
				<?php
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Last scanned: %s ago', 'wc-product-health-check' ),
					esc_html( human_time_diff( $scanned_at, time() ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the issues table.
	 *
	 * @param array $data Scan result data.
	 */
	private function render_table( array $data ): void {
		$issues = $data['issues'];
		$labels = WPHC_Health_Checker::get_issue_labels();

		if ( empty( $issues ) ) {
			echo '<p class="wphc-no-issues">' . esc_html__( 'No issues found — your products look healthy!', 'wc-product-health-check' ) . '</p>';
			return;
		}

		// Group issues by product ID for display.
		$grouped = array();
		foreach ( $issues as $issue ) {
			$grouped[ $issue['product_id'] ][] = $issue;
		}
		?>
		<table class="wp-list-table widefat fixed striped wphc-issues-table">
			<thead>
				<tr>
					<th scope="col" class="wphc-col-product"><?php esc_html_e( 'Product', 'wc-product-health-check' ); ?></th>
					<th scope="col" class="wphc-col-type"><?php esc_html_e( 'Issue Type', 'wc-product-health-check' ); ?></th>
					<th scope="col" class="wphc-col-detail"><?php esc_html_e( 'Detail', 'wc-product-health-check' ); ?></th>
					<th scope="col" class="wphc-col-modified"><?php esc_html_e( 'Last Modified', 'wc-product-health-check' ); ?></th>
					<th scope="col" class="wphc-col-actions"><?php esc_html_e( 'Actions', 'wc-product-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody id="wphc-issues-tbody">
				<?php foreach ( $grouped as $product_id => $product_issues ) : ?>
					<?php $first = true; ?>
					<?php foreach ( $product_issues as $issue ) : ?>
						<tr data-issue-type="<?php echo esc_attr( $issue['type'] ); ?>" data-severity="<?php echo esc_attr( $issue['severity'] ); ?>">
							<td class="wphc-col-product">
								<?php if ( $first ) : ?>
									<strong><?php echo esc_html( $issue['product_name'] ); ?></strong>
									<?php $first = false; ?>
								<?php else : ?>
									<span class="wphc-product-name-repeat" aria-hidden="true">— <?php echo esc_html( $issue['product_name'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="wphc-col-type">
								<span class="wphc-badge wphc-badge--<?php echo esc_attr( $issue['severity'] ); ?>">
									<?php echo esc_html( $labels[ $issue['type'] ] ?? $issue['type'] ); ?>
								</span>
							</td>
							<td class="wphc-col-detail">
								<?php echo esc_html( $issue['detail'] ); ?>
							</td>
							<td class="wphc-col-modified">
								<?php if ( ! empty( $issue['last_modified'] ) ) : ?>
									<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i', $issue['last_modified'] ) ); ?>">
										<?php echo esc_html( human_time_diff( $issue['last_modified'], time() ) . ' ' . __( 'ago', 'wc-product-health-check' ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td class="wphc-col-actions">
								<?php if ( ! empty( $issue['edit_url'] ) ) : ?>
									<a href="<?php echo esc_url( $issue['edit_url'] ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit Product', 'wc-product-health-check' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'wc-product-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Issue Type', 'wc-product-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'wc-product-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last Modified', 'wc-product-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'wc-product-health-check' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<div id="wphc-pagination" class="wphc-pagination" style="display:none;"></div>
		<?php
	}
}
