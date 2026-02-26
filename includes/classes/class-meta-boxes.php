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
					add_action( 'add_meta_boxes_' . $post_type, array( $this, 'add_shortlinks_metabox' ) );
					add_action( 'save_post_' . $post_type, array( $this, 'save_native_shortlinks_metabox' ), 10, 2 );
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
		 * Add shortlinks metabox to post edit screen
		 *
		 * @return void
		 */
		function add_shortlinks_metabox() {
			global $post;
			
			if ( ! $post ) {
				return;
			}
			
			add_meta_box(
				'tinypress_shortlinks_' . $post->post_type,
				esc_html__( 'Shortlinks', 'tinypress' ),
				array( $this, 'render_native_shortlinks_metabox' ),
				$post->post_type,
				'side',
				'high'
			);
		}


		/**
		 * Render native shortlinks metabox content
		 *
		 * @param $post
		 * @return void
		 */
		function render_native_shortlinks_metabox( $post ) {
			wp_nonce_field( 'tinypress_shortlinks_nonce', 'tinypress_shortlinks_nonce_' . $post->post_type );
			
			$args = array(
				'default' => $this->tinypress_default_slug,
			);
			
			// Hook for Pro to add content before shortlink field
			do_action( 'tinypress_metabox_before_shortlink_field', $post );
			
			echo tinypress_get_tiny_slug_copier( $post->ID, true, $args );
			
			// Hook for Pro to add content after shortlink field
			do_action( 'tinypress_metabox_after_shortlink_field', $post );
		}
		
		/**
		 * Save native shortlinks metabox data
		 *
		 * @param $post_id
		 * @param $post
		 * @return void
		 */
		function save_native_shortlinks_metabox( $post_id, $post ) {
			if ( ! isset( $_POST['tinypress_shortlinks_nonce_' . $post->post_type] ) || 
			     ! wp_verify_nonce( $_POST['tinypress_shortlinks_nonce_' . $post->post_type], 'tinypress_shortlinks_nonce' ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$meta_key = 'tinypress_meta_side_' . $post->post_type;
			if ( isset( $_POST[ $meta_key ]['tiny_slug'] ) ) {
				$tiny_slug = sanitize_text_field( $_POST[ $meta_key ]['tiny_slug'] );
				
				// Save directly as 'tiny_slug' meta key for compatibility with the rest of the plugin
				update_post_meta( $post_id, 'tiny_slug', $tiny_slug );
				
				// Also save in the nested format for backward compatibility with WPDK
				$meta_data = get_post_meta( $post_id, $meta_key, true );
				if ( ! is_array( $meta_data ) ) {
					$meta_data = array();
				}
				$meta_data['tiny_slug'] = $tiny_slug;
				update_post_meta( $post_id, $meta_key, $meta_data );
			}
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
					'title'     => esc_html__( 'PublishPress Shortlinks', 'tinypress' ),
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
							'subtitle'   => esc_html__( 'Disable the shortlink instantly.', 'tinypress' ),
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
                            'subtitle' => esc_html__( 'Mark links as sponsored content.', 'tinypress' ),
                            'label'    => esc_html__( 'Adds rel="sponsored" attribute. Recommended for affiliate links and paid promotions.', 'tinypress' ),
						),
						array(
							'id'       => 'redirection_no_follow',
							'type'     => 'switcher',
							'title'    => esc_html__( 'NoFollow', 'tinypress' ),
                            'subtitle' => esc_html__( 'Prevent search engines from following this link.', 'tinypress' ),
                            'label'    => esc_html__( 'Adds rel="nofollow" attribute. Recommended for external links and untrusted sources.', 'tinypress' ),
							'default'  => true,
						),
						array(
							'id'           => 'redirection_parameter_forwarding',
							'type'         => 'switcher',
							'title'        => esc_html__( 'Parameter Forwarding', 'tinypress' ),
                            'subtitle'     => esc_html__( 'Pass URL parameters to the target link.', 'tinypress' ),
                            'label'        => esc_html__( 'Any parameters added to the short URL (e.g., ?utm_source=email) will be forwarded to the target URL.', 'tinypress' ),
						),
					),
				)
			);

			// Security Settings section.
			$security_fields = array(
				array(
					'id'           => 'password_protection',
					'type'         => 'switcher',
					'title'        => esc_html__( 'Password Protection', 'tinypress' ),
					'subtitle'     => esc_html__( 'Secure your shortlink.', 'tinypress' ),
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
			);

			$security_fields = apply_filters( 'tinypress_security_metabox_fields', $security_fields );

			WPDK_Settings::createSection( $this->tinypress_metabox_main,
				array(
					'title'  => esc_html__( 'Security', 'tinypress' ),
					'fields' => $security_fields,
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