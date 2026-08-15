<?php
/**
 * WooCommerce products query layer.
 *
 * Filters supported:
 *   - product_cat_ids[]  (term IDs for product_cat taxonomy)
 *   - product_tag_ids[]  (term IDs for product_tag taxonomy)
 *
 * Always queries published products only — product status is not exposed as a client filter.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Data_Products {

	public static function get( array $filters ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$args = [
			'status'  => [ 'publish' ],
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		];

		$tax_query = self::build_tax_query( $filters );
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$products = wc_get_products( $args );

		$rows = [];
		foreach ( $products as $product ) {
			$rows[] = self::format_row( $product );
		}

		return $rows;
	}

	public static function build_tax_query( array $filters ): array {
		$cat_ids = self::clean_ids( $filters['product_cat_ids'] ?? [] );
		$tag_ids = self::clean_ids( $filters['product_tag_ids'] ?? [] );

		$clauses = [];

		if ( ! empty( $cat_ids ) ) {
			$clauses[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cat_ids,
				'operator' => 'IN',
			];
		}

		if ( ! empty( $tag_ids ) ) {
			$clauses[] = [
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $tag_ids,
				'operator' => 'IN',
			];
		}

		if ( count( $clauses ) > 1 ) {
			array_unshift( $clauses, 'OR' );
			$clauses['relation'] = 'OR';
		}

		return $clauses;
	}

	public static function product_ids_in_taxonomy( array $cat_ids, array $tag_ids ): array {
		$cat_ids = self::clean_ids( $cat_ids );
		$tag_ids = self::clean_ids( $tag_ids );

		if ( empty( $cat_ids ) && empty( $tag_ids ) ) {
			return [];
		}

		$tax_query = self::build_tax_query(
			[
				'product_cat_ids' => $cat_ids,
				'product_tag_ids' => $tag_ids,
			]
		);

		$ids = wc_get_products(
			[
				'status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'limit'     => -1,
				'return'    => 'ids',
				'tax_query' => $tax_query,
			]
		);

		return array_map( 'intval', (array) $ids );
	}

	private static function format_row( $product ): array {
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );

		$created  = $product->get_date_created();
		$modified = $product->get_date_modified();
		$dt_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return [
			'Product ID'      => $product->get_id(),
			'SKU'             => $product->get_sku(),
			'Name'            => $product->get_name(),
			'Type'            => $product->get_type(),
			'Status'          => $product->get_status(),
			'Regular Price'   => wc_format_decimal( $product->get_regular_price(), 2 ),
			'Sale Price'      => wc_format_decimal( $product->get_sale_price(), 2 ),
			'Stock Status'    => $product->get_stock_status(),
			'Stock Quantity'  => $product->get_stock_quantity(),
			'Categories'      => is_array( $cats ) ? implode( ' | ', $cats ) : '',
			'Tags'            => is_array( $tags ) ? implode( ' | ', $tags ) : '',
			'Total Sales'     => (int) $product->get_total_sales(),
			'Date Created'    => $created ? $created->date_i18n( $dt_fmt ) : '',
			'Date Modified'   => $modified ? $modified->date_i18n( $dt_fmt ) : '',
			'Permalink'       => get_permalink( $product->get_id() ),
		];
	}

	public static function clean_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [ $raw ];
		}
		$out = [];
		foreach ( $raw as $v ) {
			$id = (int) $v;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
