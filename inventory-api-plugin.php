<?php
/**
 * Plugin Name:       B2B Inventory Viewer API
 * Description:       A small, read-only WooCommerce inventory API built for the accompanying portfolio demo.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Dimitris Nimas
 * License:           MIT
 * License URI:       https://opensource.org/license/mit
 * Text Domain:       b2b-inventory-viewer
 */

namespace DimitrisNimas\InventoryViewer;

use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private const REST_NAMESPACE   = 'inventory-viewer/v1';
	private const DEFAULT_PER_PAGE = 24;
	private const MAX_PER_PAGE     = 50;

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'admin_notices', array( self::class, 'render_dependency_notice' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_products' ),
				'permission_callback' => array( self::class, 'can_view_inventory' ),
				'args'                => array(
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 0,
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1,
					),
					'per_page' => array(
						'default'           => self::DEFAULT_PER_PAGE,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= self::MAX_PER_PAGE,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_product' ),
				'permission_callback' => array( self::class, 'can_view_inventory' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_categories' ),
				'permission_callback' => array( self::class, 'can_view_inventory' ),
			)
		);
	}

	/**
	 * Keep the reference API private by default. Integrators can replace this
	 * decision with a narrowly scoped authentication policy through the filter.
	 *
	 * @return true|WP_Error
	 */
	public static function can_view_inventory( WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'inventory_viewer_woocommerce_missing',
				__( 'WooCommerce is not active.', 'b2b-inventory-viewer' ),
				array( 'status' => 503 )
			);
		}

		$allowed = current_user_can( 'manage_woocommerce' );
		$allowed = (bool) apply_filters( 'b2b_inventory_viewer_can_view_inventory', $allowed, $request );

		if ( $allowed ) {
			return true;
		}

		return new WP_Error(
			'inventory_viewer_forbidden',
			__( 'You are not allowed to view inventory data.', 'b2b-inventory-viewer' ),
			array( 'status' => 403 )
		);
	}

	public static function get_products( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$category = (int) $request->get_param( 'category' );

		$query_args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			's'                      => (string) $request->get_param( 'search' ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		if ( $category > 0 ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( $category ),
					'include_children' => true,
				),
			);
		}

		$query    = new WP_Query( $query_args );
		$products = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$products[] = self::format_product_summary( $product );
			}
		}

		return new WP_REST_Response(
			array(
				'products'    => $products,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_product( WP_REST_Request $request ) {
		$product_id = (int) $request->get_param( 'id' );
		$product    = wc_get_product( $product_id );

		if ( ! $product || 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return new WP_Error(
				'inventory_viewer_product_not_found',
				__( 'Product not found.', 'b2b-inventory-viewer' ),
				array( 'status' => 404 )
			);
		}

		$data                = self::format_product_summary( $product );
		$data['description'] = wp_strip_all_tags( $product->get_description() );
		$data['images']      = self::get_product_images( $product );

		return new WP_REST_Response( $data );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$categories = array_map(
			static function ( $term ): array {
				return array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'parent' => (int) $term->parent,
					'count'  => (int) $term->count,
				);
			},
			$terms
		);

		return new WP_REST_Response( $categories );
	}

	private static function format_product_summary( \WC_Product $product ): array {
		$data = array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'image'          => self::get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
			'prices'         => array( 'retail' => $product->get_price() ),
			'currency'       => get_woocommerce_currency(),
		);

		/**
		 * Add integration-specific, non-sensitive price tiers without coupling
		 * this portfolio plugin to a particular B2B extension.
		 */
		return (array) apply_filters( 'b2b_inventory_viewer_product_data', $data, $product );
	}

	private static function get_product_images( \WC_Product $product ): array {
		$image_ids = array_filter(
			array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() )
		);

		$images = array_map(
			static fn( int $image_id ): string => self::get_attachment_image_url( $image_id, 'large' ),
			array_map( 'absint', $image_ids )
		);

		return array_values( array_filter( $images ) );
	}

	private static function get_attachment_image_url( int $image_id, string $size ): string {
		$url = wp_get_attachment_image_url( $image_id, $size );

		return false === $url ? '' : $url;
	}

	public static function render_dependency_notice(): void {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'B2B Inventory Viewer API requires WooCommerce to be installed and active.', 'b2b-inventory-viewer' )
		);
	}
}

Plugin::boot();
