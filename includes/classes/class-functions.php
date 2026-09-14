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
				AND p.post_status = 'publish'
				ORDER BY p.ID DESC
				LIMIT 1", $slug));
            // If no published tinypress_link is found, look for another post type with this slug.
            if (empty($link_id)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table fallback excludes unpublished shortlink records.
                $link_id = (int) $wpdb->get_var($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = 'tiny_slug'
                    AND pm.meta_value = %s
                    AND (p.post_type != 'tinypress_link' OR p.post_status = 'publish')
                    ORDER BY pm.post_id DESC
                    LIMIT 1", $slug));
            }

            return $link_id;
        }

        /**
         * Find an imported shortlink by its original public path.
         *
         * @param string $path Original source-plugin request path.
         * @return int
         */
        public function legacy_slug_to_post_id($path)
        {
            static $resolved_paths = array();

            $path = trim((string) $path, '/');

            if ('' === $path) {
                return 0;
            }

            if (array_key_exists($path, $resolved_paths)) {
                return $resolved_paths[$path];
            }

            if (! $this->has_legacy_migration_paths()) {
                $resolved_paths[$path] = 0;
                return 0;
            }

            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cross-table lookup for a migrated legacy path.
            $resolved_paths[$path] = (int) $wpdb->get_var($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_tinypress_migration_legacy_path'
                AND pm.meta_value = %s
                AND p.post_type = 'tinypress_link'
                AND p.post_status = 'publish'
                ORDER BY p.ID DESC
                LIMIT 1", $path));

            return $resolved_paths[$path];
        }

        /**
         * Check whether any imported links retain their original public path.
         *
         * @return bool
         */
        private function has_legacy_migration_paths()
        {
            $has_legacy_paths = get_option('tinypress_has_legacy_migration_paths', null);

            if (null !== $has_legacy_paths) {
                return '1' === (string) $has_legacy_paths;
            }

            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time upgrade detection, persisted as an option.
            $has_legacy_paths = (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_tinypress_migration_legacy_path' LIMIT 1");
            update_option('tinypress_has_legacy_migration_paths', $has_legacy_paths ? '1' : '0');

            return $has_legacy_paths;
        }
    }
    // phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}
