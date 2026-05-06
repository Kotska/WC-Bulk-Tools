<?php
/**
 * Bootstrap for PHPUnit tests - loads WordPress + WooCommerce
 */

// Path to WordPress installation
// From: wp-content/plugins/wc-bulk-tools/tests/
// Go up 4 levels: wp-content/plugins/wc-bulk-tools -> wp-content/plugins -> wp-content -> WooCommerce root
$wp_root = dirname(__DIR__, 4);

require_once $wp_root . '/wp-load.php';

// Ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    echo "WooCommerce is not active. Tests require WooCommerce.\n";
    exit(1);
}
