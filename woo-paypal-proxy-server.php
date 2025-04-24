<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Server
 * Plugin URI: https://yourwebsite.com
 * Description: Serves as a proxy for PayPal payments from external WooCommerce stores
 * Version: 1.0.0
 * Author: Masum Bilah
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-paypal-proxy-server
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPPPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPPPS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPPS_VERSION', '1.0.0');

/**
 * Check if WooCommerce is active
 */
function wppps_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wppps_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display WooCommerce missing notice
 */
function wppps_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce PayPal Proxy Server requires WooCommerce to be installed and active.', 'woo-paypal-proxy-server'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function wppps_init() {
    if (!wppps_check_woocommerce_active()) {
        return;
    }
    
    // Load required files
    require_once WPPPS_PLUGIN_DIR . 'includes/class-paypal-api.php';
    require_once WPPPS_PLUGIN_DIR . 'includes/class-rest-api.php';
    require_once WPPPS_PLUGIN_DIR . 'includes/class-admin.php';
    require_once WPPPS_PLUGIN_DIR . 'includes/class-woo-paypal-gateway-server.php';

    
    // Initialize classes
    $paypal_api = new WPPPS_PayPal_API();
    $rest_api = new WPPPS_REST_API($paypal_api);
    $admin = new WPPPS_Admin();
    
    add_filter('woocommerce_payment_gateways', 'wppps_add_gateway');

    
    // Register REST API routes
    add_action('rest_api_init', array($rest_api, 'register_routes'));
    
    // Add scripts and styles
    add_action('wp_enqueue_scripts', 'wppps_enqueue_scripts');
}
add_action('plugins_loaded', 'wppps_init');

/**
 * Enqueue scripts and styles
 */
function wppps_enqueue_scripts() {
    // Only enqueue on the PayPal Buttons template
    if (is_page_template('paypal-buttons-template.php') || isset($_GET['rest_route'])) {
        wp_enqueue_style('wppps-paypal-style', WPPPS_PLUGIN_URL . 'assets/css/paypal-buttons.css', array(), WPPPS_VERSION);
        wp_enqueue_script('wppps-paypal-script', WPPPS_PLUGIN_URL . 'assets/js/paypal-buttons.js', array('jquery'), WPPPS_VERSION, true);
        
        // Load PayPal SDK
        $paypal_api = new WPPPS_PayPal_API();
        $client_id = $paypal_api->get_client_id();
        $environment = $paypal_api->get_environment();
        
        wp_enqueue_script(
            'paypal-sdk',
            "https://www.paypal.com/sdk/js?client-id={$client_id}&currency=USD&intent=capture",
            array(),
            null,
            true
        );
        
        // Add localized data for the script
        wp_localize_script('wppps-paypal-script', 'wppps_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url(),
            'nonce' => wp_create_nonce('wppps-nonce'),
            'environment' => $environment,
        ));
    }
}

/**
 * Add settings link on plugin page
 */
function wppps_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wppps-settings">' . __('Settings', 'woo-paypal-proxy-server') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wppps_settings_link');

/**
 * Plugin activation hook
 */
function wppps_activate() {
    // Create necessary database tables or options if needed
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wppps_sites';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        site_url varchar(255) NOT NULL,
        site_name varchar(255) NOT NULL,
        api_key varchar(64) NOT NULL,
        api_secret varchar(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY api_key (api_key)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Create a transaction log table
    $log_table = $wpdb->prefix . 'wppps_transaction_log';
    
    $sql = "CREATE TABLE $log_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        site_id mediumint(9) NOT NULL,
        order_id varchar(64) NOT NULL,
        paypal_order_id varchar(64) NOT NULL,
        amount decimal(10,2) NOT NULL,
        currency varchar(3) NOT NULL,
        status varchar(20) NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at datetime DEFAULT NULL,
        transaction_data longtext,
        PRIMARY KEY  (id),
        KEY site_id (site_id),
        KEY order_id (order_id),
        KEY paypal_order_id (paypal_order_id)
    ) $charset_collate;";
    
    dbDelta($sql);
    
    // Add plugin options
    add_option('wppps_paypal_client_id', '');
    add_option('wppps_paypal_client_secret', '');
    add_option('wppps_paypal_environment', 'sandbox');
}
register_activation_hook(__FILE__, 'wppps_activate');

/**
 * Add PayPal Direct Gateway to WooCommerce
 */
function wppps_add_gateway($gateways) {
    $gateways[] = 'WPPPS_PayPal_Gateway';
    return $gateways;
}
/**
 * AJAX handler for creating a WooCommerce order
 */
function wppps_create_order_handler() {
    check_ajax_referer('wppps-paypal-nonce', 'nonce');
    
    $debug_mode = true; // Set to false in production
    
    // Log request data
    if ($debug_mode) {
        error_log('PayPal Direct - Create Order Request: ' . json_encode($_POST));
    }
    
    try {
        // Create order from checkout form data
        $checkout_form_data = $_POST;
        
        // Validate checkout fields with improved logic
        $errors = array();
        
        // Get all checkout fields
        $checkout_fields = WC()->checkout()->get_checkout_fields();
        
        // Check if user is logged in
        $is_user_logged_in = is_user_logged_in();
        
        // Check if creating account
        $create_account = !empty($checkout_form_data['createaccount']) && $checkout_form_data['createaccount'] == 1;
        
        // Check if shipping to different address
        $ship_to_different_address = !empty($checkout_form_data['ship_to_different_address']) && $checkout_form_data['ship_to_different_address'] == 1;
        
        error_log('PayPal Direct - Validating form: User logged in: ' . ($is_user_logged_in ? 'Yes' : 'No') . 
                ', Create account: ' . ($create_account ? 'Yes' : 'No') . 
                ', Ship to different address: ' . ($ship_to_different_address ? 'Yes' : 'No'));
        
        // Loop through field groups and validate conditionally
        foreach ($checkout_fields as $fieldset_key => $fieldset) {
            // Skip account fields if user is already logged in
            if ($fieldset_key === 'account' && $is_user_logged_in) {
                continue;
            }
            
            // Skip account fields if not creating account
            if ($fieldset_key === 'account' && !$create_account) {
                continue;
            }
            
            // Skip shipping fields if not shipping to different address
            if ($fieldset_key === 'shipping' && !$ship_to_different_address) {
                continue;
            }
            
            foreach ($fieldset as $key => $field) {
                // Only validate required fields that are empty
                if (!empty($field['required']) && empty($checkout_form_data[$key])) {
                    $errors[$key] = sprintf(__('%s is a required field.', 'woocommerce'), $field['label']);
                    error_log('PayPal Direct - Field validation error: ' . $key);
                }
            }
        }
        
        // If we have validation errors, return them
        if (!empty($errors)) {
            error_log('PayPal Direct - Validation errors found: ' . json_encode($errors));
            wp_send_json_error(array(
                'message' => __('Please fill in all required fields.', 'woo-paypal-proxy-server'),
                'errors' => $errors
            ));
            wp_die();
        }
        
        // Create new order
        $order = wc_create_order();
        
        // Set billing address
        $billing_address = array(
            'first_name' => sanitize_text_field($checkout_form_data['billing_first_name']),
            'last_name'  => sanitize_text_field($checkout_form_data['billing_last_name']),
            'company'    => isset($checkout_form_data['billing_company']) ? sanitize_text_field($checkout_form_data['billing_company']) : '',
            'email'      => sanitize_email($checkout_form_data['billing_email']),
            'phone'      => isset($checkout_form_data['billing_phone']) ? sanitize_text_field($checkout_form_data['billing_phone']) : '',
            'address_1'  => sanitize_text_field($checkout_form_data['billing_address_1']),
            'address_2'  => isset($checkout_form_data['billing_address_2']) ? sanitize_text_field($checkout_form_data['billing_address_2']) : '',
            'city'       => sanitize_text_field($checkout_form_data['billing_city']),
            'state'      => sanitize_text_field($checkout_form_data['billing_state']),
            'postcode'   => sanitize_text_field($checkout_form_data['billing_postcode']),
            'country'    => sanitize_text_field($checkout_form_data['billing_country']),
        );
        $order->set_address($billing_address, 'billing');
        
        // Set shipping address
        $ship_to_different_address = isset($checkout_form_data['ship_to_different_address']) && $checkout_form_data['ship_to_different_address'];
        
        if ($ship_to_different_address) {
            $shipping_address = array(
                'first_name' => sanitize_text_field($checkout_form_data['shipping_first_name']),
                'last_name'  => sanitize_text_field($checkout_form_data['shipping_last_name']),
                'company'    => isset($checkout_form_data['shipping_company']) ? sanitize_text_field($checkout_form_data['shipping_company']) : '',
                'address_1'  => sanitize_text_field($checkout_form_data['shipping_address_1']),
                'address_2'  => isset($checkout_form_data['shipping_address_2']) ? sanitize_text_field($checkout_form_data['shipping_address_2']) : '',
                'city'       => sanitize_text_field($checkout_form_data['shipping_city']),
                'state'      => sanitize_text_field($checkout_form_data['shipping_state']),
                'postcode'   => sanitize_text_field($checkout_form_data['shipping_postcode']),
                'country'    => sanitize_text_field($checkout_form_data['shipping_country']),
            );
        } else {
            $shipping_address = $billing_address;
        }
        $order->set_address($shipping_address, 'shipping');
        
        // Add shipping method
        if (WC()->session) {
            $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
            
            if (!empty($chosen_shipping_methods)) {
                foreach (WC()->shipping()->get_packages() as $package_key => $package) {
                    if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                        $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                        
                        $item = new WC_Order_Item_Shipping();
                        $item->set_props(array(
                            'method_title' => $shipping_rate->get_label(),
                            'method_id'    => $shipping_rate->get_id(),
                            'total'        => wc_format_decimal($shipping_rate->get_cost()),
                            'taxes'        => $shipping_rate->get_taxes(),
                        ));
                        
                        foreach ($shipping_rate->get_meta_data() as $key => $value) {
                            $item->add_meta_data($key, $value, true);
                        }
                        
                        $order->add_item($item);
                    }
                }
            }
        }
        
        // Add cart items
        if (WC()->cart->is_empty()) {
            wp_send_json_error(array(
                'message' => __('Your cart is empty. Please add some items to your cart before checking out.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            
            // Add line item
            $item_id = $order->add_product(
                $product,
                $quantity,
                array(
                    'total'    => wc_format_decimal($cart_item['line_total'], wc_get_price_decimals()),
                    'subtotal' => wc_format_decimal($cart_item['line_subtotal'], wc_get_price_decimals()),
                    'taxes'    => $cart_item['line_tax_data']
                )
            );
            
            // Add line item meta data
            if (!empty($cart_item['variation_id'])) {
                wc_add_order_item_meta($item_id, '_variation_id', $cart_item['variation_id']);
            }
            
            if (!empty($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $name => $value) {
                    wc_add_order_item_meta($item_id, sanitize_text_field($name), sanitize_text_field($value));
                }
            }
        }
        
        // Add fees
        foreach (WC()->cart->get_fees() as $fee_key => $fee) {
            $item = new WC_Order_Item_Fee();
            $item->set_props(array(
                'name'   => $fee->name,
                'tax_class' => $fee->tax_class,
                'total'  => $fee->amount,
                'total_tax' => $fee->tax,
                'taxes'  => array(
                    'total' => $fee->tax_data,
                ),
            ));
            $order->add_item($item);
        }
        
        // Add coupons
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $item = new WC_Order_Item_Coupon();
            $item->set_props(array(
                'code'         => $code,
                'discount'     => WC()->cart->get_coupon_discount_amount($code),
                'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
            ));
            $item->add_meta_data('coupon_data', $coupon->get_data());
            $order->add_item($item);
        }
        
        // Set payment method
        $order->set_payment_method('paypal_direct');
        
        // Calculate totals
        $order->calculate_totals();
        
        // Update order status
        $order->update_status('pending', __('Order created, awaiting PayPal payment', 'woo-paypal-proxy-server'));
        
        // Store order ID in user meta
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, '_last_paypal_direct_order', $order->get_id());
        }
        
        // Log success
        if ($debug_mode) {
            error_log('PayPal Direct - Order created successfully: #' . $order->get_id());
        }
        
        // Return order information
        wp_send_json_success(array(
            'order_id'   => $order->get_id(),
            'order_key'  => $order->get_order_key(),
        ));
    } catch (Exception $e) {
        // Log error
        error_log('PayPal Direct - Error creating order: ' . $e->getMessage());
        
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
add_action('wp_ajax_wppps_create_order', 'wppps_create_order_handler');
add_action('wp_ajax_nopriv_wppps_create_order', 'wppps_create_order_handler');

/**
 * AJAX handler for creating a PayPal order
 */
function wppps_create_paypal_order_handler() {
    check_ajax_referer('wppps-paypal-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (!$order_id) {
        wp_send_json_error(array(
            'message' => __('Invalid order ID', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array(
            'message' => __('Order not found', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    try {
        // Initialize PayPal API
        $paypal_api = new WPPPS_PayPal_API();
        
        // Prepare order data for PayPal
        $amount = $order->get_total();
        $currency = $order->get_currency();
        $reference_id = $order->get_id();
        
        // Prepare line items
        $line_items = array();
        $subtotal = 0;
        
        // Add line items
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $item_name = $item->get_name();
            $quantity = $item->get_quantity();
            $unit_price = $order->get_item_subtotal($item, false, false);
            $tax_amount = $item->get_total_tax();
            
            $subtotal += $unit_price * $quantity;
            
            $line_items[] = array(
                'name' => $item_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'tax_amount' => $tax_amount,
                'sku' => $product ? $product->get_sku() : '',
                'description' => $product ? substr(wp_strip_all_tags($product->get_short_description()), 0, 127) : ''
            );
        }
        
        // Get shipping details
        $shipping_amount = $order->get_shipping_total();
        $shipping_tax = $order->get_shipping_tax();
        
        // Prepare billing address
        $billing_address = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone()
        );
        
        // Prepare shipping address
        $shipping_address = array(
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'state'      => $order->get_shipping_state(),
            'postcode'   => $order->get_shipping_postcode(),
            'country'    => $order->get_shipping_country()
        );
        
        // Prepare custom data
        $custom_data = array(
            'line_items' => $line_items,
            'shipping_amount' => $shipping_amount,
            'shipping_tax' => $shipping_tax,
            'tax_total' => $order->get_total_tax(),
            'billing_address' => $billing_address,
            'shipping_address' => $shipping_address,
            'description' => 'Order #' . $order->get_id()
        );
        
        // Create PayPal order
        $paypal_order = $paypal_api->create_order(
            $amount,
            $currency,
            $reference_id,
            $order->get_checkout_order_received_url(),
            wc_get_cart_url(),
            $custom_data
        );
        
        if (is_wp_error($paypal_order)) {
            throw new Exception($paypal_order->get_error_message());
        }
        
        // Store PayPal order ID in WooCommerce order meta
        $order->update_meta_data('_paypal_order_id', $paypal_order['id']);
        $order->save();
        
        // Log the PayPal order ID
        error_log('PayPal Direct - PayPal Order ID for WooCommerce Order #' . $order->get_id() . ': ' . $paypal_order['id']);
        
        // Return PayPal order details
        wp_send_json_success(array(
            'id' => $paypal_order['id'],
            'status' => $paypal_order['status']
        ));
    } catch (Exception $e) {
        error_log('PayPal Direct - Error creating PayPal order: ' . $e->getMessage());
        
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
add_action('wp_ajax_wppps_create_paypal_order', 'wppps_create_paypal_order_handler');
add_action('wp_ajax_nopriv_wppps_create_paypal_order', 'wppps_create_paypal_order_handler');

/**
 * AJAX handler for capturing PayPal payment
 */
function wppps_capture_paypal_payment_handler() {
    check_ajax_referer('wppps-paypal-nonce', 'nonce');
    
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    if (empty($paypal_order_id)) {
        wp_send_json_error(array(
            'message' => __('PayPal order ID is required', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    try {
        // Initialize PayPal API
        $paypal_api = new WPPPS_PayPal_API();
        
        // Capture the payment
        $capture = $paypal_api->capture_payment($paypal_order_id);
        
        if (is_wp_error($capture)) {
            throw new Exception($capture->get_error_message());
        }
        
        // Extract transaction ID
        $transaction_id = '';
        if (!empty($capture['purchase_units'][0]['payments']['captures'][0]['id'])) {
            $transaction_id = $capture['purchase_units'][0]['payments']['captures'][0]['id'];
        }
        
        // Log the capture
        error_log('PayPal Direct - Payment captured for PayPal Order ID: ' . $paypal_order_id . ', Transaction ID: ' . $transaction_id);
        
        // Return capture details
        wp_send_json_success(array(
            'status' => $capture['status'],
            'transaction_id' => $transaction_id
        ));
    } catch (Exception $e) {
        error_log('PayPal Direct - Error capturing payment: ' . $e->getMessage());
        
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
add_action('wp_ajax_wppps_capture_paypal_payment', 'wppps_capture_paypal_payment_handler');
add_action('wp_ajax_nopriv_wppps_capture_paypal_payment', 'wppps_capture_paypal_payment_handler');

/**
 * AJAX handler for completing order after payment - FIXED VERSION
 */
function wppps_complete_order_handler() {
    check_ajax_referer('wppps-paypal-nonce', 'nonce');
    
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    $wc_order_id = isset($_POST['wc_order_id']) ? intval($_POST['wc_order_id']) : 0;
    
    // Debug log
    error_log('PayPal Direct - Completing order. PayPal Order ID: ' . $paypal_order_id);
    error_log('PayPal Direct - Transaction ID: ' . $transaction_id);
    error_log('PayPal Direct - WC Order ID from frontend: ' . $wc_order_id);
    
    if (empty($paypal_order_id)) {
        wp_send_json_error(array(
            'message' => __('PayPal order ID is required', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    // First try using the WC order ID from the request if provided
    $order = null;
    if ($wc_order_id) {
        $order = wc_get_order($wc_order_id);
        error_log('PayPal Direct - Using WC order ID from request: ' . $wc_order_id);
    }
    
    // If no order found, try finding by meta
    if (!$order) {
        error_log('PayPal Direct - Searching for order by PayPal Order ID meta');
        
        // Use direct SQL query for more reliable results
        global $wpdb;
        $meta_table = $wpdb->prefix . 'postmeta';
        $posts_table = $wpdb->prefix . 'posts';
        
        $sql = $wpdb->prepare(
            "SELECT post_id FROM $meta_table WHERE meta_key = %s AND meta_value = %s 
             AND post_id IN (SELECT ID FROM $posts_table WHERE post_type = 'shop_order')",
            '_paypal_order_id',
            $paypal_order_id
        );
        
        $order_id = $wpdb->get_var($sql);
        error_log('PayPal Direct - SQL query result for order ID: ' . ($order_id ? $order_id : 'Not found'));
        
        if ($order_id) {
            $order = wc_get_order($order_id);
        }
    }
    
    // Try to find any recent pending order as a fallback
    if (!$order) {
        error_log('PayPal Direct - No order found by meta, trying to find recent pending orders');
        
        // Get recent pending orders
        $orders = wc_get_orders(array(
            'status' => 'pending',
            'limit' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        error_log('PayPal Direct - Found ' . count($orders) . ' recent pending orders');
        
        // Loop through recent orders
        foreach ($orders as $recent_order) {
            $recent_id = $recent_order->get_id();
            error_log('PayPal Direct - Checking order #' . $recent_id);
            
            // If this recent order is associated with our session or user, use it
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $last_order_id = get_user_meta($user_id, '_last_paypal_direct_order', true);
                
                if ($last_order_id == $recent_id) {
                    $order = $recent_order;
                    error_log('PayPal Direct - Found matching order by user meta: ' . $recent_id);
                    break;
                }
            }
        }
    }
    
    if (!$order) {
        error_log('PayPal Direct - Could not find any matching order for PayPal ID: ' . $paypal_order_id);
        wp_send_json_error(array(
            'message' => __('Order not found for this PayPal transaction', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    try {
        $order_id = $order->get_id();
        error_log('PayPal Direct - Using order ID: ' . $order_id);
        
        // Check if payment is already completed to avoid duplicate processing
        if ($order->is_paid()) {
            error_log('PayPal Direct - Order #' . $order_id . ' is already paid, redirecting to thank you page');
            wp_send_json_success(array(
                'redirect' => $order->get_checkout_order_received_url()
            ));
            wp_die();
        }
        
        // Store PayPal order ID in case it wasn't stored earlier
        $order->update_meta_data('_paypal_order_id', $paypal_order_id);
        
        // Update order status to processing/completed
        if ($order->get_status() === 'pending') {
            // Add payment complete
            $order->payment_complete($transaction_id);
            
            // Add order note
            $order->add_order_note(
                sprintf(__('Payment completed via PayPal. Transaction ID: %s', 'woo-paypal-proxy-server'), $transaction_id)
            );
            
            // Store transaction ID
            if (!empty($transaction_id)) {
                $order->update_meta_data('_paypal_transaction_id', $transaction_id);
            }
            
            // Save the order
            $order->save();
            
            // Log the completion
            error_log('PayPal Direct - Order #' . $order_id . ' completed with transaction ID: ' . $transaction_id);
            
            // Empty cart
            WC()->cart->empty_cart();
        }
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
    } catch (Exception $e) {
        error_log('PayPal Direct - Error completing order: ' . $e->getMessage());
        
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
add_action('wp_ajax_wppps_complete_order', 'wppps_complete_order_handler');
add_action('wp_ajax_nopriv_wppps_complete_order', 'wppps_complete_order_handler');


function wppps_find_order_handler() {
    check_ajax_referer('wppps-paypal-nonce', 'nonce');
    
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    if (empty($paypal_order_id)) {
        wp_send_json_error(array(
            'message' => __('PayPal order ID is required', 'woo-paypal-proxy-server')
        ));
        wp_die();
    }
    
    error_log('PayPal Direct - Looking up order by PayPal ID: ' . $paypal_order_id);
    
    // First, try to find by meta directly
    global $wpdb;
    $meta_table = $wpdb->prefix . 'postmeta';
    $posts_table = $wpdb->prefix . 'posts';
    
    $sql = $wpdb->prepare(
        "SELECT post_id FROM $meta_table WHERE meta_key = %s AND meta_value = %s 
         AND post_id IN (SELECT ID FROM $posts_table WHERE post_type = 'shop_order')",
        '_paypal_order_id',
        $paypal_order_id
    );
    
    $order_id = $wpdb->get_var($sql);
    
    if ($order_id) {
        error_log('PayPal Direct - Found order #' . $order_id . ' by meta query');
        $order = wc_get_order($order_id);
        
        wp_send_json_success(array(
            'order_id' => $order_id,
            'order_status' => $order ? $order->get_status() : 'unknown'
        ));
        wp_die();
    }
    
    // Try to get the most recent pending orders
    $args = array(
        'status' => 'pending',
        'limit' => 5,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    
    $orders = wc_get_orders($args);
    error_log('PayPal Direct - Found ' . count($orders) . ' recent pending orders to check');
    
    $order_ids = array();
    foreach ($orders as $order) {
        $order_ids[] = $order->get_id();
    }
    
    if (!empty($order_ids)) {
        wp_send_json_success(array(
            'order_id' => null,
            'possible_order_ids' => $order_ids,
            'message' => 'No direct match found, but found ' . count($order_ids) . ' recent pending orders'
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'No matching orders found'
        ));
    }
    
    wp_die();
}

// Register the AJAX handler
add_action('wp_ajax_wppps_find_order', 'wppps_find_order_handler');
add_action('wp_ajax_nopriv_wppps_find_order', 'wppps_find_order_handler');

// Helper function to get order ID by meta key and value
if (!function_exists('wc_get_order_id_by_meta_key_and_value')) {
    function wc_get_order_id_by_meta_key_and_value($meta_key, $meta_value) {
        global $wpdb;
        
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $meta_value
        ));
        
        return $order_id;
    }
}



/**
 * Plugin deactivation hook
 */
function wppps_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wppps_deactivate');