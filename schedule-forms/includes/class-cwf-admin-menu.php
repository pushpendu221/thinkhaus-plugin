<?php
/**
 * CWF_Admin_Menu
 *
 * Builds:
 *   Schedule Forms (top-level menu)
 *     -> Tour Booking Form: Settings
 *     -> Tour Booking Form: Submissions
 *     -> (repeats per registered form)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWF_Admin_Menu {

	/** @var array<string, CWF_Form_Module> */
	protected $forms;

	public function __construct( $forms ) {
		$this->forms = $forms;
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_menus() {
		$parent_slug = 'cwf_dashboard';

		add_menu_page(
			'Schedule Forms',
			'Schedule Forms',
			'manage_options',
			$parent_slug,
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			26
		);

		foreach ( $this->forms as $form ) {
			add_submenu_page(
				$parent_slug,
				$form->label . ' Settings',
				$form->label . ': Settings',
				'manage_options',
				'cwf_' . $form->slug . '_settings',
				array( $form, 'render_settings_page' )
			);

			add_submenu_page(
				$parent_slug,
				$form->label . ' Submissions',
				$form->label . ': Submissions',
				'manage_options',
				'cwf_' . $form->slug . '_submissions',
				array( $form, 'render_submissions_page' )
			);
		}

		// Remove the auto-generated duplicate first submenu item that mirrors the top-level page.
		remove_submenu_page( $parent_slug, $parent_slug );
	}

	public function render_dashboard() {
		?>
		<div class="wrap cwf-admin-wrap">
			<h1>Schedule Forms</h1>
			<p>Each form below has its own Settings (configure fields like calendar availability) and Submissions (view entries) screen.</p>
			<ul class="cwf-dashboard-list">
				<?php foreach ( $this->forms as $form ) : ?>
					<li>
						<strong><?php echo esc_html( $form->label ); ?></strong>
						&nbsp;&mdash;&nbsp;
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cwf_' . $form->slug . '_settings' ) ); ?>">Settings</a>
						&nbsp;|&nbsp;
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cwf_' . $form->slug . '_submissions' ) ); ?>">Submissions</a>
						&nbsp;|&nbsp;
						<code>[<?php echo esc_html( $form->shortcode_tag() ); ?>]</code>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		// Only load on our own admin pages.
		if ( ! isset( $_GET['page'] ) || 0 !== strpos( $_GET['page'], 'cwf_' ) ) {
			return;
		}

		wp_enqueue_style( 'cwf-admin', CWF_PLUGIN_URL . 'assets/css/admin.css', array(), CWF_VERSION );
		wp_enqueue_script( 'cwf-admin', CWF_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CWF_VERSION, true );

		wp_localize_script(
			'cwf-admin',
			'cwfAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}
}