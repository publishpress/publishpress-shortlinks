<?php
/*
	Plugin Name: PublishPress Shortlinks - Shorten and Track URLs
	Plugin URI:  https://publishpress.com/shortlinks/
	Description: No more long URL, Shorten and track it with PublishPress Shortlinks.
	Version: 1.2.5
	Text Domain: tinypress
	Author: PublishPress
	Author URI: https://publishpress.com/
	License: GPLv2 or later
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

global $wpdb;
defined( 'ABSPATH' ) || exit;

defined( 'TINYPRESS_PLUGIN_URL' ) || define( 'TINYPRESS_PLUGIN_URL', WP_PLUGIN_URL . '/' . plugin_basename( dirname( __FILE__ ) ) . '/' );
defined( 'TINYPRESS_PLUGIN_DIR' ) || define( 'TINYPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'TINYPRESS_PLUGIN_VERSION' ) || define( 'TINYPRESS_PLUGIN_VERSION', '1.2.5' );
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

			register_activation_hook( __FILE__, array( $this, 'flush_rewrite_rules' ) );
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
		 * Load Text Domain
		 */
		function load_text_domain() {
			load_plugin_textdomain( 'tinypress', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );
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

			new TINYPRESS_Hooks();
			new TINYPRESS_Column_link();
			new TINYPRESS_Settings();
			new TINYPRESS_Redirection();
			
			// Initialize metaboxes after translations are loaded
			add_action( 'init', function() {
				new TINYPRESS_Meta_boxes();
			}, 1 );
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
