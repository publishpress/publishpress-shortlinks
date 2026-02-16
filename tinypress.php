<?php
/**
 * Plugin Name: PublishPress Shortlinks
 * Plugin URI:  https://publishpress.com/shortlinks/
 * Description: Create custom links for your posts. These links are brandable, trackable, and can have custom view permissions.
 * Version: 1.3.0
 * Text Domain: tinypress
 * Author: PublishPress
 * Author URI: https://publishpress.com/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

global $wpdb;
defined( 'ABSPATH' ) || exit;

if (!defined('TINYPRESS_PLUGIN_VERSION')) {
define('TINYPRESS_PLUGIN_VERSION', '1.3.0');
}

defined( 'TINYPRESS_PLUGIN_URL' ) || define( 'TINYPRESS_PLUGIN_URL', WP_PLUGIN_URL . '/' . plugin_basename( dirname( __FILE__ ) ) . '/' );
defined( 'TINYPRESS_PLUGIN_DIR' ) || define( 'TINYPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'TINYPRESS_PLUGIN_FILE' ) || define( 'TINYPRESS_PLUGIN_FILE', plugin_basename( __FILE__ ) );
defined( 'TINYPRESS_TABLE_REPORTS' ) || define( 'TINYPRESS_TABLE_REPORTS', sprintf( '%stinypress_reports', $wpdb->prefix ) );
defined( 'TINYPRESS_SERVER' ) || define( 'TINYPRESS_SERVER', esc_url_raw( 'https://endearing-lobster-8e2abe.instawp.xyz/' ) );
defined( 'TINYPRESS_LINK_PRO' ) || define( 'TINYPRESS_LINK_PRO', esc_url_raw( 'https://pluginbazar.com/products/tinypress/?ref=' . site_url() ) );
defined( 'TINYPRESS_LINK_DOC' ) || define( 'TINYPRESS_LINK_DOC', esc_url_raw( 'https://docs.pluginbazar.com/plugin/tinypress/' ) );
defined( 'TINYPRESS_LINK_DOC' ) || define( 'TINYPRESS_LINK_DOC', esc_url_raw( 'https://docs.pluginbazar.com/plugin/tinypress/' ) );
defined( 'TINYPRESS_LINK_SUPPORT' ) || define( 'TINYPRESS_LINK_SUPPORT', esc_url_raw( 'mailto:hello@tinypress.xyz' ) );

if ( ! class_exists( 'TINYPRESS_Main' ) ) {
	/**
	 * Class TINYPRESS_Main
	 */
	class TINYPRESS_Main {

		protected static $_instance = null;


		protected static $_script_version = null;


		/**
		 * TINYPRESS_Main constructor.
		 */
		function __construct() {

			self::$_script_version = defined( 'WP_DEBUG' ) && WP_DEBUG ? current_time( 'U' ) : TINYPRESS_PLUGIN_VERSION;

			$this->define_scripts();
			$this->define_classes_functions();

			add_action( 'init', array( $this, 'create_data_table' ), 5 );
			add_action( 'init', array( $this, 'load_text_domain' ), 0 );
			add_action( 'init', array( $this, 'initialize_default_settings' ), 10 );
			add_filter( 'admin_footer_text', array( $this, 'update_footer_admin' ) );
			add_filter( 'tinypress_show_footer', array( $this, 'filter_display_footer' ), 10 );

			register_activation_hook( __FILE__, array( $this, 'flush_rewrite_rules' ) );
			register_activation_hook( __FILE__, array( $this, 'set_default_settings' ) );
		}


		/**
		 * flush_rewrite_rules
		 *
		 * @return void
		 */
		function flush_rewrite_rules() {
			global $wp_rewrite;
			$wp_rewrite->flush_rules( true );
		}


		/**
		 * Create data table
		 *
		 * @return void
		 */
		function create_data_table() {

			if ( ! function_exists( 'maybe_create_table' ) ) {
				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			}

			$sql_create_table = "CREATE TABLE " . TINYPRESS_TABLE_REPORTS . " (
	            id int(50) NOT NULL AUTO_INCREMENT,
	            user_id varchar(50) NOT NULL,
	            post_id varchar(50) NOT NULL,
			    user_ip varchar(255) NOT NULL,
			    user_location varchar(1024) NOT NULL,
	            datetime  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	            PRIMARY KEY (id)
            );";

			maybe_create_table( TINYPRESS_TABLE_REPORTS, $sql_create_table );
		}


		/**
		 * Set default settings on plugin activation
		 *
		 * @return void
		 */
		function set_default_settings() {
			$settings = get_option( 'tinypress_settings', array() );
			
			if ( empty( $settings ) || ! isset( $settings['tinypress_autolist_enabled'] ) ) {
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}

				if ( ! isset( $settings['tinypress_autolist_enabled'] ) ) {
					$settings['tinypress_autolist_enabled'] = '1';
				}
				
				if ( ! isset( $settings['tinypress_autolist_post_types'] ) || empty( $settings['tinypress_autolist_post_types'] ) ) {
					$settings['tinypress_autolist_post_types'] = array(
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
				
				if ( ! isset( $settings['tinypress_allowed_post_statuses'] ) ) {
					$settings['tinypress_allowed_post_statuses'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
				}
				
				update_option( 'tinypress_settings', $settings );
			}
		}
		
		/**
		 * Initialize default settings on init (for upgrades from older versions)
		 *
		 * @return void
		 */
		function initialize_default_settings() {
			// Check if we need to initialize defaults (for sites upgrading from older versions)
			$settings = get_option( 'tinypress_settings', array() );
			
			// If settings exist but autolist settings are missing, initialize them
			if ( is_array( $settings ) && ! empty( $settings ) && ! isset( $settings['tinypress_autolist_enabled'] ) ) {
				$this->set_default_settings();
			}
		}


		/**
		 * Load Text Domain
		 */
		function load_text_domain() {
			$locale = determine_locale();
			if ( 'en_US' !== $locale ) {
				load_plugin_textdomain( 'tinypress', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );
			}
		}


		/**
		 * Include Classes and Functions
		 */
		function define_classes_functions() {
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-hooks.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-functions.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/functions.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-meta-boxes.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-columns-link.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-settings.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-redirection.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-autolist.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-autolist-ajax.php';
			require_once TINYPRESS_PLUGIN_DIR . 'includes/classes/class-reviews.php';

			new TINYPRESS_Hooks();
			new TINYPRESS_Settings();
			new TINYPRESS_Redirection();
			new TINYPRESS_AutoList();
			TINYPRESS_Autolist_Ajax::instance();
			TINYPRESS_Reviews::instance();
			
			// Initialize metaboxes early for proper registration
			add_action( 'init', function() {
				new TINYPRESS_Meta_boxes();
			}, 1 );
			
			// Initialize columns late to catch all registered post types
			add_action( 'init', function() {
				new TINYPRESS_Column_link();
			}, 999 );
		}


		/**
		 * Localize Scripts
		 *
		 * @return mixed|void
		 */
		function localize_scripts() {
			return apply_filters( 'tinypress/filters/localize_scripts', array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'copy_text' => esc_html__( 'Copied.', 'tinypress' ),
			) );
		}


		/**
		 * Load Admin Scripts
		 */
		function admin_scripts() {

			wp_enqueue_script( 'apexcharts', plugins_url( '/assets/admin/js/apexcharts.js', __FILE__ ), array( 'jquery' ), self::$_script_version );

			wp_enqueue_script( 'qrcode', plugins_url( '/assets/admin/js/qrcode.min.js', __FILE__ ), array( 'jquery' ), self::$_script_version );
			wp_enqueue_script( 'tinypress', plugins_url( '/assets/admin/js/scripts.js', __FILE__ ), array( 'jquery' ), self::$_script_version );
			wp_localize_script( 'tinypress', 'tinypress', $this->localize_scripts() );

			wp_enqueue_style( 'tinypress', TINYPRESS_PLUGIN_URL . 'assets/admin/css/style.css', self::$_script_version );
			wp_enqueue_style( 'tinypress-tool-tip', TINYPRESS_PLUGIN_URL . 'assets/hint.min.css' );
		}


		/**
		 * Load Scripts
		 */
		function define_scripts() {
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		}


		/**
		 * Update admin footer with PublishPress footer
		 *
		 * @param string $footer
		 * @return string
		 */
		public function update_footer_admin( $footer ) {
			if ( $this->should_display_footer() ) {
				$html = '<div class="pressshack-admin-wrapper">';
				$html .= $this->print_default_footer( false );

				// Add the wordpress footer
				$html .= $footer;

				if ( ! defined( 'TINYPRESS_FOOTER_DISPLAYED' ) ) {
					define( 'TINYPRESS_FOOTER_DISPLAYED', true );
				}

				return $html;
			}

			return $footer;
		}


		/**
		 * Check if footer should be displayed
		 *
		 * @return bool
		 */
		private function should_display_footer() {
			return apply_filters( 'tinypress_show_footer', false );
		}


		/**
		 * Echo or return the default footer
		 *
		 * @param bool $echo
		 * @return string
		 */
		public function print_default_footer( $echo = true ) {
			$html = '';
			
			$show_footer = apply_filters( 'tinypress_show_footer', true );

			if ( $show_footer ) {
				$context = array(
					'plugin_name' => __( 'PublishPress Shortlinks', 'tinypress' ),
					'plugin_slug' => 'tinypress',
					'plugin_url'  => TINYPRESS_PLUGIN_URL,
				);

				ob_start();
				include TINYPRESS_PLUGIN_DIR . 'templates/admin/footer.php';
				$html = ob_get_clean();
			}

			if ( ! $echo ) {
				return $html;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $html;

			return '';
		}


		/**
		 * Filter to determine which pages should display the footer
		 *
		 * @param bool $should_display
		 * @return bool
		 */
		public function filter_display_footer( $should_display = true ) {
			global $current_screen;

			if ( defined( 'TINYPRESS_FOOTER_DISPLAYED' ) ) {
				return false;
			}

			if ( $current_screen->base === 'tinypress_link_page_settings' ) {
				return true;
			}

			if ( $current_screen->base === 'tinypress_link_page_tinypress-logs' ) {
				return true;
			}

			if ( $current_screen->base === 'edit' && $current_screen->post_type === 'tinypress_link' ) {
				return true;
			}

			if ( $current_screen->base === 'edit-tags' && $current_screen->post_type === 'tinypress_link' ) {
				return true;
			}

			if ( ( $current_screen->base === 'post' || $current_screen->base === 'post-new' ) && $current_screen->post_type === 'tinypress_link' ) {
				return true;
			}

			return $should_display;
		}


		/**
		 * @return TINYPRESS_Main
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}

function pb_sdk_init_tinypress() {

	if ( ! function_exists( 'get_plugins' ) ) {
		include_once ABSPATH . '/wp-admin/includes/plugin.php';
	}

	if ( ! class_exists( 'WPDK\Client' ) ) {
		require_once( plugin_dir_path( __FILE__ ) . 'includes/wp-dev-kit/classes/class-client.php' );
	}

	global $tinypress_wpdk;

	$tinypress_wpdk = new WPDK\Client( esc_html( 'TinyPress - Shorten and Track your links' ), 'tinypress', 35, __FILE__ );

	do_action( 'pb_sdk_init_tinypress', $tinypress_wpdk );
}

/**
 * @global \WPDK\Client $tinypress_wpdk
 */
global $tinypress_wpdk;

pb_sdk_init_tinypress();

TINYPRESS_Main::instance();
