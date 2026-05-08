<?php
/**
 * Tests that the plugin loads correctly and all required classes exist.
 */

use PHPUnit\Framework\Attributes\Depends;
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

    public function test_wc_bulk_tools_class_exists(): void
    {
        $this->assertTrue(class_exists('WC_Bulk_Tools'), 'WC_Bulk_Tools class must exist after plugin load.');
    }

    #[Depends('test_wc_bulk_tools_class_exists')]
    public function test_wc_bulk_tools_is_singleton(): void
    {
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
