<?php
if (!defined('ABSPATH')) {
    require_once __DIR__ . '/wp-load.php';
}

if (php_sapi_name() !== 'cli') {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die('Access denied', 'Access denied', array('response' => 403));
    }
}

$amount = 1000;

if (php_sapi_name() === 'cli') {
    $cli_args = array_slice($_SERVER['argv'], 1);
    $arg_count = count($cli_args);
    for ($idx = 0; $idx < $arg_count; $idx++) {
        $arg = $cli_args[$idx];

        if (strpos($arg, '-amount=') === 0 || strpos($arg, '--amount=') === 0) {
            $amount = (int) substr($arg, strpos($arg, '=') + 1);
            continue;
        }

        if ($arg === '-amount' || $arg === '--amount' || $arg === '-a') {
            if (isset($cli_args[$idx + 1])) {
                $amount = (int) $cli_args[$idx + 1];
                $idx++;
                continue;
            }

            fwrite(STDERR, "Error: Missing value for $arg\n");
            exit(1);
        }
    }

    if (! is_int($amount) || $amount < 1) {
        fwrite(STDERR, "Error: Amount must be a positive integer.\n");
        exit(1);
    }
}

global $wpdb;

// Get available product IDs and customer IDs
$products = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' LIMIT 1000");
$customers = $wpdb->get_col("SELECT ID FROM {$wpdb->users}");

if (empty($products)) {
    fwrite(STDERR, "Error: No products found. Please generate products first.\n");
    exit(1);
}

if (empty($customers)) {
    fwrite(STDERR, "Error: No customers found. Please create customers first.\n");
    exit(1);
}

function insert_order_direct_sql($product_ids, $customer_ids, $order_id, $now_gmt) {
    global $wpdb;

    $product_id = $product_ids[array_rand($product_ids)];
    $customer_id = $customer_ids[array_rand($customer_ids)];

    // Get product price
    $product_price = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price'",
        $product_id
    ));

    if ($product_price <= 0) {
        $product_price = rand(10, 100);
    }

    $quantity = rand(1, 3);
    $total = $product_price * $quantity;
    $tax = round($total * 0.1, 2);
    $total_with_tax = round($total + $tax, 2);

    // Insert order into HPOS table

    $result = $wpdb->insert(
        $wpdb->prefix . 'wc_orders',
        [
            'id'                     => $order_id,
            'status'                 => 'wc-completed',
            'currency'               => 'HUF',
            'type'                   => 'shop_order',
            'tax_amount'             => $tax,
            'total_amount'           => $total_with_tax,
            'customer_id'            => $customer_id,
            'billing_email'          => 'test' . $customer_id . '@test.com',
            'date_created_gmt'       => $now_gmt,
            'date_updated_gmt'       => $now_gmt,
            'payment_method'         => 'bacs',
            'payment_method_title'   => 'Direct Bank Transfer',
            'transaction_id'         => '',
            'ip_address'             => '192.168.1.1',
            'user_agent'             => 'Mozilla/5.0',
            'customer_note'          => '',
        ],
        [
            '%d', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ]
    );

    if ($result === false) {
        error_log("Error inserting order: " . $wpdb->last_error);
        exit(1);
    }

    // Insert order metadata into wc_order_meta
    // Table structure:

    $order_meta = [
        '_order_currency'        => 'USD',
        '_billing_first_name'    => 'Test',
        '_billing_last_name'     => 'Customer',
        '_billing_address_1'     => '123 Test Street',
        '_billing_city'          => 'Test City',
        '_billing_postcode'      => '12345',
        '_billing_country'       => 'US',
        '_billing_state'         => 'CA',
        '_billing_email'         => 'test' . $customer_id . '@test.com',
        '_billing_phone'         => '5551234567',
        '_shipping_first_name'   => 'Test',
        '_shipping_last_name'    => 'Customer',
        '_shipping_address_1'    => '123 Test Street',
        '_shipping_city'         => 'Test City',
        '_shipping_postcode'     => '12345',
        '_shipping_country'      => 'US',
        '_shipping_state'        => 'CA',
        '_cart_hash'             => '',
        '_customer_note'         => '',
        '_payment_method'        => 'bacs',
        '_payment_method_title'  => 'Direct Bank Transfer',
    ];

    $meta_values = [];
    $meta_placeholders = [];
    foreach ($order_meta as $key => $value) {
        $meta_values[] = $order_id;
        $meta_values[] = $key;
        $meta_values[] = $value;
        $meta_placeholders[] = '(%d, %s, %s)';
    }
    $sql = "INSERT INTO {$wpdb->prefix}wc_orders_meta (order_id, meta_key, meta_value) VALUES " . implode(', ', $meta_placeholders);
    $result = $wpdb->query($wpdb->prepare($sql, $meta_values));
    if ($result === false) {
        fwrite(STDERR, "Error inserting order meta for order $order_id: " . $wpdb->last_error . "\n");
        exit(1);
    }

    // Insert billing address
    // Table structure:

    $result = $wpdb->insert(
        $wpdb->prefix . 'wc_order_addresses',
        [
            'order_id'     => $order_id,
            'address_type' => 'billing',
            'first_name'   => 'Test',
            'last_name'    => 'Customer',
            'company'      => '',
            'address_1'    => '123 Test Street',
            'address_2'    => '',
            'city'         => 'Test City',
            'state'        => 'CA',
            'postcode'     => '12345',
            'country'      => 'US',
            'email'        => 'test' . $customer_id . '@test.com',
            'phone'        => '5551234567',
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );
    if ($result === false) {
        fwrite(STDERR, "Error inserting billing address for order $order_id: " . $wpdb->last_error . "\n");
        exit(1);
    }

    // Insert shipping address
    $result = $wpdb->insert(
        $wpdb->prefix . 'wc_order_addresses',
        [
            'order_id'     => $order_id,
            'address_type' => 'shipping',
            'first_name'   => 'Test',
            'last_name'    => 'Customer',
            'company'      => '',
            'address_1'    => '123 Test Street',
            'address_2'    => '',
            'city'         => 'Test City',
            'state'        => 'CA',
            'postcode'     => '12345',
            'country'      => 'US',
            'email'        => '',
            'phone'        => '',
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );
    if ($result === false) {
        fwrite(STDERR, "Error inserting shipping address for order $order_id: " . $wpdb->last_error . "\n");
        exit(1);
    }

    // Insert order item
    // Table structure:

    $result = $wpdb->insert(
        $wpdb->prefix . 'wc_order_product_lookup',
        [
            'order_id'              => $order_id,
            'product_id'            => $product_id,
            'variation_id'          => 0,
            'customer_id'           => $customer_id,
            'product_qty'           => $quantity,
            'product_net_revenue'   => $total - $tax,
            'product_gross_revenue' => $total,
            'coupon_amount'         => 0,
            'tax_amount'            => $tax,
            'shipping_amount'       => 0,
            'shipping_tax_amount'   => 0,
        ],
        ['%d', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%f']
    );
    if ($result === false) {
        fwrite(STDERR, "Error inserting order product lookup for order $order_id: " . $wpdb->last_error . "\n");
        exit(1);
    }

    return $order_id;
}

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);

$max_id = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders");
$next_id = ($max_id ? $max_id + 1 : 1);

$now = current_time('mysql');
$now_gmt = get_gmt_from_date($now);

for ($i = 1; $i <= $amount; $i++) {
    insert_order_direct_sql($products, $customers, $next_id++, $now_gmt);

    if ($i % 5000 == 0) {
        echo "Inserted $i orders...\n";
        flush();
    }
}

$wpdb->query('COMMIT');

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);
$time_direct_sql = microtime(true) - $start;
echo "Direct SQL insert time for $amount orders: " . round($time_direct_sql, 3) . " seconds\n";
echo "Total orders inserted: $amount\n";

// Run post-insertion cleanup functions
echo "\nRunning post-insertion cleanup...\n";
$cleanup_start = microtime(true);

// Clear order-related transients
if (function_exists('wc_delete_shop_order_transients')) {
    wc_delete_shop_order_transients();
    echo "✓ Cleared shop order transients\n";
}

// Clear WooCommerce cache
if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::invalidate_cache_group('orders');
    WC_Cache_Helper::invalidate_cache_group('wc_orders');
    echo "✓ Invalidated WooCommerce order caches\n";
}

// General WordPress cache flush
wp_cache_flush();
echo "✓ Flushed WordPress cache\n";

// Update customer order counts
if (function_exists('wc_update_customer_order_meta')) {
    echo "Updating customer order statistics...\n";
    $customer_ids = $wpdb->get_col("SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_orders WHERE customer_id > 0");
    foreach ($customer_ids as $cust_id) {
        wc_update_customer_order_meta($cust_id);
    }
    echo "✓ Updated customer order meta for " . count($customer_ids) . " customers\n";
}

// Rebuild order stats if available
if (function_exists('wc_admin_get_order_stats') || class_exists('WC_Admin_Reports_Orders_Stats_Query')) {
    echo "Triggering order stats sync...\n";
    // WooCommerce Analytics will sync automatically via ActionScheduler
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('woocommerce_analytics_sync_orders');
        echo "✓ Enqueued analytics sync action\n";
    }
}

$cleanup_time = microtime(true) - $cleanup_start;
echo "\nCleanup completed in " . round($cleanup_time, 3) . " seconds\n";
echo "Total operation time: " . round($time_direct_sql + $cleanup_time, 3) . " seconds\n";
