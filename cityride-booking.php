<?php
/**
 * Plugin Name: CityRide Booking
 * Plugin URI: https://yoursite.com
 * Description: Kompletan WordPress plugin za rezervaciju vožnji s Stripe plaćanjem, Mapbox rutama i OneSignal push notifikacijama
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('CITYRIDE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CITYRIDE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CITYRIDE_VERSION', '1.0.0');

class CityRideBooking {

    public function __construct() {
        // Initialize hooks
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize Stripe library
        $this->init_stripe();
    }

    /**
     * Initializes various WordPress hooks for admin and frontend.
     */
    public function init() {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        }

        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        add_shortcode('cityride_booking_form', array($this, 'booking_form_shortcode'));

        // AJAX hooks for frontend (public access)
        add_action('wp_ajax_cityride_calculate_price', array($this, 'ajax_calculate_price'));
        add_action('wp_ajax_nopriv_cityride_calculate_price', array($this, 'ajax_calculate_price'));

        add_action('wp_ajax_cityride_create_payment_intent', array($this, 'ajax_create_payment_intent'));
        add_action('wp_ajax_nopriv_cityride_create_payment_intent', array($this, 'ajax_create_payment_intent'));

        add_action('wp_ajax_cityride_save_booking', array($this, 'ajax_save_booking'));
        add_action('wp_ajax_nopriv_cityride_save_booking', array($this, 'ajax_save_booking'));


        // AJAX hooks for admin (requires authentication)
        add_action('wp_ajax_cityride_load_rides', array($this, 'ajax_load_rides'));
        add_action('wp_ajax_cityride_assign_driver', array($this, 'ajax_assign_driver'));
        add_action('wp_ajax_cityride_complete_ride', array($this, 'ajax_complete_ride'));
        add_action('wp_ajax_cityride_export_rides', array($this, 'ajax_export_rides')); // Returns CSV data via AJAX
        add_action('wp_ajax_cityride_test_webhook', array($this, 'ajax_test_webhook'));

        // REST API Endpoint for Stripe Webhooks
        add_action( 'rest_api_init', array($this, 'register_stripe_webhook_endpoint'));
    }

    /**
     * Plugin activation hook. Creates the database table and sets default options.
     */
    public function activate() {
        $this->create_database_table();

        // Add default options if they don't exist
        add_option('cityride_stripe_public_key', '');
        add_option('cityride_stripe_secret_key', '');
        add_option('cityride_mapbox_api_key', '');
        add_option('cityride_onesignal_app_id', '');
        add_option('cityride_onesignal_api_key', '');
        add_option('cityride_make_webhook_url', '');
        add_option('cityride_start_tariff', '2.50');
        add_option('cityride_price_per_km', '1.50');
        add_option('cityride_enable_push_notifications', '1');
        add_option('cityride_enable_webhook_notifications', '1');
        add_option('cityride_stripe_webhook_secret', '');
    }

    /**
     * Plugin deactivation hook. (Optional: Add cleanup code here).
     */
    public function deactivate() {
        // Example: delete plugin options on deactivation (uncomment if desired)
        // delete_option('cityride_stripe_public_key');
        // delete_option('cityride_stripe_secret_key');
        // ... all other options

        // Example: drop the custom table on deactivation (use with caution, as it deletes data)
        // global $wpdb;
        // $table_name = $wpdb->prefix . 'cityride_rides';
        // $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }

    /**
     * Initializes the Stripe PHP library.
     * Checks for Stripe library paths and includes it.
     * Displays an admin notice if the library is not found.
     *
     * @return bool True if Stripe library is loaded, false otherwise.
     */
    private function init_stripe() {
        if (!class_exists('\Stripe\Stripe')) {
            $stripe_paths = [
                CITYRIDE_PLUGIN_PATH . 'includes/stripe-php/init.php',
                CITYRIDE_PLUGIN_PATH . 'vendor/stripe/stripe-php/init.php',
            ];

            $stripe_loaded = false;
            foreach ($stripe_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $stripe_loaded = true;
                    break;
                }
            }

            if (!$stripe_loaded) {
                add_action('admin_notices', array($this, 'stripe_library_missing_notice'));
                return false;
            }
        }
        return true;
    }

    /**
     * AJAX handler for calculating price and fetching route from Mapbox.
     */
    public function ajax_calculate_price() {
        check_ajax_referer('cityride_frontend_nonce', 'nonce');

        $address_from = sanitize_text_field($_POST['address_from']);
        $address_to = sanitize_text_field($_POST['address_to']);

        if (empty($address_from) || empty($address_to)) {
            wp_send_json_error('Polazna i dolazna adresa su obavezne.');
        }

        $mapbox_api_key = get_option('cityride_mapbox_api_key');
        if (empty($mapbox_api_key)) {
            wp_send_json_error('Mapbox API ključ nije konfigurisan. Molimo kontaktirajte administratora.');
        }

        // Use Mapbox Geocoding to get coordinates
        $from_coords = $this->get_mapbox_coordinates($address_from, $mapbox_api_key);
        $to_coords = $this->get_mapbox_coordinates($address_to, $mapbox_api_key);

        if (!$from_coords) {
            wp_send_json_error('Nije moguće pronaći koordinate za polazište: ' . esc_html($address_from) . '. Molimo unesite precizniju adresu.');
        }
        if (!$to_coords) {
            wp_send_json_error('Nije moguće pronaći koordinate za odredište: ' . esc_html($address_to) . '. Molimo unesite precizniju adresu.');
        }

        // Use Mapbox Directions API to get route and distance
        $directions_url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$from_coords['lng']},{$from_coords['lat']};{$to_coords['lng']},{$to_coords['lat']}";
        $directions_url .= "?access_token={$mapbox_api_key}&geometries=geojson&steps=false";

        $response = wp_remote_get($directions_url);

        if (is_wp_error($response)) {
            error_log('CityRide Mapbox Directions Error: ' . $response->get_error_message());
            wp_send_json_error('Greška pri dohvatu rute: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['routes'][0]['distance']) || !isset($data['routes'][0]['geometry']) || $data['routes'][0]['distance'] <= 0) {
            error_log('CityRide Mapbox Directions Error: Nije pronađena validna ruta ili je udaljenost 0. Podaci: ' . print_r($data, true));
            wp_send_json_error('Nije moguće pronaći rutu između odabranih lokacija ili je udaljenost nula. Molimo pokušajte sa preciznijim adresama unutar BiH.');
        }

        $distance_meters = $data['routes'][0]['distance']; // Distance in meters
        $distance_km = round($distance_meters / 1000, 2); // Convert to kilometers

        $route_geometry = $data['routes'][0]['geometry'];

        if ($distance_km <= 0) {
            wp_send_json_error('Neispravna udaljenost rute (0 km). Provjerite adrese.');
        }

        $start_tariff = floatval(get_option('cityride_start_tariff', '2.50'));
        $price_per_km = floatval(get_option('cityride_price_per_km', '1.50'));

        $total_price = $start_tariff + ($distance_km * $price_per_km);
        $total_price = round($total_price, 2);

        // Convert to stripe format (smallest currency unit, e.g., cents)
        $stripe_amount = round($total_price * 100);

        wp_send_json_success([
            'distance_km' => $distance_km,
            'total_price' => $total_price,
            'stripe_amount' => $stripe_amount,
            'start_tariff' => $start_tariff,
            'price_per_km' => $price_per_km,
            'route_geometry' => $route_geometry,
            'from_coords' => $from_coords,
            'to_coords' => $to_coords
        ]);
    }

    /**
     * Helper function to get coordinates from an address using Mapbox Geocoding API.
     *
     * @param string $address The address to geocode.
     * @param string $mapbox_api_key Your Mapbox API key.
     * @return array|false An associative array with 'lat' and 'lng' on success, false on failure.
     */
    private function get_mapbox_coordinates($address, $mapbox_api_key) {
        $encoded_address = urlencode($address);
        // Prefer localities in Bosnia and Herzegovina (BA) using 'country' parameter
        $geocode_url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$encoded_address}.json?access_token={$mapbox_api_key}&country=BA&limit=1";

        $response = wp_remote_get($geocode_url);

        if (is_wp_error($response)) {
            error_log('CityRide Mapbox Geocoding Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['features'][0]['center']) && count($data['features'][0]['center']) === 2) {
            return [
                'lng' => $data['features'][0]['center'][0],
                'lat' => $data['features'][0]['center'][1]
            ];
        }

        error_log('CityRide Mapbox Geocoding: No coordinates found for address: ' . $address . ' Response: ' . print_r($data, true));
        return false;
    }

    /**
     * AJAX handler for creating a Stripe PaymentIntent.
     * Validates input, sets Stripe API key, and creates a PaymentIntent.
     */
    public function ajax_create_payment_intent() {
        check_ajax_referer('cityride_frontend_nonce', 'nonce');

        if (!$this->init_stripe()) {
            wp_send_json_error('Stripe nije pravilno konfigurisan');
        }

        $secret_key = get_option('cityride_stripe_secret_key');
        if (empty($secret_key)) {
            wp_send_json_error('Stripe Secret Key nije konfigurisan');
        }

        \Stripe\Stripe::setApiKey($secret_key);

        // Ensure amount is in smallest currency unit (e.g., cents for USD/BAM)
        // The frontend passes it already multiplied by 100
        $amount = floatval($_POST['amount']);
        $address_from = sanitize_text_field($_POST['address_from']);
        $address_to = sanitize_text_field($_POST['address_to']);
        $distance_km = floatval($_POST['distance_km']);
        $passenger_name = sanitize_text_field($_POST['passenger_name']);
        $passenger_phone = sanitize_text_field($_POST['passenger_phone']);
        $passenger_email = sanitize_email($_POST['passenger_email'] ?? '');
        // OneSignal player ID is added to metadata here
        $passenger_onesignal_id = sanitize_text_field($_POST['passenger_onesignal_id'] ?? '');

        if (empty($amount) || $amount <= 0) {
            wp_send_json_error('Nevažeći iznos');
        }

        if (empty($address_from) || empty($address_to) || empty($passenger_name) || empty($passenger_phone)) {
            wp_send_json_error('Molimo popunite sva obavezna polja');
        }

        try {
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'bam', // Bosnia and Herzegovina Mark
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'metadata' => [
                    'address_from' => $address_from,
                    'address_to' => $address_to,
                    'distance_km' => $distance_km,
                    'passenger_name' => $passenger_name,
                    'passenger_phone' => $passenger_phone,
                    'passenger_email' => $passenger_email,
                    'passenger_onesignal_id' => $passenger_onesignal_id, // Pass OneSignal ID
                    'plugin' => 'cityride'
                ],
                'description' => "CityRide vožnja: {$address_from} → {$address_to}"
            ]);

            wp_send_json_success([
                'client_secret' => $payment_intent->client_secret,
                'payment_intent_id' => $payment_intent->id
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('CityRide Stripe Error: ' . $e->getMessage());
            wp_send_json_error('Greška pri kreiranju plaćanja: ' . $e->getMessage());
        } catch (Exception $e) {
            error_log('CityRide General Error: ' . $e->getMessage());
            wp_send_json_error('Dogodila se greška. Molimo pokušajte ponovo.');
        }
    }

    /**
     * AJAX handler for saving a booking after successful payment.
     * Verifies payment with Stripe and saves booking details to the database.
     */
    public function ajax_save_booking() {
        check_ajax_referer('cityride_frontend_nonce', 'nonce');

        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id']);
        $address_from = sanitize_text_field($_POST['address_from']);
        $address_to = sanitize_text_field($_POST['address_to']);
        $distance_km = floatval($_POST['distance_km']);
        $total_price = floatval($_POST['total_price']);
        $passenger_name = sanitize_text_field($_POST['passenger_name']);
        $passenger_phone = sanitize_text_field($_POST['passenger_phone']);
        $passenger_email = sanitize_email($_POST['passenger_email'] ?? '');
        $passenger_onesignal_id = sanitize_text_field($_POST['passenger_onesignal_id'] ?? ''); // Expecting this from frontend

        if (empty($payment_intent_id) || empty($address_from) || empty($address_to) ||
            empty($passenger_name) || empty($passenger_phone)) {
            wp_send_json_error('Nedostaju obavezni podaci.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Check if the ride for this payment intent already exists to prevent duplicates
        $existing_ride = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table_name WHERE stripe_payment_id = %s", $payment_intent_id));
        if ($existing_ride) {
            wp_send_json_success([
                'booking_id' => $existing_ride->id,
                'message' => 'Rezervacija je već kreirana za ovaj Payment Intent.'
            ]);
            return;
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'passenger_name' => $passenger_name,
                'passenger_phone' => $passenger_phone,
                'address_from' => $address_from,
                'address_to' => $address_to,
                'distance_km' => $distance_km,
                'total_price' => $total_price,
                'stripe_payment_id' => $payment_intent_id,
                'passenger_email' => $passenger_email,
                'passenger_onesignal_id' => $passenger_onesignal_id, // Store player ID in DB
                'status' => 'payed_unassigned',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array(
                '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );

        if ($result === false) {
            error_log('CityRide: Database insert failed in ajax_save_booking: ' . $wpdb->last_error);
            wp_send_json_error('Greška pri spremanju rezervacije.');
        }

        $booking_id = $wpdb->insert_id;

        if (get_option('cityride_enable_webhook_notifications') === '1') {
            $this->send_webhook_notification($booking_id);
        }

        wp_send_json_success([
            'booking_id' => $booking_id,
            'message' => 'Rezervacija je uspješno kreirana i plaćena!'
        ]);
    }

    /**
     * Verifies a Stripe payment using the PaymentIntent ID.
     * This function is primarily for server-side verification, not directly used in the frontend AJAX.
     *
     * @param string $payment_intent_id The Stripe PaymentIntent ID.
     * @return array|false Payment data if successful, false otherwise.
     */
    private function verify_stripe_payment($payment_intent_id) {
        if (!$this->init_stripe()) {
            return false;
        }

        $secret_key = get_option('cityride_stripe_secret_key');
        if (empty($secret_key)) {
            return false;
        }

        \Stripe\Stripe::setApiKey($secret_key);

        try {
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

            if ($payment_intent->status !== 'succeeded') {
                return false;
            }

            return [
                'id'        => $payment_intent->id,
                'amount'    => $payment_intent->amount,    // In smallest currency unit
                'currency'  => $payment_intent->currency,   // BAM, EUR, etc.
                'status'    => $payment_intent->status,
                'metadata'  => $payment_intent->metadata // Include metadata for detailed verification
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('CityRide: Stripe verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a webhook notification to Make.com for new bookings.
     *
     * @param int $booking_id The ID of the newly created booking.
     */
    private function send_webhook_notification($booking_id) {
        $webhook_url = get_option('cityride_make_webhook_url');

        if (empty($webhook_url)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $booking_id));

        if (!$ride) {
            return;
        }

        $webhook_data = array(
            'booking_id' => $ride->id,
            'address_from' => $ride->address_from,
            'address_to' => $ride->address_to,
            'distance_km' => $ride->distance_km,
            'total_price' => $ride->total_price,
            'status' => $ride->status,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'passenger_email' => $ride->passenger_email,
            'passenger_onesignal_id' => $ride->passenger_onesignal_id, // Include OneSignal ID
            'cab_driver_id' => $ride->cab_driver_id,
            'eta' => $ride->eta,
            'created_at' => $ride->created_at,
            'updated_at' => $ride->updated_at,
            'stripe_payment_id' => $ride->stripe_payment_id
        );

        // Use non-blocking request for webhooks to avoid delaying user response
        wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($webhook_data),
            'timeout' => 30, // Timeout in seconds
            'blocking' => false, // Make the request asynchronous
            'sslverify' => defined('WP_DEBUG') && WP_DEBUG ? false : true // Set to true in production for security
        ));
    }

    /**
     * Sends a test webhook notification to Make.com.
     */
    public function ajax_test_webhook() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za pristup.');
        }

        $webhook_url = get_option('cityride_make_webhook_url');

        if (empty($webhook_url)) {
            wp_send_json_error('Make.com Webhook URL nije konfigurisan.');
        }

        $test_data = array(
            'event' => 'test_webhook',
            'timestamp' => current_time('mysql'),
            'message' => 'Ovo je testna poruka iz CityRide WordPress plugin-a.'
        );

        $response = wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($test_data),
            'timeout' => 10,
            'sslverify' => defined('WP_DEBUG') && WP_DEBUG ? false : true
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Greška pri slanju testnog webhooka: ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code >= 200 && $response_code < 300) {
                wp_send_json_success('Testni webhook uspješno poslan. Odgovor: ' . $response_code);
            } else {
                wp_send_json_error('Testni webhook poslan, ali je primljen odgovor: ' . $response_code . ' - ' . wp_remote_retrieve_response_message($response));
            }
        }
    }


    /**
     * Displays an admin notice if the Stripe PHP library is missing.
     */
    public function stripe_library_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>CityRide:</strong> Stripe PHP library nije pronađena. Molimo instalirajte Stripe PHP SDK u <code>' . CITYRIDE_PLUGIN_PATH . 'includes/stripe-php/</code> folder ili putem Composera u <code>' . CITYRIDE_PLUGIN_PATH . 'vendor/</code>.</p></div>';
    }

    /**
     * Creates the custom database table for CityRide bookings.
     */
    private function create_database_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cityride_rides';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            address_from varchar(255) NOT NULL,
            address_to varchar(255) NOT NULL,
            distance_km decimal(10,2) NOT NULL,
            total_price decimal(10,2) NOT NULL,
            status enum('payed_unassigned','payed_assigned','completed') DEFAULT 'payed_unassigned',
            cab_driver_id varchar(100) DEFAULT NULL,
            eta varchar(50) DEFAULT NULL,
            stripe_payment_id varchar(255) DEFAULT NULL,
            passenger_name varchar(255) DEFAULT NULL,
            passenger_phone varchar(50) DEFAULT NULL,
            passenger_email varchar(255) DEFAULT NULL,
            passenger_onesignal_id varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Adds admin menu pages for the plugin.
     */
    public function add_admin_menu() {
        add_menu_page(
            'CityRide Booking',
            'CityRide',
            'manage_options',
            'cityride-booking',
            array($this, 'admin_main_page'),
            'dashicons-car',
            30
        );

        add_submenu_page(
            'cityride-booking',
            'Konfiguracija',
            'Konfiguracija',
            'manage_options',
            'cityride-config',
            array($this, 'admin_config_page')
        );
    }

    /**
     * Registers plugin settings for the admin configuration page.
     */
    public function admin_init() {
        register_setting('cityride_settings', 'cityride_stripe_public_key');
        register_setting('cityride_settings', 'cityride_stripe_secret_key');
        register_setting('cityride_settings', 'cityride_mapbox_api_key');
        register_setting('cityride_settings', 'cityride_onesignal_app_id');
        register_setting('cityride_settings', 'cityride_onesignal_api_key');
        register_setting('cityride_settings', 'cityride_make_webhook_url');
        register_setting('cityride_settings', 'cityride_start_tariff');
        register_setting('cityride_settings', 'cityride_price_per_km');
        register_setting('cityride_settings', 'cityride_enable_push_notifications');
        register_setting('cityride_settings', 'cityride_enable_webhook_notifications');
        register_setting('cityride_settings', 'cityride_stripe_webhook_secret');
    }

   /**
 * Enqueues admin-specific scripts and styles.
 *
 * @param string $hook The current admin page hook.
 */
public function admin_enqueue_scripts($hook) {
    // Only enqueue on CityRide admin pages
    if (strpos($hook, 'cityride') !== false) {
        wp_enqueue_style('cityride-admin-css', CITYRIDE_PLUGIN_URL . 'assets/admin-style.css', array(), CITYRIDE_VERSION);
        // KLJUČNA PROMJENA OVDJE: Koristimo time() za verziju admin-script.js
        wp_enqueue_script('cityride-admin-js', CITYRIDE_PLUGIN_URL . 'assets/admin-script.js', array('jquery'), time(), true);

        // Localize script to pass AJAX URL and nonce to JavaScript
        wp_localize_script('cityride-admin-js', 'cityride_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cityride_admin_nonce')
        ));
    }
}

    /**
     * Enqueues frontend-specific scripts and styles.
     */
    public function frontend_enqueue_scripts() {
        // Enqueue styles
        wp_enqueue_style('cityride-frontend-css', CITYRIDE_PLUGIN_URL . 'assets/frontend-style.css', array(), CITYRIDE_VERSION);
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4', 'all' );
        wp_enqueue_style('mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css');

        // Mapbox JS and Stripe JS
        wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js', array(), null, true);
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);

        // --- OVDJE JE UKLONJENA ONE SIGNAL INICIJALIZACIJA ---
        // OneSignal WordPress plugin se brine za učitavanje i inicijalizaciju OneSignal SDK-a.
        // Nema potrebe za dupliciranjem koda ovdje.
        // --- KRAJ UKLANJANJA ---

        // Vaš frontend-script.js
        // Sada zavisi samo od jQuery, Mapbox i Stripe jer OneSignal plugin osigurava OneSignal objekat globalno.
        // Koristimo time() za verziju da bi se osiguralo da se nova verzija učita tokom debugiranja.
        wp_enqueue_script('cityride-frontend-js', CITYRIDE_PLUGIN_URL . 'assets/frontend-script.js', array('jquery', 'mapbox-gl-js', 'stripe-js'), time(), true);

        // Localize script for frontend AJAX calls
        wp_localize_script('cityride-frontend-js', 'cityride_frontend_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cityride_frontend_nonce'),
            'mapbox_api_key' => get_option('cityride_mapbox_api_key'),
            'stripe_public_key' => get_option('cityride_stripe_public_key'),
            'enable_push_notifications' => get_option('cityride_enable_push_notifications', '1')
        ));
    }

    /**
     * Shortcode callback for displaying the booking form.
     *
     * @param array $atts Shortcode attributes (not used in this example).
     * @return string The HTML content of the booking form.
     */
    public function booking_form_shortcode($atts) {
    ob_start();
    ?>
    <div class="cityride-booking-wrapper">
        <div id="cityride-booking-form-container" class="cityride-booking-form">
            <div class="form-header">
             <?php
                // --- PLACE YOUR LOGO HERE ---
                // Assuming your logo is in a similar 'assets/images' directory within your plugin.
                // Adjust the path 'cityride-logo.png' if your filename is different.
                $logo_url = CITYRIDE_PLUGIN_URL . 'assets/images/city-ride-logo.png'; // Make sure this path is correct
                ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="CityRide Logo" class="cityride-logo" style="width: 250px; height: auto; display: block; margin: 0 auto 20px;">
            <h2>Dva klika do taksija i bez gotovine!</h2>
                <p>Unesite adrese, izračunajte fiksnu cijenu i sigurno platite karticom. Vaš taxi je tu.</p>
            </div>
            <form id="cityride-booking-form">
                <?php wp_nonce_field('cityride_frontend_nonce', 'cityride_nonce'); ?>

                <div class="form-row"> <div class="form-group">
                        <label for="address-from">Polazište:</label>
                        <input type="text" id="address-from" placeholder="Unesite polazište" required>
                    </div>
                    <div class="form-group">
                        <label for="address-to">Odredište:</label>
                        <input type="text" id="address-to" placeholder="Unesite odredište" required>
                    </div>
                </div>

                <div class="form-row"> <div class="form-group">
                        <label for="passenger-name">Vaše ime:</label>
                        <input type="text" id="passenger-name" placeholder="Puno ime" required>
                    </div>
                    <div class="form-group">
                        <label for="passenger-phone">Vaš broj telefona:</label>
                        <input type="tel" id="passenger-phone" placeholder="Broj telefona" required>
                    </div>
                </div>
                <div class="form-group"> <label for="passenger-email">Vaš Email (opciono):</label>
                    <input type="email" id="passenger-email" placeholder="Email adresa">
                </div>
                <input type="hidden" id="passenger_onesignal_id" name="passenger_onesignal_id">

                <div class="form-actions"> <button type="button" id="calculate-price-btn" class="btn btn-primary">Izračunaj cijenu</button>
                </div>

                <div class="map-container" style="display: none;"> <div id="cityride-map" style="height: 300px; width: 100%;"></div>
                </div>

                <div id="price-calculation-section" class="price-section" style="display: none;"> <div class="section-header">
                        <h2>Detalji vožnje i cijene</h2>
                    </div>
                    <div class="price-info-card">
                        <div class="price-detail-item">
                            <span class="label">Udaljenost:</span>
                            <span class="value"><span id="distance-display"></span> km</span>
                        </div>
                        <div class="price-detail-item">
                            <span class="label">Startna tarifa:</span>
                            <span class="value"><span id="start-tariff-display"></span> BAM</span>
                        </div>
                        <div class="price-detail-item">
                            <span class="label">Cijena po km:</span>
                            <span class="value"><span id="price-per-km-display"></span> BAM</span>
                        </div>
                        <div class="price-separator"></div>
                        <div class="total-price-item">
                            <span class="label">Ukupna cijena:</span>
                            <span class="value main-total-price"><span id="total-price"></span> BAM</span>
                        </div>
                        <p class="disclaimer">Provjerite da su adrese ispravno označene na mapi – pogrešan broj ili naziv ulice može uticati na proračun.</p>
                    </div>
                </div>
                <input type="hidden" id="calculated_distance_km" name="distance_km">
                <input type="hidden" id="calculated_total_price" name="total_price">
                <input type="hidden" id="calculated_stripe_amount" name="stripe_amount">

                <div id="payment-section" class="payment-section" style="display: none;"> <div class="payment-header">
                        <div class="card-icons">
                            <img src="<?php echo CITYRIDE_PLUGIN_URL . 'assets/images/Visa_Inc._logo.svg'; ?>" alt="Visa" class="card-icon">
                            <img src="<?php echo CITYRIDE_PLUGIN_URL . 'assets/images/Mastercard-logo.svg'; ?>" alt="Mastercard" class="card-icon">
                            <img src="<?php echo CITYRIDE_PLUGIN_URL . 'assets/images/Amex.svg'; ?>" alt="Amex" class="card-icon">
                        </div>
                    </div>
                    <p class="reassurance-text">
                        </i>Zaštićeno plaćanje putem Stripe platforme. Vaši podaci ostaju privatni i sigurni.
                    </p>
                    <div id="stripe-card-element">
                    </div>
                    <div id="stripe-card-errors" role="alert" style="color: red; margin-top: 10px;"></div>
                    <button type="button" id="complete-payment-btn" class="btn btn-success" style="margin-top: 20px;">Pozovi taxi</button>
                </div>
            </form>
            <div id="cityride-message" style="display: none;" class="cityride-message"></div>
            <div id="loading-spinner" class="cityride-loading-spinner" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Učitavam...</span>
                </div>
            </div>
            <div id="booking-success" class="booking-success" style="display: none;"> <div class="success-icon">✅</div>
                <h3>🎉 Rezervacija uspješna!</h3>
                <p>Vaša vožnja je uspješno rezervisana. ID rezervacije: <strong id="booking-id"></strong></p>
                <p>Uskoro ćete primiti potvrdu i detalje o vozaču.</p>
                <button class="btn btn-primary" onclick="location.reload();">Napravi novu rezervaciju</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

    /**
     * Admin main page callback. Displays the ride management interface.
     */
    public function admin_main_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemate dovoljna prava za pristup ovoj stranici.'));
        }
        ?>
        <div class="wrap cityride-admin-page">
            <h1 class="wp-heading-inline">CityRide – Kontrolna ploča dispečera</h1>
            <hr class="wp-header-end">

            <div id="cityride-admin-message" class="notice" style="display: none;"></div>

            <div class="cityride-dashboard-widgets">
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3>Vožnje danas</h3>
                        <p class="stat-number" id="stat-today-rides">0</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <h3>Vožnje ovaj mjesec</h3>
                        <p class="stat-number" id="stat-this-month-rides">0</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <h3>Prihod ovaj mjesec</h3>
                        <p class="stat-number" id="stat-monthly-revenue">0.00 BAM</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-content">
                        <h3>Zahtjevi za prijevoz</h3>
                        <p class="stat-number" id="stat-pending-rides">0</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">🚗</div>
                    <div class="stat-content">
                        <h3>Dodijeljene</h3>
                        <p class="stat-number" id="stat-assigned-rides">0</p>
                    </div>
                </div>
            </div>

            <div class="cityride-filters-and-controls">
                <div class="filter-group">
                    <label for="status-filter">Status:</label>
                    <select id="status-filter">
                        <option value="">Svi statusi</option>
                        <option value="payed_unassigned">Plaćene (Nedodijeljene)</option>
                        <option value="payed_assigned">Plaćene (Dodijeljene)</option>
                        <option value="completed">Završene</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="date-from-filter">Datum od:</label>
                    <input type="date" id="date-from-filter">
                </div>
                <div class="filter-group">
                    <label for="date-to-filter">Datum do:</label>
                    <input type="date" id="date-to-filter">
                </div>
                <div class="filter-group">
                    <label for="search-filter">Pretraga:</label>
                    <input type="text" id="search-filter" placeholder="Ime, telefon, adresa, ID...">
                </div>
                <div class="filter-actions">
                    <button id="apply-filters" class="button button-primary">Primijeni filtere</button>
                    <button id="clear-filters" class="button">Poništi filtere</button>
                    <button id="refresh-rides" class="button"><span class="dashicons dashicons-update"></span> Osvježi</button>
                </div>
                 <div class="filter-group per-page-select">
                    <label for="rides-per-page">Vožnji po stranici:</label>
                    <select id="rides-per-page">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <button id="export-rides-csv" class="button button-secondary"><span class="dashicons dashicons-download"></span> Izvezi CSV</button>
                <div id="auto-refresh-indicator" class="cityride-refresh-indicator" style="display:none;">
                    <span class="spinner is-active" style="float:none;"></span> Automatsko osvježavanje...
                </div>
            </div>

            <div class="cityride-table-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Polazište</th>
                            <th>Odredište</th>
                            <th>Udaljenost (km)</th>
                            <th>Cijena (BAM)</th>
                            <th>Status</th>
                            <th>Putnik</th>
                            <th>Telefon</th>
                            <th>Vozač</th>
                            <th>ETA</th>
                            <th>Kreirano</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="rides-table-body">
                        <tr><td colspan="12" style="text-align:center;">Učitavam vožnje...</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="rides-pagination" class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num pagination-info"></span>
                    <span class="pagination-links"></span>
                </div>
                <div class="alignleft actions">
                     <span class="displaying-num results-info" style="line-height: 2.1;"></span>
                </div>
            </div>

            <div class="cityride-modal" id="assign-driver-modal" style="display: none;">
                <span class="close">&times;</span>
                <h2>Dodijeli Vozača</h2>
                <form id="assign-driver-form">
                    <input type="hidden" id="assign-ride-id" name="ride_id">
                    <label for="driver-vehicle-number">Broj vozila:</label>
                    <input type="text" id="driver-vehicle-number" name="cab_driver_id" placeholder="Unesite broj vozila" required>

                    <label for="eta">ETA (Procijenjeno vrijeme dolaska):</label>
                    <input type="text" id="eta" name="eta" placeholder="Npr. 5 minuta" required>

                    <div id="modal-message"></div>
                    <div class="form-actions">
                        <button type="submit" class="button button-primary">Dodijeli</button>
                        <button type="button" class="button button-secondary cancel-modal">Poništi</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Admin configuration page callback.
     */
    public function admin_config_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemate dovoljna prava za pristup ovoj stranici.'));
        }
        ?>
        <div class="wrap cityride-admin-config">
            <h1>CityRide - Konfiguracija</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('cityride_settings');
                do_settings_sections('cityride_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Stripe Public Key</th>
                        <td><input type="text" name="cityride_stripe_public_key" value="<?php echo esc_attr(get_option('cityride_stripe_public_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Secret Key</th>
                        <td><input type="text" name="cityride_stripe_secret_key" value="<?php echo esc_attr(get_option('cityride_stripe_secret_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Webhook Secret</th>
                        <td><input type="text" name="cityride_stripe_webhook_secret" value="<?php echo esc_attr(get_option('cityride_stripe_webhook_secret')); ?>" class="regular-text" /><p class="description">Postavite Stripe webhook na <code><?php echo esc_url(get_rest_url(null, 'cityride/v1/stripe-webhook')); ?></code> i unesite Secret ovdje.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mapbox API Key</th>
                        <td><input type="text" name="cityride_mapbox_api_key" value="<?php echo esc_attr(get_option('cityride_mapbox_api_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">OneSignal App ID</th>
                        <td><input type="text" name="cityride_onesignal_app_id" value="<?php echo esc_attr(get_option('cityride_onesignal_app_id')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">OneSignal REST API Key</th>
                        <td><input type="text" name="cityride_onesignal_api_key" value="<?php echo esc_attr(get_option('cityride_onesignal_api_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Make.com Webhook URL</th>
                        <td><input type="url" name="cityride_make_webhook_url" value="<?php echo esc_attr(get_option('cityride_make_webhook_url')); ?>" class="regular-text" /><p class="description">URL za slanje podataka o novim vožnjama na Make.com.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Startna tarifa (BAM)</th>
                        <td><input type="number" step="0.01" name="cityride_start_tariff" value="<?php echo esc_attr(get_option('cityride_start_tariff')); ?>" class="small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Cijena po km (BAM)</th>
                        <td><input type="number" step="0.01" name="cityride_price_per_km" value="<?php echo esc_attr(get_option('cityride_price_per_km')); ?>" class="small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Omogući Push Notifikacije (OneSignal)</th>
                        <td>
                            <input type="checkbox" name="cityride_enable_push_notifications" value="1" <?php checked('1', get_option('cityride_enable_push_notifications')); ?> />
                            <p class="description">Ukoliko je omogućeno, šalju se push notifikacije putnicima (ako imaju OneSignal ID).</p>
                        </td>
                    </tr>
                     <tr valign="top">
                        <th scope="row">Omogući Webhook Notifikacije (Make.com)</th>
                        <td>
                            <input type="checkbox" name="cityride_enable_webhook_notifications" value="1" <?php checked('1', get_option('cityride_enable_webhook_notifications')); ?> />
                            <p class="description">Ukoliko je omogućeno, šalju se podaci o novim vožnjama na Make.com webhook URL.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Testiraj Webhook</th>
                        <td>
                            <button type="button" id="test-webhook-btn" class="button button-secondary">Pošalji testni webhook</button>
                            <p class="description">Šalje testni podatak na configured Make.com Webhook URL.</p>
                            <div id="test-webhook-message" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Retrieves ride data and statistics for the admin panel.
     * This function is now the primary AJAX handler for displaying rides.
     */
    public function ajax_load_rides() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za pristup.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Get pagination parameters
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25; // Default to 25
        $offset = ($page - 1) * $per_page;

        // Get filter parameters
        $status = sanitize_text_field($_POST['status'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        // Build WHERE clause
        $where_clauses = ['1=1'];
        $query_args = [];

        if (!empty($status)) {
            $where_clauses[] = 'status = %s';
            $query_args[] = $status;
        }
        if (!empty($date_from)) {
            $where_clauses[] = 'DATE(created_at) >= %s';
            $query_args[] = $date_from;
        }
        if (!empty($date_to)) {
            $where_clauses[] = 'DATE(created_at) <= %s';
            $query_args[] = $date_to;
        }
        if (!empty($search)) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(passenger_name LIKE %s OR passenger_phone LIKE %s OR address_from LIKE %s OR address_to LIKE %s OR cab_driver_id LIKE %s OR id = %d)';
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = intval($search); // Allow searching by ID as number
        }

        $where_sql = count($where_clauses) > 1 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Get total number of rides for pagination
        $total_rides_query = $wpdb->prepare("SELECT COUNT(id) FROM $table_name $where_sql", ...$query_args);
        $total_rides = $wpdb->get_var($total_rides_query);
        $total_pages = ceil($total_rides / $per_page);

        // Get rides for the current page
        $final_query_args = $query_args;
        $final_query_args[] = $per_page;
        $final_query_args[] = $offset;

        $rides_query = $wpdb->prepare(
            "SELECT * FROM $table_name $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$final_query_args
        );
        $rides = $wpdb->get_results($rides_query);

        // Generate HTML for rides table
        $rides_html = '';
        if ($rides) {
            foreach ($rides as $ride) {
                $status_class = '';
                $action_buttons = '';
                switch ($ride->status) {
                    case 'payed_unassigned':
                        $status_class = 'status-unassigned';
                        $action_buttons = '<button class="button assign-driver-btn" data-ride-id="' . esc_attr($ride->id) . '">Dodijeli</button>';
                        break;
                    case 'payed_assigned':
                        $status_class = 'status-assigned';
                        $action_buttons = '<button class="button button-primary complete-ride-btn" data-ride-id="' . esc_attr($ride->id) . '">Završi</button>';
                        break;
                    case 'completed':
                        $status_class = 'status-completed';
                        $action_buttons = ''; // No actions for completed rides
                        break;
                }

                $rides_html .= '<tr>';
                $rides_html .= '<td data-label="ID">' . esc_html($ride->id) . '</td>';
                $rides_html .= '<td data-label="Polazište">' . esc_html($ride->address_from) . '</td>';
                $rides_html .= '<td data-label="Odredište">' . esc_html($ride->address_to) . '</td>';
                $rides_html .= '<td data-label="Udaljenost (km)">' . esc_html(number_format($ride->distance_km, 2)) . '</td>';
                $rides_html .= '<td data-label="Cijena (BAM)">' . esc_html(number_format($ride->total_price, 2)) . '</td>';
                $rides_html .= '<td data-label="Status" class="status ' . esc_attr($status_class) . '">' . esc_html($this->get_status_label($ride->status)) . '</td>';
                $rides_html .= '<td data-label="Putnik">' . esc_html($ride->passenger_name) . '</td>';
                $rides_html .= '<td data-label="Telefon">' . esc_html($ride->passenger_phone) . '</td>';
                $rides_html .= '<td data-label="Vozač">' . (empty($ride->cab_driver_id) ? 'Nije dodijeljen' : esc_html($ride->cab_driver_id)) . '</td>';
                $rides_html .= '<td data-label="ETA">' . (empty($ride->eta) ? 'N/A' : esc_html($ride->eta)) . '</td>';
                $rides_html .= '<td data-label="Kreirano">' . esc_html(date('d.m.Y H:i', strtotime($ride->created_at))) . '</td>';
                $rides_html .= '<td data-label="Akcije">' . $action_buttons . '</td>';
                $rides_html .= '</tr>';
            }
        } else {
            $rides_html = '<tr><td colspan="12" style="text-align:center; padding: 40px;">Nema pronađenih vožnji.</td></tr>';
        }

        // Generate pagination links
        $pagination_html = '';
        if ($total_pages > 1) {
            $pagination_html .= '<a class="first-page button' . ($page == 1 ? ' disabled' : '') . '" href="#" data-page="1"><span class="screen-reader-text">Prva stranica</span><span aria-hidden="true">&laquo;</span></a>';
            $pagination_html .= '<a class="prev-page button' . ($page == 1 ? ' disabled' : '') . '" href="#" data-page="' . max(1, $page - 1) . '"><span class="screen-reader-text">Prethodna stranica</span><span aria-hidden="true">&lsaquo;</span></a>';
            $pagination_html .= '<span class="paging-input"><label for="current-page-selector" class="screen-reader-text">Trenutna stranica</label><input class="current-page" id="current-page-selector" type="text" value="' . esc_attr($page) . '" size="' . strlen($total_pages) . '" aria-describedby="table-paging"> od <span class="total-pages">' . esc_html($total_pages) . '</span></span>';
            $pagination_html .= '<a class="next-page button' . ($page == $total_pages ? ' disabled' : '') . '" href="#" data-page="' . min($total_pages, $page + 1) . '"><span class="screen-reader-text">Sljedeća stranica</span><span aria-hidden="true">&rsaquo;</span></a>';
            $pagination_html .= '<a class="last-page button' . ($page == $total_pages ? ' disabled' : '') . '" href="#" data-page="' . esc_attr($total_pages) . '"><span class="screen-reader-text">Posljednja stranica</span><span aria-hidden="true">&raquo;</span></a>';
        }

        // Get statistics
        $stats = $this->get_ride_statistics();

        wp_send_json_success([
            'rides_html' => $rides_html,
            'pagination_html' => $pagination_html,
            'total_rides' => $total_rides,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'stats' => $stats // Include statistics in the response
        ]);
    }

    /**
     * Helper function to get status label for display.
     */
    private function get_status_label($status) {
        switch ($status) {
            case 'payed_unassigned':
                return 'Plaćena (Nedodijeljena)';
            case 'payed_assigned':
                return 'Plaćena (Dodijeljena)';
            case 'completed':
                return 'Završena';
            default:
                return ucfirst(str_replace('_', ' ', $status));
        }
    }

    /**
     * Retrieves ride statistics.
     * This function is now called internally by ajax_load_rides.
     */
    private function get_ride_statistics() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        $stats = array(
            'today_rides' => 0,
            'this_month_rides' => 0,
            'monthly_revenue' => 0.00,
            'pending_rides' => 0,
            'assigned_rides' => 0
        );

        // Get current time in WordPress timezone (adjusted for site's timezone)
        // This is generally better for "today" and "this month" calculations relative to the user
        $current_wp_time = current_time('mysql'); // Returns YYYY-MM-DD HH:MM:SS in WP timezone
        $current_date_only = date('Y-m-d', strtotime($current_wp_time));
        $current_month_start = date('Y-m-01 00:00:00', strtotime($current_wp_time));

        // Vožnje danas
        $today_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(id) FROM $table_name WHERE DATE(created_at) = %s",
                $current_date_only
            )
        );
        $stats['today_rides'] = intval($today_count ?: 0);

        // Vožnje ovaj mjesec
        $month_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(id) FROM $table_name WHERE created_at >= %s",
                $current_month_start
            )
        );
        $stats['this_month_rides'] = intval($month_count ?: 0);

        // Monthly revenue
        $monthly_revenue_from_db = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(total_price), 0) FROM $table_name
                 WHERE status IN ('completed', 'payed_unassigned', 'payed_assigned')
                 AND created_at >= %s",
                $current_month_start
            )
        );
        $stats['monthly_revenue'] = floatval($monthly_revenue_from_db);

        // Pending rides
        $pending_count = $wpdb->get_var(
            "SELECT COUNT(id) FROM $table_name WHERE status = 'payed_unassigned'"
        );
        $stats['pending_rides'] = intval($pending_count ?: 0);

        // Assigned rides
        $assigned_count = $wpdb->get_var(
            "SELECT COUNT(id) FROM $table_name WHERE status = 'payed_assigned'"
        );
        $stats['assigned_rides'] = intval($assigned_count ?: 0);

        // Debug log (ukloniti u produkciji)
        error_log('CityRide Stats (after fix): ' . json_encode($stats));

        return $stats;
    }

 /**
     * AJAX handler for assigning a driver to a ride.
     * Changes status from 'payed_unassigned' to 'payed_assigned'.
     * Sends OneSignal notification to passenger upon successful assignment.
     */
    public function ajax_assign_driver() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za dodjelu vozača.');
        }

        $ride_id = intval($_POST['ride_id']); // Ovo je ID vožnje iz baze podataka
        $cab_driver_id = sanitize_text_field($_POST['cab_driver_id']); // Ovo je ono što se prikazuje kao 'broj taksija'
        $eta = sanitize_text_field($_POST['eta']);

        if (empty($ride_id) || empty($cab_driver_id) || empty($eta)) {
            wp_send_json_error('Svi podaci su obavezni (ID vožnje, broj vozila, ETA).');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Dohvati trenutni status vožnje i passenger_onesignal_id PRIJE ažuriranja
        $ride_data = $wpdb->get_row($wpdb->prepare("SELECT status, passenger_onesignal_id FROM $table_name WHERE id = %d", $ride_id));

        $current_status = $ride_data ? $ride_data->status : null;
        $passenger_onesignal_id = $ride_data ? $ride_data->passenger_onesignal_id : ''; // Dohvati Player ID

        $updated = $wpdb->update(
            $table_name,
            array(
                'cab_driver_id' => $cab_driver_id,
                'eta' => $eta,
                'status' => 'payed_assigned', // Postavi novi status
                'updated_at' => current_time('mysql')
            ),
            array('id' => $ride_id),
            array('%s', '%s', '%s', '%s'), // Formati za cab_driver_id, eta, status, updated_at
            array('%d') // Format za WHERE id
        );

        if ($updated === false) {
            error_log('CityRide: Database update failed in ajax_assign_driver: ' . $wpdb->last_error);
            wp_send_json_error('Greška pri dodjeli vozača.');
        } else {
            // KLJUČNO: Provjeri da li je status prešao iz 'payed_unassigned' u 'payed_assigned'
            // i pošalji notifikaciju SAMO ako je došlo do promjene i ako je prethodni status bio 'payed_unassigned'
            if ($current_status === 'payed_unassigned') {

                // Provjeri da li su push notifikacije omogućene u opcijama i da li imamo Player ID
                $enable_push_notifications = get_option('cityride_enable_push_notifications', '0'); // Pretpostavljamo da imate ovu opciju

                if ($enable_push_notifications === '1' && !empty($passenger_onesignal_id)) {
                    $notification_title = 'Vaša vožnja je dodijeljena!';
                    // Format poruke: Taksi #ID_VOZILA je na putu! Procijenjeno vrijeme dolaska: X minuta.
                    // Koristimo $cab_driver_id kao broj taksija u poruci
                    $notification_message = sprintf('Taksi #%s je na putu! Procijenjeno vrijeme dolaska: %s minuta.',
                        $cab_driver_id, // Koristi se kao prikazani broj taksija
                        $eta
                    );

                    // Pozivamo send_onesignal_notification metodu
                    // Očekuje $player_ids (niz), $title, $message, $url (opcionalno)
                    $this->send_onesignal_notification(
                        [$passenger_onesignal_id], // Player ID ide kao niz
                        $notification_title,
                        $notification_message,
                        '' // Opcionalno, dodajte URL ako imate stranicu za praćenje
                    );

                    error_log("CityRide: OneSignal notification sent for ride {$ride_id} (Cab: {$cab_driver_id}, ETA: {$eta}) to {$passenger_onesignal_id}");
                } else {
                    error_log("CityRide: OneSignal notification not sent for ride {$ride_id}. Either disabled ({$enable_push_notifications}), or no Player ID ({$passenger_onesignal_id}).");
                }
            } else {
                 error_log("CityRide: No notification sent for ride {$ride_id}. Status was not 'payed_unassigned' or update didn't involve status change from 'payed_unassigned'. Current status: {$current_status}");
            }

            wp_send_json_success('Vozač uspješno dodijeljen.');
        }
    }

    /**
     * AJAX handler for completing a ride.
     */
    public function ajax_complete_ride() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za završetak vožnje.');
        }

        $ride_id = intval($_POST['ride_id']);

        if (empty($ride_id)) {
            wp_send_json_error('ID vožnje je obavezan.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        $updated = $wpdb->update(
            $table_name,
            array(
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $ride_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            error_log('CityRide: Database update failed in ajax_complete_ride: ' . $wpdb->last_error);
            wp_send_json_error('Greška pri završetku vožnje.');
        } else {
            // Fetch the updated ride to get passenger_onesignal_id
            $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ride_id));
            if ($ride && get_option('cityride_enable_push_notifications') === '1' && !empty($ride->passenger_onesignal_id)) {
                $message = "Vaša vožnja ({$ride->address_from} do {$ride->address_to}) je uspješno završena! Hvala Vam na korištenju CityRide-a.";
                $this->send_onesignal_notification([$ride->passenger_onesignal_id], 'Vožnja završena!', $message); // Fixed to pass array
            }
            wp_send_json_success('Vožnja uspješno završena.');
        }
    }

    /**
     * AJAX handler for exporting rides to CSV.
     */
    public function ajax_export_rides() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za izvoz podataka.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Get filter parameters from POST for consistency with ajax_load_rides
        $status = sanitize_text_field($_POST['status'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        // Build WHERE clause
        $where_clauses = ['1=1'];
        $query_args = [];

        if (!empty($status)) {
            $where_clauses[] = 'status = %s';
            $query_args[] = $status;
        }
        if (!empty($date_from)) {
            $where_clauses[] = 'DATE(created_at) >= %s';
            $query_args[] = $date_from;
        }
        if (!empty($date_to)) {
            $where_clauses[] = 'DATE(created_at) <= %s';
            $query_args[] = $date_to;
        }
        if (!empty($search)) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(passenger_name LIKE %s OR passenger_phone LIKE %s OR address_from LIKE %s OR address_to LIKE %s OR cab_driver_id LIKE %s OR id = %d)';
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = $search_like;
            $query_args[] = intval($search);
        }

        $where_sql = count($where_clauses) > 1 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Fetch all rides matching the filters
        $rides_query = $wpdb->prepare(
            "SELECT id, passenger_name, passenger_phone, passenger_email, address_from, address_to, distance_km, total_price, status, cab_driver_id, eta, stripe_payment_id, created_at, updated_at FROM $table_name $where_sql ORDER BY created_at DESC",
            ...$query_args
        );
        $rides = $wpdb->get_results($rides_query, ARRAY_A);

        if (empty($rides)) {
            wp_send_json_error('Nema podataka za izvoz s odabranim filterima.');
        }
        error_log('CityRide CSV Export Data: ' . print_r($rides, true));
        $csv_data = $this->generate_csv($rides);

        wp_send_json_success([
            'filename' => 'cityride_rides_' . date('Ymd_His') . '.csv',
            'data' => base64_encode($csv_data), // Base64 encode for safe AJAX transfer
            'mime_type' => 'text/csv'
        ]);
    }

    /**
     * Generates CSV content from an array of data.
     *
     * @param array $data The data to export.
     * @return string The CSV content.
     */
    private function generate_csv($data) {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Add UTF-8 BOM for proper character display in Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Get headers from the first row keys
        $headers = array_keys($data[0]);
        fputcsv($output, $headers);

        foreach ($data as $row) {
            // Konvertuj sve NULL vrijednosti u prazne stringove, i osiguraj da su sve vrijednosti stringovi
            $cleaned_row = array_map(function($value) {
                return is_null($value) ? '' : (string) $value;
            }, $row);
            fputcsv($output, $cleaned_row);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);

        return $csv_content;
    }

    /**
     * Registers the REST API endpoint for Stripe Webhooks.
     */
    public function register_stripe_webhook_endpoint() {
        register_rest_route('cityride/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_stripe_webhook'),
            'permission_callback' => '__return_true', // Stripe webhooks don't use WP nonces/auth
        ));
    }

    /**
     * Handles incoming Stripe webhook events.
     */
    public function handle_stripe_webhook(WP_REST_Request $request) {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $webhook_secret = get_option('cityride_stripe_webhook_secret');

        if (empty($webhook_secret)) {
            error_log('CityRide Stripe Webhook: Webhook secret not configured.');
            return new WP_REST_Response('Webhook Secret not configured.', 403);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $webhook_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            error_log('CityRide Stripe Webhook Error: Invalid payload: ' . $e->getMessage());
            return new WP_REST_Response('Invalid payload.', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            error_log('CityRide Stripe Webhook Error: Invalid signature: ' . $e->getMessage());
            return new WP_REST_Response('Invalid signature.', 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                error_log('CityRide Stripe Webhook: PaymentIntent ' . $paymentIntent->id . ' succeeded!');
                // You could perform actions here if needed, but ajax_save_booking handles this on frontend success.
                // This webhook primarily serves as a fallback or for internal consistency checks.
                $this->process_successful_payment_webhook($paymentIntent);
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                error_log('CityRide Stripe Webhook: PaymentIntent ' . $paymentIntent->id . ' failed! Reason: ' . ($paymentIntent->last_payment_error->message ?? 'N/A'));
                // Handle failed payments (e.g., notify admin, log, etc.)
                break;
            // ... handle other event types
            default:
                // Unhandled event type
                error_log('CityRide Stripe Webhook: Received unhandled event type ' . $event->type);
        }

        return new WP_REST_Response('OK', 200);
    }

    /**
     * Processes a successful payment intent received via webhook.
     * This is a fallback/redundancy for cases where the frontend `ajax_save_booking` might fail.
     *
     * @param object $paymentIntent The Stripe PaymentIntent object.
     */
    private function process_successful_payment_webhook($paymentIntent) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        $payment_intent_id = $paymentIntent->id;
        $amount = $paymentIntent->amount / 100; // Convert from cents to BAM
        $currency = $paymentIntent->currency;

        $metadata = $paymentIntent->metadata;

        // Check if the ride already exists based on payment intent ID
        $existing_ride = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM $table_name WHERE stripe_payment_id = %s", $payment_intent_id));

        if ($existing_ride) {
            // If it exists and is not 'payed_unassigned' (e.g., already assigned or completed), do nothing.
            // If it's already 'payed_unassigned', ensure consistency.
            if ($existing_ride->status === 'payed_unassigned') {
                error_log("CityRide Webhook: Ride {$existing_ride->id} for PaymentIntent {$payment_intent_id} already exists and is 'payed_unassigned'. No action needed.");
            } else {
                error_log("CityRide Webhook: Ride {$existing_ride->id} for PaymentIntent {$payment_intent_id} already exists with status '{$existing_ride->status}'. No action needed.");
            }
            return;
        }

        // If the ride does not exist, insert it.
        $result = $wpdb->insert(
            $table_name,
            array(
                'passenger_name' => $metadata->passenger_name ?? 'N/A',
                'passenger_phone' => $metadata->passenger_phone ?? 'N/A',
                'address_from' => $metadata->address_from ?? 'N/A',
                'address_to' => $metadata->address_to ?? 'N/A',
                'distance_km' => $metadata->distance_km ?? 0.00,
                'total_price' => $amount,
                'stripe_payment_id' => $payment_intent_id,
                'passenger_email' => $metadata->passenger_email ?? '',
                'passenger_onesignal_id' => $metadata->passenger_onesignal_id ?? '', // OneSignal ID from metadata
                'status' => 'payed_unassigned',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array(
                '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );

        if ($result === false) {
            error_log('CityRide Webhook: Database insert failed for PaymentIntent ' . $payment_intent_id . ': ' . $wpdb->last_error);
        } else {
            $booking_id = $wpdb->insert_id;
            error_log('CityRide Webhook: Successfully created booking ' . $booking_id . ' from PaymentIntent ' . $payment_intent_id);
            if (get_option('cityride_enable_webhook_notifications') === '1') {
                $this->send_webhook_notification($booking_id);
            }
        }
    }

    /**
     * Sends a push notification using OneSignal REST API.
     *
     * @param string|array $player_ids Single player ID or array of player IDs.
     * @param string $heading The title of the notification.
     * @param string $message The body of the notification.
     * @param string $url URL to open when notification is clicked (optional).
     */
    private function send_onesignal_notification($player_ids, $heading, $message, $url = '') {
        $app_id = get_option('cityride_onesignal_app_id');
        $api_key = get_option('cityride_onesignal_api_key');

        if (empty($app_id) || empty($api_key) || get_option('cityride_enable_push_notifications') !== '1') {
            error_log('OneSignal not fully configured or disabled.');
            return;
        }

        if (empty($player_ids)) {
            error_log('No OneSignal Player IDs provided for notification.');
            return;
        }

        $fields = array(
            'app_id' => $app_id,
            'contents' => array('en' => $message, 'bs' => $message), // Assuming Bosnian and English are primary languages
            'headings' => array('en' => $heading, 'bs' => $heading),
            'chrome_web_icon' => CITYRIDE_PLUGIN_URL . 'assets/images/cityride-logo-192x192.png', // Optional icon
            'chrome_big_picture' => CITYRIDE_PLUGIN_URL . 'assets/images/cityride-logo-512x512.png', // Optional large image
            'web_push_topic' => 'cityride_notifications', // Group notifications
        );

        if (is_array($player_ids)) {
            $fields['include_player_ids'] = $player_ids;
        } else {
            $fields['include_player_ids'] = [$player_ids];
        }

        if (!empty($url)) {
            $fields['url'] = $url;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Basic ' . $api_key
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, defined('WP_DEBUG') && WP_DEBUG ? false : true); // Adjust for dev/prod

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === FALSE) {
            error_log('OneSignal cURL Error: ' . $curl_error);
        } else {
            $response_data = json_decode($response, true);
            if ($http_code != 200 || (isset($response_data['errors']) && !empty($response_data['errors']))) {
                error_log('OneSignal API Error (HTTP ' . $http_code . '): ' . $response);
            } else {
                error_log('OneSignal notification sent successfully. Response: ' . $response);
            }
        }
    }
} // <-- Ovo je zatvarajuća zagrada za klasu CityRideBooking

new CityRideBooking();