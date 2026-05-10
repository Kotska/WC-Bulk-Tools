<?php
/**
 * Shared helper functions and data pools for generate_orders.php and
 * generate_products.php.
 *
 * Loaded by the generators; not intended to run standalone.
 */

if (!defined('ABSPATH')) {
    exit;
}

// -----------------------------------------------------------------------------
// Data pools
// -----------------------------------------------------------------------------

/**
 * Order status distribution. Weights are relative; do not need to sum to 100.
 */
function wcbt_order_status_pool() {
    return [
        ['key' => 'wc-completed',  'weight' => 70],
        ['key' => 'wc-processing', 'weight' => 12],
        ['key' => 'wc-on-hold',    'weight' => 6],
        ['key' => 'wc-pending',    'weight' => 5],
        ['key' => 'wc-cancelled',  'weight' => 4],
        ['key' => 'wc-failed',     'weight' => 2],
        ['key' => 'wc-refunded',   'weight' => 1],
    ];
}

/**
 * Payment method distribution.
 */
function wcbt_payment_method_pool() {
    return [
        ['key' => 'stripe',       'title' => 'Credit Card (Stripe)',  'weight' => 45],
        ['key' => 'ppcp-gateway', 'title' => 'PayPal',                'weight' => 30],
        ['key' => 'bacs',         'title' => 'Direct Bank Transfer',  'weight' => 10],
        ['key' => 'cod',          'title' => 'Cash on Delivery',      'weight' => 8],
        ['key' => 'cheque',       'title' => 'Check Payments',        'weight' => 4],
        ['key' => 'square',       'title' => 'Square',                'weight' => 3],
    ];
}

function wcbt_first_name_pool() {
    return [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda',
        'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Christopher', 'Nancy', 'Daniel', 'Lisa',
        'Matthew', 'Margaret', 'Anthony', 'Betty', 'Mark', 'Sandra', 'Donald', 'Ashley',
        'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle',
        'Kenneth', 'Carol', 'Kevin', 'Amanda', 'Brian', 'Melissa', 'George', 'Deborah',
        'Edward', 'Stephanie',
    ];
}

function wcbt_last_name_pool() {
    return [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
        'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson',
        'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker',
        'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
        'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell',
        'Carter', 'Roberts',
    ];
}

function wcbt_email_domain_pool() {
    return [
        'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com',
        'protonmail.com', 'aol.com', 'live.com', 'msn.com', 'mail.com',
    ];
}

// -----------------------------------------------------------------------------
// Random helpers
// -----------------------------------------------------------------------------

/**
 * Pick a weighted-random element from an array of ['weight' => N, ...] items.
 * Returns the entire chosen item (not just the key).
 */
function wcbt_weighted_pick(array $items) {
    $total = 0;
    foreach ($items as $item) {
        $total += (int) $item['weight'];
    }
    if ($total <= 0) {
        return $items[array_rand($items)];
    }
    $roll = mt_rand(1, $total);
    $acc = 0;
    foreach ($items as $item) {
        $acc += (int) $item['weight'];
        if ($roll <= $acc) {
            return $item;
        }
    }
    return end($items);
}

/**
 * Pick a random unix timestamp between $start_ts and $end_ts using a linear
 * growth curve so that recent dates are weighted more heavily.
 *
 * Day weight = 1 + (day_index / total_days) * 2  =>  oldest day weight 1, newest weight 3.
 */
function wcbt_weighted_random_date($start_ts, $end_ts) {
    $span = $end_ts - $start_ts;
    if ($span <= 0) {
        return $start_ts;
    }
    // Sample from f(x) = 1 + 2x on [0, 1] using inverse CDF.
    // CDF F(x) = x + x^2; total = 2 (so normalized F(x) = (x + x^2) / 2).
    // Inverse: solve (x + x^2)/2 = u -> x^2 + x - 2u = 0 -> x = (-1 + sqrt(1 + 8u))/2.
    $u = mt_rand() / mt_getrandmax();
    $x = (-1 + sqrt(1 + 8 * $u)) / 2;
    return (int) round($start_ts + $x * $span);
}

/**
 * Choose a customer index using a Pareto-like weighting so a small fraction of
 * customers receive most orders (rough 80/20 rule).
 *
 * Weight for rank r (1-indexed) = 1 / r^1.16.
 *
 * Returns a 0-indexed position into the sorted customer pool.
 */
function wcbt_pareto_pick($n, array $cumulative_weights, $total_weight) {
    if ($n <= 0) {
        return 0;
    }
    $roll = (mt_rand() / mt_getrandmax()) * $total_weight;
    // Binary search in cumulative weights.
    $lo = 0;
    $hi = $n - 1;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($cumulative_weights[$mid] < $roll) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo;
}

/**
 * Build cumulative weights array for Pareto picking.
 * Returns [cumulative_weights_array, total_weight].
 */
function wcbt_build_pareto_weights($n, $exponent = 1.16) {
    $cum = [];
    $total = 0.0;
    for ($r = 1; $r <= $n; $r++) {
        $total += 1.0 / pow($r, $exponent);
        $cum[] = $total;
    }
    return [$cum, $total];
}

/**
 * Build a deterministic-ish customer profile (name, email) for a registered
 * customer id. Caches results in a static array so the same customer always
 * gets the same profile within a run.
 */
function wcbt_customer_profile($customer_id, &$cache, array $first_names, array $last_names, array $domains) {
    if (isset($cache[$customer_id])) {
        return $cache[$customer_id];
    }
    $first = $first_names[array_rand($first_names)];
    $last  = $last_names[array_rand($last_names)];
    $domain = $domains[array_rand($domains)];
    $email = strtolower($first . '.' . $last) . $customer_id . '@' . $domain;
    $profile = [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
    ];
    $cache[$customer_id] = $profile;
    return $profile;
}

/**
 * Build a guest profile with a unique-ish email per order.
 */
function wcbt_guest_profile($order_id, array $first_names, array $last_names, array $domains) {
    $first = $first_names[array_rand($first_names)];
    $last  = $last_names[array_rand($last_names)];
    $domain = $domains[array_rand($domains)];
    $email = strtolower($first . '.' . $last) . '.g' . $order_id . '@' . $domain;
    return [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
    ];
}

// -----------------------------------------------------------------------------
// Batched insert helper
// -----------------------------------------------------------------------------

/**
 * Flush an array of associative-array rows into a table using a single
 * multi-row INSERT statement. All rows must have the same keys, in the same
 * order as $columns / $formats.
 *
 * @param wpdb   $wpdb
 * @param string $table   Full table name (with prefix).
 * @param array  $columns List of column names (order must match $formats and the row arrays).
 * @param array  $formats List of printf-style format specifiers (%d, %s, %f).
 * @param array  $rows    Array of associative arrays keyed by column name.
 * @return int|false Rows affected, or false on error.
 */
function wcbt_flush_batch($wpdb, $table, array $columns, array $formats, array $rows) {
    if (empty($rows)) {
        return 0;
    }
    $col_count = count($columns);
    if ($col_count !== count($formats)) {
        fwrite(STDERR, "wcbt_flush_batch: column/format count mismatch for $table\n");
        return false;
    }

    $placeholder_row = '(' . implode(', ', $formats) . ')';
    $values = [];
    $row_placeholders = [];
    foreach ($rows as $row) {
        $row_placeholders[] = $placeholder_row;
        foreach ($columns as $col) {
            $values[] = array_key_exists($col, $row) ? $row[$col] : null;
        }
    }

    $col_list = '`' . implode('`, `', $columns) . '`';
    $sql = "INSERT INTO `$table` ($col_list) VALUES " . implode(', ', $row_placeholders);

    $prepared = $wpdb->prepare($sql, $values);
    $result = $wpdb->query($prepared);
    if ($result === false) {
        fwrite(STDERR, "wcbt_flush_batch error on $table: " . $wpdb->last_error . "\n");
    }
    return $result;
}

// =============================================================================
// Product generation helpers
// =============================================================================

/**
 * Category tree definition. Each top-level category has subcategories with
 * SKU prefixes, a price range, a long-tail exponent, title templates, and
 * description templates.
 *
 * Title templates use placeholders:
 *   {adj}   - random adjective from wcbt_product_adjectives()
 *   {brand} - random brand from wcbt_brand_pool()
 */
function wcbt_category_tree() {
    return [
        [
            'name' => 'Electronics', 'slug' => 'electronics', 'sku' => 'ELE',
            'price_min' => 30, 'price_max' => 2000, 'price_exp' => 3.0,
            'subcategories' => [
                ['name' => 'Headphones',  'slug' => 'headphones',  'sku' => 'HP'],
                ['name' => 'Cameras',     'slug' => 'cameras',     'sku' => 'CM'],
                ['name' => 'Laptops',     'slug' => 'laptops',     'sku' => 'LT'],
                ['name' => 'Smartwatches','slug' => 'smartwatches','sku' => 'SW'],
            ],
            'titles' => [
                '{adj} Wireless Bluetooth Headphones',
                '{adj} Noise-Cancelling Over-Ear Headphones',
                '{adj} Mirrorless Digital Camera',
                '{adj} 4K Action Camera',
                '{adj} 15-inch Ultrabook Laptop',
                '{adj} Gaming Laptop with RGB Keyboard',
                '{adj} Fitness Smartwatch',
                '{adj} GPS Smartwatch with Heart Rate Monitor',
                '{adj} USB-C Hub with HDMI',
                '{adj} Mechanical Keyboard',
            ],
            'descriptions' => [
                'Engineered for performance and reliability, this {adj_lower} device delivers exceptional results in any environment.',
                'Featuring the latest technology, it combines sleek design with practical functionality.',
                'Built to last with premium materials and rigorous quality testing.',
            ],
        ],
        [
            'name' => 'Clothing', 'slug' => 'clothing', 'sku' => 'CLO',
            'price_min' => 15, 'price_max' => 150, 'price_exp' => 2.5,
            'subcategories' => [
                ['name' => "Men's",        'slug' => 'mens',        'sku' => 'MN'],
                ['name' => "Women's",      'slug' => 'womens',      'sku' => 'WM'],
                ['name' => "Kids'",        'slug' => 'kids',        'sku' => 'KD'],
                ['name' => 'Accessories',  'slug' => 'accessories', 'sku' => 'AC'],
            ],
            'titles' => [
                '{adj} Cotton Crew-Neck T-Shirt',
                '{adj} Slim-Fit Denim Jeans',
                '{adj} Wool-Blend Sweater',
                '{adj} Hooded Sweatshirt',
                '{adj} Linen Summer Dress',
                '{adj} Stretch Leggings',
                '{adj} Kids Graphic Tee',
                '{adj} Toddler Joggers',
                '{adj} Leather Belt',
                '{adj} Knit Beanie',
            ],
            'descriptions' => [
                'Crafted from {adj_lower} fabric for all-day comfort and a flattering fit.',
                'Versatile enough for any occasion, this piece is a wardrobe essential.',
                'Easy-care construction means it looks great wash after wash.',
            ],
        ],
        [
            'name' => 'Home & Kitchen', 'slug' => 'home-kitchen', 'sku' => 'HOM',
            'price_min' => 15, 'price_max' => 500, 'price_exp' => 2.7,
            'subcategories' => [
                ['name' => 'Cookware',   'slug' => 'cookware',   'sku' => 'CW'],
                ['name' => 'Appliances', 'slug' => 'appliances', 'sku' => 'AP'],
                ['name' => 'Decor',      'slug' => 'decor',      'sku' => 'DC'],
            ],
            'titles' => [
                '{adj} Non-Stick 12-inch Frying Pan',
                '{adj} Stainless Steel Stockpot',
                '{adj} Cast-Iron Dutch Oven',
                '{adj} Stand Mixer',
                '{adj} Programmable Coffee Maker',
                '{adj} High-Speed Blender',
                '{adj} Ceramic Vase',
                '{adj} Throw Pillow Cover',
                '{adj} Wall Clock',
                '{adj} Bamboo Cutting Board',
            ],
            'descriptions' => [
                'Designed for everyday use, this {adj_lower} addition to your home is built to perform.',
                'Combines style and substance for a kitchen and home you will love.',
                'Easy to clean and maintain, with thoughtful details throughout.',
            ],
        ],
        [
            'name' => 'Books', 'slug' => 'books', 'sku' => 'BOO',
            'price_min' => 5, 'price_max' => 40, 'price_exp' => 2.0,
            'subcategories' => [
                ['name' => 'Fiction',     'slug' => 'fiction',     'sku' => 'FC'],
                ['name' => 'Non-Fiction', 'slug' => 'non-fiction', 'sku' => 'NF'],
                ['name' => 'Children',    'slug' => 'children',    'sku' => 'CH'],
            ],
            'titles' => [
                'The {adj} Detective: A Mystery Novel',
                'Echoes of the {adj} Kingdom',
                '{adj} Beginnings: A Memoir',
                'The {adj} Guide to Productivity',
                'Cooking the {adj} Way',
                'The {adj} Adventures of Pip',
                'Bedtime Stories for {adj} Dreamers',
                'A Brief History of {adj} Things',
                'The {adj} Investor',
                'Mindfulness for the {adj} Mind',
            ],
            'descriptions' => [
                'A captivating read that has earned widespread acclaim from readers and critics.',
                'Blending insight and storytelling, this book is one you will want to revisit.',
                'Perfect for readers seeking depth, entertainment, and a fresh perspective.',
            ],
        ],
        [
            'name' => 'Sports & Outdoors', 'slug' => 'sports-outdoors', 'sku' => 'SPO',
            'price_min' => 20, 'price_max' => 400, 'price_exp' => 2.8,
            'subcategories' => [
                ['name' => 'Fitness', 'slug' => 'fitness', 'sku' => 'FT'],
                ['name' => 'Camping', 'slug' => 'camping', 'sku' => 'CP'],
                ['name' => 'Cycling', 'slug' => 'cycling', 'sku' => 'CY'],
            ],
            'titles' => [
                '{adj} Yoga Mat with Carrying Strap',
                '{adj} Adjustable Dumbbell Set',
                '{adj} Resistance Bands Kit',
                '{adj} 4-Person Camping Tent',
                '{adj} Insulated Sleeping Bag',
                '{adj} Portable Camp Stove',
                '{adj} Road Bike Helmet',
                '{adj} Cycling Gloves',
                '{adj} Bike Repair Tool Kit',
                '{adj} Hydration Backpack',
            ],
            'descriptions' => [
                'Designed for active lifestyles, this {adj_lower} gear stands up to demanding use.',
                'Lightweight, durable, and ready for your next adventure.',
                'Trusted by enthusiasts and professionals alike.',
            ],
        ],
        [
            'name' => 'Beauty', 'slug' => 'beauty', 'sku' => 'BEA',
            'price_min' => 8, 'price_max' => 80, 'price_exp' => 2.2,
            'subcategories' => [
                ['name' => 'Skincare', 'slug' => 'skincare', 'sku' => 'SK'],
                ['name' => 'Makeup',   'slug' => 'makeup',   'sku' => 'MK'],
                ['name' => 'Haircare', 'slug' => 'haircare', 'sku' => 'HC'],
            ],
            'titles' => [
                '{adj} Hydrating Face Serum',
                '{adj} Vitamin C Brightening Cream',
                '{adj} Gentle Cleansing Foam',
                '{adj} Long-Wear Liquid Foundation',
                '{adj} Matte Lipstick',
                '{adj} Volumizing Mascara',
                '{adj} Argan Oil Hair Mask',
                '{adj} Sulfate-Free Shampoo',
                '{adj} Curl-Defining Cream',
                '{adj} Daily Moisturizer SPF 30',
            ],
            'descriptions' => [
                'Formulated with {adj_lower} ingredients for visible results you can feel.',
                'Dermatologist-tested and suitable for sensitive skin.',
                'Part of a complete routine for a healthy, radiant glow.',
            ],
        ],
        [
            'name' => 'Toys', 'slug' => 'toys', 'sku' => 'TOY',
            'price_min' => 10, 'price_max' => 100, 'price_exp' => 2.3,
            'subcategories' => [
                ['name' => 'Educational', 'slug' => 'educational', 'sku' => 'ED'],
                ['name' => 'Plush',       'slug' => 'plush',       'sku' => 'PL'],
                ['name' => 'Games',       'slug' => 'games',       'sku' => 'GM'],
            ],
            'titles' => [
                '{adj} Wooden Building Blocks Set',
                '{adj} STEM Robotics Kit',
                '{adj} Magnetic Tile Set',
                '{adj} Plush Teddy Bear',
                '{adj} Stuffed Unicorn Toy',
                '{adj} Sensory Plush Animal',
                '{adj} Family Board Game',
                '{adj} Strategy Card Game',
                '{adj} 1000-Piece Jigsaw Puzzle',
                '{adj} Science Experiment Kit',
            ],
            'descriptions' => [
                'Encourages {adj_lower} learning and creative play for hours of fun.',
                'Designed with safety and durability in mind for children of all ages.',
                'A thoughtful gift that sparks imagination and curiosity.',
            ],
        ],
        [
            'name' => 'Office', 'slug' => 'office', 'sku' => 'OFF',
            'price_min' => 5, 'price_max' => 300, 'price_exp' => 2.6,
            'subcategories' => [
                ['name' => 'Stationery', 'slug' => 'stationery', 'sku' => 'ST'],
                ['name' => 'Furniture',  'slug' => 'furniture',  'sku' => 'FR'],
                ['name' => 'Storage',    'slug' => 'storage',    'sku' => 'SG'],
            ],
            'titles' => [
                '{adj} Hardcover Notebook',
                '{adj} Gel Pen Set (12-pack)',
                '{adj} Desk Organizer Tray',
                '{adj} Ergonomic Office Chair',
                '{adj} Standing Desk Converter',
                '{adj} Adjustable Monitor Arm',
                '{adj} File Cabinet with Lock',
                '{adj} Stackable Storage Bins',
                '{adj} Cable Management Box',
                '{adj} Whiteboard Marker Set',
            ],
            'descriptions' => [
                'Built for the modern workspace, this {adj_lower} piece keeps you organized and focused.',
                'Sturdy construction and clean design make it a workspace staple.',
                'Helps you do your best work, day in and day out.',
            ],
        ],
    ];
}

function wcbt_brand_pool() {
    return [
        'Acme', 'Globex', 'Initech', 'Umbrella', 'Stark', 'Wayne',
        'Cyberdyne', 'Soylent', 'Tyrell', 'Wonka', 'Pied Piper', 'Hooli',
        'Massive Dynamic', 'Oscorp', 'Aperture',
    ];
}

function wcbt_product_adjectives() {
    return [
        'Premium', 'Classic', 'Compact', 'Eco-Friendly', 'Modern', 'Vintage',
        'Professional', 'Deluxe', 'Essential', 'Advanced', 'Lightweight',
        'Heavy-Duty', 'Portable', 'Smart', 'Ergonomic', 'Durable', 'Stylish',
        'Minimalist', 'Rustic', 'Sleek', 'Versatile', 'Innovative', 'Reliable',
        'Handcrafted', 'Artisan', 'Luxury', 'Everyday', 'All-Weather', 'Travel',
        'Signature',
    ];
}

function wcbt_tax_class_pool() {
    return [
        ['key' => '',             'weight' => 80],
        ['key' => 'reduced-rate', 'weight' => 12],
        ['key' => 'zero-rate',    'weight' => 8],
    ];
}

/**
 * Stock status distribution. Each entry includes manage_stock + stock range.
 */
function wcbt_stock_status_pool() {
    return [
        ['status' => 'instock',      'manage' => 'no',  'min' => 0,   'max' => 0,    'weight' => 75],
        ['status' => 'instock',      'manage' => 'yes', 'min' => 5,   'max' => 500,  'weight' => 15],
        ['status' => 'outofstock',   'manage' => 'yes', 'min' => 0,   'max' => 0,    'weight' => 7],
        ['status' => 'onbackorder',  'manage' => 'no',  'min' => 0,   'max' => 0,    'weight' => 3],
    ];
}

/**
 * Product type / virtual / downloadable / external mix.
 * Returns one option chosen by weighted pick.
 */
function wcbt_product_type_pool() {
    return [
        ['virtual' => 'no',  'downloadable' => 'no',  'weight' => 92],
        ['virtual' => 'yes', 'downloadable' => 'no',  'weight' => 5],
        ['virtual' => 'yes', 'downloadable' => 'yes', 'weight' => 2],
        // External products require _product_url meta and a different post handling; skip.
        ['virtual' => 'no',  'downloadable' => 'no',  'weight' => 1],
    ];
}

// -----------------------------------------------------------------------------
// Product generation utilities
// -----------------------------------------------------------------------------

/**
 * Long-tail price within [min, max] using inverse-CDF of x^exp on [0,1].
 * Larger exp => more skew toward the lower end.
 */
function wcbt_long_tail_price($min, $max, $exp = 3.0) {
    $u = mt_rand() / mt_getrandmax();
    $x = pow($u, $exp);
    return $min + ($max - $min) * $x;
}

/**
 * Round a raw price to a realistic ending: .99 / .95 / .49 / whole.
 */
function wcbt_pretty_price($raw) {
    $raw = max(0.01, (float) $raw);
    $endings = [
        ['frac' => 0.99, 'weight' => 60],
        ['frac' => 0.95, 'weight' => 20],
        ['frac' => 0.49, 'weight' => 10],
        ['frac' => 0.00, 'weight' => 10],
    ];
    $pick = wcbt_weighted_pick($endings);
    $whole = floor($raw);
    if ($pick['frac'] === 0.00) {
        return number_format($whole, 2, '.', '');
    }
    return number_format($whole + $pick['frac'], 2, '.', '');
}

/**
 * Build a unique slug given a base, mutating $used set as a side effect.
 *
 * @param string $base
 * @param array  &$used  Map of slug => true.
 * @return string
 */
function wcbt_unique_slug($base, array &$used) {
    $slug = $base;
    if ($slug === '') {
        $slug = 'product';
    }
    if (!isset($used[$slug])) {
        $used[$slug] = true;
        return $slug;
    }
    $n = 2;
    while (isset($used[$slug . '-' . $n])) {
        $n++;
    }
    $final = $slug . '-' . $n;
    $used[$final] = true;
    return $final;
}

/**
 * Build a product title from the category templates.
 */
function wcbt_build_title(array $category, array $adjectives) {
    $template = $category['titles'][array_rand($category['titles'])];
    $adj = $adjectives[array_rand($adjectives)];
    return strtr($template, ['{adj}' => $adj]);
}

/**
 * Build a product description from category templates with a random adjective.
 */
function wcbt_build_description(array $category, array $adjectives) {
    $sentences = $category['descriptions'];
    $adj = $adjectives[array_rand($adjectives)];
    $out = [];
    foreach ($sentences as $s) {
        $out[] = strtr($s, [
            '{adj}'       => $adj,
            '{adj_lower}' => strtolower($adj),
        ]);
    }
    return implode(' ', $out);
}

/**
 * Build a SKU like "ELE-HP-00042".
 */
function wcbt_build_sku($cat_prefix, $subcat_prefix, $counter) {
    return sprintf('%s-%s-%05d', $cat_prefix, $subcat_prefix, $counter);
}

/**
 * Look up or create a taxonomy term and return [term_id, term_taxonomy_id].
 */
function wcbt_get_or_create_term($wpdb, $name, $slug, $taxonomy, $parent_tt_id = 0, $parent_term_id = 0) {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT t.term_id, tt.term_taxonomy_id
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE t.slug = %s AND tt.taxonomy = %s
         LIMIT 1",
        $slug,
        $taxonomy
    ));

    if ($row) {
        return [(int) $row->term_id, (int) $row->term_taxonomy_id];
    }

    $wpdb->insert(
        $wpdb->terms,
        ['name' => $name, 'slug' => $slug, 'term_group' => 0],
        ['%s', '%s', '%d']
    );
    $term_id = (int) $wpdb->insert_id;

    $wpdb->insert(
        $wpdb->term_taxonomy,
        [
            'term_id'     => $term_id,
            'taxonomy'    => $taxonomy,
            'description' => '',
            'parent'      => $parent_term_id,
            'count'       => 0,
        ],
        ['%d', '%s', '%s', '%d', '%d']
    );
    $tt_id = (int) $wpdb->insert_id;

    return [$term_id, $tt_id];
}

// =============================================================================
// Stats helper
// =============================================================================

/**
 * Count products, orders, and customers in the store.
 *
 * @return array{products: int, orders: int, customers: int}
 */
function wcbt_count_stats() {
    global $wpdb;

    $products = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'"
    );

    $orders = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'"
    );

    $customers = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $wpdb->prefix . 'capabilities',
            '%"customer"%'
        )
    );

    return compact('products', 'orders', 'customers');
}

