<?php
/**
 * Bootstrap for dev-only tests - loads WordPress + WooCommerce
 * Includes the main test bootstrap and the plugin file.
 */

require_once __DIR__ . '\..\tests\bootstrap.php';

// Load the main plugin file so WC_Bulk_Tools class is available
$plugin_file = dirname(__DIR__) . '\wc-bulk-tools.php';
if (file_exists($plugin_file)) {
    require_once $plugin_file;
}
