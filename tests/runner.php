<?php
/**
 * Standalone table structure test runner.
 * Run with: php tests/runner.php
 * Requires WordPress + WooCommerce to be loaded.
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$prefix = $wpdb->prefix;

$passed = 0;
$failed = 0;

function test(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label" . ($detail ? " -- $detail" : '') . "\n";
        $failed++;
    }
}

function describe_table(string $table): array
{
    global $wpdb;
    $results = $wpdb->get_results("DESCRIBE `$table`");
    if ($results === null) {
        return [];
    }
    $columns = [];
    foreach ($results as $col) {
        $columns[$col->Field] = $col;
    }
    return $columns;
}

function normalize_type(string $type): string
{
    // MySQL 8+ drops display width from integer types (bigint(20) -> bigint, int(11) -> int)
    // but keeps it for tinyint(1) and non-integer types.
    return preg_replace('/^(bigint|int|smallint|mediumint)\(\d+\)/', '$1', $type);
}

function column_test(array $columns, string $name, string $type, string $null, $default, string $extra = '', array $alternate_defaults = []): bool
{
    if (!isset($columns[$name])) {
        echo "        Column `$name` not found.\n";
        return false;
    }
    $col = $columns[$name];
    $ok = true;

    $actual_type = normalize_type($col->Type);
    $expected_type = normalize_type($type);

    if ($actual_type !== $expected_type) {
        echo "        Type mismatch for `$name`: expected '$type', got '$col->Type'\n";
        $ok = false;
    }
    if ($col->Null !== $null) {
        echo "        Null mismatch for `$name`: expected '$null', got '$col->Null'\n";
        $ok = false;
    }
    // Compare defaults — allow alternate values (e.g. CURRENT_TIMESTAMP vs NULL for datetime)
    $expected_default = $default;
    $actual_default = $col->Default;
    $defaults_ok = ($expected_default === $actual_default) || in_array($actual_default, $alternate_defaults, true);
    if (!$defaults_ok) {
        echo "        Default mismatch for `$name`: expected " . var_export($expected_default, true) . ", got " . var_export($actual_default, true) . "\n";
        $ok = false;
    }
    if ($extra && stripos($col->Extra, $extra) === false) {
        echo "        Extra mismatch for `$name`: expected to contain '$extra', got '$col->Extra'\n";
        $ok = false;
    }
    return $ok;
}

function run_table_tests(string $table_name, array $expected_columns): int
{
    global $wpdb, $passed, $failed;
    $table = $wpdb->prefix . $table_name;
    $columns = describe_table($table);

    if (empty($columns)) {
        echo "  FAIL  $table_name -- Table does not exist or is not accessible.\n";
        $failed++;
        return 1;
    }

    echo "\n--- $table_name ---\n";

    $failures = 0;
    foreach ($expected_columns as $name => $def) {
        $ok = column_test($columns, $name, $def['type'], $def['null'], $def['default'] ?? null, $def['extra'] ?? '', $def['alternate_defaults'] ?? []);
        test("Column: `$name`", $ok);
        if (!$ok) {
            $failures++;
        }
    }

    return $failures;
}

// ============================================================
//  wc_orders
// ============================================================
run_table_tests('wc_orders', [
    'id'                => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'status'            => ['type' => 'varchar(20)',         'null' => 'YES'],
    'currency'          => ['type' => 'varchar(10)',         'null' => 'YES'],
    'type'              => ['type' => 'varchar(20)',         'null' => 'YES'],
    'tax_amount'        => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'total_amount'      => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'customer_id'       => ['type' => 'bigint(20) unsigned', 'null' => 'YES'],
    'billing_email'     => ['type' => 'varchar(320)',       'null' => 'YES'],
    'date_created_gmt'  => ['type' => 'datetime',            'null' => 'YES'],
    'date_updated_gmt'  => ['type' => 'datetime',            'null' => 'YES'],
    'parent_order_id'   => ['type' => 'bigint(20) unsigned', 'null' => 'YES'],
    'payment_method'    => ['type' => 'varchar(100)',        'null' => 'YES'],
    'payment_method_title' => ['type' => 'text',             'null' => 'YES'],
    'transaction_id'    => ['type' => 'varchar(100)',        'null' => 'YES'],
    'ip_address'        => ['type' => 'varchar(100)',        'null' => 'YES'],
    'user_agent'        => ['type' => 'text',                'null' => 'YES'],
    'customer_note'     => ['type' => 'text',                'null' => 'YES'],
]);

// ============================================================
//  wc_order_addresses
// ============================================================
run_table_tests('wc_order_addresses', [
    'id'            => ['type' => 'bigint(20) unsigned', 'null' => 'NO',  'extra' => 'auto_increment'],
    'order_id'      => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'address_type'  => ['type' => 'varchar(20)',         'null' => 'YES'],
    'first_name'    => ['type' => 'text',                 'null' => 'YES'],
    'last_name'     => ['type' => 'text',                 'null' => 'YES'],
    'company'       => ['type' => 'text',                 'null' => 'YES'],
    'address_1'     => ['type' => 'text',                 'null' => 'YES'],
    'address_2'     => ['type' => 'text',                 'null' => 'YES'],
    'city'          => ['type' => 'text',                 'null' => 'YES'],
    'state'         => ['type' => 'text',                 'null' => 'YES'],
    'postcode'      => ['type' => 'text',                 'null' => 'YES'],
    'country'       => ['type' => 'text',                 'null' => 'YES'],
    'email'         => ['type' => 'varchar(320)',         'null' => 'YES'],
    'phone'         => ['type' => 'varchar(100)',         'null' => 'YES'],
]);

// ============================================================
//  wc_orders_meta
// ============================================================
run_table_tests('wc_orders_meta', [
    'id'         => ['type' => 'bigint(20) unsigned', 'null' => 'NO',  'extra' => 'auto_increment'],
    'order_id'   => ['type' => 'bigint(20) unsigned', 'null' => 'YES'],
    'meta_key'   => ['type' => 'varchar(255)',         'null' => 'YES'],
    'meta_value' => ['type' => 'text',                 'null' => 'YES'],
]);

// ============================================================
//  wc_order_operational_data
// ============================================================
run_table_tests('wc_order_operational_data', [
    'id'                         => ['type' => 'bigint(20) unsigned', 'null' => 'NO',  'extra' => 'auto_increment'],
    'order_id'                   => ['type' => 'bigint(20) unsigned', 'null' => 'YES'],
    'created_via'                => ['type' => 'varchar(100)',        'null' => 'YES'],
    'woocommerce_version'        => ['type' => 'varchar(20)',         'null' => 'YES'],
    'prices_include_tax'         => ['type' => 'tinyint(1)',          'null' => 'YES'],
    'coupon_usages_are_counted'  => ['type' => 'tinyint(1)',          'null' => 'YES'],
    'download_permission_granted'=> ['type' => 'tinyint(1)',          'null' => 'YES'],
    'cart_hash'                  => ['type' => 'varchar(100)',        'null' => 'YES'],
    'new_order_email_sent'       => ['type' => 'tinyint(1)',          'null' => 'YES'],
    'order_key'                  => ['type' => 'varchar(100)',        'null' => 'YES'],
    'order_stock_reduced'        => ['type' => 'tinyint(1)',          'null' => 'YES'],
    'date_paid_gmt'              => ['type' => 'datetime',            'null' => 'YES'],
    'date_completed_gmt'         => ['type' => 'datetime',            'null' => 'YES'],
    'shipping_tax_amount'        => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'shipping_total_amount'      => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'discount_tax_amount'        => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'discount_total_amount'      => ['type' => 'decimal(26,8)',      'null' => 'YES'],
    'recorded_sales'             => ['type' => 'tinyint(1)',          'null' => 'YES'],
]);

// ============================================================
//  wc_product_meta_lookup
// ============================================================
run_table_tests('wc_product_meta_lookup', [
    'product_id'       => ['type' => 'bigint(20)',       'null' => 'NO'],
    'sku'              => ['type' => 'varchar(100)',      'null' => 'YES', 'default' => ''],
    'global_unique_id' => ['type' => 'varchar(100)',      'null' => 'YES', 'default' => ''],
    'virtual'          => ['type' => 'tinyint(1)',        'null' => 'YES', 'default' => '0'],
    'downloadable'     => ['type' => 'tinyint(1)',        'null' => 'YES', 'default' => '0'],
    'min_price'        => ['type' => 'decimal(19,4)',     'null' => 'YES', 'default' => null],
    'max_price'        => ['type' => 'decimal(19,4)',     'null' => 'YES', 'default' => null],
    'onsale'           => ['type' => 'tinyint(1)',        'null' => 'YES', 'default' => '0'],
    'stock_quantity'   => ['type' => 'double',            'null' => 'YES', 'default' => null],
    'stock_status'     => ['type' => 'varchar(100)',      'null' => 'YES', 'default' => 'instock'],
    'rating_count'     => ['type' => 'bigint(20)',        'null' => 'YES', 'default' => '0'],
    'average_rating'   => ['type' => 'decimal(3,2)',      'null' => 'YES', 'default' => '0.00'],
    'total_sales'      => ['type' => 'bigint(20)',        'null' => 'YES', 'default' => '0'],
    'tax_status'       => ['type' => 'varchar(100)',      'null' => 'YES', 'default' => 'taxable'],
    'tax_class'        => ['type' => 'varchar(100)',      'null' => 'YES', 'default' => ''],
]);

// ============================================================
//  wc_order_product_lookup
// ============================================================
run_table_tests('wc_order_product_lookup', [
    'order_item_id'         => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'order_id'              => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'product_id'            => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'variation_id'          => ['type' => 'bigint(20) unsigned', 'null' => 'NO'],
    'customer_id'           => ['type' => 'bigint(20) unsigned', 'null' => 'YES'],
    'date_created'          => ['type' => 'datetime',            'null' => 'NO',  'default' => null, 'alternate_defaults' => ['CURRENT_TIMESTAMP']],
    'product_qty'           => ['type' => 'int(11)',             'null' => 'NO'],
    'product_net_revenue'   => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
    'product_gross_revenue' => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
    'coupon_amount'         => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
    'tax_amount'            => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
    'shipping_amount'       => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
    'shipping_tax_amount'   => ['type' => 'double',              'null' => 'NO', 'default' => '0'],
]);

echo "\n========================================\n";
echo "Results: $passed passed, $failed failed\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
