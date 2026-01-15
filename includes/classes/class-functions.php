<?php
/**
 * Class Functions
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Functions' ) ) {
	class TINYPRESS_Functions {

		public static $text_hint = null;

		public static $text_copied = null;


		/**
		 * @var TINYPRESS_Meta_boxes
		 */
		public $tinypress_metaboxes = null;

		/**
		 * @var TINYPRESS_Column_link
		 */
		public $tinypress_columns = null;

		public static $connect_url = null;

		/**
		 * TINYPRESS_Functions constructor.
		 */
		function __construct() {
			self::$connect_url = TINYPRESS_SERVER . 'tiny-connect/?s_url=' . site_url();
		}

		public static function get_text_hint() {
			if ( is_null( self::$text_hint ) ) {
				self::$text_hint = esc_html__( 'Click to Copy.', 'tinypress' );
			}
			return self::$text_hint;
		}

		public static function get_text_copied() {
			if ( is_null( self::$text_copied ) ) {
				self::$text_copied = esc_html__( 'Copied.', 'tinypress' );
			}
			return self::$text_copied;
		}

		public static function is_license_active() {
			return apply_filters( 'tinypress_filters_is_pro', class_exists( 'TINYPRESS_PRO_Main' ) );
		}

		/**
		 * @param $slug
		 *
		 * @return int
		 */
		function tiny_slug_to_post_id( $slug ) {

			if ( empty( $slug ) ) {
				return 0;
			}

			global $wpdb;

			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value like %s", $slug ) );
		}
	}
}