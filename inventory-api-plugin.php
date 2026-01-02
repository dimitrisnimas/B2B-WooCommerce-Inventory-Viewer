<?php
/**
 * Plugin Name: Fast Inventory REST API
 * Description: Lightweight, read-only inventory API for external live viewer.
 * Version: 1.2.0
 * Author: Dimitris Nimas
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// CONFIGURATION
define( 'INV_API_NAMESPACE', 'kubik/v1' );
define( 'INV_API_ROUTE', '/inventory' );
define( 'INV_API_KEY_HEADER', 'X-INVENTORY-KEY' );
define( 'INV_ACCCES_KEY', 'CHANGE_THIS_TO_YOUR_SECURE_KEY' );
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

    // 2. Fetch Categories (Dropdown)
    if ( $request->get_param('action') === 'categories' ) {
        return rest_ensure_response( inv_get_all_categories() );
    }

    // 3. Search Results
    $search = sanitize_text_field( $request->get_param('search') );
    $cat_id = intval( $request->get_param('category') );
    $page   = intval( $request->get_param('page') );
    if ( $page < 1 ) $page = 1;

    // Allow if search is NOT empty OR if category IS selected
    if ( ! empty( $search ) || $cat_id > 0 ) {
        return rest_ensure_response( inv_generate_data( $search, $cat_id, $page ) );
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

    // Categories
    $cats = wc_get_product_category_list( $id, ', ' );

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
        // 'categories'  => $cats, // Reverted per request
        'gnisios'     => $gn,
        'description' => apply_filters( 'the_content', $product->get_description() ),
        'images'      => $images,
        'prices'      => $prices,
        'stock'       => $product->get_stock_quantity(),
        'status'      => $product->get_stock_status(),
    ];
}

function inv_get_all_categories() {
    $terms = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
    ] );

    if ( is_wp_error( $terms ) ) return [];

    $cats = [];
    foreach ( $terms as $t ) {
        $cats[] = [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'parent' => $t->parent,
            'count'  => $t->count
        ];
    }
    return $cats;
}

/**
 * 3. Efficient Data Generation
 */
function inv_generate_data( $search_term = '', $cat_id = 0, $page = 1 ) {
    global $inv_b2b_roles;
    $per_page = 50;

    if ( function_exists( 'wp_suspend_cache_addition' ) ) wp_suspend_cache_addition( true );
    if ( ! function_exists( 'wc_get_product' ) ) return [ 'error' => 'WooCommerce not active' ];

    // 0. Cache Check
    $cache_key = 'inv_search_' . md5( $search_term . '_' . $cat_id );
    $cached_ids = get_transient( $cache_key );
    
    $found_ids = [];

    if ( $cached_ids !== false ) {
        $found_ids = $cached_ids;
    } else {
        // 1. Database Search
        global $wpdb;
        $like_term = $wpdb->esc_like( $search_term ) . '%';
        $found_ids = [];

        // CAT FILTER PREP
        $cat_join = "";
        $cat_where = "";
        $ids_str = "";

        if ( $cat_id > 0 ) {
            // Get children
            $term_ids = get_term_children( $cat_id, 'product_cat' );
            $term_ids[] = $cat_id;
            $ids_str = implode( ',', array_map( 'intval', $term_ids ) );
            
            // We join term_relationships to check existence in these cats
            // Alias tr_c to avoid collision
            $cat_join = " INNER JOIN {$wpdb->term_relationships} tr_c ON ID = tr_c.object_id "; // ID is usually ambiguous, careful
            $cat_where = " AND tr_c.term_taxonomy_id IN ($ids_str) ";
        }



        // --- BROWSE MODE (No Search Term, Only Category) ---
        if ( empty( $search_term ) && $cat_id > 0 ) {
            $sql_browse = "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                WHERE p.post_type='product' AND p.post_status='publish' AND tr.term_taxonomy_id IN ($ids_str)
                LIMIT 2000"; // Increased Limit
            
            $found_ids = $wpdb->get_col( $sql_browse );
        }
        // --- SEARCH MODE (Has Search Term) ---
        else {
            // A: Title
            // NOTE: {$wpdb->posts} has column ID. 
            // If we join, we better clarify ID refers to posts.ID, but here we construct query manually.
            $sql_a = "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} ";
            if ($cat_id > 0) $sql_a .= " INNER JOIN {$wpdb->term_relationships} tr_c ON {$wpdb->posts}.ID = tr_c.object_id ";
            $sql_a .= " WHERE post_type='product' AND post_status='publish' AND post_title LIKE %s ";
            if ($cat_id > 0) $sql_a .= " AND tr_c.term_taxonomy_id IN ($ids_str) ";
            $sql_a .= " LIMIT 1000";

            $title_ids = $wpdb->get_col( $wpdb->prepare( $sql_a, $like_term ) );
            $found_ids = array_merge( $found_ids, $title_ids );

            // B: SKU
            // postmeta joined with posts? No, direct lookup on postmeta usually. 
            // But to filter by category, we need to join relationships OR filter results later.
            // Joining is efficient. 
            // SELECT post_id FROM postmeta ... id is post_id
            $sql_b = "SELECT post_id FROM {$wpdb->postmeta} ";
            if ($cat_id > 0) $sql_b .= " INNER JOIN {$wpdb->term_relationships} tr_c ON {$wpdb->postmeta}.post_id = tr_c.object_id ";
            $sql_b .= " WHERE meta_key='_sku' AND meta_value LIKE %s ";
            if ($cat_id > 0) $sql_b .= " AND tr_c.term_taxonomy_id IN ($ids_str) ";
            $sql_b .= " LIMIT 1000";

            $sku_ids = $wpdb->get_col( $wpdb->prepare( $sql_b, $like_term ) );
            $found_ids = array_merge( $found_ids, $sku_ids );

            // C: Attributes (pa_gnisios_kodikos, pa_antistixia)
            // tr is already used.
            // tr.object_id IS the product ID.
            $sql_c = "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id ";
            if ($cat_id > 0) $sql_c .= " INNER JOIN {$wpdb->term_relationships} tr_c ON tr.object_id = tr_c.object_id ";
            $sql_c .= " WHERE tt.taxonomy IN ('pa_gnisios_kodikos','pa_antistixia') AND t.name LIKE %s ";
            if ($cat_id > 0) $sql_c .= " AND tr_c.term_taxonomy_id IN ($ids_str) ";
            $sql_c .= " LIMIT 500";

            $attr_ids = $wpdb->get_col( $wpdb->prepare( $sql_c, $like_term ) );
            $found_ids = array_merge( $found_ids, $attr_ids );

            // D: Fallback to Description if empty
            if ( empty( $found_ids ) ) {
                $sql_d = "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} ";
                if ($cat_id > 0) $sql_d .= " INNER JOIN {$wpdb->term_relationships} tr_c ON {$wpdb->posts}.ID = tr_c.object_id ";
                $sql_d .= " WHERE post_type='product' AND post_status='publish' AND post_content LIKE %s ";
                if ($cat_id > 0) $sql_d .= " AND tr_c.term_taxonomy_id IN ($ids_str) ";
                $sql_d .= " LIMIT 200";
                
                $content_ids = $wpdb->get_col( $wpdb->prepare( $sql_d, '%' . $wpdb->esc_like( $search_term ) . '%' ) );
                $found_ids = array_merge( $found_ids, $content_ids );
            }
        }

        $found_ids = array_unique( array_map( 'intval', $found_ids ) );
        // $found_ids = array_slice( $found_ids, 0, 50 ); // OLD Hard Limit - REMOVED
        
        // Cache for 5 minutes
        set_transient( $cache_key, $found_ids, 300 );
    }

    // --- PAGINATION SLICE ---
    $total_items = count( $found_ids );
    $total_pages = ceil( $total_items / $per_page );
    $offset      = ( $page - 1 ) * $per_page;
    
    // Slice just the IDs we need for this page
    $paged_ids   = array_slice( $found_ids, $offset, $per_page );

    $results = [];

    foreach ( $paged_ids as $p_id ) {
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

            // ... (price and attribute logic) ...

            if ( is_null( $item['stock'] ) ) {
                $item['stock'] = ($item['status'] === 'instock') ? '>50' : 0;
            }

            $terms = get_the_terms( $p_id, 'pa_gnisios_kodikos' );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $item['gn'] = $terms[0]->name; 
            }

            // Categories (Reverted per request)
            // $item['cats'] = wc_get_product_category_list( $p_id, ', ' );

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
        'count'     => $total_items, // Total Found (not just this page)
        'total_pages' => $total_pages,
        'current_page' => $page,
        'products'  => $results,
        'debug'     => [
            'term' => $search_term,
            'sql_like' => isset($like_term) ? $like_term : 'N/A',
            'ids_found' => isset($found_ids) ? count($found_ids) : 0,
            // 'ids_list' => isset($found_ids) ? array_slice($found_ids, 0, 5) : []
        ]
    ];
}
