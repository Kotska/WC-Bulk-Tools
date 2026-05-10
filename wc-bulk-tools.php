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
                WP_CLI::add_hook( 'after_wp_load', [WC_Bulk_Tools::class, 'register_cli_commands'] );
            }
        }

        public static function register_cli_commands(): void
        {
            $self = self::instance();
            WP_CLI::add_command('wc-bulk generate products', $self->generate_products(...));
            WP_CLI::add_command('wc-bulk generate orders', $self->generate_orders(...));
            WP_CLI::add_command('wc-bulk delete products', $self->delete_products(...));
            WP_CLI::add_command('wc-bulk delete orders', $self->delete_orders(...));
            WP_CLI::add_command('wc-bulk count', $self->count_stats(...));
        }

        public function generate_products($args, $assoc_args): void
        {
            $assoc_args = wp_parse_args($assoc_args, [
                'amount'     => 1000,
                'days'       => 730,
                'start-date' => null,
                'end-date'   => null,
                'seed'       => null,
            ]);

            $_SERVER['argv'] = $this->build_argv('generate_products', $assoc_args);

            require __DIR__ . '/generate_products.php';
        }

        public function generate_orders($args, $assoc_args): void
        {
            $assoc_args = wp_parse_args($assoc_args, [
                'amount'     => 1000,
                'days'       => 365,
                'start-date' => null,
                'end-date'   => null,
                'currency'   => null,
                'seed'       => null,
            ]);

            $_SERVER['argv'] = $this->build_argv('generate_orders', $assoc_args);

            require __DIR__ . '/generate_orders.php';
        }

        public function delete_products($args, $assoc_args): void
        {
            $force = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);

            $_SERVER['argv'] = $force ? ['', '--force'] : [''];

            require __DIR__ . '/delete_products.php';

            $count = (int) ($return ?? 0);
            WP_CLI::success("Deleted $count products.");
        }

        public function delete_orders($args, $assoc_args): void
        {
            $force = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);

            $_SERVER['argv'] = $force ? ['', '--force'] : [''];

            require __DIR__ . '/delete_orders.php';
        }

        public function count_stats(): void
        {
            require_once __DIR__ . '/generate_helpers.php';

            $stats = wcbt_count_stats();

            WP_CLI::log("Products:  {$stats['products']}");
            WP_CLI::log("Orders:    {$stats['orders']}");
            WP_CLI::log("Customers: {$stats['customers']}");
        }

        private function build_argv(string $command, array $assoc_args): array
        {
            $known = [
                'generate_products' => ['amount', 'days', 'start-date', 'end-date', 'seed'],
                'generate_orders'   => ['amount', 'days', 'start-date', 'end-date', 'currency', 'seed'],
            ];

            $keys = $known[$command] ?? array_keys($assoc_args);

            $cli = [''];
            foreach ($keys as $key) {
                if (isset($assoc_args[$key]) && $assoc_args[$key] !== null) {
                    $cli[] = "--$key={$assoc_args[$key]}";
                }
            }

            return $cli;
        }
    }
}
WC_Bulk_Tools::instance();
