<?php
/**
 * Plugin Name: Schedule Forms
 * Description: Custom multi-form system (shortcode + inline form) with admin-managed calendar availability and time slots. Built to support multiple distinct forms (Tour Booking, Inquiry, etc.), each with its own settings screen and submissions table.
 * Version:     1.0.0
 * Author:      Pushpendu
 * Text Domain: cwf
 *
 * ----------------------------------------------------------------------
 * HOW THIS PLUGIN IS ORGANIZED (read this before adding a new form)
 * ----------------------------------------------------------------------
 * - includes/class-cwf-form-module.php
 *      The reusable "engine". Registers a CPT for submissions, builds the
 *      admin Settings + Submissions screens, renders the shortcode,
 *      handles the AJAX submit, sends the admin email.
 *
 * - includes/forms/class-cwf-form-tour-booking.php
 *      One concrete form ("Lets schedule a tour for you!"). It just
 *      configures CWF_Form_Module with: a slug, a label, a list of
 *      fields, and (optionally) overrides for special fields like the
 *      calendar/time picker used here.
 *
 * - TO ADD A NEW FORM LATER:
 *      1. Duplicate includes/forms/class-cwf-form-tour-booking.php
 *      2. Change the slug/label/fields array
 *      3. Register it in cwf_register_forms() below
 *      That's it — it automatically gets its own admin menu item,
 *      settings page, submissions list, shortcode, and CPT.
 * ----------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CWF_VERSION', '1.0.0' );
define( 'CWF_PLUGIN_FILE', __FILE__ );
define( 'CWF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CWF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Composer-free, simple autoload-ish require list.
 */
require_once CWF_PLUGIN_DIR . 'includes/class-cwf-form-module.php';
require_once CWF_PLUGIN_DIR . 'includes/class-cwf-calendar.php';
require_once CWF_PLUGIN_DIR . 'includes/class-cwf-admin-menu.php';
require_once CWF_PLUGIN_DIR . 'includes/forms/class-cwf-form-tour-booking.php';

/**
 * Holds all registered CWF_Form_Module instances, keyed by slug.
 *
 * @return array<string, CWF_Form_Module>
 */
function cwf_registered_forms() {
	static $forms = null;

	if ( null === $forms ) {
		$forms = array();

		// ---- Register every form module here ----
		$tour_booking   = new CWF_Form_Tour_Booking();
		$forms[ $tour_booking->slug ] = $tour_booking;

		// Future forms, e.g.:
		// $inquiry = new CWF_Form_Inquiry();
		// $forms[ $inquiry->slug ] = $inquiry;

		/**
		 * Allow other plugins/themes to register additional CWF forms.
		 */
		$forms = apply_filters( 'cwf_registered_forms', $forms );
	}

	return $forms;
}

/**
 * Bootstrap: hook every registered form module into WP.
 */
function cwf_init_plugin() {
	foreach ( cwf_registered_forms() as $form ) {
		$form->init();
	}

	new CWF_Admin_Menu( cwf_registered_forms() );
}
add_action( 'plugins_loaded', 'cwf_init_plugin' );

/**
 * Enqueue front-end assets unconditionally on every front-end request.
 *
 * IMPORTANT: this used to only register the assets and rely on
 * render_shortcode() to enqueue them when the shortcode actually ran on the
 * page. That breaks for builders like Elementor that render Popups via a
 * separate AJAX request when the popup is opened — that request never goes
 * through the normal page bootstrap, so wp_enqueue_scripts() never fires for
 * it, and our script + the localized cwfFrontend (ajaxUrl/nonce) object
 * never get printed into the page that's already loaded in the browser.
 * Without cwfFrontend, every AJAX call in frontend.js silently fails.
 *
 * Enqueuing here unconditionally guarantees the script, style, and
 * localized data are always present on the live page, regardless of
 * whether/when a builder injects the shortcode's HTML into the DOM.
 */
function cwf_enqueue_frontend_assets() {
	wp_register_style( 'cwf-frontend', CWF_PLUGIN_URL . 'assets/css/frontend.css', array(), CWF_VERSION );
	wp_register_script( 'cwf-frontend', CWF_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), CWF_VERSION, true );

	wp_localize_script(
		'cwf-frontend',
		'cwfFrontend',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cwf_frontend_nonce' ),
		)
	);

	wp_enqueue_style( 'cwf-frontend' );
	wp_enqueue_script( 'cwf-frontend' );
}
add_action( 'wp_enqueue_scripts', 'cwf_enqueue_frontend_assets' );

/**
 * Activation: register CPTs immediately then flush rewrite rules,
 * so submission permalinks work right away.
 */
function cwf_activate_plugin() {
	foreach ( cwf_registered_forms() as $form ) {
		$form->register_post_type();
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cwf_activate_plugin' );

function cwf_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cwf_deactivate_plugin' );