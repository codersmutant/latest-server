<?php
/**
 * PayPal API Integration for WooCommerce PayPal Proxy Server
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle PayPal API integration
 */
class WPPPS_PayPal_API {
    
    /**
     * PayPal API Base URL
     */
    private $api_url;
    
    /**
     * PayPal Client ID
     */
    private $client_id;
    
    /**
     * PayPal Client Secret
     */
    private $client_secret;
    
    /**
     * PayPal Environment (sandbox or live)
     */
    private $environment;
    
    /**
     * Access Token
     */
    private $access_token;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->environment = get_option('wppps_paypal_environment', 'sandbox');
        $this->client_id = get_option('wppps_paypal_client_id', '');
        $this->client_secret = get_option('wppps_paypal_client_secret', '');
        
        // Set API URL based on environment
        $this->api_url = ($this->environment === 'sandbox') 
            ? 'https://api-m.sandbox.paypal.com' 
            : 'https://api-m.paypal.com';
    }
    
    /**
     * Get PayPal environment
     */
    public function get_environment() {
        return $this->environment;
    }
    
    /**
     * Get PayPal client ID
     */
    public function get_client_id() {
        return $this->client_id;
    }
    
    /**
     * Get PayPal SDK URL
     */
    public function get_sdk_url($currency = 'USD', $intent = 'capture') {
        $params = array(
            'client-id' => $this->client_id,
            'currency' => $currency,
            'intent' => $intent
        );
        
        return 'https://www.paypal.com/sdk/js?' . http_build_query($params);
    }
    
    /**
     * Get access token for API requests
     */
    private function get_access_token() {
        // Return existing token if we have one
        if (!empty($this->access_token)) {
            return $this->access_token;
        }
        
        // Set API endpoint
        $endpoint = $this->api_url . '/v1/oauth2/token';
        
        // Set up basic authentication
        $auth = base64_encode($this->client_id . ':' . $this->client_secret);
        
        // Set up request arguments
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => 'grant_type=client_credentials',
            'timeout' => 30,
        );
        
        // Make the request
        $response = wp_remote_post($endpoint, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_error('Failed to get access token: ' . $response->get_error_message());
            return false;
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['access_token'])) {
            $this->log_error('Invalid access token response: ' . print_r($body, true));
            return false;
        }
        
        // Store the token
        $this->access_token = $body['access_token'];
        
        return $this->access_token;
    }
    
    /**
 * Create PayPal order
 */
public function create_order($amount, $currency = 'USD', $reference_id = '', $return_url = '', $cancel_url = '',  $custom_data = array()) {
    // Get access token
    $access_token = $this->get_access_token();
    
    if (!$access_token) {
        return new WP_Error('paypal_auth_error', __('Failed to authenticate with PayPal API', 'woo-paypal-proxy-server'));
    }
    
    // Set API endpoint
    $endpoint = $this->api_url . '/v2/checkout/orders';
    
    // Build request body
    $payload = array(
        'intent' => 'CAPTURE',
        'purchase_units' => array(
            array(
                'amount' => array(
                    'currency_code' => $currency,
                    'value' => number_format($amount, 2, '.', ''),
                ),
            ),
        ),
        // Start with a basic application context
        'application_context' => array(
            'shipping_preference' => !empty($custom_data['shipping_address']) ? 'SET_PROVIDED_ADDRESS' : 'GET_FROM_FILE',
            'billing_preference' => 'NO_BILLING',  // This is the key setting
            'user_action' => 'PAY_NOW',
            'brand_name' => get_bloginfo('name'),
            'landing_page' => 'BILLING',
        )
    );
    
    // Add reference ID if provided
    if (!empty($reference_id)) {
        $payload['purchase_units'][0]['reference_id'] = $reference_id;
    }
    
    // Add description if provided in custom data
    if (!empty($custom_data['description'])) {
        $payload['purchase_units'][0]['description'] = $custom_data['description'];
        $this->log_info('Adding description to PayPal API request: ' . $custom_data['description']);
    }
    
    // Add return and cancel URLs if provided
    if (!empty($return_url) && !empty($cancel_url)) {
        $payload['application_context']['return_url'] = $return_url;
        $payload['application_context']['cancel_url'] = $cancel_url;
    }
    
    // Add billing address if provided - THIS IS CRITICAL
    if (!empty($custom_data['billing_address'])) {
        $this->log_info('Adding billing address to PayPal request');
        
        $billing = $custom_data['billing_address'];
        
        // Add payer information with billing address
        $payload['payer'] = array(
            'name' => array(
                'given_name' => $billing['first_name'],
                'surname' => $billing['last_name']
            ),
            'email_address' => !empty($billing['email']) ? $billing['email'] : '',
            'phone' => !empty($billing['phone']) ? array(
                'phone_number' => array(
                    'national_number' => preg_replace('/[^0-9]/', '', $billing['phone'])
                )
            ) : null,
            'address' => array(
                'address_line_1' => $billing['address_1'],
                'address_line_2' => $billing['address_2'] ?: '',
                'admin_area_2' => $billing['city'],           // City
                'admin_area_1' => $billing['state'],          // State
                'postal_code' => $billing['postcode'],
                'country_code' => $billing['country']         // Country code
            )
        );
    }
    
    // Add shipping address if provided
    if (!empty($custom_data['shipping_address'])) {
        $this->log_info('Adding shipping address to PayPal request');
        
        // Format shipping address according to PayPal API specifications
        $shipping = $custom_data['shipping_address'];
        
        // Create properly formatted shipping data for PayPal
        $formatted_shipping = array(
            'name' => array(
                'full_name' => $shipping['first_name'] . ' ' . $shipping['last_name']
            ),
            'address' => array(
                'address_line_1' => $shipping['address_1'],
                'address_line_2' => $shipping['address_2'] ?: '',
                'admin_area_2' => $shipping['city'],           // City
                'admin_area_1' => $shipping['state'],          // State
                'postal_code' => $shipping['postcode'],
                'country_code' => $shipping['country']         // Country code
            )
        );
        
        // Log the formatted shipping data
        $this->log_info('Formatted shipping data: ' . json_encode($formatted_shipping));
        
        // Add shipping to payload
        $payload['purchase_units'][0]['shipping'] = $formatted_shipping;
    }
    
    // Set up request arguments
    $args = array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($payload),
        'timeout' => 30,
    );
    
    $this->log_info('PayPal order creation payload: ' . json_encode($payload));
    
    // Make the request
    $response = wp_remote_post($endpoint, $args);
    
    // Check for errors
    if (is_wp_error($response)) {
        $this->log_error('Failed to create PayPal order: ' . $response->get_error_message());
        return $response;
    }
    
    // Get response code
    $response_code = wp_remote_retrieve_response_code($response);
    
    if ($response_code !== 201) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $error_message = $this->get_error_message($body);
        $this->log_error('PayPal API error (' . $response_code . '): ' . $error_message);
        return new WP_Error('paypal_api_error', $error_message);
    }
    
    // Parse response
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (empty($body['id'])) {
        $this->log_error('Invalid order creation response: ' . print_r($body, true));
        return new WP_Error('paypal_response_error', __('Invalid response from PayPal API', 'woo-paypal-proxy-server'));
    }
    
    return $body;
}
    
    /**
     * Capture payment for a PayPal order
     */
    public function capture_payment($order_id) {
        // Get access token
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return new WP_Error('paypal_auth_error', __('Failed to authenticate with PayPal API', 'woo-paypal-proxy-server'));
        }
        
        // Set API endpoint
        $endpoint = $this->api_url . '/v2/checkout/orders/' . $order_id . '/capture';
        
        // Set up request arguments
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ),
            'body' => '{}',
            'timeout' => 30,
        );
        
        // Make the request
        $response = wp_remote_post($endpoint, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_error('Failed to capture PayPal payment: ' . $response->get_error_message());
            return $response;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 201) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $this->get_error_message($body);
            $this->log_error('PayPal API error (' . $response_code . '): ' . $error_message);
            return new WP_Error('paypal_api_error', $error_message);
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body;
    }
    
    /**
     * Get PayPal order details
     */
    public function get_order_details($order_id) {
        // Get access token
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return new WP_Error('paypal_auth_error', __('Failed to authenticate with PayPal API', 'woo-paypal-proxy-server'));
        }
        
        // Set API endpoint
        $endpoint = $this->api_url . '/v2/checkout/orders/' . $order_id;
        
        // Set up request arguments
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );
        
        // Make the request
        $response = wp_remote_get($endpoint, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_error('Failed to get PayPal order details: ' . $response->get_error_message());
            return $response;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $this->get_error_message($body);
            $this->log_error('PayPal API error (' . $response_code . '): ' . $error_message);
            return new WP_Error('paypal_api_error', $error_message);
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body;
    }
    
    /**
     * Process PayPal webhook event
     */
    public function process_webhook_event($event_data) {
        if (empty($event_data) || empty($event_data['event_type'])) {
            return new WP_Error('invalid_webhook', __('Invalid webhook data', 'woo-paypal-proxy-server'));
        }
        
        // Log webhook event
        $this->log_info('Received PayPal webhook: ' . $event_data['event_type']);
        
        // Process different event types
        switch ($event_data['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                return $this->process_payment_capture_completed($event_data);
                
            case 'PAYMENT.CAPTURE.DENIED':
                return $this->process_payment_capture_denied($event_data);
                
            default:
                // Just log the event for now
                $this->log_info('Unhandled webhook event: ' . $event_data['event_type']);
                return true;
        }
    }
    
    /**
     * Process PAYMENT.CAPTURE.COMPLETED webhook event
     */
    private function process_payment_capture_completed($event_data) {
        global $wpdb;
        
        // Extract the resource data
        $resource = isset($event_data['resource']) ? $event_data['resource'] : array();
        
        if (empty($resource['id']) || empty($resource['supplementary_data']['related_ids']['order_id'])) {
            return new WP_Error('invalid_resource', __('Invalid resource data in webhook', 'woo-paypal-proxy-server'));
        }
        
        $transaction_id = $resource['id'];
        $paypal_order_id = $resource['supplementary_data']['related_ids']['order_id'];
        
        // Find the transaction in our log
        $log_table = $wpdb->prefix . 'wppps_transaction_log';
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $log_table WHERE paypal_order_id = %s AND status = 'pending'",
            $paypal_order_id
        ));
        
        if (!$transaction) {
            $this->log_warning('Transaction not found for PayPal order ID: ' . $paypal_order_id);
            return new WP_Error('transaction_not_found', __('Transaction not found', 'woo-paypal-proxy-server'));
        }
        
        // Update the transaction status
        $wpdb->update(
            $log_table,
            array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'transaction_data' => json_encode($event_data),
            ),
            array('id' => $transaction->id)
        );
        
        // Get the site information
        $sites_table = $wpdb->prefix . 'wppps_sites';
        $site = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sites_table WHERE id = %d",
            $transaction->site_id
        ));
        
        if (!$site) {
            $this->log_error('Site not found for transaction: ' . $transaction->id);
            return new WP_Error('site_not_found', __('Site not found', 'woo-paypal-proxy-server'));
        }
        
        // Notify the original website about the completed payment
        $this->notify_site_of_payment_completion($site, $transaction, $paypal_order_id, $transaction_id);
        
        return true;
    }
    
    /**
     * Process PAYMENT.CAPTURE.DENIED webhook event
     */
    private function process_payment_capture_denied($event_data) {
        global $wpdb;
        
        // Extract the resource data
        $resource = isset($event_data['resource']) ? $event_data['resource'] : array();
        
        if (empty($resource['id']) || empty($resource['supplementary_data']['related_ids']['order_id'])) {
            return new WP_Error('invalid_resource', __('Invalid resource data in webhook', 'woo-paypal-proxy-server'));
        }
        
        $transaction_id = $resource['id'];
        $paypal_order_id = $resource['supplementary_data']['related_ids']['order_id'];
        
        // Find the transaction in our log
        $log_table = $wpdb->prefix . 'wppps_transaction_log';
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $log_table WHERE paypal_order_id = %s AND status = 'pending'",
            $paypal_order_id
        ));
        
        if (!$transaction) {
            $this->log_warning('Transaction not found for PayPal order ID: ' . $paypal_order_id);
            return new WP_Error('transaction_not_found', __('Transaction not found', 'woo-paypal-proxy-server'));
        }
        
        // Update the transaction status
        $wpdb->update(
            $log_table,
            array(
                'status' => 'failed',
                'completed_at' => current_time('mysql'),
                'transaction_data' => json_encode($event_data),
            ),
            array('id' => $transaction->id)
        );
        
        // Get the site information
        $sites_table = $wpdb->prefix . 'wppps_sites';
        $site = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sites_table WHERE id = %d",
            $transaction->site_id
        ));
        
        if (!$site) {
            $this->log_error('Site not found for transaction: ' . $transaction->id);
            return new WP_Error('site_not_found', __('Site not found', 'woo-paypal-proxy-server'));
        }
        
        // Notify the original website about the failed payment
        $this->notify_site_of_payment_failure($site, $transaction, $paypal_order_id, $resource['status_details']['reason']);
        
        return true;
    }
    
    /**
     * Notify the original website about a completed payment
     */
    private function notify_site_of_payment_completion($site, $transaction, $paypal_order_id, $transaction_id) {
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $transaction->order_id . 'completed' . $site->api_key;
        $hash = hash_hmac('sha256', $hash_data, $site->api_secret);
        
        // Build the callback URL
        $callback_url = trailingslashit($site->site_url) . 'wc-api/wpppc_callback';
        $params = array(
            'order_id' => $transaction->order_id,
            'status' => 'completed',
            'paypal_order_id' => $paypal_order_id,
            'transaction_id' => $transaction_id,
            'timestamp' => $timestamp,
            'hash' => $hash,
        );
        
        $url = add_query_arg($params, $callback_url);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
        ));
        
        // Log the result
        if (is_wp_error($response)) {
            $this->log_error('Failed to notify site of payment completion: ' . $response->get_error_message());
        } else {
            $this->log_info('Site notified of payment completion. Response code: ' . wp_remote_retrieve_response_code($response));
        }
        
        return $response;
    }
    
    /**
     * Notify the original website about a failed payment
     */
    private function notify_site_of_payment_failure($site, $transaction, $paypal_order_id, $reason) {
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $transaction->order_id . 'failed' . $site->api_key;
        $hash = hash_hmac('sha256', $hash_data, $site->api_secret);
        
        // Build the callback URL
        $callback_url = trailingslashit($site->site_url) . 'wc-api/wpppc_callback';
        $params = array(
            'order_id' => $transaction->order_id,
            'status' => 'failed',
            'paypal_order_id' => $paypal_order_id,
            'reason' => urlencode($reason),
            'timestamp' => $timestamp,
            'hash' => $hash,
        );
        
        $url = add_query_arg($params, $callback_url);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
        ));
        
        // Log the result
        if (is_wp_error($response)) {
            $this->log_error('Failed to notify site of payment failure: ' . $response->get_error_message());
        } else {
            $this->log_info('Site notified of payment failure. Response code: ' . wp_remote_retrieve_response_code($response));
        }
        
        return $response;
    }
    
    /**
     * Extract error message from PayPal API response
     */
    private function get_error_message($response) {
        if (isset($response['message'])) {
            return $response['message'];
        }
        
        if (isset($response['error_description'])) {
            return $response['error_description'];
        }
        
        if (isset($response['details']) && is_array($response['details']) && !empty($response['details'][0]['description'])) {
            return $response['details'][0]['description'];
        }
        
        return __('Unknown PayPal error', 'woo-paypal-proxy-server');
    }
    
    /**
     * Log an error message
     */
    private function log_error($message) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error($message, array('source' => 'woo-paypal-proxy-server'));
        } else {
            error_log('[WooCommerce PayPal Proxy Server] ' . $message);
        }
    }
    
    /**
     * Log a warning message
     */
    private function log_warning($message) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->warning($message, array('source' => 'woo-paypal-proxy-server'));
        } else {
            error_log('[WooCommerce PayPal Proxy Server] Warning: ' . $message);
        }
    }
    
    /**
     * Log an info message
     */
    private function log_info($message) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'woo-paypal-proxy-server'));
        } else {
            error_log('[WooCommerce PayPal Proxy Server] Info: ' . $message);
        }
    }
}