<?php
/**
 * Data roundtrip tests - verify the database operations work correctly.
 * Instead of including the scripts (which manage their own transactions),
 * we replicate the core logic here to test the table structures and operations.
 */

class DataRoundtripTest extends \PHPUnit\Framework\TestCase
{
    private static \wpdb $wpdb;
    private static int $original_product_count;
    private static int $original_order_count;

    public static function setUpBeforeClass(): void
    {
        global $wpdb;
        self::$wpdb = $wpdb;

        self::$original_product_count = (int) self::$wpdb->get_var(
            self::$wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'product')
        );

        self::$original_order_count = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"
        );
    }

    /**
     * Test creating and deleting products via direct SQL (same as generate_products.php).
     */
    public function test_create_and_delete_products(): void
    {
        global $wpdb;

        // Create 3 test products directly
        $test_product_ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $wpdb->insert(
                $wpdb->posts,
                [
                    'post_author' => 1,
                    'post_title' => "Test Roundtrip Product $i",
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'post_date' => current_time('mysql'),
                    'post_date_gmt' => get_gmt_from_date(current_time('mysql')),
                ]
            );
            $product_id = (int) $wpdb->insert_id;
            $test_product_ids[] = $product_id;

            // Add required meta
            foreach (['_regular_price', '_price'] as $meta_key) {
                $wpdb->insert(
                    $wpdb->postmeta,
                    [
                        'post_id' => $product_id,
                        'meta_key' => $meta_key,
                        'meta_value' => rand(10, 100),
                    ]
                );
            }
        }

        // Verify products were created
        $product_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'product')
        );
        $this->assertEquals(
            self::$original_product_count + 3,
            $product_count,
            '3 test products must be created.'
        );

        // Verify meta exists
        foreach ($test_product_ids as $product_id) {
            $price = $wpdb->get_var(
                $wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price'", $product_id)
            );
            $this->assertNotNull($price, "Product $product_id must have _price meta.");
        }

        // Now delete the test products
        $ids = implode(',', array_map('intval', $test_product_ids));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids)");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($ids)");

        // Verify deletion
        $product_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'product')
        );
        $this->assertEquals(
            self::$original_product_count,
            $product_count,
            'Test products must be deleted.'
        );
    }

    /**
     * Test creating and deleting orders via direct SQL (same as generate_orders.php).
     */
    public function test_create_and_delete_orders(): void
    {
        global $wpdb;

        // Check prerequisites
        $product_id = $wpdb->get_var(
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'product')
        );
        if (!$product_id) {
            $this->markTestSkipped('No products available to create test orders.');
        }

        $customer_id = $wpdb->get_var("SELECT ID FROM {$wpdb->users} LIMIT 1");
        if (!$customer_id) {
            $this->markTestSkipped('No customers available to create test orders.');
        }

        // Create a test order directly
        $now = current_time('mysql');
        $now_gmt = get_gmt_from_date($now);

        $wpdb->insert(
            $wpdb->prefix . 'wc_orders',
            [
                'status' => 'wc-completed',
                'currency' => 'USD',
                'type' => 'shop_order',
                'total_amount' => 100.00,
                'customer_id' => $customer_id,
                'billing_email' => "test$customer_id@test.com",
                'date_created_gmt' => $now_gmt,
                'date_updated_gmt' => $now_gmt,
            ]
        );
        $test_order_id = (int) $wpdb->insert_id;

        // Add billing address
        $wpdb->insert(
            $wpdb->prefix . 'wc_order_addresses',
            [
                'order_id' => $test_order_id,
                'address_type' => 'billing',
                'first_name' => 'Test',
                'last_name' => 'Customer',
            ]
        );

        // Add shipping address
        $wpdb->insert(
            $wpdb->prefix . 'wc_order_addresses',
            [
                'order_id' => $test_order_id,
                'address_type' => 'shipping',
                'first_name' => 'Test',
                'last_name' => 'Customer',
            ]
        );

        // Add order meta
        $wpdb->insert(
            $wpdb->prefix . 'wc_orders_meta',
            [
                'order_id' => $test_order_id,
                'meta_key' => '_billing_first_name',
                'meta_value' => 'Test',
            ]
        );

        // Add product lookup
        $wpdb->insert(
            $wpdb->prefix . 'wc_order_product_lookup',
            [
                'order_id' => $test_order_id,
                'product_id' => $product_id,
                'customer_id' => $customer_id,
                'product_qty' => 1,
                'product_net_revenue' => 100.00,
                'product_gross_revenue' => 100.00,
            ]
        );

        // Verify order was created
        $order_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
        $this->assertEquals(
            self::$original_order_count + 1,
            $order_count,
            'Test order must be created.'
        );

        // Verify addresses
        $address_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $test_order_id)
        );
        $this->assertEquals(2, $address_count, 'Order must have 2 addresses (billing + shipping).');

        // Now delete the test order (similar to delete_orders.php)
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_order_product_lookup WHERE order_id = %d", $test_order_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $test_order_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d", $test_order_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_orders WHERE id = %d", $test_order_id));

        // Verify deletion
        $order_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
        $this->assertEquals(
            self::$original_order_count,
            $order_count,
            'Test order must be deleted.'
        );

        $address_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $test_order_id)
        );
        $this->assertEquals(0, $address_count, 'Order addresses must be deleted.');
    }

    /**
     * Test that the delete_products.php script works correctly.
     * We create test products, run the delete logic, and verify.
     */
    public function test_delete_products_script_logic(): void
    {
        global $wpdb;

        // Create a test product
        $wpdb->insert(
            $wpdb->posts,
            [
                'post_author' => 1,
                'post_title' => 'Test Delete Product',
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_date' => current_time('mysql'),
                'post_date_gmt' => get_gmt_from_date(current_time('mysql')),
            ]
        );
        $test_product_id = (int) $wpdb->insert_id;

        // Add meta
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id' => $test_product_id,
                'meta_key' => '_price',
                'meta_value' => 50,
            ]
        );

        // Run the delete logic (same as delete_products.php)
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID = %d",
            'product',
            $test_product_id
        ));

        if (!empty($post_ids)) {
            $ids = implode(',', array_map('intval', $post_ids));
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids)");
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids)");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($ids)");
        }

        // Verify deletion
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
            $test_product_id
        ));
        $this->assertEquals(0, $exists, 'Test product must be deleted.');
    }

    /**
     * Test that the delete_orders.php script logic works.
     */
    public function test_delete_orders_script_logic(): void
    {
        global $wpdb;

        // Create minimal test order
        $customer_id = $wpdb->get_var("SELECT ID FROM {$wpdb->users} LIMIT 1") ?: 1;
        $now = current_time('mysql');
        $now_gmt = get_gmt_from_date($now);

        $wpdb->insert(
            $wpdb->prefix . 'wc_orders',
            [
                'status' => 'wc-completed',
                'type' => 'shop_order',
                'customer_id' => $customer_id,
                'billing_email' => 'test@test.com',
                'date_created_gmt' => $now_gmt,
                'date_updated_gmt' => $now_gmt,
            ]
        );
        $test_order_id = (int) $wpdb->insert_id;

        // Add address
        $wpdb->insert(
            $wpdb->prefix . 'wc_order_addresses',
            [
                'order_id' => $test_order_id,
                'address_type' => 'billing',
            ]
        );

        // Run delete logic (same as delete_orders.php)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_order_product_lookup WHERE order_id = %d",
            $test_order_id
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d",
            $test_order_id
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d",
            $test_order_id
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_orders WHERE id = %d",
            $test_order_id
        ));

        // Verify deletion
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d",
            $test_order_id
        ));
        $this->assertEquals(0, $exists, 'Test order must be deleted.');
    }
}
