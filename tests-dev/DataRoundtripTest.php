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
}
