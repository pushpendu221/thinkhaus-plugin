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
                   <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="154" height="154" viewBox="0 0 154 154" fill="none"><rect width="154" height="154" fill="url(#pattern0_1_2661)"></rect><defs><pattern id="pattern0_1_2661" patternContentUnits="objectBoundingBox" width="1" height="1"><use xlink:href="#image0_1_2661" transform="scale(0.00195312)"></use></pattern><image id="image0_1_2661" width="512" height="512" preserveAspectRatio="none" xlink:href="data:image/png;base64,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"></image></defs></svg>
                    <!-- <img src="<?php echo esc_url( $atts['play_icon'] ); ?>" alt="Play"> -->
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