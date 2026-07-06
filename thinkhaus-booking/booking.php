<?php
/**
 * Plugin Name:  ThinkHaus
 * Description:  Advanced booking form with custom calendar, seat tracking, hours/rooms, and Razorpay integration.
 * Version:      2.2.0
 * Author:       Pushpendu
 * Text Domain:  thinkhaus
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HBS_VERSION', '2.2.0' );
define( 'HBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

add_filter( 'site_transient_update_plugins', 'hbs_disable_plugin_updates' );
function hbs_disable_plugin_updates( $value ) { unset( $value->response[ plugin_basename( __FILE__ ) ] ); return $value; }

/* ==========================================================================
   1. CALENDAR & PRICE LOGIC
   ========================================================================== */

class HBS_Calendar {
    const STATUS_AVAILABLE = 'available'; const STATUS_LIMITED = 'limited'; const STATUS_FULL = 'full';
    protected $form_slug;
    public function __construct( $form_slug ) { $this->form_slug = $form_slug; }
    protected function option_key() { return 'hbs_calendar_' . $this->form_slug; }
    public function get_all() { $data = get_option( $this->option_key(), array() ); return is_array( $data ) ? $data : array(); }
    public function set_status( $date, $status ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;
        $all = $this->get_all();
        if ( self::STATUS_AVAILABLE === $status ) unset( $all[ $date ] );
        elseif ( in_array( $status, array( self::STATUS_LIMITED, self::STATUS_FULL ), true ) ) $all[ $date ] = $status;
        else return false;
        return update_option( $this->option_key(), $all, false );
    }
    public function get_status( $date ) { $all = $this->get_all(); return isset( $all[ $date ] ) ? $all[ $date ] : self::STATUS_AVAILABLE; }
}

function hbs_calculate_price( $service_id, $location_id ) {
    $loc_prices = get_option('hbs_location_prices', array()); $svc_prices = get_option('hbs_service_prices', array()); $global_price = get_option('hbs_default_price', '0.00');
    $loc_key = $service_id . '_' . $location_id;
    if ( isset($loc_prices[$loc_key]) && $loc_prices[$loc_key] !== '' ) return floatval($loc_prices[$loc_key]);
    elseif ( isset($svc_prices[$service_id]) && $svc_prices[$service_id] !== '' ) return floatval($svc_prices[$service_id]);
    else return floatval($global_price);
}

function hbs_get_max_rooms( $service_id, $location_id ) {
    $room_rules = get_option('hbs_location_rooms', array());
    $loc_key = $service_id . '_' . $location_id;
    if ( isset($room_rules[$loc_key]) && $room_rules[$loc_key] !== '' ) {
        return intval($room_rules[$loc_key]);
    }
    return intval(get_option('hbs_max_rooms', '5'));
}

/* ==========================================================================
   2. DATABASE CREATION
   ========================================================================== */

register_activation_hook( __FILE__, 'hbs_create_custom_tables' );
function hbs_create_custom_tables() {
    global $wpdb; require_once( ABSPATH . 'wp-admin/includes/upgrade.php' ); $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'hbs_bookings';
    $sql = "CREATE TABLE $table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, service_id mediumint(9) NOT NULL, city_id mediumint(9) NOT NULL, location_id mediumint(9) NOT NULL, booking_date date NOT NULL, seats int NOT NULL DEFAULT 1, hours int NOT NULL DEFAULT 1, rooms int NOT NULL DEFAULT 1, total_amount decimal(10,2) NOT NULL, customer_name varchar(255) NOT NULL, customer_email varchar(255) NOT NULL, customer_phone varchar(50) NOT NULL, customer_company varchar(255), razorpay_order_id varchar(255), razorpay_payment_id varchar(255), status varchar(20) DEFAULT 'pending', created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY  (id) ) $charset_collate;";
    dbDelta( $sql ); update_option( 'hbs_db_version', HBS_VERSION );
}

/* ==========================================================================
   3. ADMIN MENU & SETTINGS PAGE
   ========================================================================== */

add_action( 'admin_menu', 'hbs_admin_menu' );
function hbs_admin_menu() {
    add_menu_page( 'ThinkHaus Bookings', 'ThinkHaus Bookings', 'manage_options', 'hbs-bookings', 'hbs_bookings_page', 'dashicons-calendar-alt', 26 );
    add_submenu_page( 'hbs-bookings', 'Settings & Calendar', 'Settings & Calendar', 'manage_options', 'hbs-settings', 'hbs_settings_page' );
}

function hbs_settings_page() {
    $services = get_posts( array( 'post_type' => 'service', 'numberposts' => -1 ) );
    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1 ) );
    $svc_prices = get_option('hbs_service_prices', array()); $loc_prices = get_option('hbs_location_prices', array());
    $loc_rooms = get_option('hbs_location_rooms', array()); // New
    ?>
    <div class="hbs-admin-wrap">
        <div class="hbs-admin-header"><h1>ThinkHaus Booking</h1><span class="hbs-admin-header-sub">Settings & Calendar</span></div>
        <div class="hbs-admin-columns">
            <div class="hbs-admin-main">
                <form method="post" action="options.php">
                    <div class="hbs-card">
                        <div class="hbs-card-title">General</div>
                        <?php settings_fields( 'hbs_settings_group' ); ?>
                        <div class="hbs-form-row"><label>Admin Email</label><div class="hbs-form-row-control"><input type="email" name="hbs_admin_email" class="regular-text" value="<?php echo esc_attr( get_option('hbs_admin_email', get_option('admin_email')) ); ?>" required /></div></div>
                        <div class="hbs-form-row"><label>Razorpay Key ID</label><div class="hbs-form-row-control"><input type="text" name="hbs_razorpay_key" class="regular-text" value="<?php echo esc_attr( get_option('hbs_razorpay_key') ); ?>" required /></div></div>
                        <div class="hbs-form-row"><label>Razorpay Secret</label><div class="hbs-form-row-control"><input type="text" name="hbs_razorpay_secret" class="regular-text" value="<?php echo esc_attr( get_option('hbs_razorpay_secret') ); ?>" required /></div></div>
                        <div class="hbs-form-row"><label>Global Default Price (₹)</label><div class="hbs-form-row-control"><input type="number" name="hbs_default_price" step="0.01" class="regular-text" value="<?php echo esc_attr( get_option('hbs_default_price', '500.00') ); ?>" required /></div></div>
                        <div class="hbs-form-row"><label>Global Default Max Rooms</label><div class="hbs-form-row-control"><input type="number" name="hbs_max_rooms" class="regular-text" value="<?php echo esc_attr( get_option('hbs_max_rooms', '5') ); ?>" min="1" required /><p class="description">Fallback if no specific location room limit is set below.</p></div></div>
                        <div class="hbs-form-row"><label>Limited Threshold (Rooms)</label><div class="hbs-form-row-control"><input type="number" name="hbs_limited_seats" class="regular-text" value="<?php echo esc_attr( get_option('hbs_limited_seats', '2') ); ?>" required /><p class="description">Shows Blue Dot & limits dropdown if available rooms fall to/below this number. Also applies when manually marking a date as "Partially Booked".</p></div></div>
                        <div class="hbs-form-row"><label>Max Booking Hours</label><div class="hbs-form-row-control"><input type="number" name="hbs_max_hours" class="regular-text" value="<?php echo esc_attr( get_option('hbs_max_hours', '8') ); ?>" min="1" required /></div></div>
                        <div class="hbs-card-footer"><?php submit_button('Save Settings', 'primary', 'submit', false); ?></div>
                    </div>
                </form>
                
                <!-- NEW: Location Room Capacity Section -->
                <div class="hbs-card">
                    <div class="hbs-card-title">Location Room Capacity</div>
                    <p class="description" style="margin-bottom: 20px;">Set exactly how many rooms are available at a specific location for a specific service. (Private Suites ignore this).</p>
                    <div class="hbs-form-row" style="border-bottom: none; padding-bottom: 0;">
                        <label>Add Room Rule</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                            <select id="hbs_room_service" data-key="service_id" style="flex:1; min-width: 150px;"><option value="">Select Service</option><?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?></select>
                            <select id="hbs_room_city" data-key="city_id" style="flex:1; min-width: 120px;"><option value="">Select City</option><?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?></select>
                            <select id="hbs_room_location" data-key="location_id" style="flex:1; min-width: 150px;"><option value="">Select Location</option></select>
                            <input type="number" id="hbs_room_amount" data-key="price" placeholder="Max Rooms" min="1" style="width:120px;" />
                            <button type="button" class="button" id="hbs_save_room_rule">Add / Update</button>
                        </div>
                    </div>
                    <ul class="hbs-price-list" id="hbs_room_list">
                        <?php foreach($loc_rooms as $key => $rooms): list($sid, $lid) = explode('_', $key); if(!get_post_status($sid) || !get_post_status($lid)) continue; ?>
                            <li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span><span class="hbs-price-rule-value"><?php echo esc_html($rooms); ?> Rooms</span><a href="#" class="hbs-price-delete" data-type="room" data-id="<?php echo esc_attr($key); ?>">Delete</a></li>
                        <?php endforeach; ?>
                        <?php if(empty($loc_rooms)): ?><li class="hbs-price-empty">No room limits set. Using global default.</li><?php endif; ?>
                    </ul>
                </div>

                <div class="hbs-card">
                    <div class="hbs-card-title">Service Default Pricing</div>
                    <div class="hbs-form-row" style="border-bottom: none; padding-bottom: 0;">
                        <label>Add Price Rule</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px; align-items:center;">
                            <select id="hbs_svc_price_service" style="flex:1;"><option value="">Select Service</option><?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?></select>
                            <input type="number" id="hbs_svc_price_amount" placeholder="₹ Price" style="width:120px;" />
                            <button type="button" class="button" id="hbs_save_svc_price">Add / Update</button>
                        </div>
                    </div>
                    <ul class="hbs-price-list" id="hbs_svc_price_list">
                        <?php foreach($svc_prices as $sid => $price): if(!get_post_status($sid)) continue; ?><li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?></span><span class="hbs-price-rule-value">₹<?php echo esc_html($price); ?></span><a href="#" class="hbs-price-delete" data-type="svc" data-id="<?php echo esc_attr($sid); ?>">Delete</a></li><?php endforeach; ?>
                        <?php if(empty($svc_prices)): ?><li class="hbs-price-empty">No service prices set yet.</li><?php endif; ?>
                    </ul>
                </div>

                <div class="hbs-card">
                    <div class="hbs-card-title">Specific Location Pricing</div>
                    <div class="hbs-form-row" style="border-bottom: none; padding-bottom: 0;">
                        <label>Add Price Rule</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                            <select id="hbs_loc_price_service" style="flex:1; min-width: 150px;"><option value="">Select Service</option><?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?></select>
                            <select id="hbs_loc_price_city" style="flex:1; min-width: 120px;"><option value="">Select City</option><?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?></select>
                            <select id="hbs_loc_price_location" style="flex:1; min-width: 150px;"><option value="">Select Location</option></select>
                            <input type="number" id="hbs_loc_price_amount" placeholder="₹ Price" style="width:120px;" />
                            <button type="button" class="button" id="hbs_save_loc_price">Add / Update</button>
                        </div>
                    </div>
                    <ul class="hbs-price-list" id="hbs_loc_price_list">
                        <?php foreach($loc_prices as $key => $price): list($sid, $lid) = explode('_', $key); if(!get_post_status($sid) || !get_post_status($lid)) continue; ?><li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span><span class="hbs-price-rule-value">₹<?php echo esc_html($price); ?></span><a href="#" class="hbs-price-delete" data-type="loc" data-id="<?php echo esc_attr($key); ?>">Delete</a></li><?php endforeach; ?>
                        <?php if(empty($loc_prices)): ?><li class="hbs-price-empty">No location prices set yet.</li><?php endif; ?>
                    </ul>
                </div>

                <div class="hbs-card">
                    <div class="hbs-card-title-row"><div class="hbs-card-title">Calendar Availability</div><span class="hbs-autosave-indicator" id="hbs-save-indicator"></span></div>
                    <div class="hbs-form-row">
                        <label>Service & Location</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px;">
                            <select id="hbs_admin_service" style="flex:1;"><option value="">Select Service</option><?php foreach($services as $s) echo "<option value='{$s->ID}'>{$s->post_title}</option>"; ?></select>
                            <select id="hbs_admin_city" style="flex:1;"><option value="">Select City</option><?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?></select>
                            <select id="hbs_admin_location" style="flex:1;"><option value="">Select Location</option></select>
                        </div>
                    </div>
                    <div class="hbs-legend">
                        <span class="hbs-legend-item"><span class="hbs-legend-dot hbs-dot-available"></span>Available</span>
                        <span class="hbs-legend-item"><span class="hbs-legend-dot hbs-dot-limited"></span>Limited</span>
                        <span class="hbs-legend-item"><span class="hbs-legend-dot hbs-dot-full"></span>Full / Blocked</span>
                    </div>
                    <p class="description" style="margin-top: -10px; margin-bottom: 15px; font-size: 13px; color: #50575e;"><strong>Calendar Controls:</strong> 1 click = Partially Booked (Limits rooms to threshold) &nbsp;|&nbsp; 2 clicks = Blocked/Full &nbsp;|&nbsp; 3+ clicks = Reset to Available</p>
                    <div id="hbs-admin-calendar"><div class="hbs-cal-loading">Select a location to load calendar...</div></div>
                </div>
            </div>
            <div class="hbs-admin-side">
                <div class="hbs-card hbs-card-compact"><div class="hbs-card-title">Shortcode</div><code class="hbs-shortcode-box">[thinkhaus_booking]</code></div>
                <div class="hbs-card hbs-card-compact"><div class="hbs-card-title">Quick Links</div><p><a href="<?php echo admin_url('admin.php?page=hbs-bookings'); ?>">View Bookings</a></p></div>
            </div>
        </div>
    </div>
    <?php
}

add_action( 'admin_init', 'hbs_register_settings' );
function hbs_register_settings() {
    register_setting( 'hbs_settings_group', 'hbs_razorpay_key' ); register_setting( 'hbs_settings_group', 'hbs_razorpay_secret' );
    register_setting( 'hbs_settings_group', 'hbs_admin_email' ); register_setting( 'hbs_settings_group', 'hbs_default_price' );
    register_setting( 'hbs_settings_group', 'hbs_max_rooms' ); register_setting( 'hbs_settings_group', 'hbs_limited_seats' );
    register_setting( 'hbs_settings_group', 'hbs_max_hours' );
}

/* ==========================================================================
   4. ADMIN ASSETS
   ========================================================================== */

add_action( 'admin_enqueue_scripts', 'hbs_enqueue_admin_assets' );
function hbs_enqueue_admin_assets( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'hbs-settings' ) return;
    wp_enqueue_style( 'hbs-admin-css', HBS_PLUGIN_URL . 'assets/css/admin.css', array(), HBS_VERSION );
    wp_enqueue_script( 'hbs-admin-js', HBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), HBS_VERSION, true );
    wp_localize_script( 'hbs-admin-js', 'hbs_admin_obj', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'hbs_admin_nonce' ) ));
}

/**
 * CSV Export handler.
 * Streams all bookings (current DB state, same columns as the listing table
 * plus a few extra reference fields) as a downloadable CSV file.
 * Hooked to admin-post.php so it can just be linked to — no changes to any
 * existing AJAX handlers or frontend behaviour.
 */
add_action( 'admin_post_hbs_export_bookings_csv', 'hbs_export_bookings_csv' );
function hbs_export_bookings_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'thinkhaus' ), 403 );
    }
    check_admin_referer( 'hbs_export_bookings_csv' );

    global $wpdb;
    $table    = $wpdb->prefix . 'hbs_bookings';
    $bookings = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=thinkhaus-bookings-' . gmdate( 'Y-m-d-His' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );

    // BOM so Excel opens UTF-8 (₹ symbol etc.) correctly.
    fputs( $out, "\xEF\xBB\xBF" );

    fputcsv( $out, array(
        'ID', 'Customer', 'Email', 'Phone', 'Company', 'Service', 'Location',
        'Date', 'Hours', 'Rooms', 'Amount', 'Razorpay Order ID',
        'Razorpay Payment ID', 'Status', 'Created At',
    ) );

    foreach ( $bookings as $b ) {
        fputcsv( $out, array(
            $b->id,
            $b->customer_name,
            $b->customer_email,
            $b->customer_phone,
            $b->customer_company,
            get_the_title( $b->service_id ),
            get_the_title( $b->location_id ),
            $b->booking_date,
            $b->hours,
            $b->rooms,
            $b->total_amount,
            $b->razorpay_order_id,
            $b->razorpay_payment_id,
            ucfirst( $b->status ),
            $b->created_at,
        ) );
    }

    fclose( $out );
    exit;
}

/**
 * Delete handler — used for BOTH the single row "Delete" link and the
 * bulk-action form on the listing page. Redirects back to the listing
 * page with a count of how many rows were removed.
 */
add_action( 'admin_post_hbs_delete_bookings', 'hbs_delete_bookings' );
function hbs_delete_bookings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'thinkhaus' ), 403 );
    }
    check_admin_referer( 'hbs_bookings_bulk_action' );

    global $wpdb;
    $table = $wpdb->prefix . 'hbs_bookings';

    $ids = array();
    if ( ! empty( $_REQUEST['booking_id'] ) ) {
        // Single row delete link.
        $ids[] = intval( $_REQUEST['booking_id'] );
    } elseif ( ! empty( $_REQUEST['booking_ids'] ) && is_array( $_REQUEST['booking_ids'] ) ) {
        // Bulk delete checkboxes.
        $ids = array_map( 'intval', $_REQUEST['booking_ids'] );
    }
    $ids = array_values( array_unique( array_filter( $ids ) ) );

    $deleted = 0;
    if ( ! empty( $ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values passed through prepare().
        $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );
    }

    $redirect = add_query_arg(
        array(
            'page'        => 'hbs-bookings',
            'hbs_deleted' => $deleted,
        ),
        admin_url( 'admin.php' )
    );
    wp_safe_redirect( $redirect );
    exit;
}

function hbs_bookings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb; $table = $wpdb->prefix . 'hbs_bookings';
    $bookings = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

    $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=hbs_export_bookings_csv' ), 'hbs_export_bookings_csv' );
    ?>
    <div class="wrap">
        <h2>ThinkHaus Bookings</h2>

        <?php if ( isset( $_GET['hbs_deleted'] ) ) :
            $deleted_count = intval( $_GET['hbs_deleted'] ); ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    if ( $deleted_count > 0 ) {
                        printf(
                            /* translators: %d: number of bookings deleted */
                            esc_html( _n( '%d booking deleted.', '%d bookings deleted.', $deleted_count, 'thinkhaus' ) ),
                            $deleted_count
                        );
                    } else {
                        esc_html_e( 'No bookings were deleted.', 'thinkhaus' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p style="margin: 15px 0;">
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                Export CSV
            </a>
        </p>

        <?php if ( empty( $bookings ) ) : ?>
            <p><?php esc_html_e( 'No bookings found.', 'thinkhaus' ); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbs-bookings-form">
                <input type="hidden" name="action" value="hbs_delete_bookings" />
                <?php wp_nonce_field( 'hbs_bookings_bulk_action' ); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="hbs_bulk_action" id="hbs-bulk-action-selector">
                            <option value="-1">Bulk actions</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="button action" id="hbs-bulk-apply">Apply</button>
                    </div>
                </div>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="hbs-select-all" />
                            </td>
                            <th>Customer</th><th>Email</th><th>Phone</th><th>Service</th><th>Location</th><th>Date</th><th>Hours</th><th>Rooms</th><th>Amount</th><th>Payment ID</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $bookings as $b ) :
                            $row_delete_url = wp_nonce_url(
                                admin_url( 'admin-post.php?action=hbs_delete_bookings&booking_id=' . intval( $b->id ) ),
                                'hbs_bookings_bulk_action'
                            );
                            ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr( $b->id ); ?>" class="hbs-row-checkbox" />
                                </th>
                                <td><?php echo esc_html( $b->customer_name ); ?></td>
                                <td><?php echo esc_html( $b->customer_email ); ?></td>
                                <td><?php echo esc_html( $b->customer_phone ); ?></td>
                                <td><?php echo esc_html( get_the_title( $b->service_id ) ); ?></td>
                                <td><?php echo esc_html( get_the_title( $b->location_id ) ); ?></td>
                                <td><?php echo esc_html( $b->booking_date ); ?></td>
                                <td><?php echo esc_html( $b->hours ); ?></td>
                                <td><?php echo esc_html( $b->rooms ); ?></td>
                                <td>₹<?php echo esc_html( $b->total_amount ); ?></td>
                                <td><?php echo esc_html( $b->razorpay_payment_id ); ?></td>
                                <td><?php echo esc_html( ucfirst( $b->status ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $row_delete_url ); ?>"
                                       class="hbs-row-delete-link"
                                       style="color:#b32d2e;"
                                       onclick="return confirm('Delete this booking? This cannot be undone.');">
                                       Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <script>
            (function () {
                var selectAll = document.getElementById('hbs-select-all');
                var form = document.getElementById('hbs-bookings-form');
                if ( selectAll && form ) {
                    selectAll.addEventListener('change', function () {
                        var boxes = form.querySelectorAll('.hbs-row-checkbox');
                        for (var i = 0; i < boxes.length; i++) {
                            boxes[i].checked = selectAll.checked;
                        }
                    });
                }
                if ( form ) {
                    form.addEventListener('submit', function (e) {
                        var action = document.getElementById('hbs-bulk-action-selector').value;
                        var checked = form.querySelectorAll('.hbs-row-checkbox:checked');
                        if ( action === '-1' ) {
                            e.preventDefault();
                            alert('Please choose a bulk action.');
                            return false;
                        }
                        if ( checked.length === 0 ) {
                            e.preventDefault();
                            alert('Please select at least one booking.');
                            return false;
                        }
                        if ( action === 'delete' ) {
                            return confirm('Delete ' + checked.length + ' selected booking(s)? This cannot be undone.');
                        }
                    });
                }
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
}

/* ==========================================================================
   6. FRONTEND SHORTCODE
   ========================================================================== */

add_shortcode( 'thinkhaus_booking', 'hbs_render_booking_form' );
function hbs_render_booking_form( $atts ) {
    $atts = shortcode_atts( array( 'service_id' => '', 'city_id' => '', 'location_id' => '' ), $atts, 'thinkhaus_booking' );
    
    if ( is_singular( 'service' ) ) $atts['service_id'] = get_the_ID();
    if ( is_singular( 'city' ) ) { 
        if ( wp_get_post_parent_id( get_the_ID() ) ) { 
            $atts['location_id'] = get_the_ID(); 
            $atts['city_id'] = wp_get_post_parent_id( get_the_ID() ); 
        } else { 
            $atts['city_id'] = get_the_ID(); 
        } 
    }
    
    if ( isset($_GET['service']) ) $atts['service_id'] = intval($_GET['service']);
    if ( isset($_GET['city']) ) $atts['city_id'] = intval($_GET['city']);
    
    // Handle URL location parameter (supports both IDs and slugs)
    if ( isset( $_GET['location'] ) ) {
        $loc_val = sanitize_title( $_GET['location'] );
        if ( ! is_numeric( $loc_val ) ) {
            $loc_posts = get_posts( array(
                'post_type'   => 'city',
                'name'        => $loc_val,
                'numberposts' => 1
            ) );
            if ( ! empty( $loc_posts ) ) {
                $atts['location_id'] = $loc_posts[0]->ID;
                $atts['city_id'] = wp_get_post_parent_id( $loc_posts[0]->ID );
            }
        } else {
            $atts['location_id'] = intval( $loc_val );
            if ( $atts['location_id'] ) {
                $atts['city_id'] = wp_get_post_parent_id( $atts['location_id'] );
            }
        }
    }

    wp_enqueue_style( 'hbs-front-css', HBS_PLUGIN_URL . 'assets/css/frontend.css', array(), HBS_VERSION );
    wp_enqueue_script( 'razorpay-checkout', 'https://checkout.razorpay.com/v1/checkout.js', array(), null, true );
    wp_enqueue_script( 'hbs-front-js', HBS_PLUGIN_URL . 'assets/js/frontend.js', array('jquery', 'razorpay-checkout'), HBS_VERSION, true );

    // Determine if we are in "locked" mode
    $is_locked = false;
    if ( is_singular( 'service' ) && isset( $_GET['location'] ) && ! empty( $atts['location_id'] ) ) {
        $is_locked = true;
    }

    wp_localize_script( 'hbs-front-js', 'hbs_obj', array(
        'ajax_url'           => admin_url( 'admin-ajax.php' ), 
        'nonce'              => wp_create_nonce('hbs_nonce'), 
        'razorpay_key'       => get_option('hbs_razorpay_key'),
        'pre_location'       => $atts['location_id'], 
        'pre_service'        => $atts['service_id'], 
        'pre_city'           => $atts['city_id'],
        'max_hours'          => get_option('hbs_max_hours', '8'), 
        'private_service_id' => 357,
        'is_locked'          => $is_locked
    ) );

    $services = get_posts( array(
        'post_type'     => 'service',
        'numberposts'   => -1,
        'post__not_in'  => array(357),
    ) );
    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1 ) );

    // Prepare Info Bar Data
    $info_service_name = ! empty( $atts['service_id'] ) ? get_the_title( $atts['service_id'] ) : '';
    $info_city_name = ! empty( $atts['city_id'] ) ? get_the_title( $atts['city_id'] ) : '';
    $info_location_name = ! empty( $atts['location_id'] ) ? get_the_title( $atts['location_id'] ) : '';
    $info_price = '0.00';
    if ( ! empty( $atts['service_id'] ) && ! empty( $atts['location_id'] ) ) {
        $info_price = hbs_calculate_price( $atts['service_id'], $atts['location_id'] );
    }

    ob_start(); ?>
    <div class="hbs-modal-overlay is-open">
        <div class="hbs-modal">
            <h2 class="hbs-form-heading">Book a Space!</h2>
            
            <?php if ( $is_locked ) : ?>
                <div class="hbs-locked-info-bar">
                    <div class="hbs-locked-item"><strong>Service:</strong> <?php echo esc_html( $info_service_name ); ?></div>
                    <div class="hbs-locked-item"><strong>City:</strong> <?php echo esc_html( $info_city_name ); ?></div>
                    <div class="hbs-locked-item"><strong>Location:</strong> <?php echo esc_html( $info_location_name ); ?></div>
                    <div class="hbs-locked-item"><strong>Price:</strong> ₹<?php echo esc_html( number_format( $info_price, 2 ) ); ?>/hr</div>
                </div>
                <div class="hbs-price-tag" style="display:none;">Price: ₹<span id="hbs-price-display">0.00</span> / Hour</div>
            <?php else: ?>
                <div class="hbs-price-tag">Price: ₹<span id="hbs-price-display">0.00</span> / Hour</div>
            <?php endif; ?>

            <form id="hbs-booking-form" class="hbs-form-grid">
                <?php if ( $is_locked ) : ?>
                    <input type="hidden" name="service" value="<?php echo esc_attr( $atts['service_id'] ); ?>">
                    <input type="hidden" name="city" value="<?php echo esc_attr( $atts['city_id'] ); ?>">
                    <input type="hidden" name="location" value="<?php echo esc_attr( $atts['location_id'] ); ?>">
                <?php endif; ?>

                <div class="hbs-field" style="display:none;">
                    <!-- <label>Service</label> -->
                    <div class="hbs-select-wrap">
                        <select name="service" id="hbs-service" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <option value="">Select Service</option>
                            <?php 
                            $service_found = false;
                            foreach ( $services as $service ) :
                                if ( $atts['service_id'] == $service->ID ) $service_found = true;
                            ?>
                                <option value="<?php echo esc_attr($service->ID); ?>" <?php selected( $atts['service_id'], $service->ID ); ?>><?php echo esc_html( $service->post_title ); ?></option>
                            <?php endforeach; 
                            if ( ! $service_found && ! empty( $atts['service_id'] ) ) {
                                echo '<option value="' . esc_attr( $atts['service_id'] ) . '" selected>' . esc_html( $info_service_name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="hbs-field" style="display:none;">
                    <!-- <label>City</label> -->
                    <div class="hbs-select-wrap">
                        <select name="city" id="hbs-city" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <option value="">Select City</option>
                            <?php 
                            $city_found = false;
                            foreach ( $cities as $city ) :
                                if ( $atts['city_id'] == $city->ID ) $city_found = true;
                            ?>
                                <option value="<?php echo esc_attr($city->ID); ?>" <?php selected( $atts['city_id'], $city->ID ); ?>><?php echo esc_html( $city->post_title ); ?></option>
                            <?php endforeach;
                            if ( ! $city_found && ! empty( $atts['city_id'] ) ) {
                                echo '<option value="' . esc_attr( $atts['city_id'] ) . '" selected>' . esc_html( $info_city_name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="hbs-field" style="display:none;">
                    <!-- <label>Location</label> -->
                    <div class="hbs-select-wrap">
                        <select name="location" id="hbs-location" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <?php if ( $is_locked ) : ?>
                                <option value="<?php echo esc_attr( $atts['location_id'] ); ?>" selected><?php echo esc_html( $info_location_name ); ?></option>
                            <?php else : ?>
                                <option value="">Select Location</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="hbs-field"><!--<label>Full Name</label>--><input type="text" name="full_name" placeholder="Full Name" required /></div>
                <div class="hbs-field"><!--<label>Company (Optional)</label>--><input type="text" name="company" placeholder="Company (Optional)" /></div>
                <div class="hbs-field"><!--<label>Phone Number</label>--><input type="tel" name="phone" placeholder="Phone Number" required /></div>
                <div class="hbs-field"><!--<label>Email</label>--><input type="email" name="email" placeholder="Email" required /></div>
                <div class="hbs-field"><!--<label>Hours</label>--><div class="hbs-select-wrap"><select name="hours" id="hbs-hours" required></select></div></div>
   
                <div class="hbs-field"><!--<label>Date</label>--><div class="hbs-date-field"><input type="text" name="date" id="hbs-date" required readonly placeholder="Select available date" /><div class="hbs-date-icon"><span class="dashicons dashicons-calendar-alt"></span></div><div class="hbs-calendar-popover" id="hbs-calendar-popover"><div class="hbs-cal-header"><button type="button" class="hbs-cal-nav-btn" data-dir="prev">&laquo;</button><span class="hbs-cal-title"></span><button type="button" class="hbs-cal-nav-btn" data-dir="next">&raquo;</button></div><div class="hbs-cal-grid"><div class="hbs-cal-dow is-weekend">Su</div><div class="hbs-cal-dow">Mo</div><div class="hbs-cal-dow">Tu</div><div class="hbs-cal-dow">We</div><div class="hbs-cal-dow">Th</div><div class="hbs-cal-dow">Fr</div><div class="hbs-cal-dow is-weekend">Sa</div></div><div class="hbs-cal-grid" id="hbs-cal-days"></div></div></div></div>
                <div class="hbs-field" id="hbs-rooms-field-wrap"><!--<label>No. of Rooms</label>--><div class="hbs-select-wrap"><select name="rooms" id="hbs-rooms" required><option value="">Select Date First</option></select></div></div>
                <div class="hbs-form-footer hbs-field-full"><button type="submit" class="hbs-submit-btn">Book Now</button><div class="hbs-form-message" id="hbs-form-message"></div></div>
            </form>
        </div>
    </div>
    <?php return ob_get_clean();
}

/* ==========================================================================
   7. AJAX HANDLERS
   ========================================================================== */

add_action( 'wp_ajax_hbs_get_locations', 'hbs_get_locations' );
add_action( 'wp_ajax_nopriv_hbs_get_locations', 'hbs_get_locations' );
function hbs_get_locations() {
    $locations = get_posts( array( 'post_type' => 'city', 'post_parent' => intval( $_POST['city_id'] ), 'numberposts' => -1 ) );
    $html = '<option value="">Select Location</option>';
    foreach ( $locations as $loc ) $html .= '<option value="' . esc_attr($loc->ID) . '">' . esc_html( $loc->post_title ) . '</option>';
    echo $html; wp_die();
}

add_action( 'wp_ajax_hbs_get_price', 'hbs_get_price' );
add_action( 'wp_ajax_nopriv_hbs_get_price', 'hbs_get_price' );
function hbs_get_price() { echo json_encode( array( 'success' => true, 'price' => hbs_calculate_price( intval($_POST['service_id']), intval($_POST['location_id']) ) ) ); wp_die(); }

add_action( 'wp_ajax_hbs_get_calendar', 'hbs_get_calendar' );
add_action( 'wp_ajax_nopriv_hbs_get_calendar', 'hbs_get_calendar' );
function hbs_get_calendar() {
    global $wpdb;
    $service_id = intval( $_POST['service_id'] ); $location_id = intval( $_POST['location_id'] );
    $month = intval( $_POST['month'] ); $year = intval( $_POST['year'] );
    $table_book = $wpdb->prefix . 'hbs_bookings';
    
    $max_rooms = hbs_get_max_rooms( $service_id, $location_id );
    $threshold = get_option('hbs_limited_seats', 2); // Acts as room threshold
    
    $calendar = new HBS_Calendar( "s{$service_id}_l{$location_id}" ); $manual_statuses = $calendar->get_all();
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $start_date = sprintf('%04d-%02d-01', $year, $month); $end_date = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
    
    // Calculate based on ROOMS booked
    $bookings = $wpdb->get_results( $wpdb->prepare("SELECT booking_date, SUM(rooms) as booked_rooms FROM $table_book WHERE service_id = %d AND location_id = %d AND booking_date BETWEEN %s AND %s AND status = 'confirmed' GROUP BY booking_date", $service_id, $location_id, $start_date, $end_date) );
    $booked_map = array(); foreach($bookings as $b) $booked_map[$b->booking_date] = intval($b->booked_rooms);
    
    $calendar_data = array(); $today = current_time('Y-m-d');
    for ($day = 1; $day <= $days_in_month; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); $status = 'available'; $avail_rooms = $max_rooms;
        
        if ($dateStr < $today) { $status = 'past'; } else {
            $booked = isset($booked_map[$dateStr]) ? $booked_map[$dateStr] : 0; 
            $avail_rooms = $max_rooms - $booked;
            
            if (isset($manual_statuses[$dateStr]) && $manual_statuses[$dateStr] === 'full') { $status = 'full'; $avail_rooms = 0; }
            elseif (isset($manual_statuses[$dateStr]) && $manual_statuses[$dateStr] === 'limited') { 
                $status = 'limited'; 
                $avail_rooms = $threshold; // Force to threshold if manually set to blue
            }
            elseif ($avail_rooms <= 0) { $status = 'full'; $avail_rooms = 0; }
            elseif ($avail_rooms <= $threshold) { $status = 'limited'; }
        }
        $calendar_data[$dateStr] = array('status' => $status, 'rooms' => $avail_rooms);
    }
    echo json_encode( array( 'success' => true, 'calendar' => $calendar_data ) ); wp_die();
}

// Generic Save/Delete for Rules (Prices & Rooms)
add_action( 'wp_ajax_hbs_save_price_rule', 'hbs_save_price_rule' );
function hbs_save_price_rule() {
    check_ajax_referer( 'hbs_admin_nonce', 'nonce' ); $type = sanitize_text_field($_POST['type']); $val = floatval($_POST['price']);
    if($type === 'svc') { $arr = get_option('hbs_service_prices', array()); $arr[intval($_POST['service_id'])] = $val; update_option('hbs_service_prices', $arr); }
    elseif($type === 'loc') { $arr = get_option('hbs_location_prices', array()); $arr[intval($_POST['service_id']) . '_' . intval($_POST['location_id'])] = $val; update_option('hbs_location_prices', $arr); }
    elseif($type === 'room') { $arr = get_option('hbs_location_rooms', array()); $arr[intval($_POST['service_id']) . '_' . intval($_POST['location_id'])] = intval($val); update_option('hbs_location_rooms', $arr); }
    hbs_render_rules_list($type); wp_die();
}

add_action( 'wp_ajax_hbs_delete_price_rule', 'hbs_delete_price_rule' );
function hbs_delete_price_rule() {
    check_ajax_referer( 'hbs_admin_nonce', 'nonce' ); $type = sanitize_text_field($_POST['type']); $id = sanitize_text_field($_POST['id']);
    if($type === 'svc') { $arr = get_option('hbs_service_prices', array()); unset($arr[$id]); update_option('hbs_service_prices', $arr); }
    elseif($type === 'loc') { $arr = get_option('hbs_location_prices', array()); unset($arr[$id]); update_option('hbs_location_prices', $arr); }
    elseif($type === 'room') { $arr = get_option('hbs_location_rooms', array()); unset($arr[$id]); update_option('hbs_location_rooms', $arr); }
    hbs_render_rules_list($type); wp_die();
}

function hbs_render_rules_list($type) {
    if($type === 'svc') { $arr = get_option('hbs_service_prices', array()); if(empty($arr)) { echo '<li class="hbs-price-empty">No service prices set.</li>'; return; } foreach($arr as $sid => $p): if(!get_post_status($sid)) continue; ?><li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?></span><span class="hbs-price-rule-value">₹<?php echo esc_html($p); ?></span><a href="#" class="hbs-price-delete" data-type="svc" data-id="<?php echo esc_attr($sid); ?>">Delete</a></li><?php endforeach; }
    elseif($type === 'loc') { $arr = get_option('hbs_location_prices', array()); if(empty($arr)) { echo '<li class="hbs-price-empty">No location prices set.</li>'; return; } foreach($arr as $key => $p): list($sid, $lid) = explode('_', $key); if(!get_post_status($sid) || !get_post_status($lid)) continue; ?><li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span><span class="hbs-price-rule-value">₹<?php echo esc_html($p); ?></span><a href="#" class="hbs-price-delete" data-type="loc" data-id="<?php echo esc_attr($key); ?>">Delete</a></li><?php endforeach; }
    elseif($type === 'room') { $arr = get_option('hbs_location_rooms', array()); if(empty($arr)) { echo '<li class="hbs-price-empty">No room limits set. Using global default.</li>'; return; } foreach($arr as $key => $r): list($sid, $lid) = explode('_', $key); if(!get_post_status($sid) || !get_post_status($lid)) continue; ?><li><span class="hbs-price-rule-name"><?php echo get_the_title($sid); ?> @ <?php echo get_the_title($lid); ?></span><span class="hbs-price-rule-value"><?php echo esc_html($r); ?> Rooms</span><a href="#" class="hbs-price-delete" data-type="room" data-id="<?php echo esc_attr($key); ?>">Delete</a></li><?php endforeach; }
}

add_action( 'wp_ajax_hbs_toggle_date_status', 'hbs_toggle_date_status' );
function hbs_toggle_date_status() {
    check_ajax_referer( 'hbs_admin_nonce', 'nonce' );
    $calendar = new HBS_Calendar( "s".intval($_POST['service_id'])."_l".intval($_POST['location_id']) );
    $calendar->set_status( sanitize_text_field( $_POST['date'] ), sanitize_text_field( $_POST['target_status'] ) );
    echo json_encode( array( 'success' => true ) ); wp_die();
}

// Create Order
add_action( 'wp_ajax_hbs_create_booking', 'hbs_create_booking' );
add_action( 'wp_ajax_nopriv_hbs_create_booking', 'hbs_create_booking' );
function hbs_create_booking() {
    check_ajax_referer( 'hbs_nonce', 'nonce' );
    $email = sanitize_email( $_POST['email'] ); $phone = sanitize_text_field( $_POST['phone'] );
    $email = sanitize_email( $_POST['email'] ); 
 $phone = sanitize_text_field( $_POST['phone'] );

if ( !is_email( $email ) ) { 
    echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid email address.' ) ); 
    wp_die(); 
}
if ( !preg_match( '/^[6-9]\d{9}$/', $phone ) ) { 
    echo json_encode( array( 'success' => false, 'message' => 'Please enter a valid 10-digit Indian phone number.' ) ); 
    wp_die(); 
}

    $service_id = intval( $_POST['service'] ); $location_id = intval( $_POST['location'] ); $date = sanitize_text_field( $_POST['date'] );
    $seats = 1; $hours = intval( $_POST['hours'] ); $rooms = intval( $_POST['rooms'] );
    
    $calendar = new HBS_Calendar( "s{$service_id}_l{$location_id}" );
    if ( $calendar->get_status($date) === 'full' ) { echo json_encode( array( 'success' => false, 'message' => 'This date is fully booked.' ) ); wp_die(); }
    
    $total_amount = hbs_calculate_price( $service_id, $location_id ) * $hours * $rooms;
    $response = wp_remote_post( 'https://api.razorpay.com/v1/orders', array( 'headers' => array( 'Authorization' => 'Basic ' . base64_encode( get_option('hbs_razorpay_key') . ':' . get_option('hbs_razorpay_secret') ), 'Content-Type' => 'application/json' ), 'body' => json_encode( array( 'amount' => $total_amount * 100, 'currency' => 'INR', 'receipt' => 'hbs_' . wp_generate_password(6, false) ) ), 'timeout' => 30 ) );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $body['id'] ) ) {
        echo json_encode( array( 'success' => true, 'order_id' => $body['id'], 'amount' => $total_amount * 100, 'description' => 'ThinkHaus Booking for ' . $date, 'booking_data' => array( 'service' => $service_id, 'city' => intval($_POST['city']), 'location' => $location_id, 'date' => $date, 'seats' => $seats, 'hours' => $hours, 'rooms' => $rooms, 'full_name' => sanitize_text_field($_POST['full_name']), 'email' => $email, 'phone' => $phone, 'company' => sanitize_text_field($_POST['company']) ) ) );
    } else { echo json_encode( array( 'success' => false, 'message' => 'Payment Gateway Error.' ) ); }
    wp_die();
}

// Verify Payment
add_action( 'wp_ajax_hbs_verify_payment', 'hbs_verify_payment' );
add_action( 'wp_ajax_nopriv_hbs_verify_payment', 'hbs_verify_payment' );
function hbs_verify_payment() {
    check_ajax_referer( 'hbs_nonce', 'nonce' );
    global $wpdb; $table_book = $wpdb->prefix . 'hbs_bookings';
    $payment_id = sanitize_text_field( $_POST['payment_id'] ); $order_id = sanitize_text_field( $_POST['order_id'] ); $signature = sanitize_text_field( $_POST['signature'] );
    if ( $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_book WHERE razorpay_payment_id = %s", $payment_id)) ) { echo 'verified'; wp_die(); }
    
    $service_id = intval( $_POST['service'] ); $location_id = intval( $_POST['location'] ); $city_id = intval( $_POST['city'] ); $date = sanitize_text_field( $_POST['date'] );
    $seats = 1; $hours = intval( $_POST['hours'] ); $rooms = intval( $_POST['rooms'] );
    
    if ( hash_hmac( 'sha256', $order_id . '|' . $payment_id, get_option('hbs_razorpay_secret') ) === $signature ) {
        // Check against max rooms
        $max_rooms = hbs_get_max_rooms( $service_id, $location_id );
        $booked_rooms = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(rooms), 0) FROM $table_book WHERE service_id = %d AND location_id = %d AND booking_date = %s AND status = 'confirmed'", $service_id, $location_id, $date));
        if ( ($booked_rooms + $rooms) > $max_rooms ) { echo 'failed'; wp_die(); }
        
        $total_amount = hbs_calculate_price( $service_id, $location_id ) * $hours * $rooms;
        $wpdb->insert( $table_book, array( 'service_id' => $service_id, 'city_id' => $city_id, 'location_id' => $location_id, 'booking_date' => $date, 'seats' => $seats, 'hours' => $hours, 'rooms' => $rooms, 'total_amount' => $total_amount, 'customer_name' => sanitize_text_field( $_POST['full_name'] ), 'customer_email' => sanitize_email( $_POST['email'] ), 'customer_phone' => sanitize_text_field( $_POST['phone'] ), 'customer_company' => sanitize_text_field( $_POST['company'] ), 'razorpay_order_id' => $order_id, 'razorpay_payment_id' => $payment_id, 'status' => 'confirmed' ) );
        
        wp_mail( get_option('hbs_admin_email', get_option('admin_email')), 'New ThinkHaus Booking', "Name: " . sanitize_text_field($_POST['full_name']) . "\nEmail: " . sanitize_email($_POST['email']) . "\nPhone: " . sanitize_text_field($_POST['phone']) . "\nService: " . get_the_title($service_id) . "\nLocation: " . get_the_title($location_id) . "\nDate: {$date}\nHours: {$hours}\nRooms: {$rooms}\nTotal: ₹{$total_amount}\nPayment ID: {$payment_id}" );
        wp_mail( sanitize_email($_POST['email']), 'Your ThinkHaus Booking Confirmed!', "Thank you!\nService: " . get_the_title($service_id) . "\nLocation: " . get_the_title($location_id) . "\nDate: {$date}\nHours: {$hours}\nRooms: {$rooms}\nTotal Paid: ₹{$total_amount}\nPayment ID: {$payment_id}" );
        echo 'verified';
    } else { echo 'failed'; }
    wp_die();
}