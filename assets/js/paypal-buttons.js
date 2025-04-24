/**
 * WooCommerce PayPal Proxy Server - PayPal Buttons JS
 */

(function() {
    'use strict';
    
    // Store parent window origin
    var parentOrigin = '*'; // Will be set from URL parameter
    var urlParams = new URLSearchParams(window.location.search);
    var siteUrl = urlParams.get('site_url');
    if (siteUrl) {
        try {
            // Decode if it's base64 encoded
            if (siteUrl.indexOf('%') === -1 && /^[A-Za-z0-9+/=]+$/.test(siteUrl)) {
                siteUrl = atob(siteUrl);
            } else {
                siteUrl = decodeURIComponent(siteUrl);
            }
            
            var siteUrlObj = new URL(siteUrl);
            parentOrigin = siteUrlObj.origin;
        } catch (e) {
            console.error('Invalid site URL:', e);
        }
    }
    
    // Store order data
    var orderData = {
        orderId: null,
        amount: getQueryParam('amount', '0'),
        currency: getQueryParam('currency', 'USD'),
        apiKey: getQueryParam('api_key', ''),
        callbackUrl: getQueryParam('callback_url', ''),
    };
    
    /**
     * Initialize PayPal buttons
     */
    function initPayPalButtons() {
        // Check if PayPal SDK is available
        if (typeof paypal === 'undefined') {
            console.error('PayPal SDK not loaded');
            showError('PayPal SDK could not be loaded. Please try again later.');
            return;
        }
        
        // Notify parent window that buttons are loaded
        sendMessageToParent({
            action: 'button_loaded',
            status: 'success'
        });
        
        // Render PayPal buttons
        paypal.Buttons({
            // Style the buttons
            style: {
                layout: 'horizontal',  // horizontal | vertical
                color: 'blue',         // gold | blue | silver | black
                shape: 'rect',         // pill | rect
                label: 'paypal',       // pay | checkout | paypal | buynow
                tagline: false
            },
            
            // Create order
            createOrder: function(data, actions) {
                // Notify parent window that button was clicked
                sendMessageToParent({
                    action: 'button_clicked'
                });
                
                // Wait for order data from parent
                return new Promise(function(resolve, reject) {
                    // Create message handler
                    var messageHandler = function(event) {
                        // Validate origin if possible
                        if (parentOrigin !== '*' && event.origin !== parentOrigin) {
                            return;
                        }
                        
                        // Check if message is for us
                        var data = event.data;
                        if (!data || !data.action || data.source !== 'woocommerce-site') {
                            return;
                        }
                        
                        // Handle create_paypal_order action
                        if (data.action === 'create_paypal_order') {
                            // Remove event listener
                            window.removeEventListener('message', messageHandler);
                            
                            // Store order data
                            orderData.orderId = data.order_id;
                            orderData.orderKey = data.order_key;
                            
                            // Create PayPal order
                            createPayPalOrder(orderData)
                                .then(function(paypalOrderId) {
                                    resolve(paypalOrderId);
                                })
                                .catch(function(error) {
                                    console.error('Error creating PayPal order:', error);
                                    showError('Error creating PayPal order: ' + error.message);
                                    reject(error);
                                });
                        } else if (data.action === 'order_creation_failed') {
                            // Remove event listener
                            window.removeEventListener('message', messageHandler);
                            
                            // Show error
                            var error = new Error(data.message || 'Failed to create order');
                            showError('Order creation failed: ' + error.message);
                            reject(error);
                        }
                    };
                    
                    // Add message listener
                    window.addEventListener('message', messageHandler);
                    
                    // Set timeout for order creation (30 seconds)
                    setTimeout(function() {
                        window.removeEventListener('message', messageHandler);
                        var error = new Error('Timeout waiting for order data');
                        showError('Timeout waiting for order data. Please try again.');
                        reject(error);
                    }, 30000);
                });
            },
            
            // On approval
            onApprove: function(data, actions) {
                // Show processing message
                showProcessing();
                
                // Capture the payment
                return capturePayPalPayment(data.orderID)
                    .then(function(captureData) {
                        // Notify parent window
                        sendMessageToParent({
                            action: 'order_approved',
                            payload: {
                                orderID: data.orderID,
                                transactionID: captureData.transaction_id,
                                status: captureData.status
                            }
                        });
                        
                        // Show success message
                        hideProcessing();
                        showSuccess('Payment successful! Finalizing your order...');
                    })
                    .catch(function(error) {
                        // Handle error
                        console.error('Error capturing payment:', error);
                        
                        // Notify parent window of error
                        sendMessageToParent({
                            action: 'payment_error',
                            error: {
                                message: error.message || 'Payment failed'
                            }
                        });
                        
                        // Show error message
                        hideProcessing();
                        showError('Error capturing payment: ' + error.message);
                        
                        throw error;
                    });
            },
            
            // On cancel
            onCancel: function(data) {
                console.log('Payment cancelled:', data);
                
                // Notify parent window
                sendMessageToParent({
                    action: 'payment_cancelled',
                    payload: data
                });
                
                showMessage('Payment cancelled. You can try again when you\'re ready.');
            },
            
            // On error
            onError: function(err) {
                console.error('PayPal error:', err);
                
                // Notify parent window
                sendMessageToParent({
                    action: 'payment_error',
                    error: {
                        message: err.message || 'An error occurred'
                    }
                });
                
                showError('PayPal error: ' + (err.message || 'An error occurred'));
            }
        }).render('#paypal-buttons-container');
        
        // Listen for messages from parent window
        window.addEventListener('message', function(event) {
            // Validate origin if possible
            if (parentOrigin !== '*' && event.origin !== parentOrigin) {
                return;
            }
            
            // Check if message is for us
            var data = event.data;
            if (!data || !data.action || data.source !== 'woocommerce-site') {
                return;
            }
            
            // Handle trigger_paypal_button action
            if (data.action === 'trigger_paypal_button') {
                // Find PayPal button and click it
                document.querySelector('.paypal-button').click();
            }
        });
    }
    
    /**
     * Create PayPal order via REST API
     */
    function createPayPalOrder(orderData) {
        return new Promise(function(resolve, reject) {
            // Calculate timestamp for security
            var timestamp = Math.floor(Date.now() / 1000);
            
            // Create request data
            var data = {
                api_key: orderData.apiKey,
                order_id: orderData.orderId,
                amount: orderData.amount,
                currency: orderData.currency,
                timestamp: timestamp
            };
            
            // Make the request
            fetch(ajaxRestUrl('/wppps/v1/create-paypal-order'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(function(responseData) {
                if (!responseData.success) {
                    throw new Error(responseData.message || 'Failed to create PayPal order');
                }
                
                // Return the PayPal order ID
                resolve(responseData.order_id);
            })
            .catch(function(error) {
                reject(error);
            });
        });
    }
    
    /**
     * Capture PayPal payment via REST API
     */
    /**
 * Capture PayPal payment via REST API
 */
function capturePayPalPayment(paypalOrderId) {
    return new Promise(function(resolve, reject) {
        // Calculate timestamp for security
        var timestamp = Math.floor(Date.now() / 1000);
        
        // Create request data
        var data = {
            api_key: orderData.apiKey,
            paypal_order_id: paypalOrderId,
            order_id: orderData.orderId,
            timestamp: timestamp
        };
        
        // Make the request
        fetch('/wp-json/wppps/v1/capture-payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(responseData) {
            if (!responseData.success) {
                throw new Error(responseData.message || 'Failed to capture payment');
            }
            
            // Return the capture data
            resolve(responseData);
        })
        .catch(function(error) {
            reject(error);
        });
    });
}
    
    /**
     * Send message to parent window
     */
    function sendMessageToParent(message) {
        // Add source identifier
        message.source = 'paypal-proxy';
        
        // Send message
        window.parent.postMessage(message, parentOrigin);
    }
    
    /**
     * Get REST API URL
     */
    function ajaxRestUrl(endpoint) {
        // Use WordPress REST API URL from localized script if available
        if (window.wppps_params && window.wppps_params.rest_url) {
            return window.wppps_params.rest_url + endpoint;
        }
        
        // Fallback to current URL path
        return window.location.pathname + endpoint;
    }
    
    /**
     * Get query parameter value
     */
    function getQueryParam(name, defaultValue) {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(name) || defaultValue;
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        var errorContainer = document.getElementById('paypal-error');
        if (errorContainer) {
            errorContainer.textContent = message;
            errorContainer.style.display = 'block';
        }
    }
    
    /**
     * Show success message
     */
    function showSuccess(message) {
        var successContainer = document.getElementById('paypal-success');
        if (successContainer) {
            successContainer.textContent = message;
            successContainer.style.display = 'block';
        }
    }
    
    /**
     * Show general message
     */
    function showMessage(message) {
        var messageContainer = document.getElementById('paypal-message');
        if (messageContainer) {
            messageContainer.textContent = message;
            messageContainer.style.display = 'block';
        }
    }
    
    /**
     * Show processing indicator
     */
    function showProcessing() {
        var processingContainer = document.getElementById('paypal-processing');
        if (processingContainer) {
            processingContainer.style.display = 'flex';
        }
    }
    
    /**
     * Hide processing indicator
     */
    function hideProcessing() {
        var processingContainer = document.getElementById('paypal-processing');
        if (processingContainer) {
            processingContainer.style.display = 'none';
        }
    }
    
    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPayPalButtons);
    } else {
        initPayPalButtons();
    }
})();