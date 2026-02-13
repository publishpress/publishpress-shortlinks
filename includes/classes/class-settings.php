<?php
/**
 * Settings class
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Settings' ) ) {
	class TINYPRESS_Settings {

		protected static $_instance = null;

		/**
		 * TINYPRESS_Settings constructor.
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'create_settings_page' ), 5 );
			add_filter( 'pb_settings_tinypress_settings_save', array( $this, 'sanitize_autolist_settings' ), 10, 2 );
			add_action( 'pb_settings_options_before', array( $this, 'add_settings_wrapper_start' ) );
			add_action( 'pb_settings_options_after', array( $this, 'add_settings_wrapper_end' ) );
		}

		/**
		 * Sanitize autolist settings to prevent duplicates and invalid post types
		 *
		 * @param array $request
		 * @param array $args
		 * @return array
		 */
		function sanitize_autolist_settings( $request, $args ) {
			if ( ! isset( $request['tinypress_autolist_post_types'] ) ) {
				return $request;
			}

			$post_types = $request['tinypress_autolist_post_types'];
			
			if ( ! is_array( $post_types ) ) {
				return $request;
			}

			$all_post_types = get_post_types( array( 'public' => true ), 'names' );
			$valid_post_types = array_diff( $all_post_types, array( 'attachment', 'tinypress_link' ) );
			
			// Track seen post types to prevent duplicates
			$seen = array();
			$sanitized = array();

			foreach ( $post_types as $config ) {
				if ( ! isset( $config['post_type'] ) || empty( $config['post_type'] ) || trim( $config['post_type'] ) === '' ) {
					continue;
				}

				$post_type = trim( $config['post_type'] );

				if ( ! in_array( $post_type, $valid_post_types ) ) {
					continue;
				}

				if ( isset( $seen[ $post_type ] ) ) {
					continue;
				}

				if ( ! isset( $config['behavior'] ) || empty( $config['behavior'] ) ) {
					$config['behavior'] = 'never';
				}

				$seen[ $post_type ] = true;
				$sanitized[] = array(
					'post_type' => $post_type,
					'behavior'  => $config['behavior'],
				);
			}

			$request['tinypress_autolist_post_types'] = $sanitized;

			return $request;
		}

		/**
		 * Create settings page on init to ensure text domain is loaded
		 */
		public function create_settings_page() {
			global $tinypress_wpdk;

			// Generate settings page
			$settings_args = array(
				'menu_title'      => esc_html__( 'Settings', 'tinypress' ),
				'menu_slug'       => 'settings',
				'menu_type'       => 'submenu',
				'menu_parent'     => 'edit.php?post_type=tinypress_link',
				'database'        => 'option',
				'theme'           => 'light',
				'show_search'     => false,
				'pro_url'         => TINYPRESS_LINK_PRO,
			);

			WPDK_Settings::createSettingsPage( $tinypress_wpdk->plugin_unique_id, $settings_args, $this->get_settings_pages() );
		}

		function add_settings_wrapper_start() {
			echo '<div class="tinypress-settings-layout">';
		}

		function add_settings_wrapper_end() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/supports.php';
			echo '</div>';
		}

		function render_field_tinypress_upgrade() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/upgrade.php';
		}


		/**
		 * Get all public post types for auto-list settings
		 *
		 * @return array
		 */
		function get_public_post_types_for_autolist() {
			$post_types = get_post_types( array( 'public' => true ), 'objects' );
			$default_settings = array();

			foreach ( $post_types as $post_type ) {
				if ( ! in_array( $post_type->name, array( 'attachment', 'tinypress_link' ) ) ) {
					$default_settings[] = array(
						'post_type' => $post_type->name,
						'behavior'  => 'never',
					);
				}
			}

			return $default_settings;
		}

		/**
		 * Get post type options for dropdown
		 *
		 * @return array
		 */
		function get_post_type_options() {
			$post_types = get_post_types( array( 'public' => true ), 'objects' );
			$options = array();

			foreach ( $post_types as $post_type ) {
				if ( ! in_array( $post_type->name, array( 'attachment', 'tinypress_link' ) ) ) {
					$options[ $post_type->name ] = $post_type->labels->singular_name . ' (' . $post_type->name . ')';
				}
			}

			return $options;
		}

		/**
		 * Return settings pages
		 *
		 * @return mixed|void
		 */
		function get_settings_pages() {

			$user_roles = tinypress_get_roles();
			
			// Get post type options dynamically when settings page is rendered
			// This ensures CPT UI and other late-registered post types are included
			$post_type_options = $this->get_post_type_options();

			$field_sections['settings'] = array(
				'title'    => esc_html__( 'General', 'tinypress' ),
				'sections' => array(
					array(
						'title'  => esc_html__( 'Options', 'tinypress' ),
						'fields' => array(
							array(
								'id'       => 'tinypress_link_prefix',
								'type'     => 'switcher',
								'title'    => esc_html__( 'Shortlink Prefix', 'tinypress' ),
								'label'    => esc_html__( 'Add a prefix between your domain name and shortlink.', 'tinypress' ),
								'default'  => true,
							),
							array(
								'id'          => 'tinypress_link_prefix_slug',
								'type'        => 'text',
								'title'       => esc_html__( 'Prefix Slug', 'tinypress' ),
								'subtitle'    => esc_html__( 'Custom prefix slug.', 'tinypress' ),
								'desc'        => esc_html__( 'This text will be added between your domain name and shortlink.', 'tinypress' ),
								'placeholder' => esc_html__( 'go', 'tinypress' ),
								'default'     => esc_html__( 'go', 'tinypress' ),
								'dependency'  => array( 'tinypress_link_prefix', '==', '1' ),
							),
							array(
								'id'          => 'tinypress_kb_shortcut',
								'type'        => 'text',
								'title'       => esc_html__( 'Keyboard Shortcut', 'tinypress' ),
								'desc'        => esc_html__( 'Create shortlinks from anywhere inside your WordPress dashboard.', 'tinypress' ),
								'placeholder' => esc_html__( 'Ctrl or Cmd + /', 'tinypress' ),
								'default'     => esc_html__( 'Ctrl or Cmd + /', 'tinypress' ),
								'attributes'  => array(
									'disabled' => true,
								),
								'dependency'  => array( 'tinypress_link_prefix', '==', '1' ),
							),
							array(
								'id'       => 'tinypress_hide_modal_opener',
								'type'     => 'switcher',
								'title'    => esc_html__( 'Remove from Admin Bar', 'tinypress' ),
								'label'    => esc_html__( 'Hide quick short link modal opener from WP Admin Bar.', 'tinypress' ),
								'default'  => false,
							),
						),
					),
					array(
						'title'  => esc_html__( 'Role Management', 'tinypress' ),
						'fields' => array(
							array(
								'id'         => 'tinypress_role_view',
								'type'       => 'checkbox',
								'title'      => esc_html__( 'Who Can View Shortlinks', 'tinypress' ),
								'subtitle'   => esc_html__( 'Upcoming feature.', 'tinypress' ),
								'desc'       => esc_html__( 'Only selected user roles can view links.', 'tinypress' ),
								'inline'     => true,
								'options'    => $user_roles,
								'attributes' => array(
									'disabled' => true,
								),
							),
							array(
								'id'         => 'tinypress_role_create',
								'type'       => 'checkbox',
								'title'      => esc_html__( 'Who Can Create/Edit Shortlinks', 'tinypress' ),
								'subtitle'   => esc_html__( 'Upcoming feature.', 'tinypress' ),
								'desc'       => esc_html__( 'Only selected user roles can create or edit links.', 'tinypress' ),
								'inline'     => true,
								'options'    => $user_roles,
								'attributes' => array(
									'disabled' => true,
								),
							),
							array(
								'id'         => 'tinypress_role_analytics',
								'type'       => 'checkbox',
								'title'      => esc_html__( 'Who Can See Analytics', 'tinypress' ),
								'subtitle'   => esc_html__( 'Upcoming feature.', 'tinypress' ),
								'desc'       => esc_html__( 'Only selected user roles can see analytics.', 'tinypress' ),
								'inline'     => true,
								'options'    => $user_roles,
								'attributes' => array(
									'disabled' => true,
								),
							),
							array(
								'id'         => 'tinypress_role_edit',
								'type'       => 'checkbox',
								'title'      => esc_html__( 'Who Can Control Settings', 'tinypress' ),
								'subtitle'   => esc_html__( 'Upcoming feature.', 'tinypress' ),
								'desc'       => esc_html__( 'Only selected user roles can control settings.', 'tinypress' ),
								'inline'     => true,
								'options'    => $user_roles,
								'attributes' => array(
									'disabled' => true,
								),
							),
						),
					),
					array(
						'title'  => esc_html__( 'Auto-List Links', 'tinypress' ),
						'fields' => array(
							array(
								'id'       => 'tinypress_autolist_enabled',
								'type'     => 'switcher',
								'title'    => esc_html__( 'Auto-List Shortlinks', 'tinypress' ),
								'label'    => esc_html__( 'When enabled, shortlinks will appear in the "All Links" table based on the behavior you configure below.', 'tinypress' ),
								'default'  => true,
							),
							array(
								'id'         => 'tinypress_autolist_post_types',
								'type'       => 'callback',
								'title'      => esc_html__( 'Configure Post Types', 'tinypress' ),
								'subtitle'   => esc_html__( 'Set when links should be auto-listed for each post type', 'tinypress' ),
								'desc'       => esc_html__( 'Add post types and configure when their shortlinks should appear in the "All Links" table. Changes are saved automatically.', 'tinypress' ),
								'dependency' => array( 'tinypress_autolist_enabled', '==', '1' ),
								'function'   => array( $this, 'render_autolist_ajax_field' ),
							),
						),
					),
					array(
						'title'  => esc_html__( 'Post Status Visibility', 'tinypress' ),
						'fields' => array(
							array(
								'id'       => 'tinypress_allowed_post_statuses',
								'type'     => 'checkbox',
								'title'    => esc_html__( 'Allowed Post Statuses', 'tinypress' ),
								'subtitle' => esc_html__( 'Choose which post statuses can be accessed via shortlinks', 'tinypress' ),
								'desc'     => esc_html__( 'Select which post statuses are accessible when visiting a PublishPress shortlink.', 'tinypress' ),
								'inline'   => true,
								'options'  => array(
									'publish' => esc_html__( 'Published', 'tinypress' ),
									'draft'   => esc_html__( 'Draft', 'tinypress' ),
									'pending' => esc_html__( 'Pending Review', 'tinypress' ),
									'private' => esc_html__( 'Private', 'tinypress' ),
									'future'  => esc_html__( 'Scheduled', 'tinypress' ),
								),
								'default'  => array( 'publish', 'draft', 'pending', 'private', 'future' ),
							),
						),
					),
				),


			);

			$field_sections['dummy'] = array(
				'title'    => esc_html__( 'Dummy', 'tinypress' ),
				'sections' => array(),
			);

//			$field_sections['premium'] = array(
//				'title'    => esc_html__( 'Upgrade - 60% off', 'tinypress' ),
//				'sections' => array(
//					array(
//						'title'  => esc_html__( 'Upgrade to Premium', 'tinypress' ),
//						'fields' => array(
//							array(
//								'id'       => 'tinypress_upgrade',
//								'type'     => 'callback',
//								'function' => array( $this, 'render_field_tinypress_upgrade' ),
//							),
//						),
//					),
//				),
//			);

			return apply_filters( 'TINYPRESS/Filters/settings_pages', $field_sections );
		}

		/**
		 * Render custom AJAX-powered autolist field
		 */
		public function render_autolist_ajax_field() {
			$all_settings = get_option( 'tinypress_settings', array() );
			$config = isset( $all_settings['tinypress_autolist_post_types'] ) ? $all_settings['tinypress_autolist_post_types'] : array();
			
			if ( empty( $config ) ) {
				$config = array(
					array(
						'post_type' => 'post',
						'behavior' => 'on_first_use'
					),
					array(
						'post_type' => 'page',
						'behavior' => 'on_first_use'
					)
				);
			}
			
			// Enqueue CSS
			wp_enqueue_style(
				'tinypress-autolist-ajax',
				TINYPRESS_PLUGIN_URL . 'assets/admin/css/autolist-ajax.css',
				array(),
				TINYPRESS_PLUGIN_VERSION
			);
			
			?>
			<div class="tinypress-save-indicator"></div>
			<div class="tinypress-autolist-wrapper">
				<div class="tinypress-autolist-container">
					<?php if ( empty( $config ) ) : ?>
						<div class="tinypress-autolist-empty">
							<span class="dashicons dashicons-admin-post"></span>
							<p><?php esc_html_e( 'No post types configured yet. Click "Add Post Type" to get started.', 'tinypress' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $config as $index => $item ) : ?>
							<div class="tinypress-autolist-row" data-index="<?php echo esc_attr( $index ); ?>">
								<div class="tinypress-autolist-handle">
									<span class="dashicons dashicons-menu"></span>
								</div>
								<div class="tinypress-autolist-field">
									<select class="tinypress-autolist-post-type" data-selected="<?php echo esc_attr( $item['post_type'] ); ?>">
										<option value="<?php echo esc_attr( $item['post_type'] ); ?>" selected><?php echo esc_html( $item['post_type'] ); ?></option>
									</select>
								</div>
								<div class="tinypress-autolist-field">
									<select class="tinypress-autolist-behavior">
										<option value="never" <?php selected( $item['behavior'], 'never' ); ?>><?php esc_html_e( 'Never', 'tinypress' ); ?></option>
										<option value="on_first_use" <?php selected( $item['behavior'], 'on_first_use' ); ?>><?php esc_html_e( 'On First Use', 'tinypress' ); ?></option>
										<option value="on_publish" <?php selected( $item['behavior'], 'on_publish' ); ?>><?php esc_html_e( 'On Publish', 'tinypress' ); ?></option>
									</select>
								</div>
								<div class="tinypress-autolist-actions">
									<button type="button" class="button tinypress-autolist-remove">
										<span class="dashicons dashicons-trash"></span>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<button type="button" class="button button-primary tinypress-autolist-add">
					<span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Add Post Type', 'tinypress' ); ?>
				</button>
			</div>
			<?php
		}

		/**
		 * @return TINYPRESS_Settings
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}