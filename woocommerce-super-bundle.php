<?php
/*
Plugin Name: WooCommerce Super Bundle Pro
Plugin URI: https://github.com/Finland93/WooCommerce-Super-Bundle-Pro
Description: The ultimate WooCommerce bundle plugin inspired by the best features. Create customizable product bundles with fixed or dynamic pricing, discounts, variation support, and min/max quantity limits. Supports open and closed bundles for maximum flexibility.
Version: 2.0.0
Author: Finland93
Author URI: https://github.com/Finland93
License: GPL-2.0
License URI: https://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: woocommerce-super-bundle-pro
Requires PHP: 7.4
Requires at least: 5.0
Tested up to: 6.6
WC requires at least: 8.0
WC tested up to: 9.0
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// PHP version check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Super Bundle Pro requires PHP 7.4 or higher.', 'woocommerce-super-bundle-pro') . '</p></div>';
    });
    return;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Super Bundle Pro requires WooCommerce to be installed and active.', 'woocommerce-super-bundle-pro') . '</p></div>';
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
register_uninstall_hook(__FILE__, 'wc_super_bundle_pro_uninstall');
function wc_super_bundle_pro_uninstall() {
    delete_option('wc_super_bundle_pro_translations');
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
        '_bundle_products',
        '_bundle_shipping_method',
        '_bundle_tax_status',
    ];
    $all_bundles = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'product' AND p.post_status = 'publish' 
        AND pm.meta_key = '_product_type' AND pm.meta_value = 'super_bundle_pro'
    ");
    foreach ($all_bundles as $bundle_id) {
        foreach ($meta_keys as $meta_key) {
            delete_post_meta($bundle_id, $meta_key);
        }
        wc_super_bundle_pro_delete_recursive($bundle_id, $meta_keys);
    }
}
function wc_super_bundle_pro_delete_recursive($bundle_id, $meta_keys) {
    global $wpdb;
    $all_bundles = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'product' AND p.post_status = 'publish' 
        AND pm.meta_key = '_product_type' AND pm.meta_value = 'super_bundle_pro'
    ");
    $child_bundles = [];
    foreach ($all_bundles as $potential_child) {
        if ($potential_child == $bundle_id) continue;
        $child_products = get_post_meta($potential_child, '_bundle_products', true);
        if (is_array($child_products) && in_array($bundle_id, array_column($child_products, 'id'))) {
            $child_bundles[] = $potential_child;
        }
    }
    foreach ($child_bundles as $child_id) {
        foreach ($meta_keys as $meta_key) {
            delete_post_meta($child_id, $meta_key);
        }
        wc_super_bundle_pro_delete_recursive($child_id, $meta_keys);
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'wc_super_bundle_pro_init', 11);
function wc_super_bundle_pro_init() {
    if (!class_exists('WC_Product')) {
        return;
    }

    // Enqueue admin assets
    add_action('admin_enqueue_scripts', 'wc_super_bundle_pro_admin_assets');
    function wc_super_bundle_pro_admin_assets($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        global $post;
        if ($post->post_type !== 'product') return;
        wp_enqueue_style('wc-super-bundle-pro-admin', plugin_dir_url(__FILE__) . 'admin.css', [], '2.0.0');
        wp_enqueue_script('wc-super-bundle-pro-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '2.0.0', true);
    }

    // Custom product class
    class WC_Product_Super_Bundle_Pro extends WC_Product {
        public function __construct($product) {
            $this->product_type = 'super_bundle_pro';
            parent::__construct($product);
        }

        public function get_type() {
            return 'super_bundle_pro';
        }

        public function is_purchasable() {
            return $this->is_in_stock();
        }

        public function is_in_stock() {
            $bundle_type = get_post_meta($this->get_id(), '_bundle_type', true);
            $products = get_post_meta($this->get_id(), '_bundle_products', true);
            if (empty($products)) return false;
            if ($bundle_type === 'closed') {
                foreach ($products as $product_data) {
                    $product_id = $product_data['id'];
                    $qty = intval($product_data['qty'] ?? 1);
                    $product = wc_get_product($product_id);
                    if (!$product || !$product->has_enough_stock($qty)) return false;
                }
            } else {
                $min_items = absint(get_post_meta($this->get_id(), '_bundle_min_items', true)) ?: 1;
                $available_units = 0;
                foreach ($products as $product_data) {
                    $product = wc_get_product($product_data['id']);
                    if ($product && $product->is_in_stock()) {
                        if ($product->managing_stock()) {
                            $available_units += max(0, (int) $product->get_stock_quantity());
                        } else {
                            $available_units += 999999;
                        }
                    }
                }
                if ($available_units < $min_items) return false;
            }
            return true;
        }

        public function get_price($context = 'view') {
            $price_mode = get_post_meta($this->get_id(), '_bundle_price_mode', true) ?: 'auto';
            $bundle_type = get_post_meta($this->get_id(), '_bundle_type', true) ?: 'open';
            if ($price_mode === 'fixed') {
                $fixed_price = get_post_meta($this->get_id(), '_bundle_fixed_price', true);
                return floatval($fixed_price);
            } else {
                $discount_type = get_post_meta($this->get_id(), '_bundle_discount_type', true) ?: 'percent';
                $discount_value = floatval(get_post_meta($this->get_id(), '_bundle_discount_value', true)) ?: 0;
                $products = get_post_meta($this->get_id(), '_bundle_products', true) ?: [];
                if ($bundle_type === 'closed') {
                    $total = 0;
                    foreach ($products as $product_data) {
                        $product = wc_get_product($product_data['id']);
                        if ($product) {
                            $price = floatval($product->get_price($context));
                            $qty = intval($product_data['qty'] ?? 1);
                            $total += $price * $qty;
                        }
                    }
                } else {
                    // Min price for open
                    $min_prices = [];
                    foreach ($products as $product_data) {
                        $product = wc_get_product($product_data['id']);
                        if ($product) {
                            $min_price = $product->is_type('variable') ? wc_super_bundle_pro_get_min_variation_price($product) : floatval($product->get_price($context));
                            if ($min_price > 0) $min_prices[] = $min_price;
                        }
                    }
                    if (empty($min_prices)) return 0;
                    $min_unit = min($min_prices);
                    $min_items = absint(get_post_meta($this->get_id(), '_bundle_min_items', true)) ?: 1;
                    $total = $min_unit * $min_items;
                }
                if ($discount_type === 'percent') {
                    $total *= (1 - $discount_value / 100);
                } else {
                    $total -= $discount_value;
                }
                return max(0, $total);
            }
        }
    }

    // Helper for min variation price
    if (!function_exists('wc_super_bundle_pro_get_min_variation_price')) {
        function wc_super_bundle_pro_get_min_variation_price($product) {
            if (!$product || !$product->is_type('variable')) return 0;
            $prices = [];
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && $child->get_price('edit') > 0) {
                    $prices[] = floatval($child->get_price('edit'));
                }
            }
            return !empty($prices) ? min($prices) : 0;
        }
    }

    // Register product class
    add_filter('woocommerce_product_class', function($classname, $product_type, $post_type, $product_id) {
        if ($product_type === 'super_bundle_pro') {
            return 'WC_Product_Super_Bundle_Pro';
        }
        return $classname;
    }, 10, 4);

    // Add to product types
    add_filter('product_type_selector', function($types) {
        $types['super_bundle_pro'] = __('Super Bundle Pro', 'woocommerce-super-bundle-pro');
        return $types;
    });

    // Hide tabs
    add_filter('woocommerce_product_data_tabs', function($tabs) {
        $tabs['inventory']['class'][] = 'hide_if_super_bundle_pro';
        $tabs['shipping']['class'][] = 'hide_if_super_bundle_pro';
        $tabs['linked_product']['class'][] = 'hide_if_super_bundle_pro';
        $tabs['attribute']['class'][] = 'hide_if_super_bundle_pro';
        $tabs['advanced']['class'][] = 'hide_if_super_bundle_pro';
        return $tabs;
    }, 10, 1);

    // Add tab
    add_filter('woocommerce_product_data_tabs', function($tabs) {
        $tabs['super_bundle_pro'] = [
            'label'  => __('Super Bundle Pro', 'woocommerce-super-bundle-pro'),
            'target' => 'super_bundle_pro_data',
            'class'  => ['show_if_super_bundle_pro'],
        ];
        return $tabs;
    }, 10, 1);

    // Panel
    add_action('woocommerce_product_data_panels', 'wc_super_bundle_pro_product_data_panel');
    function wc_super_bundle_pro_product_data_panel() {
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
        $bundle_products = get_post_meta($post->ID, '_bundle_products', true) ?: [];
        ?>
        <div id="super_bundle_pro_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_select([
                    'id'      => '_bundle_type',
                    'label'   => __('Bundle Type', 'woocommerce-super-bundle-pro'),
                    'options' => [
                        'open'   => __('Open (Customizable)', 'woocommerce-super-bundle-pro'),
                        'closed' => __('Closed (Fixed)', 'woocommerce-super-bundle-pro'),
                    ],
                    'value'   => $bundle_type,
                    'desc_tip' => true,
                    'description' => __('Open: Customers can adjust quantities and select variations. Closed: Fixed items and quantities.', 'woocommerce-super-bundle-pro'),
                ]);
                woocommerce_wp_select([
                    'id'          => '_bundle_price_mode',
                    'label'       => __('Price Mode', 'woocommerce-super-bundle-pro'),
                    'options'     => [
                        'auto'   => __('Auto (with Discount)', 'woocommerce-super-bundle-pro'),
                        'fixed'  => __('Fixed Price', 'woocommerce-super-bundle-pro'),
                    ],
                    'value'   => $price_mode,
                    'desc_tip' => true,
                    'description' => __('Auto: Sum of products minus discount. Fixed: Manual price.', 'woocommerce-super-bundle-pro'),
                ]);
                ?>
                <div class="show_if_auto_price" style="display: none;">
                    <?php
                    woocommerce_wp_select([
                        'id'          => '_bundle_discount_type',
                        'label'       => __('Discount Type', 'woocommerce-super-bundle-pro'),
                        'options'     => [
                            'percent' => __('Percentage %', 'woocommerce-super-bundle-pro'),
                            'fixed'   => __('Fixed Amount', 'woocommerce-super-bundle-pro'),
                        ],
                        'value'   => $discount_type,
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_discount_value',
                        'label'       => __('Discount Value', 'woocommerce-super-bundle-pro'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 0, 'step' => 0.01],
                        'value'       => $discount_value,
                        'desc_tip' => true,
                        'description' => __('Percentage (0-100) or fixed amount discount on total.', 'woocommerce-super-bundle-pro'),
                    ]);
                    ?>
                </div>
                <div class="show_if_fixed_price" style="display: none;">
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_fixed_price',
                        'label'       => __('Fixed Price', 'woocommerce-super-bundle-pro'),
                        'data_type'   => 'price',
                        'value'       => $fixed_price,
                        'desc_tip' => true,
                        'description' => __('Manual price for the entire bundle.', 'woocommerce-super-bundle-pro'),
                    ]);
                    ?>
                </div>
                <div class="show_if_open_bundle" style="display: none;">
                    <?php
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_min_items',
                        'label'       => __('Min Items', 'woocommerce-super-bundle-pro'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 1],
                        'value'       => $min_items,
                        'desc_tip' => true,
                        'description' => __('Minimum total items to select.', 'woocommerce-super-bundle-pro'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_max_items',
                        'label'       => __('Max Items', 'woocommerce-super-bundle-pro'),
                        'type'        => 'number',
                        'custom_attributes' => ['min' => 0],
                        'value'       => $max_items,
                        'desc_tip' => true,
                        'description' => __('Maximum total items to select. 0 for unlimited.', 'woocommerce-super-bundle-pro'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_min_total',
                        'label'       => __('Min Total Amount', 'woocommerce-super-bundle-pro'),
                        'data_type'   => 'price',
                        'value'       => $min_total,
                        'desc_tip' => true,
                        'description' => __('Minimum bundle total value.', 'woocommerce-super-bundle-pro'),
                    ]);
                    woocommerce_wp_text_input([
                        'id'          => '_bundle_max_total',
                        'label'       => __('Max Total Amount', 'woocommerce-super-bundle-pro'),
                        'data_type'   => 'price',
                        'value'       => $max_total,
                        'desc_tip' => true,
                        'description' => __('Maximum bundle total value.', 'woocommerce-super-bundle-pro'),
                    ]);
                    ?>
                </div>
                <?php
                woocommerce_wp_select([
                    'id'          => '_bundle_shipping_method',
                    'label'       => __('Shipping Method', 'woocommerce-super-bundle-pro'),
                    'options'     => [
                        'bundled'  => __('As Bundle', 'woocommerce-super-bundle-pro'),
                        'separate' => __('Separate Items', 'woocommerce-super-bundle-pro'),
                    ],
                    'value'   => $shipping_method,
                    'desc_tip' => true,
                    'description' => __('How shipping is calculated for the bundle.', 'woocommerce-super-bundle-pro'),
                ]);
                woocommerce_wp_select([
                    'id'          => '_bundle_tax_status',
                    'label'       => __('Tax Status', 'woocommerce-super-bundle-pro'),
                    'options'     => [
                        'bundled'  => __('As Bundle', 'woocommerce-super-bundle-pro'),
                        'separate' => __('Separate Items', 'woocommerce-super-bundle-pro'),
                    ],
                    'value'   => $tax_status,
                    'desc_tip' => true,
                    'description' => __('How taxes are applied to the bundle.', 'woocommerce-super-bundle-pro'),
                ]);
                ?>
                <p class="form-field">
                    <label for="bundle_products"><?php _e('Bundle Products', 'woocommerce-super-bundle-pro'); ?></label>
                    <select class="wc-product-search" multiple="multiple" style="width: 50%;" id="bundle_products" name="bundle_products[]" data-placeholder="<?php esc_attr_e('Search for a product&hellip;', 'woocommerce'); ?>" data-action="woocommerce_json_search_products_and_variations" data-exclude="<?php echo absint($post->ID); ?>">
                        <?php
                        if (!empty($bundle_products)) {
                            foreach ($bundle_products as $product_data) {
                                $product_id = $product_data['id'];
                                $product = wc_get_product($product_id);
                                if (is_object($product)) {
                                    $qty = intval($product_data['qty'] ?? 1);
                                    echo '<option value="' . esc_attr($product_id) . '" selected="selected" data-qty="' . $qty . '">' . wp_kses_post($product->get_formatted_name()) . ' (Qty: ' . $qty . ')</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <?php echo wc_help_tip(__('Select products/variations. For open bundles, set default quantities.', 'woocommerce-super-bundle-pro')); ?>
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
    add_action('woocommerce_process_product_meta_super_bundle_pro', 'wc_super_bundle_pro_save_product_data');
    function wc_super_bundle_pro_save_product_data($post_id) {
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
        $bundle_products_input = isset($_POST['bundle_products']) ? array_map('absint', (array) $_POST['bundle_products']) : [];

        // Process products with quantities (default 1)
        $bundle_products = [];
        foreach ($bundle_products_input as $product_id) {
            $qty = absint($_POST['bundle_qty_' . $product_id] ?? 1);
            $bundle_products[] = [
                'id' => $product_id,
                'qty' => $qty,
            ];
        }

        // Validation
        if (empty($bundle_products)) {
            WC_Admin_Meta_Boxes::add_error(__('Select at least one product.', 'woocommerce-super-bundle-pro'));
            return;
        }
        if ($discount_value < 0 || ($discount_type === 'percent' && $discount_value > 100)) {
            WC_Admin_Meta_Boxes::add_error(__('Invalid discount value.', 'woocommerce-super-bundle-pro'));
            $discount_value = max(0, min(100, $discount_value));
        }
        if ($bundle_type === 'open') {
            $min_items = max(1, $min_items);
        } else {
            $min_items = $max_items = array_sum(array_column($bundle_products, 'qty'));
        }

        // Recursion check
        if (wc_super_bundle_pro_has_recursion($post_id, array_column($bundle_products, 'id'))) {
            WC_Admin_Meta_Boxes::add_error(__('Recursive bundle detected.', 'woocommerce-super-bundle-pro'));
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
        update_post_meta($post_id, '_bundle_products', $bundle_products);

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
    function wc_super_bundle_pro_has_recursion($bundle_id, $direct_products, $checked = [], $depth = 0) {
        if ($depth > 20) return true;
        $checked[] = $bundle_id;
        foreach ($direct_products as $p_id) {
            if (in_array($p_id, $checked)) return true;
            $p = wc_get_product($p_id);
            if (!$p) continue;
            $p_type = $p->get_type();
            if ($p_type === 'super_bundle_pro') {
                $child_products = array_column(get_post_meta($p_id, '_bundle_products', true) ?: [], 'id');
                if (wc_super_bundle_pro_has_recursion($p_id, $child_products, $checked, $depth + 1)) return true;
            }
        }
        return false;
    }

    // Price HTML
    add_filter('woocommerce_get_price_html', function($price_html, $product) {
        if ($product->is_type('super_bundle_pro')) {
            $bundle_type = get_post_meta($product->get_id(), '_bundle_type', true);
            $price_mode = get_post_meta($product->get_id(), '_bundle_price_mode', true);
            if ($price_mode === 'auto' && $bundle_type === 'open') {
                $price_html = sprintf(__('From %s', 'woocommerce-super-bundle-pro'), wc_price($product->get_price()));
            } else {
                $price_html = wc_price($product->get_price());
            }
        }
        return $price_html;
    }, 10, 2);

    // Remove default add to cart
    add_action('woocommerce_single_product_summary', 'wc_super_bundle_pro_remove_default_add_to_cart', 1);
    function wc_super_bundle_pro_remove_default_add_to_cart() {
        global $product;
        if ($product && $product->is_type('super_bundle_pro')) {
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            add_action('woocommerce_single_product_summary', 'wc_super_bundle_pro_render_add_to_cart', 30);
        }
    }

    // Render add to cart
    function wc_super_bundle_pro_render_add_to_cart() {
        global $product;
        if (!$product || !$product->is_type('super_bundle_pro')) return;

        $bundle_id = $product->get_id();
        $bundle_type = get_post_meta($bundle_id, '_bundle_type', true) ?: 'open';
        $bundle_products = get_post_meta($bundle_id, '_bundle_products', true) ?: [];
        $min_items = absint(get_post_meta($bundle_id, '_bundle_min_items', true)) ?: 1;
        $max_items = absint(get_post_meta($bundle_id, '_bundle_max_items', true)) ?: 0;
        $min_total = floatval(get_post_meta($bundle_id, '_bundle_min_total', true)) ?: 0;
        $max_total = floatval(get_post_meta($bundle_id, '_bundle_max_total', true)) ?: 0;
        $price_mode = get_post_meta($bundle_id, '_bundle_price_mode', true) ?: 'auto';
        $discount_type = get_post_meta($bundle_id, '_bundle_discount_type', true) ?: 'percent';
        $discount_value = floatval(get_post_meta($bundle_id, '_bundle_discount_value', true)) ?: 0;
        $shipping_method = get_post_meta($bundle_id, '_bundle_shipping_method', true) ?: 'bundled';

        $translations = get_option('wc_super_bundle_pro_translations', [
            'select_items_min_max' => __('Select %1$d to %2$d items', 'woocommerce-super-bundle-pro'),
            'select_items_min' => __('Select at least %d items', 'woocommerce-super-bundle-pro'),
            'fixed_bundle' => __('Fixed Bundle: %d items', 'woocommerce-super-bundle-pro'),
            'total' => __('Bundle Total: ', 'woocommerce-super-bundle-pro'),
            'add_to_cart' => __('Add to Cart', 'woocommerce-super-bundle-pro'),
            'out_of_stock' => __('Out of stock', 'woocommerce-super-bundle-pro'),
            'min_total_error' => __('Total must be at least %s', 'woocommerce-super-bundle-pro'),
            'max_total_error' => __('Total cannot exceed %s', 'woocommerce-super-bundle-pro'),
            'min_items_error' => __('Select at least %d items', 'woocommerce-super-bundle-pro'),
            'max_items_error' => __('Cannot select more than %d items', 'woocommerce-super-bundle-pro'),
            'select_variation' => __('Please select all variations.', 'woocommerce-super-bundle-pro'),
        ]);

        if (empty($bundle_products)) {
            echo '<div class="woocommerce-error">' . esc_html__('No products in bundle.', 'woocommerce-super-bundle-pro') . '</div>';
            return;
        }

        $fixed_qty_sum = array_sum(array_column($bundle_products, 'qty'));
        $header_text = $bundle_type === 'open' 
            ? ($max_items > 0 ? sprintf($translations['select_items_min_max'], $min_items, $max_items) : sprintf($translations['select_items_min'], $min_items))
            : sprintf($translations['fixed_bundle'], $fixed_qty_sum);

        // Closed total
        $closed_total = $product->get_price();
        ?>
        <style>
            .super-bundle-pro { margin: 20px 0; }
            .bundle-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
            .bundle-item { border: 1px solid #ddd; padding: 15px; border-radius: 5px; position: relative; }
            .bundle-item.out-of-stock { opacity: 0.5; }
            .bundle-item-image { max-width: 100%; height: auto; }
            .bundle-item-header { font-weight: bold; margin-bottom: 5px; }
            .bundle-qty-input { width: 60px; text-align: center; }
            .bundle-variation-select { width: 100%; margin: 5px 0; }
            .bundle-item-price { font-weight: bold; color: #e74c3c; }
            .bundle-total { font-size: 1.3em; font-weight: bold; margin: 15px 0; color: #27ae60; }
            .bundle-message { margin: 10px 0; }
            .bundle-search { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        </style>
        <form class="cart" method="post" enctype='multipart/form-data'>
            <div class="super-bundle-pro">
                <h3><?php echo esc_html($header_text); ?></h3>
                <?php if ($bundle_type === 'open') : ?>
                    <div id="bundle-total-price" class="bundle-total"><?php echo esc_html($translations['total']); ?><span class="price"><?php echo wc_price(0); ?></span></div>
                    <input type="text" id="bundle_search" placeholder="<?php esc_attr_e('Search products...', 'woocommerce-super-bundle-pro'); ?>" class="bundle-search">
                    <div class="bundle-items">
                        <?php foreach ($bundle_products as $index => $product_data) :
                            $p_id = $product_data['id'];
                            $default_qty = intval($product_data['qty'] ?? 1);
                            $p = wc_get_product($p_id);
                            if (!$p) continue;
                            $stock_status = $p->is_in_stock() ? '' : ' out-of-stock';
                            $base_price = floatval($p->get_price('view'));
                            $base_price_html = wc_price($base_price);
                        ?>
                            <div class="bundle-item <?php echo $stock_status; ?>" data-product-id="<?php echo esc_attr($p_id); ?>" data-price="<?php echo esc_attr($base_price); ?>" data-name="<?php echo esc_attr(strtolower($p->get_name())); ?>">
                                <img src="<?php echo wp_get_attachment_image_src($p->get_image_id(), 'thumbnail')[0] ?? wc_placeholder_img_src(); ?>" alt="<?php echo esc_attr($p->get_name()); ?>" class="bundle-item-image">
                                <div class="bundle-item-header"><?php echo esc_html($p->get_name()); ?></div>
                                <div class="bundle-item-price"><?php echo $base_price_html; ?></div>
                                <input type="number" class="bundle-qty-input" name="bundle_quantities[<?php echo $p_id; ?>]" value="<?php echo $default_qty; ?>" min="0" <?php echo $p->is_in_stock() ? '' : 'disabled'; ?>>
                                <?php if ($p->is_type('variable')) :
                                    $attributes = $p->get_variation_attributes();
                                    foreach ($attributes as $attr_key => $options) :
                                ?>
                                    <select class="bundle-variation-select" name="bundle_variations[<?php echo $p_id; ?>][<?php echo esc_attr($attr_key); ?>]" data-attr="<?php echo esc_attr($attr_key); ?>">
                                        <option value=""><?php esc_html_e('Choose an option', 'woocommerce'); ?></option>
                                        <?php foreach ($options as $option) : ?>
                                            <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endforeach; endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add-to-cart" value="<?php echo esc_attr($bundle_id); ?>" class="single_add_to_cart_button button alt" disabled><?php echo esc_html($translations['add_to_cart']); ?></button>
                <?php else : ?>
                    <ul class="bundle-closed-list">
                        <?php foreach ($bundle_products as $product_data) :
                            $p_id = $product_data['id'];
                            $p = wc_get_product($p_id);
                            $qty = intval($product_data['qty'] ?? 1);
                            if ($p) echo '<li>' . esc_html($p->get_name()) . ' x ' . $qty . ' - ' . wc_price(floatval($p->get_price()) * $qty) . '</li>';
                        endforeach; ?>
                    </ul>
                    <div class="bundle-total"><?php echo esc_html($translations['total']); ?><?php echo wc_price($closed_total); ?></div>
                    <button type="submit" name="add-to-cart" value="<?php echo esc_attr($bundle_id); ?>" class="single_add_to_cart_button button alt"><?php echo esc_html($translations['add_to_cart']); ?></button>
                <?php endif; ?>
                <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
            </div>
        </form>
        <?php if ($bundle_type === 'open') : ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var minItems = <?php echo $min_items; ?>;
                var maxItems = <?php echo $max_items; ?>;
                var minTotal = <?php echo $min_total; ?>;
                var maxTotal = <?php echo $max_total; ?>;
                var discountType = '<?php echo $discount_type; ?>';
                var discountValue = <?php echo $discount_value; ?>;
                var translations = <?php echo json_encode($translations); ?>;
                var $container = $('.super-bundle-pro');
                var $total = $container.find('.bundle-total .price');
                var $btn = $container.find('.single_add_to_cart_button');
                var $search = $container.find('.bundle-search');
                var currencySymbol = '<?php echo get_woocommerce_currency_symbol(); ?>';
                var decimals = <?php echo wc_get_price_decimals(); ?>;

                function formatPrice(num) {
                    return currencySymbol + num.toFixed(decimals);
                }

                function calculateTotal() {
                    var totalQty = 0;
                    var subtotal = 0;
                    var incomplete = false;
                    $container.find('.bundle-item').each(function() {
                        var $item = $(this);
                        var qty = parseInt($item.find('.bundle-qty-input').val() || 0);
                        totalQty += qty;
                        if (qty > 0) {
                            var price = parseFloat($item.data('price') || 0);
                            subtotal += price * qty;
                            // Check variations if qty > 0
                            if ($item.find('.bundle-variation-select').length > 0) {
                                $item.find('.bundle-variation-select').each(function() {
                                    if (!$(this).val()) incomplete = true;
                                });
                            }
                        }
                    });
                    var total = subtotal;
                    if (discountType === 'percent') {
                        total *= (1 - discountValue / 100);
                    } else {
                        total -= discountValue;
                    }
                    total = Math.max(0, total);
                    $total.html(formatPrice(total));

                    // Validation
                    var error = '';
                    if (totalQty < minItems) error = translations.min_items_error.replace('%d', minItems);
                    else if (maxItems > 0 && totalQty > maxItems) error = translations.max_items_error.replace('%d', maxItems);
                    else if (minTotal > 0 && total < minTotal) error = translations.min_total_error.replace('%s', formatPrice(minTotal));
                    else if (maxTotal > 0 && total > maxTotal) error = translations.max_total_error.replace('%s', formatPrice(maxTotal));
                    else if (incomplete) error = translations.select_variation;

                    $container.find('.bundle-message').remove();
                    if (error) {
                        $container.prepend('<div class="bundle-message woocommerce-error">' + error + '</div>');
                        $btn.prop('disabled', true);
                    } else {
                        $btn.prop('disabled', false);
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

                // Qty change
                $container.on('change input', '.bundle-qty-input', function() {
                    var $item = $(this).closest('.bundle-item');
                    var qty = parseInt($(this).val() || 0);
                    $item.find('.bundle-variation-select').prop('disabled', qty === 0);
                    calculateTotal();
                });

                // Variation change
                $container.on('change', '.bundle-variation-select', function() {
                    var $item = $(this).closest('.bundle-item');
                    var productId = $item.data('product-id');
                    var variations = {};
                    $item.find('.bundle-variation-select').each(function() {
                        variations[$(this).data('attr')] = $(this).val();
                    });
                    if (Object.values(variations).every(v => v)) {
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'wc_super_bundle_pro_get_variation_price',
                            product_id: productId,
                            variations: variations,
                            nonce: '<?php echo wp_create_nonce('wc_super_bundle_pro_nonce'); ?>'
                        }).done(function(resp) {
                            if (resp.success) {
                                $item.data('price', resp.data.price);
                                $item.find('.bundle-item-price').html(resp.data.html);
                            }
                            calculateTotal();
                        });
                    } else {
                        calculateTotal();
                    }
                });

                calculateTotal();
            });
        </script>
        <?php endif;
    }

    // AJAX for variation price
    add_action('wp_ajax_wc_super_bundle_pro_get_variation_price', 'wc_super_bundle_pro_ajax_get_variation_price');
    add_action('wp_ajax_nopriv_wc_super_bundle_pro_get_variation_price', 'wc_super_bundle_pro_ajax_get_variation_price');
    function wc_super_bundle_pro_ajax_get_variation_price() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wc_super_bundle_pro_nonce')) {
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
    add_filter('woocommerce_add_to_cart_validation', 'wc_super_bundle_pro_add_to_cart_validation', 10, 3);
    function wc_super_bundle_pro_add_to_cart_validation($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('super_bundle_pro')) return $passed;

        $bundle_type = get_post_meta($product_id, '_bundle_type', true);
        $bundle_products = get_post_meta($product_id, '_bundle_products', true) ?: [];
        $min_items = absint(get_post_meta($product_id, '_bundle_min_items', true));
        $max_items = absint(get_post_meta($product_id, '_bundle_max_items', true));
        $min_total = floatval(get_post_meta($product_id, '_bundle_min_total', true));
        $max_total = floatval(get_post_meta($product_id, '_bundle_max_total', true));

        if ($bundle_type === 'closed') {
            foreach ($bundle_products as $product_data) {
                $p_id = $product_data['id'];
                $qty = intval($product_data['qty'] ?? 1);
                $p = wc_get_product($p_id);
                if (!$p || !$p->has_enough_stock($qty)) {
                    wc_add_notice(sprintf(__('%s does not have enough stock.', 'woocommerce-super-bundle-pro'), $p->get_name()), 'error');
                    return false;
                }
            }
            return $passed;
        }

        $quantities = isset($_POST['bundle_quantities']) ? array_map('absint', (array) $_POST['bundle_quantities']) : [];
        $variations = isset($_POST['bundle_variations']) ? (array) $_POST['bundle_variations'] : [];
        $total_qty = array_sum($quantities);
        if ($total_qty < $min_items) {
            wc_add_notice(sprintf(__('Select at least %d items', 'woocommerce-super-bundle-pro'), $min_items), 'error');
            return false;
        }
        if ($max_items > 0 && $total_qty > $max_items) {
            wc_add_notice(sprintf(__('Cannot select more than %d items', 'woocommerce-super-bundle-pro'), $max_items), 'error');
            return false;
        }

        // Calculate total for min/max total check and stock
        $subtotal = 0;
        foreach ($quantities as $p_id => $qty) {
            if ($qty > 0) {
                $p = wc_get_product($p_id);
                if (!$p) continue;
                $price = floatval($p->get_price('edit'));
                $selected_var_id = 0;
                if (isset($variations[$p_id])) {
                    $var_attrs = array_map('sanitize_text_field', $variations[$p_id]);
                    $selected_var_id = wc_get_variation_id_from_variation_data($p_id, $var_attrs);
                    $var = wc_get_product($selected_var_id);
                    if ($var) {
                        $price = floatval($var->get_price('edit'));
                        $p = $var; // For stock check
                    } else {
                        wc_add_notice(sprintf(__('Invalid variation for %s.', 'woocommerce-super-bundle-pro'), $p->get_name()), 'error');
                        return false;
                    }
                }
                if (!$p->has_enough_stock($qty)) {
                    wc_add_notice(sprintf(__('%s does not have enough stock for quantity %d.', 'woocommerce-super-bundle-pro'), $p->get_name(), $qty), 'error');
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
            wc_add_notice(sprintf(__('Total must be at least %s', 'woocommerce-super-bundle-pro'), wc_price($min_total)), 'error');
            return false;
        }
        if ($max_total > 0 && $total > $max_total) {
            wc_add_notice(sprintf(__('Total cannot exceed %s', 'woocommerce-super-bundle-pro'), wc_price($max_total)), 'error');
            return false;
        }

        return $passed;
    }

    // Cart data
    add_filter('woocommerce_add_cart_item_data', 'wc_super_bundle_pro_add_cart_item_data', 10, 3);
    function wc_super_bundle_pro_add_cart_item_data($cart_item_data, $product_id, $variation_id = 0) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('super_bundle_pro')) return $cart_item_data;

        $bundle_type = get_post_meta($product_id, '_bundle_type', true);
        if ($bundle_type === 'closed') {
            $bundle_products = get_post_meta($product_id, '_bundle_products', true) ?: [];
            $quantities = [];
            foreach ($bundle_products as $pd) {
                $quantities[$pd['id']] = intval($pd['qty'] ?? 1);
            }
            $cart_item_data['super_bundle_pro_data'] = [
                'quantities' => $quantities,
                'variations' => [],
            ];
        } else {
            $cart_item_data['super_bundle_pro_data'] = [
                'quantities' => isset($_POST['bundle_quantities']) ? array_map('absint', (array) $_POST['bundle_quantities']) : [],
                'variations' => isset($_POST['bundle_variations']) ? (array) $_POST['bundle_variations'] : [],
            ];
        }
        $cart_item_data['unique_key'] = md5(microtime() . rand());
        return $cart_item_data;
    }

    // Cart display
    add_filter('woocommerce_get_item_data', 'wc_super_bundle_pro_get_item_data', 10, 2);
    function wc_super_bundle_pro_get_item_data($item_data, $cart_item) {
        if (!empty($cart_item['super_bundle_pro_data'])) {
            $contents = '<ul class="wc-item-meta">';
            $quantities = $cart_item['super_bundle_pro_data']['quantities'];
            $variations = $cart_item['super_bundle_pro_data']['variations'];
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
                        $contents .= '<li class="variation">' . esc_html($display_name) . ' &times; ' . absint($qty) . '</li>';
                    }
                }
            }
            $contents .= '</ul>';
            $item_data[] = [
                'name' => __('Bundle Contents', 'woocommerce-super-bundle-pro'),
                'value' => $contents,
            ];
        }
        return $item_data;
    }

    // Cart totals
    add_action('woocommerce_before_calculate_totals', 'wc_super_bundle_pro_before_calculate_totals', 10, 1);
    function wc_super_bundle_pro_before_calculate_totals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['super_bundle_pro_data'])) {
                $product_id = $cart_item['product_id'];
                $price_mode = get_post_meta($product_id, '_bundle_price_mode', true);
                if ($price_mode === 'fixed') {
                    $fixed_price = get_post_meta($product_id, '_bundle_fixed_price', true);
                    $cart_item['data']->set_price(floatval($fixed_price));
                } else {
                    $discount_type = get_post_meta($product_id, '_bundle_discount_type', true);
                    $discount_value = floatval(get_post_meta($product_id, '_bundle_discount_value', true));
                    $subtotal = 0;
                    foreach ($cart_item['super_bundle_pro_data']['quantities'] as $p_id => $qty) {
                        if ($qty > 0) {
                            $p = wc_get_product($p_id);
                            $price = floatval($p->get_price('edit'));
                            if (isset($cart_item['super_bundle_pro_data']['variations'][$p_id])) {
                                $var_id = wc_get_variation_id_from_variation_data($p_id, $cart_item['super_bundle_pro_data']['variations'][$p_id]);
                                $var = wc_get_product($var_id);
                                if ($var) $price = floatval($var->get_price('edit'));
                            }
                            $subtotal += $price * $qty;
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
    add_filter('woocommerce_cart_shipping_packages', 'wc_super_bundle_pro_shipping_packages');
    function wc_super_bundle_pro_shipping_packages($packages) {
        return $packages;
    }

    // Settings (simplified)
    add_filter('woocommerce_settings_tabs_array', function($tabs) {
        $tabs['super-bundle-pro'] = __('Super Bundle Pro', 'woocommerce-super-bundle-pro');
        return $tabs;
    }, 50);

    add_action('woocommerce_settings_tabs_super-bundle-pro', function() {
        woocommerce_admin_fields(wc_super_bundle_pro_get_settings());
    });

    add_action('woocommerce_update_options_super-bundle-pro', 'wc_super_bundle_pro_update_settings');
    function wc_super_bundle_pro_update_settings() {
        woocommerce_update_options(wc_super_bundle_pro_get_settings());
    }

    function wc_super_bundle_pro_get_settings() {
        return [
            'title' => ['title' => __('Settings', 'woocommerce-super-bundle-pro'), 'type' => 'title'],
            'end' => ['type' => 'sectionend'],
        ];
    }
}
