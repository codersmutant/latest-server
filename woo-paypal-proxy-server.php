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
    
    // Initialize classes
    $paypal_api = new WPPPS_PayPal_API();
    $rest_api = new WPPPS_REST_API($paypal_api);
    $admin = new WPPPS_Admin();
    
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
 * Plugin deactivation hook
 */
function wppps_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wppps_deactivate');