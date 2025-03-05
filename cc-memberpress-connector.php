<?php
/**
 * Plugin Name: CC MemberPress Connector
 * Description: CC MemberPress Connector helps to integrate MemberPress with Constant Contact for OAuth authentication and contact creation on member signups.
 * Version: 1.0
 * Author: Sayan. M
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ConstantContactIntegration {
    public function __construct() {
        // Admin menu and settings
        add_action('admin_menu', [$this, 'create_settings_page']);
        add_action('admin_init', [$this, 'initialize_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX actions
        add_action('wp_ajax_generate_auth_url', [$this, 'generate_auth_url']);
        add_action('wp_ajax_revoke_access', [$this, 'revoke_access']);
        add_action('wp_ajax_fetch_ccmp_entries', [$this, 'fetch_ccmp_entries']);

        // Handle authentication response
        add_action('admin_init', [$this, 'handle_auth_response']);

        // Member signup event hook
        add_action('mepr-event-member-signup-completed', [$this, 'create_contact'],20,1);

        // Disable emojis
        add_action('init', [$this, 'disable_emojis']);

        // Activation hook
        register_activation_hook(__FILE__, [$this, 'create_ccmp_database_table']);
    }

    /**
    * Creates the database table for storing Constant Contact entries.
    */

    public function create_ccmp_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ccmp_entries';

        // Ensure the table does not exist before creating it
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id INT(11) NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL,
                date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    /**
     * Disables WordPress emojis - only for plugin backend
    */

    public function disable_emojis() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content', 'wp_staticize_emoji');
        remove_filter('the_excerpt', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    /**
    * Enqueues plugin styles and scripts.
    */

    public function enqueue_scripts() {
        // Enqueue styles
        wp_enqueue_style('cc_integration_styles', plugin_dir_url(__FILE__) . 'assets/css/style.css');
        wp_enqueue_style('datatables_css', 'https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css', [], '1.11.5');
    
        // Enqueue scripts
        wp_enqueue_script('datatables_js', 'https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js', ['jquery'], '1.11.5', true);
        wp_enqueue_script('cc_integration_script', plugin_dir_url(__FILE__) . 'assets/js/script.js', ['jquery', 'datatables_js'], null, true);
    
        // Localize script for AJAX
        wp_localize_script('cc_integration_script', 'cc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('revoke_access_nonce'),
            'refresh_token' => get_option('cc_refresh_token'),
        ]);
    }

    /**
    * Creates the admin settings page.
    */

    public function create_settings_page() {
        add_menu_page(
            'CC MemberPress Connector Settings',
            'CC MemberPress Connector',
            'manage_options',
            'cc-memberpress-connector-settings',
            [$this, 'settings_page_content'],
            'dashicons-admin-generic'
        );
    }

    /**
    * Initializes plugin settings.
    */

    public function initialize_settings() {
        register_setting('cc_settings_group', 'cc_client_id');
        register_setting('cc_settings_group', 'cc_client_secret');
        register_setting('cc_settings_group', 'cc_redirect_uri');
        register_setting('cc_settings_group', 'cc_access_token');
    }
    public function settings_page_content() {
        $client_id = get_option('cc_client_id');
        $client_secret = get_option('cc_client_secret');
        $redirect_uri = get_option('cc_redirect_uri');
        $access_token = get_option('cc_access_token');
        $is_connected = !empty($access_token);
        ?>
    
    <div class="cc-admin-page">
        <div class="wrap">
            <h1>CCMC Settings</h1>
            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active" data-tab="cc-settings">API Settings</a>
                <a href="#" class="nav-tab" data-tab="cc-entries">Constant Contact Entries</a>
            </h2>
            <div id="cc-settings" class="cc-tab-content active">
                <form method="post" action="options.php">
                    <?php settings_fields('cc_settings_group'); ?>
                    <?php do_settings_sections('cc_settings_group'); ?>
                    <div class="form-table-wrap">
                        <table class="form-table">
                            <tr>
                                <th><label for="cc_client_id">Client ID</label></th>
                                <td>
                                    <input type="text" name="cc_client_id" id="cc_client_id" 
                                        value="<?php echo esc_attr($client_id); ?>" 
                                        <?php echo $is_connected ? 'disabled' : ''; ?> required>
                                    <?php if ($is_connected) : ?>
                                        <?php echo wp_kses_post('<p style="color: green; font-weight: bold; margin-top: 5px; font-size: 12px; font-family: Arial, sans-serif;">&#x1F7E2; Connected to Constant Contact</p>'); ?>
                                    <?php else : ?>
                                    <?php echo wp_kses_post('<p style="color: #a94442; font-weight: bold; margin-top: 5px; font-size: 12px; font-family: Arial, sans-serif;">&#x1F534; Not Connected to Constant Contact</p>'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="cc_client_secret">Client Secret</label></th>
                                <td><input type="text" name="cc_client_secret" id="cc_client_secret" 
                                        value="<?php echo esc_attr($client_secret); ?>" 
                                        <?php echo $is_connected ? 'disabled' : ''; ?> required></td>
                            </tr>
                            <tr>
                                <th><label for="cc_redirect_uri">Redirect URI</label></th>
                                <td><input type="url" name="cc_redirect_uri" id="cc_redirect_uri" 
                                        value="<?php echo esc_attr($redirect_uri); ?>" 
                                        <?php echo $is_connected ? 'disabled' : ''; ?> required></td>
                            </tr>
                        </table>
                    </div>
                    <div class="cc-button-group">
                        <?php if (!$is_connected) : ?>
                            <?php submit_button('Save Changes'); ?>
                            <button id="cc_connect_button" class="button button-primary">Connect</button>
                        <?php else : ?>
                            <button id="cc_revoke_button" class="button button-secondary">Revoke Access</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <!-- Submission Logs Tab -->
            <div id="cc-entries" class="cc-tab-content" style="display: none;">
            <table id="cc-entries-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Id</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Created Date</th>
                </tr>
            </thead>
            <tbody></tbody>
            </table>
            </div>
        </div>
    </div>
        <?php
    }

    /**
    * Generates the authorization URL for OAuth authentication.
    */

    public function generate_auth_url() {
        $client_id = get_option('cc_client_id');
        $redirect_uri = get_option('cc_redirect_uri');

        if (!$client_id || !$redirect_uri) {
            wp_send_json_error(['message' => 'Missing required credentials.']);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['state'] = bin2hex(random_bytes(16));

        $auth_url = "https://authz.constantcontact.com/oauth2/default/v1/authorize?" . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'state' => $_SESSION['state'],
            'scope' => 'contact_data campaign_data offline_access'
        ]);

        wp_send_json_success(['auth_url' => $auth_url]);
    }

    /**
    * Handles the authentication response from Constant Contact.
    */

    public function handle_auth_response() {
        if (isset($_GET['code']) && isset($_GET['state'])) {
            $client_id = get_option('cc_client_id');
            $client_secret = get_option('cc_client_secret');
            $redirect_uri = get_option('cc_redirect_uri');
            $code = sanitize_text_field($_GET['code']);

            $response = wp_remote_post('https://authz.constantcontact.com/oauth2/default/v1/token', [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $code,
                    'redirect_uri' => $redirect_uri
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
            ]);

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($data['access_token'])) {
                update_option('cc_access_token', $data['access_token'], true);
                update_option('cc_refresh_token', $data['refresh_token'], true);
                update_option('cc_access_token_expiry', time() + $data['expires_in'], true); // Store expiry time
    
                wp_redirect(admin_url('admin.php?page=cc-memberpress-connector-settings&success=1'));
                exit;
            }
        
        }
        
    }

    /**
    * Handles CC Token Refresh.
    */

    public function get_valid_access_token() {
        $access_token = get_option('cc_access_token');
        $expiry_time = get_option('cc_access_token_expiry');
    
        // If token is missing or expired, refresh it
        if (!$access_token || !$expiry_time || time() >= $expiry_time) {
            $client_id = get_option('cc_client_id');
            $client_secret = get_option('cc_client_secret');
            $refresh_token = get_option('cc_refresh_token');
    
            if (!$refresh_token) {
                return false; // No refresh token available
            }
    
            $response = wp_remote_post('https://authz.constantcontact.com/oauth2/default/v1/token', [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
            ]);
    
            $data = json_decode(wp_remote_retrieve_body($response), true);
    
            if (isset($data['access_token'])) {
                update_option('cc_access_token', $data['access_token'], true);
                update_option('cc_refresh_token', $data['refresh_token'], true);
                update_option('cc_access_token_expiry', time() + $data['expires_in'], true);
                return $data['access_token'];
            }
    
            return false;
        }
    
        return $access_token;
    }

    /**
    * Creating Contacts in CC on memberpress sign-ups.
    */

    function create_contact($event) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ccmp_entries';
    
        $access_token = $this->get_valid_access_token();
    
        if (!$access_token) {
            return; // Unable to retrieve a valid token, stop execution
        }
    
        $user = $event->get_data();
        $mepr_user = new MeprUser($user->ID);
    
        // Fetch user details
        $first_name = sanitize_text_field($mepr_user->first_name);
        error_log('FIRST NAME: ' . $first_name);
    
        $last_name = sanitize_text_field($mepr_user->last_name);
        error_log('LAST NAME: ' . $last_name);
    
        $email = sanitize_email($mepr_user->user_email);
    
        $contact_data = [
            "email_address" => [
                "address" => (string) $email,
                "permission_to_send" => "implicit"
            ],
            "first_name" => (string) $first_name,
            "last_name" => (string) $last_name,
            "create_source" => "Account",
        ];
    
        $response = wp_remote_post('https://api.cc.email/v3/contacts', [
            'body' => json_encode($contact_data),
            'headers' => [
                'Authorization' => "Bearer $access_token",
                'Content-Type' => 'application/json'
            ],
        ]);
    
        $body = wp_remote_retrieve_body($response);
        error_log('API RESPONSE: ' . print_r($body, true)); // Log the full response
    
        if (is_wp_error($response)) {
            $status = 'Failed';
            $error_message = $response->get_error_message();
            error_log('API ERROR: ' . print_r($error_message, true));
        } else {
            $status = 'Success';
            $error_message = '';
            error_log('API SUCCESS: ' . print_r($body, true));
        }
    
        // Insert log entry into database
        $wpdb->insert(
            $table_name,
            [
                'email'  => $email,
                'status' => $status,
                'date'   => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );
    }
    
    /**
    * Revoking CC Access.
    */

    public function revoke_access() {
        check_ajax_referer('revoke_access_nonce', 'nonce');
        delete_option('cc_access_token');
        delete_option('cc_refresh_token');
        wp_send_json_success(['message' => 'Access revoked successfully.']);
    }

    /**
    * Display CC entries using jQuery datatable server-side scripting.
    */

    function fetch_ccmp_entries() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ccmp_entries'; 
    
        // Get request parameters for pagination
        $limit = isset($_POST['length']) ? intval($_POST['length']) : 10;
        $offset = isset($_POST['start']) ? intval($_POST['start']) : 0;
        $order_column = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : 0;
        $order_dir = isset($_POST['order'][0]['dir']) ? sanitize_sql_orderby($_POST['order'][0]['dir']) : 'desc';
        $search_value = isset($_POST['search']['value']) ? sanitize_text_field($_POST['search']['value']) : '';
    
        $columns = ['email', 'status', 'date']; // ✅ Match table columns
        $order_by = isset($columns[$order_column]) ? $columns[$order_column] : 'date';
    
        // ✅ Construct base query
        $where = '';
        if (!empty($search_value)) {
            $where = $wpdb->prepare("WHERE email LIKE %s", '%' . $wpdb->esc_like($search_value) . '%');
        }
    
        // ✅ Get total records
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
        // ✅ Get filtered records
        $filtered_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
    
        // ✅ Fetch paginated results with search
        $query = $wpdb->prepare(
            "SELECT date, email, status FROM $table_name $where ORDER BY $order_by $order_dir LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        $results = $wpdb->get_results($query, ARRAY_A);
    
        // ✅ Prepare response
        $response = [
            "draw"            => isset($_POST['draw']) ? intval($_POST['draw']) : 1,
            "recordsTotal"    => $total_records,
            "recordsFiltered" => $filtered_records, // ✅ Correct filtered count
            "data"            => $results ? $results : [], // Ensure empty array if no results
        ];
    
        wp_send_json($response);
    }
    
 
}

new ConstantContactIntegration();
