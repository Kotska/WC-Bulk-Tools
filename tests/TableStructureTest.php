<?php
/**
 * Tests that verify WooCommerce database table structures match expected schemas.
 * These tables are used by the bulk generate/delete scripts.
 */

class TableStructureTest extends \PHPUnit\Framework\TestCase
{
    private static \wpdb $wpdb;
    private static string $prefix;

    public static function setUpBeforeClass(): void
    {
        global $wpdb;
        self::$wpdb = $wpdb;
        self::$prefix = $wpdb->prefix;
    }

    private static function describeTable(string $table): array
    {
        $results = self::$wpdb->get_results("DESCRIBE `$table`");
        self::assertNotNull($results, "Table `$table` does not exist.");

        $columns = [];
        foreach ($results as $col) {
            $columns[$col->Field] = $col;
        }
        return $columns;
    }

    /**
     * MySQL 8+ drops display width from integer types (bigint(20) -> bigint).
     */
    private static function normalizeType(string $type): string
    {
        return preg_replace('/^(bigint|int|smallint|mediumint)\(\d+\)/', '$1', $type);
    }

    /**
     * Normalize datetime defaults that vary across MySQL versions.
     * MySQL 5.x returns CURRENT_TIMESTAMP, MySQL 8 returns current_timestamp().
     */
    private static function normalizeDefault(?string $default): ?string
    {
        if ($default === null) {
            return null;
        }
        if (preg_match('/^current_timestamp(\(\))?$/i', trim($default))) {
            return 'CURRENT_TIMESTAMP';
        }
        return $default;
    }

    private static function assertColumn(array $columns, string $name, string $type, string $null, mixed $default, string $extra = '', array $alternateDefaults = []): void
    {
        self::assertArrayHasKey($name, $columns, "Column `$name` not found.");
        $col = $columns[$name];

        $actualType = self::normalizeType($col->Type);
        $expectedType = self::normalizeType($type);
        self::assertSame($expectedType, $actualType, "Column `$name`: expected type '$type', got '$col->Type'.");

        self::assertSame($null, $col->Null, "Column `$name`: expected Null='$null', got '$col->Null'.");

        $actualDefault = self::normalizeDefault($col->Default);
        $expectedDefault = self::normalizeDefault($default);
        $normalizedAlts = array_map([self::class, 'normalizeDefault'], $alternateDefaults);

        $defaultsOk = $expectedDefault === $actualDefault || in_array($actualDefault, $normalizedAlts, true);
        self::assertTrue($defaultsOk, "Column `$name`: expected Default=" . var_export($default, true) . ", got " . var_export($col->Default, true) . ".");

        if ($extra) {
            self::assertStringContainsString($extra, strtolower($col->Extra), "Column `$name`: expected Extra to contain '$extra', got '$col->Extra'.");
        }
    }

    // ─── wc_orders ───────────────────────────────────────────────────

    public function test_wc_orders_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_orders');

        self::assertColumn($columns, 'id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'status', 'varchar(20)', 'YES', null);
        self::assertColumn($columns, 'currency', 'varchar(10)', 'YES', null);
        self::assertColumn($columns, 'type', 'varchar(20)', 'YES', null);
        self::assertColumn($columns, 'tax_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'total_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'customer_id', 'bigint(20) unsigned', 'YES', null);
        self::assertColumn($columns, 'billing_email', 'varchar(320)', 'YES', null);
        self::assertColumn($columns, 'date_created_gmt', 'datetime', 'YES', null);
        self::assertColumn($columns, 'date_updated_gmt', 'datetime', 'YES', null);
        self::assertColumn($columns, 'parent_order_id', 'bigint(20) unsigned', 'YES', null);
        self::assertColumn($columns, 'payment_method', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'payment_method_title', 'text', 'YES', null);
        self::assertColumn($columns, 'transaction_id', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'ip_address', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'user_agent', 'text', 'YES', null);
        self::assertColumn($columns, 'customer_note', 'text', 'YES', null);

        $pk = self::$wpdb->get_results("SHOW KEYS FROM `" . self::$prefix . "wc_orders` WHERE Key_name = 'PRIMARY'");
        self::assertCount(1, $pk, 'wc_orders must have a PRIMARY KEY.');
        self::assertSame('id', $pk[0]->Column_name, 'wc_orders PRIMARY KEY must be on `id`.');
    }

    // ─── wc_order_addresses ──────────────────────────────────────────

    public function test_wc_order_addresses_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_order_addresses');

        self::assertColumn($columns, 'id', 'bigint(20) unsigned', 'NO', null, 'auto_increment');
        self::assertColumn($columns, 'order_id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'address_type', 'varchar(20)', 'YES', null);
        self::assertColumn($columns, 'first_name', 'text', 'YES', null);
        self::assertColumn($columns, 'last_name', 'text', 'YES', null);
        self::assertColumn($columns, 'company', 'text', 'YES', null);
        self::assertColumn($columns, 'address_1', 'text', 'YES', null);
        self::assertColumn($columns, 'address_2', 'text', 'YES', null);
        self::assertColumn($columns, 'city', 'text', 'YES', null);
        self::assertColumn($columns, 'state', 'text', 'YES', null);
        self::assertColumn($columns, 'postcode', 'text', 'YES', null);
        self::assertColumn($columns, 'country', 'text', 'YES', null);
        self::assertColumn($columns, 'email', 'varchar(320)', 'YES', null);
        self::assertColumn($columns, 'phone', 'varchar(100)', 'YES', null);

        $unique = self::$wpdb->get_results("SHOW KEYS FROM `" . self::$prefix . "wc_order_addresses` WHERE Key_name = 'address_type_order_id' AND Non_unique = 0");
        self::assertCount(2, $unique, 'wc_order_addresses must have a UNIQUE KEY on (address_type, order_id).');
    }

    // ─── wc_orders_meta ──────────────────────────────────────────────

    public function test_wc_orders_meta_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_orders_meta');

        self::assertColumn($columns, 'id', 'bigint(20) unsigned', 'NO', null, 'auto_increment');
        self::assertColumn($columns, 'order_id', 'bigint(20) unsigned', 'YES', null);
        self::assertColumn($columns, 'meta_key', 'varchar(255)', 'YES', null);
        self::assertColumn($columns, 'meta_value', 'text', 'YES', null);
    }

    // ─── wc_order_operational_data ───────────────────────────────────

    public function test_wc_order_operational_data_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_order_operational_data');

        self::assertColumn($columns, 'id', 'bigint(20) unsigned', 'NO', null, 'auto_increment');
        self::assertColumn($columns, 'order_id', 'bigint(20) unsigned', 'YES', null);
        self::assertColumn($columns, 'created_via', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'woocommerce_version', 'varchar(20)', 'YES', null);
        self::assertColumn($columns, 'prices_include_tax', 'tinyint(1)', 'YES', null);
        self::assertColumn($columns, 'coupon_usages_are_counted', 'tinyint(1)', 'YES', null);
        self::assertColumn($columns, 'download_permission_granted', 'tinyint(1)', 'YES', null);
        self::assertColumn($columns, 'cart_hash', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'new_order_email_sent', 'tinyint(1)', 'YES', null);
        self::assertColumn($columns, 'order_key', 'varchar(100)', 'YES', null);
        self::assertColumn($columns, 'order_stock_reduced', 'tinyint(1)', 'YES', null);
        self::assertColumn($columns, 'date_paid_gmt', 'datetime', 'YES', null);
        self::assertColumn($columns, 'date_completed_gmt', 'datetime', 'YES', null);
        self::assertColumn($columns, 'shipping_tax_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'shipping_total_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'discount_tax_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'discount_total_amount', 'decimal(26,8)', 'YES', null);
        self::assertColumn($columns, 'recorded_sales', 'tinyint(1)', 'YES', null);

        $unique = self::$wpdb->get_results("SHOW KEYS FROM `" . self::$prefix . "wc_order_operational_data` WHERE Key_name = 'order_id' AND Non_unique = 0");
        self::assertCount(1, $unique, 'wc_order_operational_data must have a UNIQUE KEY on order_id.');
    }

    // ─── wc_product_meta_lookup ──────────────────────────────────────

    public function test_wc_product_meta_lookup_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_product_meta_lookup');

        self::assertColumn($columns, 'product_id', 'bigint(20)', 'NO', null);
        self::assertColumn($columns, 'sku', 'varchar(100)', 'YES', '');
        self::assertColumn($columns, 'global_unique_id', 'varchar(100)', 'YES', '');
        self::assertColumn($columns, 'virtual', 'tinyint(1)', 'YES', '0');
        self::assertColumn($columns, 'downloadable', 'tinyint(1)', 'YES', '0');
        self::assertColumn($columns, 'min_price', 'decimal(19,4)', 'YES', null);
        self::assertColumn($columns, 'max_price', 'decimal(19,4)', 'YES', null);
        self::assertColumn($columns, 'onsale', 'tinyint(1)', 'YES', '0');
        self::assertColumn($columns, 'stock_quantity', 'double', 'YES', null);
        self::assertColumn($columns, 'stock_status', 'varchar(100)', 'YES', 'instock');
        self::assertColumn($columns, 'rating_count', 'bigint(20)', 'YES', '0');
        self::assertColumn($columns, 'average_rating', 'decimal(3,2)', 'YES', '0.00');
        self::assertColumn($columns, 'total_sales', 'bigint(20)', 'YES', '0');
        self::assertColumn($columns, 'tax_status', 'varchar(100)', 'YES', 'taxable');
        self::assertColumn($columns, 'tax_class', 'varchar(100)', 'YES', '');

        $pk = self::$wpdb->get_results("SHOW KEYS FROM `" . self::$prefix . "wc_product_meta_lookup` WHERE Key_name = 'PRIMARY'");
        self::assertCount(1, $pk, 'wc_product_meta_lookup must have a PRIMARY KEY.');
        self::assertSame('product_id', $pk[0]->Column_name, 'wc_product_meta_lookup PRIMARY KEY must be on `product_id`.');
    }

    // ─── wc_order_product_lookup ─────────────────────────────────────

    public function test_wc_order_product_lookup_table_structure(): void
    {
        $columns = self::describeTable(self::$prefix . 'wc_order_product_lookup');

        self::assertColumn($columns, 'order_item_id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'order_id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'product_id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'variation_id', 'bigint(20) unsigned', 'NO', null);
        self::assertColumn($columns, 'customer_id', 'bigint(20) unsigned', 'YES', null);
        self::assertColumn($columns, 'date_created', 'datetime', 'NO', null, '', ['CURRENT_TIMESTAMP', '1970-01-01 00:00:00']);
        self::assertColumn($columns, 'product_qty', 'int(11)', 'NO', null);
        self::assertColumn($columns, 'product_net_revenue', 'double', 'NO', '0');
        self::assertColumn($columns, 'product_gross_revenue', 'double', 'NO', '0');
        self::assertColumn($columns, 'coupon_amount', 'double', 'NO', '0');
        self::assertColumn($columns, 'tax_amount', 'double', 'NO', '0');
        self::assertColumn($columns, 'shipping_amount', 'double', 'NO', '0');
        self::assertColumn($columns, 'shipping_tax_amount', 'double', 'NO', '0');

        $pk = self::$wpdb->get_results("SHOW KEYS FROM `" . self::$prefix . "wc_order_product_lookup` WHERE Key_name = 'PRIMARY'");
        self::assertCount(2, $pk, 'wc_order_product_lookup must have a composite PRIMARY KEY on (order_item_id, order_id).');
        self::assertSame('order_item_id', $pk[0]->Column_name);
        self::assertSame('order_id', $pk[1]->Column_name);
    }
}
