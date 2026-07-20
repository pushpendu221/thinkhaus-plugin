<?php
/**
 * Plugin Name: Day Pass Booking System
 * Description: Companion plugin for Service/City CPTs. Adds a day pass booking form with custom calendar, seat tracking, and Razorpay integration.
 * Version: 2.0.0
 * Author: Pushpendu
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DPBS_VERSION', '2.0.0' );
define( 'DPBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DPBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* NEW: Private Suites support.
   Service ID 357 = "Private Suites". When this service is selected in the
   Day Pass Booking form, the form switches to Start/End date + Manager Seats
   fields (like the Private Suites Inquiry plugin) and skips Razorpay payment
   entirely - it just records an inquiry-style booking and emails the admin.
   Everything else (regular day-pass services) is completely untouched. */
define( 'DPBS_SUITE_SERVICE_ID', 357 );
define( 'DPBS_DB_VERSION', '3' ); // bump when dpbs_bookings schema changes

/* NEW: Branded "From" name/email for booking emails ------------------------
   By default wp_mail() sends as "WordPress <wordpress@yourdomain.com>",
   which is why booking emails were showing up as "WordPress" instead of
   "ThinkHaus". dpbs_send_mail() below wraps wp_mail() and temporarily
   applies these From values ONLY for the duration of that single send, so
   nothing else on the site (other plugins/WP core emails) is affected. */
define( 'DPBS_MAIL_FROM_NAME', 'ThinkHaus' );
define( 'DPBS_MAIL_FROM_EMAIL', 'no-reply@thinkhaus.co.in' );

function dpbs_mail_from_email( $original ) { return DPBS_MAIL_FROM_EMAIL; }
function dpbs_mail_from_name( $original ) { return DPBS_MAIL_FROM_NAME; }

/**
 * Drop-in replacement for wp_mail() used everywhere in this plugin, so all
 * booking-related emails (admin notifications + customer confirmations)
 * show up as "ThinkHaus <no-reply@thinkhaus.co.in>" instead of the default
 * "WordPress <wordpress@yourdomain.com>".
 *
 * NOTE: for this to land in inboxes (not spam) and not get silently
 * rewritten by the mail server, no-reply@thinkhaus.co.in should be a real
 * address on the thinkhaus.co.in domain, and the site should be sending
 * through an SMTP service (e.g. WP Mail SMTP + a provider like SES/SendGrid)
 * with SPF/DKIM configured for thinkhaus.co.in. Without that, some mail
 * servers ignore the From name/email set here and substitute their own.
 */
function dpbs_send_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    add_filter( 'wp_mail_from', 'dpbs_mail_from_email' );
    add_filter( 'wp_mail_from_name', 'dpbs_mail_from_name' );
    $sent = wp_mail( $to, $subject, $message, $headers, $attachments );
    remove_filter( 'wp_mail_from', 'dpbs_mail_from_email' );
    remove_filter( 'wp_mail_from_name', 'dpbs_mail_from_name' );
    return $sent;
}

/* NEW: WhatsApp notifications via AiSensy -----------------------------------
   Sends the booking confirmation as a WhatsApp message alongside the email,
   using AiSensy's Campaign API. Requires an AiSensy account with an approved
   message template ("campaign") - see the WhatsApp Notifications card on
   the plugin's Settings page. Entirely additive and fails silently (logged,
   not shown to the customer) if not configured, so it can never block a
   booking or break the existing email flow.

   $template_params must be an ordered array of strings matching the {{1}},
   {{2}}... placeholders in the approved AiSensy template, in order. */
function dpbs_send_whatsapp_message( $phone, $campaign_name, $template_params, $guest_name = '' ) {
    if ( get_option( 'dpbs_whatsapp_enabled', 'no' ) !== 'yes' ) return false;

    $api_key = get_option( 'dpbs_aisensy_api_key' );
    if ( empty( $api_key ) || empty( $campaign_name ) ) return false;

    // Normalize to E.164-ish digits-only with country code (defaults to 91/India
    // if a 10-digit local number was entered without one).
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen( $digits ) === 10 ) $digits = '91' . $digits;
    if ( empty( $digits ) ) return false;

    $body = array(
        'apiKey'       => $api_key,
        'campaignName' => $campaign_name,
        'destination'  => $digits,
        'userName'     => $guest_name,
        'templateParams' => array_values( $template_params ),
    );

    $response = wp_remote_post( 'https://backend.aisensy.com/campaign/t1/api/v2', array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( 'DPBS: WhatsApp send failed - ' . $response->get_error_message() );
        return false;
    }
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        error_log( 'DPBS: WhatsApp send returned HTTP ' . $code . ' - ' . wp_remote_retrieve_body( $response ) );
        return false;
    }
    return true;
}

/* NEW: Tax system. Adds subtotal_amount/tax_amount/tax_percent columns to
   dpbs_bookings (via the existing dpbs_maybe_upgrade_db auto-migration) and
   a Tax Settings card on the Settings page. When enabled, tax is calculated
   on top of the existing price logic and is included in the amount actually
   charged via Razorpay - existing pricing (dpbs_calculate_price) and every
   other flow (Private Suites inquiry, calendar, admin UI) is untouched
   unless tax is explicitly turned on in Settings. Defaults to disabled. */

function dpbs_is_suite_service( $service_id ) {
    return intval( $service_id ) === DPBS_SUITE_SERVICE_ID;
}

/* ==========================================================================
   1. CALENDAR & PRICE LOGIC
   ========================================================================== */

class DPBS_Calendar {
    const STATUS_AVAILABLE = 'available';
    const STATUS_LIMITED   = 'limited';
    const STATUS_FULL      = 'full';

    protected $form_slug;
    public function __construct( $form_slug ) { $this->form_slug = $form_slug; }
    protected function option_key() { return 'dpbs_calendar_' . $this->form_slug; }
    public function get_all() {
        $data = get_option( $this->option_key(), array() );
        return is_array( $data ) ? $data : array();
    }
    public function set_status( $date, $status ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;
        $all = $this->get_all();
        if ( self::STATUS_AVAILABLE === $status ) unset( $all[ $date ] );
        elseif ( in_array( $status, array( self::STATUS_LIMITED, self::STATUS_FULL ), true ) ) $all[ $date ] = $status;
        else return false;
        return update_option( $this->option_key(), $all, false );
    }
    public function get_status( $date ) {
        $all = $this->get_all();
        return isset( $all[ $date ] ) ? $all[ $date ] : self::STATUS_AVAILABLE;
    }
}

function dpbs_calculate_price( $service_id, $location_id ) {
    $loc_prices = get_option('dpbs_location_prices', array());
    $svc_prices = get_option('dpbs_service_prices', array());
    $global_price = get_option('dpbs_default_price', '0.00');

    $loc_key = $service_id . '_' . $location_id;
    if ( isset($loc_prices[$loc_key]) && $loc_prices[$loc_key] !== '' ) {
        return floatval($loc_prices[$loc_key]);
    } elseif ( isset($svc_prices[$service_id]) && $svc_prices[$service_id] !== '' ) {
        return floatval($svc_prices[$service_id]);
    } else {
        return floatval($global_price);
    }
}

/* NEW: Tax helpers ---------------------------------------------------------
   Tax is OFF by default (dpbs_tax_enabled option defaults to 'no'), so on
   any existing live site nothing changes until the admin explicitly enables
   it from Settings > Tax Settings. */
function dpbs_tax_enabled() {
    return get_option( 'dpbs_tax_enabled', 'no' ) === 'yes';
}
function dpbs_get_tax_rate() {
    $rate = floatval( get_option( 'dpbs_tax_percent', '18' ) );
    return $rate > 0 ? $rate : 0.0;
}
function dpbs_get_tax_label() {
    $label = trim( (string) get_option( 'dpbs_tax_label', 'GST' ) );
    return $label !== '' ? $label : 'GST';
}

/**
 * Central place that computes subtotal, tax and grand total for a booking.
 * $multiplier is whatever the existing price is multiplied by for that flow
 * (seats for regular day-pass bookings, seats * months for Private Suites).
 * Returns amounts rounded to 2 decimals, matching the decimal(10,2) columns.
 */
function dpbs_calculate_totals( $service_id, $location_id, $multiplier ) {
    $unit_price = dpbs_calculate_price( $service_id, $location_id );
    $subtotal   = round( $unit_price * floatval( $multiplier ), 2 );

    $tax_rate   = dpbs_tax_enabled() ? dpbs_get_tax_rate() : 0.0;
    $tax_amount = $tax_rate > 0 ? round( $subtotal * ( $tax_rate / 100 ), 2 ) : 0.00;
    $total      = round( $subtotal + $tax_amount, 2 );

    return array(
        'unit_price'  => $unit_price,
        'subtotal'    => $subtotal,
        'tax_label'   => dpbs_get_tax_label(),
        'tax_rate'    => $tax_rate,
        'tax_amount'  => $tax_amount,
        'total'       => $total,
    );
}

/**
 * Resolve a post ID from either a numeric ID or a slug/name
 */
function dpbs_resolve_post_id( $value, $post_type = 'city' ) {
    if ( empty( $value ) ) return '';
    
    // If numeric, use as post ID directly
    if ( is_numeric( $value ) ) {
        return intval( $value );
    }
    
    // Try to find by slug (post_name)
    $posts = get_posts( array(
        'post_type'      => $post_type,
        'name'           => sanitize_title( $value ),
        'numberposts'    => 1,
        'post_status'    => 'publish',
    ) );
    
    if ( ! empty( $posts ) ) {
        return $posts[0]->ID;
    }
    
    // Fallback: try by post title
    $posts = get_posts( array(
        'post_type'      => $post_type,
        'title'          => sanitize_text_field( $value ),
        'numberposts'    => 1,
        'post_status'    => 'publish',
    ) );
    
    if ( ! empty( $posts ) ) {
        return $posts[0]->ID;
    }
    
    return '';
}

/* NEW: Customer confirmation email -----------------------------------------
   Directions link: uses the location's "google_location" custom field
   (set on the city CPT post) as the Google Maps link when present, with a
   few other commonly-used key names checked as a fallback, and finally
   falls back to a Google Maps search link built from the location/city
   name if nothing has been set at all. */
function dpbs_get_location_maps_link( $location_id, $city_id = 0 ) {
    $meta_keys = array( 'google_location', 'google_maps_link', '_dpbs_google_maps_link', 'maps_link', 'directions_link', 'map_url' );
    foreach ( $meta_keys as $key ) {
        $val = get_post_meta( $location_id, $key, true );
        if ( ! empty( $val ) ) return esc_url_raw( $val );
    }
    $query_parts = array( get_the_title( $location_id ) );
    if ( $city_id ) $query_parts[] = get_the_title( $city_id );
    $query = implode( ', ', array_filter( $query_parts ) );
    /* NEW: lets a site owner override the maps link logic entirely (e.g. to
       pull from their own address field) without touching this file. */
    return apply_filters( 'dpbs_location_maps_link', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query ), $location_id, $city_id );
}

/**
 * Sends the "your booking is confirmed" email to the customer (guest),
 * separate from and in addition to the existing admin notification email.
 * $duration is a plain string, e.g. "Full Day" or "3 Days", so both the
 * regular day-pass flow and the Private Suites inquiry flow can reuse it.
 */
function dpbs_send_customer_confirmation_email( $args ) {
    $guest_name  = $args['guest_name'];
    $email       = $args['email'];
    $date        = $args['date'];
    $service_id  = $args['service_id'];
    $duration    = $args['duration'];
    $seats       = $args['seats'];
    $location_id = $args['location_id'];
    $city_id     = isset( $args['city_id'] ) ? $args['city_id'] : 0;

    if ( ! is_email( $email ) ) return false;

    $maps_link = dpbs_get_location_maps_link( $location_id, $city_id );

    $subject = 'Your ThinkHaus Booking is Confirmed';
    $message  = "Hi {$guest_name},\n\n";
    $message .= "Your booking at ThinkHaus is confirmed!\n\n";
    $message .= "Date of Booking: {$date}\n";
    $message .= "Service Booked: " . get_the_title( $service_id ) . "\n";
    $message .= "Duration: {$duration}\n";
    $message .= "Number of Seats: {$seats}\n";
    $message .= "Location: " . get_the_title( $location_id ) . "\n";
    $message .= "Directions: {$maps_link}\n\n";
    $message .= "We look forward to welcoming you to ThinkHaus — Built for what's next.";

    return dpbs_send_mail( $email, $subject, $message );
}

/**
 * Sends the "we received your inquiry" email to the customer for a Private
 * Suites inquiry. Deliberately separate from dpbs_send_customer_confirmation_email()
 * above: a suite inquiry has no payment, a date *range* instead of a single
 * date, and a couple of suite-only fields (Company, Manager Seats), so it
 * needs its own wording and field list rather than reusing the paid-booking
 * "confirmed" template.
 */
function dpbs_send_suite_inquiry_customer_email( $args ) {
    $guest_name   = $args['guest_name'];
    $email        = $args['email'];
    $company      = $args['company'];
    $service_id   = $args['service_id'];
    $start_date   = $args['start_date'];
    $end_date     = $args['end_date'];
    $duration_days = $args['duration_days'];
    $months       = $args['months'];
    $seats        = $args['seats'];
    $manager_seats = $args['manager_seats'];
    $location_id  = $args['location_id'];
    $city_id      = isset( $args['city_id'] ) ? $args['city_id'] : 0;
    $totals       = $args['totals'];

    if ( ! is_email( $email ) ) return false;

    $maps_link = dpbs_get_location_maps_link( $location_id, $city_id );

    $subject = 'We\'ve Received Your ThinkHaus Private Suite Inquiry';
    $message  = "Hi {$guest_name},\n\n";
    $message .= "Thank you for your interest in ThinkHaus Private Suites! We've received your inquiry and our team will get back to you within 24 hours to confirm availability and finalize the details.\n\n";
    $message .= "Here's a summary of your inquiry:\n\n";
    $message .= "Service: " . get_the_title( $service_id ) . "\n";
    if ( ! empty( $company ) ) {
        $message .= "Company: {$company}\n";
    }
    $message .= "Start Date: {$start_date}\n";
    $message .= "End Date: {$end_date}\n";
    $message .= "Duration: {$duration_days} day" . ( $duration_days > 1 ? 's' : '' ) . " ({$months} month" . ( $months > 1 ? 's' : '' ) . ")\n";
    $message .= "Number of Seats: {$seats}\n";
    $message .= "Manager Seats: " . ( $manager_seats ?: 'No' ) . "\n";
    $message .= "Location: " . get_the_title( $location_id ) . "\n";
    $message .= "Directions: {$maps_link}\n\n";
    $message .= "Estimated Subtotal: ₹" . number_format( $totals['subtotal'], 2 ) . "\n";
    if ( $totals['tax_amount'] > 0 ) {
        $message .= "Estimated " . $totals['tax_label'] . " ({$totals['tax_rate']}%): ₹" . number_format( $totals['tax_amount'], 2 ) . "\n";
    }
    $message .= "Estimated Total: ₹" . number_format( $totals['total'], 2 ) . "\n\n";
    $message .= "Please note: this is an inquiry, not a confirmed booking. No payment has been collected, and your Private Suite will be confirmed once our team reaches out to you.\n\n";
    $message .= "We look forward to welcoming you to ThinkHaus — Built for what's next.";

    return dpbs_send_mail( $email, $subject, $message );
}

/**
 * Get the service IDs available at a given location (child 'city' post).
 * Reads the '_csm_service_records' meta written by the Service Details
 * Meta Box (CSM) plugin - a JSON blob keyed by service post ID.
 */
function dpbs_get_service_ids_for_location( $location_id ) {
    $raw = get_post_meta( $location_id, '_csm_service_records', true );
    $records = array();

    if ( is_string( $raw ) ) {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) $records = $decoded;
    } elseif ( is_array( $raw ) ) {
        $records = $raw;
    } elseif ( is_object( $raw ) ) {
        $records = json_decode( wp_json_encode( $raw ), true );
    }

    if ( empty( $records ) || ! is_array( $records ) ) return array();

    return array_map( 'intval', array_keys( $records ) );
}

/**
 * Whether a given location offers a given service.
 */
function dpbs_location_offers_service( $location_id, $service_id ) {
    if ( ! $service_id ) return true; // no service filter requested
    return in_array( intval( $service_id ), dpbs_get_service_ids_for_location( $location_id ), true );
}

/**
 * Whether a given city (parent) has at least one child location offering the service.
 */
function dpbs_city_offers_service( $city_id, $service_id ) {
    if ( ! $service_id ) return true;
    $children = get_posts( array(
        'post_type'      => 'city',
        'post_parent'    => $city_id,
        'numberposts'    => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ) );
    foreach ( $children as $loc_id ) {
        if ( dpbs_location_offers_service( $loc_id, $service_id ) ) return true;
    }
    return false;
}

/* ==========================================================================
   2. DATABASE CREATION
   ========================================================================== */

register_activation_hook( __FILE__, 'dpbs_create_custom_tables' );
function dpbs_create_custom_tables() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    $charset_collate = $wpdb->get_charset_collate();

    $table_bookings = $wpdb->prefix . 'dpbs_bookings';
    $sql = "CREATE TABLE $table_bookings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        service_id mediumint(9) NOT NULL,
        city_id mediumint(9) NOT NULL,
        location_id mediumint(9) NOT NULL,
        booking_date date NOT NULL,
        end_date date DEFAULT NULL,
        seats int NOT NULL,
        total_amount decimal(10,2) NOT NULL,
        subtotal_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        tax_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        tax_percent decimal(5,2) NOT NULL DEFAULT 0.00,
        customer_name varchar(255) NOT NULL,
        customer_email varchar(255) NOT NULL,
        customer_phone varchar(50) NOT NULL,
        customer_company varchar(255),
        manager_seats varchar(10) DEFAULT NULL,
        razorpay_order_id varchar(255),
        razorpay_payment_id varchar(255),
        status varchar(20) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql );
}

/* NEW: Runs dbDelta again (idempotent) on already-installed sites so the
   `end_date` and `manager_seats` columns get added without requiring the
   admin to deactivate/reactivate the plugin. Does nothing once up to date. */
add_action( 'plugins_loaded', 'dpbs_maybe_upgrade_db' );
function dpbs_maybe_upgrade_db() {
    if ( get_option( 'dpbs_db_version' ) === DPBS_DB_VERSION ) {
        return;
    }
    dpbs_create_custom_tables();
    update_option( 'dpbs_db_version', DPBS_DB_VERSION );
}



/* ==========================================================================
   3. ADMIN MENU & SETTINGS PAGE
   ========================================================================== */

add_action( 'admin_menu', 'dpbs_admin_menu' );
function dpbs_admin_menu() {
    add_menu_page( 'Day Pass Bookings', 'Day Pass Bookings', 'manage_options', 'dpbs-bookings', 'dpbs_bookings_page', 'dashicons-calendar-alt', 26 );
    add_submenu_page( 'dpbs-bookings', 'Settings & Calendar', 'Settings & Calendar', 'manage_options', 'dpbs-settings', 'dpbs_settings_page' );
}

function dpbs_settings_page() {
    $services = get_posts( array( 'post_type' => 'service', 'numberposts' => -1 ) );
    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1 ) );
    
    $svc_prices = get_option('dpbs_service_prices', array());
    $loc_prices = get_option('dpbs_location_prices', array());
    ?>
    <div class="cwf-admin-wrap">
        <div class="cwf-admin-header">
            <h1>Day Pass Booking</h1>
            <span class="cwf-admin-header-sub">Settings & Calendar</span>
        </div>
        
        <div class="cwf-admin-columns">
            <div class="cwf-admin-main">
                <form method="post" action="options.php">
                    <div class="cwf-card">
                        <div class="cwf-card-title">General</div>
                        <?php settings_fields( 'dpbs_settings_group' ); ?>
                        <div class="cwf-form-row">
                            <label>Admin Email</label>
                            <div class="cwf-form-row-control">
                                <input type="email" name="dpbs_admin_email" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_admin_email', get_option('admin_email')) ); ?>" required />
                                <p class="description">Email address where new booking notifications will be sent.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Razorpay Key ID</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_razorpay_key" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_razorpay_key') ); ?>" required />
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Razorpay Secret</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_razorpay_secret" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_razorpay_secret') ); ?>" required />
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Global Default Price (₹)</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_default_price" step="0.01" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_default_price', '500.00') ); ?>" required />
                                <p class="description">Fallback price if no service or location price is set.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Default Total Seats</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_default_seats" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_default_seats', '10') ); ?>" required />
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Limited Seats Threshold</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_limited_seats" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_limited_seats', '3') ); ?>" required />
                                <p class="description">If available seats fall to/below this number, date shows a Blue Dot automatically.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Private Suites Min Stay (days)</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_suite_min_days" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_suite_min_days', '1') ); ?>" min="1" />
                                <p class="description">Applies only to Service ID <?php echo DPBS_SUITE_SERVICE_ID; ?> (Private Suites), which uses a Start/End date range instead of a single day.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Private Suites Max Stay (days)</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_suite_max_days" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_suite_max_days', '30') ); ?>" min="1" />
                            </div>
                        </div>
                        <div class="cwf-card-footer">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </div>

                    <!-- NEW: Tax Settings -->
                    <div class="cwf-card">
                        <div class="cwf-card-title">Tax Settings</div>
                        <p class="description" style="margin-bottom: 20px;">When enabled, tax is calculated on the booking subtotal and included in the amount charged to the customer via Razorpay.</p>
                        <div class="cwf-form-row">
                            <label>Enable Tax</label>
                            <div class="cwf-form-row-control">
                                <input type="hidden" name="dpbs_tax_enabled" value="no" />
                                <label style="font-weight:normal;">
                                    <input type="checkbox" name="dpbs_tax_enabled" value="yes" <?php checked( get_option( 'dpbs_tax_enabled', 'no' ), 'yes' ); ?> />
                                    Collect tax on bookings
                                </label>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Tax Label</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_tax_label" class="regular-text" value="<?php echo esc_attr( get_option( 'dpbs_tax_label', 'GST' ) ); ?>" placeholder="GST" />
                                <p class="description">Shown to customers and in admin (e.g. GST, VAT, Service Tax).</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Tax Percentage (%)</label>
                            <div class="cwf-form-row-control">
                                <input type="number" name="dpbs_tax_percent" step="0.01" min="0" max="100" class="regular-text" value="<?php echo esc_attr( get_option( 'dpbs_tax_percent', '18' ) ); ?>" />
                                <p class="description">Applied to the subtotal (price × seats, or price × seats × months for Private Suites).</p>
                            </div>
                        </div>
                        <div class="cwf-card-footer">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </div>

                    <div class="cwf-card">
                        <div class="cwf-card-title">WhatsApp Notifications (AiSensy)</div>
                        <p class="description" style="margin-bottom: 20px;">Sends the same booking confirmation as a WhatsApp message, in addition to the email. Requires an AiSensy account with an approved template ("campaign") for each message below. See <a href="https://docs.aisensy.com/" target="_blank" rel="noopener">AiSensy's docs</a> for creating a WABA + template.</p>
                        <div class="cwf-form-row">
                            <label>Enable WhatsApp Messages</label>
                            <div class="cwf-form-row-control">
                                <input type="hidden" name="dpbs_whatsapp_enabled" value="no" />
                                <label style="font-weight:normal;">
                                    <input type="checkbox" name="dpbs_whatsapp_enabled" value="yes" <?php checked( get_option( 'dpbs_whatsapp_enabled', 'no' ), 'yes' ); ?> />
                                    Also send booking confirmations via WhatsApp
                                </label>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>AiSensy API Key</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_aisensy_api_key" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_aisensy_api_key') ); ?>" />
                                <p class="description">From AiSensy dashboard → Manage → API Keys.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Day Pass Campaign Name</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_aisensy_campaign_daypass" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_aisensy_campaign_daypass') ); ?>" placeholder="e.g. daypass_booking_confirmed" />
                                <p class="description">Name of the approved AiSensy template used for regular (paid) Day Pass confirmations.</p>
                            </div>
                        </div>
                        <div class="cwf-form-row">
                            <label>Private Suites Campaign Name</label>
                            <div class="cwf-form-row-control">
                                <input type="text" name="dpbs_aisensy_campaign_suite" class="regular-text" value="<?php echo esc_attr( get_option('dpbs_aisensy_campaign_suite') ); ?>" placeholder="e.g. suite_inquiry_received" />
                                <p class="description">Name of the approved AiSensy template used for Private Suites inquiries.</p>
                            </div>
                        </div>
                        <div class="cwf-card-footer">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </div>
                </form>
                
                <div class="cwf-card">
                    <div class="cwf-card-title">Service Default Pricing</div>
                    <p class="description" style="margin-bottom: 20px;">Select a service and set a default price for it. This overrides the global price.</p>
                    <div class="cwf-form-row" style="border-bottom: none; padding-bottom: 0;">
                        <label>Add Price Rule</label>
                        <div class="cwf-form-row-control" style="display:flex; gap:10px; align-items:center;">
                            <!-- CHANGED: Already had placeholder, kept consistent -->
                            <select id="svc_price_service" style="flex:1;">
                                <option value="">— Select Service —</option>
                                <?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?>
                            </select>
                            <input type="number" id="svc_price_amount" placeholder="₹ Price" style="width:120px;" />
                            <button type="button" class="button" id="save_svc_price">Add / Update</button>
                        </div>
                    </div>
                    <ul class="cwf-price-list" id="svc_price_list">
                        <?php foreach($svc_prices as $sid => $price): 
                            if(!get_post_status($sid)) continue; ?>
                            <li>
                                <span class="cwf-price-rule-name"><?php echo get_the_title($sid); ?></span>
                                <span class="cwf-price-rule-value">₹<?php echo esc_html($price); ?></span>
                                <a href="#" class="cwf-price-delete" data-type="svc" data-id="<?php echo esc_attr($sid); ?>">Delete</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($svc_prices)): ?><li class="cwf-price-empty">No service prices set yet.</li><?php endif; ?>
                    </ul>
                </div>

                <div class="cwf-card">
                    <div class="cwf-card-title">Specific Location Pricing</div>
                    <p class="description" style="margin-bottom: 20px;">Set exact prices for a Service at a specific Location. This overrides the service price.</p>
                    <div class="cwf-form-row" style="border-bottom: none; padding-bottom: 0;">
                        <label>Add Price Rule</label>
                        <div class="cwf-form-row-control" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                            <select id="loc_price_service" style="flex:1; min-width: 150px;">
                                <option value="">— Select Service —</option>
                                <?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?>
                            </select>
                            <!-- CHANGED: Added "Select City" placeholder -->
                            <select id="loc_price_city" style="flex:1; min-width: 120px;">
                                <option value="">— Select City —</option>
                                <?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?>
                            </select>
                            <select id="loc_price_location" style="flex:1; min-width: 150px;"><option value="">— Select Location —</option></select>
                            <input type="number" id="loc_price_amount" placeholder="₹ Price" style="width:120px;" />
                            <button type="button" class="button" id="save_loc_price">Add / Update</button>
                        </div>
                    </div>
                    <ul class="cwf-price-list" id="loc_price_list">
                        <?php foreach($loc_prices as $key => $price): 
                            list($sid, $lid) = explode('_', $key);
                            if(!get_post_status($sid) || !get_post_status($lid)) continue; ?>
                            <li>
                                <span class="cwf-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span>
                                <span class="cwf-price-rule-value">₹<?php echo esc_html($price); ?></span>
                                <a href="#" class="cwf-price-delete" data-type="loc" data-id="<?php echo esc_attr($key); ?>">Delete</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($loc_prices)): ?><li class="cwf-price-empty">No location prices set yet.</li><?php endif; ?>
                    </ul>
                </div>

                <div class="cwf-card">
                    <div class="cwf-card-title-row">
                        <div class="cwf-card-title">Calendar Availability</div>
                        <span class="cwf-autosave-indicator" id="cwf-save-indicator"></span>
                    </div>
                    
                    <!-- CHANGED: Added click-instruction notice -->
                    <div class="cwf-cal-notice">
                        <span class="cwf-cal-notice-icon dashicons dashicons-info"></span>
                        <ul>
                            <li><strong>Single click</strong> on a date → Mark as <span class="cwf-notice-limited">Partially Booked</span> (Blue dot)</li>
                            <li><strong>Double click</strong> on a date → Mark as <span class="cwf-notice-full">Blocked / Fully Booked</span> (Red dot)</li>
                            <li><strong>Triple click (or more)</strong> on a date → <span class="cwf-notice-reset">Reset to Available</span> (Remove all status)</li>
                        </ul>
                    </div>

                    <div class="cwf-form-row">
                        <label>Service & Location</label>
                        <div class="cwf-form-row-control" style="display:flex; gap:10px;">
                            <!-- CHANGED: Added "Select Service" placeholder -->
                            <select id="admin_service" style="flex:1;">
                                <option value="">— Select Service —</option>
                                <?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?>
                            </select>
                            <!-- CHANGED: Added "Select City" placeholder -->
                            <select id="admin_city" style="flex:1;">
                                <option value="">— Select City —</option>
                                <?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?>
                            </select>
                            <select id="admin_location" style="flex:1;"><option value="">— Select Location —</option></select>
                        </div>
                    </div>

                    <div class="cwf-legend">
                        <span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-available"></span>Available</span>
                        <span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-limited"></span>Limited Seats</span>
                        <span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-full"></span>Blocked / Full</span>
                    </div>
                    
                    <div id="cwf-admin-calendar">
                        <div class="cwf-cal-loading">Select a location to load calendar...</div>
                    </div>
                </div>
            </div>
            
            <div class="cwf-admin-side">
                <div class="cwf-card cwf-card-compact">
                    <div class="cwf-card-title">Shortcode</div>
                    <code class="cwf-shortcode-box">[book_day_pass]</code>
                </div>
                <div class="cwf-card cwf-card-compact">
                    <div class="cwf-card-title">Quick Links</div>
                    <p><a href="<?php echo admin_url('admin.php?page=dpbs-bookings'); ?>">View Bookings</a></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

add_action( 'admin_init', 'dpbs_register_settings' );
function dpbs_register_settings() {
    register_setting( 'dpbs_settings_group', 'dpbs_razorpay_key' );
    register_setting( 'dpbs_settings_group', 'dpbs_razorpay_secret' );
    register_setting( 'dpbs_settings_group', 'dpbs_admin_email' );
    register_setting( 'dpbs_settings_group', 'dpbs_default_price' );
    register_setting( 'dpbs_settings_group', 'dpbs_default_seats' );
    register_setting( 'dpbs_settings_group', 'dpbs_limited_seats' );
    register_setting( 'dpbs_settings_group', 'dpbs_suite_min_days' );
    register_setting( 'dpbs_settings_group', 'dpbs_suite_max_days' );
    /* NEW: Tax settings */
    register_setting( 'dpbs_settings_group', 'dpbs_tax_enabled', array( 'sanitize_callback' => 'dpbs_sanitize_tax_enabled' ) );
    register_setting( 'dpbs_settings_group', 'dpbs_tax_label' );
    register_setting( 'dpbs_settings_group', 'dpbs_tax_percent' );

    register_setting( 'dpbs_settings_group', 'dpbs_whatsapp_enabled', array( 'sanitize_callback' => 'dpbs_sanitize_tax_enabled' ) );
    register_setting( 'dpbs_settings_group', 'dpbs_aisensy_api_key' );
    register_setting( 'dpbs_settings_group', 'dpbs_aisensy_campaign_daypass' );
    register_setting( 'dpbs_settings_group', 'dpbs_aisensy_campaign_suite' );
}

/* Checkboxes only POST a value when checked, so without this an unchecked
   box would leave the option untouched instead of turning tax off. */
function dpbs_sanitize_tax_enabled( $value ) {
    return ( $value === 'yes' ) ? 'yes' : 'no';
}

/* ==========================================================================
   4. ADMIN ASSETS
   ========================================================================== */

add_action( 'admin_enqueue_scripts', 'dpbs_enqueue_admin_assets' );
function dpbs_enqueue_admin_assets( $hook ) {
    /* CHANGED: Also load admin assets on the top-level Bookings list page
       (previously only loaded on the Settings & Calendar submenu, so the
       bookings table had zero JS - no export, no delete). */
    $dpbs_admin_hooks = array( 'day-pass-bookings_page_dpbs-settings', 'toplevel_page_dpbs-bookings' );
    if ( ! in_array( $hook, $dpbs_admin_hooks, true ) ) return;

    wp_enqueue_style( 'dpbs-admin-css', DPBS_PLUGIN_URL . 'assets/css/admin.css', array(), DPBS_VERSION );
    wp_enqueue_script( 'dpbs-admin-js', DPBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), DPBS_VERSION, true );
    wp_localize_script( 'dpbs-admin-js', 'dpbs_admin_obj', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'dpbs_admin_nonce' ),
        'confirm_delete_one'  => 'Delete this booking? This cannot be undone.',
        'confirm_delete_bulk' => 'Delete the selected bookings? This cannot be undone.',
        'no_selection'        => 'Please select at least one booking to delete.'
    ));
}

/* ==========================================================================
   5. ADMIN BOOKINGS LIST (With Razorpay Details)
   ========================================================================== */

function dpbs_bookings_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'dpbs_bookings';
    $bookings = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
    $export_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=dpbs_export_csv' ), 'dpbs_admin_nonce', 'nonce' );
    ?>
    <div class="wrap cwf-admin-wrap">
        <div class="cwf-admin-header">
            <h1>Day Pass Bookings</h1>
            <span class="cwf-admin-header-sub"><?php echo count( $bookings ); ?> total</span>
        </div>

        <div class="cwf-card">
            <div class="cwf-bookings-toolbar">
                <div class="cwf-bookings-bulk">
                    <select id="dpbs_bulk_action">
                        <option value="">Bulk actions</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="button" class="button" id="dpbs_bulk_apply">Apply</button>
                </div>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" id="dpbs_export_csv_btn">
                    <span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
                    Export CSV
                </a>
            </div>

            <div id="dpbs_bookings_table_wrap">
                <?php dpbs_render_bookings_table( $bookings ); ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders the bookings table (used for the initial page load and for the
 * AJAX-refreshed markup after a delete).
 */
function dpbs_render_bookings_table( $bookings ) {
    if ( empty( $bookings ) ) {
        echo '<p class="cwf-bookings-empty">No bookings yet.</p>';
        return;
    }
    ?>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <td class="check-column"><input type="checkbox" id="dpbs_select_all" /></td>
                <th>Customer</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Service</th>
                <th>Location</th>
                <th>Date</th>
                <th>End Date</th>
                <th>Seats</th>
                <th>Manager Seats</th>
                <th>Subtotal</th>
                <th>Tax</th>
                <th>Total</th>
                <th>Order ID</th>
                <th>Payment ID</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $bookings as $b ) : ?>
                <tr data-id="<?php echo esc_attr( $b->id ); ?>">
                    <th class="check-column"><input type="checkbox" class="dpbs_row_checkbox" value="<?php echo esc_attr( $b->id ); ?>" /></th>
                    <td><?php echo esc_html( $b->customer_name ); ?></td>
                    <td><?php echo esc_html( $b->customer_email ); ?></td>
                    <td><?php echo esc_html( $b->customer_phone ); ?></td>
                    <td><?php echo esc_html( get_the_title( $b->service_id ) ); ?></td>
                    <td><?php echo esc_html( get_the_title( $b->location_id ) ); ?></td>
                    <td><?php echo esc_html( $b->booking_date ); ?></td>
                    <td><?php echo ! empty( $b->end_date ) ? esc_html( $b->end_date ) : '—'; ?></td>
                    <td><?php echo esc_html( $b->seats ); ?></td>
                    <td><?php echo ! empty( $b->manager_seats ) ? esc_html( $b->manager_seats ) : '—'; ?></td>
                    <td>₹<?php echo esc_html( number_format( (float) $b->subtotal_amount, 2 ) ); ?></td>
                    <td><?php echo ( isset( $b->tax_amount ) && $b->tax_amount > 0 ) ? '₹' . esc_html( number_format( (float) $b->tax_amount, 2 ) ) . ' (' . esc_html( rtrim( rtrim( number_format( (float) $b->tax_percent, 2 ), '0' ), '.' ) ) . '%)' : '—'; ?></td>
                    <td>₹<?php echo esc_html( number_format( (float) $b->total_amount, 2 ) ); ?></td>
                    <td><?php echo esc_html( $b->razorpay_order_id ); ?></td>
                    <td><?php echo esc_html( $b->razorpay_payment_id ); ?></td>
                    <td><?php echo esc_html( ucfirst( $b->status ) ); ?></td>
                    <td>
                        <a href="#" class="dpbs-row-delete" data-id="<?php echo esc_attr( $b->id ); ?>" title="Delete booking">
                            <span class="dashicons dashicons-trash"></span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/* ==========================================================================
   6. FRONTEND SHORTCODE & ASSETS
   ========================================================================== */

add_shortcode( 'book_day_pass', 'dpbs_render_booking_form' );
function dpbs_render_booking_form( $atts ) {
    $atts = shortcode_atts( array(
        'service_id'  => '',
        'city_id'     => '',
        'location_id' => '',
        'instance_id' => '',  // NEW: Unique instance identifier
    ), $atts, 'book_day_pass' );

    if ( is_singular( 'service' ) ) $atts['service_id'] = get_the_ID();
    if ( is_singular( 'city' ) ) {
        if ( wp_get_post_parent_id( get_the_ID() ) ) {
            $atts['location_id'] = get_the_ID();
            $atts['city_id'] = wp_get_post_parent_id( get_the_ID() );
        } else {
            $atts['city_id'] = get_the_ID();
        }
    }
// Handle URL parameters - supports both numeric IDs and slugs
if ( isset( $_GET['service'] ) ) {
    $atts['service_id'] = dpbs_resolve_post_id( $_GET['service'], 'service' );
}
if ( isset( $_GET['city'] ) ) {
    $atts['city_id'] = dpbs_resolve_post_id( $_GET['city'], 'city' );
}
if ( isset( $_GET['location'] ) ) {
    $location_id = dpbs_resolve_post_id( $_GET['location'], 'city' );
    $atts['location_id'] = $location_id;
    
    // Auto-detect city from location's parent if city not already set
    if ( $location_id && empty( $atts['city_id'] ) ) {
        $parent_id = wp_get_post_parent_id( $location_id );
        if ( $parent_id ) {
            $atts['city_id'] = $parent_id;
        }
    }
}

    // Generate unique instance ID if not provided
    if ( empty($atts['instance_id']) ) {
        $atts['instance_id'] = 'dpbs-' . wp_unique_id();
    }

    wp_enqueue_style( 'dpbs-front-css', DPBS_PLUGIN_URL . 'assets/css/frontend.css', array(), DPBS_VERSION );
    wp_enqueue_script( 'razorpay-checkout', 'https://checkout.razorpay.com/v1/checkout.js', array(), null, true );
    wp_enqueue_script( 'dpbs-front-js', DPBS_PLUGIN_URL . 'assets/js/frontend.js', array('jquery', 'razorpay-checkout'), DPBS_VERSION, true );

    $services = get_posts( array( 'post_type' => 'service', 'numberposts' => -1, 'post_status' => 'publish' ) );

    /* NEW: default to the first published service (e.g. "Hotdesk") when none
       was explicitly requested via a singular service page or URL param, so
       the dropdown shows it pre-selected instead of the blank "Select
       Service" placeholder. This only changes what's pre-selected - the
       placeholder option is still the first literal <option> in the
       markup and the URL-param / singular-page logic above still wins
       whenever a service is actually specified, so nothing else changes. */
    if ( empty( $atts['service_id'] ) && ! empty( $services ) ) {
        $atts['service_id'] = $services[0]->ID;
    }

    wp_localize_script( 'dpbs-front-js', 'dpbs_obj', array(
        'ajax_url'      => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce('dpbs_nonce'),
        'razorpay_key'  => get_option('dpbs_razorpay_key'),
        'pre_location'  => $atts['location_id'],
        'pre_service'   => $atts['service_id'],
        'pre_city'      => $atts['city_id'],
        /* NEW: lets frontend.js know which service ID should switch the form
           into "Private Suites" mode (Start/End date + Manager Seats, no payment). */
        'suite_service_id' => DPBS_SUITE_SERVICE_ID,
        'suite_min_days'    => intval( get_option( 'dpbs_suite_min_days', 1 ) ),
        'suite_max_days'    => intval( get_option( 'dpbs_suite_max_days', 30 ) ),
    ) );

    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1, 'post_status' => 'publish' ) );

    // If a service is preset (via singular page or URL param), only show cities that offer it
    if ( ! empty( $atts['service_id'] ) ) {
        $cities = array_values( array_filter( $cities, function( $city ) use ( $atts ) {
            return dpbs_city_offers_service( $city->ID, $atts['service_id'] );
        } ) );
    }

    $iid = esc_attr($atts['instance_id']);
    
    ob_start();
    ?>
    <!-- CRITICAL: Wrapper with unique class and data attribute for instance scoping.
         Every class below is dpbs-prefixed and unique to this plugin. Nothing here
         shares a selector with the Schedule Forms (cwf-*) plugin, so neither
         plugin's CSS cascade nor delegated JS click handlers can touch this markup,
         even when both plugins are enqueued on the same page. -->
   <div class="dpbs-booking-instance" data-instance-id="<?php echo $iid; ?>" 
     data-pre-service="<?php echo esc_attr($atts['service_id']); ?>"
     data-pre-city="<?php echo esc_attr($atts['city_id']); ?>"
     data-pre-location="<?php echo esc_attr($atts['location_id']); ?>">
        <div class="dpbs-form-wrap">
            <div class="dpbs-form-panel">
                <h2 class="dpbs-form-heading">Book a Day Pass!</h2>

                <div class="dpbs-price-line">
                    Price: ₹<span id="<?php echo $iid; ?>-price-display">0.00</span> <span id="<?php echo $iid; ?>-price-suffix">/ Seat</span><span class="dpbs-gst-note" id="<?php echo $iid; ?>-gst-note"></span>
                 <div class="dpbs-tax-line" id="<?php echo $iid; ?>-tax-line" style="display:none;"></div>
                </div>
                <!-- NEW: populated by frontend.js with the tax breakdown for
                     the currently selected price/seats, so the customer sees
                     what they'll actually pay before submitting. Hidden when
                     tax is off or nothing is selected yet. -->
               

                <form id="<?php echo $iid; ?>-booking-form" class="dpbs-booking-form dpbs-form-grid" data-instance="<?php echo $iid; ?>" novalidate>
                    <div class="dpbs-field">
                        <!-- <label>Service</label> -->
                        <div class="dpbs-select-wrap">
                            <select name="service" id="<?php echo $iid; ?>-service" class="dpbs-service" required>
                                <option value="">Select Service</option>
                                <?php foreach ( $services as $service ) : ?>
                                    <option value="<?php echo esc_attr($service->ID); ?>" <?php selected( $atts['service_id'], $service->ID ); ?>>
                                        <?php echo esc_html( $service->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>City</label> -->
                        <div class="dpbs-select-wrap">
                            <select name="city" id="<?php echo $iid; ?>-city" class="dpbs-city" required>
                                <option value="">Select City</option>
                                <?php foreach ( $cities as $city ) : ?>
                                    <option value="<?php echo esc_attr($city->ID); ?>" <?php selected( $atts['city_id'], $city->ID ); ?>>
                                        <?php echo esc_html( $city->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>Location</label> -->
                        <div class="dpbs-select-wrap">
                            <select name="location" id="<?php echo $iid; ?>-location" class="dpbs-location" required>
                                <option value="">Select Location</option>
                            </select>
                        </div>
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>Company (Optional)</label> -->
                        <input type="text" name="company" class="dpbs-company" placeholder="Company (Optional)" />
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>Full Name</label> -->
                        <input type="text" name="full_name" class="dpbs-fullname" placeholder="Full Name" required />
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>Phone Number</label> -->
                        <input type="tel" name="phone" class="dpbs-phone" required placeholder="Phone Number" />
                        <!-- <small class="dpbs-field-hint">e.g. 9876543210</small> -->
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>Email</label> -->
                        <input type="email" name="email" class="dpbs-email" required placeholder="Email" />
                    </div>
                  <div class="dpbs-field" id="<?php echo $iid; ?>-regular-date-wrap">
                        <!-- <label>Date</label> -->
                        <div class="dpbs-date-field">
                            <input type="text" name="date" id="<?php echo $iid; ?>-date" class="dpbs-date" required readonly placeholder="Date" />
                            <div class="dpbs-date-icon"><span class="dashicons"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line></svg>
                            </span></div>
                            <!-- Calendar popover - JS moves this to <body> and positions it with getBoundingClientRect(), CSS just handles show/hide + styling -->
                            <div class="dpbs-calendar-popover" id="<?php echo $iid; ?>-calendar-popover">
                                <div class="dpbs-cal-header">
                                    <button type="button" class="dpbs-cal-nav-btn" data-dir="prev">&laquo;</button>
                                    <span class="dpbs-cal-title"></span>
                                    <button type="button" class="dpbs-cal-nav-btn" data-dir="next">&raquo;</button>
                                </div>
                                <div class="dpbs-cal-content">
                                    <div class="dpbs-cal-grid dpbs-cal-dow-row">
                                        <div class="dpbs-cal-dow is-weekend">Su</div><div class="dpbs-cal-dow">Mo</div>
                                        <div class="dpbs-cal-dow">Tu</div><div class="dpbs-cal-dow">We</div>
                                        <div class="dpbs-cal-dow">Th</div><div class="dpbs-cal-dow">Fr</div>
                                        <div class="dpbs-cal-dow is-weekend">Sa</div>
                                    </div>
                                    <div class="dpbs-cal-grid dpbs-cal-days" id="<?php echo $iid; ?>-cal-days"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dpbs-field">
                        <!-- <label>No. of Seats</label> -->
                        <div class="dpbs-select-wrap">
                            <select name="seats" id="<?php echo $iid; ?>-seats" class="dpbs-seats" required>
                                <option value="">No. of Seats</option>
                                <?php for ($i = 1; $i <= 50; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected( $i, 1 ); ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <small id="<?php echo $iid; ?>-seats-info" class="dpbs-seats-info"></small>
                    </div>

        <!-- NEW: Private Suites (service ID <?php echo DPBS_SUITE_SERVICE_ID; ?>) only fields.
     Hidden by default via inline style; frontend.js shows these and hides
     the single "Date" field above whenever this service is selected, and
     reverses it for every other service. Nothing here affects the regular
     day-pass flow. Uses identical calendar markup as the regular date field
     so the UI is visually consistent. -->
        <div class="dpbs-field dpbs-suite-field" id="<?php echo $iid; ?>-suite-start-date-wrap" style="display:none;">
            <div class="dpbs-date-field dpbs-date-field--suite">
                <input type="text" name="suite_start_date" id="<?php echo $iid; ?>-suite-start-date" class="dpbs-suite-start-date dpbs-suite-date-input" readonly placeholder="Start Date" />
                <div class="dpbs-date-icon"><span class="dashicons"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line></svg></span></div>
                <div class="dpbs-calendar-popover dpbs-cal-popover--suite-start" id="<?php echo $iid; ?>-suite-start-calendar-popover">
                    <div class="dpbs-cal-header">
                        <button type="button" class="dpbs-cal-nav-btn dpbs-suite-start-nav" data-dir="prev">&laquo;</button>
                        <span class="dpbs-cal-title dpbs-suite-start-title"></span>
                        <button type="button" class="dpbs-cal-nav-btn dpbs-suite-start-nav" data-dir="next">&raquo;</button>
                    </div>
                    <div class="dpbs-cal-content">
                        <div class="dpbs-cal-grid dpbs-cal-dow-row">
                            <div class="dpbs-cal-dow is-weekend">Su</div><div class="dpbs-cal-dow">Mo</div>
                            <div class="dpbs-cal-dow">Tu</div><div class="dpbs-cal-dow">We</div>
                            <div class="dpbs-cal-dow">Th</div><div class="dpbs-cal-dow">Fr</div>
                            <div class="dpbs-cal-dow is-weekend">Sa</div>
                        </div>
                        <div class="dpbs-cal-grid dpbs-cal-days dpbs-suite-start-days" id="<?php echo $iid; ?>-suite-start-cal-days"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="dpbs-field dpbs-suite-field" id="<?php echo $iid; ?>-suite-end-date-wrap" style="display:none;">
            <div class="dpbs-date-field dpbs-date-field--suite">
                <input type="text" name="suite_end_date" id="<?php echo $iid; ?>-suite-end-date" class="dpbs-suite-end-date dpbs-suite-date-input" readonly placeholder="End Date" />
                <div class="dpbs-date-icon"><span class="dashicons"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line></svg></span></div>
                <div class="dpbs-calendar-popover dpbs-cal-popover--suite-end" id="<?php echo $iid; ?>-suite-end-calendar-popover">
                    <div class="dpbs-cal-header">
                        <button type="button" class="dpbs-cal-nav-btn dpbs-suite-end-nav" data-dir="prev">&laquo;</button>
                        <span class="dpbs-cal-title dpbs-suite-end-title"></span>
                        <button type="button" class="dpbs-cal-nav-btn dpbs-suite-end-nav" data-dir="next">&raquo;</button>
                    </div>
                    <div class="dpbs-cal-content">
                        <div class="dpbs-cal-grid dpbs-cal-dow-row">
                            <div class="dpbs-cal-dow is-weekend">Su</div><div class="dpbs-cal-dow">Mo</div>
                            <div class="dpbs-cal-dow">Tu</div><div class="dpbs-cal-dow">We</div>
                            <div class="dpbs-cal-dow">Th</div><div class="dpbs-cal-dow">Fr</div>
                            <div class="dpbs-cal-dow is-weekend">Sa</div>
                        </div>
                        <div class="dpbs-cal-grid dpbs-cal-days dpbs-suite-end-days" id="<?php echo $iid; ?>-suite-end-cal-days"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="dpbs-field dpbs-suite-field" id="<?php echo $iid; ?>-suite-manager-seats-wrap" style="display:none;">
            <div class="dpbs-select-wrap">
                <select name="manager_seats" id="<?php echo $iid; ?>-suite-manager-seats" class="dpbs-manager-seats">
                    <option value="No">Manager Seats: No</option>
                    <option value="Yes">Manager Seats: Yes</option>
                </select>
            </div>
        </div>

                    <div class="dpbs-form-footer dpbs-field-full">
                        <button type="submit" class="dpbs-submit-btn jd-bookaday-button">
                           Book Now <!-- <span class="dashicons dashicons-arrow-right-alt2"></span> -->
                        </button>
                        <div class="dpbs-form-message" id="<?php echo $iid; ?>-form-message"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ==========================================================================
   7. AJAX HANDLERS
   ========================================================================== */

add_action( 'wp_ajax_dpbs_get_cities_for_service', 'dpbs_get_cities_for_service' );
add_action( 'wp_ajax_nopriv_dpbs_get_cities_for_service', 'dpbs_get_cities_for_service' );
function dpbs_get_cities_for_service() {
    $service_id = intval( $_POST['service_id'] );
    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1, 'post_status' => 'publish' ) );

    $html = '<option value="">Select City</option>';
    foreach ( $cities as $city ) {
        if ( ! dpbs_city_offers_service( $city->ID, $service_id ) ) continue;
        $html .= '<option value="' . esc_attr($city->ID) . '">' . esc_html( $city->post_title ) . '</option>';
    }
    echo $html;
    wp_die();
}

add_action( 'wp_ajax_dpbs_get_locations', 'dpbs_get_locations' );
add_action( 'wp_ajax_nopriv_dpbs_get_locations', 'dpbs_get_locations' );
function dpbs_get_locations() {
    $city_id = intval( $_POST['city_id'] );
    $service_id = isset( $_POST['service_id'] ) ? intval( $_POST['service_id'] ) : 0;
    $locations = get_posts( array( 'post_type' => 'city', 'post_parent' => $city_id, 'numberposts' => -1, 'post_status' => 'publish' ) );
    $html = '<option value="">Select Location</option>';
    foreach ( $locations as $loc ) {
        if ( ! dpbs_location_offers_service( $loc->ID, $service_id ) ) continue;
        $html .= '<option value="' . esc_attr($loc->ID) . '">' . esc_html( $loc->post_title ) . '</option>';
    }
    echo $html;
    wp_die();
}

add_action( 'wp_ajax_dpbs_get_price', 'dpbs_get_price' );
add_action( 'wp_ajax_nopriv_dpbs_get_price', 'dpbs_get_price' );
function dpbs_get_price() {
    $service_id = intval( $_POST['service_id'] );
    $location_id = intval( $_POST['location_id'] );
    $price = dpbs_calculate_price( $service_id, $location_id );
    /* NEW: tax_* fields are additive - 'price' is unchanged (still the raw
       per-unit price) so existing frontend.js math keeps working as-is. */
    echo json_encode( array(
        'success'     => true,
        'price'       => $price,
        'tax_enabled' => dpbs_tax_enabled(),
        'tax_label'   => dpbs_get_tax_label(),
        'tax_rate'    => dpbs_tax_enabled() ? dpbs_get_tax_rate() : 0,
    ) );
    wp_die();
}

add_action( 'wp_ajax_dpbs_get_calendar', 'dpbs_get_calendar' );
add_action( 'wp_ajax_nopriv_dpbs_get_calendar', 'dpbs_get_calendar' );
function dpbs_get_calendar() {
    global $wpdb;
    $service_id = intval( $_POST['service_id'] );
    $location_id = intval( $_POST['location_id'] );
    $month = intval( $_POST['month'] );
    $year = intval( $_POST['year'] );
    
    $table_book = $wpdb->prefix . 'dpbs_bookings';
    $default_seats = get_option('dpbs_default_seats', 10);
    $threshold = get_option('dpbs_limited_seats', 3);
    
    $slug = "s{$service_id}_l{$location_id}";
    $calendar = new DPBS_Calendar( $slug );
    $manual_statuses = $calendar->get_all();
    
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
    
    $bookings = $wpdb->get_results( $wpdb->prepare(
        "SELECT booking_date, SUM(seats) as booked_seats 
         FROM $table_book 
         WHERE service_id = %d AND location_id = %d AND booking_date BETWEEN %s AND %s AND status = 'confirmed'
         GROUP BY booking_date",
        $service_id, $location_id, $start_date, $end_date
    ) );
    
    $booked_map = array();
    foreach($bookings as $b) $booked_map[$b->booking_date] = intval($b->booked_seats);

    $calendar_data = array();
    $today = current_time('Y-m-d');
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $status = 'available';
        $avail_seats = $default_seats;
        
        if ($dateStr < $today) {
            $status = 'past';
        } else {
            $booked = isset($booked_map[$dateStr]) ? $booked_map[$dateStr] : 0;
            $avail_seats = $default_seats - $booked;
            
            if (isset($manual_statuses[$dateStr]) && $manual_statuses[$dateStr] === 'full') {
                $status = 'full'; $avail_seats = 0;
            } elseif (isset($manual_statuses[$dateStr]) && $manual_statuses[$dateStr] === 'limited') {
                $status = 'limited'; $avail_seats = min($avail_seats, $threshold); 
            } elseif ($avail_seats <= 0) {
                $status = 'full'; $avail_seats = 0;
            } elseif ($avail_seats <= $threshold) {
                $status = 'limited';
            }
        }
        $calendar_data[$dateStr] = array('status' => $status, 'seats' => $avail_seats);
    }
    
    echo json_encode( array( 'success' => true, 'calendar' => $calendar_data ) );
    wp_die();
}

add_action( 'wp_ajax_dpbs_toggle_date_status', 'dpbs_toggle_date_status' );
function dpbs_toggle_date_status() {
    check_ajax_referer( 'dpbs_admin_nonce', 'nonce' );
    $date = sanitize_text_field( $_POST['date'] );
    $service_id = intval( $_POST['service_id'] );
    $location_id = intval( $_POST['location_id'] );
    $target_status = sanitize_text_field( $_POST['target_status'] );
    
    $slug = "s{$service_id}_l{$location_id}";
    $calendar = new DPBS_Calendar( $slug );
    $calendar->set_status( $date, $target_status );
    
    echo json_encode( array( 'success' => true ) );
    wp_die();
}

add_action( 'wp_ajax_dpbs_save_price_rule', 'dpbs_save_price_rule' );
function dpbs_save_price_rule() {
    check_ajax_referer( 'dpbs_admin_nonce', 'nonce' );
    $type = sanitize_text_field($_POST['type']);
    $price = floatval($_POST['price']);
    
    if($type === 'svc') {
        $service_id = intval($_POST['service_id']);
        $prices = get_option('dpbs_service_prices', array());
        $prices[$service_id] = $price;
        update_option('dpbs_service_prices', $prices);
    } elseif($type === 'loc') {
        $service_id = intval($_POST['service_id']);
        $location_id = intval($_POST['location_id']);
        $prices = get_option('dpbs_location_prices', array());
        $key = $service_id . '_' . $location_id;
        $prices[$key] = $price;
        update_option('dpbs_location_prices', $prices);
    }
    
    $svc_prices = get_option('dpbs_service_prices', array());
    $loc_prices = get_option('dpbs_location_prices', array());
    
    if($type === 'svc') {
        if(empty($svc_prices)) {
            echo '<li class="cwf-price-empty">No service prices set yet.</li>';
        } else {
            foreach($svc_prices as $sid => $p): 
                if(!get_post_status($sid)) continue; ?>
                <li>
                    <span class="cwf-price-rule-name"><?php echo get_the_title($sid); ?></span>
                    <span class="cwf-price-rule-value">₹<?php echo esc_html($p); ?></span>
                    <a href="#" class="cwf-price-delete" data-type="svc" data-id="<?php echo esc_attr($sid); ?>">Delete</a>
                </li>
            <?php endforeach;
        }
    } else {
        if(empty($loc_prices)) {
            echo '<li class="cwf-price-empty">No location prices set yet.</li>';
        } else {
            foreach($loc_prices as $key => $p): 
                list($sid, $lid) = explode('_', $key);
                if(!get_post_status($sid) || !get_post_status($lid)) continue; ?>
                <li>
                    <span class="cwf-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span>
                    <span class="cwf-price-rule-value">₹<?php echo esc_html($p); ?></span>
                    <a href="#" class="cwf-price-delete" data-type="loc" data-id="<?php echo esc_attr($key); ?>">Delete</a>
                </li>
            <?php endforeach;
        }
    }
    wp_die();
}

add_action( 'wp_ajax_dpbs_delete_price_rule', 'dpbs_delete_price_rule' );
function dpbs_delete_price_rule() {
    check_ajax_referer( 'dpbs_admin_nonce', 'nonce' );
    $type = sanitize_text_field($_POST['type']);
    $id = sanitize_text_field($_POST['id']);
    
    if($type === 'svc') {
        $prices = get_option('dpbs_service_prices', array());
        unset($prices[$id]);
        update_option('dpbs_service_prices', $prices);
        $updated_prices = $prices;
        $list_type = 'svc';
    } elseif($type === 'loc') {
        $prices = get_option('dpbs_location_prices', array());
        unset($prices[$id]);
        update_option('dpbs_location_prices', $prices);
        $updated_prices = $prices;
        $list_type = 'loc';
    }
    
    if(empty($updated_prices)) {
        echo $list_type === 'svc' ? '<li class="cwf-price-empty">No service prices set yet.</li>' : '<li class="cwf-price-empty">No location prices set yet.</li>';
    } else {
        foreach($updated_prices as $key => $p): 
            if($list_type === 'svc') {
                if(!get_post_status($key)) continue; ?>
                <li>
                    <span class="cwf-price-rule-name"><?php echo get_the_title($key); ?></span>
                    <span class="cwf-price-rule-value">₹<?php echo esc_html($p); ?></span>
                    <a href="#" class="cwf-price-delete" data-type="svc" data-id="<?php echo esc_attr($key); ?>">Delete</a>
                </li>
            <?php } else {
                list($sid, $lid) = explode('_', $key);
                if(!get_post_status($sid) || !get_post_status($lid)) continue; ?>
                <li>
                    <span class="cwf-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span>
                    <span class="cwf-price-rule-value">₹<?php echo esc_html($p); ?></span>
                    <a href="#" class="cwf-price-delete" data-type="loc" data-id="<?php echo esc_attr($key); ?>">Delete</a>
                </li>
            <?php }
        endforeach;
    }
    wp_die();
}

/**
 * Delete one or more bookings (admin only). Accepts either a single `id`
 * or a JSON-encoded array of ids in `ids` for bulk delete. Returns the
 * refreshed table HTML so admin.js can swap it straight in.
 */
add_action( 'wp_ajax_dpbs_delete_booking', 'dpbs_delete_booking' );
function dpbs_delete_booking() {
    check_ajax_referer( 'dpbs_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dpbs_bookings';

    $ids = array();
    if ( isset( $_POST['ids'] ) ) {
        $raw_ids = json_decode( wp_unslash( $_POST['ids'] ), true );
        if ( is_array( $raw_ids ) ) {
            $ids = array_filter( array_map( 'intval', $raw_ids ) );
        }
    } elseif ( isset( $_POST['id'] ) ) {
        $ids = array( intval( $_POST['id'] ) );
    }

    if ( empty( $ids ) ) {
        wp_send_json_error( array( 'message' => 'No booking(s) specified.' ) );
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );

    $bookings = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );

    ob_start();
    dpbs_render_bookings_table( $bookings );
    $html = ob_get_clean();

    wp_send_json_success( array(
        'html'  => $html,
        'count' => count( $bookings ),
    ) );
}

/**
 * Streams all bookings as a downloadable CSV file. Triggered by a plain
 * GET link (not a JS-driven AJAX call) so the browser handles it as a
 * normal file download.
 */
add_action( 'wp_ajax_dpbs_export_csv', 'dpbs_export_csv' );
function dpbs_export_csv() {
    check_admin_referer( 'dpbs_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dpbs_bookings';
    $bookings = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=day-pass-bookings-' . gmdate( 'Y-m-d' ) . '.csv' );

    $output = fopen( 'php://output', 'w' );

    // UTF-8 BOM so Excel renders ₹ and non-ASCII names correctly.
    fwrite( $output, "\xEF\xBB\xBF" );

    fputcsv( $output, array(
        'ID', 'Customer Name', 'Email', 'Phone', 'Company', 'Service', 'City', 'Location',
        'Booking Date', 'End Date', 'Seats', 'Manager Seats', 'Subtotal Amount', 'Tax Percent', 'Tax Amount', 'Total Amount', 'Razorpay Order ID', 'Razorpay Payment ID',
        'Status', 'Created At'
    ) );

    foreach ( $bookings as $b ) {
        fputcsv( $output, array(
            $b->id,
            $b->customer_name,
            $b->customer_email,
            $b->customer_phone,
            $b->customer_company,
            get_the_title( $b->service_id ),
            get_the_title( $b->city_id ),
            get_the_title( $b->location_id ),
            $b->booking_date,
            $b->end_date,
            $b->seats,
            $b->manager_seats,
            isset( $b->subtotal_amount ) ? $b->subtotal_amount : '',
            isset( $b->tax_percent ) ? $b->tax_percent : '',
            isset( $b->tax_amount ) ? $b->tax_amount : '',
            $b->total_amount,
            $b->razorpay_order_id,
            $b->razorpay_payment_id,
            $b->status,
            $b->created_at,
        ) );
    }

    fclose( $output );
    exit;
}

// Create Razorpay Order (NO DB INSERT HERE)
add_action( 'wp_ajax_dpbs_create_booking', 'dpbs_create_booking' );
add_action( 'wp_ajax_nopriv_dpbs_create_booking', 'dpbs_create_booking' );
function dpbs_create_booking() {
    check_ajax_referer( 'dpbs_nonce', 'nonce' );
    
    $service_id = intval( $_POST['service'] );
    $location_id = intval( $_POST['location'] );
    $date = sanitize_text_field( $_POST['date'] );
    $seats = intval( $_POST['seats'] );

    /* CHANGED: Server-side email validation */
    $email = sanitize_email( $_POST['email'] );
    if ( ! is_email( $email ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid email address.' ) );
        wp_die();
    }

    /* CHANGED: Server-side Indian phone validation */
    $phone_raw = sanitize_text_field( $_POST['phone'] );
    $phone_clean = preg_replace( '/[\s\-\+\(\)]/', '', $phone_raw );
    if ( ! preg_match( '/^[6-9]\d{9}$/', $phone_clean ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid 10-digit Indian phone number starting with 6, 7, 8, or 9.' ) );
        wp_die();
    }
    
    // Validate that this service is actually offered at this location
    if ( ! dpbs_location_offers_service( $location_id, $service_id ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'This service is not available at the selected location.' ) );
        wp_die();
    }

    // Validate availability again just in case
    $slug = "s{$service_id}_l{$location_id}";
    $calendar = new DPBS_Calendar( $slug );
    if ( $calendar->get_status($date) === 'full' ) {
        echo json_encode( array( 'success' => false, 'message' => 'This date is fully booked.' ) );
        wp_die();
    }

    /* CHANGED: totals now include tax (if enabled in Settings). $total_amount
       is what's actually charged via Razorpay - unchanged behavior when tax
       is disabled (dpbs_calculate_totals returns tax_amount = 0 and
       total === subtotal === price * seats, same as before). */
    $totals = dpbs_calculate_totals( $service_id, $location_id, $seats );
    $total_amount = $totals['total'];

    $api_key = get_option('dpbs_razorpay_key');
    $api_secret = get_option('dpbs_razorpay_secret');
    
    $response = wp_remote_post( 'https://api.razorpay.com/v1/orders', array(
        'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ), 'Content-Type' => 'application/json' ),
        'body' => json_encode( array( 'amount' => $total_amount * 100, 'currency' => 'INR', 'receipt' => 'dpbs_' . wp_generate_password(6, false) ) ),
        'timeout' => 30
    ));
    
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    if ( isset( $body['id'] ) ) {
        $description = 'Day Pass Booking for ' . $date;
        if ( $totals['tax_amount'] > 0 ) {
            $description .= ' (Subtotal ₹' . number_format( $totals['subtotal'], 2 ) . ' + ' . $totals['tax_label'] . ' ' . $totals['tax_rate'] . '% ₹' . number_format( $totals['tax_amount'], 2 ) . ')';
        }
        echo json_encode( array(
            'success' => true,
            'order_id' => $body['id'],
            'amount' => $total_amount * 100,
            'subtotal' => $totals['subtotal'],
            'tax_label' => $totals['tax_label'],
            'tax_rate' => $totals['tax_rate'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'description' => $description,
            'booking_data' => array(
                'service' => $service_id,
                'city' => intval($_POST['city']),
                'location' => $location_id,
                'date' => $date,
                'seats' => $seats,
                'full_name' => sanitize_text_field($_POST['full_name']),
                'email' => $email,
                'phone' => $phone_clean,
                'company' => sanitize_text_field($_POST['company'])
            )
        ) );
    } else {
        echo json_encode( array( 'success' => false, 'message' => 'Payment Gateway Error.' ) );
    }
    wp_die();
}

// Verify Payment & SAVE DATA TO DB ONLY HERE
add_action( 'wp_ajax_dpbs_verify_payment', 'dpbs_verify_payment' );
add_action( 'wp_ajax_nopriv_dpbs_verify_payment', 'dpbs_verify_payment' );
function dpbs_verify_payment() {
    check_ajax_referer( 'dpbs_nonce', 'nonce' );
    global $wpdb;
    $table_book = $wpdb->prefix . 'dpbs_bookings';
    
    $payment_id = sanitize_text_field( $_POST['payment_id'] );
    $order_id = sanitize_text_field( $_POST['order_id'] );
    $signature = sanitize_text_field( $_POST['signature'] );
    
    $service_id = intval( $_POST['service'] );
    $location_id = intval( $_POST['location'] );
    $city_id = intval( $_POST['city'] );
    $date = sanitize_text_field( $_POST['date'] );
    $seats = intval( $_POST['seats'] );
    
    $api_secret = get_option('dpbs_razorpay_secret');
    $generated_signature = hash_hmac( 'sha256', $order_id . '|' . $payment_id, $api_secret );
    
    if ( $generated_signature === $signature ) {
        /* CHANGED: recompute subtotal/tax/total server-side the same way
           dpbs_create_booking did (never trust client-sent amounts). When
           tax is disabled this reduces to the original price * seats. */
        $totals = dpbs_calculate_totals( $service_id, $location_id, $seats );
        $total_amount = $totals['total'];

        $inserted = $wpdb->insert( $table_book, array(
            'service_id'       => $service_id,
            'city_id'          => $city_id,
            'location_id'      => $location_id,
            'booking_date'     => $date,
            'seats'            => $seats,
            'total_amount'     => $total_amount,
            'subtotal_amount'  => $totals['subtotal'],
            'tax_amount'       => $totals['tax_amount'],
            'tax_percent'      => $totals['tax_rate'],
            'customer_name'    => sanitize_text_field( $_POST['full_name'] ),
            'customer_email'   => sanitize_email( $_POST['email'] ),
            'customer_phone'   => sanitize_text_field( $_POST['phone'] ),
            'customer_company' => sanitize_text_field( $_POST['company'] ),
            'razorpay_order_id'=> $order_id,
            'razorpay_payment_id' => $payment_id,
            'status'           => 'confirmed'
        ));

        $admin_email = get_option('dpbs_admin_email', get_option('admin_email'));

        /* IMPORTANT: the payment is already verified and captured by Razorpay
           at this point. If the DB insert fails (e.g. a schema mismatch, such
           as a missing column after a bad update), we must NOT tell the
           customer that verification failed - that would be showing a
           payment error for a payment that actually succeeded. Instead we
           still confirm to the customer and urgently alert the admin so the
           booking can be reconciled/recorded manually. */
        if ( $inserted === false ) {
            error_log( 'DPBS: booking insert failed after verified payment. Order: ' . $order_id . ' Payment: ' . $payment_id . ' DB error: ' . $wpdb->last_error );
            dpbs_send_mail(
                $admin_email,
                'URGENT: Day Pass booking NOT saved after successful payment',
                "A Razorpay payment was successfully verified but the booking could not be saved to the database.\n\n" .
                "Order ID: {$order_id}\nPayment ID: {$payment_id}\nDB Error: {$wpdb->last_error}\n\n" .
                "Name: " . sanitize_text_field($_POST['full_name']) . "\nEmail: " . sanitize_email($_POST['email']) . "\nPhone: " . sanitize_text_field($_POST['phone']) . "\n" .
                "Service: " . get_the_title($service_id) . "\nLocation: " . get_the_title($location_id) . "\n" .
                "Date: {$date}\nSeats: {$seats}\nTotal: ₹{$total_amount}\n\nPlease record this booking manually."
            );
        } else {
            $subject = 'New Day Pass Booking Confirmed';
            $message = "A new booking has been confirmed.\n\nName: " . sanitize_text_field($_POST['full_name']) . "\nEmail: " . sanitize_email($_POST['email']) . "\nPhone: " . sanitize_text_field($_POST['phone']) . "\n";
            $message .= "Service: " . get_the_title($service_id) . "\nLocation: " . get_the_title($location_id) . "\n";
            $message .= "Date: {$date}\nSeats: {$seats}\n";
            $message .= "Subtotal: ₹" . number_format( $totals['subtotal'], 2 ) . "\n";
            if ( $totals['tax_amount'] > 0 ) {
                $message .= $totals['tax_label'] . " ({$totals['tax_rate']}%): ₹" . number_format( $totals['tax_amount'], 2 ) . "\n";
            }
            $message .= "Total Paid: ₹{$total_amount}\nRazorpay Payment ID: {$payment_id}";
            dpbs_send_mail( $admin_email, $subject, $message );

            /* NEW: also email the customer their booking confirmation.
               Purely additive - if this fails for any reason it does not
               affect the admin email above or the "verified" response, so
               the customer's payment/booking flow is never impacted. */
            dpbs_send_customer_confirmation_email( array(
                'guest_name'  => sanitize_text_field( $_POST['full_name'] ),
                'email'       => sanitize_email( $_POST['email'] ),
                'date'        => $date,
                'service_id'  => $service_id,
                'duration'    => 'Full Day',
                'seats'       => $seats,
                'location_id' => $location_id,
                'city_id'     => $city_id,
            ) );

            /* NEW: also send the same confirmation over WhatsApp (no-op if
               not configured in Settings - see dpbs_send_whatsapp_message()). */
            dpbs_send_whatsapp_message(
                sanitize_text_field( $_POST['phone'] ),
                get_option( 'dpbs_aisensy_campaign_daypass' ),
                array(
                    sanitize_text_field( $_POST['full_name'] ),
                    $date,
                    get_the_title( $service_id ),
                    'Full Day',
                    $seats,
                    get_the_title( $location_id ),
                    dpbs_get_location_maps_link( $location_id, $city_id ),
                ),
                sanitize_text_field( $_POST['full_name'] )
            );
        }

        echo 'verified';
    } else {
        echo 'failed';
    }
    wp_die();
}

/* ==========================================================================
   8. PRIVATE SUITES (service ID 357) - NO PAYMENT INQUIRY FLOW
   ==========================================================================
   Mirrors the Private Suites Inquiry plugin: a Start/End date range, an
   optional Manager Seats flag, and no Razorpay step at all - the row is
   saved straight to dpbs_bookings (status 'inquiry') and the admin is
   emailed. Completely separate from dpbs_create_booking/dpbs_verify_payment
   above, so the regular paid day-pass flow is untouched. */
add_action( 'wp_ajax_dpbs_submit_suite_booking', 'dpbs_submit_suite_booking' );
add_action( 'wp_ajax_nopriv_dpbs_submit_suite_booking', 'dpbs_submit_suite_booking' );
function dpbs_submit_suite_booking() {
    check_ajax_referer( 'dpbs_nonce', 'nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'dpbs_bookings';

    $service_id  = intval( $_POST['service'] );
    $city_id     = intval( $_POST['city'] );
    $location_id = intval( $_POST['location'] );
    $full_name   = sanitize_text_field( $_POST['full_name'] );
    $company     = sanitize_text_field( $_POST['company'] );
    $email       = sanitize_email( $_POST['email'] );
    $phone_raw   = sanitize_text_field( $_POST['phone'] );
    $start_date  = sanitize_text_field( $_POST['start_date'] );
    $end_date    = sanitize_text_field( $_POST['end_date'] );
    $seats       = intval( $_POST['seats'] );
    $manager     = sanitize_text_field( $_POST['manager_seats'] );

    /* Only ever allow this no-payment path for the designated suite service. */
    if ( ! dpbs_is_suite_service( $service_id ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Invalid service for this request.' ) );
        wp_die();
    }

    if ( ! is_email( $email ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid email address.' ) );
        wp_die();
    }
    $phone_clean = preg_replace( '/[\s\-\+\(\)]/', '', $phone_raw );
    if ( ! preg_match( '/^[6-9]\d{9}$/', $phone_clean ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid 10-digit Indian phone number.' ) );
        wp_die();
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'Invalid date format.' ) );
        wp_die();
    }
    if ( $end_date < $start_date ) {
        echo json_encode( array( 'success' => false, 'message' => 'End date cannot be before start date.' ) );
        wp_die();
    }
    if ( $seats < 1 ) {
        echo json_encode( array( 'success' => false, 'message' => 'Please select at least 1 seat.' ) );
        wp_die();
    }
    if ( ! dpbs_location_offers_service( $location_id, $service_id ) ) {
        echo json_encode( array( 'success' => false, 'message' => 'This service is not available at the selected location.' ) );
        wp_die();
    }

    $min_days = intval( get_option( 'dpbs_suite_min_days', 1 ) );
    $max_days = intval( get_option( 'dpbs_suite_max_days', 30 ) );
    $duration = ( strtotime( $end_date ) - strtotime( $start_date ) ) / 86400 + 1;
    if ( $duration < $min_days ) {
        echo json_encode( array( 'success' => false, 'message' => 'Minimum stay is ' . $min_days . ' day' . ( $min_days > 1 ? 's' : '' ) . '.' ) );
        wp_die();
    }
    if ( $duration > $max_days ) {
        echo json_encode( array( 'success' => false, 'message' => 'Maximum stay is ' . $max_days . ' days.' ) );
        wp_die();
    }

    /* Same price source as regular day-pass bookings (service/location price
       rules configured on this plugin's Settings page), just billed per
       month like the Private Suites Inquiry plugin instead of per day. */
    $months         = max( 1, ceil( $duration / 30 ) );
    /* CHANGED: same tax-aware totals helper as the paid flow, so the
       estimate emailed to the admin/customer is consistent. Still no
       Razorpay charge here - this remains an inquiry, not a payment. */
    $totals         = dpbs_calculate_totals( $service_id, $location_id, $seats * $months );
    $total_amount   = $totals['total'];

    $inserted = $wpdb->insert( $table, array(
        'service_id'       => $service_id,
        'city_id'          => $city_id,
        'location_id'      => $location_id,
        'booking_date'     => $start_date,
        'end_date'         => $end_date,
        'seats'            => $seats,
        'total_amount'     => $total_amount,
        'subtotal_amount'  => $totals['subtotal'],
        'tax_amount'       => $totals['tax_amount'],
        'tax_percent'      => $totals['tax_rate'],
        'customer_name'    => $full_name,
        'customer_email'   => $email,
        'customer_phone'   => $phone_clean,
        'customer_company' => $company,
        'manager_seats'    => $manager ? $manager : 'No',
        'razorpay_order_id'   => '',
        'razorpay_payment_id' => '',
        'status'           => 'inquiry', // no payment collected for suites
    ) );

    if ( $inserted === false ) {
        echo json_encode( array( 'success' => false, 'message' => 'Database error. Please try again.' ) );
        wp_die();
    }

    $admin_email = get_option( 'dpbs_admin_email', get_option( 'admin_email' ) );
    $subject     = 'New Private Suite Inquiry — ' . $full_name . ' (' . $months . ' month' . ( $months > 1 ? 's' : '' ) . ')';

    $message  = "A new Private Suite inquiry has been received (no payment collected).\n\n";
    $message .= "Name: {$full_name}\n";
    $message .= "Company: " . ( $company ?: '—' ) . "\n";
    $message .= "Email: {$email}\n";
    $message .= "Phone: {$phone_clean}\n";
    $message .= "City: " . get_the_title( $city_id ) . "\n";
    $message .= "Location: " . get_the_title( $location_id ) . "\n";
    $message .= "Start Date: {$start_date}\n";
    $message .= "End Date: {$end_date}\n";
    $message .= "Duration: {$duration} days ({$months} month" . ( $months > 1 ? 's' : '' ) . ")\n";
    $message .= "Seats: {$seats}\n";
    $message .= "Manager Seats: {$manager}\n";
    $message .= "Estimated Subtotal: ₹" . number_format( $totals['subtotal'], 2 ) . "\n";
    if ( $totals['tax_amount'] > 0 ) {
        $message .= "Estimated " . $totals['tax_label'] . " ({$totals['tax_rate']}%): ₹" . number_format( $totals['tax_amount'], 2 ) . "\n";
    }
    $message .= "Estimated Total: ₹" . number_format( $total_amount, 2 ) . "\n";
    dpbs_send_mail( $admin_email, $subject, $message );

    /* NEW: also email the customer confirming their inquiry was received.
       Uses the dedicated inquiry-email template (not the paid-booking
       "confirmed" one) since no payment was collected and the fields
       differ (date range, company, manager seats). Purely additive -
       failures here don't affect the admin email above or the success
       response returned to the browser. */
    dpbs_send_suite_inquiry_customer_email( array(
        'guest_name'    => $full_name,
        'email'         => $email,
        'company'       => $company,
        'service_id'    => $service_id,
        'start_date'    => $start_date,
        'end_date'      => $end_date,
        'duration_days' => $duration,
        'months'        => $months,
        'seats'         => $seats,
        'manager_seats' => $manager,
        'location_id'   => $location_id,
        'city_id'       => $city_id,
        'totals'        => $totals,
    ) );

    /* NEW: also send the same inquiry confirmation over WhatsApp (no-op if
       not configured in Settings - see dpbs_send_whatsapp_message()). */
    dpbs_send_whatsapp_message(
        $phone_clean,
        get_option( 'dpbs_aisensy_campaign_suite' ),
        array(
            $full_name,
            get_the_title( $service_id ),
            $start_date,
            $end_date,
            $duration . ' days (' . $months . ' month' . ( $months > 1 ? 's' : '' ) . ')',
            $seats,
            $manager ?: 'No',
            get_the_title( $location_id ),
            dpbs_get_location_maps_link( $location_id, $city_id ),
        ),
        $full_name
    );

    echo json_encode( array(
        'success' => true,
        'message' => 'Thank you for your inquiry! We will get back to you within 24 hours.',
    ) );
    wp_die();
}