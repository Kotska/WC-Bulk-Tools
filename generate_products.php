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

function insert_product_direct_sql($title, $price) {
    global $wpdb;

    $now   = current_time('mysql');
    $slug  = sanitize_title($title);
    $wpdb->insert(
        $wpdb->posts,
        [
            'post_author'           => 1,
            'post_date'             => $now,
            'post_date_gmt'         => get_gmt_from_date($now),
            'post_content'          => '',
            'post_title'            => $title,
            'post_excerpt'          => '',
            'post_status'           => 'publish',
            'comment_status'        => 'closed',
            'ping_status'           => 'closed',
            'post_name'             => $slug,
            'post_modified'         => $now,
            'post_modified_gmt'     => get_gmt_from_date($now),
            'post_type'             => 'product',
            'post_mime_type'        => '',
        ],
        [
            '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ]
    );

    $post_id = (int) $wpdb->insert_id;
    if (! $post_id) {
        return 0;
    }

    $meta = [
        '_regular_price' => $price,
        '_price'         => $price,
        '_visibility'    => 'visible',
        '_stock_status'  => 'instock',
    ];

    foreach ($meta as $key => $value) {
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id'    => $post_id,
                'meta_key'   => $key,
                'meta_value' => $value,
            ],
            ['%d', '%s', '%s']
        );
    }

    return $post_id;
}

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);
$insert_success = true;
for ($i = 1; $i <= $amount; $i++) {
    if (! insert_product_direct_sql("Test Product SQL $i", rand(10, 100))) {
        $insert_success = false;
        break;
    }
}

if ($insert_success) {
    $wpdb->query('COMMIT');
} else {
    $wpdb->query('ROLLBACK');
}

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);
$time_direct_sql = microtime(true) - $start;
echo "Direct SQL insert time for $amount products: " . round($time_direct_sql, 3) . " seconds\n";

$lookup_time = 0;
if (function_exists('wc_update_product_lookup_tables')) {
    $lookup_start = microtime(true);
    wc_update_product_lookup_tables();
    $lookup_time = microtime(true) - $lookup_start;
    echo "WooCommerce lookup rebuild time: " . round($lookup_time, 3) . " seconds\n";
} else {
    echo "WooCommerce lookup rebuild function not available.\n";
}

$total_direct_sql = $time_direct_sql + $lookup_time;
echo "Total direct SQL insert + lookup rebuild time: " . round($total_direct_sql, 3) . " seconds\n";
