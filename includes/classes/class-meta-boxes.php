<?php
/*
* @Author 		pluginbazar
* Copyright: 	2022 pluginbazar
*/

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Meta_boxes' ) ) {
	/**
	 * Class TINYPRESS_Meta_boxes
	 */
	class TINYPRESS_Meta_boxes {

		private $tinypress_metabox_main = 'tinypress_meta_main';
		private $tinypress_metabox_side = 'tinypress_meta_side';
		private $tinypress_default_slug;


		/**
		 * TINYPRESS_Meta_boxes constructor.
		 */
		function __construct() {
			$this->tinypress_default_slug = tinypress_create_url_slug();

			$this->generate_tinypress_meta_box();

			foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
				if ( ! in_array( $post_type, array( 'attachment', 'tinypress_link' ) ) ) {
					$this->generate_tinypress_meta_box_side( $post_type );
				}
			}

			add_action( 'add_meta_boxes', array( $this, 'add_side_meta_box' ), 0 );
			add_action( 'WPDK_Settings/meta_section/analytics', array( $this, 'render_analytics' ) );
		}


		/**
		 * Render analytics section
		 *
		 * @return void
		 */
		function render_analytics() {
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/analytics.php';
		}


		/**
		 * Render Side Meta Box
		 *
		 * @return void
		 */
		function render_side_box() {
			echo '<div class="tinypress-meta-side">';
			include TINYPRESS_PLUGIN_DIR . 'templates/admin/qr-code.php';
			echo '</div>';
		}


		/**
		 * Add Side Meta Box
		 *
		 * @return void
		 */
		function add_side_meta_box() {
			add_meta_box( 'tinypress-meta-side', esc_html__( 'Side', 'tinypress' ), array( $this, 'render_side_box' ), 'tinypress_link', 'side', 'core' );
		}


		/**
		 * Generate side metabox
		 *
		 * @param $post_type
		 *
		 * @return void
		 */
		function generate_tinypress_meta_box_side( $post_type ) {
			$prefix = $this->tinypress_metabox_side . '_' . $post_type;

			WPDK_Settings::createMetabox( $prefix,
				array(
					'title'     => esc_html__( 'PublishPress Links', 'tinypress' ),
					'post_type' => $post_type,
					'data_type' => 'unserialize',
					'nav'       => 'inline',
					'context'   => 'side',
					'priority'  => 'high',
					'preview'   => true,
				)
			);

			WPDK_Settings::createSection( $prefix,
				array(
					'title'  => esc_html__( 'PublishPress Links', 'tinypress' ),
					'fields' => array(
						array(
							'id'       => 'tiny_slug',
							'title'    => ' ',
							'type'     => 'callback',
							'function' => array( $this, 'render_field_tinypress_link' ),
							'default'  => $this->tinypress_default_slug,
						),
					),
				)
			);
		}


		/**
		 * Render short URL field
		 *
		 * @param $args
		 *
		 * @return void
		 */
		function render_field_tinypress_link( $args ) {
			global $post;

			echo tinypress_get_tiny_slug_copier( $post->ID, true, $args );
		}


		/**
		 * Generate meta box for slider data
		 */
		function generate_tinypress_meta_box() {
			// Create a metabox for tinypress.
			WPDK_Settings::createMetabox( $this->tinypress_metabox_main,
				array(
					'title'     => esc_html__( 'PublishPress Links', 'tinypress' ),
					'post_type' => 'tinypress_link',
					'data_type' => 'unserialize',
					'context'   => 'normal',
					'nav'       => 'inline',
					'preview'   => true,
				)
			);

			// General Settings section.
			WPDK_Settings::createSection( $this->tinypress_metabox_main,
				array(
					'title'  => esc_html__( 'General', 'tinypress' ),
					'fields' => array(
						array(
							'id'         => 'post_title',
							'type'       => 'text',
							'title'      => esc_html__( 'Label *', 'tinypress' ),
							'wp_type'    => 'post_title',
							'subtitle'   => esc_html__( 'For admin purpose only.', 'tinypress' ),
							'attributes' => array(
								'autocomplete' => 'off',
								'class'        => 'tinypress_tiny_label',
							),
						),
						array(
							'id'         => 'target_url',
							'type'       => 'text',
							'title'      => esc_html__( 'Target URL *', 'tinypress' ),
							'attributes' => array(
								'class' => 'tinypress_tiny_url',
							),
						),
						array(
							'id'       => 'tiny_slug',
							'type'     => 'callback',
							'function' => array( $this, 'render_field_tinypress_link' ),
							'title'    => esc_html__( 'Short String *', 'tinypress' ),
							'subtitle' => esc_html__( 'Short string of this URL.', 'tinypress' ),
							'default'  => $this->tinypress_default_slug,
						),
						array(
							'id'         => 'link_status',
							'type'       => 'switcher',
							'title'      => esc_html__( 'Status', 'tinypress' ),
							'subtitle'   => esc_html__( 'Disable the link instantly.', 'tinypress' ),
							'label'      => esc_html__( 'After disabling the link will not active but the settings will be reserved.', 'tinypress' ),
							'text_on'    => esc_html__( 'Enable', 'tinypress' ),
							'text_off'   => esc_html__( 'Disable', 'tinypress' ),
							'default'    => true,
							'text_width' => 100,
						),
						array(
							'id'    => 'tiny_notes',
							'type'  => 'textarea',
							'title' => esc_html__( 'Notes', 'tinypress' ),
						),
					),
				)
			);

			// Redirection Settings section.
			WPDK_Settings::createSection( $this->tinypress_metabox_main,
				array(
					'title'  => esc_html__( 'Redirection', 'tinypress' ),
					'fields' => array(
						array(
							'id'          => 'redirection_method',
							'type'        => 'select',
							'title'       => esc_html__( 'Redirection Method', 'tinypress' ),
							'subtitle'    => esc_html__( 'Select redirection method', 'tinypress' ),
							'placeholder' => 'Select a method',
							'options'     => array(
								307 => esc_html__( '307 (Temporary)', 'tinypress' ),
								302 => esc_html__( '302 (Temporary)', 'tinypress' ),
								301 => esc_html__( '301 (Permanent)', 'tinypress' ),
							),
							'default'     => 302,
						),
						array(
							'id'       => 'redirection_sponsored',
							'type'     => 'switcher',
							'title'    => esc_html__( 'Sponsored', 'tinypress' ),
							'subtitle' => esc_html__( 'Add sponsored attribute.', 'tinypress' ),
							'label'    => esc_html__( 'Recommended for affiliate links.', 'tinypress' ),
						),
						array(
							'id'       => 'redirection_no_follow',
							'type'     => 'switcher',
							'title'    => esc_html__( 'No Follow', 'tinypress' ),
							'subtitle' => esc_html__( 'Add no follow attribute.', 'tinypress' ),
							'label'    => esc_html__( 'We recommended to use this.', 'tinypress' ),
							'default'  => true,
						),
						array(
							'id'           => 'redirection_parameter_forwarding',
							'type'         => 'switcher',
							'title'        => esc_html__( 'Parameter Forwarding', 'tinypress' ),
							'label'        => esc_html__( 'All the parameters will pass to the target link.', 'tinypress' ),
						),
					),
				)
			);

			// Security Settings section.
			WPDK_Settings::createSection( $this->tinypress_metabox_main,
				array(
					'title'  => esc_html__( 'Security', 'tinypress' ),
					'fields' => array(
						array(
							'id'           => 'password_protection',
							'type'         => 'switcher',
							'title'        => esc_html__( 'Password Protection', 'tinypress' ),
							'subtitle'     => esc_html__( 'Secure your link.', 'tinypress' ),
							'label'        => esc_html__( 'Users must enter the password to redirect to the target link.', 'tinypress' ),
						),
						array(
							'id'           => 'link_password',
							'type'         => 'text',
							'title'        => esc_html__( 'Password', 'tinypress' ),
							'subtitle'     => esc_html__( 'Share this with users.', 'tinypress' ),
							'desc'         => esc_html__( 'Passwords are case sensitive.', 'tinypress' ),
							'placeholder'  => esc_html__( '********', 'tinypress' ),
							'attributes'   => array(
								'minlength' => 6,
							),
							'dependency'   => array( 'password_protection', '==', '1' ),
						),
						array(
							'id'           => 'enable_expiration',
							'type'         => 'switcher',
							'title'        => esc_html__( 'Enable Expiration', 'tinypress' ),
							'subtitle'     => esc_html__( 'Expire automatically.', 'tinypress' ),
							'label'        => esc_html__( 'Users will not able to redirect to the target URL once expire.', 'tinypress' ),
						),
						array(
							'id'           => 'expiration_date',
							'type'         => 'datetime',
							'title'        => esc_html__( 'Expiration Date', 'tinypress' ),
							'subtitle'     => esc_html__( 'It will automatically expire.', 'tinypress' ),
							'settings'     => array(
								'dateFormat'      => 'd-m-Y',
								'allowInput'      => false,
								'minuteIncrement' => 1,
								'minDate'         => 'today',
							),
							'dependency'   => array( 'enable_expiration', '==', '1' ),
						),
					),
				)
			);

			// Analytics section.
			WPDK_Settings::createSection( $this->tinypress_metabox_main,
				array(
					'id'       => 'analytics',
					'external' => true,
					'title'    => esc_html__( 'Analytics', 'tinypress' ),
				)
			);
		}
	}
}