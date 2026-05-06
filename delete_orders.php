<?php
if (!defined('ABSPATH')) {
    require_once __DIR__ . '/wp-load.php';
}

if (php_sapi_name() !== 'cli') {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die('Access denied', 'Access denied', array('response' => 403));
    }
}

$force = false;

if (php_sapi_name() === 'cli') {
    $cli_args = array_slice($_SERVER['argv'], 1);
    $arg_count = count($cli_args);
    for ($idx = 0; $idx < $arg_count; $idx++) {
        $arg = $cli_args[$idx];

        if ($arg === '-force' || $arg === '--force' || $arg === '-f') {
            $force = true;
            continue;
        }

        if ($arg === '-h' || $arg === '--help') {
            echo "Usage: php delete_orders.php [OPTIONS]\n";
            echo "\nOptions:\n";
            echo "  -force, --force, -f    Skip confirmation prompt\n";
            echo "  -h, --help             Show this help message\n";
            exit(0);
        }
    }
}

global $wpdb;

// Get total order count
$total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");

if ($total_orders === 0) {
    echo "No orders found to delete.\n";
    exit(0);
}

// Confirmation prompt
if (!$force && php_sapi_name() === 'cli') {
    echo "⚠️  WARNING: You are about to delete ALL $total_orders orders!\n";
    echo "This action cannot be undone.\n";
    echo "Type 'yes' to confirm: ";
    $input = trim(fgets(STDIN));
    
    if ($input !== 'yes') {
        echo "Deletion cancelled.\n";
        exit(0);
    }
}

echo "Deleting $total_orders orders...\n\n";

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);

try {
    // Delete order product lookup
    echo "Deleting order product lookups...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_order_product_lookup");
    $lookup_count = $wpdb->rows_affected;
    echo "✓ Deleted $lookup_count product lookups\n";

    // Delete order addresses
    echo "Deleting order addresses...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_order_addresses");
    $addresses_count = $wpdb->rows_affected;
    echo "✓ Deleted $addresses_count addresses\n";

    // Delete order metadata
    echo "Deleting order metadata...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_order_meta");
    $meta_count = $wpdb->rows_affected;
    echo "✓ Deleted $meta_count meta entries\n";

    // Delete orders
    echo "Deleting orders...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders");
    $orders_count = $wpdb->rows_affected;
    echo "✓ Deleted $orders_count orders\n";

    $wpdb->query('COMMIT');
    echo "\n✓ Transaction committed successfully\n";
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    echo "\n✗ Error during deletion. Transaction rolled back.\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);
$time_delete = microtime(true) - $start;
echo "\nDeletion time: " . round($time_delete, 3) . " seconds\n";

// Clear caches
echo "\nClearing caches...\n";
$cache_start = microtime(true);

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

$cache_time = microtime(true) - $cache_start;
echo "\nCache clearing time: " . round($cache_time, 3) . " seconds\n";
echo "Total operation time: " . round($time_delete + $cache_time, 3) . " seconds\n";

echo "\n✓ All orders deleted successfully!\n";
