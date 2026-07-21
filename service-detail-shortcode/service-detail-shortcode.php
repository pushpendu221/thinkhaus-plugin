<?php
/**
 * Plugin Name:  Service Detail Shortcode
 * Description:  Renders a fully dynamic single-service detail page via the
 *               [service_detail] shortcode. Auto-resolves both Location and Service
 *               from the URL (e.g., /board-rooms/?location=koramangala).
 * Version:      1.3.0
 * Author:       Pushpendu
 * Text Domain:  sds
 *
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ==========================================================================
   1. ENQUEUE FRONT-END STYLES
   ========================================================================== */

add_action( 'wp_enqueue_scripts', 'sds_enqueue_styles' );
function sds_enqueue_styles(): void {
    wp_enqueue_style(
        'service-detail-shortcode',
        plugin_dir_url( __FILE__ ) . 'assets/css/service-detail.css',
        [],
        '1.0.0'
    );
    wp_enqueue_script(
        'service-detail-shortcode',
        plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js',
        ['jquery'],
        '1.0.0',
        true
    );
}

/* ==========================================================================
   2. HELPER — get a field value via SCF or plain post meta
   ========================================================================== */

function sds_get_field( string $key, int $post_id ): string {
    if ( function_exists( 'get_field' ) ) {
        $val = get_field( $key, $post_id );
    } else {
        $val = get_post_meta( $post_id, $key, true );
    }
    return is_string( $val ) ? trim( $val ) : '';
}

/* ==========================================================================
   2b. HELPER — resolve the linked "Service" post ID from a city child post.
   ========================================================================== */

function sds_get_linked_service_id( int $post_id ): int {
    return (int) get_post_meta( $post_id, '_linked_service_id', true );
}

/* ==========================================================================
   3. HELPER — convert a plain Google Maps share URL to an embeddable src
   ========================================================================== */

function sds_maps_embed_url( string $url ): string {
    if ( empty( $url ) ) {
        return '';
    }

    if ( str_contains( $url, '/maps/embed' ) || str_contains( $url, 'output=embed' ) ) {
        return esc_url( $url );
    }

    $query = '';

    if ( preg_match( '/[?&]q=([^&]+)/', $url, $m ) ) {
        $query = urldecode( $m[1] );
    } elseif ( preg_match( '#/maps/place/([^/@]+)#', $url, $m ) ) {
        $query = urldecode( str_replace( '+', ' ', $m[1] ) );
    }

    if ( $query ) {
        return 'https://www.google.com/maps?q=' . urlencode( $query ) . '&output=embed';
    }

    return 'https://www.google.com/maps?q=' . urlencode( $url ) . '&output=embed';
}

/* ==========================================================================
   4. HELPER — convert a YouTube / Vimeo watch URL to an embed URL
   ========================================================================== */

function sds_video_embed_url( string $url ): string {
    if ( empty( $url ) ) {
        return '';
    }

    if ( preg_match( '#youtu(?:\.be/|be\.com/(?:watch\?v=|embed/))([A-Za-z0-9_\-]{11})#', $url, $m ) ) {
        return 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1';
    }

    if ( preg_match( '#vimeo\.com/(\d+)#', $url, $m ) ) {
        return 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1';
    }

    return esc_url( $url );
}

/* ==========================================================================
   5. HELPER — convert a newline-separated textarea value to <p> tags
   ========================================================================== */

function sds_lines_to_paragraphs( string $text ): string {
    if ( empty( $text ) ) {
        return '';
    }
    $lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
    $html  = '';
    foreach ( $lines as $line ) {
        $html .= '<p>' . esc_html( $line ) . '</p>';
    }
    return $html;
}

/* ==========================================================================
   5b. HELPER — Fix stripped unicode sequences (e.g., 'u20b9' -> '₹')
       WordPress's wp_unslash() can strip the backslash from JSON \uXXXX
       escapes during saving. This restores them on the front-end.
   ========================================================================== */

function sds_fix_unicode( string $text ): string {
    if ( empty( $text ) ) {
        return '';
    }

    // 1. Fix standard JSON unicode escapes that might have survived: \uXXXX
    $text = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function( $matches ) {
        return mb_convert_encoding( pack( 'H*', $matches[1] ), 'UTF-8', 'UCS-2BE' );
    }, $text );

    // 2. Fix stripped backslash sequences for common currency symbols
    $currency_map = [
        'u20b9' => '₹', // Indian Rupee
        'u20ac' => '€', // Euro
        'u00a3' => '£', // British Pound
        'u00a5' => '¥', // Yen/Yuan
    ];

    return str_replace( array_keys( $currency_map ), array_values( $currency_map ), $text );
}

/* ==========================================================================
   6. DEFAULT FEATURE ICON MAP
   ========================================================================== */

function sds_default_icons(): array {
    $upload_url = wp_get_upload_dir()['baseurl'] . '/2026/07';
    return [
        'hours_of_operation' => $upload_url . '/clock.svg',
        'location_address'   => $upload_url . '/location-address.svg',
        'metro_info'         => $upload_url . '/metro.svg',
        'closest_airport'    => $upload_url . '/airport.svg',
        'nearby_hotels'      => $upload_url . '/hoteltag.svg',
        'nearby_restaurants' => $upload_url . '/hotelspoons.svg',
        'closest_mall'       => $upload_url . '/closesthotel.svg',
        'closest_cafe'       => $upload_url . '/cafenear.svg',
        'where_to_park'      => $upload_url . '/parking.svg',
    ];
}

/* ==========================================================================
   7. FEATURE BLOCK LABELS
   ========================================================================== */

function sds_feature_labels(): array {
    return [
        'hours_of_operation' => 'HOURS OF OPERATION',
        'location_address'   => 'LOCATION ADDRESS',
        'metro_info'         => 'Metro',
        'closest_airport'    => 'CLOSEST AIRPORT',
        'nearby_hotels'      => 'NEARBY HOTELS',
        'nearby_restaurants' => 'NEARBY RESTAURANTS',
        'closest_mall'       => 'Closest Mall',
        'closest_cafe'       => 'CLOSEST CAFE',
        'where_to_park'      => 'WHERE TO PARK',
    ];
}

/* ==========================================================================
   8. AMENITY ITEMS
   ========================================================================== */

function sds_amenity_items(): array {
    $base = trailingslashit( wp_get_upload_dir()['baseurl'] ) . '2026/06/';
    return [
        [ 'icon' => $base . 'W.png',         'label' => 'Free High Speed WiFi' ],
        [ 'icon' => $base . 'mug.png',        'label' => 'Complimentary refreshments' ],
        [ 'icon' => $base . 'print.png',      'label' => 'Free printing and scanning' ],
        [ 'icon' => $base . 'envolap.png',    'label' => 'Virtual address' ],
        [ 'icon' => $base . 'projecter.png',  'label' => 'Presentation equipment' ],
        [ 'icon' => $base . 'informal.png',   'label' => 'Breakout spaces' ],
        [ 'icon' => $base . 'headphone.png',  'label' => 'IT Support' ],
        [ 'icon' => $base . 'secure.png',     'label' => 'Secure spaces' ],
    ];
}

/* ==========================================================================
   8b. RESOLVERS — Find Location (City) and Service IDs from URL
   ========================================================================== */

/**
 * Look up a post ID by slug and post_type.
 */
function sds_find_post_by_slug( string $slug, string $post_type ): int {
    if ( '' === $slug ) return 0;

    $query = new WP_Query( [
        'post_type'        => $post_type,
        'name'             => $slug,
        'post_status'      => 'publish',
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => false,
    ] );

    return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
}

/**
 * Resolve the Location (City child post) ID.
 */
function sds_resolve_city_post_id( array $atts ): int {
    // 1. Explicit att
    if ( ! empty( $atts['id'] ) ) return (int) $atts['id'];

    // 2. Singular city template
    if ( is_singular( 'city' ) ) {
        $queried = (int) get_queried_object_id();
        if ( $queried ) return $queried;
    }

    // 3. ?location=<slug>
    if ( isset( $_GET['location'] ) ) {
        $slug = sanitize_title( wp_unslash( $_GET['location'] ) );
        $found = sds_find_post_by_slug( $slug, 'city' );
        if ( $found ) return $found;
    }

    // 4. Pretty URL fallback
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $path = wp_parse_url( $uri, PHP_URL_PATH );
    $segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
    
    for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
        $candidate = sanitize_title( $segments[ $i ] );
        if ( '' === $candidate ) continue;
        $found = sds_find_post_by_slug( $candidate, 'city' );
        if ( $found ) return $found;
    }

    return (int) get_the_ID();
}

/**
 * Resolve the Service ID.
 * Looks for a 'service' CPT post matching the current page slug or URL path segment.
 */
function sds_resolve_service_id( array $atts ): int {
    // 1. Explicit att
    if ( ! empty( $atts['service_id'] ) ) return (int) $atts['service_id'];
    if ( ! empty( $atts['service_slug'] ) ) {
        $found = sds_find_post_by_slug( $atts['service_slug'], 'service' );
        if ( $found ) return $found;
    }

    // 2. Singular service template
    if ( is_singular( 'service' ) ) {
        return (int) get_queried_object_id();
    }

    // 3. Match current page slug to a Service CPT
    // (Handles cases where shortcode is on a standard page named 'board-rooms')
    $queried_obj = get_queried_object();
    if ( $queried_obj instanceof WP_Post ) {
        $slug = $queried_obj->post_name;
        $found = sds_find_post_by_slug( $slug, 'service' );
        if ( $found ) return $found;
    }

    // 4. Fallback: Scan URL path right-to-left for a service slug
    // (Helps if pretty URLs are used: /services/board-rooms/koramangala/)
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $path = wp_parse_url( $uri, PHP_URL_PATH );
    $segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
    
    for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
        $candidate = sanitize_title( $segments[ $i ] );
        if ( '' === $candidate ) continue;
        $found = sds_find_post_by_slug( $candidate, 'service' );
        if ( $found ) return $found;
    }

    return 0;
}

/* ==========================================================================
   9. MAIN SHORTCODE
   ========================================================================== */

add_shortcode( 'service_detail', 'sds_render_shortcode' );

function sds_render_shortcode( array $atts ): string {

    $atts = shortcode_atts(
        [
            'id'             => 0,      // Explicit Location ID
            'service_id'     => 0,      // Explicit Service ID
            'service_slug'   => '',     // Explicit Service slug
            'tour_url'       => '#',
            'book_url'       => '#',
            'video_poster'   => site_url('/wp-content/uploads/2026/06/Placeholder-Image.png'),
            'play_icon'      => site_url('/wp-content/uploads/2026/06/multimedia-play-icon-svgrepo-com.svg'),
            'green_bar_text' => 'Coffee, WiFi &amp; big ideas included',
        ],
        $atts,
        'service_detail'
    );

    // ── Resolve IDs ───────────────────────────────────────────────────────
    $location_id = sds_resolve_city_post_id( $atts );
    $service_id  = sds_resolve_service_id( $atts );

    if ( ! $location_id ) {
        return '<!-- [service_detail] Error: no location ID resolved -->';
    }

    $post = get_post( $location_id );
    if ( ! $post ) {
        return '<!-- [service_detail] Error: location post ' . $location_id . ' not found -->';
    }

    // ── Read location-specific meta fields ────────────────────────────────
    // Start with flat meta/SCF as fallback (represents the "active" service)
    $f = [];
    $field_keys = [
        'hours_of_operation', 'location_address', 'metro_info',
        'closest_airport', 'nearby_hotels', 'nearby_restaurants',
        'closest_mall', 'closest_cafe', 'where_to_park',
        'google_location', 'price', 'video_url',
    ];
    foreach ( $field_keys as $key ) {
        $f[ $key ] = sds_get_field( $key, $location_id );
    }

    // ── OVERRIDE WITH URL-SPECIFIC SERVICE DATA ───────────────────────────
    // If we resolved a service from the URL, fetch the JSON map from meta
    // and extract the specific fields saved for this service.
    if ( $service_id > 0 ) {
        $raw_records = get_post_meta( $location_id, '_csm_service_records', true );

        // Accept multiple storage formats: JSON string, PHP array, or stdClass.
        $records = [];
        if ( is_string( $raw_records ) ) {
            $decoded = json_decode( $raw_records, true );
            if ( is_array( $decoded ) ) {
                $records = $decoded;
            }
        } elseif ( is_array( $raw_records ) ) {
            $records = $raw_records;
        } elseif ( is_object( $raw_records ) ) {
            // Convert object to array safely.
            $records = json_decode( wp_json_encode( $raw_records ), true );
            if ( ! is_array( $records ) ) {
                $records = [];
            }
        }

        if ( isset( $records[ $service_id ] ) && is_array( $records[ $service_id ] ) ) {
            foreach ( $field_keys as $key ) {
                if ( isset( $records[ $service_id ][ $key ] ) ) {
                    $f[ $key ] = $records[ $service_id ][ $key ];
                }
            }
        }
    }

    // ── Resolve the linked Service post (for Title, Desc, Image) ──────────
    // If we have a service ID from URL, use it directly. Otherwise, fall back
    // to the post meta "_linked_service_id".
    $service_post_id = $service_id > 0 ? $service_id : sds_get_linked_service_id( $location_id );
    $service_post    = $service_post_id ? get_post( $service_post_id ) : null;

    if ( $service_post ) {
        $title             = get_the_title( $service_post_id );
        $description       = apply_filters( 'the_content', $service_post->post_content );
        $included_text     = sds_get_field('included_text', $service_post_id);
        $included_features = function_exists( 'get_field' ) ? get_field('included_features', $service_post_id) : [];
    } else {
        // No Service linked — fall back to the location post's own data
        $title             = get_the_title( $location_id );
        $description       = apply_filters( 'the_content', $post->post_content );
        $included_text     = '';
        $included_features = [];
    }
    
    if ( empty( trim( strip_tags( $description ) ) ) ) {
        $description = '';
    }

    // Featured image — from the linked Service post first, then location
    $image_url = $service_post ? get_the_post_thumbnail_url( $service_post_id, 'large' ) : '';
    if ( ! $image_url ) {
        $image_url = get_the_post_thumbnail_url( $location_id, 'large' );
    }
    if ( ! $image_url ) {
        $image_url = esc_url( wp_get_upload_dir()['baseurl'] . '/2026/06/featredimg.png' );
    }

    // ── Prepare Display Variables ─────────────────────────────────────────
    $price_display = $f['price'] ? esc_html( sds_fix_unicode( $f['price'] ) ) : '';
    $map_src       = sds_maps_embed_url( $f['google_location'] );
    $video_embed   = sds_video_embed_url( $f['video_url'] );
    $video_poster  = esc_url( $atts['video_poster'] );
    $icons         = apply_filters( 'sds_feature_icons', sds_default_icons(), $location_id, $atts );
    $labels        = sds_feature_labels();
    $amenities     = apply_filters( 'sds_amenities', sds_amenity_items(), $location_id );

    // ── Build HTML ────────────────────────────────────────────────────────
    ob_start();
    ?>

    <div class="singleservice-container">

        <!-- ── Heading ──────────────────────────────────────────────────── -->
        <div class="heading">
            <h2><?php echo esc_html( $title ); ?></h2>
            <?php if ( $price_display ) : 
                      // Get current service slug
            $service_slug = $service_post_id ? get_post_field( 'post_name', $service_post_id ) : '';
            if ( $service_slug == 'podcast-studios' ) {
                $price_display = '₹' . $price_display . '/hour';                
            } else {
                $price_display = '₹' . $price_display . '/day'; 
            }
                ?>

                <span class="price-tag">Starting at <?php echo  $price_display; ?></span>
            <?php endif; ?>
        </div>

        <!-- ── Podcast / Service Card ───────────────────────────────────── -->
        <div class="podcast-card">
            <div class="podcast-image">
                <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $title ); ?>">
            </div>
            <?php if ( $description ) : ?>
                <div class="podcast-content">
                    <?php echo wp_kses_post( $description ); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Google Map ───────────────────────────────────────────────── -->
        <?php if ( $map_src ) : ?>
            <div class="map-wrapper">
                <iframe
                    src="<?php echo esc_url( $map_src ); ?>"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
            </div>
        <?php endif; ?>

        <!-- ── Feature Blocks ───────────────────────────────────────────── -->
        <div class="features">

            <?php foreach ( $labels as $key => $label ) :
                $value = $f[ $key ] ?? '';

                // Skip this feature block entirely if no meta/SCF value exists.
                if ( '' === $value ) {
                    continue;
                }

                $icon  = $icons[ $key ] ?? '';
            ?>
                <div class="feature">
                    <?php if ( $icon ) : ?>
                        <img src="<?php echo esc_url( $icon ); ?>" alt="<?php echo esc_attr( strtolower( $label ) ); ?>">
                    <?php endif; ?>
                    <div class="content">
                        <h4><?php echo esc_html( $label ); ?></h4>
                        <div class="content-desc">
                            <?php echo sds_lines_to_paragraphs( $value ); ?>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

        </div><!-- .features -->

        <!-- ── Video Section ────────────────────────────────────────────── -->
        <?php if ( $f['video_url'] ) : ?>
            <div class="video-wrapper" data-embed="<?php echo esc_attr( $video_embed ); ?>">
                <img class="poster" src="<?php echo $video_poster; ?>" alt="Video preview">
                <button class="play-btn" aria-label="Play video">
                    <img src="<?php echo esc_url( $atts['play_icon'] ); ?>" alt="Play">
                </button>
            </div>
        <?php endif; ?>
        <div id="sticky-stop"></div>
    </div><!-- .singleservice-container -->

    <!-- ── CTA Bar ──────────────────────────────────────────────────────── -->
<section class="bar">
    <!-- Triggers Tour Popup -->
    <a href="javascript:void(0);" id="tour-open-popup" class="commonbtn">
        <span>Schedule a tour</span>
    </a>
    <!-- Triggers Booking Popup -->
    <a href="javascript:void(0);" id="hbs-open-popup" class="day-pass-btn">
        <span>Book space</span>
    </a>
</section>

<!-- Schedule Tour Popup -->
<div id="tour-popup-overlay" class="popup-overlay">
    <div class="hbs-popup-modal">
        <button class="popup-close-btn tour-close-popup" aria-label="Close popup">&times;</button>
        <?php echo do_shortcode('[cwf_form_tour_booking]'); ?>
    </div>
</div>

<!-- Book Space Popup -->
<div id="hbs-popup-overlay" class="popup-overlay">
    <div class="hbs-popup-modal">
        <button class="popup-close-btn hbs-close-popup" aria-label="Close popup">&times;</button>
        <?php
        $booking_shortcode = '[thinkhaus_booking]';

        // Get current service slug
        $service_slug = $service_post_id ? get_post_field( 'post_name', $service_post_id ) : '';

        // Map service slugs to shortcodes
        $shortcode_map = [
            'private-suites' => '[private_suites_inquiry]',
        ];

        // Override default shortcode if mapping exists
        if ( isset( $shortcode_map[ $service_slug ] ) ) {
            $booking_shortcode = $shortcode_map[ $service_slug ];
        }

        echo do_shortcode( $booking_shortcode );
        ?>
    </div>
</div>

    <!-- ── Green Amenities Bar ──────────────────────────────────────────── -->
    <section class="greenbar">
        <?php if ( ! empty( $included_text ) ) : ?>
            <h4><?php echo esc_html( $included_text ); ?></h4>
        <?php endif; ?>

        <?php if ( ! empty( $included_features ) && is_array( $included_features ) ) : ?>
            <div class="amenities">
                <?php foreach ( $included_features as $feature ) :
                    $image_data = isset( $feature['includes_images'] ) ? $feature['includes_images'] : array();
                    $item_text  = isset( $feature['include_text'] ) ? $feature['include_text'] : '';

                    $img_url = '';
                    $img_alt = '';

                    if ( ! empty( $image_data ) ) {
                        if ( is_array( $image_data ) ) {
                            $img_url = isset( $image_data['url'] ) ? $image_data['url'] : '';
                            $img_alt = isset( $image_data['alt'] ) ? $image_data['alt'] : '';

                            if ( empty( $img_alt ) && isset( $image_data['id'] ) ) {
                                $img_alt = get_post_meta( $image_data['id'], '_wp_attachment_image_alt', true );
                            }
                        } else {
                            $img_url = $image_data;
                        }
                    }
                ?>
                    <div class="item">
                        <?php if ( ! empty( $img_url ) ) : ?>
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $img_alt ); ?>" />
                        <?php endif; ?>
                        <!-- <?php if ( ! empty( $item_text ) ) : ?>
                            <p><?php echo esc_html( $item_text ); ?></p>
                        <?php endif; ?> -->
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Inline JS: video play handler ───────────────────────────────── -->
    <script>
    (function () {
        var wrapper = document.querySelector('.video-wrapper[data-embed]');
        if ( ! wrapper ) return;
        var btn = wrapper.querySelector('.play-btn');
        if ( ! btn ) return;
        btn.addEventListener('click', function () {
            var embed = wrapper.getAttribute('data-embed');
            if ( ! embed ) return;
            var iframe = document.createElement('iframe');
            iframe.src             = embed;
            iframe.allowFullscreen = true;
            iframe.allow           = 'autoplay; encrypted-media';
            iframe.style.width     = '100%';
            iframe.style.height    = '100%';
            iframe.style.border    = '0';
            wrapper.innerHTML = '';
            wrapper.appendChild(iframe);
        });
    })();
    </script>

    <?php
    return ob_get_clean();
}