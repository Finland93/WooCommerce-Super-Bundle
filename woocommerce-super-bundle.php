<?php
/*
Plugin Name: WooCommerce Super Bundle
Plugin URI: https://github.com/Finland93/WooCommerce-Super-bundle
Description: The ultimate WooCommerce bundle plugin inspired by the best features. Create customizable product bundles with fixed or dynamic pricing, discounts, variation support, and min/max quantity limits. Supports open and closed bundles for maximum flexibility.
Version: 2.1.0
Author: Finland93
Author URI: https://github.com/Finland93
License: GPL-2.0
License URI: https://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: woocommerce-super-bundle
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// PHP version check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Super Bundle requires PHP 7.4 or higher.', 'woocommerce-super-bundle') . '</p></div>';
    });
    return;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Super Bundle requires WooCommerce to be installed and active.', 'woocommerce-super-bundle') . '</p></div>';
    });
    return;
}

// HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Uninstall hook
register_uninstall_hook(__FILE__, 'wc_super_bundle_uninstall');
function wc_super_bundle_uninstall() {
    delete_option('wc_super_bundle_translations');
    global $wpdb;
    $meta_keys = [
        '_bundle_type',
        '_bundle_price_mode',
        '_bundle_fixed_price',
        '_bundle_discount_type',
        '_bundle_discount_value',
        '_bundle_min_total',
        '_bundle_max_total',
        '_bundle_min_items',
        '_bundle_max_items',
        '_bundleducts',
        '_bundle_shipping_method',
        '_bundle_tax_status',
    ];
    $all_bundles = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'product' AND p.post_status = 'publish' 
        AND pm.meta_key = '_product_type' AND pm.meta_value = 'super_bundle'
    ");
    foreach ($all_bundles as $bundle_id) {
        foreach ($meta_keys as $meta_key) {
            delete_post_meta($bundle_id, $meta_key);
        }
        wc_super_bundle_delete_recursive($bundle_id, $meta_keys);
    }
}
function wc_super_bundle_delete_recursive($bundle_id, $meta_keys) {
    global $wpdb;
    $all_bundles = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'product' AND p.post_status = 'publish' 
        AND pm.meta_key = '_product_type' AND pm.meta_value = 'super_bundle'
    ");
    $child_bundles = [];
    foreach ($all_bundles as $potential_child) {
        if ($potential_child == $bundle_id) continue;
        $child_products = get_post_meta($potential_child, '_bundleducts', true);
        if (is_array($child_products) && in_array($bundle_id, array_column($child_products, 'id'))) {
            $child_bundles[] = $potential_child;
        }
    }
    foreach ($child_bundles as $child_id) {
        foreach ($meta_keys as $meta_key) {
            delete_post_meta($child_id, $meta_key);
        }
        wc_super_bundle_delete_recursive($child_id, $meta_keys);
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'wc_super_bundle_init', 11);
function wc_super_bundle_init() {
    if (!class_exists('WC_Product')) {
        return;
    }

    // Add settings tab for translations
    add_filter( 'woocommerce_settings_tabs_array', 'wc_super_bundle_add_settings_tab', 50 );
    function wc_super_bundle_add_settings_tab( $settings_tabs ) {
        $settings_tabs['super_bundle_translations'] = __( 'Super Bundle Translations', 'woocommerce-super-bundle' );
        return $settings_tabs;
    }

    add_action( 'woocommerce_settings_tabs_super_bundle_translations', 'wc_super_bundle_translation_settings' );
    function wc_super_bundle_translation_settings() {
        woocommerce_admin_fields( wc_super_bundle_get_translation_settings() );
    }

    add_action( 'woocommerce_update_options_super_bundle_translations', 'wc_super_bundle_update_translation_settings' );
    function wc_super_bundle_update_translation_settings() {
        woocommerce_update_options( wc_super_bundle_get_translation_settings() );
    }

    function wc_super_bundle_get_translation_settings() {
        $defaults = [
            'select_items_min_max' => __( 'Select %1$d to %2$d products', 'woocommerce-super-bundle' ),
            'select_items_min' => __( 'Select at least %d products', 'woocommerce-super-bundle' ),
            'fixed_bundle' => __( 'Fixed Bundle: %d products', 'woocommerce-super-bundle' ),
            'total' => __( 'Price now: ', 'woocommerce-super-bundle' ),
            'price_before' => __( 'Price before: ', 'woocommerce-super-bundle' ),
            'include_text' => __( 'Include', 'woocommerce-super-bundle' ),
            'bundle_contents' => __( 'Bundle Contents', 'woocommerce-super-bundle' ),
            'add_to_cart' => __( 'Add to Cart', 'woocommerce-super-bundle' ),
            'out_of_stock' => __( 'Out of stock', 'woocommerce-super-bundle' ),
            'min_total_error' => __( 'Total must be at least %s', 'woocommerce-super-bundle' ),
            'max_total_error' => __( 'Total cannot exceed %s', 'woocommerce-super-bundle' ),
            'min_items_error' => __( 'Select at least %d products', 'woocommerce-super-bundle' ),
            'max_items_error' => __( 'Cannot select more than %d products', 'woocommerce-super-bundle' ),
            'select_variation' => __( 'Please select all variations.', 'woocommerce-super-bundle' ),
        ];
        $translations = get_option( 'wc_super_bundle_translations', $defaults );

        return apply_filters( 'woocommerce_super_bundle_translation_settings', [
            'section_title' => [
                'title' => __( 'Frontend Texts', 'woocommerce-super-bundle' ),
                'type'  => 'title',
                'desc'  => __( 'Customize the texts shown to customers in the bundle builder. Use placeholders like %d for numbers or %s for prices.', 'woocommerce-super-bundle' ),
                'id'    => 'super_bundle_translation_options'
            ],
            'select_items_min_max' => [
                'title'             => __( 'Select items min-max (Open bundle with max)', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Select %1$d to %2$d products', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['select_items_min_max'],
                'id'                => 'wc_super_bundle_translations[select_items_min_max]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['select_items_min_max'],
            ],
            'select_items_min' => [
                'title'             => __( 'Select items min (Open bundle unlimited)', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Select at least %d products', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['select_items_min'],
                'id'                => 'wc_super_bundle_translations[select_items_min]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['select_items_min'],
            ],
            'fixed_bundle' => [
                'title'             => __( 'Fixed bundle header', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Fixed Bundle: %d products', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['fixed_bundle'],
                'id'                => 'wc_super_bundle_translations[fixed_bundle]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['fixed_bundle'],
            ],
            'total' => [
                'title'             => __( 'Bundle total label (Price now)', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Price now: ', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['total'],
                'id'                => 'wc_super_bundle_translations[total]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['total'],
            ],
            'price_before' => [
                'title'             => __( 'Products separately label (Price before)', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Price before: ', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['price_before'],
                'id'                => 'wc_super_bundle_translations[price_before]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['price_before'],
            ],
            'include_text' => [
                'title'             => __( 'Include checkbox label', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Include', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['include_text'],
                'id'                => 'wc_super_bundle_translations[include_text]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['include_text'],
            ],
            'bundle_contents' => [
                'title'             => __( 'Bundle contents label (in cart)', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Bundle Contents', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['bundle_contents'],
                'id'                => 'wc_super_bundle_translations[bundle_contents]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['bundle_contents'],
            ],
            'add_to_cart' => [
                'title'             => __( 'Add to cart button', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Add to Cart', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['add_to_cart'],
                'id'                => 'wc_super_bundle_translations[add_to_cart]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['add_to_cart'],
            ],
            'out_of_stock' => [
                'title'             => __( 'Out of stock message', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Out of stock', 'woocommerce-super-bundle' ),
                'type'              => 'text',
                'default'           => $defaults['out_of_stock'],
                'id'                => 'wc_super_bundle_translations[out_of_stock]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['out_of_stock'],
            ],
            'min_total_error' => [
                'title'             => __( 'Min total error', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Total must be at least %s', 'woocommerce-super-bundle' ),
                'type'              => 'textarea',
                'default'           => $defaults['min_total_error'],
                'id'                => 'wc_super_bundle_translations[min_total_error]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['min_total_error'],
            ],
            'max_total_error' => [
                'title'             => __( 'Max total error', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Total cannot exceed %s', 'woocommerce-super-bundle' ),
                'type'              => 'textarea',
                'default'           => $defaults['max_total_error'],
                'id'                => 'wc_super_bundle_translations[max_total_error]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['max_total_error'],
            ],
            'min_items_error' => [
                'title'             => __( 'Min items error', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Select at least %d products', 'woocommerce-super-bundle' ),
                'type'              => 'textarea',
                'default'           => $defaults['min_items_error'],
                'id'                => 'wc_super_bundle_translations[min_items_error]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['min_items_error'],
            ],
            'max_items_error' => [
                'title'             => __( 'Max items error', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Cannot select more than %d products', 'woocommerce-super-bundle' ),
                'type'              => 'textarea',
                'default'           => $defaults['max_items_error'],
                'id'                => 'wc_super_bundle_translations[max_items_error]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['max_items_error'],
            ],
            'select_variation' => [
                'title'             => __( 'Select variations error', 'woocommerce-super-bundle' ),
                'description'       => __( 'Default: Please select all variations.', 'woocommerce-super-bundle' ),
                'type'              => 'textarea',
                'default'           => $defaults['select_variation'],
                'id'                => 'wc_super_bundle_translations[select_variation]',
                'desc_tip'          => true,
                'placeholder'       => $defaults['select_variation'],
            ],
            'section_end' => [
                'type' => 'sectionend',
                'id' => 'super_bundle_translation_options'
            ],
        ] );
    }

    // Enqueue frontend assets for cart
    add_action('wp_enqueue_scripts', 'wc_super_bundle_frontend_assets');
    function wc_super_bundle_frontend_assets() {
        if (is_cart() || is_checkout()) {
            wp_add_inline_style('woocommerce-general', '
                .woocommerce .cart .wc-item-meta,
                .woocommerce-cart .wc-item-meta,
                .woocommerce-checkout .wc-item-meta {
                    display: block !important;
                    margin: 0 0 0.5em 0 !important;
                    padding: 0 !important;
                }
                .woocommerce dl.variation dt,
                .woocommerce-cart dl.variation dt,
                .woocommerce-checkout dl.variation dt {
                    font-weight: bold;
                    margin: 0 0 0.25em 0 !important;
                    display: inline-block;
                    min-width: 100px;
                }
                .woocommerce dl.variation dd,
                .woocommerce-cart dl.variation dd,
                .woocommerce-checkout dl.variation dd {
                    margin: 0 0 0.5em 0 !important;
                    padding-left: 0 !important;
                }
                .woocommerce ul.wc-item-meta li {
                    margin: 0 0 0.25em 0;
                    list-style: none;
                    padding: 0;
                }
                .woocommerce .cart .variation-BundleContents,
                .woocommerce-cart .variation-BundleContents,
                .woocommerce-checkout .variation-BundleContents {
                    font-size: 0.9em;
                    color: #666;
                }
            ');
        }
    }

    // Enqueue admin assets
    add_action('admin_enqueue_scripts', 'wc_super_bundle_admin_assets');
    function wc_super_bundle_admin_assets($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        global $post;
        if ($post->post_type !== 'product') return;
        wp_enqueue_style('wc-super-bundle-admin', plugin_dir_url(__FILE__) . 'admin.css', [], '2.0.3');
        wp_enqueue_script('wc-super-bundle-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '2.0.3', true);
    }

    // Custom product class
    class WC_Product_Super_bundle extends WC_Product {
        public function __construct($product) {
            $this->product_type = 'super_bundle';
            parent::__construct($product);
        }

        public function get_type() {
            return 'super_bundle';
        }

        public function is_purchasable() {
            return $this->is_in_stock();
        }

        public function is_in_stock() {
            $bundle_type = get_post_meta($this->get_id(), '_bundle_type', true);
            $products = get_post_meta($this->get_id(), '_bundleducts', true);
            if (empty($products)) return false;
            if ($bundle_type === 'closed') {
                foreach ($products as $product_data) {
                    $product_id = $product_data['id'];
                    $product = wc_get_product($product_id);
                    if (!$product || !$product->is_in_stock()) return false;
                }
            } else {
                $min_items = absint(get_post_meta($this->get_id(), '_bundle_min_items', true)) ?: 1;
                $in_stock_count = 0;
                foreach ($products as $product_data) {
                    $product = wc_get_product($product_data['id']);
                    if ($product && $product->is_in_stock()) $in_stock_count++;
                }
                if ($in_stock_count < $min_items) return false;
            }
            return true;
        }

        public function get_price($context = 'view') {
            $price = $this->get_prop( 'price' );
            if ( ! is_null( $price ) && '' !== $price ) {
                return $price;
            }

            $price_mode = get_post_meta($this->get_id(), '_bundle_price_mode', true) ?: 'auto';
            $bundle_type = get_post_meta($this->get_id(), '_bundle_type', true) ?: 'open';
            if ($price_mode === 'fixed') {
                $fixed_price = get_post_meta($this->get_id(), '_bundle_fixed_price', true);
                $price = floatval($fixed_price);
            } else {
                $discount_type = get_post_meta($this->get_id(), '_bundle_discount_type', true) ?: 'percent';
                $discount_value = floatval(get_post_meta($this->get_id(), '_bundle_discount_value', true)) ?: 0;
                $products = get_post_meta($this->get_id(), '_bundleducts', true) ?: [];
                $total = 0;
                if ($bundle_type === 'closed') {
                    foreach ($products as $product_data) {
                        $product = wc_get_product($product_data['id']);
                        if ($product) {
                            $total += floatval($product->get_price($context));
                        }
                    }
                } else {
                    $prices = [];
                    foreach ($products as $product_data) {
                        $product = wc_get_product($product_data['id']);
                        if ($product) {
                            $min_price = $product->is_type('variable') ? wc_super_bundle_get_min_variation_price($product, $context) : floatval($product->get_price($context));
                            if ($min_price > 0) $prices[] = $min_price;
                        }
                    }
                    if (empty($prices)) {
                        $price = 0;
                    } else {
                        sort($prices, SORT_NUMERIC);
                        $min_items = absint(get_post_meta($this->get_id(), '_bundle_min_items', true)) ?: 1;
                        $sum = 0;
                        for ($i = 0; $i < $min_items && $i < count($prices); $i++) {
                            $sum += $prices[$i];
                        }
                        $total = $sum;
                    }
                }
                if (isset($total) && $total > 0) {
                    if ($discount_type === 'percent') {
                        $total *= (1 - $discount_value / 100);
                    } else {
                        $total -= $discount_value;
                    }
                    $price = max(0, $total);
                } else {
                    $price = 0;
                }
            }
            $this->set_prop( 'price', $price );
            return $price;
        }
    }

    // Helper for min variation price
    if (!function_exists('wc_super_bundle_get_min_variation_price')) {
        function wc_super_bundle_get_min_variation_price($product, $context = 'view') {
            if (!$product || !$product->is_type('variable')) return 0;
            $prices = [];
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && $child->get_price($context) > 0) {
                    $prices[] = floatval($child->get_price($context));
                }
            }
            return !empty($prices) ? min($prices) : 0;
        }
    }

    // Register product class
    add_filter('woocommerce_product_class', function($classname, $product_type, $post_type, $product_id) {
        if ($product_type === 'super_bundle') {
            return 'WC_Product_Super_bundle';
        }
        return $classname;
    }, 10, 4);

    // Add to product types
    add_filter('product_type_selector', function($types) {
        $types['super_bundle'] = __('Super bundle', 'woocommerce-super-bundle');
        return $types;
    });

    // Hide tabs
    add_filter('woocommerce_product_data_tabs', function($tabs) {
        $tabs['inventory']['class'][] = 'hide_if_super_bundle';
        $tabs['shipping']['class'][] = 'hide_if_super_bundle';
        $tabs['linked_product']['class'][] = 'hide_if_super_bundle';
        $tabs['attribute']['class'][] = 'hide_if_super_bundle';
        $tabs['advanced']['class'][] = 'hide_if_super_bundle';
        return $tabs;
    }, 10, 1);

    // Add tab
    add_filter('woocommerce_product_data_tabs', function($tabs) {
        $tabs['super_bundle'] = [
            'label'  => __('Super bundle', 'woocommerce-super-bundle'),
            'target' => 'super_bundle_data',
            'class'  => ['show_if_super_bundle'],
        ];
        return $tabs;
    }, 10, 1);

    // Panel
    add_action('woocommerce_product_data_panels', 'wc_super_bundle_product_data_panel');
    function wc_super_bundle_product_data_panel() {
        global $post;
        $bundle_type = get_post_meta($post->ID, '_bundle_type', true) ?: 'open';
        $price_mode = get_post_meta($post->ID, '_bundle_price_mode', true) ?: 'auto';
        $fixed_price = get_post_meta($post->ID, '_bundle_fixed_price', true) ?: '';
        $discount_type = get_post_meta($post->ID, '_bundle_discount_type', true) ?: 'percent';
        $discount_value = get_post_meta($post->ID, '_bundle_discount_value', true) ?: 0;
        $min_items = absint(get_post_meta($post->ID, '_bundle_min_items', true)) ?: 1;
        $max_items = absint(get_post_meta($post->ID, '_bundle_max_items', true)) ?: 0;
        $min_total = get_post_meta($post->ID, '_bundle_min_total', true) ?: '';
        $max_total = get_post_meta($post->ID, '_bundle_max_total', true) ?: '';
        $shipping_method = get_post_meta($post->ID, '_bundle_shipping_method', true) ?: 'bundled';
        $tax_status = get_post_meta($post->ID, '_bundle_tax_status', true) ?: 'bundled';
        $bundleducts = get_post_meta($post->ID, '_bundleducts', true) ?: [];
        ?>
        <div id="super_bundle_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_select([
                    'id'      => '_bundle_type',
                    'label'   => __('Bundle Type', 'woocommerce-super-bundle'),
                    'options' => [
                        'open'   => __('Open (Customizable)', 'woocommerce-super-bundle'),
                        'closed' => __('Closed (Fixed)', 'woocommerce-super-bundle'),
                    ],
                    'value'   => $bundle_type,
                    'desc_tip' => true,
                    'description' => __('Open: Customers can select products to include (one each). Closed: Fixed items.', 'woocommerce-super-bundle'),
                ]);
                woocommerce_wp_select([
                    'id'          => '_bundle_price_mode',
                    'label'       => __('Price Mode', 'woocommerce-super-bundle'),
                    'options'     => [
                        'auto'   => __('Auto (with Discount)', 'woocommerce-super-bundle'),
                        'fixed'  => __('Fixed Price', 'woocommerce-super-bundle'),
                    ],
                    'value'   => $price_mode,
                    'desc_tip' => true,
                    'description' => __('Auto: Sum of products minus discount. Fixed: Manual price.', 'woocommerce-super-bundle'),
                ]);
                ?>
                <div class="show_if_auto_price" style="display: none;">
                    <?php
                    woocommerce_wp_select([
                        'id'          => '_bundle_discount_type',
                        'label'       => __('Discount Type', 'woocommerce-super-bundle'),
                        'options'     => [
                            'percent' => __('Percentage %', 'woocommerce-super-bundle'),
                            'fixed'   => __('Fixed Amount', 'woocommerce-super-bundle'),
                        ],
                        'value'   => $discount_type,
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_discount_value',
                        'label'       => __('Discount Value', 'woocommerce-super-bundle'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 0, 'step' => 0.01],
                        'value'       => $discount_value,
                        'desc_tip' => true,
                        'description' => __('Percentage (0-100) or fixed amount discount on total.', 'woocommerce-super-bundle'),
                    ]);
                    ?>
                </div>
                <div class="show_if_fixed_price" style="display: none;">
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_fixed_price',
                        'label'       => __('Fixed Price', 'woocommerce-super-bundle'),
                        'data_type'   => 'price',
                        'value'       => $fixed_price,
                        'desc_tip' => true,
                        'description' => __('Manual price for the entire bundle.', 'woocommerce-super-bundle'),
                    ]);
                    ?>
                </div>
                <div class="show_if_open_bundle" style="display: none;">
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_min_items',
                        'label'       => __('Min Items', 'woocommerce-super-bundle'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 1],
                        'value'       => $min_items,
                        'desc_tip' => true,
                        'description' => __('Minimum number of products to select.', 'woocommerce-super-bundle'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_max_items',
                        'label'       => __('Max Items', 'woocommerce-super-bundle'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 0],
                        'value'       => $max_items,
                        'desc_tip' => true,
                        'description' => __('Maximum number of products to select. 0 for unlimited.', 'woocommerce-super-bundle'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_min_total',
                        'label'       => __('Min Total Amount', 'woocommerce-super-bundle'),
                        'data_type'   => 'price',
                        'value'       => $min_total,
                        'desc_tip' => true,
                        'description' => __('Minimum bundle total value.', 'woocommerce-super-bundle'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_max_total',
                        'label'       => __('Max Total Amount', 'woocommerce-super-bundle'),
                        'data_type'   => 'price',
                        'value'       => $max_total,
                        'desc_tip' => true,
                        'description' => __('Maximum bundle total value.', 'woocommerce-super-bundle'),
                    ]);
                    ?>
                </div>
                <?php
                woocommerce_wp_select([
                    'id'          => '_bundle_shipping_method',
                    'label'       => __('Shipping Method', 'woocommerce-super-bundle'),
                    'options'     => [
                        'bundled'  => __('As Bundle', 'woocommerce-super-bundle'),
                        'separate' => __('Separate Items', 'woocommerce-super-bundle'),
                    ],
                    'value'   => $shipping_method,
                    'desc_tip' => true,
                    'description' => __('How shipping is calculated for the bundle.', 'woocommerce-super-bundle'),
                ]);
                woocommerce_wp_select([
                    'id'          => '_bundle_tax_status',
                    'label'       => __('Tax Status', 'woocommerce-super-bundle'),
                    'options'     => [
                        'bundled'  => __('As Bundle', 'woocommerce-super-bundle'),
                        'separate' => __('Separate Items', 'woocommerce-super-bundle'),
                    ],
                    'value'   => $tax_status,
                    'desc_tip' => true,
                    'description' => __('How taxes are applied to the bundle.', 'woocommerce-super-bundle'),
                ]);
                ?>
                <p class="form-field">
                    <label for="bundleducts"><?php _e('bundleducts', 'woocommerce-super-bundle'); ?></label>
                    <select class="wc-product-search" multiple="multiple" style="width: 50%;" id="bundleducts" name="bundleducts[]" data-placeholder="<?php esc_attr_e('Search for a product&hellip;', 'woocommerce'); ?>" data-action="woocommerce_json_search_products_and_variations" data-exclude="<?php echo absint($post->ID); ?>">
                        <?php
                        if (!empty($bundleducts)) {
                            foreach ($bundleducts as $product_data) {
                                $product_id = $product_data['id'];
                                $product = wc_get_product($product_id);
                                if (is_object($product)) {
                                    echo '<option value="' . esc_attr($product_id) . '" selected="selected">' . wp_kses_post($product->get_formatted_name()) . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <?php echo wc_help_tip(__('Select products to include in the bundle. Each product can be selected only once.', 'woocommerce-super-bundle')); ?>
                </p>
            </div>
            <script type="text/javascript">
                jQuery(function($) {
                    function toggleFields() {
                        var bundleType = $('#_bundle_type').val();
                        var priceMode = $('#_bundle_price_mode').val();
                        $('.show_if_open_bundle').toggle(bundleType === 'open');
                        $('.show_if_auto_price').toggle(priceMode === 'auto');
                        $('.show_if_fixed_price').toggle(priceMode === 'fixed');
                    }
                    $('#_bundle_type, #_bundle_price_mode').on('change', toggleFields).trigger('change');
                });
            </script>
        </div>
        <?php
    }

    // Save data
    add_action('woocommerce_process_product_meta_super_bundle', 'wc_super_bundle_save_product_data');
    function wc_super_bundle_save_product_data($post_id) {
        if (!current_user_can('edit_post', $post_id)) return;

        $bundle_type = sanitize_text_field($_POST['_bundle_type'] ?? 'open');
        $price_mode = sanitize_text_field($_POST['_bundle_price_mode'] ?? 'auto');
        $fixed_price = wc_format_decimal(sanitize_text_field($_POST['_bundle_fixed_price'] ?? ''));
        $discount_type = sanitize_text_field($_POST['_bundle_discount_type'] ?? 'percent');
        $discount_value = floatval(sanitize_text_field($_POST['_bundle_discount_value'] ?? 0));
        $min_items = absint($_POST['_bundle_min_items'] ?? 1);
        $max_items = absint($_POST['_bundle_max_items'] ?? 0);
        $min_total = wc_format_decimal(sanitize_text_field($_POST['_bundle_min_total'] ?? ''));
        $max_total = wc_format_decimal(sanitize_text_field($_POST['_bundle_max_total'] ?? ''));
        $shipping_method = sanitize_text_field($_POST['_bundle_shipping_method'] ?? 'bundled');
        $tax_status = sanitize_text_field($_POST['_bundle_tax_status'] ?? 'bundled');
        $bundleducts_input = isset($_POST['bundleducts']) ? array_map('absint', (array) $_POST['bundleducts']) : [];

        // Process products (qty always 1)
        $bundleducts = [];
        foreach ($bundleducts_input as $product_id) {
            $bundleducts[] = [
                'id' => $product_id,
                'qty' => 1,
            ];
        }

        // Validation
        if (empty($bundleducts)) {
            WC_Admin_Meta_Boxes::add_error(__('Select at least one product.', 'woocommerce-super-bundle'));
            return;
        }
        if ($discount_value < 0 || ($discount_type === 'percent' && $discount_value > 100)) {
            WC_Admin_Meta_Boxes::add_error(__('Invalid discount value.', 'woocommerce-super-bundle'));
            $discount_value = max(0, min(100, $discount_value));
        }
        if ($bundle_type === 'open') {
            $min_items = max(1, $min_items);
            if ($min_items > count($bundleducts)) {
                WC_Admin_Meta_Boxes::add_error(__('Min items exceed available products.', 'woocommerce-super-bundle'));
                return;
            }
        } else {
            $min_items = $max_items = count($bundleducts);
        }

        // Recursion check
        if (wc_super_bundle_has_recursion($post_id, array_column($bundleducts, 'id'))) {
            WC_Admin_Meta_Boxes::add_error(__('Recursive bundle detected.', 'woocommerce-super-bundle'));
            return;
        }

        // Update meta
        update_post_meta($post_id, '_bundle_type', $bundle_type);
        update_post_meta($post_id, '_bundle_price_mode', $price_mode);
        update_post_meta($post_id, '_bundle_fixed_price', $fixed_price);
        update_post_meta($post_id, '_bundle_discount_type', $discount_type);
        update_post_meta($post_id, '_bundle_discount_value', $discount_value);
        update_post_meta($post_id, '_bundle_min_items', $min_items);
        update_post_meta($post_id, '_bundle_max_items', $max_items);
        update_post_meta($post_id, '_bundle_min_total', $min_total);
        update_post_meta($post_id, '_bundle_max_total', $max_total);
        update_post_meta($post_id, '_bundle_shipping_method', $shipping_method);
        update_post_meta($post_id, '_bundle_tax_status', $tax_status);
        update_post_meta($post_id, '_bundleducts', $bundleducts);

        // Sync price
        $product = wc_get_product($post_id);
        if ($product) {
            $calc_price = $product->get_price('edit');
            $product->set_regular_price($calc_price);
            $product->set_price($calc_price);
            $product->save();
        }
    }

    // Recursion helper
    function wc_super_bundle_has_recursion($bundle_id, $direct_products, $checked = [], $depth = 0) {
        if ($depth > 20) return true;
        $checked[] = $bundle_id;
        foreach ($direct_products as $p_id) {
            if (in_array($p_id, $checked)) return true;
            $p = wc_get_product($p_id);
            if (!$p) continue;
            $p_type = $p->get_type();
            if ($p_type === 'super_bundle') {
                $child_products = array_column(get_post_meta($p_id, '_bundleducts', true) ?: [], 'id');
                if (wc_super_bundle_has_recursion($p_id, $child_products, $checked, $depth + 1)) return true;
            }
        }
        return false;
    }

    // Price HTML
    add_filter('woocommerce_get_price_html', function($price_html, $product) {
        if ($product->is_type('super_bundle')) {
            $bundle_type = get_post_meta($product->get_id(), '_bundle_type', true);
            $price_mode = get_post_meta($product->get_id(), '_bundle_price_mode', true);
            if ($price_mode === 'auto' && $bundle_type === 'open') {
                $price_html = sprintf(__('From %s', 'woocommerce-super-bundle'), wc_price($product->get_price()));
            } else {
                $price_html = wc_price($product->get_price());
            }
        }
        return $price_html;
    }, 10, 2);

    // Remove default add to cart
    add_action('woocommerce_single_product_summary', 'wc_super_bundle_remove_default_add_to_cart', 1);
    function wc_super_bundle_remove_default_add_to_cart() {
        global $product;
        if ($product && $product->is_type('super_bundle')) {
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            add_action('woocommerce_single_product_summary', 'wc_super_bundle_render_add_to_cart', 30);
        }
    }

    // Render add to cart
    function wc_super_bundle_render_add_to_cart() {
        global $product;
        if (!$product || !$product->is_type('super_bundle')) return;

        $bundle_id = $product->get_id();
        $bundle_type = get_post_meta($bundle_id, '_bundle_type', true) ?: 'open';
        $bundleducts = get_post_meta($bundle_id, '_bundleducts', true) ?: [];
        $min_items = absint(get_post_meta($bundle_id, '_bundle_min_items', true)) ?: 1;
        $max_items = absint(get_post_meta($bundle_id, '_bundle_max_items', true)) ?: 0;
        $min_total = floatval(get_post_meta($bundle_id, '_bundle_min_total', true)) ?: 0;
        $max_total = floatval(get_post_meta($bundle_id, '_bundle_max_total', true)) ?: 0;
        $price_mode = get_post_meta($bundle_id, '_bundle_price_mode', true) ?: 'auto';
        $discount_type = get_post_meta($bundle_id, '_bundle_discount_type', true) ?: 'percent';
        $discount_value = floatval(get_post_meta($bundle_id, '_bundle_discount_value', true)) ?: 0;
        $shipping_method = get_post_meta($bundle_id, '_bundle_shipping_method', true) ?: 'bundled';

        $defaults = [
            'select_items_min_max' => __('Select %1$d to %2$d products', 'woocommerce-super-bundle'),
            'select_items_min' => __('Select at least %d products', 'woocommerce-super-bundle'),
            'fixed_bundle' => __('Fixed Bundle: %d products', 'woocommerce-super-bundle'),
            'total' => __('Price now: ', 'woocommerce-super-bundle'),
            'price_before' => __('Price before: ', 'woocommerce-super-bundle'),
            'include_text' => __('Include', 'woocommerce-super-bundle'),
            'bundle_contents' => __('Bundle Contents', 'woocommerce-super-bundle'),
            'add_to_cart' => __('Add to Cart', 'woocommerce-super-bundle'),
            'out_of_stock' => __('Out of stock', 'woocommerce-super-bundle'),
            'min_total_error' => __('Total must be at least %s', 'woocommerce-super-bundle'),
            'max_total_error' => __('Total cannot exceed %s', 'woocommerce-super-bundle'),
            'min_items_error' => __('Select at least %d products', 'woocommerce-super-bundle'),
            'max_items_error' => __('Cannot select more than %d products', 'woocommerce-super-bundle'),
            'select_variation' => __('Please select all variations.', 'woocommerce-super-bundle'),
        ];
        $translations = wp_parse_args( get_option('wc_super_bundle_translations', $defaults), $defaults );

        $edit_data = [];
        if (WC()->session) {
            $edit_data = WC()->session->get('edit_bundle_data_' . $bundle_id, []);
            WC()->session->__unset('edit_bundle_data_' . $bundle_id);
        }

        if (empty($bundleducts)) {
            echo '<div class="woocommerce-error">' . esc_html__('No products in bundle.', 'woocommerce-super-bundle') . '</div>';
            return;
        }

        $products_count = count($bundleducts);
        $header_text = $bundle_type === 'open' 
            ? ($max_items > 0 ? sprintf($translations['select_items_min_max'], $min_items, $max_items) : sprintf($translations['select_items_min'], $min_items))
            : sprintf($translations['fixed_bundle'], $products_count);

        // Closed totals
        $closed_total = $product->get_price();
        $closed_subtotal = 0;
        if ($bundle_type === 'closed') {
            foreach ($bundleducts as $product_data) {
                $p = wc_get_product($product_data['id']);
                if ($p) {
                    $closed_subtotal += floatval($p->get_price());
                }
            }
        }
        ?>
        <style>
            .super-bundle { margin: 20px 0; }
            .bundle-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
            .bundle-item { border: 1px solid #ddd; padding: 15px; border-radius: 5px; position: relative; }
            .bundle-item.out-of-stock { opacity: 0.5; }
            .bundle-item-image { max-width: 100%; height: auto; }
            .bundle-item-header { font-weight: bold; margin-bottom: 5px; }
            .bundle-qty-checkbox { margin: 5px 0; }
            .bundle-variation-select { width: 100%; margin: 5px 0; }
            .bundle-item-price { font-weight: bold; color: #e74c3c; }
            .bundle-total { font-size: 1.3em; font-weight: bold; margin: 15px 0; color: #27ae60; }
            .bundle-subtotal { font-size: 1.1em; margin: 10px 0; color: #999; }
            .bundle-message { margin: 10px 0; }
            .bundle-search { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        </style>
        <form class="cart" method="post" enctype='multipart/form-data'>
            <div class="super-bundle">
                <h3><?php echo esc_html($header_text); ?></h3>
                <?php if ($bundle_type === 'open') : ?>
                    <div id="bundle-total-price" class="bundle-total"><?php echo esc_html($translations['total']); ?><span class="price"><?php echo wc_price(0); ?></span></div>
                    <div id="bundle-subtotal-price" class="bundle-subtotal"><?php echo esc_html($translations['price_before']); ?><span class="sub-price"><?php echo wc_price(0); ?></span></div>
                    <input type="text" id="bundle_search" placeholder="<?php esc_attr_e('Search products...', 'woocommerce-super-bundle'); ?>" class="bundle-search">
                    <div class="bundle-items">
                        <?php foreach ($bundleducts as $index => $product_data) :
                            $p_id = $product_data['id'];
                            $p = wc_get_product($p_id);
                            if (!$p) continue;
                            $stock_status = $p->is_in_stock() ? '' : ' out-of-stock';
                            $base_price = floatval($p->get_price('view'));
                            $base_price_html = wc_price($base_price);
                            $selected = isset($edit_data['quantities'][$p_id]) ? $edit_data['quantities'][$p_id] : 0;
                        ?>
                            <div class="bundle-item <?php echo $stock_status; ?>" data-product-id="<?php echo esc_attr($p_id); ?>" data-price="<?php echo esc_attr($base_price); ?>" data-base-price="<?php echo esc_attr($base_price); ?>" data-name="<?php echo esc_attr(strtolower($p->get_name())); ?>">
                                <img src="<?php echo wp_get_attachment_image_src($p->get_image_id(), 'thumbnail')[0] ?? wc_placeholder_img_src(); ?>" alt="<?php echo esc_attr($p->get_name()); ?>" class="bundle-item-image">
                                <div class="bundle-item-header"><?php echo esc_html($p->get_name()); ?></div>
                                <div class="bundle-item-price"><?php echo $base_price_html; ?></div>
                                <label><input type="checkbox" class="bundle-qty-checkbox" name="bundle_quantities[<?php echo $p_id; ?>]" value="1" <?php checked($selected, 1); ?> <?php echo $p->is_in_stock() ? '' : 'disabled'; ?>> <?php echo esc_html($translations['include_text']); ?></label>
                                <?php if ($p->is_type('variable')) :
                                    $attributes = $p->get_variation_attributes();
                                    foreach ($attributes as $attr_key => $options) :
                                        $selected_val = isset($edit_data['variations'][$p_id][$attr_key]) ? $edit_data['variations'][$p_id][$attr_key] : '';
                                ?>
                                    <select class="bundle-variation-select" name="bundle_variations[<?php echo $p_id; ?>][<?php echo esc_attr($attr_key); ?>]" data-attr="<?php echo esc_attr($attr_key); ?>">
                                        <option value=""><?php esc_html_e('Choose an option', 'woocommerce'); ?></option>
                                        <?php foreach ($options as $option) : ?>
                                            <option value="<?php echo esc_attr($option); ?>" <?php selected($selected_val, $option); ?>><?php echo esc_html($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endforeach; endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add-to-cart" value="<?php echo esc_attr($bundle_id); ?>" class="single_add_to_cart_button button alt" disabled><?php echo esc_html($translations['add_to_cart']); ?></button>
                <?php else : ?>
                    <div class="bundle-total"><?php echo esc_html($translations['total']); ?><?php echo wc_price($closed_total); ?></div>
                    <div class="bundle-subtotal"><?php echo esc_html($translations['price_before']); ?><?php echo wc_price($closed_subtotal); ?></div>
                    <ul class="bundle-closed-list">
                        <?php foreach ($bundleducts as $product_data) :
                            $p_id = $product_data['id'];
                            $p = wc_get_product($p_id);
                            if ($p) echo '<li>' . esc_html($p->get_name()) . ' - ' . wc_price(floatval($p->get_price())) . '</li>';
                        endforeach; ?>
                    </ul>
                    <button type="submit" name="add-to-cart" value="<?php echo esc_attr($bundle_id); ?>" class="single_add_to_cart_button button alt"><?php echo esc_html($translations['add_to_cart']); ?></button>
                <?php endif; ?>
                <?php if (isset($_GET['edit_bundle'])) : ?>
                    <input type="hidden" name="editing_bundle_key" value="<?php echo esc_attr(sanitize_text_field($_GET['edit_bundle'])); ?>">
                <?php endif; ?>
                <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
            </div>
        </form>
        <?php if ($bundle_type === 'open') : ?>
        <script type="text/javascript">
            var ajax_url = '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>';
            var nonce = '<?php echo esc_attr( wp_create_nonce( "wc_super_bundle_nonce" ) ); ?>';
            jQuery(document).ready(function($) {
                var minItems = <?php echo $min_items; ?>;
                var maxItems = <?php echo $max_items; ?>;
                var minTotal = <?php echo $min_total; ?>;
                var maxTotal = <?php echo $max_total; ?>;
                var discountType = '<?php echo $discount_type; ?>';
                var discountValue = <?php echo $discount_value; ?>;
                var translations = <?php echo json_encode($translations); ?>;
                var $container = $('.super-bundle');
                var $total = $container.find('.bundle-total .price');
                var $subtotal = $container.find('.bundle-subtotal .sub-price');
                var $btn = $container.find('.single_add_to_cart_button');
                var $search = $container.find('.bundle-search');
                var $productPrice = $('.summary .price .amount');
                var currencySymbol = '<?php echo get_woocommerce_currency_symbol(); ?>';
                var decimals = <?php echo wc_get_price_decimals(); ?>;
                var editData = <?php echo json_encode($edit_data); ?>;

                function formatPrice(num) {
                    var formatted = num.toFixed(decimals).replace('.', ',');
                    return '<bdi>' + formatted + '&nbsp;<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span></bdi>';
                }

                function calculateTotal() {
                    var totalQty = 0;
                    var subtotal = 0;
                    var incomplete = false;
                    $container.find('.bundle-item').each(function() {
                        var $item = $(this);
                        var $checkbox = $item.find('.bundle-qty-checkbox');
                        var qty = $checkbox.is(':checked') ? 1 : 0;
                        totalQty += qty;
                        if (qty > 0) {
                            var price = parseFloat($item.data('price') || 0);
                            subtotal += price * qty;
                            // Check variations if checked
                            if ($item.find('.bundle-variation-select').length > 0) {
                                $item.find('.bundle-variation-select').each(function() {
                                    if (!$(this).val()) incomplete = true;
                                });
                            }
                        }
                    });
                    $subtotal.html(formatPrice(subtotal));
                    var total = subtotal;
                    if (discountType === 'percent') {
                        total *= (1 - discountValue / 100);
                    } else {
                        total -= discountValue;
                    }
                    total = Math.max(0, total);
                    $total.html(formatPrice(total));
                    $productPrice.html(formatPrice(total));

                    // Validation
                    var error = '';
                    if (totalQty < minItems) error = translations.min_items_error.replace('%d', minItems);
                    else if (maxItems > 0 && totalQty > maxItems) error = translations.max_items_error.replace('%d', maxItems);
                    else if (minTotal > 0 && total < minTotal) error = translations.min_total_error.replace('%s', formatPrice(minTotal));
                    else if (maxTotal > 0 && maxTotal > total) error = translations.max_total_error.replace('%s', formatPrice(maxTotal));
                    else if (incomplete) error = translations.select_variation;

                    $container.find('.bundle-message').remove();
                    if (error) {
                        $container.prepend('<div class="bundle-message woocommerce-error">' + error + '</div>');
                        $btn.prop('disabled', true);
                    } else {
                        $btn.prop('disabled', false);
                    }
                }

                function updateVariationPrice($item) {
                    var $selects = $item.find('.bundle-variation-select');
                    var allFilled = $selects.length === 0 || $selects.filter(function() { return $(this).val() === ''; }).length === 0;
                    var variations = {};
                    $selects.each(function() {
                        var attr = $(this).data('attr');
                        variations[attr] = $(this).val();
                    });
                    if (allFilled && $item.find('.bundle-qty-checkbox').is(':checked')) {
                        $.post(ajax_url, {
                            action: 'wc_super_bundle_get_variation_price',
                            product_id: $item.data('product-id'),
                            variations: variations,
                            nonce: nonce
                        }).done(function(resp) {
                            if (resp.success) {
                                $item.data('price', resp.data.price).attr('data-price', resp.data.price);
                            } else {
                                $item.data('price', parseFloat($item.attr('data-base-price')));
                            }
                            calculateTotal();
                        }).fail(function() {
                            $item.data('price', parseFloat($item.attr('data-base-price')));
                            calculateTotal();
                        });
                    } else {
                        $item.data('price', parseFloat($item.attr('data-base-price')));
                        calculateTotal();
                    }
                }

                // Search
                $search.on('input', function() {
                    var term = $(this).val().toLowerCase();
                    $container.find('.bundle-item').each(function() {
                        var name = $(this).data('name');
                        $(this).toggle(name.indexOf(term) !== -1);
                    });
                });

                // Checkbox change
                $container.on('change', '.bundle-qty-checkbox', function() {
                    var $item = $(this).closest('.bundle-item');
                    var checked = $(this).is(':checked');
                    $item.find('.bundle-variation-select').prop('disabled', !checked);
                    if (checked && $item.find('.bundle-variation-select').length > 0) {
                        updateVariationPrice($item);
                    } else {
                        calculateTotal();
                    }
                });

                // Variation change
                $container.on('change', '.bundle-variation-select', function() {
                    var $item = $(this).closest('.bundle-item');
                    updateVariationPrice($item);
                });

                // Preload edit data
                if (Object.keys(editData).length > 0) {
                    setTimeout(function() {
                        $.each(editData.quantities || {}, function(pid, qty) {
                            if (qty == 1) {
                                $container.find('.bundle-qty-checkbox[name="bundle_quantities[' + pid + ']"]').prop('checked', true).trigger('change');
                            }
                        });
                        $.each(editData.variations || {}, function(pid, vars) {
                            $.each(vars, function(attr, val) {
                                $container.find('.bundle-variation-select[name="bundle_variations[' + pid + '][' + attr + ']"]').val(val).trigger('change');
                            });
                        });
                        calculateTotal();
                    }, 100);
                } else {
                    // Auto-select the cheapest min_items products to show starting prices
                    var items = $container.find('.bundle-item').toArray().sort(function(a, b) {
                        return parseFloat($(a).data('price')) - parseFloat($(b).data('price'));
                    });
                    for (var i = 0; i < minItems && i < items.length; i++) {
                        $(items[i]).find('.bundle-qty-checkbox').prop('checked', true).trigger('change');
                    }
                }

                // Init variation prices for checked items on load
                $container.find('.bundle-item:has(.bundle-variation-select)').each(function() {
                    var $item = $(this);
                    if ($item.find('.bundle-qty-checkbox').is(':checked')) {
                        updateVariationPrice($item);
                    }
                });

                calculateTotal();
            });
        </script>
        <?php endif;
    }

    // AJAX for variation price
    add_action('wp_ajax_wc_super_bundle_get_variation_price', 'wc_super_bundle_ajax_get_variation_price');
    add_action('wp_ajax_nopriv_wc_super_bundle_get_variation_price', 'wc_super_bundle_ajax_get_variation_price');
    function wc_super_bundle_ajax_get_variation_price() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wc_super_bundle_nonce')) {
            wp_send_json_error();
        }
        $product_id = absint($_POST['product_id']);
        $variations = array_map('sanitize_text_field', (array) $_POST['variations']);
        $variation_id = wc_get_variation_id_from_variation_data($product_id, $variations);
        $variation = wc_get_product($variation_id);
        if ($variation) {
            wp_send_json_success([
                'price' => floatval($variation->get_price('view')),
                'html' => $variation->get_price_html()
            ]);
        }
        wp_send_json_error();
    }

    if (!function_exists('wc_get_variation_id_from_variation_data')) {
        function wc_get_variation_id_from_variation_data($parent_id, $variation_data) {
            $data_store = WC_Data_Store::load('product-variable');
            return $data_store->find_matching_product_variation(wc_get_product($parent_id), $variation_data);
        }
    }

    // Validation
    add_filter('woocommerce_add_to_cart_validation', 'wc_super_bundle_add_to_cart_validation', 10, 3);
    function wc_super_bundle_add_to_cart_validation($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('super_bundle')) return $passed;

        $bundle_type = get_post_meta($product_id, '_bundle_type', true);
        $bundleducts = get_post_meta($product_id, '_bundleducts', true) ?: [];
        $min_items = absint(get_post_meta($product_id, '_bundle_min_items', true));
        $max_items = absint(get_post_meta($product_id, '_bundle_max_items', true));
        $min_total = floatval(get_post_meta($product_id, '_bundle_min_total', true));
        $max_total = floatval(get_post_meta($product_id, '_bundle_max_total', true));

        if ($bundle_type === 'closed') {
            foreach ($bundleducts as $product_data) {
                $p_id = $product_data['id'];
                $p = wc_get_product($p_id);
                if (!$p || !$p->is_in_stock()) {
                    wc_add_notice(sprintf(__('%s is out of stock.', 'woocommerce-super-bundle'), $p->get_name()), 'error');
                    return false;
                }
            }
            return $passed;
        }

        $selected_quantities = isset($_POST['bundle_quantities']) ? array_keys((array) $_POST['bundle_quantities']) : [];
        $total_qty = count($selected_quantities);
        if ($total_qty < $min_items) {
            wc_add_notice(sprintf(__('Select at least %d products', 'woocommerce-super-bundle'), $min_items), 'error');
            return false;
        }
        if ($max_items > 0 && $total_qty > $max_items) {
            wc_add_notice(sprintf(__('Cannot select more than %d products', 'woocommerce-super-bundle'), $max_items), 'error');
            return false;
        }

        // Calculate total for min/max total check and stock
        $subtotal = 0;
        foreach ($bundleducts as $product_data) {
            $p_id = $product_data['id'];
            $qty = in_array($p_id, $selected_quantities) ? 1 : 0;
            if ($qty > 0) {
                $p = wc_get_product($p_id);
                if (!$p) continue;
                $price = floatval($p->get_price('view'));
                $selected_var_id = 0;
                if (isset($_POST['bundle_variations'][$p_id])) {
                    $var_attrs = array_map('sanitize_text_field', $_POST['bundle_variations'][$p_id]);
                    $selected_var_id = wc_get_variation_id_from_variation_data($p_id, $var_attrs);
                    $var = wc_get_product($selected_var_id);
                    if ($var) {
                        $price = floatval($var->get_price('view'));
                        $p = $var; // For stock check
                    } else {
                        wc_add_notice(sprintf(__('Invalid variation for %s.', 'woocommerce-super-bundle'), $p->get_name()), 'error');
                        return false;
                    }
                }
                if (!$p->is_in_stock()) {
                    wc_add_notice(sprintf(__('%s is out of stock.', 'woocommerce-super-bundle'), $p->get_name()), 'error');
                    return false;
                }
                $subtotal += $price * $qty;
            }
        }
        $discount_type = get_post_meta($product_id, '_bundle_discount_type', true);
        $discount_value = floatval(get_post_meta($product_id, '_bundle_discount_value', true));
        $total = $subtotal;
        if ($discount_type === 'percent') {
            $total *= (1 - $discount_value / 100);
        } else {
            $total -= $discount_value;
        }
        $total = max(0, $total);
        if ($min_total > 0 && $total < $min_total) {
            wc_add_notice(sprintf(__('Total must be at least %s', 'woocommerce-super-bundle'), wc_price($min_total)), 'error');
            return false;
        }
        if ($max_total > 0 && $total > $max_total) {
            wc_add_notice(sprintf(__('Total cannot exceed %s', 'woocommerce-super-bundle'), wc_price($max_total)), 'error');
            return false;
        }

        return $passed;
    }

    // Cart data
    add_filter('woocommerce_add_cart_item_data', 'wc_super_bundle_add_cart_item_data', 10, 3);
    function wc_super_bundle_add_cart_item_data($cart_item_data, $product_id, $variation_id = 0) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('super_bundle')) return $cart_item_data;

        $bundle_type = get_post_meta($product_id, '_bundle_type', true);
        $bundleducts = get_post_meta($product_id, '_bundleducts', true) ?: [];
        $quantities = [];
        $variations = [];
        if ($bundle_type === 'closed') {
            foreach ($bundleducts as $pd) {
                $quantities[$pd['id']] = 1;
            }
        } else {
            $selected_quantities = isset($_POST['bundle_quantities']) ? array_keys((array) $_POST['bundle_quantities']) : [];
            foreach ($bundleducts as $pd) {
                $p_id = $pd['id'];
                $quantities[$p_id] = in_array($p_id, $selected_quantities) ? 1 : 0;
            }
            $variations = isset($_POST['bundle_variations']) ? (array) $_POST['bundle_variations'] : [];
        }
        $cart_item_data['super_bundle_data'] = [
            'quantities' => $quantities,
            'variations' => $variations,
        ];
        $cart_item_data['unique_key'] = md5(microtime() . rand());
        return $cart_item_data;
    }

    // Handle edit - remove old item after adding new
    add_action('woocommerce_add_to_cart', 'wc_super_bundle_handle_edit_after_add', 10, 6);
    function wc_super_bundle_handle_edit_after_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (isset($_POST['editing_bundle_key'])) {
            $old_key = sanitize_text_field($_POST['editing_bundle_key']);
            WC()->cart->remove_cart_item($old_key);
        }
    }

    // Cart display
    add_filter('woocommerce_get_item_data', 'wc_super_bundle_get_item_data', 10, 2);
    function wc_super_bundle_get_item_data($item_data, $cart_item) {
        if (!empty($cart_item['super_bundle_data'])) {
            $defaults = [
                'bundle_contents' => __('Bundle Contents', 'woocommerce-super-bundle'),
            ];
            $translations = wp_parse_args( get_option('wc_super_bundle_translations', $defaults), $defaults );
            $contents = '<ul class="wc-item-meta">';
            $quantities = $cart_item['super_bundle_data']['quantities'];
            $variations = $cart_item['super_bundle_data']['variations'];
            foreach ($quantities as $p_id => $qty) {
                if ($qty > 0) {
                    $p = wc_get_product($p_id);
                    if ($p) {
                        $display_name = $p->get_name();
                        if (!empty($variations[$p_id])) {
                            $var_id = wc_get_variation_id_from_variation_data($p_id, $variations[$p_id]);
                            $var = wc_get_product($var_id);
                            if ($var) {
                                $display_name = $var->get_name();
                            }
                        }
                        $contents .= '<li class="variation">' . esc_html($display_name) . '</li>';
                    }
                }
            }
            $contents .= '</ul>';
            $item_data[] = [
                'name' => $translations['bundle_contents'],
                'value' => $contents,
            ];
        }
        return $item_data;
    }

    // Cart totals
    add_action('woocommerce_before_calculate_totals', 'wc_super_bundle_before_calculate_totals', 10, 1);
    function wc_super_bundle_before_calculate_totals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['super_bundle_data'])) {
                $product_id = $cart_item['product_id'];
                $price_mode = get_post_meta($product_id, '_bundle_price_mode', true);
                if ($price_mode === 'fixed') {
                    $fixed_price = get_post_meta($product_id, '_bundle_fixed_price', true);
                    $cart_item['data']->set_price(floatval($fixed_price));
                } else {
                    $discount_type = get_post_meta($product_id, '_bundle_discount_type', true);
                    $discount_value = floatval(get_post_meta($product_id, '_bundle_discount_value', true));
                    $subtotal = 0;
                    foreach ($cart_item['super_bundle_data']['quantities'] as $p_id => $qty) {
                        if ($qty > 0) {
                            $p = wc_get_product($p_id);
                            $price = floatval($p->get_price('edit'));
                            if (isset($cart_item['super_bundle_data']['variations'][$p_id])) {
                                $var_id = wc_get_variation_id_from_variation_data($p_id, $cart_item['super_bundle_data']['variations'][$p_id]);
                                $var = wc_get_product($var_id);
                                if ($var) $price = floatval($var->get_price('edit'));
                            }
                            $subtotal += $price;
                        }
                    }
                    $total = $subtotal;
                    if ($discount_type === 'percent') {
                        $total *= (1 - $discount_value / 100);
                    } else {
                        $total -= $discount_value;
                    }
                    $cart_item['data']->set_price(max(0, $total));
                }
            }
        }
    }

    // Shipping and tax hooks (placeholder, implement if needed)
    add_filter('woocommerce_cart_shipping_packages', 'wc_super_bundle_shipping_packages');
    function wc_super_bundle_shipping_packages($packages) {
        return $packages;
    }

    // Edit handling
    add_action('template_redirect', 'wc_super_bundle_handle_edit');
    function wc_super_bundle_handle_edit() {
        if (isset($_GET['edit_bundle']) && is_product()) {
            $cart_key = sanitize_text_field($_GET['edit_bundle']);
            $cart_item = WC()->cart->get_cart_item($cart_key);
            if ($cart_item && !empty($cart_item['super_bundle_data']) && $cart_item['product_id'] == get_the_ID()) {
                WC()->session->set('edit_bundle_data_' . get_the_ID(), $cart_item['super_bundle_data']);
            }
        }
    }

}
