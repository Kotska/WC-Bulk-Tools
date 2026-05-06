<?php
/**
 * Plugin Name: WooCommerce Bulk Tools
 * Description: WP-CLI commands and admin tools for bulk generating/deleting WooCommerce products and orders.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

if (!class_exists(WC_Bulk_Tools::class)) {
    final class WC_Bulk_Tools
    {
        private static ?WC_Bulk_Tools $instance = null;

        public static function instance(): WC_Bulk_Tools
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            if (defined('WP_CLI') && WP_CLI) {
                $this->register_cli_commands();
            }
        }

        private function register_cli_commands(): void
        {
            WP_CLI::add_command('wc-bulk generate products', $this->generate_products(...));
            WP_CLI::add_command('wc-bulk generate orders', $this->generate_orders(...));
            WP_CLI::add_command('wc-bulk delete products', $this->delete_products(...));
            WP_CLI::add_command('wc-bulk delete orders', $this->delete_orders(...));
        }

        public function generate_products($args, $assoc_args): void
        {
            $amount = (int) ($assoc_args['amount'] ?? 1000);

            $_SERVER['argv'] = ['', "--amount=$amount"];

            require __DIR__ . '/generate_products.php';
        }

        public function generate_orders($args, $assoc_args): void
        {
            $amount = (int) ($assoc_args['amount'] ?? 1000);

            $_SERVER['argv'] = ['', "--amount=$amount"];

            require __DIR__ . '/generate_orders.php';
        }

        public function delete_products(): void
        {
            require __DIR__ . '/delete_products.php';

            $count = (int) ($return ?? 0);
            WP_CLI::success("Deleted $count products.");
        }

        public function delete_orders($args, $assoc_args): void
        {
            if (isset($assoc_args['force'])) {
                $_SERVER['argv'] = ['', '--force'];
            }

            require __DIR__ . '/delete_orders.php';
        }
    }

    add_action('plugins_loaded', [WC_Bulk_Tools::class, 'instance']);
}
