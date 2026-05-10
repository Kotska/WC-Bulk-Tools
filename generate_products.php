<?php
if (!defined('ABSPATH')) {
    require_once __DIR__ . '/wp-load.php';
}

if (php_sapi_name() !== 'cli') {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die('Access denied', 'Access denied', array('response' => 403));
    }
}

require_once __DIR__ . '/generate_helpers.php';

// -----------------------------------------------------------------------------
// CLI argument parsing
// -----------------------------------------------------------------------------

$amount     = 1000;
$days       = 730;
$start_date = null;
$end_date   = null;
$seed       = null;

if (php_sapi_name() === 'cli') {
    $cli_args = array_slice($_SERVER['argv'], 1);
    $arg_count = count($cli_args);

    $consume_value = function ($idx, $arg) use ($cli_args) {
        if (isset($cli_args[$idx + 1])) {
            return [$cli_args[$idx + 1], $idx + 1];
        }
        fwrite(STDERR, "Error: Missing value for $arg\n");
        exit(1);
    };

    for ($idx = 0; $idx < $arg_count; $idx++) {
        $arg = $cli_args[$idx];

        if (strpos($arg, '=') !== false && (strpos($arg, '-') === 0)) {
            list($k, $v) = explode('=', $arg, 2);
            switch ($k) {
                case '-amount': case '--amount': case '-a':
                    $amount = (int) $v; continue 2;
                case '-days': case '--days':
                    $days = (int) $v; continue 2;
                case '-start-date': case '--start-date':
                    $start_date = $v; continue 2;
                case '-end-date': case '--end-date':
                    $end_date = $v; continue 2;
                case '-seed': case '--seed':
                    $seed = (int) $v; continue 2;
                default:
                    fwrite(STDERR, "Error: Unknown argument: $arg\n");
                    exit(1);
            }
        }

        switch ($arg) {
            case '-amount': case '--amount': case '-a':
                list($v, $idx) = $consume_value($idx, $arg); $amount = (int) $v; break;
            case '-days': case '--days':
                list($v, $idx) = $consume_value($idx, $arg); $days = (int) $v; break;
            case '-start-date': case '--start-date':
                list($v, $idx) = $consume_value($idx, $arg); $start_date = $v; break;
            case '-end-date': case '--end-date':
                list($v, $idx) = $consume_value($idx, $arg); $end_date = $v; break;
            case '-seed': case '--seed':
                list($v, $idx) = $consume_value($idx, $arg); $seed = (int) $v; break;
            default:
                fwrite(STDERR, "Error: Unknown argument: $arg\n");
                exit(1);
        }
    }

    if (!is_int($amount) || $amount < 1) {
        fwrite(STDERR, "Error: Amount must be a positive integer.\n");
        exit(1);
    }
    if (!is_int($days) || $days < 1) {
        fwrite(STDERR, "Error: Days must be a positive integer.\n");
        exit(1);
    }
}

// Resolve dates.
$end_ts = $end_date !== null ? strtotime($end_date) : time();
if ($end_ts === false) {
    fwrite(STDERR, "Error: Invalid --end-date value.\n");
    exit(1);
}
if ($start_date !== null) {
    $start_ts = strtotime($start_date);
    if ($start_ts === false) {
        fwrite(STDERR, "Error: Invalid --start-date value.\n");
        exit(1);
    }
} else {
    $start_ts = $end_ts - ($days * 86400);
}
if ($start_ts >= $end_ts) {
    fwrite(STDERR, "Error: start-date must be earlier than end-date.\n");
    exit(1);
}

if ($seed !== null) {
    mt_srand($seed);
}

global $wpdb;

// -----------------------------------------------------------------------------
// Phase 1 - Taxonomy bootstrap (idempotent)
// -----------------------------------------------------------------------------

echo "Bootstrapping taxonomy...\n";
$tree = wcbt_category_tree();
$brand_pool = wcbt_brand_pool();
$adjectives = wcbt_product_adjectives();

// Build category map: index => ['top' => [...term info], 'subs' => [['cat' => subcat_def, 'term_id' => ..., 'tt_id' => ...], ...]].
$cat_map = [];
foreach ($tree as $cat) {
    list($cat_term_id, $cat_tt_id) = wcbt_get_or_create_term(
        $wpdb, $cat['name'], $cat['slug'], 'product_cat', 0, 0
    );

    $subs = [];
    foreach ($cat['subcategories'] as $sub) {
        list($sub_term_id, $sub_tt_id) = wcbt_get_or_create_term(
            $wpdb, $sub['name'], $sub['slug'], 'product_cat', $cat_tt_id, $cat_term_id
        );
        $subs[] = [
            'def'     => $sub,
            'term_id' => $sub_term_id,
            'tt_id'   => $sub_tt_id,
        ];
    }

    $cat_map[] = [
        'def'     => $cat,
        'term_id' => $cat_term_id,
        'tt_id'   => $cat_tt_id,
        'subs'    => $subs,
    ];
}

$brand_map = [];
foreach ($brand_pool as $brand) {
    $slug = sanitize_title($brand);
    list($b_term_id, $b_tt_id) = wcbt_get_or_create_term(
        $wpdb, $brand, $slug, 'product_tag', 0, 0
    );
    $brand_map[] = ['name' => $brand, 'term_id' => $b_term_id, 'tt_id' => $b_tt_id];
}

echo "[OK] " . count($cat_map) . " categories ready, "
    . array_sum(array_map(function ($c) { return count($c['subs']); }, $cat_map))
    . " subcategories, " . count($brand_map) . " brand tags.\n";

// -----------------------------------------------------------------------------
// Phase 2 - Generate product data in memory
// -----------------------------------------------------------------------------

echo "Generating $amount products between "
    . gmdate('Y-m-d', $start_ts) . " and " . gmdate('Y-m-d', $end_ts) . "...\n";

$max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
$next_id = $max_id ? $max_id + 1 : 1;

$tax_class_pool   = wcbt_tax_class_pool();
$stock_pool       = wcbt_stock_status_pool();
$product_type_pool = wcbt_product_type_pool();

// Pre-load existing slugs to avoid collisions on re-runs.
$used_slugs = [];
$existing_slugs = $wpdb->get_col("SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'product'");
foreach ($existing_slugs as $s) {
    $used_slugs[$s] = true;
}

// Pre-load existing SKUs to avoid collisions.
$used_skus = [];
$existing_skus = $wpdb->get_col(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value <> ''"
);
foreach ($existing_skus as $s) {
    $used_skus[$s] = true;
}

// SKU running counters per (cat-prefix, subcat-prefix).
$sku_counters = [];

$rows_posts             = [];
$rows_postmeta          = [];
$rows_term_relationships = [];

// Track tt_ids touched so we can recount later.
$touched_tt_ids = [];

for ($i = 0; $i < $amount; $i++) {
    // Pick category + subcategory uniformly.
    $cat = $cat_map[array_rand($cat_map)];
    $sub = $cat['subs'][array_rand($cat['subs'])];
    $cat_def = $cat['def'];
    $sub_def = $sub['def'];

    // Title + slug.
    $title = wcbt_build_title($cat_def, $adjectives);
    $base_slug = sanitize_title($title);
    $slug = wcbt_unique_slug($base_slug, $used_slugs);

    // Price.
    $regular = wcbt_pretty_price(
        wcbt_long_tail_price($cat_def['price_min'], $cat_def['price_max'], $cat_def['price_exp'])
    );
    $on_sale = (mt_rand(1, 100) <= 20);
    if ($on_sale) {
        $discount = mt_rand(10, 40) / 100.0;
        $sale_raw = ((float) $regular) * (1 - $discount);
        $sale = wcbt_pretty_price($sale_raw);
        if ((float) $sale >= (float) $regular) {
            $sale = number_format(max(0.01, (float) $regular - 1), 2, '.', '');
        }
        $effective_price = $sale;
    } else {
        $sale = '';
        $effective_price = $regular;
    }

    // Stock.
    $stock_pick = wcbt_weighted_pick($stock_pool);
    $stock_status = $stock_pick['status'];
    $manage_stock = $stock_pick['manage'];
    $stock_qty = ($manage_stock === 'yes' && $stock_pick['max'] > 0)
        ? mt_rand($stock_pick['min'], $stock_pick['max'])
        : ($manage_stock === 'yes' ? 0 : null);

    // Product type.
    $type_pick = wcbt_weighted_pick($product_type_pool);
    $virtual = $type_pick['virtual'];
    $downloadable = $type_pick['downloadable'];

    $featured = (mt_rand(1, 100) <= 8) ? 'yes' : 'no';

    // Tax class.
    $tax_class = wcbt_weighted_pick($tax_class_pool)['key'];

    // SKU (must be unique even across pre-existing).
    $sku_key = $cat_def['sku'] . '-' . $sub_def['sku'];
    if (!isset($sku_counters[$sku_key])) {
        $sku_counters[$sku_key] = 1;
    }
    do {
        $sku = wcbt_build_sku($cat_def['sku'], $sub_def['sku'], $sku_counters[$sku_key]);
        $sku_counters[$sku_key]++;
    } while (isset($used_skus[$sku]));
    $used_skus[$sku] = true;

    // Brand: 1-2 random tags.
    $brand_count = mt_rand(1, 2);
    $brand_picks = (array) array_rand($brand_map, $brand_count);
    if ($brand_count === 1) {
        $brand_picks = [$brand_picks[0]];
    }

    // Description.
    $description = wcbt_build_description($cat_def, $adjectives);
    $excerpt = explode('. ', $description)[0] . '.';

    // Date.
    $ts = mt_rand($start_ts, $end_ts);
    $gmt_date = gmdate('Y-m-d H:i:s', $ts);
    $local_date = get_date_from_gmt($gmt_date);

    $pid = $next_id++;

    // Posts row.
    $rows_posts[] = [
        'ID'                    => $pid,
        'post_author'           => 1,
        'post_date'             => $local_date,
        'post_date_gmt'         => $gmt_date,
        'post_content'          => $description,
        'post_title'            => $title,
        'post_excerpt'          => $excerpt,
        'post_status'           => 'publish',
        'comment_status'        => 'closed',
        'ping_status'           => 'closed',
        'post_password'         => '',
        'post_name'             => $slug,
        'to_ping'               => '',
        'pinged'                => '',
        'post_modified'         => $local_date,
        'post_modified_gmt'     => $gmt_date,
        'post_content_filtered' => '',
        'post_parent'           => 0,
        'guid'                  => '',
        'menu_order'            => 0,
        'post_type'             => 'product',
        'post_mime_type'        => '',
        'comment_count'         => 0,
    ];

    // Postmeta.
    $meta = [
        '_sku'                => $sku,
        '_regular_price'      => $regular,
        '_price'              => $effective_price,
        '_visibility'         => 'visible',
        '_stock_status'       => $stock_status,
        '_manage_stock'       => $manage_stock,
        '_backorders'         => 'no',
        '_sold_individually'  => 'no',
        '_virtual'            => $virtual,
        '_downloadable'       => $downloadable,
        '_featured'           => $featured,
        '_tax_status'         => 'taxable',
        '_tax_class'          => $tax_class,
        '_product_version'    => defined('WC_VERSION') ? WC_VERSION : '8.0.0',
        'total_sales'         => 0,
    ];
    if ($sale !== '') {
        $meta['_sale_price'] = $sale;
    }
    if ($manage_stock === 'yes' && $stock_qty !== null) {
        $meta['_stock'] = (string) $stock_qty;
    }

    foreach ($meta as $k => $v) {
        $rows_postmeta[] = [
            'post_id'    => $pid,
            'meta_key'   => $k,
            'meta_value' => $v,
        ];
    }

    // Term relationships: subcategory + parent category.
    $rows_term_relationships[] = [
        'object_id'        => $pid,
        'term_taxonomy_id' => $sub['tt_id'],
        'term_order'       => 0,
    ];
    $rows_term_relationships[] = [
        'object_id'        => $pid,
        'term_taxonomy_id' => $cat['tt_id'],
        'term_order'       => 0,
    ];
    $touched_tt_ids[$sub['tt_id']] = true;
    $touched_tt_ids[$cat['tt_id']] = true;

    foreach ($brand_picks as $bidx) {
        $b = $brand_map[$bidx];
        $rows_term_relationships[] = [
            'object_id'        => $pid,
            'term_taxonomy_id' => $b['tt_id'],
            'term_order'       => 0,
        ];
        $touched_tt_ids[$b['tt_id']] = true;
    }
}

// -----------------------------------------------------------------------------
// Phase 3 - Batch flush
// -----------------------------------------------------------------------------

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);

$tx_aborted = false;

$flush = function ($table_short, array $columns, array $formats, array &$rows, $chunk_size, $is_full_table = false) use (&$tx_aborted) {
    global $wpdb;
    if ($tx_aborted) {
        return;
    }
    $table = $is_full_table ? $table_short : ($wpdb->prefix . $table_short);
    $total = count($rows);
    if ($total === 0) {
        return;
    }
    for ($offset = 0; $offset < $total; $offset += $chunk_size) {
        $chunk = array_slice($rows, $offset, $chunk_size);
        $result = wcbt_flush_batch($wpdb, $table, $columns, $formats, $chunk);
        if ($result === false) {
            fwrite(STDERR, "Aborting: failed to insert into $table\n");
            $tx_aborted = true;
            return;
        }
    }
};

// wp_posts
$flush(
    $wpdb->posts,
    ['ID','post_author','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt','post_content_filtered','post_parent','guid','menu_order','post_type','post_mime_type','comment_count'],
    ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d'],
    $rows_posts,
    500,
    true
);

// wp_postmeta
$flush(
    $wpdb->postmeta,
    ['post_id','meta_key','meta_value'],
    ['%d','%s','%s'],
    $rows_postmeta,
    2000,
    true
);

// wp_term_relationships
$flush(
    $wpdb->term_relationships,
    ['object_id','term_taxonomy_id','term_order'],
    ['%d','%d','%d'],
    $rows_term_relationships,
    1000,
    true
);

if ($tx_aborted) {
    $wpdb->query('ROLLBACK');
    wp_suspend_cache_invalidation(false);
    wp_defer_term_counting(false);
    fwrite(STDERR, "Transaction rolled back due to insert errors.\n");
    exit(1);
}

$wpdb->query('COMMIT');

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);
$insert_time = microtime(true) - $start;
echo "Direct SQL insert time for $amount products: " . round($insert_time, 3) . " seconds\n";

// -----------------------------------------------------------------------------
// Phase 4 - Term counts + lookup rebuild
// -----------------------------------------------------------------------------

echo "Updating term counts...\n";
$count_start = microtime(true);
foreach (array_keys($touched_tt_ids) as $tt_id) {
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->term_taxonomy} tt
         SET count = (
             SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
         )
         WHERE tt.term_taxonomy_id = %d",
        $tt_id
    ));
}
$count_time = microtime(true) - $count_start;
echo "[OK] Recounted " . count($touched_tt_ids) . " term taxonomies in " . round($count_time, 3) . "s\n";

$lookup_time = 0;
if (function_exists('wc_update_product_lookup_tables')) {
    echo "Rebuilding WooCommerce product lookup tables...\n";
    $lookup_start = microtime(true);

    // Ensure synchronous execution. wc_update_product_lookup_tables() only
    // runs synchronously when WP_CLI is defined; otherwise it schedules
    // ActionScheduler jobs that may never run during this script's lifetime.
    if (!defined('WP_CLI')) {
        define('WP_CLI', true);
    }
    wc_update_product_lookup_tables();

    $lookup_time = microtime(true) - $lookup_start;
    echo "[OK] Rebuilt in " . round($lookup_time, 3) . " seconds\n";
} else {
    echo "WooCommerce lookup rebuild function not available.\n";
}

// -----------------------------------------------------------------------------
// Phase 5 - Cache cleanup
// -----------------------------------------------------------------------------

echo "\nRunning post-insertion cleanup...\n";
$cleanup_start = microtime(true);

if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::invalidate_cache_group('products');
    echo "[OK] Invalidated WooCommerce product caches\n";
}

wp_cache_flush();
echo "[OK] Flushed WordPress cache\n";

$cleanup_time = microtime(true) - $cleanup_start;
$total_time = $insert_time + $count_time + $lookup_time + $cleanup_time;
echo "\nCleanup completed in " . round($cleanup_time, 3) . " seconds\n";
echo "Total operation time: " . round($total_time, 3) . " seconds\n";
