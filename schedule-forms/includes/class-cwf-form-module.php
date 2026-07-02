<?php
/**
 * CWF_Form_Module
 *
 * Reusable engine that powers ONE form. A concrete form (e.g. Tour Booking)
 * extends this and supplies: slug, label, fields, and (optionally) the
 * special calendar/time behaviour.
 *
 * Each form gets, automatically:
 *  - its own Custom Post Type for storing submissions
 *  - a shortcode that renders the form inline (no button/popup)
 *  - an AJAX handler that validates + saves the submission + emails admin
 *  - an admin "Settings" screen (under Schedule Forms menu)
 *  - an admin "Submissions" screen (uses WP's native list table via CPT)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CWF_Form_Module {

	/** @var string Unique slug, e.g. 'tour-booking'. Used in CPT name, option keys, shortcode. */
	public $slug;

	/** @var string Human label, e.g. 'Tour Booking Form'. Shown in admin menu. */
	public $label;

	/** @var string Heading text shown at the top of the form, e.g. "Lets schedule a tour for you!" */
	public $heading = '';

	/** @var string Text on the button that opens the popup. */
	public $button_text = 'Book Now';

	/**
	 * @var array Field definitions. Each: 
	 *   [ 'key' => 'full_name', 'label' => 'Full Name', 'type' => 'text|tel|select|date|time|...',
	 *     'required' => true, 'options' => [...] (for select) ]
	 */
	public $fields = array();

	/** @var bool Whether this form includes the special calendar date + generated time-slot pair. */
	public $has_calendar = false;

	/** @var CWF_Calendar|null */
	public $calendar;

	public function __construct() {
		$this->calendar = new CWF_Calendar( $this->slug );
	}

	/* ------------------------------------------------------------------ */
	/* CPT (storage)                                                       */
	/* ------------------------------------------------------------------ */

	public function post_type() {
		// CPT names capped at 20 chars by WP; keep slug short or this truncates safely.
		return 'cwf_' . substr( $this->slug, 0, 15 );
	}

	public function register_post_type() {
		register_post_type(
			$this->post_type(),
			array(
				'label'           => $this->label . ' Submissions',
				'public'          => false,
				'show_ui'         => false, // We build our own custom list screen instead.
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings (per-form options: recipient email, time range, etc.)      */
	/* ------------------------------------------------------------------ */

	public function settings_option_key() {
		return 'cwf_settings_' . $this->slug;
	}

	public function get_settings() {
		$defaults = array(
			'notify_email' => get_option( 'admin_email' ),
			'time_start'   => 10, // 24h hour
			'time_end'     => 13,
		);
		$saved = get_option( $this->settings_option_key(), array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public function save_settings( $settings ) {
		update_option( $this->settings_option_key(), $settings, false );
	}

	/**
	 * Build the list of bookable time slots from the configured start/end hour.
	 * e.g. start=10,end=13 => ["10:00-11:00" => "10AM - 11AM", ...]
	 *
	 * @return array<string,string> value => label
	 */
	public function get_time_slots() {
		$settings = $this->get_settings();
		$start    = (int) $settings['time_start'];
		$end      = (int) $settings['time_end'];

		$slots = array();
		if ( $end <= $start ) {
			return $slots;
		}

		for ( $h = $start; $h < $end; $h++ ) {
			$from_value = sprintf( '%02d:00', $h );
			$to_value   = sprintf( '%02d:00', $h + 1 );
			$value      = $from_value . '-' . $to_value;
			$label      = $this->format_hour_label( $h ) . ' - ' . $this->format_hour_label( $h + 1 );
			$slots[ $value ] = $label;
		}
		return $slots;
	}

	protected function format_hour_label( $hour24 ) {
		$hour24 = (int) $hour24 % 24;
		$suffix = $hour24 >= 12 ? 'PM' : 'AM';
		$hour12 = $hour24 % 12;
		if ( 0 === $hour12 ) {
			$hour12 = 12;
		}
		return $hour12 . $suffix;
	}

	/* ------------------------------------------------------------------ */
	/* Hookup                                                              */
	/* ------------------------------------------------------------------ */

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_shortcode( $this->shortcode_tag(), array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_cwf_submit_' . $this->action_slug(), array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_cwf_submit_' . $this->action_slug(), array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_cwf_calendar_' . $this->action_slug(), array( $this, 'handle_calendar_fetch' ) );
		add_action( 'wp_ajax_nopriv_cwf_calendar_' . $this->action_slug(), array( $this, 'handle_calendar_fetch' ) );
		add_action( 'wp_ajax_cwf_admin_calendar_fetch_' . $this->action_slug(), array( $this, 'handle_admin_calendar_fetch' ) );
		add_action( 'wp_ajax_cwf_admin_calendar_set_' . $this->action_slug(), array( $this, 'handle_admin_calendar_set' ) );
		add_action( 'admin_post_cwf_export_' . $this->action_slug(), array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_cwf_delete_' . $this->action_slug(), array( $this, 'handle_delete_submissions' ) );
	}

	public function shortcode_tag() {
		return 'cwf_form_' . str_replace( '-', '_', $this->slug );
	}

	/**
	 * Underscore-safe version of the slug for use in AJAX action names
	 * (WordPress hook names are exact-match strings, and our front-end JS
	 * always converts hyphens to underscores before building the action
	 * name, so PHP must register the exact same underscored form).
	 */
	public function action_slug() {
		return str_replace( '-', '_', $this->slug );
	}

	/* ------------------------------------------------------------------ */
	/* Front-end rendering                                                 */
	/* ------------------------------------------------------------------ */

	public function render_shortcode( $atts ) {
		// Note: button_text atts kept for backwards-compat but no longer used
		// (form is rendered inline — no trigger button or modal wrapper).
		$atts = shortcode_atts( array(), $atts, $this->shortcode_tag() );

		wp_enqueue_style( 'cwf-frontend' );
		wp_enqueue_script( 'cwf-frontend' );

		ob_start();
		?>
		<div class="cwf-inline-form-wrap">
			<form class="cwf-form" data-cwf-slug="<?php echo esc_attr( $this->slug ); ?>" novalidate>
				<?php if ( $this->heading ) : ?>
					<h2 class="cwf-form-heading"><?php echo esc_html( $this->heading ); ?></h2>
				<?php endif; ?>

				<div class="cwf-form-grid">
					<?php foreach ( $this->fields as $field ) : ?>
						<?php $this->render_field( $field ); ?>
					<?php endforeach; ?>
				</div>

				<?php wp_nonce_field( 'cwf_frontend_nonce', 'cwf_nonce' ); ?>
				<input type="hidden" name="cwf_form_slug" value="<?php echo esc_attr( $this->slug ); ?>" />

				<div class="cwf-form-footer">
					<div class="cwf-form-message" aria-live="polite"></div>
					<button type="submit" class="cwf-submit-btn jd-text-proceed" aria-label="Submit"><span>Proceed</span>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_field( $field ) {
		$key      = $field['key'];
		$label    = $field['label'];
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$id       = 'cwf-' . $this->slug . '-' . $key;

		echo '<div class="cwf-field cwf-field-' . esc_attr( $type ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

		switch ( $type ) {

			case 'select':
				echo '<div class="cwf-select-wrap">';
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" ' . ( $required ? 'required' : '' ) . '>';
				echo '<option value="">Select one...</option>';
				foreach ( (array) $field['options'] as $value => $opt_label ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
				break;

			case 'cwf_date_picker':
				// Special calendar-popover date field (see frontend.js for the calendar UI).
				echo '<div class="cwf-date-field" data-cwf-date-field>';
				echo '<input type="text" readonly id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" placeholder="Select a date" ' . ( $required ? 'required' : '' ) . ' autocomplete="off" />';
				echo '<span class="cwf-date-icon" aria-hidden="true">'
					. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>'
					. '</span>';
				echo '<div class="cwf-calendar-popover" data-cwf-calendar></div>';
				echo '</div>';
				break;

			case 'cwf_time_picker':
				echo '<div class="cwf-select-wrap">';
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" ' . ( $required ? 'required' : '' ) . '>';
				echo '<option value="">Select one...</option>';
				foreach ( $this->get_time_slots() as $value => $slot_label ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $slot_label ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
				break;
				case 'tel':
					echo '<input 
						type="tel"
						id="' . esc_attr( $id ) . '"
						name="' . esc_attr( $key ) . '"
						pattern="[0-9]{10}"
						maxlength="10"
						minlength="10"
						inputmode="numeric"
						' . ( $required ? 'required' : '' ) . '
					/>';
				break;
			case 'email':
				echo '<input type="email" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" ' . ( $required ? 'required' : '' ) . ' />';
				break;

			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" ' . ( $required ? 'required' : '' ) . '></textarea>';
				break;

		default:
		$extra = '';
		if ( 'full_name' === $key ) {
			$extra = 'pattern="^[A-Za-z]+(?:\s+[A-Za-z]+)+$"';
		}

		echo '<input 
			type="text"
			id="' . esc_attr( $id ) . '"
			name="' . esc_attr( $key ) . '"
			' . $extra . '
			' . ( $required ? 'required' : '' ) . '
		/>';
		break;
			}

		// Per-field inline validation message (mirrors the Private Suites
		// Inquiry plugin's UI: a small red message under the specific
		// field, toggled by frontend.js, instead of only one shared
		// message at the bottom of the form).
		echo '<small class="cwf-field-error-msg" id="' . esc_attr( $id ) . '-error" style="display:none;color:#d63638;font-size:12px;margin-top:4px;"></small>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: calendar fetch (month data for the popover)                   */
	/* ------------------------------------------------------------------ */

	public function handle_calendar_fetch() {
		check_ajax_referer( 'cwf_frontend_nonce', 'nonce' );

		$year  = isset( $_POST['year'] ) ? (int) $_POST['year'] : (int) current_time( 'Y' );
		$month = isset( $_POST['month'] ) ? (int) $_POST['month'] : (int) current_time( 'n' );

		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$statuses      = array();

		for ( $d = 1; $d <= $days_in_month; $d++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
			$statuses[ $date ] = array(
				'status'     => $this->calendar->get_status( $date ),
				'selectable' => $this->calendar->is_selectable( $date ),
			);
		}

		wp_send_json_success(
			array(
				'year'  => $year,
				'month' => $month,
				'days'  => $statuses,
			)
		);
	}

	/**
	 * Admin: fetch one month's status map fresh from the DB.
	 * Always re-reads on demand (no reliance on a stale page-load snapshot),
	 * so the admin calendar can never show out-of-date information.
	 */
	public function handle_admin_calendar_fetch() {
		check_ajax_referer( 'cwf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$year  = isset( $_POST['year'] ) ? (int) $_POST['year'] : (int) current_time( 'Y' );
		$month = isset( $_POST['month'] ) ? (int) $_POST['month'] : (int) current_time( 'n' );

		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$statuses      = array();

		for ( $d = 1; $d <= $days_in_month; $d++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
			$statuses[ $date ] = $this->calendar->get_status( $date );
		}

		$today = current_time( 'Y-m-d' );

		wp_send_json_success(
			array(
				'year'  => $year,
				'month' => $month,
				'days'  => $statuses,
				'today' => $today,
			)
		);
	}

	/**
	 * Admin: set ONE date's status, atomically. This is the key fix for the
	 * "all dates end up marked Limited/Full" bug class: rather than building
	 * a full month/¬year JSON blob client-side and round-tripping it through
	 * a hidden <input> on a full-page form POST (where any stale snapshot,
	 * double-encoding, or page-cache issue could corrupt the whole map),
	 * each click now sends just { date, status } and the server merges it
	 * into the stored map directly. Nothing else in storage is ever touched.
	 */
	public function handle_admin_calendar_set() {
		check_ajax_referer( 'cwf_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => 'Invalid date.' ) );
		}

		$valid_statuses = array( CWF_Calendar::STATUS_AVAILABLE, CWF_Calendar::STATUS_LIMITED, CWF_Calendar::STATUS_FULL );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid status.' ) );
		}

		$this->calendar->set_status( $date, $status );

		wp_send_json_success(
			array(
				'date'   => $date,
				'status' => $this->calendar->get_status( $date ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: submit                                                        */
	/* ------------------------------------------------------------------ */

	public function handle_submit() {
		check_ajax_referer( 'cwf_frontend_nonce', 'cwf_nonce' );

		$errors = array();
		$clean  = array();

		foreach ( $this->fields as $field ) {
			$key   = $field['key'];
			$raw   = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$value = sanitize_text_field( $raw );
			// Full Name validation
			if ( 'full_name' === $key && '' !== $value ) {

				if ( ! preg_match( '/^[A-Za-z]+(?:\s+[A-Za-z]+)+$/', trim( $value ) ) ) {
					$errors[] = 'Please enter a valid full name.';
				}
			}

			// Phone validation
			if ( 'phone_number' === $key && '' !== $value ) {

				$phone = preg_replace( '/\D/', '', $value );

				if ( strlen( $phone ) !== 10 ) {
					$errors[] = 'Please enter a valid 10-digit phone number.';
				}

				$value = $phone;
			}

			if ( ! empty( $field['required'] ) && '' === $value ) {
				$errors[] = sprintf( '%s is required.', $field['label'] );
				continue;
			}

			if ( 'cwf_date_picker' === $field['type'] && '' !== $value ) {
				if ( ! $this->calendar->is_selectable( $value ) ) {
					$errors[] = 'Selected date is no longer available. Please choose another date.';
				}
			}

			$clean[ $key ] = $value;
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ) );
		}

		// Build a readable post title.
		$title_field = isset( $this->fields[0]['key'] ) ? $this->fields[0]['key'] : null;
		$title       = $title_field && ! empty( $clean[ $title_field ] )
			? $clean[ $title_field ] . ' - ' . current_time( 'Y-m-d H:i' )
			: $this->label . ' - ' . current_time( 'Y-m-d H:i' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => $this->post_type(),
				'post_title'  => sanitize_text_field( $title ),
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Could not save your submission. Please try again.' ) );
		}

		foreach ( $clean as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		update_post_meta( $post_id, '_cwf_submitted_at', current_time( 'mysql' ) );

		$this->send_admin_notification( $post_id, $clean );

		/**
		 * Fires after a CWF submission is saved. Lets concrete form classes
		 * hook in extra logic (e.g. marking a date as more booked).
		 */
		do_action( 'cwf_after_submit_' . $this->slug, $post_id, $clean );

		wp_send_json_success( array( 'message' => 'Thank you! Your submission has been received.' ) );
	}

	protected function send_admin_notification( $post_id, $clean ) {
		$settings = $this->get_settings();
		$to       = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );

		$subject = sprintf( '[%s] New submission: %s', get_bloginfo( 'name' ), $this->label );

		$lines = array();
		foreach ( $this->fields as $field ) {
			$key = $field['key'];
			if ( isset( $clean[ $key ] ) && '' !== $clean[ $key ] ) {
				$lines[] = $field['label'] . ': ' . $clean[ $key ];
			}
		}
		$lines[] = '';
		$lines[] = 'View in admin: ' . admin_url( 'admin.php?page=cwf_' . $this->slug . '_submissions&view=' . $post_id );

		$body = implode( "\n", $lines );

		wp_mail( $to, $subject, $body );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: settings + submissions screens (rendering delegated here,    */
	/* registered centrally by CWF_Admin_Menu)                             */
	/* ------------------------------------------------------------------ */

	public function render_settings_page() {
		if ( isset( $_POST['cwf_settings_nonce'] ) && wp_verify_nonce( $_POST['cwf_settings_nonce'], 'cwf_save_settings_' . $this->slug ) ) {
			$this->save_settings(
				array(
					'notify_email' => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
					'time_start'   => (int) ( $_POST['time_start'] ?? 10 ),
					'time_end'     => (int) ( $_POST['time_end'] ?? 13 ),
				)
			);

			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap cwf-admin-wrap">
			<div class="cwf-admin-header">
				<h1><?php echo esc_html( $this->label ); ?></h1>
				<span class="cwf-admin-header-sub">Settings</span>
			</div>

			<div class="cwf-admin-columns">
				<div class="cwf-admin-main">

					<form method="post" class="cwf-card">
						<h2 class="cwf-card-title">General</h2>
						<?php wp_nonce_field( 'cwf_save_settings_' . $this->slug, 'cwf_settings_nonce' ); ?>

						<div class="cwf-form-row">
							<label for="notify_email">Notification Email</label>
							<div class="cwf-form-row-control">
								<input type="email" id="notify_email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" required />
								<p class="description">Where submission alerts for this form are sent.</p>
							</div>
						</div>

						<?php if ( $this->has_calendar ) : ?>
						<div class="cwf-form-row">
							<label>Time Slot Range</label>
							<div class="cwf-form-row-control">
								<div class="cwf-time-range">
									<select name="time_start">
										<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
											<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) $settings['time_start'], $h ); ?>><?php echo esc_html( $this->format_hour_label( $h ) ); ?></option>
										<?php endfor; ?>
									</select>
									<span class="cwf-time-range-sep">to</span>
									<select name="time_end">
										<?php for ( $h = 1; $h <= 24; $h++ ) : ?>
											<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) $settings['time_end'], $h ); ?>><?php echo esc_html( $this->format_hour_label( $h ) ); ?></option>
										<?php endfor; ?>
									</select>
								</div>
								<p class="description">Generates consecutive 1-hour slots, e.g. 10AM&nbsp;&ndash;&nbsp;11AM, 11AM&nbsp;&ndash;&nbsp;12PM, etc.</p>
							</div>
						</div>
						<?php endif; ?>

						<div class="cwf-card-footer">
							<button type="submit" class="button button-primary">Save Changes</button>
						</div>
					</form>

					<?php if ( $this->has_calendar ) : ?>
					<div class="cwf-card">
						<div class="cwf-card-title-row">
							<h2 class="cwf-card-title">Calendar Availability</h2>
							<span class="cwf-autosave-indicator" id="cwf-cal-save-indicator" aria-live="polite"></span>
						</div>
						<p class="description">Click a date to cycle its status. Changes save instantly.</p>

						<div class="cwf-legend">
							<span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-available"></span>Available</span>
							<span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-limited"></span>Limited seats</span>
							<span class="cwf-legend-item"><span class="cwf-legend-dot cwf-dot-full"></span>Full / unavailable</span>
							<span class="cwf-legend-item cwf-legend-item-muted"><span class="cwf-legend-swatch cwf-swatch-past"></span>Past date (locked)</span>
						</div>

						<div
							id="cwf-admin-calendar"
							data-slug="<?php echo esc_attr( $this->slug ); ?>"
							data-action-slug="<?php echo esc_attr( $this->action_slug() ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'cwf_admin_nonce' ) ); ?>"
						></div>
					</div>
					<?php endif; ?>

				</div>

				<div class="cwf-admin-side">
					<div class="cwf-card cwf-card-compact">
						<h2 class="cwf-card-title">Shortcode</h2>
						<p class="description">Paste this anywhere — a page, post, or Elementor Shortcode widget.</p>
						<code class="cwf-shortcode-box">[<?php echo esc_html( $this->shortcode_tag() ); ?>]</code>
					</div>
					<div class="cwf-card cwf-card-compact">
						<h2 class="cwf-card-title">Quick Links</h2>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cwf_' . $this->slug . '_submissions' ) ); ?>">
								&rarr; View Submissions
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The exact moment a submission was saved, as recorded by handle_submit()
	 * (falls back to the post's publish date for any older row saved before
	 * this meta key existed).
	 */
	protected function get_submitted_at_display( $post ) {
		$post_id = is_object( $post ) ? $post->ID : (int) $post;
		$raw     = get_post_meta( $post_id, '_cwf_submitted_at', true );

		if ( $raw ) {
			$timestamp = strtotime( $raw );
			if ( $timestamp ) {
				return date_i18n( 'M j, Y g:ia', $timestamp );
			}
		}

		return get_the_date( 'M j, Y g:ia', $post_id );
	}

	public function render_submissions_page() {
		// View single submission detail.
		if ( isset( $_GET['view'] ) ) {
			$this->render_submission_detail( (int) $_GET['view'] );
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => $this->post_type(),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$export_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'cwf_export_' . $this->action_slug() ),
				admin_url( 'admin-post.php' )
			),
			'cwf_export_' . $this->slug,
			'cwf_export_nonce'
		);

		$bulk_action_url = admin_url( 'admin-post.php' );
		$col_count       = count( $this->fields ) + 3; // checkbox + submitted + fields + row actions
		?>
		<div class="wrap cwf-admin-wrap">
			<h1><?php echo esc_html( $this->label ); ?> &mdash; Submissions</h1>

			<?php if ( isset( $_GET['cwf_deleted'] ) ) : ?>
				<?php $deleted_count = (int) $_GET['cwf_deleted']; ?>
				<?php if ( $deleted_count > 0 ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php echo esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted_count, 'cwf' ), $deleted_count ) ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-warning is-dismissible">
						<p>No submissions were deleted.</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="cwf-submissions-toolbar" style="display:flex; justify-content:flex-end; margin:12px 0;">
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">Export CSV</a>
			</div>

			<form method="post" action="<?php echo esc_url( $bulk_action_url ); ?>" id="cwf-bulk-delete-form-<?php echo esc_attr( $this->slug ); ?>">
				<input type="hidden" name="action" value="cwf_delete_<?php echo esc_attr( $this->action_slug() ); ?>" />
				<?php wp_nonce_field( 'cwf_delete_' . $this->slug, 'cwf_delete_nonce' ); ?>

				<div class="cwf-bulk-actions" style="margin-bottom:8px;">
					<button type="submit" class="button" data-cwf-bulk-delete-btn>Delete Selected</button>
				</div>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:24px;"><input type="checkbox" data-cwf-select-all aria-label="Select all submissions" /></th>
							<th>Submitted</th>
							<?php foreach ( $this->fields as $field ) : ?>
								<th><?php echo esc_html( $field['label'] ); ?></th>
							<?php endforeach; ?>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $posts ) ) : ?>
							<tr><td colspan="<?php echo esc_attr( $col_count ); ?>">No submissions yet.</td></tr>
						<?php endif; ?>
						<?php foreach ( $posts as $post ) : ?>
							<?php
							$row_delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'cwf_delete_' . $this->action_slug(),
										'post_id' => $post->ID,
									),
									admin_url( 'admin-post.php' )
								),
								'cwf_delete_' . $this->slug,
								'cwf_delete_nonce'
							);
							?>
							<tr>
								<td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post->ID ); ?>" data-cwf-row-checkbox /></td>
								<td><?php echo esc_html( $this->get_submitted_at_display( $post ) ); ?></td>
								<?php foreach ( $this->fields as $field ) : ?>
									<td><?php echo esc_html( get_post_meta( $post->ID, $field['key'], true ) ); ?></td>
								<?php endforeach; ?>
								<td>
									<a href="<?php echo esc_url( add_query_arg( 'view', $post->ID ) ); ?>">View</a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $row_delete_url ); ?>" class="cwf-delete-link" data-cwf-confirm-delete style="color:#b32d2e;">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		</div>
		<script>
		(function () {
			var form = document.getElementById( 'cwf-bulk-delete-form-<?php echo esc_js( $this->slug ); ?>' );
			if ( ! form ) {
				return;
			}
			var selectAll = form.querySelector( '[data-cwf-select-all]' );
			if ( selectAll ) {
				selectAll.addEventListener( 'change', function () {
					form.querySelectorAll( '[data-cwf-row-checkbox]' ).forEach( function ( cb ) {
						cb.checked = selectAll.checked;
					} );
				} );
			}
			var bulkBtn = form.querySelector( '[data-cwf-bulk-delete-btn]' );
			if ( bulkBtn ) {
				bulkBtn.addEventListener( 'click', function ( e ) {
					var checked = form.querySelectorAll( '[data-cwf-row-checkbox]:checked' );
					if ( ! checked.length ) {
						e.preventDefault();
						window.alert( 'Select at least one submission to delete.' );
						return;
					}
					if ( ! window.confirm( 'Delete ' + checked.length + ' selected submission(s)? This cannot be undone.' ) ) {
						e.preventDefault();
					}
				} );
			}
			document.querySelectorAll( '[data-cwf-confirm-delete]' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( e ) {
					if ( ! window.confirm( 'Delete this submission? This cannot be undone.' ) ) {
						e.preventDefault();
					}
				} );
			} );
		})();
		</script>
		<?php
	}

	protected function render_submission_detail( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $this->post_type() ) {
			echo '<div class="wrap"><p>Submission not found.</p></div>';
			return;
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'cwf_delete_' . $this->action_slug(),
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'cwf_delete_' . $this->slug,
			'cwf_delete_nonce'
		);
		?>
		<div class="wrap cwf-admin-wrap">
			<h1><?php echo esc_html( $this->label ); ?> &mdash; Submission #<?php echo esc_html( $post_id ); ?></h1>
			<p>
				<a href="<?php echo esc_url( remove_query_arg( 'view' ) ); ?>">&larr; Back to all submissions</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $delete_url ); ?>" style="color:#b32d2e;" onclick="return confirm('Delete this submission? This cannot be undone.');">Delete this submission</a>
			</p>

			<table class="form-table">
				<tr>
					<th>Submitted</th>
					<td><?php echo esc_html( $this->get_submitted_at_display( $post ) ); ?></td>
				</tr>
				<?php foreach ( $this->fields as $field ) : ?>
					<tr>
						<th><?php echo esc_html( $field['label'] ); ?></th>
						<td><?php echo esc_html( get_post_meta( $post_id, $field['key'], true ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Admin: CSV export + delete/bulk-delete (admin-post.php handlers)    */
	/* ------------------------------------------------------------------ */

	/**
	 * Streams every submission for this form as a CSV download.
	 * GET-triggered from the "Export CSV" button on the submissions page.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'cwf_export_' . $this->slug, 'cwf_export_nonce' );

		$posts = get_posts(
			array(
				'post_type'      => $this->post_type(),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$filename = 'submissions-' . $this->slug . '-' . current_time( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		$header_row = array( 'Submitted At' );
		foreach ( $this->fields as $field ) {
			$header_row[] = $field['label'];
		}
		fputcsv( $out, $header_row );

		foreach ( $posts as $post ) {
			$row = array( $this->get_submitted_at_display( $post ) );
			foreach ( $this->fields as $field ) {
				$row[] = get_post_meta( $post->ID, $field['key'], true );
			}
			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Deletes one or more submissions, then redirects back to the
	 * submissions list with a result notice. Accepts either a single
	 * `post_id` (row-level "Delete" link, GET) or an array of `post_ids`
	 * (bulk "Delete Selected" form, POST) — both funnel through here.
	 */
	public function handle_delete_submissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.' );
		}
		check_admin_referer( 'cwf_delete_' . $this->slug, 'cwf_delete_nonce' );

		$post_ids = array();
		if ( isset( $_REQUEST['post_ids'] ) ) {
			$post_ids = array_map( 'intval', (array) $_REQUEST['post_ids'] );
		} elseif ( isset( $_REQUEST['post_id'] ) ) {
			$post_ids = array( (int) $_REQUEST['post_id'] );
		}
		$post_ids = array_filter( array_unique( $post_ids ) );

		$deleted = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_type === $this->post_type() ) {
				if ( wp_delete_post( $post_id, true ) ) {
					$deleted++;
				}
			}
		}

		$redirect_url = add_query_arg(
			array(
				'page'        => 'cwf_' . $this->slug . '_submissions',
				'cwf_deleted' => $deleted,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}