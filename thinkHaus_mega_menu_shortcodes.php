<?php
/**
 * Plugin Name: ThinkHaus Mega Menu Shortcodes
 * Plugin URI:  https://thinkhaus.com
 * Description: Shortcodes for Elementor menus — Services grid, City/Location tabbed navigation, and a combined Mobile accordion menu.
 * Version:     1.1.0
 * Author:      ThinkHaus
 * Author URI:  https://thinkhaus.com
 * License:     GPL-2.0+
 * Text Domain: thinkhaus-mega-menu
 * Domain Path: /languages
 *
 * Shortcodes:
 *   [thinkhaus_services_menu]   — Service cards with ACF image + excerpt (desktop mega-menu)
 *   [thinkhaus_locations_menu]  — Tabbed city → location navigation (desktop mega-menu)
 *   [thinkhaus_mobile_menu]     — Combined accordion menu (Services / Locations / About) for mobile nav
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TH_MM_VERSION', '1.1.0' );
define( 'TH_MM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TH_MM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TH_MM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


final class ThinkHaus_Mega_Menu {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_shortcode( 'thinkhaus_services_menu', array( $this, 'render_services_menu' ) );
        add_shortcode( 'thinkhaus_locations_menu', array( $this, 'render_locations_menu' ) );
        add_shortcode( 'thinkhaus_mobile_menu', array( $this, 'render_mobile_menu' ) );
    }

    private function __clone() {}

    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }


    /* ══════════════════════════════════════════════════════════════
       SHORTCODE 1 — SERVICES MEGA MENU (desktop)
       ══════════════════════════════════════════════════════════════ */

    public function render_services_menu( $atts ) {

        $atts = shortcode_atts( array(
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ), $atts, 'thinkhaus_services_menu' );

        $cards_markup = $this->get_services_cards_markup( $atts );

        if ( '' === $cards_markup ) {
            return '';
        }

        return '<div class="mega-menu">' . $cards_markup . '</div>';
    }

    /**
     * Builds just the <a class="menu-card"> items for the services grid.
     * Shared by the desktop services shortcode and the mobile menu shortcode.
     */
    private function get_services_cards_markup( $atts ) {

        $services = get_posts( array(
            'post_type'      => 'service',
            'posts_per_page' => intval( $atts['posts_per_page'] ),
            'orderby'        => sanitize_key( $atts['orderby'] ),
            'order'          => sanitize_key( $atts['order'] ),
            'post_status'    => 'publish',
        ) );

        if ( empty( $services ) ) {
            return '';
        }

        ob_start();

        foreach ( $services as $service ) :

            $image_raw = get_field( 'filter_image', $service->ID );

            if ( empty( $image_raw ) ) {
                continue;
            }

            if ( is_array( $image_raw ) ) {
                $image_url = isset( $image_raw['url'] ) ? $image_raw['url'] : '';
            } else {
                $image_url = $image_raw;
            }

            if ( empty( $image_url ) || ! is_string( $image_url ) ) {
                continue;
            }

            $excerpt = $service->post_excerpt
                ? $service->post_excerpt
                : wp_trim_words( $service->post_content, 12, '...' );

            $link = home_url( '/cities/?servicetype=' . $service->post_name . '/' );
            ?>
            <a href="<?php echo esc_url( $link ); ?>" class="menu-card">
                <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $service->post_title ); ?>" loading="lazy">
                <div>
                    <h4><?php echo esc_html( $service->post_title ); ?></h4>
                    <p><?php echo esc_html( $excerpt ); ?></p>
                </div>
            </a>
        <?php endforeach;

        return ob_get_clean();
    }


    /* ══════════════════════════════════════════════════════════════
       SHORTCODE 2 — LOCATIONS MEGA MENU (desktop, tabbed)
       ══════════════════════════════════════════════════════════════ */

    public function render_locations_menu( $atts ) {

        $atts = shortcode_atts( array(
            'orderby' => 'menu_order',
            'order'   => 'ASC',
        ), $atts, 'thinkhaus_locations_menu' );

        $wrapper_markup = $this->get_locations_wrapper_markup( $atts );

        if ( '' === $wrapper_markup ) {
            return '';
        }

        return $wrapper_markup . $this->get_locations_js();
    }

    /**
     * Builds the full .location-wrapper (tabs + panels) markup.
     * Shared by the desktop locations shortcode and the mobile menu shortcode.
     */
    private function get_locations_wrapper_markup( $atts ) {

        $parent_cities = get_posts( array(
            'post_type'      => 'city',
            'posts_per_page' => -1,
            'post_parent'    => 0,
            'post_status'    => 'publish',
            'orderby'        => sanitize_key( $atts['orderby'] ),
            'order'          => sanitize_key( $atts['order'] ),
        ) );

        if ( empty( $parent_cities ) ) {
            return '';
        }

        $parent_ids   = wp_list_pluck( $parent_cities, 'ID' );
        $all_children = get_posts( array(
            'post_type'       => 'city',
            'posts_per_page'  => -1,
            'post_parent__in' => $parent_ids,
            'post_status'     => 'publish',
            'orderby'         => 'menu_order',
            'order'           => 'ASC',
        ) );

        $children_grouped = array();
        foreach ( $all_children as $child ) {
            $children_grouped[ $child->post_parent ][] = $child;
        }

        ob_start();
        $tab_index = 0;
        ?>
        <div class="location-wrapper" data-th-locations>

            <div class="location-tabs">
                <ul>
                    <?php
                    foreach ( $parent_cities as $parent ) :
                        if ( empty( $children_grouped[ $parent->ID ] ) ) {
                            continue;
                        }
                        $tab_index++;
                        $active_class = ( 1 === $tab_index ) ? ' active' : '';
                        ?>
                        <li class="<?php echo $active_class; ?>" data-tab="<?php echo $tab_index; ?>">
                            <?php echo esc_html( $parent->post_title ); ?>
                            <span>➜</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="location-content">
                <?php
                $content_index = 0;
                foreach ( $parent_cities as $parent ) :
                    $children = isset( $children_grouped[ $parent->ID ] ) ? $children_grouped[ $parent->ID ] : array();
                    if ( empty( $children ) ) {
                        continue;
                    }
                    $content_index++;
                    $active_class = ( 1 === $content_index ) ? ' active' : '';
                    ?>
                    <div class="city-list<?php echo $active_class; ?>" id="tab<?php echo $content_index; ?>">
                        <?php foreach ( $children as $child ) :
                            $link = home_url( '/city/' . $parent->post_name . '/' . $child->post_name . '/?location=' . $child->post_name );
                            ?>
                            <p><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $child->post_title ); ?></a></p>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php

        return ob_get_clean();
    }


    /* ══════════════════════════════════════════════════════════════
       SHORTCODE 3 — MOBILE MENU (combined accordion)
       ══════════════════════════════════════════════════════════════ */

    /**
     * [thinkhaus_mobile_menu]
     *
     * Outputs a full <ul class="menu"> with "has-submenu" accordion items for
     * Services and Locations (reusing the same data as the desktop shortcodes),
     * plus a static "About Us" link. Intended for the mobile nav / off-canvas menu.
     *
     * Attributes:
     *   services_label (string) default "OUR SERVICES"
     *   locations_label (string) default "LOCATIONS"
     *   about_label (string) default "ABOUT US"
     *   about_url (string) default site home URL (falls back to "#")
     */
    public function render_mobile_menu( $atts ) {

        $atts = shortcode_atts( array(
            'services_label'  => __( 'OUR SERVICES', 'thinkhaus-mega-menu' ),
            'locations_label' => __( 'LOCATIONS', 'thinkhaus-mega-menu' ),
            'about_label'     => __( 'ABOUT US', 'thinkhaus-mega-menu' ),
            'about_url'       => site_url( '/about-us' ),
        ), $atts, 'thinkhaus_mobile_menu' );

        $about_url = ! empty( $atts['about_url'] ) ? $atts['about_url'] : '#';

        $services_cards = $this->get_services_cards_markup( array(
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ) );

        $locations_wrapper = $this->get_locations_wrapper_markup( array(
            'orderby' => 'menu_order',
            'order'   => 'ASC',
        ) );

        ob_start();
        ?>
        <ul class="menu th-mobile-menu">

            <?php if ( '' !== $services_cards ) : ?>
                <li class="has-submenu">
                    <a href="#">
                        <?php echo esc_html( $atts['services_label'] ); ?>
                        <i class="fa-solid fa-chevron-down"></i>
                    </a>
                    <ul class="submenu">
                        <div class="mega-menu">
                            <?php echo $services_cards; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped in get_services_cards_markup() ?>
                        </div>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ( '' !== $locations_wrapper ) : ?>
                <li class="has-submenu">
                    <a href="#">
                        <?php echo esc_html( $atts['locations_label'] ); ?>
                        <i class="fa-solid fa-chevron-down"></i>
                    </a>
                    <ul class="submenu">
                        <?php echo $locations_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped in get_locations_wrapper_markup() ?>
                    </ul>
                </li>
            <?php endif; ?>

            <li>
                <a href="<?php echo esc_url( $about_url ); ?>"><?php echo esc_html( $atts['about_label'] ); ?></a>
            </li>

        </ul>
        <?php

        $html = ob_get_clean();

        // Tab-switching JS for the Locations panel inside the accordion.
        $html .= $this->get_locations_js();

        // Accordion open/close JS for the has-submenu items (mobile only).
        $html .= $this->get_mobile_menu_js();

        return $html;
    }

    /**
     * Accordion toggle JS for .has-submenu items — output only once per page.
     * Mirrors the jQuery behaviour from the reference markup: on viewports
     * <= 991px, clicking a top-level link toggles its .submenu open/closed
     * and closes any other open submenu.
     */
    private function get_mobile_menu_js() {

        static $js_printed = false;
        if ( $js_printed ) {
            return '';
        }
        $js_printed = true;

        ob_start();
        ?>
        <script>
        (function($) {
            if (window.__thMobileMenuInit) return;
            window.__thMobileMenuInit = true;

            function initMobileMenu() {
                $('.th-mobile-menu > .has-submenu > a').off('click.thMobileMenu').on('click.thMobileMenu', function (e) {

                    if ($(window).width() <= 991) {

                        e.preventDefault();

                        var submenu = $(this).next('.submenu');

                        // Close any other open submenus.
                        $('.th-mobile-menu .submenu').not(submenu).slideUp();

                        // Toggle current.
                        submenu.slideToggle();
                    }
                });
            }

            if (window.jQuery) {
                $(document).ready(initMobileMenu);
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    if (window.jQuery) {
                        initMobileMenu();
                    }
                });
            }

            if (window.elementorFrontend) {
                window.elementorFrontend.hooks.addAction('frontend/element_ready/global', initMobileMenu);
            }
        })(jQuery);
        </script>
        <?php

        return ob_get_clean();
    }


    /**
     * Tab-switching JS — output only once per page.
     */
    private function get_locations_js() {

        static $js_printed = false;
        if ( $js_printed ) {
            return '';
        }
        $js_printed = true;

        ob_start();
        ?>
        <script>
        (function() {
            if (window.__thLocationsInit) return;
            window.__thLocationsInit = true;

            function initTabs() {
                document.querySelectorAll('[data-th-locations]').forEach(function(wrapper) {
                    if (wrapper.dataset.thReady) return;
                    wrapper.dataset.thReady = '1';

                    var tabs   = wrapper.querySelectorAll('.location-tabs li');
                    var panels = wrapper.querySelectorAll('.city-list');

                    tabs.forEach(function(tab) {
                        tab.addEventListener('click', function() {
                            var target = this.getAttribute('data-tab');

                            tabs.forEach(function(t) { t.classList.remove('active'); });
                            panels.forEach(function(p) { p.classList.remove('active'); });

                            this.classList.add('active');
                            var panel = wrapper.querySelector('#tab' + target);
                            if (panel) panel.classList.add('active');
                        });
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTabs);
            } else {
                initTabs();
            }

            if (window.elementorFrontend) {
                window.elementorFrontend.hooks.addAction('frontend/element_ready/global', initTabs);
            }

            var observer = new MutationObserver(function(mutations) {
                var found = false;
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.matches && node.matches('[data-th-locations]')) found = true;
                            if (node.querySelector && node.querySelector('[data-th-locations]')) found = true;
                        }
                    });
                });
                if (found) initTabs();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        })();
        </script>
        <?php

        return ob_get_clean();
    }


    /* ══════════════════════════════════════════════════════════════
       I18N
       ══════════════════════════════════════════════════════════════ */

    public function load_textdomain() {
        load_plugin_textdomain(
            'thinkhaus-mega-menu',
            false,
            dirname( TH_MM_PLUGIN_BASENAME ) . '/languages'
        );
    }
}

ThinkHaus_Mega_Menu::get_instance();