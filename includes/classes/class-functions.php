<?php

/**
 * Class Functions
 */

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Functions')) {
    /**
     * Class TINYPRESS_Functions
     * 
     * Note: This class uses WordPress naming conventions instead of strict PSR-1/PSR-2 standards.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Functions
    {
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
        public function __construct()
        {
            self::$connect_url = TINYPRESS_SERVER . 'tiny-connect/?s_url=' . site_url();
        }

        public static function get_text_hint()
        {
            if (is_null(self::$text_hint)) {
                self::$text_hint = esc_html__('Click to copy', 'tinypress');
            }
            return self::$text_hint;
        }

        public static function get_text_copied()
        {
            if (is_null(self::$text_copied)) {
                self::$text_copied = esc_html__('Copied', 'tinypress');
            }
            return self::$text_copied;
        }

        public static function is_license_active()
        {
            return apply_filters('tinypress_filters_is_pro', class_exists('TINYPRESS_PRO_Main'));
        }

        /**
         * @param $slug
         *
         * @return int
         */
        public function tiny_slug_to_post_id($slug)
        {

            if (empty($slug)) {
                return 0;
            }

            global $wpdb;
            // First try to find a tinypress_link post with this slug (for auto-list links)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table join lookup; not cacheable via standard WP functions
            $link_id = (int) $wpdb->get_var($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = 'tiny_slug' 
				AND pm.meta_value = %s 
				AND p.post_type = 'tinypress_link'
				LIMIT 1", $slug));
            // If no tinypress_link found, look for any post with this slug
            if (empty($link_id)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback lookup by meta_key; not cacheable via standard WP functions
                $link_id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} 
					WHERE meta_key = 'tiny_slug' 
					AND meta_value = %s
					LIMIT 1", $slug));
            }

            return $link_id;
        }
    }
    // phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}
