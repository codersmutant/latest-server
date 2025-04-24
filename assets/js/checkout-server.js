/**
 * WooCommerce PayPal Direct Checkout JS - Complete Fix
 */
(function($) {
    'use strict';
    
    // Variables to track order status
    var paypalOrderId = null;
    var orderCreating = false;
    var orderCompleting = false;
    var wooOrderId = null; // Store WooCommerce order ID globally
    
    /**
     * Initialize the PayPal buttons
     */
    function initPayPalButtons() {
        // Check if PayPal SDK is loaded
        if (typeof paypal === 'undefined') {
            console.error('PayPal SDK not loaded');
            showError('PayPal SDK could not be loaded. Please try again or choose another payment method.');
            return;
        }
        
        // Check if container exists
        var container = document.getElementById('wppps-paypal-button-container');
        if (!container) {
            console.error('PayPal button container not found');
            return;
        }
        
        // Clear the container (in case we're re-initializing)
        container.innerHTML = '';
        
        try {
            // Render PayPal buttons
            paypal.Buttons({
                // Style the buttons
                style: {
                    layout: 'vertical',
                    color: 'gold',
                    shape: 'rect',
                    label: 'paypal'
                },
                
                // Create order
                createOrder: function(data, actions) {
                    // Reset stored order ID to prevent using old values
                    wooOrderId = null;
                    
                    // Call our server to create the order
                    return createWooCommerceOrder()
                        .then(function(orderData) {
                            debug('WooCommerce order created:', orderData);
                            
                            // Store the WooCommerce order ID globally
                            if (orderData && orderData.order_id) {
                                wooOrderId = orderData.order_id;
                                debug('Stored WooCommerce order ID:', wooOrderId);
                            } else {
                                debug('WARNING: No order_id in WooCommerce response', orderData);
                            }
                            
                            // Now create PayPal order with our API
                            return createPayPalOrder(orderData.order_id)
                                .then(function(paypalOrder) {
                                    debug('PayPal order created:', paypalOrder);
                                    paypalOrderId = paypalOrder.id;
                                    return paypalOrderId;
                                });
                        })
                        .catch(function(error) {
                            console.error('Order creation failed:', error);
                            showError('Failed to create order: ' + (error.message || 'Unknown error'));
                            throw error;
                        });
                },
                
                // On approval
                onApprove: function(data, actions) {
                    debug('Payment approved:', data);
                    showLoading('Processing your payment...');
                    
                    // Verify we have the WooCommerce order ID
                    if (!wooOrderId) {
                        debug('WARNING: No WooCommerce order ID stored when payment approved');
                    } else {
                        debug('Using stored WooCommerce order ID for completion:', wooOrderId);
                    }
                    
                    // Capture the payment
                    return capturePayPalPayment(data.orderID)
                        .then(function(captureData) {
                            debug('Payment captured:', captureData);
                            
                            // Complete the WooCommerce order
                            return completeWooCommerceOrder(data.orderID, captureData, wooOrderId)
                                .then(function(completeData) {
                                    debug('Order completed:', completeData);
                                    
                                    // Redirect to thank you page
                                    if (completeData.redirect) {
                                        window.location.href = completeData.redirect;
                                    } else {
                                        hideLoading();
                                        showSuccess('Payment successful! Your order has been processed.');
                                    }
                                });
                        })
                        .catch(function(error) {
                            console.error('Payment capture failed:', error);
                            hideLoading();
                            showError('Payment processing failed: ' + (error.message || 'Unknown error'));
                            throw error;
                        });
                },
                
                // On cancel
                onCancel: function(data) {
                    debug('Payment cancelled:', data);
                    showMessage('Payment cancelled. You can try again when you\'re ready.');
                },
                
                // On error
                onError: function(err) {
                    console.error('PayPal error:', err);
                    showError('PayPal error: ' + (err.message || 'An error occurred'));
                }
            }).render('#wppps-paypal-button-container');
        } catch (error) {
            console.error('Error rendering PayPal buttons:', error);
            showError('Failed to initialize PayPal. Please refresh and try again.');
        }
    }
    
    /**
     * Create WooCommerce order
     */
    function createWooCommerceOrder() {
        if (orderCreating) {
            debug('Order already being created, waiting...');
            return new Promise(function(resolve, reject) {
                var checkInterval = setInterval(function() {
                    if (!orderCreating) {
                        clearInterval(checkInterval);
                        if (wooOrderId) {
                            resolve({ order_id: wooOrderId });
                        } else {
                            reject(new Error('No order data available'));
                        }
                    }
                }, 500);
                
                // Timeout after 10 seconds
                setTimeout(function() {
                    clearInterval(checkInterval);
                    reject(new Error('Order creation timed out'));
                }, 10000);
            });
        }
        
        orderCreating = true;
        showLoading('Creating your order...');
        
        return new Promise(function(resolve, reject) {
            // Get form data
            var form = $('form.checkout');
            var formData = new FormData();
            
            // Add form data
            var formInputs = form.serializeArray();
            $.each(formInputs, function(i, field) {
                formData.append(field.name, field.value);
            });
            
            // Add action and nonce
            formData.append('action', 'wppps_create_order');
            formData.append('nonce', wppps_paypal_params.nonce);
            formData.append('payment_method', 'paypal_direct');
            
            debug('Creating WooCommerce order with form data');
            
            // Send request
            $.ajax({
                url: wppps_paypal_params.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    orderCreating = false;
                    hideLoading();
                    
                    if (response.success) {
                        debug('WooCommerce order created successfully:', response.data);
                        
                        // Store order ID globally
                        if (response.data && response.data.order_id) {
                            wooOrderId = response.data.order_id;
                            debug('Set global wooOrderId:', wooOrderId);
                        } else {
                            debug('WARNING: No order_id in successful response', response.data);
                        }
                        
                        resolve(response.data);
                    } else {
                        debug('WooCommerce order creation failed:', response.data);
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to create order';
                        
                        // Handle validation errors
                        if (response.data && response.data.errors) {
                            handleValidationErrors(response.data.errors);
                        }
                        
                        reject(new Error(errorMsg));
                    }
                },
                error: function(xhr, status, error) {
                    orderCreating = false;
                    hideLoading();
                    console.error('AJAX error during order creation:', xhr.responseText);
                    reject(new Error('Server error: ' + error));
                }
            });
        });
    }
    
    /**
     * Create PayPal order via AJAX
     */
    function createPayPalOrder(orderId) {
        debug('Creating PayPal order for WooCommerce order:', orderId);
        
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: wppps_paypal_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wppps_create_paypal_order',
                    nonce: wppps_paypal_params.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to create PayPal order';
                        reject(new Error(errorMsg));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error creating PayPal order:', xhr.responseText);
                    reject(new Error('Server error: ' + error));
                }
            });
        });
    }
    
    /**
     * Capture PayPal payment
     */
    function capturePayPalPayment(paypalOrderId) {
        debug('Capturing PayPal payment for order:', paypalOrderId);
        
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: wppps_paypal_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wppps_capture_paypal_payment',
                    nonce: wppps_paypal_params.nonce,
                    paypal_order_id: paypalOrderId
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to capture payment';
                        reject(new Error(errorMsg));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error capturing payment:', xhr.responseText);
                    reject(new Error('Server error: ' + error));
                }
            });
        });
    }
    
    /**
     * Complete WooCommerce order
     */
    function completeWooCommerceOrder(paypalOrderId, captureData, wcOrderId) {
        if (orderCompleting) {
            return Promise.reject(new Error('Order already being completed'));
        }
        
        orderCompleting = true;
        debug('Completing WooCommerce order with PayPal data:', { 
            paypalOrderId: paypalOrderId, 
            captureData: captureData,
            wcOrderId: wcOrderId || '(not set)'
        });
        
        return new Promise(function(resolve, reject) {
            // Prepare data for AJAX request
            var requestData = {
                action: 'wppps_complete_order',
                nonce: wppps_paypal_params.nonce,
                paypal_order_id: paypalOrderId,
                transaction_id: captureData.transaction_id || ''
            };
            
            // Add WooCommerce order ID if we have it
            if (wcOrderId) {
                requestData.wc_order_id = wcOrderId;
            }
            
            $.ajax({
                url: wppps_paypal_params.ajax_url,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    orderCompleting = false;
                    
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to complete order';
                        reject(new Error(errorMsg));
                    }
                },
                error: function(xhr, status, error) {
                    orderCompleting = false;
                    console.error('AJAX error completing order:', xhr.responseText);
                    reject(new Error('Server error: ' + error));
                }
            });
        });
    }
    
    /**
     * Handle validation errors
     */
    function handleValidationErrors(errors) {
        // Reset previous errors
        $('.woocommerce-error, .woocommerce-message').remove();
        $('.woocommerce-invalid').removeClass('woocommerce-invalid');
        
        // Add new errors
        var $form = $('form.checkout');
        var $errorsContainer = $('.woocommerce-notices-wrapper:first');
        
        if ($errorsContainer.length === 0) {
            $form.before('<div class="woocommerce-notices-wrapper"></div>');
            $errorsContainer = $('.woocommerce-notices-wrapper:first');
        }
        
        var errorList = '<ul class="woocommerce-error" role="alert">';
        $.each(errors, function(key, message) {
            errorList += '<li>' + message + '</li>';
            
            // Mark field as invalid
            var $field = $('#' + key);
            if ($field.length) {
                $field.closest('.form-row').addClass('woocommerce-invalid');
            }
        });
        errorList += '</ul>';
        
        $errorsContainer.append(errorList);
        
        // Scroll to errors
        $('html, body').animate({
            scrollTop: $errorsContainer.offset().top - 100
        }, 500);
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        var $error = $('#wppps-paypal-error');
        $error.html(message).show();
        
        // Hide other messages
        $('#wppps-paypal-message').hide();
    }
    
    /**
     * Show success message
     */
    function showSuccess(message) {
        var $message = $('#wppps-paypal-message').html(message);
        $message.removeClass('wppps-error').addClass('wppps-success').show();
        
        // Hide error message
        $('#wppps-paypal-error').hide();
    }
    
    /**
     * Show regular message
     */
    function showMessage(message) {
        var $message = $('#wppps-paypal-message').html(message);
        $message.removeClass('wppps-error wppps-success').show();
        
        // Hide error message
        $('#wppps-paypal-error').hide();
    }
    
    /**
     * Show loading indicator
     */
    function showLoading(message) {
        var $loading = $('#wppps-paypal-loading');
        if (message) {
            $loading.find('.wppps-loading-text').text(message);
        }
        $loading.show();
    }
    
    /**
     * Hide loading indicator
     */
    function hideLoading() {
        $('#wppps-paypal-loading').hide();
    }
    
    /**
     * Debug logging
     */
    function debug() {
        if (wppps_paypal_params.debug_mode) {
            console.log.apply(console, ['[PayPal Direct]'].concat(Array.prototype.slice.call(arguments)));
        }
    }
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize PayPal buttons when payment method is selected
        $('body').on('payment_method_selected', function() {
            if ($('input[name="payment_method"]:checked').val() === 'paypal_direct') {
                initPayPalButtons();
            }
        });
        
        // Check on page load
        if ($('input[name="payment_method"]:checked').val() === 'paypal_direct') {
            initPayPalButtons();
        }
        
        // Listen for payment method changes
        $('body').on('change', 'input[name="payment_method"]', function() {
            if ($(this).val() === 'paypal_direct') {
                initPayPalButtons();
            }
        });
        
        // Also initialize on updated_checkout event
        $('body').on('updated_checkout', function() {
            if ($('input[name="payment_method"]:checked').val() === 'paypal_direct') {
                initPayPalButtons();
            }
        });
    });
    
})(jQuery);