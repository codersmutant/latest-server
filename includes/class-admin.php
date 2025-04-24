<?php
/**
 * Admin settings for WooCommerce PayPal Proxy Server
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Server Admin Class
 */
class WPPPS_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add ajax handler for managing sites
        add_action('wp_ajax_wppps_add_site', array($this, 'ajax_add_site'));
        add_action('wp_ajax_wppps_update_site', array($this, 'ajax_update_site'));
        add_action('wp_ajax_wppps_delete_site', array($this, 'ajax_delete_site'));
        add_action('wp_ajax_wppps_test_paypal', array($this, 'ajax_test_paypal'));
        add_action('wp_ajax_wppps_get_site', array($this, 'ajax_get_site'));

    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('PayPal Proxy', 'woo-paypal-proxy-server'),
            __('PayPal Proxy', 'woo-paypal-proxy-server'),
            'manage_options',
            'wppps-settings',
            array($this, 'settings_page'),
            'dashicons-money-alt',
            56
        );
        
        add_submenu_page(
            'wppps-settings',
            __('Settings', 'woo-paypal-proxy-server'),
            __('Settings', 'woo-paypal-proxy-server'),
            'manage_options',
            'wppps-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'wppps-settings',
            __('Registered Sites', 'woo-paypal-proxy-server'),
            __('Registered Sites', 'woo-paypal-proxy-server'),
            'manage_options',
            'wppps-sites',
            array($this, 'sites_page')
        );
        
        add_submenu_page(
            'wppps-settings',
            __('Transaction Log', 'woo-paypal-proxy-server'),
            __('Transaction Log', 'woo-paypal-proxy-server'),
            'manage_options',
            'wppps-transactions',
            array($this, 'transactions_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wppps_settings', 'wppps_paypal_client_id');
        register_setting('wppps_settings', 'wppps_paypal_client_secret');
        register_setting('wppps_settings', 'wppps_paypal_environment');
        
        add_settings_section(
            'wppps_paypal_settings',
            __('PayPal API Settings', 'woo-paypal-proxy-server'),
            array($this, 'paypal_settings_callback'),
            'wppps_settings'
        );
        
        add_settings_field(
            'wppps_paypal_environment',
            __('Environment', 'woo-paypal-proxy-server'),
            array($this, 'environment_callback'),
            'wppps_settings',
            'wppps_paypal_settings'
        );
        
        add_settings_field(
            'wppps_paypal_client_id',
            __('Client ID', 'woo-paypal-proxy-server'),
            array($this, 'client_id_callback'),
            'wppps_settings',
            'wppps_paypal_settings'
        );
        
        add_settings_field(
            'wppps_paypal_client_secret',
            __('Client Secret', 'woo-paypal-proxy-server'),
            array($this, 'client_secret_callback'),
            'wppps_settings',
            'wppps_paypal_settings'
        );
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wppps_settings');
                do_settings_sections('wppps_settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Test PayPal API Connection', 'woo-paypal-proxy-server'); ?></h2>
            <p><?php _e('Test the connection to the PayPal API with your credentials.', 'woo-paypal-proxy-server'); ?></p>
            <button id="wppps-test-paypal" class="button button-secondary">
                <?php _e('Test Connection', 'woo-paypal-proxy-server'); ?>
            </button>
            <div id="wppps-test-result" style="margin-top: 10px;"></div>
            
            <script>
                jQuery(document).ready(function($) {
                    $('#wppps-test-paypal').on('click', function(e) {
                        e.preventDefault();
                        
                        var $button = $(this);
                        var $result = $('#wppps-test-result');
                        
                        $button.prop('disabled', true).text('<?php _e('Testing...', 'woo-paypal-proxy-server'); ?>');
                        $result.html('');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wppps_test_paypal',
                                nonce: '<?php echo wp_create_nonce('wppps-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                                } else {
                                    $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                                }
                            },
                            error: function() {
                                $result.html('<div class="notice notice-error inline"><p><?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-server'); ?></p></div>');
                            },
                            complete: function() {
                                $button.prop('disabled', false).text('<?php _e('Test Connection', 'woo-paypal-proxy-server'); ?>');
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Sites management page
     */
    public function sites_page() {
        // Enqueue scripts and styles for the sites page
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        // Get sites from database
        $sites = $this->get_sites();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Registered Sites', 'woo-paypal-proxy-server'); ?></h1>
            <a href="#" class="page-title-action" id="add-new-site"><?php _e('Add New', 'woo-paypal-proxy-server'); ?></a>
            
            <hr class="wp-header-end">
            
            <div class="notice notice-info">
                <p><?php _e('Register WooCommerce sites that will use this server as a PayPal proxy.', 'woo-paypal-proxy-server'); ?></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('Site URL', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Site Name', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('API Key', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Status', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Created', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Actions', 'woo-paypal-proxy-server'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sites)) : ?>
                        <tr>
                            <td colspan="6"><?php _e('No sites registered yet.', 'woo-paypal-proxy-server'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($sites as $site) : ?>
                            <tr>
                                <td><?php echo esc_html($site->site_url); ?></td>
                                <td><?php echo esc_html($site->site_name); ?></td>
                                <td>
                                    <code><?php echo esc_html($site->api_key); ?></code>
                                    <button class="button button-small copy-api-key" data-api-key="<?php echo esc_attr($site->api_key); ?>">
                                        <?php _e('Copy', 'woo-paypal-proxy-server'); ?>
                                    </button>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($site->status); ?>">
                                        <?php echo esc_html(ucfirst($site->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($site->created_at))); ?></td>
                                <td>
                                    <a href="#" class="edit-site" data-id="<?php echo esc_attr($site->id); ?>"><?php _e('Edit', 'woo-paypal-proxy-server'); ?></a> |
                                    <a href="#" class="view-api-secret" data-id="<?php echo esc_attr($site->id); ?>"><?php _e('View Secret', 'woo-paypal-proxy-server'); ?></a> |
                                    <a href="#" class="delete-site" data-id="<?php echo esc_attr($site->id); ?>"><?php _e('Delete', 'woo-paypal-proxy-server'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Add/Edit Site Dialog -->
            <div id="site-dialog" title="<?php _e('Site Details', 'woo-paypal-proxy-server'); ?>" style="display:none;">
                <form id="site-form">
                    <input type="hidden" id="site-id" name="site_id" value="">
                    <p>
                        <label for="site-url"><?php _e('Site URL', 'woo-paypal-proxy-server'); ?></label><br>
                        <input type="url" id="site-url" name="site_url" class="regular-text" required>
                        <p class="description"><?php _e('Enter the full URL of the WooCommerce site (e.g. https://example.com).', 'woo-paypal-proxy-server'); ?></p>
                    </p>
                    <p>
                        <label for="site-name"><?php _e('Site Name', 'woo-paypal-proxy-server'); ?></label><br>
                        <input type="text" id="site-name" name="site_name" class="regular-text" required>
                        <p class="description"><?php _e('Enter a name to identify this site.', 'woo-paypal-proxy-server'); ?></p>
                    </p>
                    <p>
                        <label for="site-status"><?php _e('Status', 'woo-paypal-proxy-server'); ?></label><br>
                        <select id="site-status" name="site_status">
                            <option value="active"><?php _e('Active', 'woo-paypal-proxy-server'); ?></option>
                            <option value="inactive"><?php _e('Inactive', 'woo-paypal-proxy-server'); ?></option>
                        </select>
                    </p>
                    <p>
                        <label for="api-key"><?php _e('API Key', 'woo-paypal-proxy-server'); ?></label><br>
                        <input type="text" id="api-key" name="api_key" class="regular-text" >
                        <button type="button" id="generate-api-key" class="button button-secondary"><?php _e('Generate', 'woo-paypal-proxy-server'); ?></button>
                        <p class="description"><?php _e('This is the API key the site will use to connect.', 'woo-paypal-proxy-server'); ?></p>
                    </p>
                    <p>
                        <label for="api-secret"><?php _e('API Secret', 'woo-paypal-proxy-server'); ?></label><br>
                        <input type="text" id="api-secret" name="api_secret" class="regular-text" >
                        <button type="button" id="generate-api-secret" class="button button-secondary"><?php _e('Generate', 'woo-paypal-proxy-server'); ?></button>
                        <p class="description"><?php _e('This is the shared secret for secure communication.', 'woo-paypal-proxy-server'); ?></p>
                    </p>
                </form>
            </div>
            
            <!-- View Secret Dialog -->
            <div id="secret-dialog" title="<?php _e('API Secret', 'woo-paypal-proxy-server'); ?>" style="display:none;">
                <p><?php _e('This is the API secret for this site. Copy it and use it in the client configuration.', 'woo-paypal-proxy-server'); ?></p>
                <p><input type="text" id="view-api-secret" class="regular-text" ></p>
                <p><button type="button" id="copy-api-secret" class="button button-primary"><?php _e('Copy to Clipboard', 'woo-paypal-proxy-server'); ?></button></p>
            </div>
            
            <!-- Delete Confirmation Dialog -->
            <div id="delete-dialog" title="<?php _e('Delete Site', 'woo-paypal-proxy-server'); ?>" style="display:none;">
                <p><?php _e('Are you sure you want to delete this site? This action cannot be undone.', 'woo-paypal-proxy-server'); ?></p>
            </div>
            
            <script>
                jQuery(document).ready(function($) {
                    var siteDialog, secretDialog, deleteDialog;
                    
                    // Initialize dialogs
                    siteDialog = $('#site-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 500,
                        buttons: {
                            "<?php _e('Save', 'woo-paypal-proxy-server'); ?>": saveSite,
                            "<?php _e('Cancel', 'woo-paypal-proxy-server'); ?>": function() {
                                siteDialog.dialog('close');
                            }
                        },
                        close: function() {
                            $('#site-form')[0].reset();
                        }
                    });
                    
                    secretDialog = $('#secret-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 500,
                        buttons: {
                            "<?php _e('Close', 'woo-paypal-proxy-server'); ?>": function() {
                                secretDialog.dialog('close');
                            }
                        }
                    });
                    
                    deleteDialog = $('#delete-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 400,
                        buttons: {
                            "<?php _e('Delete', 'woo-paypal-proxy-server'); ?>": function() {
                                var siteId = $(this).data('site-id');
                                deleteSite(siteId);
                                deleteDialog.dialog('close');
                            },
                            "<?php _e('Cancel', 'woo-paypal-proxy-server'); ?>": function() {
                                deleteDialog.dialog('close');
                            }
                        }
                    });
                    
                    // Add new site button
                    $('#add-new-site').on('click', function(e) {
                        e.preventDefault();
                        
                        // Reset form and set defaults
                        $('#site-form')[0].reset();
                        $('#site-id').val('');
                        $('#site-status').val('active');
                        
                        // Generate API key and secret
                        generateApiKey();
                        generateApiSecret();
                        
                        // Set dialog title and open
                        siteDialog.dialog('option', 'title', '<?php _e('Add New Site', 'woo-paypal-proxy-server'); ?>');
                        siteDialog.dialog('open');
                    });
                    
                    // Edit site button
                    $('.edit-site').on('click', function(e) {
                        e.preventDefault();
                        
                        var siteId = $(this).data('id');
                        loadSite(siteId);
                    });
                    
                    // View API secret button
                    $('.view-api-secret').on('click', function(e) {
                        e.preventDefault();
                        
                        var siteId = $(this).data('id');
                        loadApiSecret(siteId);
                    });
                    
                    // Delete site button
                    $('.delete-site').on('click', function(e) {
                        e.preventDefault();
                        
                        var siteId = $(this).data('id');
                        deleteDialog.data('site-id', siteId).dialog('open');
                    });
                    
                    // Copy API key button
                    $('.copy-api-key').on('click', function(e) {
                        e.preventDefault();
                        
                        var apiKey = $(this).data('api-key');
                        copyToClipboard(apiKey);
                        
                        $(this).text('<?php _e('Copied!', 'woo-paypal-proxy-server'); ?>');
                        setTimeout(function() {
                            $('.copy-api-key').text('<?php _e('Copy', 'woo-paypal-proxy-server'); ?>');
                        }, 1000);
                    });
                    
                    // Generate API key button
                    $('#generate-api-key').on('click', function(e) {
                        e.preventDefault();
                        generateApiKey();
                    });
                    
                    // Generate API secret button
                    $('#generate-api-secret').on('click', function(e) {
                        e.preventDefault();
                        generateApiSecret();
                    });
                    
                    // Copy API secret button
                    $('#copy-api-secret').on('click', function(e) {
                        e.preventDefault();
                        
                        var apiSecret = $('#view-api-secret').val();
                        copyToClipboard(apiSecret);
                        
                        $(this).text('<?php _e('Copied!', 'woo-paypal-proxy-server'); ?>');
                        setTimeout(function() {
                            $('#copy-api-secret').text('<?php _e('Copy to Clipboard', 'woo-paypal-proxy-server'); ?>');
                        }, 1000);
                    });
                    
                    // Generate random API key
                    function generateApiKey() {
                        var length = 32;
                        var charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                        var key = '';
                        
                        for (var i = 0; i < length; i++) {
                            key += charset.charAt(Math.floor(Math.random() * charset.length));
                        }
                        
                        $('#api-key').val(key);
                    }
                    
                    // Generate random API secret
                    function generateApiSecret() {
                        var length = 64;
                        var charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                        var secret = '';
                        
                        for (var i = 0; i < length; i++) {
                            secret += charset.charAt(Math.floor(Math.random() * charset.length));
                        }
                        
                        $('#api-secret').val(secret);
                    }
                    
                    // Copy text to clipboard
                    function copyToClipboard(text) {
                        var $temp = $('<input>');
                        $('body').append($temp);
                        $temp.val(text).select();
                        document.execCommand('copy');
                        $temp.remove();
                    }
                    
                    // Load site details
                    function loadSite(siteId) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wppps_get_site',
                                site_id: siteId,
                                nonce: '<?php echo wp_create_nonce('wppps-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    var site = response.data.site;
                                    
                                    $('#site-id').val(site.id);
                                    $('#site-url').val(site.site_url);
                                    $('#site-name').val(site.site_name);
                                    $('#site-status').val(site.status);
                                    $('#api-key').val(site.api_key);
                                    $('#api-secret').val(site.api_secret);
                                    
                                    siteDialog.dialog('option', 'title', '<?php _e('Edit Site', 'woo-paypal-proxy-server'); ?>');
                                    siteDialog.dialog('open');
                                } else {
                                    alert(response.data.message);
                                }
                            },
                            error: function() {
                                alert('<?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-server'); ?>');
                            }
                        });
                    }
                    
                    // Load API secret
                    function loadApiSecret(siteId) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wppps_get_site',
                                site_id: siteId,
                                nonce: '<?php echo wp_create_nonce('wppps-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    var site = response.data.site;
                                    $('#view-api-secret').val(site.api_secret);
                                    secretDialog.dialog('open');
                                } else {
                                    alert(response.data.message);
                                }
                            },
                            error: function() {
                                alert('<?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-server'); ?>');
                            }
                        });
                    }
                    
                    // Save site
                    function saveSite() {
                        var siteId = $('#site-id').val();
                        var action = siteId ? 'wppps_update_site' : 'wppps_add_site';
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: action,
                                site_id: siteId,
                                site_url: $('#site-url').val(),
                                site_name: $('#site-name').val(),
                                site_status: $('#site-status').val(),
                                api_key: $('#api-key').val(),
                                api_secret: $('#api-secret').val(),
                                nonce: '<?php echo wp_create_nonce('wppps-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    location.reload();
                                } else {
                                    alert(response.data.message);
                                }
                            },
                            error: function() {
                                alert('<?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-server'); ?>');
                            }
                        });
                    }
                    
                    // Delete site
                    function deleteSite(siteId) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wppps_delete_site',
                                site_id: siteId,
                                nonce: '<?php echo wp_create_nonce('wppps-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    location.reload();
                                } else {
                                    alert(response.data.message);
                                }
                            },
                            error: function() {
                                alert('<?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-server'); ?>');
                            }
                        });
                    }
                });
            </script>
            
            <style>
                .status-active {
                    color: green;
                    font-weight: bold;
                }
                .status-inactive {
                    color: red;
                }
                .wp-list-table .column-actions {
                    width: 15%;
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * Transactions log page
     */
    public function transactions_page() {
        // Get transactions from database
        $transactions = $this->get_transactions();
        ?>
        <div class="wrap">
            <h1><?php _e('Transaction Log', 'woo-paypal-proxy-server'); ?></h1>
            
            <hr class="wp-header-end">
            
            <div class="notice notice-info">
                <p><?php _e('View all PayPal transactions processed through this proxy.', 'woo-paypal-proxy-server'); ?></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('ID', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Site', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Order ID', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('PayPal Order ID', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Amount', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Status', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Created', 'woo-paypal-proxy-server'); ?></th>
                        <th scope="col"><?php _e('Completed', 'woo-paypal-proxy-server'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)) : ?>
                        <tr>
                            <td colspan="8"><?php _e('No transactions recorded yet.', 'woo-paypal-proxy-server'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($transactions as $transaction) : ?>
                            <tr>
                                <td><?php echo esc_html($transaction->id); ?></td>
                                <td><?php echo esc_html($transaction->site_name); ?></td>
                                <td><?php echo esc_html($transaction->order_id); ?></td>
                                <td><?php echo esc_html($transaction->paypal_order_id); ?></td>
                                <td><?php echo esc_html($transaction->amount . ' ' . $transaction->currency); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($transaction->status); ?>">
                                        <?php echo esc_html(ucfirst($transaction->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?></td>
                                <td>
                                    <?php if ($transaction->completed_at) : ?>
                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->completed_at))); ?>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <style>
                .status-pending {
                    color: orange;
                }
                .status-completed {
                    color: green;
                    font-weight: bold;
                }
                .status-failed {
                    color: red;
                }
                .status-cancelled {
                    color: gray;
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * PayPal settings callback
     */
    public function paypal_settings_callback() {
        echo '<p>' . __('Configure your PayPal API credentials. These will be used to process payments.', 'woo-paypal-proxy-server') . '</p>';
        echo '<p>' . sprintf(__('You can create PayPal API credentials in the <a href="%s" target="_blank">PayPal Developer Dashboard</a>.', 'woo-paypal-proxy-server'), 'https://developer.paypal.com/dashboard/applications/live') . '</p>';
    }
    
    /**
     * Environment field callback
     */
    public function environment_callback() {
        $value = get_option('wppps_paypal_environment', 'sandbox');
        ?>
        <select id="wppps_paypal_environment" name="wppps_paypal_environment">
            <option value="sandbox" <?php selected($value, 'sandbox'); ?>><?php _e('Sandbox (Testing)', 'woo-paypal-proxy-server'); ?></option>
            <option value="live" <?php selected($value, 'live'); ?>><?php _e('Live (Production)', 'woo-paypal-proxy-server'); ?></option>
        </select>
        <p class="description"><?php _e('Select the PayPal environment.', 'woo-paypal-proxy-server'); ?></p>
        <?php
    }
    
    /**
     * Client ID field callback
     */
    public function client_id_callback() {
        $value = get_option('wppps_paypal_client_id');
        echo '<input type="text" id="wppps_paypal_client_id" name="wppps_paypal_client_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Enter your PayPal API Client ID.', 'woo-paypal-proxy-server') . '</p>';
    }
    
    /**
     * Client Secret field callback
     */
    public function client_secret_callback() {
        $value = get_option('wppps_paypal_client_secret');
        echo '<input type="password" id="wppps_paypal_client_secret" name="wppps_paypal_client_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Enter your PayPal API Client Secret.', 'woo-paypal-proxy-server') . '</p>';
    }
    
    /**
     * AJAX handler for adding a site
     */
    public function ajax_add_site() {
        check_ajax_referer('wppps-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        $site_name = isset($_POST['site_name']) ? sanitize_text_field($_POST['site_name']) : '';
        $site_status = isset($_POST['site_status']) ? sanitize_text_field($_POST['site_status']) : 'active';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $api_secret = isset($_POST['api_secret']) ? sanitize_text_field($_POST['api_secret']) : '';
        
        // Validate inputs
        if (empty($site_url) || empty($site_name) || empty($api_key) || empty($api_secret)) {
            wp_send_json_error(array(
                'message' => __('All fields are required.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Validate URL
        if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array(
                'message' => __('Invalid site URL.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Check if API key already exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppps_sites';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE api_key = %s",
            $api_key
        ));
        
        if ($existing) {
            wp_send_json_error(array(
                'message' => __('API key already exists. Please generate a new one.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Add site to database
        $wpdb->insert(
            $table_name,
            array(
                'site_url' => $site_url,
                'site_name' => $site_name,
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'status' => $site_status,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );
        
        if ($wpdb->last_error) {
            wp_send_json_error(array(
                'message' => $wpdb->last_error
            ));
            wp_die();
        }
        
        wp_send_json_success(array(
            'message' => __('Site added successfully.', 'woo-paypal-proxy-server'),
            'site_id' => $wpdb->insert_id
        ));
        
        wp_die();
    }
    
    /**
     * AJAX handler for updating a site
     */
    public function ajax_update_site() {
        check_ajax_referer('wppps-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        $site_name = isset($_POST['site_name']) ? sanitize_text_field($_POST['site_name']) : '';
        $site_status = isset($_POST['site_status']) ? sanitize_text_field($_POST['site_status']) : 'active';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $api_secret = isset($_POST['api_secret']) ? sanitize_text_field($_POST['api_secret']) : '';
        
        // Validate inputs
        if (!$site_id || empty($site_url) || empty($site_name) || empty($api_key) || empty($api_secret)) {
            wp_send_json_error(array(
                'message' => __('All fields are required.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Validate URL
        if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array(
                'message' => __('Invalid site URL.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Check if API key already exists for another site
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppps_sites';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE api_key = %s AND id != %d",
            $api_key, $site_id
        ));
        
        if ($existing) {
            wp_send_json_error(array(
                'message' => __('API key already exists for another site. Please generate a new one.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Update site in database
        $wpdb->update(
            $table_name,
            array(
                'site_url' => $site_url,
                'site_name' => $site_name,
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'status' => $site_status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $site_id)
        );
        
        if ($wpdb->last_error) {
            wp_send_json_error(array(
                'message' => $wpdb->last_error
            ));
            wp_die();
        }
        
        wp_send_json_success(array(
            'message' => __('Site updated successfully.', 'woo-paypal-proxy-server')
        ));
        
        wp_die();
    }
    
    /**
     * AJAX handler for deleting a site
     */
    public function ajax_delete_site() {
        check_ajax_referer('wppps-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        
        if (!$site_id) {
            wp_send_json_error(array(
                'message' => __('Invalid site ID.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Delete site from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppps_sites';
        
        $wpdb->delete(
            $table_name,
            array('id' => $site_id)
        );
        
        if ($wpdb->last_error) {
            wp_send_json_error(array(
                'message' => $wpdb->last_error
            ));
            wp_die();
        }
        
        wp_send_json_success(array(
            'message' => __('Site deleted successfully.', 'woo-paypal-proxy-server')
        ));
        
        wp_die();
    }
    
    /**
     * AJAX handler for testing PayPal API connection
     */
    public function ajax_test_paypal() {
        check_ajax_referer('wppps-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        // Create an instance of the PayPal API class
        $paypal_api = new WPPPS_PayPal_API();
        
        // Try to get an access token
        $access_token = $paypal_api->get_access_token();
        
        if (!$access_token) {
            wp_send_json_error(array(
                'message' => __('Failed to connect to PayPal API. Please check your credentials.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        wp_send_json_success(array(
            'message' => __('Successfully connected to PayPal API!', 'woo-paypal-proxy-server')
        ));
        
        wp_die();
    }
    
    /**
     * Get all registered sites
     */
    private function get_sites() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppps_sites';
        
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    }
    
    /**
     * Get transaction log
     */
    private function get_transactions($limit = 100) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'wppps_transaction_log';
        $sites_table = $wpdb->prefix . 'wppps_sites';
        
        $query = "
            SELECT l.*, s.site_name
            FROM $log_table AS l
            LEFT JOIN $sites_table AS s ON l.site_id = s.id
            ORDER BY l.created_at DESC
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit));
    }
    
    /**
     * Get site by ID
     */
    public function get_site_by_id($site_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wppps_sites';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $site_id
        ));
    }
    
    /**
     * AJAX handler for getting a site
     */
    public function ajax_get_site() {
        check_ajax_referer('wppps-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        
        if (!$site_id) {
            wp_send_json_error(array(
                'message' => __('Invalid site ID.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        $site = $this->get_site_by_id($site_id);
        
        if (!$site) {
            wp_send_json_error(array(
                'message' => __('Site not found.', 'woo-paypal-proxy-server')
            ));
            wp_die();
        }
        
        wp_send_json_success(array(
            'site' => $site
        ));
        
        wp_die();
    }
}