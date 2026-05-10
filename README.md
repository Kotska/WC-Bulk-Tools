# WooCommerce Bulk Tools

WP-CLI commands for bulk generating and deleting WooCommerce products and orders using direct SQL for performance.

## Requirements

- WordPress
- WooCommerce (active)
- WP-CLI

## Usage

All commands are under the `wp wc-bulk` namespace.

### Generate Products

```sh
wp wc-bulk generate products --amount=1000
```

Creates simple published products with a random price (10–100) and the `_regular_price`, `_price`, `_visibility`, and `_stock_status` meta fields. Runs inside a single transaction and rebuilds WooCommerce lookup tables afterward.

| Flag | Default | Description |
|------|---------|-------------|
| `--amount` | `1000` | Number of products to create |

### Generate Orders

```sh
wp wc-bulk generate orders --amount=1000
```

Creates completed orders assigned to random existing customers and products. Populates HPOS tables (`wc_orders`, `wc_order_addresses`, `wc_order_meta`, `wc_order_product_lookup`). Clears caches and syncs customer order meta on completion.

| Flag | Default | Description |
|------|---------|-------------|
| `--amount` | `1000` | Number of orders to create |

### Delete Products

```sh
wp wc-bulk delete products
```

Deletes all products (post type `product`) via direct SQL — removes postmeta, term relationships, posts, and cleans up the `wc_product_meta_lookup` table.

### Delete Orders

```sh
wp wc-bulk delete orders
wp wc-bulk delete orders --force
```

Deletes all orders from HPOS tables (`wc_orders`, `wc_order_addresses`, `wc_order_meta`, `wc_order_product_lookup`). Without `--force` the script prompts for confirmation (CLI only).

| Flag | Description |
|------|-------------|
| `--force` | Skip confirmation prompt |

### Count

```sh
wp wc-bulk count
```

Displays the current number of products, orders, and customers in the store.

```
Products:  1500
Orders:    850
Customers: 120
```

## Notes

- All operations use direct SQL (`$wpdb`) — WooCommerce hooks, validation, and stock management are bypassed.
- Products and orders are generated with minimal, test-oriented data.
- Always take a database backup before running delete commands.

## Running Tests

The project includes two test suites and a standalone runner:

### Unit tests (`tests/`)

Requires WordPress + WooCommerce loaded. Uses PHPUnit 10 via the bundled PHAR.

```sh
php tools/phpunit --configuration=tests/phpunit.xml.dist
```

### Table structure tests

Standalone runner that validates HPOS and lookup table schemas without PHPUnit:

```sh
php tests/runner.php
```

### Integration & WP-CLI tests (`tests-dev/`)

Requires WordPress, WooCommerce, and WP-CLI. Composer dev dependencies must be installed first:

```sh
composer install
php tools/phpunit --configuration=tests-dev/phpunit.xml.dist
```

The `tests-dev` suite verifies WP-CLI command registration, argument handling, and data roundtrip correctness.

## AI Tools

This repository was developed with the assistance of AI tools (Claude, ChatGPT) for code generation, refactoring, and documentation. All AI-generated code has been reviewed and tested before inclusion.
