<?php
/**
 * Tests that the plugin loads correctly and all required classes exist.
 */

class PluginLoadTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load the main plugin file so WC_Bulk_Tools class is available
        $plugin_file = dirname(__DIR__) . '/wc-bulk-tools.php';
        if (file_exists($plugin_file)) {
            require_once $plugin_file;
        }
    }

    public function test_plugin_file_exists(): void
    {
        $plugin_file = dirname(__DIR__) . '/wc-bulk-tools.php';
        $this->assertFileExists($plugin_file, 'Plugin main file must exist.');
    }

    public function test_plugin_loads_without_fatal_errors(): void
    {
        $plugin_file = dirname(__DIR__) . '/wc-bulk-tools.php';

        // Check syntax using full PHP path
        $php_path = 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe';
        if (!file_exists($php_path)) {
            $this->markTestSkipped('PHP executable not found at expected path.');
        }

        $output = [];
        $return_var = 0;
        exec('"' . $php_path . '" -l ' . escapeshellarg($plugin_file) . ' 2>&1', $output, $return_var);

        $this->assertSame(0, $return_var, 'Plugin file has syntax errors: ' . implode("\n", $output));
    }

    public function test_wc_bulk_tools_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Bulk_Tools'), 'WC_Bulk_Tools class must exist after plugin load.');
    }

    public function test_wc_bulk_tools_is_singleton(): void
    {
        if (!class_exists('WC_Bulk_Tools')) {
            $this->markTestSkipped('WC_Bulk_Tools class not loaded.');
        }

        $instance1 = WC_Bulk_Tools::instance();
        $instance2 = WC_Bulk_Tools::instance();

        $this->assertSame($instance1, $instance2, 'WC_Bulk_Tools::instance() must return the same instance.');
        $this->assertInstanceOf('WC_Bulk_Tools', $instance1);
    }

    public function test_required_files_exist(): void
    {
        $plugin_dir = dirname(__DIR__);

        $required_files = [
            'delete_orders.php',
            'delete_products.php',
            'generate_orders.php',
            'generate_products.php',
        ];

        foreach ($required_files as $file) {
            $this->assertFileExists(
                $plugin_dir . '/' . $file,
                "Required file $file must exist in plugin directory."
            );
        }
    }
}
