<?php
/*
Plugin Name: Product & Order Cleanup
Description: Adds functionality to bulk delete all products or orders with confirmation and progress tracking
Version: 1.2
Author: Your Name
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function poc_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>Product & Order Cleanup requires WooCommerce to be installed and activated.</p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Enqueue necessary scripts
add_action('admin_enqueue_scripts', 'poc_enqueue_scripts');
function poc_enqueue_scripts($hook) {
    if ('toplevel_page_product-order-cleanup' !== $hook) {
        return;
    }
    
    wp_enqueue_style('poc-styles', plugins_url('css/poc-styles.css', __FILE__));
    wp_enqueue_script('jquery');
    wp_enqueue_script('poc-ajax-script', plugins_url('js/poc-ajax.js', __FILE__), array('jquery'), '1.0', true);
    wp_localize_script('poc-ajax-script', 'poc_ajax_obj', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('poc_ajax_nonce')
    ));
}

// Add menu item under Tools
add_action('admin_menu', 'poc_add_admin_menu');
function poc_add_admin_menu() {
    add_menu_page(
        'Product & Order Cleanup',
        'Bulk Cleanup',
        'manage_options',
        'product-order-cleanup',
        'poc_admin_page',
        'dashicons-trash',
        30
    );
}

// Create the admin page
function poc_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="poc-container">
            <div class="poc-warning">
                <strong>Warning:</strong> These actions cannot be undone. Please backup your database before proceeding.
            </div>

            <!-- Delete Products Card -->
            <div class="poc-card">
                <h3>Delete All Products</h3>
                <p>This will remove all products, including:</p>
                <ul class="poc-list">
                    <li>All product data and meta</li>
                    <li>Product categories and tags</li>
                    <li>Product images (from media library)</li>
                    <li>Product variations</li>
                </ul>
                
                <div id="product-progress-container" class="poc-progress-container" style="display: none;">
                    <div class="poc-progress-wrapper">
                        <div id="product-progress-bar" class="poc-progress-bar" style="width: 0%"></div>
                    </div>
                    <p class="poc-progress-text">
                        Processed: <span id="product-processed">0</span> / <span id="product-total">0</span>
                        <br>
                        
                    </p>
                </div>
                
                <button type="button" id="delete-products-btn" class="poc-btn delete">
                    <span class="poc-spinner" style="display: none;"></span>
                    Delete All Products
                </button>
            </div>

            <!-- Delete Orders Card -->
            <div class="poc-card">
                <h3>Delete All Orders</h3>
                <p>This will remove all orders, including:</p>
                <ul class="poc-list">
                    <li>All order data and meta</li>
                    <li>Order items and notes</li>
                    <li>Customer order history</li>
                    <li>Related refunds and transactions</li>
                </ul>
                
                <div id="order-progress-container" class="poc-progress-container" style="display: none;">
                    <div class="poc-progress-wrapper">
                        <div id="order-progress-bar" class="poc-progress-bar" style="width: 0%"></div>
                    </div>
                    <p class="poc-progress-text">
                        Processed: <span id="order-processed">0</span> / <span id="order-total">0</span>
                        <br>
                        
                    </p>
                </div>
                
                <button type="button" id="delete-orders-btn" class="poc-btn delete">
                    <span class="poc-spinner" style="display: none;"></span>
                    Delete All Orders
                </button>
            </div>
        </div>
    </div>
    <?php
}

// AJAX handler for getting total counts
add_action('wp_ajax_poc_get_counts', 'poc_get_counts');
function poc_get_counts() {
    check_ajax_referer('poc_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $product_count = count(wc_get_products(array(
        'limit' => -1,
        'status' => 'any',
        'return' => 'ids',
    )));

    $order_count = count(wc_get_orders(array(
        'limit' => -1,
        'type' => 'shop_order',
        'return' => 'ids',
    )));
    
    wp_send_json_success(array(
        'product_count' => (int)$product_count,
        'order_count' => (int)$order_count
    ));
}

// AJAX handler for deleting products in batches
add_action('wp_ajax_poc_delete_products_batch', 'poc_delete_products_batch');
function poc_delete_products_batch() {
    check_ajax_referer('poc_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $batch_size = 40;
    
    // Get batch of products
    $products = wc_get_products(array(
        'status' => 'any',
        'limit' => $batch_size,
        'offset' => $offset,
    ));
    
    $deleted = 0;
    $skipped = 0;
    $skipped_ids = array();
    
    foreach ($products as $product) {
        try {
            $deletion_successful = true;
            
            // Delete variations first if it's a variable product
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation && !$variation->delete(true)) {
                        $deletion_successful = false;
                    }
                }
            }
            
            // Delete the product
            if ($deletion_successful && $product->delete(true)) {
                $deleted++;
            } else {
                $skipped++;
                $skipped_ids[] = array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name()
                );
            }
        } catch (Exception $e) {
            $skipped++;
            $skipped_ids[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'error' => $e->getMessage()
            );
            continue;
        }
    }

    // Log skipped products
    if (!empty($skipped_ids)) {
        error_log('Skipped products during bulk deletion: ' . print_r($skipped_ids, true));
    }
    
    wp_send_json_success(array(
        'deleted' => $deleted,
        'skipped' => $skipped,
        'skipped_ids' => $skipped_ids,
        'done' => count($products) < $batch_size
    ));
}

// AJAX handler for deleting orders in batches
add_action('wp_ajax_poc_delete_orders_batch', 'poc_delete_orders_batch');
function poc_delete_orders_batch() {
    check_ajax_referer('poc_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $batch_size = 40;
    
    // Get batch of orders
    $orders = wc_get_orders(array(
        'limit' => $batch_size,
        'offset' => $offset,
        'type' => 'shop_order',
    ));
    
    $deleted = 0;
    $skipped = 0;
    $skipped_ids = array();
    
    foreach ($orders as $order) {
        try {
            // Delete the order
            if ($order->delete(true)) {
                $deleted++;
            } else {
                $skipped++;
                $skipped_ids[] = array(
                    'id' => $order->get_id(),
                    'number' => $order->get_order_number()
                );
            }
        } catch (Exception $e) {
            $skipped++;
            $skipped_ids[] = array(
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'error' => $e->getMessage()
            );
            continue;
        }
    }

    // Log skipped orders
    if (!empty($skipped_ids)) {
        error_log('Skipped orders during bulk deletion: ' . print_r($skipped_ids, true));
    }
    
    wp_send_json_success(array(
        'deleted' => $deleted,
        'skipped' => $skipped,
        'skipped_ids' => $skipped_ids,
        'done' => count($orders) < $batch_size
    ));
}