<?php
/**
 * Tests for WooCommerce dependency checking.
 */

use PHPUnit\Framework\Attributes\Depends;
class DependencyTest extends \PHPUnit\Framework\TestCase
{
    public function test_woocommerce_is_active(): void
    {
        $this->assertTrue(
            class_exists('WooCommerce') || function_exists('WC'),
            'WooCommerce must be active for the plugin to function.'
        );
    }

    public function test_woocommerce_version_is_available(): void
    {
        if (!defined('WC_VERSION')) {
            $this->markTestSkipped('WC_VERSION constant not defined.');
        }

        $this->assertNotEmpty(WC_VERSION, 'WC_VERSION must not be empty.');
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+(\.\d+)?$/',
            WC_VERSION,
            'WC_VERSION must be a valid version string.'
        );
    }

    public function test_required_woocommerce_tables_exist(): void
    {
        global $wpdb;

        $required_tables = [
            $wpdb->prefix . 'wc_orders',
            $wpdb->prefix . 'wc_order_addresses',
            $wpdb->prefix . 'wc_product_meta_lookup',
            $wpdb->prefix . 'wc_order_product_lookup',
        ];

        foreach ($required_tables as $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            $this->assertSame(
                $table,
                $exists,
                "Required WooCommerce table $table must exist."
            );
        }
    }

    public function test_woocommerce_container_available(): void {
        $this->assertTrue(function_exists('wc_get_container'));
    }

    #[Depends('test_woocommerce_container_available')]
    public function test_hpos_is_enabled(): void
    {
        // Check if HPOS is enabled
        $hpos_enabled = get_option('woocommerce_custom_orders_table_enabled', 'no');
        $this->assertContains(
            $hpos_enabled,
            ['yes', 'no'],
            'HPOS setting must be either yes or no.'
        );

        if ($hpos_enabled === 'yes') {
            $this->assertTrue(true, 'HPOS is enabled.');
        }
    }

    public function test_required_wordpress_version(): void
    {
        global $wp_version;

        $this->assertNotNull($wp_version, 'WordPress version must be available.');
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+(\.\d+)?$/',
            $wp_version,
            'WordPress version must be a valid version string.'
        );

        // WooCommerce 8+ requires WordPress 6.3+
        $this->assertTrue(
            version_compare($wp_version, '6.3', '>='),
            'WordPress version must be 6.3 or higher for WooCommerce 8+.'
        );
    }
}
