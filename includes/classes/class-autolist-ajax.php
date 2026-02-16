<?php
/**
 * AJAX handler for autolist settings
 * Handles real-time validation and auto-save
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TINYPRESS_Autolist_Ajax' ) ) {
	class TINYPRESS_Autolist_Ajax {

		protected static $_instance = null;

		public function __construct() {
			add_action( 'wp_ajax_tinypress_validate_post_type', array( $this, 'validate_post_type' ) );
			add_action( 'wp_ajax_tinypress_save_autolist_config', array( $this, 'save_autolist_config' ) );
			add_action( 'wp_ajax_tinypress_get_post_types', array( $this, 'get_post_types' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

		/**
		 * AJAX: Validate post type in real-time
		 */
		public function validate_post_type() {
			check_ajax_referer( 'tinypress_autolist_nonce', 'nonce' );
			
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied', 'tinypress' ) ) );
			}
			
			$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( trim( $_POST['post_type'] ) ) : '';
			$current_index = isset( $_POST['current_index'] ) ? intval( $_POST['current_index'] ) : -1;
			
			if ( empty( $post_type ) ) {
				wp_send_json_error( array( 'message' => __( 'Post type is required', 'tinypress' ) ) );
			}

			if ( ! post_type_exists( $post_type ) ) {
				wp_send_json_error( array( 
					'message' => sprintf( __( '"%s" is not a registered post type', 'tinypress' ), $post_type )
				) );
			}

			$post_type_obj = get_post_type_object( $post_type );
			if ( ! $post_type_obj || ! $post_type_obj->public ) {
				wp_send_json_error( array( 
					'message' => sprintf( __( '"%s" is not a public post type', 'tinypress' ), $post_type )
				) );
			}

			if ( in_array( $post_type, array( 'attachment', 'tinypress_link' ) ) ) {
				wp_send_json_error( array( 
					'message' => sprintf( __( '"%s" cannot be used for auto-listing', 'tinypress' ), $post_type )
				) );
			}
			
			$all_settings = get_option( 'tinypress_settings', array() );
			$existing_config = isset( $all_settings['tinypress_autolist_post_types'] ) ? $all_settings['tinypress_autolist_post_types'] : array();
			foreach ( $existing_config as $index => $config ) {
				if ( $index !== $current_index && isset( $config['post_type'] ) && $config['post_type'] === $post_type ) {
					wp_send_json_error( array( 
						'message' => sprintf( __( '"%s" is already configured', 'tinypress' ), $post_type )
					) );
				}
			}
			
			wp_send_json_success( array( 
				'message' => sprintf( __( 'Valid: %s', 'tinypress' ), $post_type_obj->labels->singular_name ),
				'label' => $post_type_obj->labels->singular_name
			) );
		}
		
		/**
		 * AJAX: Save autolist configuration
		 */
		public function save_autolist_config() {
			try {
				check_ajax_referer( 'tinypress_autolist_nonce', 'nonce' );
				
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( array( 'message' => __( 'Permission denied - you must be an administrator', 'tinypress' ) ) );
				}
				
				$config = isset( $_POST['config'] ) ? $_POST['config'] : array();
				$enabled = isset( $_POST['enabled'] ) ? sanitize_text_field( $_POST['enabled'] ) : '0';
				
				if ( ! is_array( $config ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid configuration format - expected array', 'tinypress' ) ) );
				}
				
				// Sanitize and validate
				$sanitized = $this->sanitize_config( $config );
				
				// Save to database - get existing settings first
				$settings = get_option( 'tinypress_settings', array() );
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}

				$settings['tinypress_autolist_enabled'] = $enabled;
				$settings['tinypress_autolist_post_types'] = $sanitized;
				
				$update_result = update_option( 'tinypress_settings', $settings );
				
				if ( $update_result === false ) {
					$current = get_option( 'tinypress_settings', array() );
					if ( isset( $current['tinypress_autolist_post_types'] ) && $current['tinypress_autolist_post_types'] === $sanitized ) {
						wp_send_json_success( array( 
							'message' => __( 'Configuration unchanged (already saved)', 'tinypress' ),
							'config' => $sanitized
						) );
					} else {
						wp_send_json_error( array( 'message' => __( 'Failed to save configuration to database', 'tinypress' ) ) );
					}
				}
				
				wp_send_json_success( array( 
					'message' => __( 'Configuration saved successfully', 'tinypress' ),
					'config' => $sanitized,
					'count' => count( $sanitized )
				) );
				
			} catch ( Exception $e ) {
				wp_send_json_error( array( 
					'message' => sprintf( __( 'Error: %s', 'tinypress' ), $e->getMessage() ),
					'trace' => $e->getTraceAsString()
				) );
			}
		}
		
		/**
		 * Sanitize configuration array
		 */
		private function sanitize_config( $config ) {
			$all_post_types = get_post_types( array( 'public' => true ), 'names' );
			$valid_post_types = array_diff( $all_post_types, array( 'attachment', 'tinypress_link' ) );
			
			$seen = array();
			$sanitized = array();
			
			foreach ( $config as $item ) {
				if ( ! isset( $item['post_type'] ) || empty( $item['post_type'] ) ) {
					continue;
				}
				
				$post_type = sanitize_text_field( trim( $item['post_type'] ) );
				
				if ( empty( $post_type ) || ! in_array( $post_type, $valid_post_types ) ) {
					continue;
				}
				
				if ( isset( $seen[ $post_type ] ) ) {
					continue;
				}
				
				$behavior = isset( $item['behavior'] ) ? sanitize_text_field( $item['behavior'] ) : 'never';
				if ( ! in_array( $behavior, array( 'never', 'on_first_use', 'on_publish' ) ) ) {
					$behavior = 'never';
				}
				
				$seen[ $post_type ] = true;
				$sanitized[] = array(
					'post_type' => $post_type,
					'behavior' => $behavior
				);
			}
			
			return $sanitized;
		}
		
		/**
		 * AJAX: Get post types with pagination for lazy loading
		 */
		public function get_post_types() {
			check_ajax_referer( 'tinypress_autolist_nonce', 'nonce' );
			
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied', 'tinypress' ) ) );
			}
			
			$page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
			$per_page = 10;
			$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
			
			$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
			$post_types = array();
			
			foreach ( $all_post_types as $post_type ) {
				if ( in_array( $post_type->name, array( 'attachment', 'tinypress_link' ) ) ) {
					continue;
				}
				
				if ( ! empty( $search ) ) {
					if ( stripos( $post_type->name, $search ) === false && stripos( $post_type->label, $search ) === false ) {
						continue;
					}
				}
				
				$post_types[] = array(
					'id' => $post_type->name,
					'text' => $post_type->label . ' (' . $post_type->name . ')'
				);
			}
			
			usort( $post_types, function( $a, $b ) {
				return strcmp( $a['text'], $b['text'] );
			});
			
			// Paginate results
			$total = count( $post_types );
			$offset = ( $page - 1 ) * $per_page;
			$paginated = array_slice( $post_types, $offset, $per_page );
			
			wp_send_json_success( array(
				'results' => $paginated,
				'pagination' => array(
					'more' => ( $offset + $per_page ) < $total
				)
			) );
		}
		
		/**
		 * Enqueue scripts for AJAX functionality
		 */
		public function enqueue_scripts( $hook ) {
			if ( ! is_admin() ) {
				return;
			}
			
			if ( $hook !== 'tinypress_link_page_settings' ) {
				return;
			}
			
			wp_register_script(
				'tinypress-admin-select2',
				TINYPRESS_PLUGIN_URL . 'assets/lib/select2/js/select2.full.min.js',
				array( 'jquery' ),
				TINYPRESS_PLUGIN_VERSION
			);
			
			wp_register_style(
				'tinypress-admin-select2',
				TINYPRESS_PLUGIN_URL . 'assets/lib/select2/css/select2.min.css',
				array(),
				TINYPRESS_PLUGIN_VERSION
			);
			
			wp_enqueue_script( 'tinypress-admin-select2' );
			wp_enqueue_style( 'tinypress-admin-select2' );
			
			wp_register_script(
				'tinypress-autolist-ajax',
				TINYPRESS_PLUGIN_URL . 'assets/admin/js/autolist-ajax.js',
				array( 'jquery', 'jquery-ui-sortable', 'tinypress-admin-select2' ),
				TINYPRESS_PLUGIN_VERSION,
				true
			);
			
			wp_enqueue_script( 'tinypress-autolist-ajax' );
			
			wp_localize_script( 'tinypress-autolist-ajax', 'tinypressAutolist', array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'tinypress_autolist_nonce' ),
				'i18n' => array(
					'validating' => __( 'Validating...', 'tinypress' ),
					'saving' => __( 'Saving...', 'tinypress' ),
					'saved' => __( 'Saved!', 'tinypress' ),
					'error' => __( 'Error', 'tinypress' ),
					'confirmDelete' => __( 'Are you sure you want to remove this post type?', 'tinypress' ),
					'selectPostType' => __( 'Select a post type', 'tinypress' ),
					'never' => __( 'Never', 'tinypress' ),
					'onFirstUse' => __( 'On First Use', 'tinypress' ),
					'onPublish' => __( 'On Publish', 'tinypress' )
				)
			) );
		}

		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}
	}
}
