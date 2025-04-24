<?php
/**
 * PayPal Direct Gateway for Website B (Proxy Server)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Direct Gateway Class for Website B
 */
class WPPPS_PayPal_Gateway extends WC_Payment_Gateway {
    
    /**
     * PayPal API instance
     */
    private $paypal_api;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'paypal_direct';
        $this->icon               = apply_filters('woocommerce_paypal_direct_icon', WPPPS_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal Direct', 'woo-paypal-proxy-server');
        $this->method_description = __('Accept PayPal payments directly through PayPal API.', 'woo-paypal-proxy-server');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        
        // Initialize PayPal API
        $this->paypal_api = new WPPPS_PayPal_API();
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_review_order_before_submit', array($this, 'hide_place_order_button'), 10);

        
        // Add scripts for checkout
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }
    
    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woo-paypal-proxy-server'),
                'type'        => 'checkbox',
                'label'       => __('Enable PayPal Direct', 'woo-paypal-proxy-server'),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-paypal-proxy-server'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-paypal-proxy-server'),
                'default'     => __('PayPal', 'woo-paypal-proxy-server'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-paypal-proxy-server'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-paypal-proxy-server'),
                'default'     => __('Pay securely via PayPal. You can pay with your credit card if you don\'t have a PayPal account.', 'woo-paypal-proxy-server'),
                'desc_tip'    => true,
            ),
            'debug_mode' => array(
                'title'       => __('Debug Mode', 'woo-paypal-proxy-server'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging for debugging', 'woo-paypal-proxy-server'),
                'default'     => 'yes'
            ),
        );
    }
    
    /**
     * Enqueue scripts for checkout
     */
    public function payment_scripts() {
        if (!is_checkout()) {
            return;
        }

        // Only load if gateway is enabled
        if ($this->enabled !== 'yes') {
            return;
        }

        // Check if we have credentials
        $client_id = $this->paypal_api->get_client_id();
        if (empty($client_id)) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style('wppps-paypal-checkout', WPPPS_PLUGIN_URL . 'assets/css/checkout-server.css', array(), WPPPS_VERSION);

        // Enqueue PayPal SDK with intent=capture
        $currency = get_woocommerce_currency();
        $sdk_url = "https://www.paypal.com/sdk/js?client-id={$client_id}&currency={$currency}&intent=capture&components=buttons";
        wp_enqueue_script('paypal-sdk', $sdk_url, array(), null, true);

        // Enqueue custom script
        wp_enqueue_script('wppps-paypal-checkout', WPPPS_PLUGIN_URL . 'assets/js/checkout-server.js', array('jquery', 'paypal-sdk'), WPPPS_VERSION, true);

        // Add the localized data
        wp_localize_script('wppps-paypal-checkout', 'wppps_paypal_params', array(
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('wppps-paypal-nonce'),
            'currency'       => $currency,
            'total'          => WC()->cart->get_total(''),
            'setup_error'    => __('PayPal encountered an error. Please try again or choose a different payment method.', 'woo-paypal-proxy-server'),
            'debug_mode'     => $this->get_option('debug_mode') === 'yes',
        ));
    }
    
    /**
     * Payment fields displayed on checkout
     */
    public function payment_fields() {
        // Display description if set
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Check if we have credentials
        $client_id = $this->paypal_api->get_client_id();
        if (empty($client_id)) {
            echo '<div class="wppps-error">' . __('PayPal is not properly configured. Please contact site administrator.', 'woo-paypal-proxy-server') . '</div>';
            return;
        }
        
        // Display PayPal buttons container
        echo '<div id="wppps-paypal-button-container" class="wppps-paypal-button-container"></div>';
        echo '<div id="wppps-paypal-message" class="wppps-message" style="display: none;"></div>';
        echo '<div id="wppps-paypal-error" class="wppps-error" style="display: none;"></div>';
        echo '<div id="wppps-paypal-loading" class="wppps-loading" style="display: none;">';
        echo '<div class="wppps-spinner"></div>';
        echo '<div class="wppps-loading-text">' . __('Processing payment...', 'woo-paypal-proxy-server') . '</div>';
        echo '</div>';
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        // This method is called when form is submitted
        // The actual payment is handled by AJAX
        $order = wc_get_order($order_id);
        
        // Add order note
        $order->add_order_note(__('Customer initiated PayPal payment.', 'woo-paypal-proxy-server'));
        
        // Return success as AJAX will handle the redirect
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }
    
    /**
     * Log debug info if enabled
     */
    public function log_debug($message) {
        if ($this->get_option('debug_mode') === 'yes') {
            if (is_array($message) || is_object($message)) {
                $message = print_r($message, true);
            }
            
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->debug($message, array('source' => 'paypal-direct'));
            } else {
                error_log('[PayPal Direct] ' . $message);
            }
        }
    }
    
    
    /**
 * Hide the standard Place Order button when PayPal is selected
 */
public function hide_place_order_button() {
    // Add script to hide the button when this gateway is selected
    ?>
    <script type="text/javascript">
        jQuery(function($) {
            // Function to hide/show place order button
            function checkPaymentMethod() {
                if ($('input[name="payment_method"]:checked').val() === 'paypal_direct') {
                    $('#place_order').hide();
                } else {
                    $('#place_order').show();
                }
            }
            
            // Run on page load
            checkPaymentMethod();
            
            // Run when payment method changes
            $('body').on('change', 'input[name="payment_method"]', function() {
                checkPaymentMethod();
            });
            
            // Run after checkout updates
            $('body').on('updated_checkout', function() {
                setTimeout(checkPaymentMethod, 100);
            });
            
            // Double check after a delay to catch any edge cases
            setTimeout(checkPaymentMethod, 500);
            setTimeout(checkPaymentMethod, 1000);
        });
    </script>
    <style type="text/css">
        /* Ensure the button stays hidden when PayPal is selected */
        .payment_method_paypal_direct ~ .place-order #place_order {
            display: none !important;
        }
    </style>
    <?php
    }
}