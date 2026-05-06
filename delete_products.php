<?php
if (!defined('ABSPATH')) {
    require_once __DIR__ . '/wp-load.php';
}

if (php_sapi_name() !== 'cli') {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die('Access denied', 'Access denied', array('response' => 403));
    }
}

global $wpdb;

$post_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
    'product'
));

if (empty($post_ids)) {
    return 0;
}

$ids = implode(',', array_map('intval', $post_ids));

$deleted = 0;

echo "Starting deletion of " . count($post_ids) . " products...\n";
echo "Deleting postmeta...\n";
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids)");
echo "Deleting term relationships...\n";
$wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids)");
echo "Deleting posts...\n";
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($ids)");

echo "Cleaning up lookup tables...\n";
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation'))");

if ($wpdb->rows_affected !== null) {
    $deleted = count($post_ids);
}

echo "Deleted $deleted products.\n";
return $deleted;