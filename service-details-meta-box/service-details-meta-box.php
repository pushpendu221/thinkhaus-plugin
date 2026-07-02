<?php
/**
 * Plugin Name:  Service Details Meta Box
 * Description:  Populates child City CPT posts (locations) with field data,
 *               selected from a Service CPT post via dropdown.
 *               Compatible with Secure Custom Fields (SCF) — the WordPress.org fork of ACF.
 * Version:      3.5.0
 * Author:       Pushpendu
 * Text Domain:  csm
 *
 * FILE STRUCTURE:
 *   service-details-meta-box.php   ← this file (logic only)
 *   assets/css/admin.css           ← all admin styles
 *   assets/js/admin.js             ← all admin JavaScript
 *
 * FIELD MAPPING (9 original + 3 new = 12 total):
 *   hours_of_operation, location_address, metro_info, closest_airport,
 *   nearby_hotels, nearby_restaurants, closest_mall, closest_cafe,
 *   where_to_park, google_location, price, video_url
 *
 * WHERE THE META BOX APPEARS (v3.2.0 change):
 *   - ONLY on 'city' posts that ARE A CHILD (post_parent > 0) of another
 *     city post — i.e. "location" style child posts of the City CPT.
 *   - It no longer appears on 'service-detail' posts at all.
 *   - Top-level ('parent') city posts do NOT get the box either, since
 *     they represent the city itself rather than a bookable location.
 *
 * PER-SERVICE DATA (v3.3.0 change):
 *   - Each location can now store a DIFFERENT set of the 12 fields for
 *     EACH linked Service, instead of one shared set. Switching the
 *     Service dropdown swaps in that service's saved data for THIS
 *     location (or fetches the service's own defaults the first time
 *     it's linked here) without erasing data saved under other services.
 *   - Full per-service breakdown lives in '_csm_service_records' (JSON).
 *   - The currently active service's record is also mirrored into the
 *     flat meta keys / SCF fields so existing front-end templates keep
 *     working unchanged.
 *
 * REQUIREMENTS:
 *   - WordPress 6.0+
 *   - SCF (Secure Custom Fields) plugin active
 *   - 'service' CPT registered with show_in_rest => true
 *   - 'city' CPT registered and hierarchical (so posts can have a parent)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSM_VERSION',    '3.5.0' );
define( 'CSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ==========================================================================
   1. FIELD MAP — single source of truth for all 12 fields
   ========================================================================== */

function csm_field_map(): array {
	return [
		// ── Original 9 ───────────────────────────────────────────────────
		'hours_of_operation' => [
			'label'    => 'Hours of Operation',
			'type'     => 'textarea',
			'icon'     => '🕐',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'location_address'   => [
			'label'    => 'Location Address',
			'type'     => 'textarea',
			'icon'     => '📍',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'metro_info'         => [
			'label'    => 'Metro',
			'type'     => 'textarea',
			'icon'     => '🚇',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'closest_airport'    => [
			'label'    => 'Closest Airport',
			'type'     => 'textarea',
			'icon'     => '✈️',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'nearby_hotels'      => [
			'label'    => 'Nearby Hotels',
			'type'     => 'textarea',
			'icon'     => '🏨',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'nearby_restaurants' => [
			'label'    => 'Nearby Restaurants',
			'type'     => 'textarea',
			'icon'     => '🍽️',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'closest_mall'       => [
			'label'    => 'Closest Mall',
			'type'     => 'textarea',
			'icon'     => '🛍️',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'closest_cafe'       => [
			'label'    => 'Closest Cafe',
			'type'     => 'textarea',
			'icon'     => '☕',
			'sanitize' => 'csm_sanitize_textarea',
		],
		'where_to_park'      => [
			'label'    => 'Where to Park',
			'type'     => 'textarea',
			'icon'     => '🅿️',
			'sanitize' => 'csm_sanitize_textarea',
		],
		// ── New 3 ────────────────────────────────────────────────────────
		'google_location'    => [
			'label'    => 'Google Location',
			'type'     => 'url',
			'icon'     => '🗺️',
			'sanitize' => 'esc_url_raw',
		],
		'price'              => [
			'label'    => 'Price',
			'type'     => 'text',
			'icon'     => '💰',
			'sanitize' => 'sanitize_text_field',
		],
		'video_url'          => [
			'label'    => 'Video (YouTube / Vimeo)',
			'type'     => 'url',
			'icon'     => '🎬',
			'sanitize' => 'esc_url_raw',
		],
	];
}

/* ==========================================================================
   1a. SANITIZATION FUNCTIONS — preserve special characters & unicode
   ========================================================================== */

/**
 * Sanitize textarea while preserving special characters, accents, and unicode.
 * Removes only dangerous HTML/JS, keeps everything else intact.
 */
function csm_sanitize_textarea( string $value ): string {
	// Decode any HTML entities first to prevent double-encoding
	$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	
	// Remove only script tags and dangerous protocols
	$value = wp_kses_post( $value );
	
	// Trim but preserve internal whitespace including special chars
	$value = trim( $value );
	
	return $value;
}

/* ==========================================================================
   1b. HELPERS — figuring out which post types / posts get the meta box
   ========================================================================== */

/**
 * Is the given post a "child city" post — i.e. a post of the 'city' CPT
 * that has a non-zero post_parent? These are the ONLY posts that should
 * receive the meta box. 'service-detail' no longer gets it, and top-level
 * (parent) city posts do not get it either.
 */
function csm_is_child_city_post( ?WP_Post $post ): bool {
	return $post instanceof WP_Post
		&& 'city' === $post->post_type
		&& (int) $post->post_parent > 0;
}

/**
 * Centralised "should this exact post show/save the meta box?" check.
 * Used by add_meta_boxes, the enqueue check, and the save handler so the
 * eligibility rule only lives in one place.
 */
function csm_post_is_eligible( ?WP_Post $post ): bool {
	return csm_is_child_city_post( $post );
}

/**
 * Read this location's full per-service records map.
 * Shape: [ service_id (int) => [ field_key => value, ... ], ... ]
 * Stored as a single JSON blob in '_csm_service_records' post meta, since
 * each location can now hold a different set of field values per linked
 * Service rather than just one flat set.
 */
function csm_get_service_records( int $post_id ): array {
	$raw = get_post_meta( $post_id, '_csm_service_records', true );
	if ( empty( $raw ) ) {
		return [];
	}
	$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Defensive normalizer: if a literal two-character escape sequence
 * (backslash + n/r/t) ends up as plain TEXT in a value instead of being
 * decoded into a real control character — which is exactly the
 * "Monday - Saturday, n8:30 AM..." symptom — convert it back to a real
 * line break / tab before sanitizing. This is a safety net regardless of
 * exactly which layer (form slashing, JSON round-trip, etc.) introduced
 * the stray escape.
 */
function csm_normalize_stray_escapes( string $value ): string {
	return str_replace(
		[ '\\r\\n', '\\n', '\\r', '\\t' ],
		[ "\r\n", "\n", "\r", "\t" ],
		$value
	);
}

function csm_normalize_unicode_escapes( string $value ): string {
    $value = preg_replace_callback(
        '/\\\\u([0-9a-fA-F]{4})/',
        function ( $matches ) {
            return mb_convert_encoding( pack( 'H*', $matches[1] ), 'UTF-8', 'UCS-2BE' );
        },
        $value
    );

    return str_replace(
        [ "\x{200B}", "\x{200C}", "\x{200D}" ],
        '',
        $value
    );
}
/* ==========================================================================
   2. REGISTER META FIELDS ON ALL RELEVANT CPTs FOR REST API ACCESS
   ========================================================================== */

add_action( 'init', 'csm_register_meta_fields' );
function csm_register_meta_fields(): void {
	// Only 'city' (for child posts) gets the field set now, plus 'service'
	// since that's the source data picked via the dropdown.
	$post_types = [ 'service', 'city' ];
	$auth_cb    = fn() => current_user_can( 'edit_posts' );

	foreach ( $post_types as $pt ) {
		foreach ( csm_field_map() as $key => $config ) {
			register_post_meta( $pt, $key, [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => $auth_cb,
			] );
		}
		register_post_meta( $pt, '_linked_service_id', [
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'integer',
			'auth_callback' => $auth_cb,
		] );
	}
}

/* ==========================================================================
   3. ENQUEUE ADMIN ASSETS — only on eligible edit screens
   ========================================================================== */

add_action( 'admin_enqueue_scripts', 'csm_enqueue_admin_assets' );
function csm_enqueue_admin_assets( string $hook ): void {
	// Only load on add/edit screens.
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'city' !== $screen->post_type ) {
		return;
	}

	// Only proceed if this specific post is a child city.
	// (For post-new.php under a parent, WP usually exposes the chosen
	// parent via $_GET['post_parent'].)
	global $post;
	$current_post = $post instanceof WP_Post ? $post : null;

	$has_parent_query_arg = isset( $_GET['post_parent'] ) && absint( $_GET['post_parent'] ) > 0;

	if ( ! csm_is_child_city_post( $current_post ) && ! $has_parent_query_arg ) {
		return;
	}

	// ── CSS ──────────────────────────────────────────────────────────────
	wp_enqueue_style(
		'csm-admin',
		CSM_PLUGIN_URL . 'assets/css/admin.css',
		[],
		CSM_VERSION
	);

	// ── JS ───────────────────────────────────────────────────────────────
	wp_enqueue_script(
		'csm-admin',
		CSM_PLUGIN_URL . 'assets/js/admin.js',
		[], // no jQuery dependency — pure vanilla JS
		CSM_VERSION,
		true  // load in footer
	);

	/*
	 * Pass PHP data to JS via wp_localize_script().
	 * This replaces all inline JS variables that were previously in the template.
	 */
	$post_id             = $current_post instanceof WP_Post ? $current_post->ID : 0;
	$saved_records       = $post_id ? csm_get_service_records( $post_id ) : [];
	$current_service_id  = $post_id ? (int) get_post_meta( $post_id, '_linked_service_id', true ) : 0;

	wp_localize_script(
		'csm-admin',
		'csmData',
		[
			'restBase'         => rest_url( 'wp/v2/service/' ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'fieldKeys'        => array_keys( csm_field_map() ),
			'locationMap'      => csm_build_location_map(),
			// Per-service saved field values for THIS location, so switching
			// the Service dropdown can restore previously saved data instead
			// of always re-fetching (and overwriting with) the service's
			// own defaults.
			// NOTE: cast empty arrays to an object so JSON encodes this as
			// `{}` rather than `[]` — otherwise JS would receive an Array
			// instead of an Object, and adding string-keyed service IDs to
			// an Array behaves unpredictably (e.g. JSON.stringify ignoring
			// or mis-indexing them).
			'savedRecords'     => $saved_records ? $saved_records : new stdClass(),
			'currentServiceId' => $current_service_id,
		]
	);
}

/* ==========================================================================
   4. BUILD LOCATION MAP  (city parent → child location array)
      Used by the City/Location cascading dropdowns.
   ========================================================================== */

function csm_build_location_map(): array {
	$locations = get_posts( [
		'post_type'      => 'city',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'post_parent__not_in' => [ 0 ], // children only
	] );

	$map = [];
	foreach ( $locations as $loc ) {
		if ( empty( $loc->post_parent ) ) {
			continue;
		}
		$map[ $loc->post_parent ][] = [
			'id'    => $loc->ID,
			'title' => $loc->post_title,
		];
	}
	return $map;
}

/* ==========================================================================
   5. META BOX REGISTRATION
      Registered ONLY for 'city' posts that are children (post_parent > 0)
      of another city post. Top-level cities and 'service-detail' posts do
      NOT get this box.
   ========================================================================== */

add_action( 'add_meta_boxes', 'csm_add_meta_box', 10, 2 );
function csm_add_meta_box( string $post_type, WP_Post $post ): void {

	// Only 'city' posts that are children of another city post get the box.
	if ( 'city' === $post_type && csm_is_child_city_post( $post ) ) {
		add_meta_box(
			'csm_service_selector',
			'📍 Service Location Details',
			'csm_render_meta_box',
			'city',
			'normal',
			'high'
		);
	}
}

/* ==========================================================================
   6. RENDER META BOX  (HTML only — no inline CSS or JS)
      Works unchanged for any eligible post type — it only ever reads/writes
      $post->ID, so no post-type-specific branching is needed here.
   ========================================================================== */

function csm_render_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'csm_save_meta', 'csm_nonce' );

	// Defensive default: if JS fails to load, this still carries whatever
	// was already saved so a plain Update click can't wipe other services'
	// records — admin.js overwrites this value right before submit.
	// Stored as base64 of the JSON (not raw JSON) so the value can never
	// interact with WordPress's automatic addslashes()/stripslashes()
	// escaping of form data — base64's alphabet contains no quotes or
	// backslashes, so there's nothing for that escaping to corrupt.
	$saved_records_b64 = base64_encode( wp_json_encode( csm_get_service_records( $post->ID ) ) );
	?>
	<input
		type="hidden"
		id="csm_service_records_field"
		name="csm_all_service_records"
		value="<?php echo esc_attr( $saved_records_b64 ); ?>"
	/>
	<?php

	// ── Services dropdown data ────────────────────────────────────────────
	$services = get_posts( [
		'post_type'      => 'service',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
		'fields'         => 'ids',
	] );
	$selected_service_id = (int) get_post_meta( $post->ID, '_linked_service_id', true );

	// ── City dropdown data ────────────────────────────────────────────────
	// $cities = get_posts( [
	// 	'post_type'      => 'city',
	// 	'post_parent'    => 0,  // top-level cities only
	// 	'posts_per_page' => -1,
	// 	'post_status'    => 'publish',
	// 	'orderby'        => 'title',
	// 	'order'          => 'ASC',
	// ] );
	// $selected_city_id     = (int) get_post_meta( $post->ID, '_linked_city_id', true );
	// $selected_location_id = (int) get_post_meta( $post->ID, '_linked_location_id', true );

	// ── Field values ──────────────────────────────────────────────────────
	$field_map       = csm_field_map();
	$saved           = [];
	$scf_active      = function_exists( 'get_field' );

	foreach ( $field_map as $key => $config ) {
		$saved[ $key ] = $scf_active
			? get_field( $key, $post->ID )
			: get_post_meta( $post->ID, $key, true );
	}

	$textarea_fields = array_filter( $field_map, fn( $c ) => $c['type'] === 'textarea' );
	?>

	<div id="csm-wrap">

		<!-- ── Row 1: Service Selector ─────────────────────────────────── -->
		<div class="csm-selector-row csm-service-row">
			<div class="csm-selector-group csm-full-width">
				<label for="csm_service_dropdown">🔗 Service</label>
				<select id="csm_service_dropdown" name="csm_linked_service_id">
					<option value="">— Select a Service —</option>
					<?php foreach ( $services as $sid ) : ?>
						<option value="<?php echo esc_attr( $sid ); ?>"
							<?php selected( $selected_service_id, $sid ); ?>>
							<?php echo esc_html( get_the_title( $sid ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span id="csm-loading">⏳ Fetching…</span>
	
			</div>
		</div>

		<!-- ── Row 2: City + Location Selectors ────────────────────────── -->
		<!-- <div class="csm-selector-row">

			<div class="csm-selector-group">
				<label for="csm_city_dropdown">🏙️ City</label>
				<select id="csm_city_dropdown" name="csm_city_id">
					<option value="">— Select City —</option>
					<?php foreach ( $cities as $city ) : ?>
						<option value="<?php echo esc_attr( $city->ID ); ?>"
							<?php selected( $selected_city_id, $city->ID ); ?>>
							<?php echo esc_html( $city->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="csm-selector-group">
				<label for="csm_location_dropdown">📍 Location</label>
				<select id="csm_location_dropdown" name="csm_location_id" disabled>
					<option value="">— Select Location —</option>
					<?php
					/*
					 * Pre-populate children if a city is already selected
					 * (important when re-editing a saved post).
					 */
					if ( $selected_city_id ) {
						$saved_children = get_posts( [
							'post_type'      => 'city',
							'post_parent'    => $selected_city_id,
							'posts_per_page' => -1,
							'post_status'    => 'publish',
							'orderby'        => 'title',
							'order'          => 'ASC',
						] );
						foreach ( $saved_children as $child ) : ?>
							<option value="<?php echo esc_attr( $child->ID ); ?>"
								<?php selected( $selected_location_id, $child->ID ); ?>>
								<?php echo esc_html( $child->post_title ); ?>
							</option>
						<?php endforeach;
					}
					?>
				</select>
			</div>

		</div> -->

		<!-- ── Section: Location Info Fields (3-col, textareas) ─────────── -->
		<p class="csm-section-label">Location Info Fields</p>
		<div class="csm-grid">
			<?php foreach ( $textarea_fields as $meta_key => $config ) : ?>
			<div class="csm-card">
				<p class="csm-card-title">
					<?php echo esc_html( $config['icon'] . ' ' . $config['label'] ); ?>
				</p>
				<textarea
					id="csm_field_<?php echo esc_attr( $meta_key ); ?>"
					name="csm_fields[<?php echo esc_attr( $meta_key ); ?>]"
					data-meta-key="<?php echo esc_attr( $meta_key ); ?>"
					rows="3"
				><?php echo esc_textarea( $saved[ $meta_key ] ?? '' ); ?></textarea>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- ── Section: Additional Details (3 new fields) ───────────────── -->
		<p class="csm-section-label">Additional Details</p>
		<div class="csm-grid-new">

			<!-- Google Location -->
			<div class="csm-card">
				<p class="csm-card-title">🗺️ Google Location</p>
				<input
					type="url"
					id="csm_field_google_location"
					name="csm_fields[google_location]"
					data-meta-key="google_location"
					value="<?php echo esc_attr( $saved['google_location'] ?? '' ); ?>"
					placeholder="https://maps.google.com/..."
				/>
				<div id="csm-map-preview">
					<iframe
						id="csm-map-frame"
						src=""
						allowfullscreen
						loading="lazy"
						referrerpolicy="no-referrer-when-downgrade"
					></iframe>
				</div>
			</div>

			<!-- Price -->
			<div class="csm-card">
				<p class="csm-card-title">💰 Price</p>
				<input
					type="text"
					id="csm_field_price"
					name="csm_fields[price]"
					data-meta-key="price"
					value="<?php echo esc_attr( $saved['price'] ?? '' ); ?>"
					placeholder="e.g. 700"
				/>
			</div>

			<!-- Video -->
			<div class="csm-card">
				<p class="csm-card-title">🎬 Video (YouTube / Vimeo)</p>
				<input
					type="url"
					id="csm_field_video_url"
					name="csm_fields[video_url]"
					data-meta-key="video_url"
					value="<?php echo esc_attr( $saved['video_url'] ?? '' ); ?>"
					placeholder="https://youtube.com/watch?v=..."
				/>
				<div id="csm-video-preview">
					<iframe
						id="csm-video-frame"
						src=""
						allowfullscreen
						allow="autoplay; encrypted-media"
					></iframe>
				</div>
			</div>

		</div><!-- .csm-grid-new -->

	</div><!-- #csm-wrap -->

	<?php
}

/* ==========================================================================
   7. SAVE META BOX DATA
      Each location now stores a SEPARATE set of field values per linked
      Service, in '_csm_service_records' (JSON map of service_id => fields).
      The currently-active service's record is also mirrored into the flat
      meta keys / SCF fields, so existing front-end templates that call
      get_field()/get_post_meta() on the location keep working unchanged —
      they'll just reflect whichever service is currently linked.
   ========================================================================== */

add_action( 'save_post_city', 'csm_save_meta_box', 10, 2 );
function csm_save_meta_box( int $post_id, WP_Post $post ): void {

	// Security + eligibility gate.
	if (
		! isset( $_POST['csm_nonce'] ) ||
		! wp_verify_nonce( $_POST['csm_nonce'], 'csm_save_meta' ) ||
		( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
		! current_user_can( 'edit_post', $post_id ) ||
		! csm_post_is_eligible( $post )
	) {
		return;
	}

	// ── Linked service ────────────────────────────────────────────────────
	$service_id = isset( $_POST['csm_linked_service_id'] )
		? absint( $_POST['csm_linked_service_id'] )
		: 0;
	update_post_meta( $post_id, '_linked_service_id', $service_id );

	// ── Linked city & location ────────────────────────────────────────────
	// update_post_meta(
	// 	$post_id,
	// 	'_linked_city_id',
	// 	isset( $_POST['csm_city_id'] ) ? absint( $_POST['csm_city_id'] ) : 0
	// );
	// update_post_meta(
	// 	$post_id,
	// 	'_linked_location_id',
	// 	isset( $_POST['csm_location_id'] ) ? absint( $_POST['csm_location_id'] ) : 0
	// );

	$field_map    = csm_field_map();
	$allowed_keys = array_keys( $field_map );

	/**
	 * Local helper: sanitize a flat key => value array against the field
	 * map, dropping unknown keys. Normalizes stray literal escape
	 * sequences first (see csm_normalize_stray_escapes()).
	 */
	$sanitize_fields = function ( array $fields ) use ( $field_map, $allowed_keys ): array {
		$clean = [];
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				continue; // reject unknown keys — never save arbitrary POST data
			}
			if ( is_string( $value ) ) {
				$value = csm_normalize_stray_escapes( $value );
				$value = csm_normalize_unicode_escapes( $value );
			}
			$clean[ $key ] = call_user_func( $field_map[ $key ]['sanitize'], $value );
		}
		return $clean;
	};

	/**
	 * Local helper: a record counts as "empty" for this service if every
	 * field in csm_field_map() is blank (null, '', or whitespace-only)
	 * after sanitization. Used to drop a service from the saved records
	 * entirely so it no longer shows as "available" for this location
	 * on the front end.
	 */
	$record_is_empty = function ( array $fields ) use ( $allowed_keys ): bool {
		foreach ( $allowed_keys as $key ) {
			$value = $fields[ $key ] ?? '';
			if ( is_string( $value ) ? trim( $value ) !== '' : ! empty( $value ) ) {
				return false;
			}
		}
		return true;
	};

	// ── Rebuild the full per-service records map ──────────────────────────
	// admin.js keeps this hidden field in sync with every service the user
	// touched during this edit session (even ones they switched away from
	// without an intermediate save), so we trust it as the full picture.
	// The field is base64-encoded JSON (see render function) specifically
	// so it can never be mangled by WordPress's automatic addslashes()
	// escaping of form data.
	$records = [];
	if ( isset( $_POST['csm_all_service_records'] ) && is_string( $_POST['csm_all_service_records'] ) ) {
		$b64        = wp_unslash( $_POST['csm_all_service_records'] );
		$json       = base64_decode( $b64, true );
		$decoded    = $json !== false ? json_decode( $json, true ) : null;
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $sid => $fields ) {
				$sid = absint( $sid );
				if ( ! $sid || ! is_array( $fields ) ) {
					continue;
				}
				$clean_fields = $sanitize_fields( $fields );
				if ( $record_is_empty( $clean_fields ) ) {
					// Every field is blank for this service on this
					// location — don't keep it in the saved map at all,
					// so it stops being treated as "available" here.
					unset( $records[ $sid ] );
					continue;
				}
				$records[ $sid ] = $clean_fields;
			}
		}
	}

	// ── Defensive fallback ─────────────────────────────────────────────────
	// Whatever is currently visible in csm_fields[] always wins for the
	// active service — covers JS failing to load/sync for any reason.
	// IMPORTANT: wp_unslash() first — WordPress automatically adds escaping
	// slashes to all incoming $_POST data, and skipping this step before
	// sanitizing is exactly the kind of inconsistency that produces stray
	// escape artifacts (e.g. a literal "\n" surviving as text).
	if ( $service_id && isset( $_POST['csm_fields'] ) && is_array( $_POST['csm_fields'] ) ) {
		$active_clean = $sanitize_fields( wp_unslash( $_POST['csm_fields'] ) );
		if ( $record_is_empty( $active_clean ) ) {
			// All fields blank for the currently active service too —
			// make sure it doesn't linger in $records from the block
			// above, and isn't (re)added here either.
			unset( $records[ $service_id ] );
		} else {
			$records[ $service_id ] = $active_clean;
		}
	}

	update_post_meta( $post_id, '_csm_service_records', $records );

	// ── Mirror the active service's record into flat/SCF fields ───────────
	$active_fields = $service_id && isset( $records[ $service_id ] ) ? $records[ $service_id ] : [];
	$scf_active    = function_exists( 'update_field' );

	foreach ( $field_map as $key => $config ) {
		$value = $active_fields[ $key ] ?? '';

		if ( $scf_active ) {
			/*
			 * update_field( field_name, value, post_id )
			 * Field name (not field key) works as long as SCF's field group
			 * location rules include Post Type = city (child posts).
			 * If your field group is still assigned only to
			 * 'service-detail' in SCF, add/switch the location rule to
			 * Post Type = city, otherwise update_field() may silently
			 * no-op for these posts.
			 */
			update_field( $key, $value, $post_id );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}

/* ==========================================================================
   8. CUSTOM REST ENDPOINT
      GET /wp-json/csm/v1/service-detail/{id}
      Now only serves child 'city' posts (the route path/name is kept
      as-is to avoid breaking any existing integrations that call it).
   ========================================================================== */

add_action( 'rest_api_init', 'csm_register_rest_route' );
function csm_register_rest_route(): void {
	register_rest_route( 'csm/v1', '/service-detail/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'csm_rest_service_detail_data',
		'permission_callback' => '__return_true',
		'args'                => [
			'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
		],
	] );
}

function csm_rest_service_detail_data( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$post_id = (int) $request['id'];
	$post    = get_post( $post_id );

	if ( ! $post || ! csm_post_is_eligible( $post ) ) {
		return new WP_Error(
			'not_found',
			'Child city post not found.',
			[ 'status' => 404 ]
		);
	}

	$scf_active = function_exists( 'get_field' );
	$meta       = [];

	foreach ( csm_field_map() as $key => $config ) {
		$meta[ $key ] = $scf_active
			? get_field( $key, $post_id )
			: get_post_meta( $post_id, $key, true );
	}

	return rest_ensure_response( [
		'id'                  => $post_id,
		'post_type'           => $post->post_type,
		'title'               => get_the_title( $post_id ),
		'linked_service_id'   => (int) get_post_meta( $post_id, '_linked_service_id', true ),
		'linked_city_id'      => (int) get_post_meta( $post_id, '_linked_city_id', true ),
		'linked_location_id'  => (int) get_post_meta( $post_id, '_linked_location_id', true ),
		'meta'                => $meta,
		// Full per-service breakdown, keyed by service ID, in case a
		// consumer needs data for a service other than the active one.
		'service_records'     => csm_get_service_records( $post_id ),
	] );
}