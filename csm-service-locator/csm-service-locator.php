<?php
/**
 * CSM Service Locator — public-facing shortcode.
 *
 * Renders the City → Location → Service → Service-Detail cascading
 * filter described in the project brief, and exposes the small REST
 * API it needs to do that filtering with fetch() instead of a full
 * page reload.
 *
 * DATA MODEL THIS FILE ASSUMES (matches the existing admin meta-box code
 * you supplied, e.g. csm_field_map(), csm_save_meta_box(), etc.):
 *
 *   CPT 'city'            Hierarchical. Top-level posts  = City.
 *                          Child posts (post_parent != 0) = Location.
 *
 *   CPT 'service'          The general "type" of workspace shown as a
 *                          card (Private Office, Dedicated Desk, ...).
 *                          Card data comes from post_title, post_excerpt,
 *                          featured image, and the shared 'price' meta
 *                          field from csm_field_map().
 *
 *   CPT 'service-detail'   One concrete, bookable listing. Linked to the
 *                          other two CPTs via post meta:
 *                            _linked_service_id   (int → 'service' post)
 *                            _linked_city_id       (int → top-level 'city' post)
 *                            _linked_location_id  (int → child 'city' post)
 *                          plus the full csm_field_map() fields (hours,
 *                          address, price, google_location, video_url...).
 *
 * If any slug or meta key differs from your live plugin, you only need
 * to touch csm_locator_query_service_ids() and the two REST callbacks
 * below — everything else funnels through those.
 *
 * Usage: [csm_service_locator]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
   0. CONSTANTS (fall back to sane defaults if the parent plugin hasn't
      already defined them)
   ========================================================================== */

if ( ! defined( 'CSM_CITY_CPT' ) ) {
	define( 'CSM_CITY_CPT', 'city' );
}
if ( ! defined( 'CSM_SERVICE_CPT' ) ) {
	define( 'CSM_SERVICE_CPT', 'service' );
}
if ( ! defined( 'CSM_SERVICE_DETAIL_CPT' ) ) {
	define( 'CSM_SERVICE_DETAIL_CPT', 'service-detail' );
}
if ( ! defined( 'CSM_LOCATOR_PLUGIN_URL' ) ) {
	define( 'CSM_LOCATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CSM_LOCATOR_VERSION' ) ) {
	define( 'CSM_LOCATOR_VERSION', '1.0.0' );
}

/* ==========================================================================
   1. SHORTCODE
   ========================================================================== */

add_shortcode( 'csm_service_locator', 'csm_render_service_locator_shortcode' );

function csm_render_service_locator_shortcode( $atts ): string {
	$cities = get_posts( [
		'post_type'      => CSM_CITY_CPT,
		'post_parent'    => 0,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	ob_start();
	?>
	<div class="csm-locator" id="csm-locator">

		<div class="filters">

			<div class="filter">
				<label for="csm-city-select">City</label>
				<select id="csm-city-select">
					<option value=""><?php esc_html_e( '— Select City —', 'csm' ); ?></option>
					<?php foreach ( $cities as $city ) : ?>
						<option value="<?php echo esc_attr( $city->ID ); ?>">
							<?php echo esc_html( $city->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="filter">
				<label for="csm-location-select">Locations</label>
				<select id="csm-location-select" disabled>
					<option value=""><?php esc_html_e( '— Select City First —', 'csm' ); ?></option>
				</select>
			</div>

		</div>

		<div class="csm-loader" id="csm-loader" hidden>
			<span class="csm-spinner"></span>
			<span class="csm-loader-text"><?php esc_html_e( 'Loading…', 'csm' ); ?></span>
		</div>

		<p class="csm-empty-state" id="csm-services-empty">
			<?php esc_html_e( 'Select a city to see available services.', 'csm' ); ?>
		</p>

		<div class="owl-carousel owl-theme csm-slider" id="csm-services-results" hidden></div>

		<div class="csm-detail-section" id="csm-service-details-wrap" hidden>
			<button type="button" class="csm-back-to-services" id="csm-back-to-services">
				&larr; <?php esc_html_e( 'Back to services', 'csm' ); ?>
			</button>
			<h3 class="csm-detail-heading" id="csm-detail-heading"></h3>
			<div class="owl-carousel owl-theme csm-slider" id="csm-service-details-results"></div>
		</div>

	</div>
	<?php
	return trim( (string) ob_get_clean() );
}

/* ==========================================================================
   2. ASSETS — only loaded on pages that actually contain the shortcode
   ========================================================================== */

add_action( 'wp_enqueue_scripts', 'csm_maybe_enqueue_locator_assets' );
function csm_maybe_enqueue_locator_assets(): void {
	if ( is_admin() ) {
		return;
	}

	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'csm_service_locator' ) ) {
		return;
	}

	// Carousel + icon dependencies (skip these two enqueues if your theme
	// already loads OwlCarousel / Font Awesome globally).
	wp_enqueue_style( 'csm-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', [], '6.5.1' );
	wp_enqueue_style( 'owl-carousel-core', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css', [], '2.3.4' );
	wp_enqueue_style( 'owl-carousel-theme', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css', [], '2.3.4' );
	wp_enqueue_script( 'owl-carousel', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js', [ 'jquery' ], '2.3.4', true );

	wp_enqueue_style(
		'csm-locator',
		CSM_LOCATOR_PLUGIN_URL . 'assets/css/service-locator.css',
		[],
		CSM_LOCATOR_VERSION
	);

	wp_enqueue_script(
		'csm-locator',
		CSM_LOCATOR_PLUGIN_URL . 'assets/js/service-locator.js',
		[ 'jquery', 'owl-carousel' ],
		CSM_LOCATOR_VERSION,
		true
	);

	wp_localize_script( 'csm-locator', 'csmLocatorData', [
		'restUrl' => esc_url_raw( rest_url( 'csm/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'i18n'    => [
			'selectCity' => __( 'Select a city to see available services.', 'csm' ),
			'noServices' => __( 'No services found for this selection.', 'csm' ),
			'noDetails'  => __( 'No listings found for this service yet.', 'csm' ),
			'loading'    => __( 'Loading…', 'csm' ),
		],
	] );
}

/* ==========================================================================
   3. REST ROUTES
   ========================================================================== */

add_action( 'rest_api_init', 'csm_register_locator_rest_routes' );
function csm_register_locator_rest_routes(): void {

	register_rest_route( 'csm/v1', '/locations/(?P<city_id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'csm_rest_get_locations',
		'permission_callback' => '__return_true',
		'args'                => [
			'city_id' => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
		],
	] );

	register_rest_route( 'csm/v1', '/services', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'csm_rest_get_services',
		'permission_callback' => '__return_true',
		'args'                => [
			'city_id'     => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
			'location_id' => [
				'required'          => false,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
		],
	] );

	register_rest_route( 'csm/v1', '/service-details', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'csm_rest_get_service_details',
		'permission_callback' => '__return_true',
		'args'                => [
			'city_id'     => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
			'location_id' => [
				'required'          => false,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
			'service_id'  => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ),
				'sanitize_callback' => 'absint',
			],
		],
	] );
}

/* ── 3a. GET /csm/v1/locations/{city_id} ─────────────────────────────────
   Children of the selected City post. */
function csm_rest_get_locations( WP_REST_Request $request ): WP_REST_Response {
	$city_id = absint( $request['city_id'] );

	$children = get_posts( [
		'post_type'      => CSM_CITY_CPT,
		'post_parent'    => $city_id,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$data = array_map(
		fn( WP_Post $p ) => [
			'id'    => $p->ID,
			'title' => get_the_title( $p ),
		],
		$children
	);

	return rest_ensure_response( $data );
}

/* ── 3b. GET /csm/v1/services?city_id=&location_id= ──────────────────────
   Distinct 'service' posts that have at least one matching 'service-detail'
   listing for the given City (and Location, if supplied). */
function csm_rest_get_services( WP_REST_Request $request ): WP_REST_Response {
	$city_id     = absint( $request['city_id'] );
	$location_id = absint( $request->get_param( 'location_id' ) );

	$service_ids = csm_locator_query_service_ids( $city_id, $location_id );

	if ( ! $service_ids ) {
		return rest_ensure_response( [] );
	}

	$services = get_posts( [
		'post_type'      => CSM_SERVICE_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'post__in'       => $service_ids,
		'orderby'        => 'post__in',
	] );

	$data = array_map( fn( WP_Post $p ) => csm_locator_format_card( $p->ID ), $services );

	return rest_ensure_response( $data );
}

/* ── 3c. GET /csm/v1/service-details?city_id=&location_id=&service_id= ───
   The actual 'service-detail' listings matching all selected filters,
   with the full csm_field_map() data attached for each one. */
function csm_rest_get_service_details( WP_REST_Request $request ): WP_REST_Response {
	$city_id     = absint( $request['city_id'] );
	$location_id = absint( $request->get_param( 'location_id' ) );
	$service_id  = absint( $request['service_id'] );

	$meta_query = [
		'relation' => 'AND',
		[ 'key' => '_linked_city_id', 'value' => $city_id, 'compare' => '=' ],
		[ 'key' => '_linked_service_id', 'value' => $service_id, 'compare' => '=' ],
	];

	if ( $location_id > 0 ) {
		$meta_query[] = [ 'key' => '_linked_location_id', 'value' => $location_id, 'compare' => '=' ];
	}

	$details = get_posts( [
		'post_type'      => CSM_SERVICE_DETAIL_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => $meta_query,
	] );

	$scf_active = function_exists( 'get_field' );
	$field_map  = function_exists( 'csm_field_map' ) ? csm_field_map() : [];

	$data = [];
	foreach ( $details as $detail ) {
		$fields = [];
		foreach ( $field_map as $key => $config ) {
			$fields[ $key ] = [
				'label' => $config['label'],
				'icon'  => $config['icon'],
				'value' => $scf_active ? get_field( $key, $detail->ID ) : get_post_meta( $detail->ID, $key, true ),
			];
		}

		$data[] = csm_locator_format_card( $detail->ID, [ 'fields' => $fields ] );
	}

	return rest_ensure_response( $data );
}

/* ==========================================================================
   4. SHARED HELPERS
   ========================================================================== */

/**
 * Distinct _linked_service_id values from 'service-detail' posts matching
 * the given City (and optionally Location).
 *
 * @return int[]
 */
function csm_locator_query_service_ids( int $city_id, int $location_id = 0 ): array {
	$meta_query = [
		'relation' => 'AND',
		[ 'key' => '_linked_city_id', 'value' => $city_id, 'compare' => '=' ],
	];

	if ( $location_id > 0 ) {
		$meta_query[] = [ 'key' => '_linked_location_id', 'value' => $location_id, 'compare' => '=' ];
	}

	$detail_ids = get_posts( [
		'post_type'      => CSM_SERVICE_DETAIL_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => $meta_query,
	] );

	if ( ! $detail_ids ) {
		return [];
	}

	$service_ids = [];
	foreach ( $detail_ids as $detail_id ) {
		$sid = (int) get_post_meta( $detail_id, '_linked_service_id', true );
		if ( $sid > 0 ) {
			$service_ids[ $sid ] = $sid; // de-dupe by key
		}
	}

	return array_values( $service_ids );
}

/**
 * Shape a 'service' or 'service-detail' post into the card payload the
 * front-end JS expects. $extra lets callers attach extra keys (e.g. the
 * full field map for service-detail cards).
 */
function csm_locator_format_card( int $post_id, array $extra = [] ): array {
	$scf_active = function_exists( 'get_field' );
	$price      = $scf_active ? get_field( 'price', $post_id ) : get_post_meta( $post_id, 'price', true );

	$base = [
		'id'        => $post_id,
		'title'     => get_the_title( $post_id ),
		'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
		'image'     => get_the_post_thumbnail_url( $post_id, 'medium' ) ?: '',
		'price'     => $price ?: '',
		'permalink' => get_permalink( $post_id ),
	];

	return array_merge( $base, $extra );
}