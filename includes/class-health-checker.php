<?php
/**
 * WPHC_Health_Checker
 *
 * Runs all product health checks and returns a structured array of issues.
 *
 * @package WC_Product_Health_Check
 */

defined( 'ABSPATH' ) || exit;

class WPHC_Health_Checker {

	/**
	 * Batch size for product queries.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Transient key.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'wphc_scan_results';

	/**
	 * Transient expiry in seconds (24 hours).
	 *
	 * @var int
	 */
	const TRANSIENT_EXPIRY = DAY_IN_SECONDS;

	/**
	 * Issue type constants.
	 */
	const ISSUE_MISSING_IMAGE     = 'missing_image';
	const ISSUE_EMPTY_SKU         = 'empty_sku';
	const ISSUE_NO_PRODUCT_IMAGE  = 'no_product_image';
	const ISSUE_EMPTY_PRICE       = 'empty_price';
	const ISSUE_MISSING_VAR_IMAGE = 'missing_variation_image';
	const ISSUE_OUT_OF_STOCK      = 'out_of_stock_no_quantity';

	/**
	 * All available check type keys.
	 *
	 * @return string[]
	 */
	public static function all_check_types(): array {
		return array(
			self::ISSUE_MISSING_IMAGE,
			self::ISSUE_EMPTY_SKU,
			self::ISSUE_NO_PRODUCT_IMAGE,
			self::ISSUE_EMPTY_PRICE,
			self::ISSUE_MISSING_VAR_IMAGE,
			self::ISSUE_OUT_OF_STOCK,
		);
	}

	/**
	 * Run all checks and return results.
	 *
	 * @param bool     $force_refresh  Bypass transient cache.
	 * @param string[] $enabled_checks Check types to run (empty = all).
	 * @return array {
	 *   'scanned'       => int,
	 *   'issues'        => array,
	 *   'counts'        => array,
	 *   'skus'          => string[],
	 *   'scanned_at'    => int,
	 *   'enabled_checks'=> string[],
	 * }
	 */
	public function run( bool $force_refresh = false, array $enabled_checks = array() ): array {
		if ( empty( $enabled_checks ) ) {
			$enabled_checks = self::all_check_types();
		}

		// Only use cache when running all checks.
		$use_cache = count( $enabled_checks ) === count( self::all_check_types() );

		if ( $use_cache && ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$issues        = array();
		$skus          = array();
		$total_scanned = 0;
		$page          = 1;

		do {
			$args = array(
				'status'   => 'publish',
				'limit'    => self::BATCH_SIZE,
				'page'     => $page,
				'paginate' => true,
				'type'     => array( 'simple', 'variable', 'grouped', 'external' ),
			);

			$query   = new WC_Product_Query( $args );
			$results = $query->get_products();

			/** @var WC_Product[] $products */
			$products = $results->products;
			$total    = $results->total;

			foreach ( $products as $product ) {
				$product_issues = $this->check_product( $product, $enabled_checks );
				if ( ! empty( $product_issues ) ) {
					$issues = array_merge( $issues, $product_issues );
				}

				// Collect SKUs (product + variations).
				$this->collect_skus( $product, $skus );

				$total_scanned++;
			}

			$page++;
		} while ( ( $page - 1 ) * self::BATCH_SIZE < $total );

		$counts = $this->count_by_type( $issues );

		$data = array(
			'scanned'        => $total_scanned,
			'issues'         => $issues,
			'counts'         => $counts,
			'skus'           => array_values( array_unique( $skus ) ),
			'scanned_at'     => time(),
			'enabled_checks' => $enabled_checks,
		);

		if ( $use_cache ) {
			set_transient( self::TRANSIENT_KEY, $data, self::TRANSIENT_EXPIRY );
		}

		return $data;
	}

	/**
	 * Collect non-empty SKUs from a product and its variations.
	 *
	 * @param WC_Product $product
	 * @param string[]   $skus Passed by reference.
	 */
	private function collect_skus( WC_Product $product, array &$skus ): void {
		$sku = $product->get_sku();
		if ( '' !== $sku && null !== $sku ) {
			$skus[] = $sku;
		}

		if ( $product instanceof WC_Product_Variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$v_sku = $variation->get_sku();
					if ( '' !== $v_sku && null !== $v_sku ) {
						$skus[] = $v_sku;
					}
				}
			}
		}
	}

	/**
	 * Delete the cached results transient.
	 */
	public function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Run all checks for a single product.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	private function check_product( WC_Product $product, array $enabled_checks = array() ): array {
		$issues = array();

		$check_images = in_array( self::ISSUE_MISSING_IMAGE, $enabled_checks, true );
		$check_no_img = in_array( self::ISSUE_NO_PRODUCT_IMAGE, $enabled_checks, true );
		$check_sku    = in_array( self::ISSUE_EMPTY_SKU, $enabled_checks, true );
		$check_price  = in_array( self::ISSUE_EMPTY_PRICE, $enabled_checks, true );
		$check_stock  = in_array( self::ISSUE_OUT_OF_STOCK, $enabled_checks, true );

		// Check 3: No product image (no featured image set at all).
		$thumbnail_id = $product->get_image_id();
		if ( $check_no_img && empty( $thumbnail_id ) ) {
			$issues[] = $this->make_issue(
				$product,
				self::ISSUE_NO_PRODUCT_IMAGE,
				__( 'Product has no featured image set.', 'wc-product-health-check' ),
				'critical'
			);
		} elseif ( $check_images && ! empty( $thumbnail_id ) ) {
			// Check 1: Featured image attachment is missing from media library.
			if ( ! $this->attachment_exists( (int) $thumbnail_id ) ) {
				$issues[] = $this->make_issue(
					$product,
					self::ISSUE_MISSING_IMAGE,
					/* translators: %d: attachment ID */
					sprintf( __( 'Featured image ID %d is missing from the media library.', 'wc-product-health-check' ), $thumbnail_id ),
					'critical'
				);
			}
		}

		// Check 1 (continued): Gallery images with missing attachments.
		if ( $check_images ) {
			$gallery_ids = $product->get_gallery_image_ids();
			foreach ( $gallery_ids as $gallery_id ) {
				if ( ! $this->attachment_exists( (int) $gallery_id ) ) {
					$issues[] = $this->make_issue(
						$product,
						self::ISSUE_MISSING_IMAGE,
						/* translators: %d: attachment ID */
						sprintf( __( 'Gallery image ID %d is missing from the media library.', 'wc-product-health-check' ), $gallery_id ),
						'critical'
					);
				}
			}
		}

		// Check 2: Empty SKU (skip variable products — check variations instead).
		if ( $check_sku && 'variable' !== $product->get_type() ) {
			$sku = $product->get_sku();
			if ( '' === $sku || null === $sku ) {
				$issues[] = $this->make_issue(
					$product,
					self::ISSUE_EMPTY_SKU,
					__( 'Product SKU is empty.', 'wc-product-health-check' ),
					'warning'
				);
			}
		}

		// Check 4: Empty price (simple and external products).
		if ( $check_price && in_array( $product->get_type(), array( 'simple', 'external' ), true ) ) {
			$regular_price = $product->get_regular_price();
			if ( '' === $regular_price || null === $regular_price ) {
				$issues[] = $this->make_issue(
					$product,
					self::ISSUE_EMPTY_PRICE,
					__( 'Product has no regular price set.', 'wc-product-health-check' ),
					'critical'
				);
			}
		}

		// Check 6: Out of stock with no stock quantity.
		if ( $check_stock && $product->get_manage_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( null === $qty || '' === $qty ) {
				$issues[] = $this->make_issue(
					$product,
					self::ISSUE_OUT_OF_STOCK,
					__( 'Stock management is enabled but stock quantity is not set.', 'wc-product-health-check' ),
					'warning'
				);
			}
		}

		// Variable product: check variations.
		if ( $product instanceof WC_Product_Variable ) {
			$variation_ids = $product->get_children();
			foreach ( $variation_ids as $variation_id ) {
				$variation      = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}
				$variation_issues = $this->check_variation( $product, $variation, $enabled_checks );
				$issues           = array_merge( $issues, $variation_issues );
			}

			// Check 4 for variable: ensure at least one variation has a price.
			if ( $check_price ) {
				$has_price = false;
				foreach ( $product->get_available_variations() as $v_data ) {
					if ( '' !== $v_data['display_regular_price'] ) {
						$has_price = true;
						break;
					}
				}
				if ( ! $has_price && ! empty( $product->get_children() ) ) {
					$issues[] = $this->make_issue(
						$product,
						self::ISSUE_EMPTY_PRICE,
						__( 'Variable product has no variation with a price set.', 'wc-product-health-check' ),
						'critical'
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Run checks specific to a product variation.
	 *
	 * @param WC_Product           $parent
	 * @param WC_Product_Variation $variation
	 * @return array
	 */
	private function check_variation( WC_Product $parent, WC_Product_Variation $variation, array $enabled_checks = array() ): array {
		$issues = array();

		// Check 2: Empty SKU on variation.
		if ( in_array( self::ISSUE_EMPTY_SKU, $enabled_checks, true ) ) {
			$sku = $variation->get_sku();
			if ( '' === $sku || null === $sku ) {
				$issues[] = $this->make_issue(
					$parent,
					self::ISSUE_EMPTY_SKU,
					/* translators: %d: variation ID */
					sprintf( __( 'Variation #%d has no SKU set.', 'wc-product-health-check' ), $variation->get_id() ),
					'warning',
					$variation->get_id()
				);
			}
		}

		// Check 4: Empty price on variation.
		if ( in_array( self::ISSUE_EMPTY_PRICE, $enabled_checks, true ) ) {
			$regular_price = $variation->get_regular_price();
			if ( '' === $regular_price || null === $regular_price ) {
				$issues[] = $this->make_issue(
					$parent,
					self::ISSUE_EMPTY_PRICE,
					/* translators: %d: variation ID */
					sprintf( __( 'Variation #%d has no regular price set.', 'wc-product-health-check' ), $variation->get_id() ),
					'critical',
					$variation->get_id()
				);
			}
		}

		// Check 5: Missing variation image.
		if ( in_array( self::ISSUE_MISSING_VAR_IMAGE, $enabled_checks, true ) || in_array( self::ISSUE_MISSING_IMAGE, $enabled_checks, true ) ) {
			$image_id = $variation->get_image_id();
			if ( empty( $image_id ) && in_array( self::ISSUE_MISSING_VAR_IMAGE, $enabled_checks, true ) ) {
				$issues[] = $this->make_issue(
					$parent,
					self::ISSUE_MISSING_VAR_IMAGE,
					/* translators: %d: variation ID */
					sprintf( __( 'Variation #%d has no image set.', 'wc-product-health-check' ), $variation->get_id() ),
					'warning',
					$variation->get_id()
				);
			} elseif ( ! empty( $image_id ) && in_array( self::ISSUE_MISSING_IMAGE, $enabled_checks, true ) && ! $this->attachment_exists( (int) $image_id ) ) {
				$issues[] = $this->make_issue(
					$parent,
					self::ISSUE_MISSING_IMAGE,
					/* translators: 1: variation ID, 2: image ID */
					sprintf( __( 'Variation #%1$d image ID %2$d is missing from the media library.', 'wc-product-health-check' ), $variation->get_id(), $image_id ),
					'critical',
					$variation->get_id()
				);
			}
		}

		// Check 6: Variation stock management without quantity.
		if ( in_array( self::ISSUE_OUT_OF_STOCK, $enabled_checks, true ) && $variation->get_manage_stock() ) {
			$qty = $variation->get_stock_quantity();
			if ( null === $qty || '' === $qty ) {
				$issues[] = $this->make_issue(
					$parent,
					self::ISSUE_OUT_OF_STOCK,
					/* translators: %d: variation ID */
					sprintf( __( 'Variation #%d has stock management enabled but no stock quantity set.', 'wc-product-health-check' ), $variation->get_id() ),
					'warning',
					$variation->get_id()
				);
			}
		}

		return $issues;
	}

	/**
	 * Check whether a media attachment still exists.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	private function attachment_exists( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$post = get_post( $attachment_id );
		return ( $post instanceof WP_Post && 'attachment' === $post->post_type );
	}

	/**
	 * Build a standardised issue array.
	 *
	 * @param WC_Product $product
	 * @param string     $type
	 * @param string     $detail
	 * @param string     $severity  'critical' | 'warning' | 'info'
	 * @param int|null   $variation_id
	 * @return array
	 */
	private function make_issue( WC_Product $product, string $type, string $detail, string $severity = 'warning', ?int $variation_id = null ): array {
		return array(
			'product_id'   => $product->get_id(),
			'product_name' => $product->get_name(),
			'edit_url'     => get_edit_post_link( $product->get_id() ),
			'type'         => $type,
			'severity'     => $severity,
			'detail'       => $detail,
			'variation_id' => $variation_id,
		);
	}

	/**
	 * Count issues grouped by type.
	 *
	 * @param array $issues
	 * @return array
	 */
	private function count_by_type( array $issues ): array {
		$counts = array(
			self::ISSUE_MISSING_IMAGE     => 0,
			self::ISSUE_EMPTY_SKU         => 0,
			self::ISSUE_NO_PRODUCT_IMAGE  => 0,
			self::ISSUE_EMPTY_PRICE       => 0,
			self::ISSUE_MISSING_VAR_IMAGE => 0,
			self::ISSUE_OUT_OF_STOCK      => 0,
		);

		foreach ( $issues as $issue ) {
			if ( isset( $counts[ $issue['type'] ] ) ) {
				$counts[ $issue['type'] ]++;
			}
		}

		return $counts;
	}

	/**
	 * Return human-readable labels for issue types.
	 *
	 * @return array<string, string>
	 */
	public static function get_issue_labels(): array {
		return array(
			self::ISSUE_MISSING_IMAGE     => __( 'Missing Image', 'wc-product-health-check' ),
			self::ISSUE_EMPTY_SKU         => __( 'Empty SKU', 'wc-product-health-check' ),
			self::ISSUE_NO_PRODUCT_IMAGE  => __( 'No Product Image', 'wc-product-health-check' ),
			self::ISSUE_EMPTY_PRICE       => __( 'Empty Price', 'wc-product-health-check' ),
			self::ISSUE_MISSING_VAR_IMAGE => __( 'Missing Variation Image', 'wc-product-health-check' ),
			self::ISSUE_OUT_OF_STOCK      => __( 'Out of Stock / No Quantity', 'wc-product-health-check' ),
		);
	}
}
