<?php
/**
 * Class Hooks
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Hooks' ) ) {
	/**
	 * Class TINYPRESS_Hooks
	 */
	class TINYPRESS_Hooks {

		protected static $_instance = null;


		/**
		 * TINYPRESS_Hooks constructor.
		 */
		function __construct() {

			add_action( 'init', array( $this, 'register_everything' ) );
			add_action( 'admin_menu', array( $this, 'links_log' ) );
			add_filter( 'post_updated_messages', array( $this, 'change_url_update_message' ) );


			add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 999 );
			add_action( 'admin_footer', array( $this, 'render_admin_modal' ) );
			add_action( 'wp_ajax_tinypress_popup_create_url', array( $this, 'tinypress_popup_create_url' ) );
		}

		public function tinypress_popup_create_url() {
			$_url_data = isset( $_POST['url_data'] ) ? wp_unslash( $_POST['url_data'] ) : null;

			parse_str( $_url_data, $url_data );

			$long_url  = Utils::get_args_option( 'long_url', $url_data );
			$tiny_slug = Utils::get_args_option( 'tiny_slug', $url_data, tinypress_create_url_slug() );

			if ( empty( $long_url ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid or empty URL', 'tinypress' ) ) );
			}

			$url_args = array(
				'target_url' => $long_url,
				'tiny_slug'  => $tiny_slug,
			);
			$tiny_url = tinypress_create_shorten_url( $url_args );

			if ( is_wp_error( $tiny_url ) ) {
				wp_send_json_error( array( 'message' => $tiny_url->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'tiny_url' => $tiny_url,
					'long_url' => $long_url,
					'message'  => esc_html__( 'Tinyurl created successfully.', 'tinypress' )
				)
			);
		}

		public function render_admin_modal() {

			include_once TINYPRESS_PLUGIN_DIR . 'templates/admin-modal-new-link.php';
		}

		public function add_admin_bar_menu( WP_Admin_Bar $admin_bar ) {

			$hide_modal_opener = (bool) Utils::get_option( 'tinypress_hide_modal_opener' );

			if ( $hide_modal_opener !== true ) {
				$admin_bar->add_menu( array(
					'id'    => 'tinypress',
					'title' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"> <path d="M9.64349 9.12137C9.64349 9.12137 7.84244 8.66818 6.03697 9.21693C2.02779 10.4355 1.95443 17.4417 5.92911 18.7681V18.7681C9.28878 19.8892 11.5866 17.7722 12.8055 15.2625C14.0243 12.7528 15.5374 10.5539 16.0934 10.1453L14.4339 9.94857C14.4339 9.94857 12.103 13.2756 11.5222 14.5917C10.9413 15.9079 8.97836 19.0633 5.40647 16.8465C5.40647 16.8465 2.90531 15.325 4.44316 12.2186C4.44316 12.2186 5.35126 10.0031 8.97274 10.4048L9.64349 9.12137Z" fill="white"/> <path d="M14.1143 14.9275C14.1143 14.9275 15.9154 15.3807 17.7208 14.8319C21.73 13.6133 21.8034 6.60711 17.8287 5.28077V5.28077C14.469 4.15965 12.1712 6.27658 10.9523 8.78628C9.7335 11.296 8.22045 13.4949 7.66442 13.9035L9.32392 14.1003C9.32392 14.1003 11.6548 10.7733 12.2356 9.4571C12.8165 8.14092 14.7794 4.98553 18.3513 7.2023C18.3513 7.2023 20.8525 8.72379 19.3147 11.8303C19.3147 11.8303 18.4066 14.0457 14.7851 13.644L14.1143 14.9275Z" fill="white"/> <path d="M16.2361 13.3248C16.2361 13.3248 18.5333 13.4481 19.2698 10.8646C20.0064 8.28122 17.6278 6.70253 16.2953 6.72667C14.9627 6.7508 14.4067 7.15944 13.6626 7.80513C13.6626 7.80513 16.9897 5.9744 18.5346 8.96757C18.5346 8.96757 19.9561 12.3365 16.2361 13.3248Z" fill="white" fill-opacity="0.8"/> <path d="M10.0787 16.1984C10.0787 16.1984 8.69657 18.0376 6.24435 16.9412C3.79212 15.8449 4.11178 13.0079 4.98682 12.0025C5.86186 10.9971 6.53228 10.8338 7.50517 10.6787C7.50517 10.6787 3.96424 12.051 5.26385 15.1586C5.26385 15.1586 6.93063 18.4131 10.0787 16.1984Z" fill="white" fill-opacity="0.8"/> <ellipse cx="1.75924" cy="1.75936" rx="1.75924" ry="1.75936" transform="matrix(0.90629 -0.422657 0.422579 0.906326 15.9453 3.79199)" fill="white"/> <ellipse cx="1.75924" cy="1.75936" rx="1.75924" ry="1.75936" transform="matrix(0.906294 -0.422649 0.422588 0.906322 3.375 18.4648)" fill="white"/> </svg>' .
					           '<span class="tinypress-icon-text">' . esc_html__( 'Shorten', 'tinypress' ) . '</span>',
					'href'  => '#',
					'meta'  => array(
						'class' => 'tinypress-admin-bar-icon',
					),
				) );
			}
		}

		/**
		 * Update post update message
		 *
		 * @param $messages
		 *
		 * @return mixed
		 */
		function change_url_update_message( $messages ) {

			$post_messages = Utils::get_args_option( 'post', $messages );
			$post_messages = array_map( function ( $message ) {
				return str_replace( 'Post', 'Shortlinks', $message );
			}, $post_messages );

			$messages['post'] = $post_messages;

			return $messages;
		}


		/**
		 * Register Post Types
		 */
		function register_everything() {

			global $tinypress_wpdk;

			$tinypress_wpdk->utils()->register_post_type( 'tinypress_link', array(
				'singular'            => esc_html__( 'Link', 'tinypress' ),
				'plural'              => esc_html__( 'All Links', 'tinypress' ),
				'labels'              => array(
					'menu_name' => esc_html__( 'Shortlinks', 'tinypress' ),
				),
				'menu_icon'           => 'dashicons-admin-links',
				'supports'            => array( '' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
			) );

			$tinypress_wpdk->utils()->register_taxonomy( 'tinypress_link_cat', 'tinypress_link',
				apply_filters( 'TINYPRESS/Filters/link_cat_args',
					array(
						'singular'     => esc_html__( 'Category', 'tinypress' ),
						'plural'       => esc_html__( 'Categories', 'tinypress' ),
						'hierarchical' => true,
					)
				)
			);

//			$tinypress_wpdk->utils()->register_taxonomy( 'tinypress_link_tags', 'tinypress_link',
//				apply_filters( 'TINYPRESS/Filters/link_tags_args',
//					array(
//						'singular' => esc_html__( 'Tag', 'tinypress' ),
//						'plural'   => esc_html__( 'Tags', 'tinypress' ),
//					)
//				)
//			);
		}


		/**
		 * Adds a submenu page under a custom post type parent.
		 */
		function links_log() {
			add_submenu_page( 'edit.php?post_type=tinypress_link',
				esc_html__( 'Logs', 'tinypress' ), esc_html__( 'Logs', 'tinypress' ), 'manage_options', 'tinypress-logs',
				array( $this, 'render_menu_logs' )
			);
		}


		/**
		 * Render logs menu
		 */
		function render_menu_logs() {

			if ( ! class_exists( 'WP_List_Table_Logs' ) ) {
				require_once 'class-table-logs.php';
			}

			$table_logs = new WP_List_Table_Logs();

			echo '<div class="wrap">';
			echo '<h2 class="report-table">' . esc_html__( 'All Logs', 'tinypress' ) . '</h2>';

			$table_logs->prepare_items();
			$table_logs->display();

			echo '</div>';
		}


		/**
		 * @return TINYPRESS_Hooks
		 */
		public static function instance() {

			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}