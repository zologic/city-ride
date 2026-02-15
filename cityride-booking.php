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

        // Run performance index migration if needed
        $this->migrate_performance_indexes();
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

        add_action('wp_ajax_cityride_validate_discount_code', array($this, 'ajax_validate_discount_code'));
        add_action('wp_ajax_nopriv_cityride_validate_discount_code', array($this, 'ajax_validate_discount_code'));

        // AJAX hooks for admin (requires authentication)
        add_action('wp_ajax_cityride_load_rides', array($this, 'ajax_load_rides'));
        add_action('wp_ajax_cityride_assign_driver', array($this, 'ajax_assign_driver'));
        add_action('wp_ajax_cityride_complete_ride', array($this, 'ajax_complete_ride'));
        add_action('wp_ajax_cityride_cancel_ride', array($this, 'ajax_cancel_ride'));
        add_action('wp_ajax_cityride_export_rides', array($this, 'ajax_export_rides')); // Returns CSV data via AJAX
        add_action('wp_ajax_cityride_test_webhook', array($this, 'ajax_test_webhook'));

        // AJAX hooks for driver management
        add_action('wp_ajax_cityride_load_drivers', array($this, 'ajax_load_drivers'));
        add_action('wp_ajax_cityride_get_driver', array($this, 'ajax_get_driver'));
        add_action('wp_ajax_cityride_save_driver', array($this, 'ajax_save_driver'));
        add_action('wp_ajax_cityride_delete_driver', array($this, 'ajax_delete_driver'));
        add_action('wp_ajax_cityride_get_active_drivers', array($this, 'ajax_get_active_drivers'));
        add_action('wp_ajax_cityride_export_driver_earnings', array($this, 'ajax_export_driver_earnings'));

        // AJAX hooks for analytics
        add_action('wp_ajax_cityride_get_analytics_data', array($this, 'ajax_get_analytics_data'));
        add_action('wp_ajax_cityride_export_analytics_excel', array($this, 'ajax_export_analytics_excel'));

        // AJAX hooks for discount code management
        add_action('wp_ajax_cityride_load_discount_codes', array($this, 'ajax_load_discount_codes'));
        add_action('wp_ajax_cityride_get_discount_code', array($this, 'ajax_get_discount_code'));
        add_action('wp_ajax_cityride_save_discount_code', array($this, 'ajax_save_discount_code'));
        add_action('wp_ajax_cityride_delete_discount_code', array($this, 'ajax_delete_discount_code'));
        add_action('wp_ajax_cityride_toggle_discount_code', array($this, 'ajax_toggle_discount_code'));

        // REST API Endpoints for Webhooks
        add_action( 'rest_api_init', array($this, 'register_stripe_webhook_endpoint'));
        add_action( 'rest_api_init', array($this, 'register_infobip_webhook_endpoint'));
        add_action( 'rest_api_init', array($this, 'register_infobip_message_id_endpoint'));

        // Register webhook retry cron action
        add_action('cityride_retry_webhook', array($this, 'cron_retry_webhook'), 10, 3);
    }

    /**
     * Plugin activation hook. Creates the database table and sets default options.
     */
    public function activate() {
        $this->create_database_table();

        // Run performance index migration
        $this->migrate_performance_indexes();

        // Add default options if they don't exist
        add_option('cityride_stripe_public_key', '');
        add_option('cityride_stripe_secret_key', '');
        add_option('cityride_mapbox_api_key', '');
        add_option('cityride_onesignal_app_id', '');
        add_option('cityride_onesignal_api_key', '');
        add_option('cityride_make_webhook_url', '');
        add_option('cityride_webhook_secret', '');
        add_option('cityride_start_tariff', '2.50');
        add_option('cityride_price_per_km', '1.50');
        add_option('cityride_enable_push_notifications', '1');
        add_option('cityride_enable_webhook_notifications', '1');
        add_option('cityride_stripe_webhook_secret', '');

        // Pricing and surcharge settings
        add_option('cityride_minimum_fare', '5.00');
        add_option('cityride_night_surcharge_enabled', '1');
        add_option('cityride_night_surcharge_percent', '20');
        add_option('cityride_night_start_time', '22:00');
        add_option('cityride_night_end_time', '06:00');
        add_option('cityride_weekend_surcharge_enabled', '1');
        add_option('cityride_weekend_surcharge_percent', '15');
        add_option('cityride_holiday_surcharge_enabled', '1');
        add_option('cityride_holiday_surcharge_percent', '25');
        add_option('cityride_holiday_dates', ''); // Comma-separated dates (YYYY-MM-DD)
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

        // Apply surcharges and minimum fare
        $pricing_details = $this->calculate_enhanced_price($total_price, $distance_km);

        wp_send_json_success([
            'distance_km' => $distance_km,
            'base_price' => $total_price,
            'total_price' => $pricing_details['final_price'],
            'stripe_amount' => $pricing_details['stripe_amount'],
            'start_tariff' => $start_tariff,
            'price_per_km' => $price_per_km,
            'minimum_fare' => $pricing_details['minimum_fare'],
            'surcharges' => $pricing_details['surcharges'],
            'surcharge_total' => $pricing_details['surcharge_total'],
            'route_geometry' => $route_geometry,
            'from_coords' => $from_coords,
            'to_coords' => $to_coords
        ]);
    }

    /**
     * Calculate enhanced price with surcharges and minimum fare
     *
     * @param float $base_price Base calculated price (start tariff + km price)
     * @param float $distance_km Distance in kilometers
     * @param string|null $booking_datetime Optional booking datetime (defaults to current time)
     * @return array Pricing details with surcharges and final price
     */
    private function calculate_enhanced_price($base_price, $distance_km, $booking_datetime = null) {
        // Use provided datetime or current time
        $datetime = $booking_datetime ? new DateTime($booking_datetime) : new DateTime('now', new DateTimeZone('Europe/Sarajevo'));

        $minimum_fare = floatval(get_option('cityride_minimum_fare', '5.00'));
        $surcharges = [];
        $surcharge_total = 0;

        // Apply minimum fare
        $calculated_price = max($base_price, $minimum_fare);

        // Check for night surcharge
        if (get_option('cityride_night_surcharge_enabled') === '1') {
            $night_percent = floatval(get_option('cityride_night_surcharge_percent', '20'));
            $night_start = get_option('cityride_night_start_time', '22:00');
            $night_end = get_option('cityride_night_end_time', '06:00');

            if ($this->is_night_time($datetime, $night_start, $night_end)) {
                $night_surcharge = ($calculated_price * $night_percent) / 100;
                $surcharges[] = [
                    'type' => 'night',
                    'label' => 'Noćni dodatak',
                    'percent' => $night_percent,
                    'amount' => round($night_surcharge, 2)
                ];
                $surcharge_total += $night_surcharge;
            }
        }

        // Check for weekend surcharge
        if (get_option('cityride_weekend_surcharge_enabled') === '1') {
            $weekend_percent = floatval(get_option('cityride_weekend_surcharge_percent', '15'));
            $day_of_week = $datetime->format('N'); // 1 (Mon) through 7 (Sun)

            if ($day_of_week >= 6) { // Saturday (6) or Sunday (7)
                $weekend_surcharge = ($calculated_price * $weekend_percent) / 100;
                $surcharges[] = [
                    'type' => 'weekend',
                    'label' => 'Vikend dodatak',
                    'percent' => $weekend_percent,
                    'amount' => round($weekend_surcharge, 2)
                ];
                $surcharge_total += $weekend_surcharge;
            }
        }

        // Check for holiday surcharge
        if (get_option('cityride_holiday_surcharge_enabled') === '1') {
            $holiday_percent = floatval(get_option('cityride_holiday_surcharge_percent', '25'));
            $holiday_dates_str = get_option('cityride_holiday_dates', '');

            if (!empty($holiday_dates_str)) {
                $holiday_dates = array_map('trim', explode(',', $holiday_dates_str));
                $current_date = $datetime->format('Y-m-d');

                if (in_array($current_date, $holiday_dates)) {
                    $holiday_surcharge = ($calculated_price * $holiday_percent) / 100;
                    $surcharges[] = [
                        'type' => 'holiday',
                        'label' => 'Dodatak za praznik',
                        'percent' => $holiday_percent,
                        'amount' => round($holiday_surcharge, 2)
                    ];
                    $surcharge_total += $holiday_surcharge;
                }
            }
        }

        $final_price = round($calculated_price + $surcharge_total, 2);
        $stripe_amount = round($final_price * 100);

        return [
            'base_price' => round($base_price, 2),
            'minimum_fare' => $minimum_fare,
            'calculated_price' => round($calculated_price, 2), // After minimum fare
            'surcharges' => $surcharges,
            'surcharge_total' => round($surcharge_total, 2),
            'final_price' => $final_price,
            'stripe_amount' => $stripe_amount
        ];
    }

    /**
     * Check if the given datetime falls within night hours
     *
     * @param DateTime $datetime The datetime to check
     * @param string $start_time Start time (HH:MM format)
     * @param string $end_time End time (HH:MM format)
     * @return bool True if within night hours
     */
    private function is_night_time($datetime, $start_time, $end_time) {
        $current_time = $datetime->format('H:i');

        // Handle overnight periods (e.g., 22:00 - 06:00)
        if ($start_time > $end_time) {
            return $current_time >= $start_time || $current_time < $end_time;
        }

        // Handle same-day periods (e.g., 01:00 - 05:00)
        return $current_time >= $start_time && $current_time < $end_time;
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
     * AJAX handler for validating discount codes
     * Checks code validity, usage limits, and calculates discount
     */
    public function ajax_validate_discount_code() {
        check_ajax_referer('cityride_frontend_nonce', 'nonce');

        $code = strtoupper(trim(sanitize_text_field($_POST['code'])));
        $order_amount = floatval($_POST['order_amount']);

        if (empty($code)) {
            wp_send_json_error('Molimo unesite kod popusta.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        // Fetch discount code
        $discount = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE code = %s AND is_active = 1",
            $code
        ));

        if (!$discount) {
            wp_send_json_error('Nepoznat kod popusta ili je istekao.');
        }

        // Check validity dates
        $now = current_time('mysql');
        if ($discount->valid_from && $discount->valid_from > $now) {
            wp_send_json_error('Kod popusta još nije aktivan.');
        }

        if ($discount->valid_until && $discount->valid_until < $now) {
            wp_send_json_error('Kod popusta je istekao.');
        }

        // Check usage limit
        if ($discount->usage_limit && $discount->usage_count >= $discount->usage_limit) {
            wp_send_json_error('Kod popusta je dostigao limit korištenja.');
        }

        // Check minimum order amount
        if ($discount->min_order_amount > $order_amount) {
            wp_send_json_error(sprintf(
                'Minimalan iznos za ovaj kod je %.2f BAM.',
                $discount->min_order_amount
            ));
        }

        // Calculate discount amount
        $discount_amount = 0;
        if ($discount->discount_type === 'percent') {
            $discount_amount = ($order_amount * $discount->discount_value) / 100;

            // Apply max discount cap if set
            if ($discount->max_discount_amount && $discount_amount > $discount->max_discount_amount) {
                $discount_amount = $discount->max_discount_amount;
            }
        } else {
            // Fixed amount discount
            $discount_amount = min($discount->discount_value, $order_amount);
        }

        $discount_amount = round($discount_amount, 2);
        $final_price = max($order_amount - $discount_amount, 0);

        wp_send_json_success([
            'code' => $discount->code,
            'discount_type' => $discount->discount_type,
            'discount_value' => floatval($discount->discount_value),
            'discount_amount' => $discount_amount,
            'original_price' => $order_amount,
            'final_price' => round($final_price, 2),
            'stripe_amount' => round($final_price * 100),
            'message' => sprintf(
                'Kod popusta primijenjen! Ušteda: %.2f BAM',
                $discount_amount
            )
        ]);
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

        // Discount code information
        $discount_code = isset($_POST['discount_code']) ? strtoupper(trim(sanitize_text_field($_POST['discount_code']))) : '';
        $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
        $original_price = isset($_POST['original_price']) ? floatval($_POST['original_price']) : $total_price;
        $final_price = isset($_POST['final_price']) ? floatval($_POST['final_price']) : $total_price;

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
                'discount_code' => !empty($discount_code) ? $discount_code : null,
                'discount_amount' => $discount_amount,
                'original_price' => $discount_amount > 0 ? $original_price : null,
                'final_price' => $discount_amount > 0 ? $final_price : null,
                'stripe_payment_id' => $payment_intent_id,
                'passenger_email' => $passenger_email,
                'passenger_onesignal_id' => $passenger_onesignal_id, // Store player ID in DB
                'status' => 'payed_unassigned',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array(
                '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );

        if ($result === false) {
            error_log('CityRide: Database insert failed in ajax_save_booking: ' . $wpdb->last_error);
            wp_send_json_error('Greška pri spremanju rezervacije.');
        }

        $booking_id = $wpdb->insert_id;

        // Increment discount code usage count if discount was applied
        if (!empty($discount_code)) {
            $discount_table = $wpdb->prefix . 'cityride_discount_codes';
            $wpdb->query($wpdb->prepare(
                "UPDATE $discount_table SET usage_count = usage_count + 1 WHERE code = %s",
                $discount_code
            ));
            error_log('CityRide: Incremented usage count for discount code: ' . $discount_code);
        }

        // Log successful booking creation
        error_log('CityRide: Booking ' . $booking_id . ' successfully saved to database');

        // Clear dashboard and key metrics caches
        $this->clear_cache(array('stats_dashboard', 'stats_key_metrics'));

        // Check webhook notifications setting and send booking_confirmed event
        $webhook_enabled = get_option('cityride_enable_webhook_notifications');
        error_log('CityRide: Webhook notifications setting: ' . ($webhook_enabled === '1' ? 'enabled' : 'disabled'));

        if ($webhook_enabled === '1') {
            error_log('CityRide: Webhook notifications enabled, triggering booking_confirmed event for booking ' . $booking_id);

            // Prepare ride data for webhook
            $ride_data = array(
                'id' => $booking_id,
                'passenger_name' => $passenger_name,
                'passenger_phone' => $passenger_phone,
                'passenger_email' => $passenger_email,
                'address_from' => $address_from,
                'address_to' => $address_to,
                'distance_km' => $distance_km,
                'total_price' => $total_price,
            );

            // Send booking_confirmed webhook
            $this->send_event_webhook('booking_confirmed', $ride_data);
            error_log('CityRide: Webhook notification call completed for booking ' . $booking_id);
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
     * Sends a webhook notification to Make.com/n8n for new bookings.
     *
     * @param int $booking_id The ID of the newly created booking.
     */
    private function send_webhook_notification($booking_id) {
        // Log entry point
        error_log('CityRide: Triggering webhook notification for booking ID: ' . $booking_id);

        $webhook_url = get_option('cityride_make_webhook_url');

        if (empty($webhook_url)) {
            error_log('CityRide: Webhook URL not configured - skipping notification');
            return;
        }

        // Log webhook URL being used
        error_log('CityRide: Using webhook URL: ' . $webhook_url);

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $booking_id));

        if (!$ride) {
            error_log('CityRide: Ride not found in database for booking_id ' . $booking_id);
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

        // Log payload in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CityRide: Webhook payload: ' . json_encode($webhook_data));
        }

        // Determine if we should use blocking mode (debug) or non-blocking (production)
        $is_debug_mode = defined('WP_DEBUG') && WP_DEBUG;

        // Get webhook secret for authentication
        $webhook_secret = get_option('cityride_webhook_secret', '');

        // Build headers array
        $headers = array('Content-Type' => 'application/json');

        // Add authentication header if secret is configured
        if (!empty($webhook_secret)) {
            $headers['X-Webhook-Secret'] = $webhook_secret;
        }

        $request_args = array(
            'headers' => $headers,
            'body' => json_encode($webhook_data),
            'timeout' => 30,
            'blocking' => $is_debug_mode, // Blocking in debug mode, non-blocking in production
            'sslverify' => !$is_debug_mode // Disable SSL verify only in debug mode
        );

        $response = wp_remote_post($webhook_url, $request_args);

        // Only capture and log response in debug mode (when blocking is enabled)
        if ($is_debug_mode) {
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('CityRide: Webhook failed - Error: ' . $error_message);

                // Store webhook status in debug mode
                update_option('cityride_last_webhook_status', array(
                    'timestamp' => current_time('mysql'),
                    'booking_id' => $booking_id,
                    'status' => 'failed',
                    'error' => $error_message,
                    'http_code' => null
                ));
            } else {
                $response_code = wp_remote_retrieve_response_code($response);

                if ($response_code >= 200 && $response_code < 300) {
                    error_log('CityRide: Webhook delivered successfully - HTTP ' . $response_code);

                    // Store success status in debug mode
                    update_option('cityride_last_webhook_status', array(
                        'timestamp' => current_time('mysql'),
                        'booking_id' => $booking_id,
                        'status' => 'success',
                        'http_code' => $response_code,
                        'error' => null
                    ));
                } else {
                    error_log('CityRide: Webhook delivered but returned HTTP ' . $response_code . ' - Check n8n/Make.com workflow configuration');

                    // Store failure status in debug mode
                    update_option('cityride_last_webhook_status', array(
                        'timestamp' => current_time('mysql'),
                        'booking_id' => $booking_id,
                        'status' => 'failed',
                        'http_code' => $response_code,
                        'error' => 'HTTP ' . $response_code . ' response'
                    ));
                }
            }
        }

        error_log('CityRide: Webhook notification sent for booking ' . $booking_id);
    }

    /**
     * Enhanced webhook system - Sends event-based notifications to n8n/Make.com
     * Supports multiple event types: booking_confirmed, driver_assigned, ride_completed, ride_cancelled
     *
     * @param string $event_type The type of event (booking_confirmed, driver_assigned, ride_completed, ride_cancelled)
     * @param array $ride_data Array containing ride information
     */
    private function send_event_webhook($event_type, $ride_data) {
        $webhook_url = get_option('cityride_make_webhook_url');

        if (empty($webhook_url) || get_option('cityride_enable_webhook_notifications') !== '1') {
            error_log("CityRide: Webhook disabled or URL not configured for event: {$event_type}");
            return;
        }

        // Format phone number to international format (+387 for Bosnia)
        $phone = $ride_data['passenger_phone'];
        if (!empty($phone) && substr($phone, 0, 1) !== '+') {
            // Remove leading zero and add Bosnia country code
            $phone = '+387' . ltrim($phone, '0');
        }

        // Build base payload
        $payload = array(
            'event' => $event_type,
            'booking_id' => $ride_data['id'],
            'passenger_phone' => $phone,
            'passenger_name' => $ride_data['passenger_name'],
            'passenger_email' => $ride_data['passenger_email'] ?? '',
            'address_from' => $ride_data['address_from'],
            'address_to' => $ride_data['address_to'],
            'distance_km' => number_format($ride_data['distance_km'], 2),
            'total_price' => number_format($ride_data['total_price'], 2) . ' BAM',
            'timestamp' => current_time('c'), // ISO 8601 format
        );

        // Add event-specific fields
        switch ($event_type) {
            case 'driver_assigned':
                $payload['driver_name'] = $ride_data['driver_name'] ?? '';
                $payload['vehicle_number'] = $ride_data['cab_driver_id'] ?? '';
                $payload['eta'] = $ride_data['eta'] ?? '';
                break;

            case 'ride_cancelled':
                $payload['cancellation_reason'] = $ride_data['cancellation_reason'] ?? 'No reason provided';
                break;

            case 'ride_completed':
                $payload['driver_name'] = $ride_data['driver_name'] ?? '';
                $payload['vehicle_number'] = $ride_data['cab_driver_id'] ?? '';
                break;
        }

        // Add SMS template and message (for n8n to use)
        $sms_template_key = 'cityride_sms_' . $event_type;
        $sms_enabled_key = 'cityride_enable_sms_' . str_replace('ride_', '', str_replace('booking_', '', $event_type));

        $payload['sms_template'] = get_option($sms_template_key, '');
        $payload['sms_enabled'] = get_option($sms_enabled_key, 'yes') === 'yes';

        // Replace variables in SMS template
        if (!empty($payload['sms_template'])) {
            $payload['sms_message'] = $this->replace_sms_variables($payload['sms_template'], $payload);
        }

        // Send webhook with retry mechanism
        $this->send_webhook_with_retry($webhook_url, $payload, 1);
    }

    /**
     * Replaces variables in SMS template with actual values
     *
     * @param string $template The SMS template with variables
     * @param array $data The data to replace variables with
     * @return string The SMS message with replaced variables
     */
    private function replace_sms_variables($template, $data) {
        $replacements = array(
            '{passenger_name}' => $data['passenger_name'] ?? '',
            '{driver_name}' => $data['driver_name'] ?? '',
            '{vehicle_number}' => $data['vehicle_number'] ?? '',
            '{eta}' => $data['eta'] ?? '',
            '{total_price}' => $data['total_price'] ?? '',
            '{address_from}' => $data['address_from'] ?? '',
            '{address_to}' => $data['address_to'] ?? '',
            '{booking_id}' => $data['booking_id'] ?? '',
            '{cancellation_reason}' => $data['cancellation_reason'] ?? '',
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Sends webhook with retry mechanism (exponential backoff)
     *
     * @param string $url Webhook URL
     * @param array $payload Webhook payload
     * @param int $attempt Current attempt number (1-3)
     */
    private function send_webhook_with_retry($url, $payload, $attempt = 1) {
        $max_attempts = 3;
        $webhook_secret = get_option('cityride_webhook_secret', '');

        $headers = array(
            'Content-Type' => 'application/json',
            'X-Webhook-Attempt' => $attempt,
        );

        if (!empty($webhook_secret)) {
            $headers['X-Webhook-Secret'] = $webhook_secret;
        }

        $request_args = array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 10,
            'blocking' => true,
            'sslverify' => !(defined('WP_DEBUG') && WP_DEBUG),
        );

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("CityRide: Webhook delivery failed (attempt {$attempt}/{$max_attempts}): {$error_message}");

            // Schedule retry if under max attempts
            if ($attempt < $max_attempts) {
                $retry_delay = 60 * pow(2, $attempt - 1); // Exponential backoff: 60s, 120s, 240s
                wp_schedule_single_event(
                    time() + $retry_delay,
                    'cityride_retry_webhook',
                    array($url, $payload, $attempt + 1)
                );
                error_log("CityRide: Webhook retry scheduled in {$retry_delay} seconds");
            }

            // Store failed webhook for review
            $this->log_webhook_failure($payload, $error_message, $attempt);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code >= 200 && $response_code < 300) {
            error_log("CityRide: Webhook delivered successfully for event '{$payload['event']}' (booking #{$payload['booking_id']}) - HTTP {$response_code}");
            return true;
        } else {
            error_log("CityRide: Webhook returned HTTP {$response_code} (attempt {$attempt}/{$max_attempts})");

            // Retry on server errors (5xx)
            if ($response_code >= 500 && $attempt < $max_attempts) {
                $retry_delay = 60 * pow(2, $attempt - 1);
                wp_schedule_single_event(
                    time() + $retry_delay,
                    'cityride_retry_webhook',
                    array($url, $payload, $attempt + 1)
                );
            }

            $this->log_webhook_failure($payload, "HTTP {$response_code}", $attempt);
            return false;
        }
    }

    /**
     * Logs webhook failures for debugging
     *
     * @param array $payload Webhook payload that failed
     * @param string $error Error message
     * @param int $attempt Attempt number
     */
    private function log_webhook_failure($payload, $error, $attempt) {
        $failures = get_option('cityride_webhook_failures', array());

        // Keep only last 50 failures
        if (count($failures) >= 50) {
            array_shift($failures);
        }

        $failures[] = array(
            'event' => $payload['event'],
            'booking_id' => $payload['booking_id'],
            'error' => $error,
            'attempt' => $attempt,
            'timestamp' => current_time('mysql'),
        );

        update_option('cityride_webhook_failures', $failures);
    }

    /**
     * Cron handler for webhook retry
     *
     * @param string $url Webhook URL
     * @param array $payload Webhook payload
     * @param int $attempt Attempt number
     */
    public function cron_retry_webhook($url, $payload, $attempt) {
        error_log("CityRide: Executing scheduled webhook retry (attempt {$attempt}) for event '{$payload['event']}', booking #{$payload['booking_id']}");
        $this->send_webhook_with_retry($url, $payload, $attempt);
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

        // Get webhook secret for authentication
        $webhook_secret = get_option('cityride_webhook_secret', '');

        // Build headers array
        $headers = array('Content-Type' => 'application/json');

        // Add authentication header if secret is configured
        if (!empty($webhook_secret)) {
            $headers['X-Webhook-Secret'] = $webhook_secret;
        }

        $response = wp_remote_post($webhook_url, array(
            'headers' => $headers,
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
     * Unified caching wrapper with object cache + transient fallback
     * Tries wp_cache_* first (Redis/Memcached if available), falls back to WordPress transients
     *
     * @param string $key Cache key (will be prefixed with 'cityride_')
     * @param callable $callback Function to generate data if cache miss
     * @param int $expiration Expiration time in seconds for transient fallback (default 3600 = 1 hour)
     * @return mixed Cached or freshly generated data
     */
    private function get_cached_data($key, $callback, $expiration = 3600) {
        // Prefix all keys
        $cache_key = 'cityride_' . $key;

        // Try object cache first (Redis/Memcached if available)
        $data = wp_cache_get($cache_key, 'cityride');
        if ($data !== false) {
            return $data;
        }

        // Fallback to transient
        $data = get_transient($cache_key);
        if ($data !== false) {
            // Store in object cache for next request (if available)
            wp_cache_set($cache_key, $data, 'cityride', $expiration);
            return $data;
        }

        // Cache miss - generate data
        $data = call_user_func($callback);

        // Store in both caches
        wp_cache_set($cache_key, $data, 'cityride', $expiration);
        set_transient($cache_key, $data, $expiration);

        return $data;
    }

    /**
     * Clear cache entries from both object cache and transients
     *
     * @param string|array $keys Single key or array of keys to clear (without 'cityride_' prefix)
     */
    private function clear_cache($keys) {
        if (!is_array($keys)) {
            $keys = array($keys);
        }

        foreach ($keys as $key) {
            $cache_key = 'cityride_' . $key;
            wp_cache_delete($cache_key, 'cityride');
            delete_transient($cache_key);
        }
    }

    /**
     * Get asset URL with minified version support based on SCRIPT_DEBUG
     *
     * @param string $asset_path Asset path relative to plugin directory (e.g., 'assets/admin-script.js')
     * @return string Asset URL with .min suffix if SCRIPT_DEBUG is not enabled
     */
    private function get_asset_url($asset_path) {
        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
        $info = pathinfo($asset_path);
        $minified_path = $info['dirname'] . '/' . $info['filename'] . $suffix . '.' . $info['extension'];
        return CITYRIDE_PLUGIN_URL . $minified_path;
    }

    /**
     * Get asset version based on file modification time for cache busting
     * Falls back to plugin version if file doesn't exist
     *
     * @param string $asset_path Asset path relative to plugin directory (e.g., 'assets/admin-script.js')
     * @return string File modification timestamp or plugin version
     */
    private function get_asset_version($asset_path) {
        $file_path = CITYRIDE_PLUGIN_PATH . $asset_path;
        return file_exists($file_path) ? filemtime($file_path) : CITYRIDE_VERSION;
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

        // Create drivers table
        $this->create_drivers_table();

        // Create discount codes table
        $this->create_discount_codes_table();

        // Run database migrations for new columns
        $this->migrate_database_schema();
    }

    /**
     * Creates the custom database table for CityRide drivers.
     */
    private function create_drivers_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cityride_drivers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            phone varchar(50) NOT NULL,
            vehicle_number varchar(50) NOT NULL,
            vehicle_model varchar(100) DEFAULT NULL,
            license_number varchar(100) DEFAULT NULL,
            status enum('active','inactive','on_break') DEFAULT 'active',
            total_rides int(11) DEFAULT 0,
            total_earnings decimal(10,2) DEFAULT 0.00,
            rating decimal(3,2) DEFAULT 5.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vehicle_number (vehicle_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Creates the discount codes table for promotional codes and special offers
     */
    private function create_discount_codes_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cityride_discount_codes';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            discount_type enum('percent','fixed') DEFAULT 'percent',
            discount_value decimal(10,2) NOT NULL,
            min_order_amount decimal(10,2) DEFAULT 0.00,
            max_discount_amount decimal(10,2) DEFAULT NULL,
            usage_limit int(11) DEFAULT NULL,
            usage_count int(11) DEFAULT 0,
            valid_from datetime DEFAULT NULL,
            valid_until datetime DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Migrates database schema to add new columns for enhanced status management
     * Adds: cancellation_reason, dispatcher_notes, status_changed_by, status_changed_at
     * Adds: sms_delivery_status, sms_delivery_updated_at, infobip_message_id (for SMS tracking)
     * Adds: discount_code, discount_amount, original_price, final_price (for pricing features)
     * Updates status ENUM to include 'cancelled' and 'no_show'
     */
    private function migrate_database_schema() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Check if migration is needed
        $column_check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'cancellation_reason'");

        if (empty($column_check)) {
            // Add new columns
            $wpdb->query("ALTER TABLE $table_name
                ADD COLUMN cancellation_reason VARCHAR(500) DEFAULT NULL AFTER status,
                ADD COLUMN dispatcher_notes TEXT DEFAULT NULL AFTER cancellation_reason,
                ADD COLUMN status_changed_by VARCHAR(100) DEFAULT NULL AFTER dispatcher_notes,
                ADD COLUMN status_changed_at DATETIME DEFAULT NULL AFTER status_changed_by
            ");

            error_log('CityRide: Database schema updated - added cancellation and audit columns');
        }

        // Check if SMS tracking columns exist
        $sms_tracking_check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'sms_delivery_status'");

        if (empty($sms_tracking_check)) {
            // Add SMS delivery tracking columns
            $wpdb->query("ALTER TABLE $table_name
                ADD COLUMN sms_delivery_status VARCHAR(50) DEFAULT 'not_sent' AFTER status_changed_at,
                ADD COLUMN sms_delivery_updated_at DATETIME DEFAULT NULL AFTER sms_delivery_status,
                ADD COLUMN infobip_message_id VARCHAR(255) DEFAULT NULL AFTER sms_delivery_updated_at
            ");

            error_log('CityRide: Database schema updated - added SMS delivery tracking columns');
        }

        // Update status ENUM to include cancelled and no_show
        $status_check = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'status'");

        if ($status_check && strpos($status_check->Type, 'cancelled') === false) {
            $wpdb->query("ALTER TABLE $table_name
                MODIFY COLUMN status ENUM('payed_unassigned', 'payed_assigned', 'completed', 'cancelled', 'no_show')
                NOT NULL DEFAULT 'payed_unassigned'
            ");

            error_log('CityRide: Database schema updated - added cancelled and no_show statuses');
        }

        // Check if pricing columns exist
        $pricing_check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'discount_code'");

        if (empty($pricing_check)) {
            // Add pricing and discount tracking columns
            $wpdb->query("ALTER TABLE $table_name
                ADD COLUMN discount_code VARCHAR(50) DEFAULT NULL AFTER total_price,
                ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0.00 AFTER discount_code,
                ADD COLUMN original_price DECIMAL(10,2) DEFAULT NULL AFTER discount_amount,
                ADD COLUMN final_price DECIMAL(10,2) DEFAULT NULL AFTER original_price
            ");

            error_log('CityRide: Database schema updated - added pricing and discount columns');
        }
    }

    /**
     * Creates performance-optimized database indexes
     * Adds indexes for frequently queried columns to improve query performance
     * Migrates from using only PRIMARY KEYs to comprehensive indexing strategy
     */
    private function migrate_performance_indexes() {
        global $wpdb;

        // Check if indexes have already been applied
        $indexes_version = get_option('cityride_indexes_version', '0');
        if ($indexes_version === '1.0') {
            return; // Indexes already created
        }

        $rides_table = $wpdb->prefix . 'cityride_rides';
        $drivers_table = $wpdb->prefix . 'cityride_drivers';
        $discount_codes_table = $wpdb->prefix . 'cityride_discount_codes';

        // Get existing indexes for rides table
        $existing_indexes = $wpdb->get_results("SHOW INDEX FROM $rides_table");
        $index_names = array_column($existing_indexes, 'Key_name');

        // Create composite index on status and created_at for rides table
        if (!in_array('idx_status_created_at', $index_names)) {
            $wpdb->query("CREATE INDEX idx_status_created_at ON $rides_table(status, created_at)");
            error_log('CityRide: Created index idx_status_created_at on rides table');
        }

        // Create index on cab_driver_id for driver assignment queries
        if (!in_array('idx_cab_driver_id', $index_names)) {
            $wpdb->query("CREATE INDEX idx_cab_driver_id ON $rides_table(cab_driver_id)");
            error_log('CityRide: Created index idx_cab_driver_id on rides table');
        }

        // Create index on infobip_message_id for webhook lookups
        if (!in_array('idx_infobip_message_id', $index_names)) {
            $wpdb->query("CREATE INDEX idx_infobip_message_id ON $rides_table(infobip_message_id)");
            error_log('CityRide: Created index idx_infobip_message_id on rides table');
        }

        // Get existing indexes for drivers table
        $existing_driver_indexes = $wpdb->get_results("SHOW INDEX FROM $drivers_table");
        $driver_index_names = array_column($existing_driver_indexes, 'Key_name');

        // Create index on status for active driver queries
        if (!in_array('idx_status', $driver_index_names)) {
            $wpdb->query("CREATE INDEX idx_status ON $drivers_table(status)");
            error_log('CityRide: Created index idx_status on drivers table');
        }

        // Get existing indexes for discount codes table
        $existing_discount_indexes = $wpdb->get_results("SHOW INDEX FROM $discount_codes_table");
        $discount_index_names = array_column($existing_discount_indexes, 'Key_name');

        // Create composite index for discount code validation queries
        if (!in_array('idx_active_validity', $discount_index_names)) {
            $wpdb->query("CREATE INDEX idx_active_validity ON $discount_codes_table(is_active, valid_from, valid_until)");
            error_log('CityRide: Created index idx_active_validity on discount_codes table');
        }

        // Mark indexes as created
        update_option('cityride_indexes_version', '1.0');
        error_log('CityRide: Performance indexes migration completed successfully');
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
            'Vozači',
            'Vozači',
            'manage_options',
            'cityride-drivers',
            array($this, 'admin_drivers_page')
        );

        add_submenu_page(
            'cityride-booking',
            'Analitika',
            'Analitika',
            'manage_options',
            'cityride-analytics',
            array($this, 'admin_analytics_page')
        );

        add_submenu_page(
            'cityride-booking',
            'Kodovi Popusta',
            'Kodovi Popusta',
            'manage_options',
            'cityride-discounts',
            array($this, 'admin_discounts_page')
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
        register_setting('cityride_settings', 'cityride_webhook_secret');
        register_setting('cityride_settings', 'cityride_start_tariff');
        register_setting('cityride_settings', 'cityride_price_per_km');
        register_setting('cityride_settings', 'cityride_enable_push_notifications');
        register_setting('cityride_settings', 'cityride_enable_webhook_notifications');
        register_setting('cityride_settings', 'cityride_stripe_webhook_secret');

        // SMS Template Settings
        register_setting('cityride_settings', 'cityride_sms_booking_confirmed');
        register_setting('cityride_settings', 'cityride_sms_driver_assigned');
        // REMOVED: cityride_sms_ride_completed - Passenger already at destination, unnecessary SMS
        register_setting('cityride_settings', 'cityride_sms_ride_cancelled');

        // SMS Enable/Disable Toggles
        register_setting('cityride_settings', 'cityride_enable_sms_confirmed');
        register_setting('cityride_settings', 'cityride_enable_sms_assigned');
        // REMOVED: cityride_enable_sms_completed - Saves SMS costs, reduces spam
        register_setting('cityride_settings', 'cityride_enable_sms_cancellation');

        // Pricing and Surcharge Settings
        register_setting('cityride_settings', 'cityride_minimum_fare');
        register_setting('cityride_settings', 'cityride_night_surcharge_enabled');
        register_setting('cityride_settings', 'cityride_night_surcharge_percent');
        register_setting('cityride_settings', 'cityride_night_start_time');
        register_setting('cityride_settings', 'cityride_night_end_time');
        register_setting('cityride_settings', 'cityride_weekend_surcharge_enabled');
        register_setting('cityride_settings', 'cityride_weekend_surcharge_percent');
        register_setting('cityride_settings', 'cityride_holiday_surcharge_enabled');
        register_setting('cityride_settings', 'cityride_holiday_surcharge_percent');
        register_setting('cityride_settings', 'cityride_holiday_dates');
    }

   /**
 * Enqueues admin-specific scripts and styles.
 *
 * @param string $hook The current admin page hook.
 */
public function admin_enqueue_scripts($hook) {
    // Only enqueue on CityRide admin pages
    if (strpos($hook, 'cityride') !== false) {
        // Enqueue admin CSS with file modification time for cache busting
        $admin_css_path = 'assets/admin-style.css';
        wp_enqueue_style('cityride-admin-css', $this->get_asset_url($admin_css_path), array(), $this->get_asset_version($admin_css_path));

        // Enqueue admin JS with file modification time for cache busting (loads .min version unless SCRIPT_DEBUG)
        $admin_js_path = 'assets/admin-script.js';
        wp_enqueue_script('cityride-admin-js', $this->get_asset_url($admin_js_path), array('jquery'), $this->get_asset_version($admin_js_path), true);

        // Localize script to pass AJAX URL and nonce to JavaScript
        wp_localize_script('cityride-admin-js', 'cityride_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cityride_admin_nonce')
        ));

        // Load Chart.js and analytics script only on analytics page
        if (strpos($hook, 'cityride-analytics') !== false) {
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);
            $analytics_js_path = 'assets/analytics.js';
            wp_enqueue_script('cityride-analytics-js', $this->get_asset_url($analytics_js_path), array('jquery', 'chartjs'), $this->get_asset_version($analytics_js_path), true);
        }
    }
}

    /**
     * Enqueues frontend-specific scripts and styles.
     */
    public function frontend_enqueue_scripts() {
        // Enqueue styles with file modification time for cache busting
        $frontend_css_path = 'assets/frontend-style.css';
        wp_enqueue_style('cityride-frontend-css', $this->get_asset_url($frontend_css_path), array(), $this->get_asset_version($frontend_css_path));
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4', 'all' );
        wp_enqueue_style('mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css');

        // Mapbox JS and Stripe JS
        wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js', array(), null, true);
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);

        // --- OVDJE JE UKLONJENA ONE SIGNAL INICIJALIZACIJA ---
        // OneSignal WordPress plugin se brine za učitavanje i inicijalizaciju OneSignal SDK-a.
        // Nema potrebe za dupliciranjem koda ovdje.
        // --- KRAJ UKLANJANJA ---

        // Enqueue frontend JS with file modification time for cache busting (loads .min version unless SCRIPT_DEBUG)
        $frontend_js_path = 'assets/frontend-script.js';
        wp_enqueue_script('cityride-frontend-js', $this->get_asset_url($frontend_js_path), array('jquery', 'mapbox-gl-js', 'stripe-js'), $this->get_asset_version($frontend_js_path), true);

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

                    <!-- Discount Code Section -->
                    <div id="discount-code-section" class="discount-code-section" style="margin-top: 25px; display: none;">
                        <label for="discount-code-input" style="display: block; font-weight: 700; margin-bottom: 10px; color: #444;">
                            🎁 Imate kod popusta?
                        </label>
                        <div style="display: flex; gap: 10px; align-items: flex-start;">
                            <input type="text" id="discount-code-input" placeholder="Unesite kod" style="flex: 1; padding: 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 1rem; text-transform: uppercase;">
                            <button type="button" id="apply-discount-btn" class="btn btn-primary" style="min-width: auto; padding: 12px 24px;">Primijeni</button>
                        </div>
                        <div id="discount-message" style="margin-top: 10px; font-weight: 600;"></div>
                        <div id="discount-details" style="display: none; margin-top: 15px; padding: 15px; background: #e6ffe6; border-radius: 8px; border: 1px solid #4CAF50;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #555;">Originalna cijena:</span>
                                <span id="discount-original-price" style="text-decoration: line-through; color: #999;"></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #555; font-weight: 700;">Popust (<span id="discount-code-used"></span>):</span>
                                <span id="discount-amount" style="color: #4CAF50; font-weight: 700;"></span>
                            </div>
                            <div style="border-top: 2px solid #4CAF50; margin: 10px 0; padding-top: 10px; display: flex; justify-content: space-between;">
                                <span style="font-weight: 800; font-size: 1.1rem;">Nova cijena:</span>
                                <span id="discount-final-price" style="font-weight: 900; font-size: 1.3rem; color: #E65100;"></span>
                            </div>
                            <button type="button" id="remove-discount-btn" class="btn" style="width: 100%; margin-top: 10px; padding: 8px; background: #f44336; color: white; min-width: auto;">
                                Ukloni popust
                            </button>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="calculated_distance_km" name="distance_km">
                <input type="hidden" id="calculated_total_price" name="total_price">
                <input type="hidden" id="calculated_stripe_amount" name="stripe_amount">
                <input type="hidden" id="discount_code_applied" name="discount_code">
                <input type="hidden" id="discount_amount_applied" name="discount_amount" value="0">

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
                            <th>SMS Status</th>
                            <th>Putnik</th>
                            <th>Telefon</th>
                            <th>Vozač</th>
                            <th>ETA</th>
                            <th>Kreirano</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="rides-table-body">
                        <tr><td colspan="13" style="text-align:center;">Učitavam vožnje...</td></tr>
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

                    <label for="driver-select">Odaberi Vozača:</label>
                    <select id="driver-select" name="driver_id" required style="width: 100%; padding: 10px; margin-bottom: 15px;">
                        <option value="">-- Učitavam vozače --</option>
                    </select>
                    <p class="description" style="margin-top: -10px; margin-bottom: 15px; font-size: 13px; color: #666;">
                        Prikazuju se samo aktivni vozači. <a href="?page=cityride-drivers" target="_blank">Upravljaj vozačima</a>
                    </p>

                    <label for="eta">ETA (Procijenjeno vrijeme dolaska):</label>
                    <input type="text" id="eta" name="eta" placeholder="Npr. 5 minuta" required>

                    <div id="modal-message"></div>
                    <div class="form-actions">
                        <button type="submit" class="button button-primary">Dodijeli</button>
                        <button type="button" class="button button-secondary cancel-modal">Poništi</button>
                    </div>
                </form>
            </div>

            <div class="cityride-modal" id="cancel-ride-modal" style="display: none;">
                <span class="close">&times;</span>
                <h2>Otkaži Vožnju</h2>
                <form id="cancel-ride-form">
                    <input type="hidden" id="cancel-ride-id" name="ride_id">

                    <label for="cancel-reason-template">Razlog otkazivanja:</label>
                    <select id="cancel-reason-template">
                        <option value="Zahtjev kupca">Zahtjev kupca</option>
                        <option value="Vozač nije dostupan">Vozač nije dostupan</option>
                        <option value="Pogrešna adresa">Pogrešna adresa</option>
                        <option value="Dupla rezervacija">Dupla rezervacija</option>
                        <option value="Problem sa plaćanjem">Problem sa plaćanjem</option>
                        <option value="Vremenski uvjeti">Vremenski uvjeti</option>
                        <option value="other">Drugo (specificiraj)...</option>
                    </select>

                    <textarea id="cancel-reason-custom" placeholder="Unesite prilagođeni razlog" style="display:none; margin-top:10px; width: 100%; min-height: 60px;" rows="3"></textarea>

                    <div style="margin-top: 15px;">
                        <label>
                            <input type="checkbox" id="process-refund" checked />
                            <strong>Procesuiraj povrat novca putem Stripe-a</strong>
                        </label>
                        <p class="description" style="margin: 5px 0 0 25px; font-size: 12px; color: #666;">
                            Ako je označeno, putniku će biti automatski vraćen novac.
                        </p>
                    </div>

                    <div id="cancel-modal-message" style="margin-top: 15px;"></div>
                    <div class="form-actions">
                        <button type="submit" class="button" style="background: linear-gradient(45deg, #dc3545, #c82333); color: white; border: none;">Otkaži Vožnju</button>
                        <button type="button" class="button button-secondary cancel-modal">Zatvori</button>
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
                        <td><input type="url" name="cityride_make_webhook_url" value="<?php echo esc_attr(get_option('cityride_make_webhook_url')); ?>" class="regular-text" /><p class="description">URL za slanje podataka o novim vožnjama na Make.com/n8n.</p></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Webhook Secret Key</th>
                        <td>
                            <input type="text" id="cityride_webhook_secret" name="cityride_webhook_secret" value="<?php echo esc_attr(get_option('cityride_webhook_secret', '')); ?>" class="regular-text" placeholder="Enter secret key for webhook authentication" />
                            <button type="button" onclick="generateWebhookSecret()" class="button" style="margin-left: 5px;">Generate Random Key</button>
                            <p class="description">Secret key sent in X-Webhook-Secret header for authentication. Configure the same secret in your n8n webhook node (Header Auth).</p>
                        </td>
                    </tr>
                    <tr valign="top" style="background: #f0f8ff; border-left: 4px solid #0073aa;">
                        <th scope="row">📲 Infobip SMS Webhook URL</th>
                        <td>
                            <input type="text" value="<?php echo esc_url(get_rest_url(null, 'cityride/v1/infobip-webhook')); ?>" class="regular-text" readonly onclick="this.select(); document.execCommand('copy'); alert('Webhook URL copied!');" style="background: #fff; cursor: pointer;" />
                            <p class="description">
                                <strong>Kopirajte ovaj URL u Infobip Dashboard:</strong><br>
                                1. Login to <a href="https://portal.infobip.com" target="_blank">Infobip Portal</a><br>
                                2. Navigate to: <strong>Channels & Numbers → SMS → Delivery Reports</strong><br>
                                3. Click <strong>"Add New Webhook"</strong><br>
                                4. Paste URL above and enable events: <strong>DELIVERED, UNDELIVERABLE, REJECTED, EXPIRED</strong><br>
                                5. Click <strong>Save</strong> - SMS delivery tracking will start working automatically!<br>
                                <em>Napomena: Webhook ne zahtijeva autentifikaciju (Infobip verifikuje server-side).</em>
                            </p>
                        </td>
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
                        <th scope="row">Minimalna cijena vožnje (BAM)</th>
                        <td>
                            <input type="number" step="0.01" name="cityride_minimum_fare" value="<?php echo esc_attr(get_option('cityride_minimum_fare')); ?>" class="small-text" />
                            <p class="description">Minimalna cijena koja se naplaćuje bez obzira na udaljenost.</p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 40px; border-top: 2px solid #eee; padding-top: 20px;">⏰ Dodaci na cijenu (Surcharges)</h2>
                <p class="description" style="margin-bottom: 20px;">
                    <strong>Konfigurirajte dodatke na cijenu za noćne vožnje, vikende i praznike.</strong><br>
                    Dodaci se automatski primjenjuju na konačnu cijenu kada su ispunjeni uslovi.
                </p>

                <table class="form-table">
                    <tr valign="top" style="background: #f0f8ff;">
                        <th scope="row">🌙 Noćni dodatak</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_night_surcharge_enabled" value="1" <?php checked('1', get_option('cityride_night_surcharge_enabled')); ?> />
                                Omogući noćni dodatak
                            </label>
                            <br><br>
                            <label>
                                Dodatak (%):
                                <input type="number" step="1" min="0" max="100" name="cityride_night_surcharge_percent" value="<?php echo esc_attr(get_option('cityride_night_surcharge_percent')); ?>" class="small-text" />%
                            </label>
                            <br><br>
                            <label>
                                Početak noćnog perioda:
                                <input type="time" name="cityride_night_start_time" value="<?php echo esc_attr(get_option('cityride_night_start_time')); ?>" />
                            </label>
                            <label style="margin-left: 20px;">
                                Kraj noćnog perioda:
                                <input type="time" name="cityride_night_end_time" value="<?php echo esc_attr(get_option('cityride_night_end_time')); ?>" />
                            </label>
                            <p class="description">Primjer: 22:00 - 06:00 za 20% dodatak na vožnje u noćnim satima.</p>
                        </td>
                    </tr>

                    <tr valign="top" style="background: #fffaf0;">
                        <th scope="row">🎉 Vikend dodatak</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_weekend_surcharge_enabled" value="1" <?php checked('1', get_option('cityride_weekend_surcharge_enabled')); ?> />
                                Omogući vikend dodatak
                            </label>
                            <br><br>
                            <label>
                                Dodatak (%):
                                <input type="number" step="1" min="0" max="100" name="cityride_weekend_surcharge_percent" value="<?php echo esc_attr(get_option('cityride_weekend_surcharge_percent')); ?>" class="small-text" />%
                            </label>
                            <p class="description">Primjenjuje se subotom i nedjeljom.</p>
                        </td>
                    </tr>

                    <tr valign="top" style="background: #fff5f5;">
                        <th scope="row">🎊 Dodatak za praznike</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_holiday_surcharge_enabled" value="1" <?php checked('1', get_option('cityride_holiday_surcharge_enabled')); ?> />
                                Omogući dodatak za praznike
                            </label>
                            <br><br>
                            <label>
                                Dodatak (%):
                                <input type="number" step="1" min="0" max="100" name="cityride_holiday_surcharge_percent" value="<?php echo esc_attr(get_option('cityride_holiday_surcharge_percent')); ?>" class="small-text" />%
                            </label>
                            <br><br>
                            <label>
                                Datumi praznika (odvojeni zarezom):
                                <textarea name="cityride_holiday_dates" rows="3" class="large-text" placeholder="2026-01-01, 2026-01-07, 2026-05-01, 2026-05-09"><?php echo esc_textarea(get_option('cityride_holiday_dates')); ?></textarea>
                            </label>
                            <p class="description">Unesite datume u formatu YYYY-MM-DD, odvojene zarezom. Primjer: 2026-01-01, 2026-01-07 (Nova Godina, Božić)</p>
                        </td>
                    </tr>
                </table>

                <table class="form-table">
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
                    <tr valign="top">
                        <th scope="row">Status Posljednjeg Webhooka</th>
                        <td>
                            <?php
                            $last_webhook_status = get_option('cityride_last_webhook_status');
                            if ($last_webhook_status && is_array($last_webhook_status)) {
                                $status_class = $last_webhook_status['status'] === 'success' ? 'notice-success' : 'notice-error';
                                $status_text = $last_webhook_status['status'] === 'success' ? 'Uspješno' : 'Neuspješno';
                                $status_icon = $last_webhook_status['status'] === 'success' ? '✅' : '❌';
                                ?>
                                <div class="notice <?php echo esc_attr($status_class); ?> inline" style="margin: 0; padding: 10px;">
                                    <p style="margin: 0;">
                                        <strong><?php echo $status_icon; ?> Status:</strong> <?php echo esc_html($status_text); ?><br>
                                        <strong>Vrijeme:</strong> <?php echo esc_html($last_webhook_status['timestamp']); ?><br>
                                        <strong>Booking ID:</strong> <?php echo esc_html($last_webhook_status['booking_id']); ?><br>
                                        <?php if (isset($last_webhook_status['http_code']) && $last_webhook_status['http_code']): ?>
                                            <strong>HTTP kod:</strong> <?php echo esc_html($last_webhook_status['http_code']); ?><br>
                                        <?php endif; ?>
                                        <?php if (isset($last_webhook_status['error']) && $last_webhook_status['error']): ?>
                                            <strong>Greška:</strong> <?php echo esc_html($last_webhook_status['error']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <p class="description">
                                    Dostupno samo u DEBUG modu (kada je WP_DEBUG omogućen).
                                    Provjerite <code>/wp-content/debug.log</code> za detaljne logove.
                                </p>
                            <?php } else { ?>
                                <p class="description">
                                    Nema zabilježenog statusa. Omogućite WP_DEBUG u <code>wp-config.php</code> da vidite status webhooks-a.
                                </p>
                            <?php } ?>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 40px; border-top: 2px solid #eee; padding-top: 20px;">SMS Predlošci (n8n + Infobip)</h2>
                <p class="description" style="margin-bottom: 20px;">
                    <strong>💰 Optimizovano za smanjenje troškova SMS-a (50% uštede)</strong><br>
                    Konfigurirajte SMS poruke koje će n8n slati putem Infobip-a. Koristite varijable: <strong>{passenger_name}</strong>, <strong>{driver_name}</strong>, <strong>{vehicle_number}</strong>, <strong>{eta}</strong>, <strong>{total_price}</strong>, <strong>{address_from}</strong>, <strong>{address_to}</strong>, <strong>{booking_id}</strong>, <strong>{cancellation_reason}</strong><br>
                    <em>Napomena: SMS "Vožnja završena" je uklonjen jer putnik već zna da je vožnja gotova (nepotreban trošak).</em>
                </p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">SMS: Rezervacija potvrđena</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_enable_sms_confirmed" value="yes" <?php checked('yes', get_option('cityride_enable_sms_confirmed', 'yes')); ?> />
                                Omogući
                            </label>
                            <br><br>
                            <textarea name="cityride_sms_booking_confirmed" rows="3" class="large-text" placeholder="Primer: Vaša rezervacija je potvrđena! Od: {address_from} Do: {address_to}. Cijena: {total_price}."><?php echo esc_textarea(get_option('cityride_sms_booking_confirmed', 'Vaša rezervacija je potvrđena! Od: {address_from} Do: {address_to}. Cijena: {total_price}. Taksi će uskoro biti dodijeljen.')); ?></textarea>
                            <p class="description">Šalje se odmah nakon uspješne rezervacije i plaćanja.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">SMS: Vozač dodijeljen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_enable_sms_assigned" value="yes" <?php checked('yes', get_option('cityride_enable_sms_assigned', 'yes')); ?> />
                                Omogući
                            </label>
                            <br><br>
                            <textarea name="cityride_sms_driver_assigned" rows="3" class="large-text" placeholder="Primer: Taksi {vehicle_number} ({driver_name}) je na putu! ETA: {eta}."><?php echo esc_textarea(get_option('cityride_sms_driver_assigned', 'Taksi {vehicle_number} ({driver_name}) je na putu! Procijenjeno vrijeme dolaska: {eta}.')); ?></textarea>
                            <p class="description">Šalje se kada dispečer dodijeli vozača vožnji.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">SMS: Rezervacija otkazana</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cityride_enable_sms_cancellation" value="yes" <?php checked('yes', get_option('cityride_enable_sms_cancellation', 'yes')); ?> />
                                Omogući
                            </label>
                            <br><br>
                            <textarea name="cityride_sms_ride_cancelled" rows="3" class="large-text" placeholder="Primer: Vaša rezervacija #{booking_id} je otkazana. Razlog: {cancellation_reason}."><?php echo esc_textarea(get_option('cityride_sms_ride_cancelled', 'Vaša rezervacija #{booking_id} je otkazana. Razlog: {cancellation_reason}. Za dodatna pitanja kontaktirajte nas.')); ?></textarea>
                            <p class="description">⚠️ Šalje se SAMO kada dispečer otkaže vožnju. Ne šalje se ako kupac otkaže (jer oni već znaju).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <script>
            function generateWebhookSecret() {
                // Generate 32-character random hex string (128 bits of entropy)
                const array = new Uint8Array(16);
                crypto.getRandomValues(array);
                const secret = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');

                // Set the value in the input field
                document.getElementById('cityride_webhook_secret').value = secret;

                // Show success message
                alert('Random webhook secret generated! Make sure to:\n1. Save this configuration\n2. Copy this secret to your n8n webhook node (Header Auth)\n3. Header name: X-Webhook-Secret\n4. Header value: ' + secret);
            }
            </script>
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
            $where_clauses[] = 'created_at >= %s';
            $query_args[] = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $where_clauses[] = 'created_at <= %s';
            $query_args[] = $date_to . ' 23:59:59';
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
        if (empty($query_args)) {
            // No filters applied, use direct query without prepare
            $total_rides = $wpdb->get_var("SELECT COUNT(id) FROM $table_name $where_sql");
        } else {
            // Filters applied, use prepare with placeholders
            $total_rides_query = $wpdb->prepare("SELECT COUNT(id) FROM $table_name $where_sql", ...$query_args);
            $total_rides = $wpdb->get_var($total_rides_query);
        }
        $total_pages = ceil($total_rides / $per_page);

        // Get rides for the current page
        $final_query_args = $query_args;
        $final_query_args[] = $per_page;
        $final_query_args[] = $offset;

        // Always use prepare here because we always have LIMIT and OFFSET placeholders
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
                        $action_buttons .= ' <button class="button cancel-ride-btn" data-ride-id="' . esc_attr($ride->id) . '" style="background: linear-gradient(45deg, #dc3545, #c82333); color: white; border: none;">Otkaži</button>';
                        break;
                    case 'payed_assigned':
                        $status_class = 'status-assigned';
                        $action_buttons = '<button class="button button-primary complete-ride-btn" data-ride-id="' . esc_attr($ride->id) . '">Završi</button>';
                        $action_buttons .= ' <button class="button cancel-ride-btn" data-ride-id="' . esc_attr($ride->id) . '" style="background: linear-gradient(45deg, #dc3545, #c82333); color: white; border: none;">Otkaži</button>';
                        break;
                    case 'completed':
                        $status_class = 'status-completed';
                        $action_buttons = ''; // No actions for completed rides
                        break;
                    case 'cancelled':
                        $status_class = 'status-cancelled';
                        $action_buttons = '<span style="color: #666; font-style: italic;">Otkazana</span>';
                        if (!empty($ride->cancellation_reason)) {
                            $action_buttons .= '<br><small style="color: #999;">Razlog: ' . esc_html($ride->cancellation_reason) . '</small>';
                        }
                        break;
                    case 'no_show':
                        $status_class = 'status-no-show';
                        $action_buttons = '<span style="color: #666; font-style: italic;">Nije se pojavio</span>';
                        break;
                    default:
                        $status_class = '';
                        $action_buttons = '';
                        break;
                }

                // Generate SMS status badge
                $sms_status = $ride->sms_delivery_status ?? 'not_sent';
                $sms_status_label = $this->get_sms_status_label($sms_status);
                $sms_status_badge = '<span class="sms-status sms-status-' . esc_attr($sms_status) . '">' . esc_html($sms_status_label) . '</span>';

                $rides_html .= '<tr>';
                $rides_html .= '<td data-label="ID">' . esc_html($ride->id) . '</td>';
                $rides_html .= '<td data-label="Polazište">' . esc_html($ride->address_from) . '</td>';
                $rides_html .= '<td data-label="Odredište">' . esc_html($ride->address_to) . '</td>';
                $rides_html .= '<td data-label="Udaljenost (km)">' . esc_html(number_format($ride->distance_km, 2)) . '</td>';
                $rides_html .= '<td data-label="Cijena (BAM)">' . esc_html(number_format($ride->total_price, 2)) . '</td>';
                $rides_html .= '<td data-label="Status" class="status ' . esc_attr($status_class) . '">' . esc_html($this->get_status_label($ride->status)) . '</td>';
                $rides_html .= '<td data-label="SMS Status">' . $sms_status_badge . '</td>';
                $rides_html .= '<td data-label="Putnik">' . esc_html($ride->passenger_name) . '</td>';
                $rides_html .= '<td data-label="Telefon">' . esc_html($ride->passenger_phone) . '</td>';
                $rides_html .= '<td data-label="Vozač">' . (empty($ride->cab_driver_id) ? 'Nije dodijeljen' : esc_html($ride->cab_driver_id)) . '</td>';
                $rides_html .= '<td data-label="ETA">' . (empty($ride->eta) ? 'N/A' : esc_html($ride->eta)) . '</td>';
                $rides_html .= '<td data-label="Kreirano">' . esc_html(date('d.m.Y H:i', strtotime($ride->created_at))) . '</td>';
                $rides_html .= '<td data-label="Akcije">' . $action_buttons . '</td>';
                $rides_html .= '</tr>';
            }
        } else {
            $rides_html = '<tr><td colspan="13" style="text-align:center; padding: 40px;">Nema pronađenih vožnji.</td></tr>';
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
            case 'cancelled':
                return 'Otkazana';
            case 'no_show':
                return 'Nije se pojavio';
            default:
                return ucfirst(str_replace('_', ' ', $status));
        }
    }

    /**
     * Helper function to get SMS delivery status label for display.
     */
    private function get_sms_status_label($sms_status) {
        switch ($sms_status) {
            case 'delivered':
                return '✓ Dostavljeno';
            case 'pending':
                return '⏳ Na čekanju';
            case 'failed':
                return '✗ Neuspjelo';
            case 'rejected':
                return '🚫 Odbijeno';
            case 'not_sent':
                return '— Nije poslano';
            case 'unknown':
                return '? Nepoznato';
            default:
                return '—';
        }
    }

    /**
     * Retrieves ride statistics.
     * This function is now called internally by ajax_load_rides.
     */
    private function get_ride_statistics() {
        return $this->get_cached_data('stats_dashboard', function() {
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
            $current_date_start = $current_date_only . ' 00:00:00';
            $current_date_end = $current_date_only . ' 23:59:59';

            // Consolidated query using conditional aggregation - 5 queries reduced to 1
            $stats_row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN created_at >= %s AND created_at <= %s THEN 1 ELSE 0 END) as today_rides,
                    SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) as this_month_rides,
                    SUM(CASE WHEN status IN ('completed', 'payed_unassigned', 'payed_assigned')
                        AND created_at >= %s THEN total_price ELSE 0 END) as monthly_revenue,
                    SUM(CASE WHEN status = 'payed_unassigned' THEN 1 ELSE 0 END) as pending_rides,
                    SUM(CASE WHEN status = 'payed_assigned' THEN 1 ELSE 0 END) as assigned_rides
                FROM $table_name",
                $current_date_start, $current_date_end,
                $current_month_start,
                $current_month_start
            ));

            $stats['today_rides'] = intval($stats_row->today_rides ?: 0);
            $stats['this_month_rides'] = intval($stats_row->this_month_rides ?: 0);
            $stats['monthly_revenue'] = floatval($stats_row->monthly_revenue ?: 0);
            $stats['pending_rides'] = intval($stats_row->pending_rides ?: 0);
            $stats['assigned_rides'] = intval($stats_row->assigned_rides ?: 0);

            // Debug log (ukloniti u produkciji)
            error_log('CityRide Stats (optimized, cache miss): ' . json_encode($stats));

            return $stats;
        }, 3600); // 1 hour cache expiration for transient fallback
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

        $ride_id = intval($_POST['ride_id']);
        $driver_id = intval($_POST['driver_id']);
        $eta = sanitize_text_field($_POST['eta']);

        if (empty($ride_id) || empty($driver_id) || empty($eta)) {
            wp_send_json_error('Svi podaci su obavezni (ID vožnje, vozač, ETA).');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        // Fetch driver details
        $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $drivers_table WHERE id = %d", $driver_id));

        if (!$driver) {
            wp_send_json_error('Vozač nije pronađen.');
        }

        $cab_driver_id = $driver->vehicle_number; // Use vehicle number for compatibility

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

                // Fetch full ride details for webhook
                $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ride_id), ARRAY_A);

                // Send driver_assigned webhook event
                if (get_option('cityride_enable_webhook_notifications') === '1' && $ride) {
                    $ride['driver_name'] = ''; // Can be populated if you have driver names in database
                    $this->send_event_webhook('driver_assigned', $ride);
                    error_log("CityRide: driver_assigned webhook sent for ride {$ride_id}");
                }

                // Provjeri da li su push notifikacije omogućene u opcijama i da li imamo Player ID
                $enable_push_notifications = get_option('cityride_enable_push_notifications', '0');

                if ($enable_push_notifications === '1' && !empty($passenger_onesignal_id)) {
                    $notification_title = 'Vaša vožnja je dodijeljena!';
                    // Format poruke: Taksi #ID_VOZILA je na putu! Procijenjeno vrijeme dolaska: X minuta.
                    $notification_message = sprintf('Taksi #%s je na putu! Procijenjeno vrijeme dolaska: %s minuta.',
                        $cab_driver_id,
                        $eta
                    );

                    // Pozivamo send_onesignal_notification metodu
                    $this->send_onesignal_notification(
                        [$passenger_onesignal_id], // Player ID ide kao niz
                        $notification_title,
                        $notification_message,
                        ''
                    );

                    error_log("CityRide: OneSignal notification sent for ride {$ride_id} (Cab: {$cab_driver_id}, ETA: {$eta}) to {$passenger_onesignal_id}");
                } else {
                    error_log("CityRide: OneSignal notification not sent for ride {$ride_id}. Either disabled ({$enable_push_notifications}), or no Player ID ({$passenger_onesignal_id}).");
                }
            } else {
                 error_log("CityRide: No notification sent for ride {$ride_id}. Status was not 'payed_unassigned' or update didn't involve status change from 'payed_unassigned'. Current status: {$current_status}");
            }

            // Clear dashboard and driver stats caches
            $this->clear_cache(array('stats_dashboard', 'stats_key_metrics', 'stats_drivers'));

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
            // Fetch the updated ride for notifications
            $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ride_id), ARRAY_A);

            if ($ride) {
                // REMOVED: ride_completed SMS webhook - Passenger already at destination, unnecessary notification
                // Reason: Wastes SMS cost, no value added (passenger knows ride is complete)
                // if (get_option('cityride_enable_webhook_notifications') === '1') {
                //     $ride['driver_name'] = '';
                //     $this->send_event_webhook('ride_completed', $ride);
                //     error_log("CityRide: ride_completed webhook sent for ride {$ride_id}");
                // }

                // Keep OneSignal push notification (optional, low cost)
                if (get_option('cityride_enable_push_notifications') === '1' && !empty($ride['passenger_onesignal_id'])) {
                    $message = "Vaša vožnja ({$ride['address_from']} do {$ride['address_to']}) je uspješno završena! Hvala Vam na korištenju CityRide-a.";
                    $this->send_onesignal_notification([$ride['passenger_onesignal_id']], 'Vožnja završena!', $message);
                }

                // Update driver earnings and ride count
                if (!empty($ride['cab_driver_id'])) {
                    $this->update_driver_earnings($ride['cab_driver_id'], $ride['total_price']);
                }
            }

            // Clear all statistics caches (affects revenue)
            $this->clear_cache(array('stats_dashboard', 'stats_key_metrics', 'stats_drivers'));

            wp_send_json_success('Vožnja uspješno završena.');
        }
    }

    /**
     * AJAX handler for cancelling a ride with reason
     * Marks ride as cancelled, sends webhook notification, and optionally processes refund
     */
    public function ajax_cancel_ride() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje za otkazivanje vožnje.');
        }

        $ride_id = intval($_POST['ride_id']);
        $cancellation_reason = sanitize_text_field($_POST['cancellation_reason']);
        $process_refund = isset($_POST['process_refund']) && $_POST['process_refund'] === 'true';

        if (empty($ride_id)) {
            wp_send_json_error('ID vožnje je obavezan.');
        }

        if (empty($cancellation_reason)) {
            wp_send_json_error('Razlog otkazivanja je obavezan.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Get ride details before cancellation
        $ride = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $ride_id), ARRAY_A);

        if (!$ride) {
            wp_send_json_error('Vožnja nije pronađena.');
        }

        // Check if ride can be cancelled (not already completed or cancelled)
        if (in_array($ride['status'], array('completed', 'cancelled', 'no_show'))) {
            wp_send_json_error('Ova vožnja ne može biti otkazana (status: ' . $ride['status'] . ').');
        }

        // Get current dispatcher name
        $dispatcher_name = wp_get_current_user()->display_name;

        // Update ride status to cancelled
        $updated = $wpdb->update(
            $table_name,
            array(
                'status' => 'cancelled',
                'cancellation_reason' => $cancellation_reason,
                'status_changed_by' => $dispatcher_name,
                'status_changed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $ride_id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            error_log('CityRide: Database update failed in ajax_cancel_ride: ' . $wpdb->last_error);
            wp_send_json_error('Greška pri otkazivanju vožnje.');
        }

        // Add cancellation reason to ride data for webhook
        $ride['cancellation_reason'] = $cancellation_reason;
        $ride['id'] = $ride_id;

        // Send ride_cancelled webhook event
        if (get_option('cityride_enable_webhook_notifications') === '1') {
            $this->send_event_webhook('ride_cancelled', $ride);
            error_log("CityRide: ride_cancelled webhook sent for ride {$ride_id}");
        }

        // Process refund if requested and Stripe payment exists
        $refund_status = 'not_processed';
        if ($process_refund && !empty($ride['stripe_payment_id'])) {
            $refund_result = $this->process_stripe_refund($ride['stripe_payment_id']);

            if ($refund_result['success']) {
                $refund_status = 'refunded';
                error_log("CityRide: Stripe refund successful for ride {$ride_id}, payment {$ride['stripe_payment_id']}");
            } else {
                $refund_status = 'refund_failed';
                error_log("CityRide: Stripe refund failed for ride {$ride_id}: " . $refund_result['error']);
            }
        }

        // Clear statistics caches
        $this->clear_cache(array('stats_dashboard', 'stats_key_metrics'));

        wp_send_json_success(array(
            'message' => 'Vožnja uspješno otkazana.',
            'ride_id' => $ride_id,
            'refund_status' => $refund_status
        ));
    }

    /**
     * Processes a Stripe refund for a cancelled ride
     *
     * @param string $payment_intent_id Stripe PaymentIntent ID
     * @return array Result with 'success' boolean and 'error' message if failed
     */
    private function process_stripe_refund($payment_intent_id) {
        try {
            $stripe_secret_key = get_option('cityride_stripe_secret_key');

            if (empty($stripe_secret_key)) {
                return array('success' => false, 'error' => 'Stripe secret key not configured');
            }

            require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
            \Stripe\Stripe::setApiKey($stripe_secret_key);

            // Create refund
            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment_intent_id,
                'reason' => 'requested_by_customer',
            ]);

            if ($refund->status === 'succeeded' || $refund->status === 'pending') {
                return array('success' => true, 'refund_id' => $refund->id);
            } else {
                return array('success' => false, 'error' => 'Refund status: ' . $refund->status);
            }

        } catch (\Stripe\Exception\ApiErrorException $e) {
            return array('success' => false, 'error' => $e->getMessage());
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
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
            $where_clauses[] = 'created_at >= %s';
            $query_args[] = $date_from . ' 00:00:00';
        }
        if (!empty($date_to)) {
            $where_clauses[] = 'created_at <= %s';
            $query_args[] = $date_to . ' 23:59:59';
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
        if (empty($query_args)) {
            // No filters applied, use direct query without prepare
            $rides = $wpdb->get_results(
                "SELECT id, passenger_name, passenger_phone, passenger_email, address_from, address_to, distance_km, total_price, status, cab_driver_id, eta, stripe_payment_id, created_at, updated_at FROM $table_name $where_sql ORDER BY created_at DESC",
                ARRAY_A
            );
        } else {
            // Filters applied, use prepare with placeholders
            $rides_query = $wpdb->prepare(
                "SELECT id, passenger_name, passenger_phone, passenger_email, address_from, address_to, distance_km, total_price, status, cab_driver_id, eta, stripe_payment_id, created_at, updated_at FROM $table_name $where_sql ORDER BY created_at DESC",
                ...$query_args
            );
            $rides = $wpdb->get_results($rides_query, ARRAY_A);
        }

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

            // Check webhook notifications setting and trigger if enabled
            $webhook_enabled = get_option('cityride_enable_webhook_notifications');
            if ($webhook_enabled === '1') {
                error_log('CityRide: Stripe webhook received, triggering notification for booking ' . $booking_id);
                $this->send_webhook_notification($booking_id);
                error_log('CityRide: Stripe webhook notification completed for booking ' . $booking_id);
            } else {
                error_log('CityRide: Stripe webhook notifications disabled, skipping notification for booking ' . $booking_id);
            }
        }
    }

    /**
     * Registers the REST API endpoint for Infobip SMS Delivery Webhooks.
     */
    public function register_infobip_webhook_endpoint() {
        register_rest_route('cityride/v1', '/infobip-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_infobip_webhook'),
            'permission_callback' => '__return_true', // Infobip webhooks don't use WP nonces/auth
        ));
    }

    /**
     * Handles incoming Infobip SMS delivery status webhooks.
     * Updates ride SMS delivery status based on Infobip callbacks.
     *
     * Expected Infobip webhook payload:
     * {
     *   "results": [{
     *     "messageId": "...",
     *     "status": { "groupId": 3, "groupName": "DELIVERED", "id": 5, "name": "DELIVERED_TO_HANDSET" },
     *     "price": { "pricePerMessage": 0.05, "currency": "EUR" },
     *     "sentAt": "2024-01-15T10:30:00.000+0000",
     *     "doneAt": "2024-01-15T10:30:05.000+0000",
     *     "to": "38761234567"
     *   }]
     * }
     */
    public function handle_infobip_webhook(WP_REST_Request $request) {
        $payload = $request->get_json_params();

        // Log received webhook for debugging
        error_log('CityRide Infobip Webhook: Received payload: ' . json_encode($payload));

        // Validate basic structure
        if (!isset($payload['results']) || !is_array($payload['results'])) {
            error_log('CityRide Infobip Webhook: Invalid payload structure');
            return new WP_REST_Response('Invalid payload', 400);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Extract all message IDs from payload
        $message_ids = array();
        foreach ($payload['results'] as $result) {
            if (isset($result['messageId'])) {
                $message_ids[] = $result['messageId'];
            }
        }

        if (empty($message_ids)) {
            error_log('CityRide Infobip Webhook: No valid message IDs in payload');
            return new WP_REST_Response('No valid message IDs', 400);
        }

        // Fetch all matching rides in a single query
        $placeholders = implode(',', array_fill(0, count($message_ids), '%s'));
        $rides = $wpdb->get_results($wpdb->prepare(
            "SELECT id, infobip_message_id, passenger_phone FROM $table_name
             WHERE infobip_message_id IN ($placeholders)",
            ...$message_ids
        ));

        // Build lookup map
        $ride_map = array();
        foreach ($rides as $ride) {
            if (!empty($ride->infobip_message_id)) {
                $ride_map[$ride->infobip_message_id] = $ride;
            }
        }

        // Build batch update arrays
        $update_cases = array();
        $ride_ids = array();
        $current_time = current_time('mysql');

        foreach ($payload['results'] as $result) {
            $message_id = $result['messageId'] ?? null;
            $status = $result['status']['groupName'] ?? null;

            if (!$message_id || !$status) {
                error_log('CityRide Infobip Webhook: Missing messageId or status in result');
                continue;
            }

            if (isset($ride_map[$message_id])) {
                $ride_id = $ride_map[$message_id]->id;
                $internal_status = $this->map_infobip_status($status);
                $ride_ids[] = $ride_id;
                $update_cases[] = $wpdb->prepare("WHEN %d THEN %s", $ride_id, $internal_status);
                error_log("CityRide Infobip Webhook: Will update ride {$ride_id} to {$internal_status}");
            } else {
                error_log("CityRide Infobip Webhook: No ride found for message ID {$message_id}");
            }
        }

        // Execute batch UPDATE with CASE statement
        if (!empty($update_cases) && !empty($ride_ids)) {
            $cases_sql = implode(' ', $update_cases);
            $ids_sql = implode(',', array_map('intval', $ride_ids));
            $query = "UPDATE $table_name SET
                sms_delivery_status = CASE id $cases_sql END,
                sms_delivery_updated_at = '" . esc_sql($current_time) . "'
                WHERE id IN ($ids_sql)";

            $result = $wpdb->query($query);

            if ($result !== false) {
                error_log("CityRide Infobip Webhook: Batch updated " . count($ride_ids) . " rides");
            } else {
                error_log("CityRide Infobip Webhook: Batch update failed: " . $wpdb->last_error);
            }
        }

        return new WP_REST_Response('OK', 200);
    }

    /**
     * Maps Infobip status names to internal status values
     *
     * @param string $infobip_status Infobip status groupName
     * @return string Internal status value
     */
    private function map_infobip_status($infobip_status) {
        $status_map = array(
            'PENDING' => 'pending',
            'DELIVERED' => 'delivered',
            'UNDELIVERABLE' => 'failed',
            'REJECTED' => 'rejected',
            'EXPIRED' => 'failed'
        );

        return $status_map[$infobip_status] ?? 'unknown';
    }

    /**
     * Registers the REST API endpoint for n8n to report Infobip message IDs
     */
    public function register_infobip_message_id_endpoint() {
        register_rest_route('cityride/v1', '/infobip-message-id', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_infobip_message_id'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handles n8n callback with Infobip message ID after sending SMS
     * This allows linking the ride to the Infobip message for delivery tracking
     *
     * Expected payload from n8n:
     * {
     *   "booking_id": 123,
     *   "message_id": "abc123def456",
     *   "phone_number": "+38761234567"
     * }
     */
    public function handle_infobip_message_id(WP_REST_Request $request) {
        $payload = $request->get_json_params();

        error_log('CityRide: Received Infobip message ID callback: ' . json_encode($payload));

        // Validate payload
        if (!isset($payload['booking_id']) || !isset($payload['message_id'])) {
            error_log('CityRide: Missing booking_id or message_id in payload');
            return new WP_REST_Response('Missing required fields', 400);
        }

        $booking_id = intval($payload['booking_id']);
        $message_id = sanitize_text_field($payload['message_id']);

        if ($booking_id <= 0 || empty($message_id)) {
            error_log('CityRide: Invalid booking_id or message_id');
            return new WP_REST_Response('Invalid data', 400);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_rides';

        // Update ride with Infobip message ID and set status to pending
        $updated = $wpdb->update(
            $table_name,
            array(
                'infobip_message_id' => $message_id,
                'sms_delivery_status' => 'pending',
                'sms_delivery_updated_at' => current_time('mysql')
            ),
            array('id' => $booking_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            error_log("CityRide: Failed to update booking {$booking_id} with message ID {$message_id}: " . $wpdb->last_error);
            return new WP_REST_Response('Database update failed', 500);
        }

        error_log("CityRide: Successfully linked booking {$booking_id} to Infobip message {$message_id}");
        return new WP_REST_Response('OK', 200);
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

    /**
     * ============================================
     * DRIVER POOL MANAGEMENT - WEEK 3
     * ============================================
     */

    /**
     * Admin page for managing drivers
     */
    public function admin_drivers_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemate dovoljna prava za pristup ovoj stranici.'));
        }
        ?>
        <div class="wrap cityride-admin-page">
            <h1 class="wp-heading-inline">Upravljanje Vozačima</h1>
            <button type="button" class="page-title-action" id="add-driver-btn">Dodaj Novog Vozača</button>
            <hr class="wp-header-end">

            <div id="cityride-admin-message" class="notice" style="display: none;"></div>

            <!-- Driver Statistics Widgets -->
            <div class="cityride-dashboard-widgets" style="margin-bottom: 30px;">
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">🚗</div>
                    <div class="stat-content">
                        <h3>Ukupno Vozača</h3>
                        <p class="stat-number" id="stat-total-drivers">0</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h3>Aktivni Vozači</h3>
                        <p class="stat-number" id="stat-active-drivers">0</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <h3>Ukupna Zarada</h3>
                        <p class="stat-number" id="stat-total-driver-earnings">0.00 BAM</p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">🏆</div>
                    <div class="stat-content">
                        <h3>Najbolji Vozač</h3>
                        <p class="stat-number" id="stat-top-driver">-</p>
                    </div>
                </div>
            </div>

            <!-- Driver Earnings Export Section -->
            <div style="background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
                <h3 style="margin-top: 0;">📊 Izvještaj Zarade Vozača</h3>
                <form id="driver-earnings-export-form" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="export-driver-select">Odaberi Vozača:</label>
                        <select id="export-driver-select" name="driver_id" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="">-- Odaberi vozača --</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 150px;">
                        <label for="export-date-range">Period:</label>
                        <select id="export-date-range" name="date_range" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="this_month">Ovaj Mjesec</option>
                            <option value="last_month">Prošli Mjesec</option>
                            <option value="this_year">Ova Godina</option>
                            <option value="custom">Prilagođeni Period</option>
                        </select>
                    </div>
                    <div class="form-group" id="export-custom-dates" style="display: none; flex: 1; min-width: 200px;">
                        <label for="export-start-date">Od:</label>
                        <input type="date" id="export-start-date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div class="form-group" id="export-custom-dates-to" style="display: none; flex: 1; min-width: 200px;">
                        <label for="export-end-date">Do:</label>
                        <input type="date" id="export-end-date" name="end_date" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <button type="submit" class="button button-primary" style="padding: 8px 20px;">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Preuzmi Izvještaj
                    </button>
                </form>
            </div>

            <!-- Drivers Table -->
            <div class="cityride-table-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ime</th>
                            <th>Telefon</th>
                            <th>Broj Vozila</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th>Ukupno Vožnji</th>
                            <th>Zarada</th>
                            <th>Rejting</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="drivers-table-body">
                        <tr><td colspan="10" style="text-align:center;">Učitavam vozače...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Add/Edit Driver Modal -->
            <div class="cityride-modal" id="driver-modal" style="display: none;">
                <div class="cityride-modal-content">
                    <span class="close" id="close-driver-modal">&times;</span>
                    <h2 id="driver-modal-title">Dodaj Vozača</h2>
                    <form id="driver-form">
                        <input type="hidden" id="driver-id" name="driver_id" value="">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="driver-name">Ime i Prezime *</label>
                                <input type="text" id="driver-name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="driver-phone">Telefon *</label>
                                <input type="tel" id="driver-phone" name="phone" required placeholder="+387 61 234 567">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="driver-vehicle-number">Broj Vozila (Tablica) *</label>
                                <input type="text" id="driver-vehicle-number" name="vehicle_number" required placeholder="SA 123 AB">
                            </div>
                            <div class="form-group">
                                <label for="driver-vehicle-model">Model Vozila</label>
                                <input type="text" id="driver-vehicle-model" name="vehicle_model" placeholder="Škoda Octavia">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="driver-license-number">Broj Vozačke Dozvole</label>
                                <input type="text" id="driver-license-number" name="license_number">
                            </div>
                            <div class="form-group">
                                <label for="driver-status">Status</label>
                                <select id="driver-status" name="status">
                                    <option value="active">Aktivan</option>
                                    <option value="inactive">Neaktivan</option>
                                    <option value="on_break">Na Pauzi</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 20px;">
                            <button type="submit" class="button button-primary">Spremi</button>
                            <button type="button" class="button" id="cancel-driver-btn">Otkaži</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Load all drivers
     */
    public function ajax_load_drivers() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje.');
        }

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $drivers = $wpdb->get_results("SELECT * FROM $drivers_table ORDER BY name ASC");

        // Generate HTML for drivers table
        $drivers_html = '';
        if ($drivers) {
            foreach ($drivers as $driver) {
                $status_class = '';
                $status_label = '';
                switch ($driver->status) {
                    case 'active':
                        $status_class = 'status-assigned';
                        $status_label = 'Aktivan';
                        break;
                    case 'inactive':
                        $status_class = 'status-cancelled';
                        $status_label = 'Neaktivan';
                        break;
                    case 'on_break':
                        $status_class = 'status-unassigned';
                        $status_label = 'Na Pauzi';
                        break;
                }

                $drivers_html .= '<tr>';
                $drivers_html .= '<td data-label="ID">' . esc_html($driver->id) . '</td>';
                $drivers_html .= '<td data-label="Ime">' . esc_html($driver->name) . '</td>';
                $drivers_html .= '<td data-label="Telefon">' . esc_html($driver->phone) . '</td>';
                $drivers_html .= '<td data-label="Broj Vozila"><strong>' . esc_html($driver->vehicle_number) . '</strong></td>';
                $drivers_html .= '<td data-label="Model">' . esc_html($driver->vehicle_model ?: '-') . '</td>';
                $drivers_html .= '<td data-label="Status" class="status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</td>';
                $drivers_html .= '<td data-label="Ukupno Vožnji">' . esc_html($driver->total_rides) . '</td>';
                $drivers_html .= '<td data-label="Zarada">' . esc_html(number_format($driver->total_earnings, 2)) . ' BAM</td>';
                $drivers_html .= '<td data-label="Rejting">⭐ ' . esc_html(number_format($driver->rating, 2)) . '</td>';
                $drivers_html .= '<td data-label="Akcije">';
                $drivers_html .= '<button class="button edit-driver-btn" data-driver-id="' . esc_attr($driver->id) . '">Uredi</button> ';
                $drivers_html .= '<button class="button delete-driver-btn" data-driver-id="' . esc_attr($driver->id) . '" style="background: #dc3545; color: white; border: none;">Obriši</button>';
                $drivers_html .= '</td>';
                $drivers_html .= '</tr>';
            }
        } else {
            $drivers_html = '<tr><td colspan="10" style="text-align:center; padding: 40px;">Nema pronađenih vozača.</td></tr>';
        }

        // Get statistics
        $stats = $this->get_driver_statistics();

        // Prepare drivers array for dropdown population
        $drivers_array = [];
        if ($drivers) {
            foreach ($drivers as $driver) {
                $drivers_array[] = [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'vehicle_number' => $driver->vehicle_number,
                    'phone' => $driver->phone,
                    'status' => $driver->status
                ];
            }
        }

        wp_send_json_success([
            'drivers_html' => $drivers_html,
            'stats' => $stats,
            'drivers' => $drivers_array
        ]);
    }

    /**
     * AJAX: Get single driver for editing
     */
    public function ajax_get_driver() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje.');
        }

        $driver_id = intval($_POST['driver_id']);

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $drivers_table WHERE id = %d", $driver_id));

        if ($driver) {
            wp_send_json_success($driver);
        } else {
            wp_send_json_error('Vozač nije pronađen.');
        }
    }

    /**
     * AJAX: Save driver (create or update)
     */
    public function ajax_save_driver() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje.');
        }

        $driver_id = intval($_POST['driver_id']);
        $name = sanitize_text_field($_POST['name']);
        $phone = sanitize_text_field($_POST['phone']);
        $vehicle_number = sanitize_text_field($_POST['vehicle_number']);
        $vehicle_model = sanitize_text_field($_POST['vehicle_model']);
        $license_number = sanitize_text_field($_POST['license_number']);
        $status = sanitize_text_field($_POST['status']);

        if (empty($name) || empty($phone) || empty($vehicle_number)) {
            wp_send_json_error('Ime, telefon i broj vozila su obavezni.');
        }

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $data = array(
            'name' => $name,
            'phone' => $phone,
            'vehicle_number' => $vehicle_number,
            'vehicle_model' => $vehicle_model,
            'license_number' => $license_number,
            'status' => $status,
            'updated_at' => current_time('mysql')
        );

        if ($driver_id > 0) {
            // Update existing driver
            $result = $wpdb->update(
                $drivers_table,
                $data,
                array('id' => $driver_id),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                wp_send_json_success('Vozač uspješno ažuriran.');
            } else {
                wp_send_json_error('Greška pri ažuriranju vozača: ' . $wpdb->last_error);
            }
        } else {
            // Create new driver
            $result = $wpdb->insert(
                $drivers_table,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result) {
                wp_send_json_success('Vozač uspješno dodan.');
            } else {
                wp_send_json_error('Greška pri dodavanju vozača: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * AJAX: Delete driver
     */
    public function ajax_delete_driver() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje.');
        }

        $driver_id = intval($_POST['driver_id']);

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $deleted = $wpdb->delete($drivers_table, array('id' => $driver_id), array('%d'));

        if ($deleted) {
            wp_send_json_success('Vozač uspješno obrisan.');
        } else {
            wp_send_json_error('Greška pri brisanju vozača.');
        }
    }

    /**
     * AJAX: Get active drivers for dropdown
     */
    public function ajax_get_active_drivers() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate ovlaštenje.');
        }

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $drivers = $wpdb->get_results("SELECT id, name, vehicle_number FROM $drivers_table WHERE status = 'active' ORDER BY name ASC");

        wp_send_json_success($drivers);
    }

    /**
     * AJAX handler: Export driver earnings report
     */
    public function ajax_export_driver_earnings() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nemate ovlaštenje.');
        }

        $driver_id = intval($_POST['driver_id']);
        $date_range = sanitize_text_field($_POST['date_range']);
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');

        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';
        $rides_table = $wpdb->prefix . 'cityride_rides';

        // Get driver info
        $driver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $drivers_table WHERE id = %d", $driver_id));

        if (!$driver) {
            wp_die('Vozač nije pronađen.');
        }

        // Calculate date range
        $tz = new DateTimeZone('Europe/Sarajevo');
        $now = new DateTime('now', $tz);

        switch ($date_range) {
            case 'this_month':
                $start_date = $now->format('Y-m-01');
                $end_date = $now->format('Y-m-t');
                $period_label = $now->format('F Y');
                break;
            case 'last_month':
                $last_month = (clone $now)->modify('-1 month');
                $start_date = $last_month->format('Y-m-01');
                $end_date = $last_month->format('Y-m-t');
                $period_label = $last_month->format('F Y');
                break;
            case 'this_year':
                $start_date = $now->format('Y-01-01');
                $end_date = $now->format('Y-12-31');
                $period_label = $now->format('Y');
                break;
            case 'custom':
                if (empty($start_date) || empty($end_date)) {
                    wp_die('Molimo unesite početni i krajnji datum.');
                }
                $period_label = date('d.m.Y', strtotime($start_date)) . ' - ' . date('d.m.Y', strtotime($end_date));
                break;
            default:
                wp_die('Nepoznat period.');
        }

        // Fetch rides for the driver in the date range
        $rides = $wpdb->get_results($wpdb->prepare("
            SELECT id, address_from, address_to, distance_km, total_price, created_at, status
            FROM $rides_table
            WHERE cab_driver_id = %s
            AND status = 'completed'
            AND created_at >= %s
            AND created_at <= %s
            ORDER BY created_at DESC
        ", $driver->vehicle_number, $start_date . ' 00:00:00', $end_date . ' 23:59:59'));

        // Calculate totals
        $total_rides = count($rides);
        $total_earnings = array_sum(array_column($rides, 'total_price'));
        $avg_per_ride = $total_rides > 0 ? $total_earnings / $total_rides : 0;

        // Generate Excel file
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="izvjestaj-zarade-' . sanitize_file_name($driver->name) . '-' . date('Y-m-d') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
        echo '<body>';
        echo '<table border="1">';

        // Header
        echo '<tr><th colspan="7" style="background: #0073aa; color: white; font-size: 16px; padding: 10px;">Izvještaj Zarade Vozača</th></tr>';
        echo '<tr><td colspan="7">&nbsp;</td></tr>';

        // Driver info
        echo '<tr><td><strong>Vozač:</strong></td><td colspan="6">' . esc_html($driver->name) . '</td></tr>';
        echo '<tr><td><strong>Vozilo:</strong></td><td colspan="6">' . esc_html($driver->vehicle_number) . ' - ' . esc_html($driver->vehicle_model) . '</td></tr>';
        echo '<tr><td><strong>Period:</strong></td><td colspan="6">' . esc_html($period_label) . '</td></tr>';
        echo '<tr><td colspan="7">&nbsp;</td></tr>';

        // Summary
        echo '<tr style="background: #f0f0f0;">';
        echo '<td><strong>Ukupno Vožnji:</strong></td><td>' . $total_rides . '</td>';
        echo '<td><strong>Ukupna Zarada:</strong></td><td>' . number_format($total_earnings, 2) . ' BAM</td>';
        echo '<td><strong>Prosječna Zarada po Vožnji:</strong></td><td colspan="2">' . number_format($avg_per_ride, 2) . ' BAM</td>';
        echo '</tr>';
        echo '<tr><td colspan="7">&nbsp;</td></tr>';

        // Rides table header
        echo '<tr style="background: #0073aa; color: white; font-weight: bold;">';
        echo '<th>ID</th>';
        echo '<th>Datum</th>';
        echo '<th>Vrijeme</th>';
        echo '<th>Od</th>';
        echo '<th>Do</th>';
        echo '<th>Udaljenost (km)</th>';
        echo '<th>Zarada (BAM)</th>';
        echo '</tr>';

        // Rides data
        if ($total_rides > 0) {
            foreach ($rides as $ride) {
                $datetime = new DateTime($ride->created_at, $tz);
                echo '<tr>';
                echo '<td>#' . $ride->id . '</td>';
                echo '<td>' . $datetime->format('d.m.Y') . '</td>';
                echo '<td>' . $datetime->format('H:i') . '</td>';
                echo '<td>' . esc_html($ride->address_from) . '</td>';
                echo '<td>' . esc_html($ride->address_to) . '</td>';
                echo '<td>' . number_format($ride->distance_km, 2) . '</td>';
                echo '<td>' . number_format($ride->total_price, 2) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7" style="text-align: center; color: #999;">Nema vožnji u ovom periodu.</td></tr>';
        }

        echo '</table>';
        echo '</body>';
        echo '</html>';

        exit;
    }

    /**
     * Get driver statistics
     */
    private function get_driver_statistics() {
        return $this->get_cached_data('stats_drivers', function() {
            global $wpdb;
            $drivers_table = $wpdb->prefix . 'cityride_drivers';

            // Consolidated query - 4 queries reduced to 1
            $stats_row = $wpdb->get_row("
                SELECT
                    COUNT(*) as total_drivers,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_drivers,
                    COALESCE(SUM(total_earnings), 0) as total_earnings
                FROM $drivers_table
            ");

            // Get top driver separately due to ORDER BY LIMIT requirement
            $top_driver = $wpdb->get_row("SELECT name, total_rides FROM $drivers_table ORDER BY total_rides DESC LIMIT 1");

            return array(
                'total_drivers' => intval($stats_row->total_drivers),
                'active_drivers' => intval($stats_row->active_drivers),
                'total_earnings' => floatval($stats_row->total_earnings),
                'top_driver' => $top_driver ? $top_driver->name . ' (' . $top_driver->total_rides . ')' : '-'
            );
        }, 3600); // 1 hour cache expiration
    }

    /**
     * Update driver earnings and ride count when ride is completed
     *
     * @param string $vehicle_number Driver's vehicle number
     * @param float $ride_earnings Earnings from the completed ride
     */
    private function update_driver_earnings($vehicle_number, $ride_earnings) {
        global $wpdb;
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $wpdb->query($wpdb->prepare(
            "UPDATE $drivers_table
            SET total_rides = total_rides + 1,
                total_earnings = total_earnings + %f,
                updated_at = %s
            WHERE vehicle_number = %s",
            $ride_earnings,
            current_time('mysql'),
            $vehicle_number
        ));

        error_log("CityRide: Updated driver earnings for vehicle {$vehicle_number}: +{$ride_earnings} BAM");
    }

    /**
     * ============================================
     * ANALYTICS PAGE & FUNCTIONS
     * ============================================
     */

    /**
     * Renders the analytics dashboard page
     */
    public function admin_analytics_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemate dovoljna prava za pristup ovoj stranici.'));
        }

        // Get key metrics
        $metrics = $this->get_key_metrics();
        $revenue_chart_data = $this->get_revenue_chart_data();
        $driver_revenue_data = $this->get_driver_revenue_data();
        $peak_hours_data = $this->get_peak_hours_data();
        $status_distribution = $this->get_status_distribution();

        ?>
        <div class="wrap cityride-admin-page cityride-analytics-page">
            <h1 class="wp-heading-inline">Analitika i Izvještaji</h1>
            <hr class="wp-header-end">

            <!-- Key Metrics Cards -->
            <div class="analytics-metrics-grid">
                <div class="metric-card">
                    <div class="metric-icon">📈</div>
                    <h3>Prihod Danas</h3>
                    <div class="metric-value"><?php echo number_format($metrics['today_revenue'], 2); ?> BAM</div>
                    <div class="metric-change <?php echo $metrics['today_vs_yesterday_change'] >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $metrics['today_vs_yesterday_change'] >= 0 ? '+' : ''; ?><?php echo number_format($metrics['today_vs_yesterday_change'], 1); ?>% u odnosu na juče
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-icon">💰</div>
                    <h3>Ovaj Mjesec</h3>
                    <div class="metric-value"><?php echo number_format($metrics['month_revenue'], 2); ?> BAM</div>
                    <div class="metric-subtext"><?php echo $metrics['month_rides']; ?> vožnji</div>
                </div>

                <div class="metric-card">
                    <div class="metric-icon">🚕</div>
                    <h3>Prosječna Vožnja</h3>
                    <div class="metric-value"><?php echo number_format($metrics['avg_ride_value'], 2); ?> BAM</div>
                    <div class="metric-subtext">Zadnjih 30 dana</div>
                </div>

                <div class="metric-card">
                    <div class="metric-icon">❌</div>
                    <h3>Stopa Otkazivanja</h3>
                    <div class="metric-value"><?php echo number_format($metrics['cancellation_rate'], 1); ?>%</div>
                    <div class="metric-subtext">Zadnjih 30 dana</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="analytics-charts-section">
                <!-- Revenue Chart -->
                <div class="chart-container">
                    <h2>Prihod Zadnjih 30 Dana</h2>
                    <canvas id="revenue-chart"></canvas>
                </div>

                <!-- Driver Revenue Chart -->
                <div class="chart-container">
                    <h2>Prihod po Vozačima (Ovaj Mjesec)</h2>
                    <canvas id="driver-revenue-chart"></canvas>
                </div>

                <!-- Peak Hours Chart -->
                <div class="chart-container">
                    <h2>Rezervacije po Satima (Zadnjih 30 Dana)</h2>
                    <canvas id="peak-hours-chart"></canvas>
                </div>

                <!-- Status Pie Chart -->
                <div class="chart-container">
                    <h2>Distribucija Statusa (Zadnjih 30 Dana)</h2>
                    <canvas id="status-pie-chart"></canvas>
                </div>
            </div>

            <!-- Detailed Reports Section -->
            <div class="analytics-reports-section">
                <h2>Detaljni Izvještaji</h2>

                <form id="analytics-filters" class="cityride-filters-and-controls">
                    <div class="filter-group">
                        <label for="analytics-start-date">Od datuma:</label>
                        <input type="date" id="analytics-start-date" name="start_date" value="<?php echo date('Y-m-01'); ?>" />
                    </div>

                    <div class="filter-group">
                        <label for="analytics-end-date">Do datuma:</label>
                        <input type="date" id="analytics-end-date" name="end_date" value="<?php echo date('Y-m-d'); ?>" />
                    </div>

                    <div class="filter-group">
                        <label for="analytics-group-by">Grupisanje:</label>
                        <select id="analytics-group-by" name="group_by">
                            <option value="day">Po danu</option>
                            <option value="week">Po sedmici</option>
                            <option value="month">Po mjesecu</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="button button-primary">Generiši Izvještaj</button>
                        <button type="button" id="export-analytics-excel" class="button">
                            <span class="dashicons dashicons-download"></span> Izvezi u Excel
                        </button>
                    </div>
                </form>

                <table class="wp-list-table widefat fixed striped" id="analytics-report-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Ukupno Vožnji</th>
                            <th>Završeno</th>
                            <th>Otkazano</th>
                            <th>Prihod (BAM)</th>
                            <th>Prosječna Vožnja</th>
                            <th>Stopa Otkazivanja</th>
                        </tr>
                    </thead>
                    <tbody id="report-table-body">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                Kliknite "Generiši Izvještaj" da vidite detalje...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        // Pass PHP data to JavaScript
        const analyticsData = {
            revenueChart: <?php echo json_encode($revenue_chart_data); ?>,
            driverRevenue: <?php echo json_encode($driver_revenue_data); ?>,
            peakHours: <?php echo json_encode($peak_hours_data); ?>,
            statusDistribution: <?php echo json_encode($status_distribution); ?>
        };
        </script>
        <?php
    }

    /**
     * Get key metrics for analytics dashboard
     */
    private function get_key_metrics() {
        return $this->get_cached_data('stats_key_metrics', function() {
            global $wpdb;
            $table = $wpdb->prefix . 'cityride_rides';

            // Get current date using WordPress timezone
            $current_wp_time = current_time('mysql');
            $today_start = date('Y-m-d 00:00:00', strtotime($current_wp_time));
            $today_end = date('Y-m-d 23:59:59', strtotime($current_wp_time));
            $yesterday_start = date('Y-m-d 00:00:00', strtotime($current_wp_time . ' -1 day'));
            $yesterday_end = date('Y-m-d 23:59:59', strtotime($current_wp_time . ' -1 day'));

            // Today's revenue
            $today_revenue = $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(total_price), 0)
                FROM $table
                WHERE created_at >= %s AND created_at <= %s
                AND status = 'completed'
            ", $today_start, $today_end));

            // Yesterday's revenue
            $yesterday_revenue = $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(total_price), 0)
                FROM $table
                WHERE created_at >= %s AND created_at <= %s
                AND status = 'completed'
            ", $yesterday_start, $yesterday_end));

        // Calculate percentage change
        $today_vs_yesterday_change = 0;
        if ($yesterday_revenue > 0) {
            $today_vs_yesterday_change = (($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100;
        }

        // This month's revenue and rides
        $month_data = $wpdb->get_row("
            SELECT
                COALESCE(SUM(total_price), 0) as revenue,
                COUNT(*) as rides
            FROM $table
            WHERE YEAR(created_at) = YEAR(CURDATE())
            AND MONTH(created_at) = MONTH(CURDATE())
            AND status = 'completed'
        ");

        // Average ride value (last 30 days)
        $avg_ride_value = $wpdb->get_var("
            SELECT COALESCE(AVG(total_price), 0)
            FROM $table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND status = 'completed'
        ");

        // Cancellation rate (last 30 days)
        $cancellation_data = $wpdb->get_row("
            SELECT
                COUNT(*) as total_rides,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_rides
            FROM $table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        $cancellation_rate = 0;
        if ($cancellation_data->total_rides > 0) {
            $cancellation_rate = ($cancellation_data->cancelled_rides / $cancellation_data->total_rides) * 100;
        }

            return array(
                'today_revenue' => floatval($today_revenue),
                'today_vs_yesterday_change' => floatval($today_vs_yesterday_change),
                'month_revenue' => floatval($month_data->revenue),
                'month_rides' => intval($month_data->rides),
                'avg_ride_value' => floatval($avg_ride_value),
                'cancellation_rate' => floatval($cancellation_rate)
            );
        }, 3600); // 1 hour cache expiration
    }

    /**
     * Get revenue chart data (last 30 days)
     */
    private function get_revenue_chart_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'cityride_rides';

        $results = $wpdb->get_results("
            SELECT
                DATE(created_at) as date,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END), 0) as revenue
            FROM $table
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", ARRAY_A);

        $dates = array();
        $revenues = array();

        foreach ($results as $row) {
            $dates[] = date('d.m', strtotime($row['date']));
            $revenues[] = floatval($row['revenue']);
        }

        return array(
            'dates' => $dates,
            'revenues' => $revenues
        );
    }

    /**
     * Get driver revenue data (this month)
     */
    private function get_driver_revenue_data() {
        global $wpdb;
        $rides_table = $wpdb->prefix . 'cityride_rides';
        $drivers_table = $wpdb->prefix . 'cityride_drivers';

        $results = $wpdb->get_results("
            SELECT
                d.name as driver_name,
                d.vehicle_number,
                COUNT(r.id) as ride_count,
                COALESCE(SUM(r.total_price), 0) as total_revenue
            FROM $drivers_table d
            LEFT JOIN $rides_table r ON d.vehicle_number = r.cab_driver_id
                AND r.status = 'completed'
                AND YEAR(r.created_at) = YEAR(CURDATE())
                AND MONTH(r.created_at) = MONTH(CURDATE())
            GROUP BY d.id
            HAVING total_revenue > 0
            ORDER BY total_revenue DESC
            LIMIT 10
        ", ARRAY_A);

        $driver_names = array();
        $revenues = array();

        foreach ($results as $row) {
            $driver_names[] = $row['driver_name'] . ' (' . $row['vehicle_number'] . ')';
            $revenues[] = floatval($row['total_revenue']);
        }

        return array(
            'driver_names' => $driver_names,
            'revenues' => $revenues
        );
    }

    /**
     * Get peak hours data (last 30 days)
     */
    private function get_peak_hours_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'cityride_rides';

        $results = $wpdb->get_results("
            SELECT
                HOUR(created_at) as hour,
                COUNT(*) as booking_count
            FROM $table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY hour
            ORDER BY hour ASC
        ", ARRAY_A);

        // Initialize all hours with 0
        $hours = array();
        $counts = array();
        for ($i = 0; $i < 24; $i++) {
            $hours[$i] = sprintf('%02d:00', $i);
            $counts[$i] = 0;
        }

        // Fill in actual data
        foreach ($results as $row) {
            $hour = intval($row['hour']);
            $counts[$hour] = intval($row['booking_count']);
        }

        return array(
            'hours' => array_values($hours),
            'counts' => array_values($counts)
        );
    }

    /**
     * Get status distribution (last 30 days)
     */
    private function get_status_distribution() {
        global $wpdb;
        $table = $wpdb->prefix . 'cityride_rides';

        $results = $wpdb->get_results("
            SELECT
                status,
                COUNT(*) as count
            FROM $table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY status
        ", ARRAY_A);

        $status_labels = array();
        $status_counts = array();

        // Map status names to Serbian
        $status_map = array(
            'payed_unassigned' => 'Neraspoređeno',
            'payed_assigned' => 'Raspoređeno',
            'completed' => 'Završeno',
            'cancelled' => 'Otkazano',
            'no_show' => 'Nije se pojavio'
        );

        foreach ($results as $row) {
            $status = $row['status'];
            $status_labels[] = isset($status_map[$status]) ? $status_map[$status] : $status;
            $status_counts[] = intval($row['count']);
        }

        return array(
            'labels' => $status_labels,
            'counts' => $status_counts
        );
    }

    /**
     * Admin page for discount code management
     */
    public function admin_discounts_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemate dovoljna prava za pristup ovoj stranici.'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        // Get statistics
        $total_codes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $active_codes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_active = 1");
        $total_usage = $wpdb->get_var("SELECT SUM(usage_count) FROM $table_name");
        ?>
        <div class="wrap cityride-admin-page">
            <h1 class="wp-heading-inline">Kodovi Popusta</h1>
            <button type="button" id="add-discount-code-btn" class="page-title-action">Dodaj novi kod</button>
            <hr class="wp-header-end">

            <!-- Statistics Widgets -->
            <div class="cityride-dashboard-widgets">
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">🎁</div>
                    <div class="stat-content">
                        <h3>Ukupno Kodova</h3>
                        <p class="stat-number"><?php echo $total_codes ?: 0; ?></p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h3>Aktivni Kodovi</h3>
                        <p class="stat-number"><?php echo $active_codes ?: 0; ?></p>
                    </div>
                </div>
                <div class="cityride-dashboard-widget stat-box">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3>Ukupna Upotreba</h3>
                        <p class="stat-number"><?php echo $total_usage ?: 0; ?></p>
                    </div>
                </div>
            </div>

            <!-- Discount Codes Table -->
            <div class="cityride-table-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Tip</th>
                            <th>Vrijednost</th>
                            <th>Min. Iznos</th>
                            <th>Limit</th>
                            <th>Korišteno</th>
                            <th>Važi Od</th>
                            <th>Važi Do</th>
                            <th>Status</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="discount-codes-table-body">
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                <div class="spinner is-active" style="float: none;"></div>
                                Učitavanje kodova...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Add/Edit Discount Code Modal -->
            <div id="discount-code-modal" class="cityride-modal" style="display: none;">
                <div class="cityride-modal-content">
                    <span class="close">&times;</span>
                    <h2 id="discount-code-modal-title">Dodaj Kod Popusta</h2>
                    <div id="discount-code-modal-message" style="display: none;"></div>
                    <form id="discount-code-form">
                        <input type="hidden" id="discount-code-id" name="id">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="discount-code-code">Kod:</label>
                                <input type="text" id="discount-code-code" name="code" required style="text-transform: uppercase;">
                                <p class="description">Unesite jedinstveni kod (npr. SUMMER2026)</p>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="discount-type">Tip Popusta:</label>
                                <select id="discount-type" name="discount_type" required>
                                    <option value="percent">Procenat (%)</option>
                                    <option value="fixed">Fiksni iznos (BAM)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="discount-value">Vrijednost:</label>
                                <input type="number" step="0.01" id="discount-value" name="discount_value" required min="0">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="min-order-amount">Minimalan Iznos Narudžbe (BAM):</label>
                                <input type="number" step="0.01" id="min-order-amount" name="min_order_amount" value="0" min="0">
                            </div>
                            <div class="form-group">
                                <label for="max-discount-amount">Maksimalan Popust (BAM):</label>
                                <input type="number" step="0.01" id="max-discount-amount" name="max_discount_amount" placeholder="Neograničeno">
                                <p class="description">Samo za procentualne popuste</p>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="usage-limit">Limit Upotrebe:</label>
                                <input type="number" id="usage-limit" name="usage_limit" placeholder="Neograničeno" min="1">
                            </div>
                            <div class="form-group">
                                <label>Status:</label>
                                <label style="display: inline-flex; align-items: center;">
                                    <input type="checkbox" id="is-active" name="is_active" value="1" checked>
                                    <span style="margin-left: 5px;">Aktivan</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="valid-from">Važi Od:</label>
                                <input type="datetime-local" id="valid-from" name="valid_from">
                            </div>
                            <div class="form-group">
                                <label for="valid-until">Važi Do:</label>
                                <input type="datetime-local" id="valid-until" name="valid_until">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="button button-primary">Spremi</button>
                            <button type="button" class="button modal-cancel">Odustani</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: Load all discount codes
     */
    public function ajax_load_discount_codes() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        $codes = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC", ARRAY_A);

        wp_send_json_success($codes);
    }

    /**
     * AJAX handler: Get single discount code
     */
    public function ajax_get_discount_code() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $id = intval($_POST['id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        $code = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id), ARRAY_A);

        if ($code) {
            wp_send_json_success($code);
        } else {
            wp_send_json_error('Kod nije pronađen.');
        }
    }

    /**
     * AJAX handler: Save (create/update) discount code
     */
    public function ajax_save_discount_code() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
        $code = strtoupper(trim(sanitize_text_field($_POST['code'])));
        $discount_type = sanitize_text_field($_POST['discount_type']);
        $discount_value = floatval($_POST['discount_value']);
        $min_order_amount = floatval($_POST['min_order_amount']);
        $max_discount_amount = !empty($_POST['max_discount_amount']) ? floatval($_POST['max_discount_amount']) : null;
        $usage_limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $valid_from = !empty($_POST['valid_from']) ? sanitize_text_field($_POST['valid_from']) : null;
        $valid_until = !empty($_POST['valid_until']) ? sanitize_text_field($_POST['valid_until']) : null;

        if (empty($code) || empty($discount_type) || $discount_value <= 0) {
            wp_send_json_error('Nedostaju obavezni podaci.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        // Check for duplicate code
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE code = %s AND id != %d",
            $code,
            $id ?: 0
        ));

        if ($existing) {
            wp_send_json_error('Kod već postoji. Molimo koristite drugi kod.');
        }

        $data = array(
            'code' => $code,
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
            'min_order_amount' => $min_order_amount,
            'max_discount_amount' => $max_discount_amount,
            'usage_limit' => $usage_limit,
            'is_active' => $is_active,
            'valid_from' => $valid_from,
            'valid_until' => $valid_until,
            'updated_at' => current_time('mysql')
        );

        $format = array('%s', '%s', '%f', '%f', '%f', '%d', '%d', '%s', '%s', '%s');

        if ($id) {
            // Update existing code
            $result = $wpdb->update($table_name, $data, array('id' => $id), $format, array('%d'));
            $message = 'Kod popusta uspješno ažuriran!';
        } else {
            // Create new code
            $data['usage_count'] = 0;
            $data['created_at'] = current_time('mysql');
            $format[] = '%d';
            $format[] = '%s';
            $result = $wpdb->insert($table_name, $data, $format);
            $message = 'Kod popusta uspješno kreiran!';
        }

        if ($result !== false) {
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error('Greška pri spremanju koda.');
        }
    }

    /**
     * AJAX handler: Delete discount code
     */
    public function ajax_delete_discount_code() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $id = intval($_POST['id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

        if ($result !== false) {
            wp_send_json_success(array('message' => 'Kod popusta uspješno obrisan!'));
        } else {
            wp_send_json_error('Greška pri brisanju koda.');
        }
    }

    /**
     * AJAX handler: Toggle discount code active status
     */
    public function ajax_toggle_discount_code() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $id = intval($_POST['id']);
        $is_active = intval($_POST['is_active']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'cityride_discount_codes';

        $result = $wpdb->update(
            $table_name,
            array('is_active' => $is_active, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success(array('message' => 'Status koda ažuriran!'));
        } else {
            wp_send_json_error('Greška pri ažuriranju statusa.');
        }
    }

    /**
     * AJAX handler: Get analytics data for detailed reports
     */
    public function ajax_get_analytics_data() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $group_by = sanitize_text_field($_POST['group_by']);

        global $wpdb;
        $table = $wpdb->prefix . 'cityride_rides';

        // Determine date format
        $date_format_map = array(
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m'
        );
        $date_format = isset($date_format_map[$group_by]) ? $date_format_map[$group_by] : '%Y-%m-%d';

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE_FORMAT(created_at, %s) as period,
                COUNT(*) as total_rides,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_rides,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_rides,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END), 0) as total_revenue,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN total_price ELSE NULL END), 0) as avg_ride_value,
                (SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) / COUNT(*) * 100) as cancellation_rate
            FROM $table
            WHERE created_at BETWEEN %s AND %s
            GROUP BY period
            ORDER BY period DESC
        ", $date_format, $start_date . ' 00:00:00', $end_date . ' 23:59:59'), ARRAY_A);

        wp_send_json_success($results);
    }

    /**
     * AJAX handler: Export analytics to Excel
     */
    public function ajax_export_analytics_excel() {
        check_ajax_referer('cityride_admin_nonce', 'nonce');

        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $group_by = sanitize_text_field($_POST['group_by']);

        global $wpdb;
        $table = $wpdb->prefix . 'cityride_rides';

        // Determine date format
        $date_format_map = array(
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m'
        );
        $date_format = isset($date_format_map[$group_by]) ? $date_format_map[$group_by] : '%Y-%m-%d';

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE_FORMAT(created_at, %s) as period,
                COUNT(*) as total_rides,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_rides,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_rides,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END), 0) as total_revenue,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN total_price ELSE NULL END), 0) as avg_ride_value,
                (SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) / COUNT(*) * 100) as cancellation_rate
            FROM $table
            WHERE created_at BETWEEN %s AND %s
            GROUP BY period
            ORDER BY period DESC
        ", $date_format, $start_date . ' 00:00:00', $end_date . ' 23:59:59'), ARRAY_A);

        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="cityride-analytics-' . date('Y-m-d') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Generate Excel HTML table
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
        echo '<body>';
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>Period</th>';
        echo '<th>Ukupno Vožnji</th>';
        echo '<th>Završeno</th>';
        echo '<th>Otkazano</th>';
        echo '<th>Prihod (BAM)</th>';
        echo '<th>Prosječna Vožnja</th>';
        echo '<th>Stopa Otkazivanja (%)</th>';
        echo '</tr>';

        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['period']) . '</td>';
            echo '<td>' . esc_html($row['total_rides']) . '</td>';
            echo '<td>' . esc_html($row['completed_rides']) . '</td>';
            echo '<td>' . esc_html($row['cancelled_rides']) . '</td>';
            echo '<td>' . number_format($row['total_revenue'], 2, ',', '.') . '</td>';
            echo '<td>' . number_format($row['avg_ride_value'], 2, ',', '.') . '</td>';
            echo '<td>' . number_format($row['cancellation_rate'], 1, ',', '.') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '</body></html>';

        exit;
    }
} // <-- Ovo je zatvarajuća zagrada za klasu CityRideBooking

new CityRideBooking();