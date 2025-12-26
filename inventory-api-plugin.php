<?php
/**
 * Plugin Name: Fast Inventory REST API
 * Description: Lightweight, read-only inventory API for external live viewer.
 * Version: 1.1.0
 * Author: Dimitris Nimas
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// CONFIGURATION
define( 'INV_API_NAMESPACE', 'kubik/v1' );
define( 'INV_API_ROUTE', '/inventory' );
define( 'INV_API_KEY_HEADER', 'X-INVENTORY-KEY' );
define( 'INV_ACCCES_KEY', 'change_this_to_a_complex_random_string' );
define( 'INV_CACHE_TTL', 45 ); // seconds
define( 'INV_CACHE_KEY', 'fast_inv_data_cache' );

// B2B ROLES TO FETCH
global $inv_b2b_roles;
$inv_b2b_roles = ['customer', 'subscriber', 'b2b_gold', 'b2b_platinum']; 

add_action( 'rest_api_init', function () {
    register_rest_route( INV_API_NAMESPACE, INV_API_ROUTE, [
        'methods'             => 'GET',
        'callback'            => 'inv_get_inventory_data',
        'permission_callback' => 'inv_check_permission',
    ] );

    // Allow our custom API Key header in CORS pre-flight
    add_filter( 'rest_allowed_cors_headers', function( $allow_headers ) {
        $allow_headers[] = INV_API_KEY_HEADER;
        return $allow_headers;
    } );
} );

// Force allow origin for this endpoint (Frontend is on subdomain)
add_filter( 'rest_pre_serve_request', function( $served, $result, $request ) {
    if ( strpos( $request->get_route(), INV_API_ROUTE ) !== false ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Access-Control-Allow-Credentials: true' );
    }
    return $served;
}, 10, 3 );


/**
 * 1. Security Check
 */
function inv_check_permission( $request ) {
    // Increase Limits for large inventory
    @ini_set( 'memory_limit', '512M' );
    @set_time_limit( 300 );

    $auth_header = $request->get_header( INV_API_KEY_HEADER );
    if ( ! empty( $auth_header ) && hash_equals( INV_ACCCES_KEY, $auth_header ) ) {
        return true;
    }
    return new WP_Error( 'rest_forbidden', 'Unauthorized', [ 'status' => 401 ] );
}

/**
 * 2. Main Handler with Caching
 */
function inv_get_inventory_data( $request ) {
    // 1. Single Product Details (Modal)
    $id = $request->get_param('id');
    if ( ! empty( $id ) ) {
        return rest_ensure_response( inv_get_single_product( intval($id) ) );
    }

    // 2. Search Results
    $search = sanitize_text_field( $request->get_param('search') );
    if ( ! empty( $search ) ) {
        return rest_ensure_response( inv_generate_data( $search ) );
    }

    // 3. Default (Empty)
    return rest_ensure_response( [
        'timestamp' => current_time('mysql'),
        'count' => 0,
        'products' => [],
        'message' => 'Use search parameter'
    ] );
}

function inv_get_single_product( $id ) {
    if ( ! function_exists( 'wc_get_product' ) ) return ['error'=>'WC missing'];
    
    $product = wc_get_product( $id );
    if ( ! $product ) return ['error'=>'Not found'];
    
    global $inv_b2b_roles;

    // Gallery
    $gallery_ids = $product->get_gallery_image_ids();
    $images = [];
    if ( $product->get_image_id() ) {
        $images[] = wp_get_attachment_url( $product->get_image_id() );
    }
    foreach( $gallery_ids as $gid ) {
        $images[] = wp_get_attachment_url( $gid );
    }
    
    // Gnisios
    $gn = '';
    $terms = get_the_terms( $id, 'pa_gnisios_kodikos' );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        $gn = $terms[0]->name; 
    }

    // Prices
    $prices = [];
    $prices['retail'] = $product->get_price();

    // Group Prices
    $group_prices_raw = get_post_meta( $id, 'wcb2b_product_group_prices', true );
    if ( ! empty( $group_prices_raw ) && is_array( $group_prices_raw ) ) {
        foreach ( $group_prices_raw as $group_id => $g_data ) {
            if ( isset( $g_data['regular_price'] ) && '' !== $g_data['regular_price'] ) {
                $prices['Group ' . $group_id] = $g_data['regular_price'];
            }
        }
    }
    
    // Role Prices
    foreach ( $inv_b2b_roles as $role ) {
        $b2b_price = get_post_meta( $id, '_b2b_price_' . $role, true );
        if ( ! empty( $b2b_price ) ) $prices[$role] = $b2b_price;
    }

    return [
        'id'          => $product->get_id(),
        'name'        => $product->get_name(),
        'sku'         => $product->get_sku(),
        'gnisios'     => $gn,
        'description' => apply_filters( 'the_content', $product->get_description() ),
        'images'      => $images,
        'prices'      => $prices,
        'stock'       => $product->get_stock_quantity(),
        'status'      => $product->get_stock_status(),
    ];
}

/**
 * 3. Efficient Data Generation
 */
function inv_generate_data( $search_term = '' ) {
    global $inv_b2b_roles;
    
    if ( function_exists( 'wp_suspend_cache_addition' ) ) wp_suspend_cache_addition( true );
    if ( ! function_exists( 'wc_get_product' ) ) return [ 'error' => 'WooCommerce not active' ];

    // 0. Cache Check
    $cache_key = 'inv_search_' . md5( $search_term );
    $cached_ids = get_transient( $cache_key );
    if ( $cached_ids !== false ) {
        $found_ids = $cached_ids;
    } else {
        // 1. Database Search
        global $wpdb;
        $like_term = $wpdb->esc_like( $search_term ) . '%';
        $found_ids = [];

        // A: Title
        $title_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_title LIKE %s LIMIT 50",
            $like_term
        ) );
        $found_ids = array_merge( $found_ids, $title_ids );

        // B: SKU
        $sku_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s LIMIT 50",
            $like_term
        ) );
        $found_ids = array_merge( $found_ids, $sku_ids );

        // C: Attributes (pa_gnisios_kodikos, pa_antistixia)
        $attr_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tt.taxonomy IN ('pa_gnisios_kodikos','pa_antistixia') AND t.name LIKE %s LIMIT 50",
            $like_term
        ) );
        $found_ids = array_merge( $found_ids, $attr_ids );

        // D: Fallback to Description if empty
        if ( empty( $found_ids ) ) {
            $content_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_content LIKE %s LIMIT 50",
                '%' . $wpdb->esc_like( $search_term ) . '%'
            ) );
            $found_ids = array_merge( $found_ids, $content_ids );
        }

        $found_ids = array_unique( array_map( 'intval', $found_ids ) );
        $found_ids = array_slice( $found_ids, 0, 50 ); // Hard Limit

        // Cache for 5 minutes
        set_transient( $cache_key, $found_ids, 300 );
    }

    $results = [];

    foreach ( $found_ids as $p_id ) {
        try {
            $product = wc_get_product( $p_id );
            if ( ! $product ) continue;

            $item = [
                'id'    => $product->get_id(),
                'sku'   => $product->get_sku(),
                'name'  => $product->get_name(),
                'gn'    => '', 
                'img'   => get_the_post_thumbnail_url( $p_id, 'thumbnail' ),
                'stock' => $product->get_stock_quantity(),
                'status'=> $product->get_stock_status(),
                'prices'=> []
            ];

            if ( is_null( $item['stock'] ) ) {
                $item['stock'] = ($item['status'] === 'instock') ? '>50' : 0;
            }

            $terms = get_the_terms( $p_id, 'pa_gnisios_kodikos' );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $item['gn'] = $terms[0]->name; 
            }

            $item['prices']['retail'] = $product->get_price();

            // 1. Group Prices (wcb2b)
            $group_prices_raw = get_post_meta( $p_id, 'wcb2b_product_group_prices', true );
            
            if ( ! empty( $group_prices_raw ) && is_array( $group_prices_raw ) ) {
                foreach ( $group_prices_raw as $group_id => $g_data ) {
                    if ( isset( $g_data['regular_price'] ) && '' !== $g_data['regular_price'] ) {
                        $item['prices']['Group ' . $group_id] = $g_data['regular_price'];
                    }
                }
            }

            // 2. Old B2B Roles (Fallback)
            foreach ( $inv_b2b_roles as $role ) {
                $b2b_price = get_post_meta( $p_id, '_b2b_price_' . $role, true );
                if ( ! empty( $b2b_price ) ) {
                    $item['prices'][$role] = $b2b_price;
                }
            }

            $results[] = $item;
        } catch ( Exception $e ) { continue; }
    }

    return [
        'timestamp' => current_time( 'mysql' ),
        'count'     => count( $results ),
        'products'  => $results,
        'debug'     => [
            'term' => $search_term,
            'sql_like' => isset($like_term) ? $like_term : 'N/A',
            'ids_found' => isset($found_ids) ? count($found_ids) : 0,
            'ids_list' => isset($found_ids) ? array_slice($found_ids, 0, 5) : []
        ]
    ];
}

