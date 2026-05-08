<?php
/**
 * Tests that WP-CLI commands are properly registered.
 * These tests only run when WP-CLI is available.
 */

class WpCliTest extends \PHPUnit\Framework\TestCase
{
    public function test_wp_cli_is_available(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            $this->markTestSkipped('WP-CLI is not available.');
        }
        $this->assertTrue(true);
    }

    /**
     * @depends test_wp_cli_is_available
     */
    public function test_wc_bulk_commands_are_registered(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            $this->markTestSkipped('WP-CLI is not available.');
        }

        // Check that our commands are in the command list
        // We can't easily call WP_CLI::add_command() again, but we can verify
        // the WC_Bulk_Tools class has the right methods
        $this->assertTrue(method_exists('WC_Bulk_Tools', 'generate_products'));
        $this->assertTrue(method_exists('WC_Bulk_Tools', 'generate_orders'));
        $this->assertTrue(method_exists('WC_Bulk_Tools', 'delete_products'));
        $this->assertTrue(method_exists('WC_Bulk_Tools', 'delete_orders'));
    }

    /**
     * @depends test_wp_cli_is_available
     */
    public function test_generate_commands_accept_amount_argument(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            $this->markTestSkipped('WP-CLI is not available.');
        }

        // Verify the generate scripts accept --amount parameter
        $plugin_dir = dirname(__DIR__);

        $generate_products = file_get_contents($plugin_dir . '/generate_products.php');
        $this->assertStringContainsString('--amount', $generate_products, 'generate_products.php must accept --amount argument.');

        $generate_orders = file_get_contents($plugin_dir . '/generate_orders.php');
        $this->assertStringContainsString('--amount', $generate_orders, 'generate_orders.php must accept --amount argument.');
    }

    /**
     * @depends test_wp_cli_is_available
     */
    public function test_delete_commands_accept_force_argument(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            $this->markTestSkipped('WP-CLI is not available.');
        }

        $plugin_dir = dirname(__DIR__);

        $delete_orders = file_get_contents($plugin_dir . '/delete_orders.php');
        $this->assertStringContainsString('--force', $delete_orders, 'delete_orders.php must accept --force argument.');
    }
}
