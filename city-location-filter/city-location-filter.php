<?php
/**
 * Plugin Name:  City Location Filter Shortcode
 * Description:  Given a service (from ?servicetype=slug), shows filtered cities 
 *               as an image carousel, then filtered locations per city. Locations 
 *               are hidden until a city is clicked.
 *               Usage: [city_location_filter]
 * Version:      1.0.6
 * Author:       Pushpendu
 * Text Domain:  cfs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CFS_VERSION',    '1.0.6' );
define( 'CFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* ==========================================================================
   1. ENQUEUE
   ========================================================================== */

add_action( 'wp_enqueue_scripts', 'cfs_enqueue_assets' );
function cfs_enqueue_assets(): void {
    wp_enqueue_style( 'cfs-filter', CFS_PLUGIN_URL . 'assets/css/city-location-filter.css', [], CFS_VERSION );
    wp_enqueue_script( 'cfs-filter', CFS_PLUGIN_URL . 'assets/js/city-location-filter.js', [ 'jquery' ], CFS_VERSION, true );

    static $done = false;
    if ( ! $done ) {
        wp_localize_script( 'cfs-filter', 'cfsConfig', [
            'restBase'  => rest_url( 'cfs/v1/service-cities' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
        ] );
        $done = true;
    }
}

/* ==========================================================================
   2. GET SERVICE FROM ?servicetype=
   ========================================================================== */

function cfs_get_service_from_url(): array {
    $slug = isset( $_GET['servicetype'] ) ? sanitize_text_field( wp_unslash( $_GET['servicetype'] ) ) : '';
    $slug = trim( $slug, '/' );
    $slug = sanitize_title( $slug );

    if ( empty( $slug ) ) {
        return [ 'id' => 0, 'slug' => '', 'title' => '' ];
    }

    $post = get_page_by_path( $slug, OBJECT, 'service' );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return [ 'id' => 0, 'slug' => $slug, 'title' => '' ];
    }

    return [ 'id' => $post->ID, 'slug' => $post->post_name, 'title' => $post->post_title ];
}

/* ==========================================================================
   3. GET IMAGE
   ========================================================================== */

function cfs_get_post_image( int $post_id ): string {
    if ( has_post_thumbnail( $post_id ) ) {
        $url = get_the_post_thumbnail_url( $post_id, 'medium_large' );
        if ( ! empty( $url ) ) {
            return $url;
        }
    }

    if ( function_exists( 'get_field' ) ) {
        $field_names = [ 'filter_image', 'city_image', 'location_image', 'icon', 'city_icon', 'location_icon', 'city_logo', 'area_image' ];

        foreach ( $field_names as $field ) {
            $img = get_field( $field, $post_id );
            if ( ! empty( $img ) ) {
                if ( is_array( $img ) && ! empty( $img['url'] ) ) {
                    return $img['url'];
                } elseif ( is_string( $img ) && filter_var( $img, FILTER_VALIDATE_URL ) ) {
                    return $img;
                } elseif ( is_numeric( $img ) && (int) $img > 0 ) {
                    $src = wp_get_attachment_image_url( (int) $img, 'medium_large' );
                    if ( $src ) return $src;
                }
            }
        }
    }

    return '';
}

function cfs_get_location_image( int $location_id, int $parent_id ): string {
    $image = cfs_get_post_image( $location_id );
    if ( empty( $image ) ) {
        $image = cfs_get_post_image( $parent_id );
    }
    return $image;
}

/* ==========================================================================
   4. GET FILTERED CITIES & LOCATIONS
   ========================================================================== */

function cfs_get_filtered_data( int $service_id ): array {
    if ( $service_id <= 0 ) {
        return [ 'cities' => [] ];
    }

    $all_locations = get_posts( [
        'post_type'           => 'city',
        'posts_per_page'      => -1,
        'post_status'         => 'publish',
        'post_parent__not_in' => [ 0 ],
        'orderby'             => 'title',
        'order'               => 'ASC',
    ] );

    if ( empty( $all_locations ) ) {
        return [ 'cities' => [] ];
    }

    $matching = [];
    foreach ( $all_locations as $loc ) {
        $raw     = get_post_meta( $loc->ID, '_csm_service_records', true );
        $records = [];

        if ( is_string( $raw ) ) {
            $d = json_decode( $raw, true );
            if ( is_array( $d ) ) $records = $d;
        } elseif ( is_array( $raw ) ) {
            $records = $raw;
        } elseif ( is_object( $raw ) ) {
            $records = json_decode( wp_json_encode( $raw ), true );
        }

        if ( isset( $records[ $service_id ] ) || isset( $records[ (string) $service_id ] ) ) {
            $matching[] = $loc;
        }
    }

    if ( empty( $matching ) ) {
        return [ 'cities' => [] ];
    }

    $map = [];
    foreach ( $matching as $loc ) {
        $pid = (int) $loc->post_parent;
        if ( ! isset( $map[ $pid ] ) ) {
            $map[ $pid ] = [
                'id'        => $pid,
                'title'     => get_the_title( $pid ),
                'slug'      => get_post_field( 'post_name', $pid ),
                'image'     => cfs_get_post_image( $pid ),
                'locations' => [],
            ];
        }
        $map[ $pid ]['locations'][] = [
            'id'    => $loc->ID,
            'title' => $loc->post_title,
            'slug'  => $loc->post_name,
            'image' => cfs_get_location_image( $loc->ID, $pid ),
        ];
    }

    $cities = array_values( $map );
    usort( $cities, function( $a, $b ) {
        return strcasecmp( $a['title'], $b['title'] );
    } );

    return [ 'cities' => $cities ];
}

/* ==========================================================================
   5. BUILD REDIRECT URL
   ========================================================================== */

function cfs_build_location_url( int $service_id, string $location_slug ): string {
    return add_query_arg( 'location', $location_slug, get_permalink( $service_id ) );
}

/* ==========================================================================
   6. SHORTCODE
   ========================================================================== */

add_shortcode( 'city_location_filter', 'cfs_render_shortcode' );
function cfs_render_shortcode( array $atts ): string {

    $atts = shortcode_atts( [ 'default_service' => '' ], $atts, 'city_location_filter' );

    $service = cfs_get_service_from_url();

    if ( empty( $service['id'] ) && absint( $atts['default_service'] ) ) {
        $svc_post = get_post( absint( $atts['default_service'] ) );
        if ( $svc_post && 'service' === $svc_post->post_type && 'publish' === $svc_post->post_status ) {
            $service = [ 'id' => $svc_post->ID, 'slug' => $svc_post->post_name, 'title' => $svc_post->post_title ];
        }
    }

    $all_services = get_posts( [
        'post_type'      => 'service',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $data   = cfs_get_filtered_data( $service['id'] );
    $cities = $data['cities'];

    static $instance = 0;
    $instance++;
    $uid = 'cfs-' . $instance;

    $slug_no_post = ( ! empty( $service['slug'] ) && empty( $service['id'] ) );
    $has_data     = ! empty( $cities );

    ob_start();
    ?>
    <section class="city-container" id="<?php echo esc_attr( $uid ); ?>"
        data-service-id="<?php echo esc_attr( $service['id'] ); ?>"
        data-service-slug="<?php echo esc_attr( $service['slug'] ); ?>">

        <!-- ── SPACES DROPDOWN ─────────────────────────────────────────── -->
        <div class="cityfilter filters">
            <div class="filter">
                <label>Spaces</label>
                <select class="cfs-service-select" data-wrapper="<?php echo esc_attr( $uid ); ?>">
                    <?php if ( empty( $service['id'] ) ) : ?>
                        <option value="">— Select Space —</option>
                    <?php endif; ?>
                    <?php foreach ( $all_services as $svc ) : ?>
                        <option value="<?php echo esc_attr( $svc->ID ); ?>"
                            <?php selected( $service['id'], $svc->ID ); ?>>
                            <?php echo esc_html( $svc->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ( $slug_no_post ) : ?>
            <div class="cfs-no-results">
                No service found with slug "<strong><?php echo esc_html( $service['slug'] ); ?></strong>".
            </div>
        <?php endif; ?>

        <div class="cfs-no-results" id="<?php echo esc_attr( $uid ); ?>-empty"
            style="<?php echo ( $service['id'] && ! $has_data && ! $slug_no_post ) ? '' : 'display:none;'; ?>">
            No locations available for "<strong><?php echo esc_html( $service['title'] ); ?></strong>".
        </div>

        <!-- ── LOADING (for AJAX dropdown changes) ─────────────────────── -->
        <div class="cfs-loading" id="<?php echo esc_attr( $uid ); ?>-loading" style="display:none;">
            <span class="cfs-spinner"></span> Loading…
        </div>

        <?php if ( $has_data ) : ?>

            <!-- ── INITIAL PAGE LOAD LOADER (shown until carousel inits) ── -->
            <div class="cfs-loading cfs-init-loader" id="<?php echo esc_attr( $uid ); ?>-init-loader">
                <span class="cfs-spinner"></span> Loading…
            </div>

            <!-- ── CITIES WRAPPER: hidden until JS is ready ────────────── -->
            <div class="cfs-cities-wrap" id="<?php echo esc_attr( $uid ); ?>-cities-wrap">

                <!-- ── CITY CAROUSEL ───────────────────────────────────── -->
                <div class="owl-carousel owl-theme worklocation-slider"
                    id="<?php echo esc_attr( $uid ); ?>-carousel">

                    <?php foreach ( $cities as $city ) : ?>
                        <div class="item">
                            <div class="worklocation-card">
                                <a href="javascript:void(0)"
                                    class="city-card"
                                    data-city="<?php echo esc_attr( $city['slug'] ); ?>">
                                    <?php if ( $city['image'] ) : ?>
                                        <img src="<?php echo esc_url( $city['image'] ); ?>"
                                            alt="<?php echo esc_attr( $city['title'] ); ?>">
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>

                <!-- ── HINT (shown until a city is clicked) ────────────── -->
                <p class="cfs-city-hint" style="display:none;">
                    Select a city above to view available locations.
                </p>

                <!-- ── LOCATION GRIDS (hidden until city clicked) ─────── -->
                <div class="cityfilter-areas" id="<?php echo esc_attr( $uid ); ?>-areas">

                    <?php foreach ( $cities as $city ) : ?>
                        <div class="area-grid" id="<?php echo esc_attr( $city['slug'] ); ?>">

                            <?php foreach ( $city['locations'] as $loc ) : ?>
                                <a href="<?php echo esc_url( cfs_build_location_url( $service['id'], $loc['slug'] ) ); ?>"
                                    class="area-card" title="<?php echo esc_attr( $loc['title'] ); ?>">
                                    <?php if ( $loc['image'] ) : ?>
                                        <img src="<?php echo esc_url( $loc['image'] ); ?>"
                                            alt="<?php echo esc_attr( $loc['title'] ); ?>" loading="lazy">
                                    <?php endif; ?>
                                    <span class="area-name"><?php echo esc_html( $loc['title'] ); ?></span>
                                </a>
                            <?php endforeach; ?>

                        </div>
                    <?php endforeach; ?>

                </div>

            </div><!-- .cfs-cities-wrap -->

        <?php endif; ?>

    </section>
    <?php
    return ob_get_clean();
}
/* ==========================================================================
   7. REST ENDPOINT
   ========================================================================== */

add_action( 'rest_api_init', 'cfs_register_rest_route' );
function cfs_register_rest_route(): void {
    register_rest_route( 'cfs/v1', '/service-cities', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'cfs_rest_service_cities',
        'permission_callback' => '__return_true',
        'args'                => [
            'service_id' => [
                'required'          => true,
                'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );
}

function cfs_rest_service_cities( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $service_id = (int) $request['service_id'];
    $post       = get_post( $service_id );

    if ( ! $post || 'service' !== $post->post_type || 'publish' !== $post->post_status ) {
        return new WP_Error( 'not_found', 'Service not found.', [ 'status' => 404 ] );
    }

    $data = cfs_get_filtered_data( $service_id );

    return rest_ensure_response( [
        'service_id'    => $service_id,
        'service_slug'  => $post->post_name,
        'service_title' => $post->post_title,
        'service_url'   => get_permalink( $service_id ),
        'cities'        => $data['cities'],
    ] );
}