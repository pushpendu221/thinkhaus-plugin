<?php
/**
 * Plugin Name:  Service Filter Shortcode
 * Description:  Renders the City → Location cascading filter with service cards carousel.
 *               Reads service availability from '_csm_service_records' set by the
 *               Service Details Meta Box plugin (v3.5.0+).
 *               Usage: [service_filter]
 * Version:      1.1.0
 * Author:       Pushpendu
 * Text Domain:  sfs
 *
 * REQUIRES:
 *   - Service Details Meta Box plugin active (CSM plugin)
 *   - 'city' CPT registered and hierarchical
 *   - 'service' CPT registered
 *   - Owl Carousel 2 JS + CSS already enqueued by the theme (or Elementor widget)
 *   - Font Awesome already loaded
 *
 * URL-BASED PRESETTING (v1.1.0):
 *   The shortcode reads the current page URL to preset dropdowns automatically.
 *   URL format: /city/{city-slug}/              → preset city only
 *   URL format: /city/{city-slug}/{loc-slug}/   → preset city + location + load services
 *   URL format: /city/                          → no preset, both dropdowns at placeholder
 *
 *   Resolution uses get_page_by_path() against the 'city' CPT, so slugs must
 *   match the post_name (URL slug) of the city/location posts exactly.
 *   URL presetting takes priority over the [default_city] shortcode attribute.
 *
 * HOW IT WORKS:
 *   1. Shortcode renders HTML with City dropdown pre-populated (parent city posts).
 *   2. PHP parses the current URL and resolves city/location slugs to post IDs.
 *   3. Dropdowns are pre-selected server-side; data attributes carry IDs to JS.
 *   4. JS calls the REST endpoint for the preset location (if any) on DOMContentLoaded.
 *   5. Subsequent dropdown changes fetch fresh data via REST and re-render the carousel.
 *
 * PRICE SOURCE:
 *   Price is stored per-service per-location inside '_csm_service_records'
 *   (the JSON blob written by the CSM plugin). The REST endpoint reads that blob,
 *   iterates every service_id key, resolves the service post for title/image/
 *   description, and merges in the location-specific 'price' field value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SFS_VERSION',    '1.1.0' );
define( 'SFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* ==========================================================================
   1. ENQUEUE FRONT-END ASSETS
   ========================================================================== */

add_action( 'wp_enqueue_scripts', 'sfs_enqueue_assets' );
function sfs_enqueue_assets(): void {
	wp_enqueue_style(
		'sfs-filter',
		SFS_PLUGIN_URL . 'assets/css/service-filter.css',
		[],
		SFS_VERSION
	);

	wp_enqueue_script(
		'sfs-filter',
		SFS_PLUGIN_URL . 'assets/js/service-filter.js',
		[],
		SFS_VERSION,
		true
	);

	static $localised = false;
	if ( ! $localised ) {
		wp_localize_script(
			'sfs-filter',
			'sfsConfig',
			[
				'restServicesBase' => rest_url( 'sfs/v1/location-services/' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				// Full parent → children map so JS can repopulate the Location
				// dropdown on City change without an extra REST call.
				// Shape: { city_id: [ { id, title }, … ], … }
				'locationMap'      => sfs_build_location_map(),
			]
		);
		$localised = true;
	}
}

/* ==========================================================================
   2. LOCATION MAP HELPER
   ========================================================================== */

function sfs_build_location_map(): array {
	$children = get_posts( [
		'post_type'           => 'city',
		'posts_per_page'      => -1,
		'post_status'         => 'publish',
		'post_parent__not_in' => [ 0 ],
		'orderby'             => 'title',
		'order'               => 'ASC',
	] );

	$map = [];
	foreach ( $children as $child ) {
		if ( empty( $child->post_parent ) ) {
			continue;
		}
		$map[ $child->post_parent ][] = [
			'id'    => $child->ID,
			'title' => $child->post_title,
		];
	}
	return $map;
}

/* ==========================================================================
   3. URL SLUG PARSER
      Extracts city and location slugs from the current request URI.

      Supported patterns (the '/city/' segment is the CPT rewrite base):
        /city/                         → city_slug = '',   loc_slug = ''
        /city/delhi/                   → city_slug = 'delhi', loc_slug = ''
        /city/delhi/aerocity/          → city_slug = 'delhi', loc_slug = 'aerocity'
        /projects/thinkhaus/city/…     → sub-directory installs handled by
                                         stripping everything before '/city/'

      Returns: [ 'city_slug' => string, 'location_slug' => string ]
   ========================================================================== */

function sfs_parse_url_slugs(): array {
	$result = [ 'city_slug' => '', 'location_slug' => '' ];

	// $_SERVER['REQUEST_URI'] gives the raw path+query, e.g.
	// /projects/thinkhaus/city/delhi/aerocity/
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( $_SERVER['REQUEST_URI'] ) : '';
	$path        = strtok( $request_uri, '?' ); // strip query string
	$path        = trailingslashit( $path );     // normalise trailing slash

	// Find the '/city/' segment — works for root and sub-directory installs.
	// get_option('permalink_structure') and the CPT rewrite slug could differ;
	// using a literal '/city/' is safe because your CPT slug is 'city'.
	$needle = '/city/';
	$pos    = strpos( $path, $needle );

	if ( $pos === false ) {
		return $result; // Not on a /city/… URL at all.
	}

	// Everything after '/city/'.
	$after = substr( $path, $pos + strlen( $needle ) );
	// Remove trailing slash and split into parts.
	$parts = array_values( array_filter( explode( '/', trim( $after, '/' ) ) ) );

	// parts[0] = city slug, parts[1] = location slug (both optional).
	$result['city_slug']     = isset( $parts[0] ) ? sanitize_title( $parts[0] ) : '';
	$result['location_slug'] = isset( $parts[1] ) ? sanitize_title( $parts[1] ) : '';

	return $result;
}

/* ==========================================================================
   4. RESOLVE SLUGS TO POST IDs
      get_page_by_path() works for hierarchical CPTs when you pass the
      post_type argument. For child posts the full path must be
      'parent-slug/child-slug', hence we compose it when both are known.
   ========================================================================== */

function sfs_resolve_url_ids(): array {
	$result = [ 'city_id' => 0, 'location_id' => 0 ];
	$slugs  = sfs_parse_url_slugs();

	if ( empty( $slugs['city_slug'] ) ) {
		return $result; // /city/ with nothing after — both dropdowns at placeholder.
	}

	// Resolve parent city post.
	$city_post = get_page_by_path( $slugs['city_slug'], OBJECT, 'city' );
	if ( ! $city_post || 'publish' !== $city_post->post_status ) {
		return $result; // Slug doesn't match a published city.
	}
	$result['city_id'] = $city_post->ID;

	if ( empty( $slugs['location_slug'] ) ) {
		return $result; // /city/delhi/ — city preset, location at placeholder.
	}

	// Resolve child location post.
	// get_page_by_path() for a child post expects 'parent-slug/child-slug'.
	$full_path    = $slugs['city_slug'] . '/' . $slugs['location_slug'];
	$location_post = get_page_by_path( $full_path, OBJECT, 'city' );

	if (
		$location_post &&
		'publish'      === $location_post->post_status &&
		(int) $location_post->post_parent === $city_post->ID
	) {
		$result['location_id'] = $location_post->ID;
	}

	return $result;
}

/* ==========================================================================
   5. THE [service_filter] SHORTCODE
   ========================================================================== */

add_shortcode( 'service_filter', 'sfs_render_shortcode' );
function sfs_render_shortcode( array $atts ): string {

	$atts = shortcode_atts(
		[
			// [service_filter default_city="42"] — fallback when no URL slug found.
			// URL detection always takes priority over this attribute.
			'default_city'  => '',
			// [service_filter carousel_opts='{"loop":false}']
			'carousel_opts' => '',
		],
		$atts,
		'service_filter'
	);

	// ── Resolve URL-based preset (priority) ──────────────────────────────
	$url_ids         = sfs_resolve_url_ids();
	$url_city_id     = $url_ids['city_id'];
	$url_location_id = $url_ids['location_id'];

	// ── Fetch all parent cities for the City dropdown ─────────────────────
	$parent_cities = get_posts( [
		'post_type'      => 'city',
		'post_parent'    => 0,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	if ( empty( $parent_cities ) ) {
		return '<p class="sfs-notice">No cities found.</p>';
	}

	// ── Determine the active city ID ──────────────────────────────────────
	// Priority: URL slug > shortcode attribute > none (placeholder shown).
	if ( $url_city_id ) {
		$active_city_id = $url_city_id;
	} elseif ( absint( $atts['default_city'] ) ) {
		$active_city_id = absint( $atts['default_city'] );
	} else {
		$active_city_id = 0; // No preset — City dropdown shows placeholder.
	}

	// ── Fetch children for the active city (Location dropdown) ────────────
	// Only populated when we have an active city. When $active_city_id = 0,
	// Location dropdown renders disabled with a "Select City first" placeholder.
	$city_children = [];
	if ( $active_city_id ) {
		$city_children = get_posts( [
			'post_type'      => 'city',
			'post_parent'    => $active_city_id,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
	}

	// ── Determine the active location ID ─────────────────────────────────
	// URL location takes priority. If only city is in the URL (no location
	// slug), active_location_id stays 0 → Location dropdown at placeholder.
	$active_location_id = $url_location_id; // 0 when not in URL.

	// ── Unique wrapper ID (supports multiple shortcode instances) ─────────
	static $instance = 0;
	$instance++;
	$uid = 'sfs-' . $instance;

	// ── Carousel options ──────────────────────────────────────────────────
	$default_carousel = [
		'loop'       => false,
		'margin'     => 24,
		'nav'        => true,
		'dots'       => true,
		'autoplay'   => false,
		'responsive' => [
			0    => [ 'items' => 1 ],
			600  => [ 'items' => 2 ],
			1024 => [ 'items' => 4 ],
		],
	];
	$carousel_opts = ! empty( $atts['carousel_opts'] )
		? wp_parse_args( json_decode( $atts['carousel_opts'], true ) ?? [], $default_carousel )
		: $default_carousel;

	ob_start();
	?>
	<section
		class="sfs-section"
		id="<?php echo esc_attr( $uid ); ?>"
		data-carousel-opts="<?php echo esc_attr( wp_json_encode( $carousel_opts ) ); ?>"
		data-active-city="<?php echo esc_attr( $active_city_id ); ?>"
		data-active-location="<?php echo esc_attr( $active_location_id ); ?>">

		<div class="spaces-container">

			<!-- ── FILTERS ───────────────────────────────────────────────── -->
			<div class="filters">

				<!-- City dropdown -->
				<div class="filter">
					<label for="<?php echo esc_attr( $uid ); ?>-city">City</label>
					<select
						id="<?php echo esc_attr( $uid ); ?>-city"
						class="sfs-city-select"
						data-wrapper="<?php echo esc_attr( $uid ); ?>">

						<?php if ( ! $active_city_id ) : ?>
							<!-- No URL preset — show a placeholder first option -->
							<option value="">— Select City —</option>
						<?php endif; ?>

						<?php foreach ( $parent_cities as $city ) : ?>
							<option
								value="<?php echo esc_attr( $city->ID ); ?>"
								<?php selected( $active_city_id, $city->ID ); ?>>
								<?php echo esc_html( strtoupper( $city->post_title ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Location dropdown -->
				<div class="filter">
					<label for="<?php echo esc_attr( $uid ); ?>-location">Locations</label>
					<select
						id="<?php echo esc_attr( $uid ); ?>-location"
						class="sfs-location-select"
						data-wrapper="<?php echo esc_attr( $uid ); ?>"
						<?php echo empty( $city_children ) ? 'disabled' : ''; ?>>

						<?php if ( empty( $city_children ) ) : ?>
							<!-- No city selected or city has no children -->
							<option value="">— Select Location —</option>

						<?php else : ?>
							<?php if ( ! $active_location_id ) : ?>
								<!-- City preset but no location in URL — placeholder first -->
								<option value="">— Select Location —</option>
							<?php endif; ?>

							<?php foreach ( $city_children as $child ) : ?>
								<option
									value="<?php echo esc_attr( $child->ID ); ?>"
									<?php selected( $active_location_id, $child->ID ); ?>>
									<?php echo esc_html( $child->post_title ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>

			</div><!-- .filters -->

			<!-- ── LOADING STATE ─────────────────────────────────────────── -->
			<div class="sfs-loading" id="<?php echo esc_attr( $uid ); ?>-loading" style="display:none;">
				<span class="sfs-spinner"></span> Loading services…
			</div>

			<!-- ── NO RESULTS STATE ──────────────────────────────────────── -->
			<div class="sfs-no-results" id="<?php echo esc_attr( $uid ); ?>-empty" style="display:none;">
				No services available at this location.
			</div>

			<!-- ── CARDS CAROUSEL ────────────────────────────────────────── -->
			<!-- Cards are injected entirely by service-filter.js via REST.
			     PHP leaves this empty intentionally — avoids double-render. -->
			<div class="owl-carousel owl-theme workspace-slider"
				id="<?php echo esc_attr( $uid ); ?>-carousel">
			</div>

		</div><!-- .spaces-container -->
	</section>
	<?php
	return ob_get_clean();
}

/* ==========================================================================
   6. SERVER-SIDE CARD RENDERER (used only by the REST endpoint)
   ========================================================================== */

function sfs_render_service_cards( int $location_id ): void {
	$services = sfs_get_services_for_location( $location_id );
	foreach ( $services as $svc ) {
		$title     = esc_html( $svc['title'] );
		$desc      = esc_html( $svc['description'] );
		$price     = esc_html( $svc['price'] );
		$image     = esc_url( $svc['image'] );
		$permalink = esc_url( $svc['permalink'] );
		?>
		<div class="item">
			<div class="workspace-card">
				<a class="card-arrow" href="<?php echo $permalink; ?>">
					<i class="fa-solid fa-arrow-right"></i>
				</a>
				<div class="card-content">
					<h3><?php echo $title; ?></h3>
					<p><?php echo $desc; ?></p>
					<?php if ( $price ) : ?>
						<div class="price"><?php echo $price; ?></div>
					<?php endif; ?>
				</div>
				<div class="card-image">
					<?php if ( $image ) : ?>
						<img src="<?php echo $image; ?>" alt="<?php echo $title; ?>" loading="lazy">
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

/* ==========================================================================
   7. DATA HELPER — resolves service cards for a given location post ID
   ========================================================================== */

function sfs_get_services_for_location( int $location_id ): array {
	$raw     = get_post_meta( $location_id, '_csm_service_records', true );
	$records = [];

	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$records = $decoded;
		}
	} elseif ( is_array( $raw ) ) {
		$records = $raw;
	} elseif ( is_object( $raw ) ) {
		$records = json_decode( wp_json_encode( $raw ), true );
	}

	if ( empty( $records ) ) {
		return [];
	}

	$scf_active = function_exists( 'get_field' );
	$cards      = [];
	$seen_ids   = []; // keyed by int service post ID — strict dedup

	foreach ( $records as $service_id => $fields ) {
		// Cast to int — JSON keys are always strings after json_decode(),
		// so "1", "01", 1 all normalise to the same integer here.
		$service_id = (int) $service_id;
		if ( $service_id <= 0 ) {
			continue;
		}

		// Hard dedup: skip if we already have a card for this service.
		// Catches duplicate keys in the blob, same service saved under
		// multiple sessions, or any other storage quirk.
		if ( isset( $seen_ids[ $service_id ] ) ) {
			continue;
		}
		$seen_ids[ $service_id ] = true;

		$post = get_post( $service_id );
		if ( ! $post || 'service' !== $post->post_type || 'publish' !== $post->post_status ) {
			continue;
		}

		// ── Image: filter_image SCF field → featured image → empty ─────
		// 'filter_image' is an ACF/SCF image field on the service post.
		// Return Format can be "Image Array" (returns array with 'url' key)
		// or "Image URL" (returns a plain string). Both are handled.
		$image = '';
		if ( $scf_active ) {
			$scf_img = get_field( 'filter_image', $service_id );
			if ( ! empty( $scf_img ) ) {
				if ( is_array( $scf_img ) && ! empty( $scf_img['url'] ) ) {
					$image = $scf_img['url'];
				} elseif ( is_string( $scf_img ) && filter_var( $scf_img, FILTER_VALIDATE_URL ) ) {
					$image = $scf_img;
				}
			}
		}
		// Fall back to featured image when filter_image is empty / not set.
		if ( empty( $image ) && has_post_thumbnail( $service_id ) ) {
			$image = get_the_post_thumbnail_url( $service_id, 'medium_large' );
		}

		// ── Description: strictly from post excerpt ──────────────────────
		$description = trim( $post->post_excerpt );

		// ── Price: location-specific value from the CSM records blob ─────
		$price = isset( $fields['price'] ) ? trim( (string) $fields['price'] ) : '';
		 $service_slug = $service_id ? get_post_field( 'post_name', $service_id ) : '';	
		 if ( $service_slug == 'podcast-studios' ) {
                $price_display = 'Starting at ₹' . $price . '/hour';                
            } else {
                $price_display = 'Starting at ₹' . $price . '/day'; 
            }			
		$cards[] = [
			'id'          => $service_id,
			'title'       => get_the_title( $service_id ),
			'description' => $description,
			'price'       => $price_display,
			'image'       => $image,
			'permalink'   => get_permalink( $service_id ),
		];
	}

	return $cards;
}

/* ==========================================================================
   8. CUSTOM REST ENDPOINT
      GET /wp-json/sfs/v1/location-services/{location_id}
   ========================================================================== */

add_action( 'rest_api_init', 'sfs_register_rest_route' );
function sfs_register_rest_route(): void {
	register_rest_route( 'sfs/v1', '/location-services/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'sfs_rest_location_services',
		'permission_callback' => '__return_true',
		'args'                => [
			'id' => [
				'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
				'sanitize_callback' => 'absint',
			],
		],
	] );
}

function sfs_rest_location_services( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$location_id = (int) $request['id'];
	$post        = get_post( $location_id );

	if (
		! $post ||
		'city'    !== $post->post_type ||
		'publish' !== $post->post_status ||
		! (int) $post->post_parent
	) {
		return new WP_Error( 'not_found', 'Location not found.', [ 'status' => 404 ] );
	}

	$services = sfs_get_services_for_location( $location_id );

	return rest_ensure_response( [
		'location_id'   => $location_id,
		'location'      => get_the_title( $location_id ),
		// post_name is the URL slug — JS appends this to each service
		// permalink as ?location={slug} so the destination page knows
		// which location was selected without a separate lookup.
		'location_slug' => $post->post_name,
		'count'         => count( $services ),
		'services'      => $services,
	] );
}