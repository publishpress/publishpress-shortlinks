<?php

/**
 * PublishPress Shortlinks - target URL metadata suggestions.
 *
 * @package publishpress-shortlinks
 */

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_URL_Metadata')) {
    /**
     * Fetch a target URL title and description for the shortlink editor.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    class TINYPRESS_URL_Metadata
    {
        public static $instance;

        public function __construct()
        {
            add_action('wp_ajax_tinypress_fetch_url_metadata', array($this, 'ajax_fetch_url_metadata'));
        }

        public static function get_instance()
        {
            if (! isset(self::$instance)) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function ajax_fetch_url_metadata()
        {
            check_ajax_referer('tinypress_fetch_url_metadata', 'nonce');

            if (! current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => esc_html__('Unauthorized.', 'tinypress')));
            }

            $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
            $url = function_exists('tinypress_validate_target_url') ? tinypress_validate_target_url($url) : esc_url_raw($url);

            if (is_wp_error($url)) {
                wp_send_json_error(array('message' => $url->get_error_message()));
            }

            $response = wp_safe_remote_get($url, array(
                'timeout'     => 3,
                'redirection' => 3,
                'limit_response_size' => 131072,
                'user-agent'  => 'PublishPress Shortlinks/' . TINYPRESS_PLUGIN_VERSION . '; ' . home_url('/'),
            ));

            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => esc_html__('Could not fetch target URL details.', 'tinypress')));
            }

            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if ($content_type && false === stripos($content_type, 'html')) {
                wp_send_json_success(array('title' => '', 'description' => ''));
            }

            $html = (string) wp_remote_retrieve_body($response);
            if ('' === trim($html)) {
                wp_send_json_success(array('title' => '', 'description' => ''));
            }

            wp_send_json_success(array(
                'title'       => $this->extract_title($html),
                'description' => $this->extract_description($html),
            ));
        }

        private function extract_title($html)
        {
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                return $this->clean_metadata_text($matches[1]);
            }

            return '';
        }

        private function extract_description($html)
        {
            if (preg_match('/<meta[^>]+(?:name|property)=["\'](?:description|og:description|twitter:description)["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $matches)) {
                return $this->clean_metadata_text($matches[1]);
            }

            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\'](?:description|og:description|twitter:description)["\']/is', $html, $matches)) {
                return $this->clean_metadata_text($matches[1]);
            }

            return '';
        }

        private function clean_metadata_text($text)
        {
            $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, get_bloginfo('charset'));
            $text = preg_replace('/\s+/', ' ', $text);

            return trim(sanitize_text_field($text));
        }
    }
}
