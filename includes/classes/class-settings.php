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
		}

		/**
		 * Create settings page on init to ensure text domain is loaded
		 */
		public function create_settings_page() {
			global $tinypress_wpdk;

			// Generate settings page
			$settings_args = array(
				'framework_title' => '<img style="max-width: 220px;" src="' . TINYPRESS_PLUGIN_URL . '/assets/images/publishpress-shortlinks.svg">',
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

		function render_field_tinypress_browsers() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/browsers.php';
		}

		function render_field_tinypress_supports() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/supports.php';
		}

		function render_field_tinypress_upgrade() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/upgrade.php';
		}


		/**
		 * Return settings pages
		 *
		 * @return mixed|void
		 */
		function get_settings_pages() {

			$user_roles = tinypress_get_roles();

			$field_sections['settings'] = array(
				'title'    => esc_html__( 'General', 'tinypress' ),
				'sections' => array(
					array(
						'title'  => esc_html__( 'Options', 'tinypress' ),
						'fields' => array(
							array(
								'id'       => 'tinypress_link_prefix',
								'type'     => 'switcher',
								'title'    => esc_html__( 'Link Prefix', 'tinypress' ),
								'subtitle' => esc_html__( 'Add custom prefix.', 'tinypress' ),
								'label'    => esc_html__( 'Customize your tiny url in a better way.', 'tinypress' ),
								'default'  => true,
							),
							array(
								'id'          => 'tinypress_link_prefix_slug',
								'type'        => 'text',
								'title'       => esc_html__( 'Prefix Slug', 'tinypress' ),
								'subtitle'    => esc_html__( 'Custom prefix slug.', 'tinypress' ),
								'desc'        => sprintf( esc_html__( 'This prefix slug will be added this way - %s', 'tinypress' ), esc_url( site_url( 'go/my-tiny-slug' ) ) ),
								'placeholder' => esc_html__( 'go', 'tinypress' ),
								'default'     => esc_html__( 'go', 'tinypress' ),
								'dependency'  => array( 'tinypress_link_prefix', '==', '1' ),
							),
							array(
								'id'          => 'tinypress_kb_shortcut',
								'type'        => 'text',
								'title'       => esc_html__( 'Keyboard Shortcut', 'tinypress' ),
								'subtitle'    => esc_html__( 'Configure your K/B', 'tinypress' ),
								'desc'        => esc_html__( 'You can now short your large links from anywhere inside your WordPress dashboard.', 'tinypress' ) . '<br>' .
								                 esc_html__( 'For now you have no option to set your own shortcut but this will come soon.', 'tinypress' ),
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
								'title'    => esc_html__( 'Hide Modal Opener', 'tinypress' ),
								'subtitle' => esc_html__( 'Remove from WP Admin Bar', 'tinypress' ),
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
								'title'      => esc_html__( 'Who Can View Links?', 'tinypress' ),
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
								'title'      => esc_html__( 'Who Can Create/Edit Links', 'tinypress' ),
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
						'title'  => esc_html__( 'Browser Extensions', 'tinypress' ),
						'fields' => array(
							array(
								'id'           => 'tinypress_chrome_address',
								'type'         => 'text',
								'title'        => esc_html__( 'Website Address', 'tinypress' ),
								'desc'         => esc_html__( 'Click on the field to copy the address.', 'tinypress' ),
								'default'      => site_url(),
								'class'        => 'tinypress-settings-copy',
								'attributes'   => array(
									'readonly' => true,
								),
								'availability' => ! tinypress()::is_license_active() ? 'pro' : '',
							),
							array(
								'id'           => 'tinypress_chrome_auth_key',
								'type'         => 'text',
								'title'        => esc_html__( 'Authentication Key', 'tinypress' ),
								'desc'         => esc_html__( 'Click on the field to copy the authentication key.', 'tinypress' ),
								'class'        => 'tinypress-settings-copy',
								'default'      => md5( current_time( 'U' ) ),
								'attributes'   => array(
									'readonly' => true,
								),
								'availability' => ! tinypress()::is_license_active() ? 'pro' : '',
							),
							array(
								'id'           => 'tinypress_browsers',
								'type'         => 'callback',
								'function'     => array( $this, 'render_field_tinypress_browsers' ),
								'title'        => esc_html__( 'Supported Browsers', 'tinypress' ),
								'availability' => ! tinypress()::is_license_active() ? 'pro' : '',
							),
						),
					),
					array(
						'title'  => esc_html__( 'Help and Supports', 'tinypress' ),
						'fields' => array(
							array(
								'id'       => 'tinypress_supports',
								'type'     => 'callback',
								'function' => array( $this, 'render_field_tinypress_supports' ),
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