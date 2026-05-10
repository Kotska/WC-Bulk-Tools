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
    foreach ($cli_args as $arg) {
        if ($arg === '-force' || $arg === '--force' || $arg === '-f') {
            $force = true;
            continue;
        }
        if ($arg === '-h' || $arg === '--help') {
            echo "Usage: php delete_products.php [OPTIONS]\n";
            echo "\nOptions:\n";
            echo "  -force, --force, -f    Skip confirmation prompt\n";
            echo "  -h, --help             Show this help message\n";
            exit(0);
        }
    }
}

global $wpdb;

$post_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
);

$total = count($post_ids);

if ($total === 0) {
    echo "No products found to delete.\n";
    exit(0);
}

if (!$force && php_sapi_name() === 'cli') {
    echo "WARNING: You are about to delete ALL $total products (and variations)!\n";
    echo "This action cannot be undone.\n";
    echo "Type 'yes' to confirm: ";
    $input = trim(fgets(STDIN));
    if ($input !== 'yes') {
        echo "Deletion cancelled.\n";
        exit(0);
    }
}

echo "Deleting $total products...\n\n";

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);

// Collect tt_ids that will need recounting (any term currently linked to a product).
$touched_tt_ids = $wpdb->get_col(
    "SELECT DISTINCT tr.term_taxonomy_id
     FROM {$wpdb->term_relationships} tr
     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
     WHERE p.post_type IN ('product', 'product_variation')"
);

try {
    // Chunked deletion to avoid massive IN() lists.
    $chunks = array_chunk(array_map('intval', $post_ids), 5000);

    $meta_deleted = 0;
    $rel_deleted  = 0;
    $post_deleted = 0;

    echo "Deleting postmeta...\n";
    foreach ($chunks as $chunk) {
        $ids_csv = implode(',', $chunk);
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids_csv)");
        $meta_deleted += (int) $wpdb->rows_affected;
    }
    echo "[OK] Deleted $meta_deleted postmeta entries\n";

    echo "Deleting term relationships...\n";
    foreach ($chunks as $chunk) {
        $ids_csv = implode(',', $chunk);
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids_csv)");
        $rel_deleted += (int) $wpdb->rows_affected;
    }
    echo "[OK] Deleted $rel_deleted term relationships\n";

    echo "Deleting posts...\n";
    foreach ($chunks as $chunk) {
        $ids_csv = implode(',', $chunk);
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($ids_csv)");
        $post_deleted += (int) $wpdb->rows_affected;
    }
    echo "[OK] Deleted $post_deleted product posts\n";

    echo "Cleaning up wc_product_meta_lookup...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_product_meta_lookup
                  WHERE product_id NOT IN (
                      SELECT ID FROM {$wpdb->posts}
                      WHERE post_type IN ('product', 'product_variation')
                  )");
    $lookup_deleted = (int) $wpdb->rows_affected;
    echo "[OK] Deleted $lookup_deleted product meta lookup rows\n";

    echo "Cleaning up wc_product_attributes_lookup...\n";
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup
                  WHERE product_id NOT IN (
                      SELECT ID FROM {$wpdb->posts}
                      WHERE post_type IN ('product', 'product_variation')
                  )");
    $attr_deleted = (int) $wpdb->rows_affected;
    echo "[OK] Deleted $attr_deleted product attribute lookup rows\n";

    $wpdb->query('COMMIT');
    echo "\n[OK] Transaction committed successfully\n";
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    echo "\n[ERR] Error during deletion. Transaction rolled back.\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Recount term taxonomies that were touched.
echo "\nRecounting term taxonomies...\n";
$count_start = microtime(true);
foreach ($touched_tt_ids as $tt_id) {
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->term_taxonomy} tt
         SET count = (
             SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
         )
         WHERE tt.term_taxonomy_id = %d",
        (int) $tt_id
    ));
}
$count_time = microtime(true) - $count_start;
echo "[OK] Recounted " . count($touched_tt_ids) . " term taxonomies in " . round($count_time, 3) . "s\n";

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);

$delete_time = microtime(true) - $start;
echo "\nDeletion time: " . round($delete_time, 3) . " seconds\n";

// Cache cleanup.
echo "\nClearing caches...\n";
$cache_start = microtime(true);

if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::invalidate_cache_group('products');
    echo "[OK] Invalidated WooCommerce product caches\n";
}

wp_cache_flush();
echo "[OK] Flushed WordPress cache\n";

$cache_time = microtime(true) - $cache_start;
echo "\nCache clearing time: " . round($cache_time, 3) . " seconds\n";
echo "Total operation time: " . round($delete_time + $cache_time, 3) . " seconds\n";

echo "\n[OK] All products deleted successfully!\n";
