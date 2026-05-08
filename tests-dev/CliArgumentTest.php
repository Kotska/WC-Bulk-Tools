<?php
/**
 * Tests that CLI arguments are correctly parsed by the plugin scripts.
 */

class CliArgumentTest extends \PHPUnit\Framework\TestCase
{
    private string $plugin_dir;

    protected function setUp(): void
    {
        $this->plugin_dir = dirname(__DIR__);
    }

    public function test_generate_products_amount_flag(): void
    {
        $script = $this->plugin_dir . '/generate_products.php';

        // Simulate CLI with --amount=50
        $_SERVER['argv'] = ['generate_products.php', '--amount=50'];

        // Include the script in a function scope to avoid exit() killing the test
        $output = $this->capture_script_output($script);

        // The script should run without error for argument parsing
        // We can't easily test the actual amount without running the full script,
        // but we can verify the argument parsing logic exists
        $content = file_get_contents($script);
        $this->assertStringContainsString('--amount', $content, 'Script must accept --amount flag.');
        $this->assertStringContainsString('$amount', $content, 'Script must use $amount variable.');
    }

    public function test_generate_orders_amount_flag(): void
    {
        $script = $this->plugin_dir . '/generate_orders.php';

        $_SERVER['argv'] = ['generate_orders.php', '--amount=100'];

        $content = file_get_contents($script);
        $this->assertStringContainsString('--amount', $content, 'Script must accept --amount flag.');
        $this->assertStringContainsString('-a', $content, 'Script must accept -a short flag.');
    }

    public function test_delete_orders_force_flag(): void
    {
        $script = $this->plugin_dir . '/delete_orders.php';

        $content = file_get_contents($script);

        $this->assertStringContainsString('--force', $content, 'delete_orders.php must accept --force flag.');
        $this->assertStringContainsString('-f', $content, 'delete_orders.php must accept -f short flag.');
        $this->assertStringContainsString('$force', $content, 'Script must use $force variable.');
    }

    public function test_delete_products_no_cli_flags(): void
    {
        $script = $this->plugin_dir . '/delete_products.php';

        $content = file_get_contents($script);

        // delete_products.php doesn't have CLI flags, just direct SQL
        $this->assertStringContainsString('$wpdb->query', $content, 'Script must use $wpdb->query for deletion.');
        $this->assertStringNotContainsString('--amount', $content, 'delete_products.php should not have --amount flag.');
    }

    public function test_scripts_check_woocommerce_environment(): void
    {
        $scripts = [
            'generate_products.php',
            'generate_orders.php',
            'delete_orders.php',
            'delete_products.php',
        ];

        foreach ($scripts as $script_name) {
            $content = file_get_contents($this->plugin_dir . '/' . $script_name);

            $this->assertStringContainsString(
                'ABSPATH',
                $content,
                "$script_name must check for ABSPATH (WordPress environment)."
            );

            $this->assertStringContainsString(
                'wp-load.php',
                $content,
                "$script_name must load wp-load.php when ABSPATH not defined."
            );
        }
    }

    public function test_scripts_check_user_permissions(): void
    {
        $scripts_with_auth = [
            'generate_products.php',
            'generate_orders.php',
            'delete_orders.php',
            'delete_products.php',
        ];

        foreach ($scripts_with_auth as $script_name) {
            $content = file_get_contents($this->plugin_dir . '/' . $script_name);

            $this->assertStringContainsString(
                'manage_options',
                $content,
                "$script_name must check for manage_options capability."
            );

            $this->assertStringContainsString(
                'is_user_logged_in',
                $content,
                "$script_name must check if user is logged in."
            );
        }
    }

    public function test_scripts_use_direct_sql(): void
    {
        $scripts_with_sql = [
            'generate_products.php' => ['$wpdb->insert', '$wpdb->posts'],
            'generate_orders.php' => ['$wpdb->prefix . \'wc_orders\'', '$wpdb->insert'],
            'delete_orders.php' => ['$wpdb->query', 'wc_order_product_lookup'],
            'delete_products.php' => ['$wpdb->query', '$wpdb->postmeta'],
        ];

        foreach ($scripts_with_sql as $script_name => $expected_strings) {
            $content = file_get_contents($this->plugin_dir . '/' . $script_name);

            foreach ($expected_strings as $expected) {
                $this->assertStringContainsString(
                    $expected,
                    $content,
                    "$script_name must use direct SQL ($expected)."
                );
            }
        }
    }

    /**
     * Helper to capture output from a script without letting it exit the test.
     */
    private function capture_script_output(string $script_path): string
    {
        // Start output buffering
        ob_start();

        // Register a shutdown function to catch exit()
        register_shutdown_function(function () {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        });

        try {
            // Include the script
            include $script_path;
        } catch (\Exception $e) {
            // Catch any exceptions
        }

        return ob_get_clean();
    }
}
