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
$days       = 365;
$start_date = null; // YYYY-MM-DD
$end_date   = null; // YYYY-MM-DD
$currency   = null; // null = use WooCommerce default
$seed       = null;

if (php_sapi_name() === 'cli') {
    $cli_args = array_slice($_SERVER['argv'], 1);
    $arg_count = count($cli_args);

    $consume_value = function ($idx, $arg) use ($cli_args, $arg_count) {
        if (isset($cli_args[$idx + 1])) {
            return [$cli_args[$idx + 1], $idx + 1];
        }
        fwrite(STDERR, "Error: Missing value for $arg\n");
        exit(1);
    };

    for ($idx = 0; $idx < $arg_count; $idx++) {
        $arg = $cli_args[$idx];

        // --key=value form
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
                case '-currency': case '--currency':
                    $currency = $v; continue 2;
                case '-seed': case '--seed':
                    $seed = (int) $v; continue 2;
                default:
                    fwrite(STDERR, "Error: Unknown argument: $arg\n");
                    exit(1);
            }
        }

        // --key value form
        switch ($arg) {
            case '-amount': case '--amount': case '-a':
                list($v, $idx) = $consume_value($idx, $arg); $amount = (int) $v; break;
            case '-days': case '--days':
                list($v, $idx) = $consume_value($idx, $arg); $days = (int) $v; break;
            case '-start-date': case '--start-date':
                list($v, $idx) = $consume_value($idx, $arg); $start_date = $v; break;
            case '-end-date': case '--end-date':
                list($v, $idx) = $consume_value($idx, $arg); $end_date = $v; break;
            case '-currency': case '--currency':
                list($v, $idx) = $consume_value($idx, $arg); $currency = $v; break;
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

// Resolve date range.
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

// Resolve currency.
if ($currency === null) {
    $currency = function_exists('get_option') ? (string) get_option('woocommerce_currency', 'USD') : 'USD';
    if ($currency === '') {
        $currency = 'USD';
    }
}

// -----------------------------------------------------------------------------
// Pre-flight queries
// -----------------------------------------------------------------------------

global $wpdb;

$products = $wpdb->get_results(
    "SELECT p.ID AS id,
            COALESCE(NULLIF(pm.meta_value, ''), '0') AS price
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm
       ON pm.post_id = p.ID AND pm.meta_key = '_regular_price'
     WHERE p.post_type = 'product' AND p.post_status = 'publish'
     LIMIT 1000",
    ARRAY_A
);

$customers = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC"));

if (empty($products)) {
    fwrite(STDERR, "Error: No products found. Please generate products first.\n");
    exit(1);
}

if (empty($customers)) {
    fwrite(STDERR, "Error: No customers found. Please create customers first.\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Data pools and customer profiles
// -----------------------------------------------------------------------------

$status_pool   = wcbt_order_status_pool();
$payment_pool  = wcbt_payment_method_pool();
$first_names   = wcbt_first_name_pool();
$last_names    = wcbt_last_name_pool();
$email_domains = wcbt_email_domain_pool();

// Build Pareto weights aligned to the customer pool order.
list($pareto_cum, $pareto_total) = wcbt_build_pareto_weights(count($customers));

// Cache of customer profiles keyed by customer_id.
$customer_profiles = [];

// -----------------------------------------------------------------------------
// Order generation (in-memory)
// -----------------------------------------------------------------------------

$max_id  = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders");
$next_id = $max_id ? $max_id + 1 : 1;

echo "Generating $amount orders between "
    . gmdate('Y-m-d', $start_ts) . " and " . gmdate('Y-m-d', $end_ts)
    . " in $currency...\n";

$generated = []; // array of order descriptors

for ($i = 0; $i < $amount; $i++) {
    // Date
    $ts = wcbt_weighted_random_date($start_ts, $end_ts);
    // Random hour/minute/second within that day
    $ts = $ts - ($ts % 86400) + mt_rand(0, 86399);
    if ($ts > $end_ts) {
        $ts = $end_ts;
    }

    // Status
    $status = wcbt_weighted_pick($status_pool)['key'];

    // Payment
    $payment = wcbt_weighted_pick($payment_pool);

    // Customer (10% guest, 90% Pareto-picked registered)
    $is_guest = (mt_rand(1, 100) <= 10);
    if ($is_guest) {
        $customer_id = 0;
    } else {
        $rank = wcbt_pareto_pick(count($customers), $pareto_cum, $pareto_total);
        $customer_id = $customers[$rank];
    }

    // Product line
    $product = $products[array_rand($products)];
    $product_id = (int) $product['id'];
    $price = (float) $product['price'];
    if ($price <= 0) {
        $price = mt_rand(10, 100);
    }
    $quantity = mt_rand(1, 3);
    $subtotal = round($price * $quantity, 2);
    $tax = round($subtotal * 0.1, 2);
    $total = round($subtotal + $tax, 2);

    $generated[] = [
        'ts'          => $ts,
        'status'      => $status,
        'payment'     => $payment,
        'is_guest'    => $is_guest,
        'customer_id' => $customer_id,
        'product_id'  => $product_id,
        'quantity'    => $quantity,
        'subtotal'    => $subtotal,
        'tax'         => $tax,
        'total'       => $total,
    ];
}

// Sort chronologically so we can compute returning_customer correctly.
usort($generated, function ($a, $b) {
    return $a['ts'] <=> $b['ts'];
});

// Assign IDs in chronological order (so id roughly correlates with date).
foreach ($generated as $idx => $_) {
    $generated[$idx]['order_id'] = $next_id++;
}

// -----------------------------------------------------------------------------
// Resolve profiles + returning_customer flag + first-order date
// -----------------------------------------------------------------------------

$seen_keys = []; // 'u:<id>' or 'g:<email>' => first ts
$customer_first_seen = []; // user_id => first ts (registered only, for customer_lookup)
$customer_last_active = []; // user_id => last ts

foreach ($generated as $idx => $order) {
    if ($order['is_guest']) {
        $profile = wcbt_guest_profile($order['order_id'], $first_names, $last_names, $email_domains);
        $key = 'g:' . $profile['email'];
    } else {
        $profile = wcbt_customer_profile($order['customer_id'], $customer_profiles, $first_names, $last_names, $email_domains);
        $key = 'u:' . $order['customer_id'];
    }

    $is_returning = isset($seen_keys[$key]) ? 1 : 0;
    if (!isset($seen_keys[$key])) {
        $seen_keys[$key] = $order['ts'];
    }

    if (!$order['is_guest']) {
        if (!isset($customer_first_seen[$order['customer_id']]) || $order['ts'] < $customer_first_seen[$order['customer_id']]) {
            $customer_first_seen[$order['customer_id']] = $order['ts'];
        }
        if (!isset($customer_last_active[$order['customer_id']]) || $order['ts'] > $customer_last_active[$order['customer_id']]) {
            $customer_last_active[$order['customer_id']] = $order['ts'];
        }
    }

    $generated[$idx]['profile']      = $profile;
    $generated[$idx]['is_returning'] = $is_returning;
}

// -----------------------------------------------------------------------------
// Build batch row arrays
// -----------------------------------------------------------------------------

$rows_orders        = [];
$rows_meta          = [];
$rows_addresses     = [];
$rows_product_lookup = [];
$rows_order_stats   = [];

// Refund child rows (separate batches because parent_order_id differs).
$rows_refund_orders     = [];
$rows_refund_stats      = [];

$refund_id = $next_id; // refunds get IDs after all parent orders

foreach ($generated as $order) {
    $oid       = $order['order_id'];
    $ts        = $order['ts'];
    $date_gmt  = gmdate('Y-m-d H:i:s', $ts);
    $date_local = get_date_from_gmt($date_gmt);
    $status    = $order['status'];
    $payment   = $order['payment'];
    $profile   = $order['profile'];
    $cid       = $order['customer_id'];
    $tax       = $order['tax'];
    $subtotal  = $order['subtotal'];
    $total     = $order['total'];
    $qty       = $order['quantity'];

    // Date paid / completed depend on status
    $paid_statuses      = ['wc-completed', 'wc-processing', 'wc-refunded'];
    $completed_statuses = ['wc-completed', 'wc-refunded'];
    $date_paid_gmt      = in_array($status, $paid_statuses, true) ? $date_gmt : null;
    $date_completed_gmt = in_array($status, $completed_statuses, true) ? $date_gmt : null;

    // wc_orders row
    $rows_orders[] = [
        'id'                   => $oid,
        'status'               => $status,
        'currency'             => $currency,
        'type'                 => 'shop_order',
        'tax_amount'           => $tax,
        'total_amount'         => $total,
        'customer_id'          => $cid,
        'billing_email'        => $profile['email'],
        'date_created_gmt'     => $date_gmt,
        'date_updated_gmt'     => $date_gmt,
        'parent_order_id'      => 0,
        'payment_method'       => $payment['key'],
        'payment_method_title' => $payment['title'],
        'transaction_id'       => '',
        'ip_address'           => '192.168.1.1',
        'user_agent'           => 'Mozilla/5.0',
        'customer_note'        => '',
    ];

    // wc_orders_meta rows
    $meta = [
        '_order_currency'       => $currency,
        '_billing_first_name'   => $profile['first_name'],
        '_billing_last_name'    => $profile['last_name'],
        '_billing_address_1'    => '123 Main St',
        '_billing_city'         => 'Springfield',
        '_billing_postcode'     => '12345',
        '_billing_country'      => 'US',
        '_billing_state'        => 'CA',
        '_billing_email'        => $profile['email'],
        '_billing_phone'        => '5551234567',
        '_shipping_first_name'  => $profile['first_name'],
        '_shipping_last_name'   => $profile['last_name'],
        '_shipping_address_1'   => '123 Main St',
        '_shipping_city'        => 'Springfield',
        '_shipping_postcode'    => '12345',
        '_shipping_country'     => 'US',
        '_shipping_state'       => 'CA',
        '_cart_hash'            => '',
        '_customer_note'        => '',
        '_payment_method'       => $payment['key'],
        '_payment_method_title' => $payment['title'],
    ];
    foreach ($meta as $k => $v) {
        $rows_meta[] = [
            'order_id'   => $oid,
            'meta_key'   => $k,
            'meta_value' => $v,
        ];
    }

    // wc_order_addresses rows (billing + shipping)
    $rows_addresses[] = [
        'order_id'     => $oid,
        'address_type' => 'billing',
        'first_name'   => $profile['first_name'],
        'last_name'    => $profile['last_name'],
        'company'      => '',
        'address_1'    => '123 Main St',
        'address_2'    => '',
        'city'         => 'Springfield',
        'state'        => 'CA',
        'postcode'     => '12345',
        'country'      => 'US',
        'email'        => $profile['email'],
        'phone'        => '5551234567',
    ];
    $rows_addresses[] = [
        'order_id'     => $oid,
        'address_type' => 'shipping',
        'first_name'   => $profile['first_name'],
        'last_name'    => $profile['last_name'],
        'company'      => '',
        'address_1'    => '123 Main St',
        'address_2'    => '',
        'city'         => 'Springfield',
        'state'        => 'CA',
        'postcode'     => '12345',
        'country'      => 'US',
        'email'        => '',
        'phone'        => '',
    ];

    // wc_order_product_lookup row
    $rows_product_lookup[] = [
        'order_id'              => $oid,
        'product_id'            => $order['product_id'],
        'variation_id'          => 0,
        'customer_id'           => $cid,
        'product_qty'           => $qty,
        'product_net_revenue'   => $subtotal,
        'product_gross_revenue' => $total,
        'coupon_amount'         => 0,
        'tax_amount'            => $tax,
        'shipping_amount'       => 0,
        'shipping_tax_amount'   => 0,
    ];

    // wc_order_stats row (skip cancelled/failed/pending? WC includes all but excludes some in queries — we insert all so the data is there)
    $rows_order_stats[] = [
        'order_id'           => $oid,
        'parent_id'          => 0,
        'date_created'       => $date_local,
        'date_created_gmt'   => $date_gmt,
        'date_paid'          => $date_paid_gmt,
        'date_completed'     => $date_completed_gmt,
        'num_items_sold'     => $qty,
        'total_sales'        => $total,
        'tax_total'          => $tax,
        'shipping_total'     => 0,
        'net_total'          => $subtotal,
        'returning_customer' => $order['is_returning'],
        'status'             => $status,
        'customer_id'        => $cid,
    ];

    // Refund child for refunded orders
    if ($status === 'wc-refunded') {
        $rid = $refund_id++;
        $r_ts = $ts + mt_rand(86400, 14 * 86400);
        if ($r_ts > $end_ts) {
            $r_ts = $end_ts;
        }
        $r_gmt = gmdate('Y-m-d H:i:s', $r_ts);
        $r_local = get_date_from_gmt($r_gmt);

        $rows_refund_orders[] = [
            'id'                   => $rid,
            'status'               => 'wc-completed', // refund records have status wc-completed in WC
            'currency'             => $currency,
            'type'                 => 'shop_order_refund',
            'tax_amount'           => -$tax,
            'total_amount'         => -$total,
            'customer_id'          => $cid,
            'billing_email'        => $profile['email'],
            'date_created_gmt'     => $r_gmt,
            'date_updated_gmt'     => $r_gmt,
            'parent_order_id'      => $oid,
            'payment_method'       => $payment['key'],
            'payment_method_title' => $payment['title'],
            'transaction_id'       => '',
            'ip_address'           => '192.168.1.1',
            'user_agent'           => 'Mozilla/5.0',
            'customer_note'        => '',
        ];

        $rows_refund_stats[] = [
            'order_id'           => $rid,
            'parent_id'          => $oid,
            'date_created'       => $r_local,
            'date_created_gmt'   => $r_gmt,
            'date_paid'          => $r_gmt,
            'date_completed'     => $r_gmt,
            'num_items_sold'     => -$qty,
            'total_sales'        => -$total,
            'tax_total'          => -$tax,
            'shipping_total'     => 0,
            'net_total'          => -$subtotal,
            'returning_customer' => $order['is_returning'],
            'status'             => 'wc-refunded',
            'customer_id'        => $cid,
        ];
    }
}

// -----------------------------------------------------------------------------
// Customer lookup rows
// -----------------------------------------------------------------------------

$existing_lookup_ids = array_map('intval', $wpdb->get_col(
    "SELECT user_id FROM {$wpdb->prefix}wc_customer_lookup WHERE user_id IS NOT NULL"
));
$existing_lookup_set = array_flip($existing_lookup_ids);

$rows_customer_lookup = [];
foreach ($customer_first_seen as $uid => $first_ts) {
    if (isset($existing_lookup_set[$uid])) {
        continue;
    }
    if (!isset($customer_profiles[$uid])) {
        continue;
    }
    $profile = $customer_profiles[$uid];
    $last_ts = isset($customer_last_active[$uid]) ? $customer_last_active[$uid] : $first_ts;
    $rows_customer_lookup[] = [
        'user_id'          => $uid,
        'username'         => '',
        'first_name'       => $profile['first_name'],
        'last_name'        => $profile['last_name'],
        'email'            => $profile['email'],
        'date_last_active' => gmdate('Y-m-d H:i:s', $last_ts),
        'date_registered'  => gmdate('Y-m-d H:i:s', $first_ts),
        'country'          => 'US',
        'postcode'         => '12345',
        'city'             => 'Springfield',
        'state'            => 'CA',
    ];
}

// -----------------------------------------------------------------------------
// Flush batches
// -----------------------------------------------------------------------------

wp_suspend_cache_invalidation(true);
wp_defer_term_counting(true);
$wpdb->query('START TRANSACTION');
$start = microtime(true);

$tx_aborted = false;

$flush = function ($table_short, array $columns, array $formats, array &$rows, $chunk_size) use (&$tx_aborted) {
    global $wpdb;
    if ($tx_aborted) {
        return;
    }
    $table = $wpdb->prefix . $table_short;
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

// wc_orders
$flush(
    'wc_orders',
    ['id','status','currency','type','tax_amount','total_amount','customer_id','billing_email','date_created_gmt','date_updated_gmt','parent_order_id','payment_method','payment_method_title','transaction_id','ip_address','user_agent','customer_note'],
    ['%d','%s','%s','%s','%f','%f','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'],
    $rows_orders,
    500
);

// wc_orders_meta (smaller chunk size: many rows per order)
$flush(
    'wc_orders_meta',
    ['order_id','meta_key','meta_value'],
    ['%d','%s','%s'],
    $rows_meta,
    2000
);

// wc_order_addresses
$flush(
    'wc_order_addresses',
    ['order_id','address_type','first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'],
    ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'],
    $rows_addresses,
    1000
);

// wc_order_product_lookup
$flush(
    'wc_order_product_lookup',
    ['order_id','product_id','variation_id','customer_id','product_qty','product_net_revenue','product_gross_revenue','coupon_amount','tax_amount','shipping_amount','shipping_tax_amount'],
    ['%d','%d','%d','%d','%d','%f','%f','%f','%f','%f','%f'],
    $rows_product_lookup,
    500
);

// wc_order_stats
$flush(
    'wc_order_stats',
    ['order_id','parent_id','date_created','date_created_gmt','date_paid','date_completed','num_items_sold','total_sales','tax_total','shipping_total','net_total','returning_customer','status','customer_id'],
    ['%d','%d','%s','%s','%s','%s','%d','%f','%f','%f','%f','%d','%s','%d'],
    $rows_order_stats,
    500
);

// Refund parent records (must come after orders so FK-like relationships are sensible)
$flush(
    'wc_orders',
    ['id','status','currency','type','tax_amount','total_amount','customer_id','billing_email','date_created_gmt','date_updated_gmt','parent_order_id','payment_method','payment_method_title','transaction_id','ip_address','user_agent','customer_note'],
    ['%d','%s','%s','%s','%f','%f','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'],
    $rows_refund_orders,
    500
);

$flush(
    'wc_order_stats',
    ['order_id','parent_id','date_created','date_created_gmt','date_paid','date_completed','num_items_sold','total_sales','tax_total','shipping_total','net_total','returning_customer','status','customer_id'],
    ['%d','%d','%s','%s','%s','%s','%d','%f','%f','%f','%f','%d','%s','%d'],
    $rows_refund_stats,
    500
);

// wc_customer_lookup
$flush(
    'wc_customer_lookup',
    ['user_id','username','first_name','last_name','email','date_last_active','date_registered','country','postcode','city','state'],
    ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'],
    $rows_customer_lookup,
    500
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

$elapsed = microtime(true) - $start;
$refund_count = count($rows_refund_orders);
echo "Direct SQL insert time for $amount orders ($refund_count refunds): " . round($elapsed, 3) . " seconds\n";
echo "Customer lookup rows inserted: " . count($rows_customer_lookup) . "\n";

// -----------------------------------------------------------------------------
// Cache cleanup (unchanged)
// -----------------------------------------------------------------------------

echo "\nRunning post-insertion cleanup...\n";
$cleanup_start = microtime(true);

if (function_exists('wc_delete_shop_order_transients')) {
    wc_delete_shop_order_transients();
    echo "[OK] Cleared shop order transients\n";
}

if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::invalidate_cache_group('orders');
    WC_Cache_Helper::invalidate_cache_group('wc_orders');
    echo "[OK] Invalidated WooCommerce order caches\n";
}

wp_cache_flush();
echo "[OK] Flushed WordPress cache\n";

if (function_exists('wc_update_customer_order_meta')) {
    echo "Updating customer order statistics...\n";
    $customer_ids = $wpdb->get_col("SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_orders WHERE customer_id > 0");
    foreach ($customer_ids as $cust_id) {
        wc_update_customer_order_meta($cust_id);
    }
    echo "[OK] Updated customer order meta for " . count($customer_ids) . " customers\n";
}

if (function_exists('wc_admin_get_order_stats') || class_exists('WC_Admin_Reports_Orders_Stats_Query')) {
    echo "Triggering order stats sync...\n";
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('woocommerce_analytics_sync_orders');
        echo "[OK] Enqueued analytics sync action\n";
    }
}

$cleanup_time = microtime(true) - $cleanup_start;
echo "\nCleanup completed in " . round($cleanup_time, 3) . " seconds\n";
echo "Total operation time: " . round($elapsed + $cleanup_time, 3) . " seconds\n";
