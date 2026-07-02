<?php
/**
 * Plugin Name: Private Suites Inquiry
 * Description: Inquiry form for Private Suites with dual date picker, location-based pricing & seats, manager seats, and email notifications.
 * Version: 2.1.0
 * Author: Senior WP Dev
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PSI_VERSION', '2.1.0' );
define( 'PSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* ==========================================================================
   1. HELPERS
   ========================================================================== */

function psi_get_price( $city_id, $location_id ) {
    $loc_prices  = get_option( 'psi_location_prices', array() );
    $key         = $city_id . '_' . $location_id;
    if ( isset( $loc_prices[$key] ) && $loc_prices[$key] !== '' ) {
        return floatval( $loc_prices[$key] );
    }
    return floatval( get_option( 'psi_default_price', '1000' ) );
}

function psi_get_max_seats( $city_id, $location_id ) {
    $loc_seats = get_option( 'psi_location_seats', array() );
    $key       = $city_id . '_' . $location_id;
    if ( isset( $loc_seats[$key] ) && $loc_seats[$key] !== '' ) {
        return intval( $loc_seats[$key] );
    }
    return intval( get_option( 'psi_default_seats', '10' ) );
}

function psi_render_price_list_html() {
    $loc_prices = get_option( 'psi_location_prices', array() );
    if ( empty( $loc_prices ) ) {
        return '<li class="hbs-price-empty">No location prices set yet.</li>';
    }
    $html = '';
    foreach ( $loc_prices as $key => $price ) {
        list( $cid, $lid ) = explode( '_', $key );
        if ( ! get_post_status( $cid ) || ! get_post_status( $lid ) ) continue;
        $html .= '<li>
            <span class="hbs-price-rule-name">' . esc_html( get_the_title( $cid ) ) . ' → ' . esc_html( get_the_title( $lid ) ) . '</span>
            <span class="hbs-price-rule-value">₹' . esc_html( $price ) . '/seat/mo</span>
            <a href="#" class="hbs-price-delete" data-type="price" data-id="' . esc_attr( $key ) . '">Delete</a>
        </li>';
    }
    if ( ! $html ) $html = '<li class="hbs-price-empty">No location prices set yet.</li>';
    return $html;
}

function psi_render_seats_list_html() {
    $loc_seats = get_option( 'psi_location_seats', array() );
    if ( empty( $loc_seats ) ) {
        return '<li class="hbs-price-empty">No location seat limits set yet.</li>';
    }
    $html = '';
    foreach ( $loc_seats as $key => $seats ) {
        list( $cid, $lid ) = explode( '_', $key );
        if ( ! get_post_status( $cid ) || ! get_post_status( $lid ) ) continue;
        $html .= '<li>
            <span class="hbs-price-rule-name">' . esc_html( get_the_title( $cid ) ) . ' → ' . esc_html( get_the_title( $lid ) ) . '</span>
            <span class="hbs-price-rule-value">' . esc_html( $seats ) . ' seats</span>
            <a href="#" class="hbs-price-delete" data-type="seats" data-id="' . esc_attr( $key ) . '">Delete</a>
        </li>';
    }
    if ( ! $html ) $html = '<li class="hbs-price-empty">No location seat limits set yet.</li>';
    return $html;
}

/* ==========================================================================
   2. DATABASE
   ========================================================================== */

register_activation_hook( __FILE__, 'psi_create_custom_tables' );
function psi_create_custom_tables() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'psi_inquiries';
    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        service_id mediumint(9) DEFAULT 0,
        city_id mediumint(9) DEFAULT 0,
        location_id mediumint(9) DEFAULT 0,
        customer_name varchar(255) NOT NULL,
        customer_company varchar(255),
        customer_email varchar(255) NOT NULL,
        customer_phone varchar(50) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        seats int NOT NULL DEFAULT 1,
        manager_seats varchar(10) DEFAULT 'No',
        total_price decimal(10,2) DEFAULT 0,
        status varchar(20) DEFAULT 'new',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql );
}

/* ==========================================================================
   3. ADMIN MENU
   ========================================================================== */

add_action( 'admin_menu', 'psi_admin_menu' );
function psi_admin_menu() {
    add_menu_page( 'Suite Inquiries', 'Suite Inquiries', 'manage_options', 'psi-inquiries', 'psi_inquiries_list_page', 'dashicons-email-alt', 27 );
    add_submenu_page( 'psi-inquiries', 'All Inquiries', 'All Inquiries', 'manage_options', 'psi-inquiries', 'psi_inquiries_list_page' );
    add_submenu_page( 'psi-inquiries', 'Settings', 'Settings', 'manage_options', 'psi-settings', 'psi_settings_page' );
}

/* ==========================================================================
   4. SETTINGS PAGE (hbs-admin styled)
   ========================================================================== */

function psi_settings_page() {
    $cities = get_posts( array( 'post_type' => 'city', 'post_parent' => 0, 'numberposts' => -1 ) );
    ?>
    <div class="hbs-admin-wrap">
        <div class="hbs-admin-header">
            <h1>Private Suites Inquiry</h1>
            <span class="hbs-admin-header-sub">Settings</span>
        </div>

        <div class="hbs-admin-columns">
            <div class="hbs-admin-main">

                <!-- General Settings -->
                <div class="hbs-card">
                    <div class="hbs-card-title">General Settings</div>
                    <form method="post" action="options.php">
                        <?php settings_fields( 'psi_settings_group' ); ?>

                        <div class="hbs-form-row">
                            <label>Admin Name</label>
                            <div class="hbs-form-row-control">
                                <input type="text" name="psi_admin_name" class="regular-text" value="<?php echo esc_attr( get_option('psi_admin_name', get_option('blogname')) ); ?>" />
                                <p class="description">Name displayed as sender in the inquiry email.</p>
                            </div>
                        </div>
                        <div class="hbs-form-row">
                            <label>Global Default Price</label>
                            <div class="hbs-form-row-control">
                                <input type="number" name="psi_default_price" class="regular-text" step="0.01" value="<?php echo esc_attr( get_option('psi_default_price', '1000') ); ?>" required />
                                <p class="description">₹ per seat per month — fallback when no location price is set.</p>
                            </div>
                        </div>
                        <div class="hbs-form-row">
                            <label>Global Default Seats</label>
                            <div class="hbs-form-row-control">
                                <input type="number" name="psi_default_seats" class="regular-text" value="<?php echo esc_attr( get_option('psi_default_seats', '10') ); ?>" required />
                                <p class="description">Max seats available — fallback when no location seat rule is set.</p>
                            </div>
                        </div>
                        <div class="hbs-form-row">
                            <label>Minimum Stay</label>
                            <div class="hbs-form-row-control">
                                <input type="number" name="psi_min_days" class="regular-text" value="<?php echo esc_attr( get_option('psi_min_days', '1') ); ?>" min="1" />
                                <p class="description">Minimum days between start and end date.</p>
                            </div>
                        </div>
                        <div class="hbs-form-row">
                            <label>Maximum Stay</label>
                            <div class="hbs-form-row-control">
                                <input type="number" name="psi_max_days" class="regular-text" value="<?php echo esc_attr( get_option('psi_max_days', '30') ); ?>" min="1" />
                                <p class="description">Maximum selectable days from start date (default 30).</p>
                            </div>
                        </div>
                        <div class="hbs-form-row">
                            <label>Success Message</label>
                            <div class="hbs-form-row-control">
                                <textarea name="psi_success_message" rows="2" class="large-text"><?php echo esc_textarea( get_option('psi_success_message', 'Thank you for your inquiry! We will get back to you within 24 hours.') ); ?></textarea>
                            </div>
                        </div>

                        <div class="hbs-card-footer">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                </div>

                <!-- Location Pricing -->
                <div class="hbs-card">
                    <div class="hbs-card-title">Location Pricing</div>
                    <p class="description" style="margin-bottom:16px;">Set price per seat per month for a specific location. Overrides the global default price.</p>
                    <div class="hbs-form-row" style="border-bottom:none; padding-bottom:0;">
                        <label>Add Price Rule</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <select id="psi_price_city" style="flex:1; min-width:140px;">
                                <option value="">— Select City —</option>
                                <?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?>
                            </select>
                            <select id="psi_price_location" style="flex:1; min-width:160px;">
                                <option value="">— Select Location —</option>
                            </select>
                            <input type="number" id="psi_price_amount" placeholder="₹ / seat / month" style="width:160px;" />
                            <button type="button" class="button" id="save_psi_price">Add / Update</button>
                        </div>
                    </div>
                    <ul class="hbs-price-list" id="psi_price_list">
                        <?php echo psi_render_price_list_html(); ?>
                    </ul>
                </div>

                <!-- Seat Availability -->
                <div class="hbs-card">
                    <div class="hbs-card-title">Seat Availability</div>
                    <p class="description" style="margin-bottom:16px;">Set maximum seats available at a specific location. Overrides the global default seats.</p>
                    <div class="hbs-form-row" style="border-bottom:none; padding-bottom:0;">
                        <label>Add Seat Rule</label>
                        <div class="hbs-form-row-control" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <select id="psi_seats_city" style="flex:1; min-width:140px;">
                                <option value="">— Select City —</option>
                                <?php foreach($cities as $c) echo "<option value='{$c->ID}'>{$c->post_title}</option>"; ?>
                            </select>
                            <select id="psi_seats_location" style="flex:1; min-width:160px;">
                                <option value="">— Select Location —</option>
                            </select>
                            <input type="number" id="psi_seats_amount" placeholder="Max seats" style="width:120px;" min="1" />
                            <button type="button" class="button" id="save_psi_seats">Add / Update</button>
                        </div>
                    </div>
                    <ul class="hbs-price-list" id="psi_seats_list">
                        <?php echo psi_render_seats_list_html(); ?>
                    </ul>
                </div>

            </div>

            <div class="hbs-admin-side">
                <div class="hbs-card hbs-card-compact">
                    <div class="hbs-card-title">Shortcode</div>
                    <code class="hbs-shortcode-box">[private_suites_inquiry]</code>
                </div>
                <div class="hbs-card hbs-card-compact">
                    <div class="hbs-card-title">Quick Links</div>
                    <p><a href="<?php echo admin_url('admin.php?page=psi-inquiries'); ?>">View Inquiries</a></p>
                    <p style="margin-top:6px;"><a href="<?php echo admin_url('options-general.php'); ?>">Admin Email Settings</a></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

add_action( 'admin_init', 'psi_register_settings' );
function psi_register_settings() {
    register_setting( 'psi_settings_group', 'psi_admin_name' );
    register_setting( 'psi_settings_group', 'psi_default_price' );
    register_setting( 'psi_settings_group', 'psi_default_seats' );
    register_setting( 'psi_settings_group', 'psi_min_days' );
    register_setting( 'psi_settings_group', 'psi_max_days' );
    register_setting( 'psi_settings_group', 'psi_success_message' );
}

/* ==========================================================================
   5. ADMIN ASSETS
   ========================================================================== */

add_action( 'admin_enqueue_scripts', 'psi_enqueue_admin_assets' );
function psi_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'psi-inquiries' ) === false && strpos( $hook, 'psi-settings' ) === false ) {
        return;
    }
    wp_enqueue_style( 'psi-admin-css', PSI_PLUGIN_URL . 'assets/css/admin.css', array(), PSI_VERSION );
    wp_enqueue_script( 'psi-admin-js', PSI_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), PSI_VERSION, true );
    wp_localize_script( 'psi-admin-js', 'psi_admin_obj', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'psi_admin_nonce' ),
    ));
}

/* ==========================================================================
   6. INQUIRIES LIST PAGE
   ========================================================================== */

function psi_inquiries_list_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'psi_inquiries';

    if ( isset($_GET['action']) && isset($_GET['inquiry_id']) && isset($_GET['_wpnonce']) ) {
        $action = sanitize_text_field($_GET['action']);
        $id     = intval($_GET['inquiry_id']);
        if ( wp_verify_nonce($_GET['_wpnonce'], 'psi_update_' . $id) && in_array($action, ['read','replied','new']) ) {
            $wpdb->update( $table, array('status' => $action), array('id' => $id) );
            echo '<div class="notice notice-success is-dismissible"><p>Status updated to <strong>' . esc_html(ucfirst($action)) . '</strong>.</p></div>';
        }
    }
    if ( isset($_GET['delete_id']) && isset($_GET['_wpnonce']) ) {
        $del_id = intval($_GET['delete_id']);
        if ( wp_verify_nonce($_GET['_wpnonce'], 'psi_delete_' . $del_id) ) {
            $wpdb->delete( $table, array('id' => $del_id) );
            echo '<div class="notice notice-success is-dismissible"><p>Inquiry deleted.</p></div>';
        }
    }
    if ( isset($_POST['psi_bulk_action']) && !empty($_POST['psi_bulk_ids']) ) {
        $bulk_action = sanitize_text_field($_POST['psi_bulk_action']);
        $bulk_ids    = array_map('intval', $_POST['psi_bulk_ids']);
        if ( $bulk_action === 'delete' ) {
            foreach ( $bulk_ids as $bid ) $wpdb->delete( $table, array('id' => $bid) );
            echo '<div class="notice notice-success is-dismissible"><p>' . count($bulk_ids) . ' inquiry(s) deleted.</p></div>';
        } elseif ( in_array($bulk_action, ['new','read','replied']) ) {
            foreach ( $bulk_ids as $bid ) $wpdb->update( $table, array('status' => $bulk_action), array('id' => $bid) );
            echo '<div class="notice notice-success is-dismissible"><p>' . count($bulk_ids) . ' inquiry(s) marked as <strong>' . esc_html(ucfirst($bulk_action)) . '</strong>.</p></div>';
        }
    }

    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
    $where = $status_filter ? $wpdb->prepare(" WHERE status = %s", $status_filter) : '';
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    $inquiries = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
    $new_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'new'");
    ?>
    <div class="hbs-admin-wrap">
        <div class="hbs-admin-header">
            <h1>Suite Inquiries <?php if ($new_count > 0) : ?><span class="psi-new-badge"><?php echo $new_count; ?> new</span><?php endif; ?></h1>
        </div>

        <ul class="subsubsub">
            <li><a href="<?php echo admin_url('admin.php?page=psi-inquiries'); ?>" class="<?php echo !$status_filter ? 'current' : ''; ?>">All <span class="count">(<?php echo $total; ?>)</span></a> |</li>
            <li><a href="<?php echo admin_url('admin.php?page=psi-inquiries&status_filter=new'); ?>" class="<?php echo $status_filter==='new' ? 'current' : ''; ?>">New <span class="count">(<?php echo $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='new'"); ?>)</span></a> |</li>
            <li><a href="<?php echo admin_url('admin.php?page=psi-inquiries&status_filter=read'); ?>" class="<?php echo $status_filter==='read' ? 'current' : ''; ?>">Read <span class="count">(<?php echo $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='read'"); ?>)</span></a> |</li>
            <li><a href="<?php echo admin_url('admin.php?page=psi-inquiries&status_filter=replied'); ?>" class="<?php echo $status_filter==='replied' ? 'current' : ''; ?>">Replied <span class="count">(<?php echo $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status='replied'"); ?>)</span></a></li>
        </ul>

        <form method="post" style="margin-top:10px;">
            <div style="margin-bottom:8px;">
                <select name="psi_bulk_action" style="vertical-align:middle;">
                    <option value="">Bulk Actions</option>
                    <option value="new">Mark as New</option>
                    <option value="read">Mark as Read</option>
                    <option value="replied">Mark as Replied</option>
                    <option value="delete">Delete</option>
                </select>
                <?php submit_button('Apply', 'secondary', 'psi_bulk_apply', false); ?>
            </div>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:28px;"><input type="checkbox" id="psi-select-all" /></th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Location</th>
                        <th>Dates</th>
                        <th>Seats</th>
                        <th>Manager</th>
                        <th>Est. Total</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($inquiries)) : ?>
                    <tr><td colspan="12" style="text-align:center; padding:40px;">No inquiries found.</td></tr>
                <?php else : foreach ($inquiries as $inq) :
                    $days = (strtotime($inq->end_date) - strtotime($inq->start_date)) / 86400 + 1;
                    $months = max(1, ceil($days / 30));
                ?>
                    <tr class="<?php echo $inq->status === 'new' ? 'psi-row-new' : ''; ?>">
                        <td><input type="checkbox" name="psi_bulk_ids[]" value="<?php echo $inq->id; ?>" class="psi-bulk-check" /></td>
                        <td><strong><?php echo esc_html($inq->customer_name); ?></strong><?php if ($inq->customer_company) echo '<br><small style="color:#888;">' . esc_html($inq->customer_company) . '</small>'; ?></td>
                        <td><a href="mailto:<?php echo esc_attr($inq->customer_email); ?>"><?php echo esc_html($inq->customer_email); ?></a></td>
                        <td><?php echo esc_html($inq->customer_phone); ?></td>
                        <td><?php echo $inq->location_id ? esc_html(get_the_title($inq->location_id)) : '—'; ?></td>
                        <td style="white-space:nowrap; font-size:12px;"><?php echo esc_html($inq->start_date); ?><br>→ <?php echo esc_html($inq->end_date); ?><br><small style="color:#888;"><?php echo $days; ?>d (<?php echo $months; ?> mo)</small></td>
                        <td><?php echo esc_html($inq->seats); ?></td>
                        <td><?php echo esc_html($inq->manager_seats); ?></td>
                        <td><strong style="color:#e8521e;">₹<?php echo number_format($inq->total_price, 0); ?></strong></td>
                        <td><span class="psi-status-<?php echo $inq->status; ?>"><?php echo esc_html(ucfirst($inq->status)); ?></span></td>
                        <td style="white-space:nowrap; font-size:12px;"><?php echo date('M j, g:i A', strtotime($inq->created_at)); ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($inq->status !== 'read') : ?><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=psi-inquiries&action=read&inquiry_id='.$inq->id), 'psi_update_'.$inq->id); ?>" class="button button-small">Read</a><?php endif; ?>
                            <?php if ($inq->status !== 'replied') : ?><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=psi-inquiries&action=replied&inquiry_id='.$inq->id), 'psi_update_'.$inq->id); ?>" class="button button-small">Replied</a><?php endif; ?>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=psi-inquiries&delete_id='.$inq->id), 'psi_delete_'.$inq->id); ?>" class="button button-small psi-delete-btn" onclick="return confirm('Delete this inquiry?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
        <?php
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">Page ' . $current_page . ' of ' . $total_pages . ' &nbsp; ';
            if ($current_page > 1) echo '<a href="' . add_query_arg('paged', $current_page - 1) . '" class="button">&laquo;</a> ';
            if ($current_page < $total_pages) echo '<a href="' . add_query_arg('paged', $current_page + 1) . '" class="button">&raquo;</a>';
            echo '</div></div>';
        }
        ?>
    </div>
    <script>jQuery(function($){$('#psi-select-all').on('click',function(){$('.psi-bulk-check').prop('checked',this.checked);});});</script>
    <?php
}

/* ==========================================================================
   7. FRONTEND SHORTCODE
   ========================================================================== */

add_shortcode( 'private_suites_inquiry', 'psi_render_inquiry_form' );
function psi_render_inquiry_form( $atts ) {
    $atts = shortcode_atts( array(
        'city_id'      => '',
        'location_id'  => '',
        'show_location'=> 'true',
    ), $atts, 'private_suites_inquiry' );

    /* Force Private Suites (ID 357) */
    $atts['service_id'] = 357;

    if ( is_singular('city') ) {
        if ( wp_get_post_parent_id(get_the_ID()) ) {
            $atts['location_id'] = get_the_ID();
            $atts['city_id'] = wp_get_post_parent_id(get_the_ID());
        } else {
            $atts['city_id'] = get_the_ID();
        }
    }
    if ( isset($_GET['city']) )     $atts['city_id']     = intval($_GET['city']);

    // Handle URL location parameter (supports both IDs and slugs), deriving city from its parent
    if ( isset( $_GET['location'] ) ) {
        $loc_val = sanitize_title( $_GET['location'] );
        if ( ! is_numeric( $loc_val ) ) {
            $loc_posts = get_posts( array(
                'post_type'   => 'city',
                'name'        => $loc_val,
                'numberposts' => 1,
            ) );
            if ( ! empty( $loc_posts ) ) {
                $atts['location_id'] = $loc_posts[0]->ID;
                $atts['city_id']     = wp_get_post_parent_id( $loc_posts[0]->ID );
            }
        } else {
            $atts['location_id'] = intval( $loc_val );
            if ( $atts['location_id'] ) {
                $atts['city_id'] = wp_get_post_parent_id( $atts['location_id'] );
            }
        }
    }

    /* Locked mode: city/location came from the URL (or a city page context), so
       pre-select and disable the dropdowns instead of leaving them editable. */
    $is_locked = ! empty( $atts['city_id'] ) && ( isset($_GET['city']) || isset($_GET['location']) || is_singular('city') );

    wp_enqueue_style( 'psi-front-css', PSI_PLUGIN_URL . 'assets/css/frontend.css', array(), PSI_VERSION );
    wp_enqueue_script( 'psi-front-js', PSI_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), PSI_VERSION, true );
    wp_localize_script( 'psi-front-js', 'psi_obj', array(
        'ajax_url'     => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('psi_nonce'),
        'pre_location' => $atts['location_id'],
        'pre_city'     => $atts['city_id'],
        'is_locked'    => $is_locked,
        'min_days'     => get_option('psi_min_days', 1),
        'max_days'     => get_option('psi_max_days', 30),
    ) );

    $cities = get_posts( array('post_type'=>'city','post_parent'=>0,'numberposts'=>-1) );
    $show_location = filter_var($atts['show_location'], FILTER_VALIDATE_BOOLEAN);

    $info_location_name = ! empty( $atts['location_id'] ) ? get_the_title( $atts['location_id'] ) : '';
    $info_city_name     = ! empty( $atts['city_id'] ) ? get_the_title( $atts['city_id'] ) : '';
    $info_price          = 0;
    if ( ! empty( $atts['city_id'] ) && ! empty( $atts['location_id'] ) ) {
        $info_price = psi_get_price( $atts['city_id'], $atts['location_id'] );
    }

    ob_start();
    ?>
    <div class="psi-modal-overlay is-open" style="position:relative; background:transparent; padding:0; display:block;">
        <div class="psi-modal">
            <h2 class="psi-form-heading">Book Space!</h2>

            <?php if ( $is_locked ) : ?>
                <div class="psi-locked-info-bar" style="display:flex; flex-wrap:wrap; gap:16px; background:#f5f3ed; border:1px solid #eae7df; border-radius:8px; padding:14px 18px; margin-bottom:18px; font-size:13px;">
                    <div class="psi-locked-item"><strong>Service:</strong> Private Suites</div>
                    <div class="psi-locked-item"><strong>City:</strong> <?php echo esc_html( $info_city_name ); ?></div>
                    <div class="psi-locked-item"><strong>Location:</strong> <?php echo esc_html( $info_location_name ); ?></div>
                    <div class="psi-locked-item"><strong>Price:</strong> ₹<?php echo esc_html( number_format( $info_price, 2 ) ); ?>/seat/mo</div>
                </div>
            <?php endif; ?>

            <!-- Price Display -->
            <div class="psi-price-section">
                <!-- <div class="psi-price-rate">
                    <span class="psi-price-label">Rate:</span>
                    <span class="psi-price-value">₹<span id="psi-rate-display">—</span></span>
                    <span class="psi-price-unit">/ seat / month</span>
                </div>
                <div class="psi-price-total" id="psi-total-section" style="display:none;">
                    <span class="psi-total-label">Estimated Total:</span>
                    <span class="psi-total-value" id="psi-total-display">₹0</span>
                </div> -->
            </div>
            <div class="psi-price-breakdown" id="psi-breakdown" style="display:none;"></div>

            <form id="psi-inquiry-form" class="psi-form-grid" novalidate>

                <!-- Service: Hardcoded to Private Suites (357), disabled so user can't change -->
                <input type="hidden" name="service" id="psi-service" value="357" />
                <div class="psi-field psi-field-full">
                    <label>Service</label>
                    <div class="psi-select-wrap">
                        <select disabled>
                            <option selected>Private Suites</option>
                        </select>
                    </div>
                </div>

                <?php if ( $show_location && $cities ) : ?>
                <div class="psi-field">
                    <label>City</label>
                    <div class="psi-select-wrap">
                        <select name="city" id="psi-city" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <option value="">Select City</option>
                            <?php foreach ( $cities as $city ) : ?>
                                <option value="<?php echo esc_attr($city->ID); ?>" <?php selected($atts['city_id'], $city->ID); ?>><?php echo esc_html($city->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ( $is_locked ) : ?>
                        <input type="hidden" name="city" value="<?php echo esc_attr($atts['city_id']); ?>" />
                    <?php endif; ?>
                </div>
                <div class="psi-field">
                    <label>Location</label>
                    <div class="psi-select-wrap">
                        <select name="location" id="psi-location" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <?php if ( $is_locked && $atts['location_id'] ) : ?>
                                <option value="<?php echo esc_attr($atts['location_id']); ?>" selected><?php echo esc_html($info_location_name); ?></option>
                            <?php else : ?>
                                <option value="">Select Location</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php if ( $is_locked ) : ?>
                        <input type="hidden" name="location" value="<?php echo esc_attr($atts['location_id']); ?>" />
                    <?php endif; ?>
                </div>
                <?php else : ?>
                <input type="hidden" name="city" id="psi-city" value="<?php echo esc_attr($atts['city_id']); ?>" />
                <input type="hidden" name="location" id="psi-location" value="<?php echo esc_attr($atts['location_id']); ?>" />
                <?php endif; ?>

                <div class="psi-field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="psi-full-name" required placeholder="John Doe" />
                </div>
                <div class="psi-field">
                    <label>Company <span class="psi-optional">(Optional)</span></label>
                    <input type="text" name="company" placeholder="Acme Corp" />
                </div>
                <div class="psi-field">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" id="psi-phone" required placeholder="9876543210" />
                    <small class="psi-field-hint" id="psi-phone-hint">10-digit Indian number</small>
                    <small class="psi-field-error-msg" id="psi-phone-error" style="display:none; color:#d63638;"></small>
                </div>
                <div class="psi-field">
                    <label>Email</label>
                    <input type="email" name="email" id="psi-email" required placeholder="you@example.com" />
                    <small class="psi-field-error-msg" id="psi-email-error" style="display:none; color:#d63638;"></small>
                </div>
                <div class="psi-field">
                    <label>Start Date</label>
                    <div class="psi-date-field">
                        <input type="text" name="start_date" id="psi-start-date" required readonly placeholder="Select start date" />
                        <div class="psi-date-icon"><span class="dashicons"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line></svg></span></div>
                        <div class="psi-calendar-popover" id="psi-cal-start">
                            <div class="psi-cal-header">
                                <button type="button" class="psi-cal-nav-btn" data-cal="start" data-dir="prev">&laquo;</button>
                                <span class="psi-cal-title"></span>
                                <button type="button" class="psi-cal-nav-btn" data-cal="start" data-dir="next">&raquo;</button>
                            </div>
                            <div class="psi-cal-grid"><div class="psi-cal-dow">Su</div><div class="psi-cal-dow">Mo</div><div class="psi-cal-dow">Tu</div><div class="psi-cal-dow">We</div><div class="psi-cal-dow">Th</div><div class="psi-cal-dow">Fr</div><div class="psi-cal-dow is-weekend">Sa</div></div>
                            <div class="psi-cal-grid" id="psi-cal-start-days"></div>
                        </div>
                    </div>
                </div>
                <div class="psi-field">
                    <label>End Date</label>
                    <div class="psi-date-field">
                        <input type="text" name="end_date" id="psi-end-date" required readonly placeholder="Select end date" />
                        <div class="psi-date-icon"><span class="dashicons"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line></svg></span></div>
                        <div class="psi-calendar-popover" id="psi-cal-end">
                            <div class="psi-cal-header">
                                <button type="button" class="psi-cal-nav-btn" data-cal="end" data-dir="prev">&laquo;</button>
                                <span class="psi-cal-title"></span>
                                <button type="button" class="psi-cal-nav-btn" data-cal="end" data-dir="next">&raquo;</button>
                            </div>
                            <div class="psi-cal-grid"><div class="psi-cal-dow">Su</div><div class="psi-cal-dow">Mo</div><div class="psi-cal-dow">Tu</div><div class="psi-cal-dow">We</div><div class="psi-cal-dow">Th</div><div class="psi-cal-dow">Fr</div><div class="psi-cal-dow is-weekend">Sa</div></div>
                            <div class="psi-cal-grid" id="psi-cal-end-days"></div>
                        </div>
                    </div>
                </div>
                <div class="psi-field">
                    <label>No. of Seats</label>
                    <div class="psi-select-wrap">
                        <select name="seats" id="psi-seats" required>
                            <option value="1">1 Seat</option>
                        </select>
                    </div>
                    <small class="psi-field-hint" id="psi-seats-info"></small>
                </div>
                <div class="psi-field">
                    <label>Manager Seats</label>
                    <div class="psi-select-wrap">
                        <select name="manager_seats" id="psi-manager-seats">
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                    </div>
                </div>

                <div class="psi-form-footer psi-field-full">
                    <div class="psi-form-message" id="psi-form-message"></div>
                    <button type="submit" class="psi-submit-btn" id="psi-submit-btn">
                        <span class="psi-btn-text">INQUIRE</span>
                        <span class="psi-btn-loader" style="display:none;"><span class="psi-spinner"></span> Sending...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ==========================================================================
   8. AJAX HANDLERS
   ========================================================================== */

add_action( 'wp_ajax_psi_get_locations', 'psi_get_locations' );
add_action( 'wp_ajax_nopriv_psi_get_locations', 'psi_get_locations' );
function psi_get_locations() {
    $city_id   = intval( $_POST['city_id'] );
    $locations = get_posts( array('post_type'=>'city','post_parent'=>$city_id,'numberposts'=>-1) );
    $html = '<option value="">Select Location</option>';
    foreach ( $locations as $loc ) {
        $html .= '<option value="' . esc_attr($loc->ID) . '">' . esc_html($loc->post_title) . '</option>';
    }
    echo $html;
    wp_die();
}

add_action( 'wp_ajax_psi_get_location_details', 'psi_get_location_details' );
add_action( 'wp_ajax_nopriv_psi_get_location_details', 'psi_get_location_details' );
function psi_get_location_details() {
    $city_id     = intval( $_POST['city_id'] );
    $location_id = intval( $_POST['location_id'] );
    echo json_encode( array(
        'success'   => true,
        'price'     => psi_get_price( $city_id, $location_id ),
        'max_seats' => psi_get_max_seats( $city_id, $location_id ),
    ) );
    wp_die();
}

add_action( 'wp_ajax_psi_save_price_rule', 'psi_save_price_rule' );
function psi_save_price_rule() {
    check_ajax_referer( 'psi_admin_nonce', 'nonce' );
    $city_id     = intval( $_POST['city_id'] );
    $location_id = intval( $_POST['location_id'] );
    $price       = floatval( $_POST['price'] );
    $prices      = get_option( 'psi_location_prices', array() );
    $prices[$city_id . '_' . $location_id] = $price;
    update_option( 'psi_location_prices', $prices );
    echo psi_render_price_list_html();
    wp_die();
}

add_action( 'wp_ajax_psi_delete_rule', 'psi_delete_rule' );
function psi_delete_rule() {
    check_ajax_referer( 'psi_admin_nonce', 'nonce' );
    $type = sanitize_text_field( $_POST['type'] );
    $id   = sanitize_text_field( $_POST['id'] );
    if ( $type === 'price' ) {
        $prices = get_option( 'psi_location_prices', array() );
        unset( $prices[$id] );
        update_option( 'psi_location_prices', $prices );
        echo psi_render_price_list_html();
    } else {
        $seats = get_option( 'psi_location_seats', array() );
        unset( $seats[$id] );
        update_option( 'psi_location_seats', $seats );
        echo psi_render_seats_list_html();
    }
    wp_die();
}

add_action( 'wp_ajax_psi_save_seat_rule', 'psi_save_seat_rule' );
function psi_save_seat_rule() {
    check_ajax_referer( 'psi_admin_nonce', 'nonce' );
    $city_id     = intval( $_POST['city_id'] );
    $location_id = intval( $_POST['location_id'] );
    $seats       = intval( $_POST['seats'] );
    $all_seats   = get_option( 'psi_location_seats', array() );
    $all_seats[$city_id . '_' . $location_id] = $seats;
    update_option( 'psi_location_seats', $all_seats );
    echo psi_render_seats_list_html();
    wp_die();
}

add_action( 'wp_ajax_psi_submit_inquiry', 'psi_submit_inquiry' );
add_action( 'wp_ajax_nopriv_psi_submit_inquiry', 'psi_submit_inquiry' );
function psi_submit_inquiry() {
    check_ajax_referer( 'psi_nonce', 'nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'psi_inquiries';

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

    if ( ! is_email($email) ) {
        echo json_encode( array('success'=>false,'message'=>'Please enter a valid email address.') ); wp_die();
    }
    $phone_clean = preg_replace('/[\s\-\+\(\)]/', '', $phone_raw);
    if ( ! preg_match('/^[6-9]\d{9}$/', $phone_clean) ) {
        echo json_encode( array('success'=>false,'message'=>'Please enter a valid 10-digit Indian phone number.') ); wp_die();
    }
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ) {
        echo json_encode( array('success'=>false,'message'=>'Invalid date format.') ); wp_die();
    }
    if ( $end_date < $start_date ) {
        echo json_encode( array('success'=>false,'message'=>'End date cannot be before start date.') ); wp_die();
    }
    $min_days = intval( get_option('psi_min_days', 1) );
    $max_days = intval( get_option('psi_max_days', 30) );
    $duration = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
    if ( $duration < $min_days ) {
        echo json_encode( array('success'=>false,'message'=>'Minimum stay is '.$min_days.' day'.($min_days>1?'s':'').'.') ); wp_die();
    }
    if ( $duration > $max_days ) {
        echo json_encode( array('success'=>false,'message'=>'Maximum stay is '.$max_days.' days.') ); wp_die();
    }

    $price_per_seat  = psi_get_price( $city_id, $location_id );
    $months          = max(1, ceil($duration / 30));
    $total_price     = $price_per_seat * $seats * $months;

    if ( $wpdb->insert( $table, array(
        'service_id'       => $service_id,
        'city_id'          => $city_id,
        'location_id'      => $location_id,
        'customer_name'    => $full_name,
        'customer_company' => $company,
        'customer_email'   => $email,
        'customer_phone'   => $phone_clean,
        'start_date'       => $start_date,
        'end_date'         => $end_date,
        'seats'            => $seats,
        'manager_seats'    => $manager,
        'total_price'      => $total_price,
        'status'           => 'new',
    )) === false ) {
        echo json_encode( array('success'=>false,'message'=>'Database error. Please try again.') ); wp_die();
    }

    /* Send email — uses WordPress default admin email settings */
    $admin_email  = get_option('admin_email');
    $admin_name   = get_option('psi_admin_name', get_option('blogname'));
    $success_msg  = get_option('psi_success_message', 'Thank you for your inquiry! We will get back to you within 24 hours.');

    $location_name = $location_id ? get_the_title($location_id) : 'N/A';
    $city_name     = $city_id ? get_the_title($city_id) : 'N/A';

    $subject = 'New Suite Inquiry — ' . $full_name . ' (' . $months . ' month' . ($months>1?'s':'') . ')';

    $body  = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:600px;margin:0 auto;background:#f9f8f5;border-radius:12px;overflow:hidden;'>";
    $body .= "<div style='background:#e8521e;padding:28px 32px;color:#fff;'><h1 style='margin:0;font-size:22px;font-weight:700;'>New Suite Inquiry</h1><p style='margin:6px 0 0;opacity:.85;font-size:14px;'>" . date('F j, Y \a\t g:i A') . "</p></div>";
    $body .= "<div style='padding:28px 32px;'><table style='width:100%;border-collapse:collapse;font-size:14px;'>";

    $rows = array(
        array('Name', esc_html($full_name)),
        array('Company', esc_html($company) ?: '—'),
        array('Email', '<a href="mailto:'.esc_attr($email).'" style="color:#e8521e;">'.esc_html($email).'</a>'),
        array('Phone', esc_html($phone_clean)),
        array('Service', 'Private Suites'),
        array('City', esc_html($city_name)),
        array('Location', esc_html($location_name)),
        array('Start Date', '<strong>'.esc_html($start_date).'</strong>'),
        array('End Date', '<strong>'.esc_html($end_date).'</strong>'),
        array('Duration', $duration.' days ('.$months.' month'.($months>1?'s':'').')'),
        array('Seats', '<strong>'.esc_html($seats).'</strong>'),
        array('Manager Seats', '<strong>'.esc_html($manager).'</strong>'),
        array('Rate', '₹'.number_format($price_per_seat).' / seat / month'),
        array('Estimated Total', '<strong style="color:#e8521e;font-size:18px;">₹'.number_format($total_price).'</strong>'),
    );
    foreach ($rows as $i => $row) {
        $bg = $i % 2 === 0 ? '#fff' : '#f5f3ed';
        $body .= "<tr><td style='padding:12px 16px;background:{$bg};font-weight:600;color:#666;width:35%;border-bottom:1px solid #eae7df;'>{$row[0]}</td>";
        $body .= "<td style='padding:12px 16px;background:{$bg};color:#1f1f1f;border-bottom:1px solid #eae7df;'>{$row[1]}</td></tr>";
    }
    $body .= "</table></div>";
    $body .= "<div style='padding:16px 32px 24px;text-align:center;font-size:12px;color:#aaa;'>Sent via Private Suites Inquiry Plugin</div></div>";

    $headers = array(
        'From: '.$admin_name.' <'.$admin_email.'>',
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: '.$full_name.' <'.$email.'>',
    );
    wp_mail( $admin_email, $subject, $body, $headers );

    echo json_encode( array('success'=>true,'message'=>$success_msg) );
    wp_die();
}