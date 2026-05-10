<?php
/**
 * Tests that WP-CLI commands are properly registered.
 * These tests only run when WP-CLI is available.
 */

use PHPUnit\Framework\Attributes\Depends;
class WpCliTest extends \PHPUnit\Framework\TestCase
{
    public function test_wp_cli_is_available(): void
    {
        exec( 'wp --info 2>&1', $output, $code );

        $this->assertSame( 0, $code );
    }

    #[Depends('test_wp_cli_is_available')]
    public function test_generate_commands_accept_amount_argument(): void
    {

        // Verify the generate scripts accept --amount parameter
        $plugin_dir = dirname(__DIR__);

        $generate_products = file_get_contents($plugin_dir . '/generate_products.php');
        $this->assertStringContainsString('--amount', $generate_products, 'generate_products.php must accept --amount argument.');

        $generate_orders = file_get_contents($plugin_dir . '/generate_orders.php');
        $this->assertStringContainsString('--amount', $generate_orders, 'generate_orders.php must accept --amount argument.');
    }

    #[Depends('test_wp_cli_is_available')]
    public function test_delete_commands_accept_force_argument(): void
    {
        $plugin_dir = dirname(__DIR__);

        $delete_orders = file_get_contents($plugin_dir . '/delete_orders.php');
        $this->assertStringContainsString('--force', $delete_orders, 'delete_orders.php must accept --force argument.');
    }

    #[Depends('test_wp_cli_is_available')]
    public function test_count_command_outputs_stats(): void
    {
        $output = shell_exec('wp wc-bulk count 2>&1');
        $this->assertNotNull($output, 'Command should produce output.');

        $this->assertStringContainsString('Products:', $output);
        $this->assertStringContainsString('Orders:', $output);
        $this->assertStringContainsString('Customers:', $output);
    }
}
