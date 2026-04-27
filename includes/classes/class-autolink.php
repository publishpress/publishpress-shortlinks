<?php

use WPDK\Utils;

defined('ABSPATH') || exit;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
if (! class_exists('TINYPRESS_AutoLink')) {
    /**
     * TINYPRESS_AutoLink Class
     * 
     * @since 1.6.0
     */
    class TINYPRESS_AutoLink
    {
        private const CACHE_KEY = 'tinypress_autolink_rules';

        private const CACHE_EXPIRATION = HOUR_IN_SECONDS;

        private const CACHE_GROUP = 'tinypress_autolink';

        private $global_target = 'same_tab';

        private $global_color = '';

        /**
         * Class Constructor
         */
        public function __construct()
        {
            if (is_admin()) {
                $this->init_admin_hooks();
                return;
            }

            $this->tinypress_load_autolink_global_settings();
            $this->init_frontend_hooks();
        }

        private function tinypress_load_autolink_global_settings()
        {
            $settings = get_option('tinypress_settings', array());
            if (! is_array($settings)) {
                $settings = array();
            }

            $target = isset($settings['tinypress_autolink_target']) ? (string) $settings['tinypress_autolink_target'] : 'same_tab';
            $this->global_target = in_array($target, array('same_tab', 'new_tab'), true) ? $target : 'same_tab';

            $color = isset($settings['tinypress_autolink_color']) ? (string) $settings['tinypress_autolink_color'] : '';
            $this->global_color = (string) sanitize_hex_color($color);
        }

        private function get_all_autolink_post_types()
        {
            $post_types = get_post_types(array('public' => true, 'show_ui' => true), 'names');
            if (! is_array($post_types)) {
                return array('post', 'page');
            }

            $post_types = array_values(array_diff($post_types, array('attachment', 'tinypress_link')));
            if (empty($post_types)) {
                return array('post', 'page');
            }

            return array_map('sanitize_key', $post_types);
        }

        /**
         * Initialize admin hooks for cache invalidation
         * 
         * @return void
         */
        private function init_admin_hooks()
        {
            add_action('save_post_tinypress_link', array($this, 'invalidate_cache'), 10, 1);
            add_action('delete_post', array($this, 'invalidate_cache_on_delete'), 10, 1);
            add_action('trashed_post', array($this, 'invalidate_cache_on_delete'), 10, 1);
            add_action('updated_post_meta', array($this, 'invalidate_cache_on_meta_update'), 10, 4);
        }

        /**
         * Initialize frontend hooks for content filtering
         * 
         * @return void
         */
        private function init_frontend_hooks()
        {
            if (! $this->is_autolink_enabled()) {
                return;
            }

            add_filter('the_content', array($this, 'filter_content'), 8);
            add_filter('the_excerpt', array($this, 'filter_content'), 8);

            if (defined('ELEMENTOR_VERSION') || class_exists('\\Elementor\\Plugin')) {
                add_filter('elementor/frontend/the_content', array($this, 'filter_content'), 8);
                add_filter('elementor/frontend/builder_content', array($this, 'filter_content'), 8);
            }
        }

        private function is_autolink_enabled()
        {
            $settings = get_option('tinypress_settings', array());
            if (! is_array($settings)) {
                $settings = array();
            }

            $enabled = isset($settings['tinypress_autolink_enabled']) ? (string) $settings['tinypress_autolink_enabled'] : '1';
            $is_enabled = ('1' === $enabled);
            
            return apply_filters('tinypress_autolink_is_enabled', $is_enabled);
        }

        public function invalidate_cache($post_id = 0)
        {
            if ($post_id && get_post_type($post_id) !== 'tinypress_link') {
                return;
            }

            delete_transient(self::CACHE_KEY);
            wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
            
            do_action('tinypress_autolink_cache_invalidated', $post_id);
        }

        public function invalidate_cache_on_delete($post_id)
        {
            if (get_post_type($post_id) === 'tinypress_link') {
                $this->invalidate_cache($post_id);
            }
        }

        public function invalidate_cache_on_meta_update($meta_id, $post_id, $meta_key, $meta_value)
        {
            if (get_post_type($post_id) !== 'tinypress_link') {
                return;
            }

            $autolink_keys = array('autolink_keywords', 'autolink_post_types', 'link_status');
            
            if (in_array($meta_key, $autolink_keys, true)) {
                $this->invalidate_cache($post_id);
            }
        }

        /**
         * Filter content to add autolinks
         * 
         * @param string $content Post content
         * @return string Filtered content with autolinks
         */
        public function filter_content($content)
        {
            if (! $this->should_process_content($content)) {
                return $content;
            }

            $post = get_post();
            if (! $post) {
                return $content;
            }

            $rules = $this->get_rules_for_post($post);
            if (empty($rules)) {
                return $content;
            }

            $content = apply_filters('tinypress_autolink_before_process', $content, $post, $rules);
            $processed = $this->auto_link_html($content, $rules);
            
            return apply_filters('tinypress_autolink_after_process', $processed, $content, $post, $rules);
        }

        /**
         * Determine if content should be processed for autolinking
         * 
         * @param mixed $content Content to check
         * @return bool
         */
        private function should_process_content($content)
        {
            if (empty($content) || ! is_string($content)) {
                return false;
            }

            if (is_feed()) {
                return false;
            }

            if (strlen($content) < 10) {
                return false;
            }

            return apply_filters('tinypress_autolink_should_process', true, $content);
        }

        private function get_rules_for_post($post)
        {
            if (! $post || empty($post->post_type)) {
                return array();
            }

            $all_rules = $this->get_rules();
            if (empty($all_rules)) {
                return array();
            }

            $post_type = $post->post_type;
            $filtered = array();

            foreach ($all_rules as $rule) {
                if (empty($rule['post_types']) || ! in_array($post_type, $rule['post_types'], true)) {
                    continue;
                }

                if (empty($rule['keywords'])) {
                    continue;
                }

                $filtered[] = $rule;
            }

            return apply_filters('tinypress_autolink_rules_for_post', $filtered, $post, $all_rules);
        }

        private function get_rules()
        {
            $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
            if (false !== $cached && is_array($cached)) {
                return $cached;
            }

            $cached = get_transient(self::CACHE_KEY);
            if (false !== $cached && is_array($cached)) {
                wp_cache_set(self::CACHE_KEY, $cached, self::CACHE_GROUP);
                return $cached;
            }

            $rules = $this->build_rules();
            
            set_transient(self::CACHE_KEY, $rules, self::CACHE_EXPIRATION);
            wp_cache_set(self::CACHE_KEY, $rules, self::CACHE_GROUP);

            return $rules;
        }

        private function build_rules()
        {
            $rules = array();

            $allowed_statuses = $this->get_allowed_post_statuses();
            
            $link_ids = get_posts(array(
                'post_type'      => 'tinypress_link',
                'post_status'    => $allowed_statuses,
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required lookup
                    array(
                        'key'     => 'autolink_keywords',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => 'autolink_keywords',
                        'compare' => '!=',
                        'value'   => '',
                    ),
                ),
            ));

            if (empty($link_ids)) {
                return array();
            }

            foreach ($link_ids as $link_id) {
                $link_status = Utils::get_meta('link_status', $link_id);
                if ('1' !== $link_status && true !== $link_status) {
                    continue;
                }

                $keywords_raw = (string) Utils::get_meta('autolink_keywords', $link_id);
                $keywords = $this->parse_keywords($keywords_raw);
                if (empty($keywords)) {
                    continue;
                }

                $post_types = Utils::get_meta('autolink_post_types', $link_id);
                if (! is_array($post_types)) {
                    $post_types = array();
                }

                $has_post_types_meta = metadata_exists('post', $link_id, 'autolink_post_types');

                if (in_array('__all__', $post_types, true)) {
                    $post_types = $this->get_all_autolink_post_types();
                }

                if (empty($post_types)) {
                    if (! $has_post_types_meta) {
                        $post_types = array('post', 'page');
                    } else {
                        continue;
                    }
                }

                $href = tinypress_get_tinyurl($link_id);
                
                if (empty($href) || ! is_string($href)) {
                    continue;
                }

                $nofollow = Utils::get_meta('redirection_no_follow', $link_id);
                $sponsored = Utils::get_meta('redirection_sponsored', $link_id);

                $rel = array();
                if ('1' === $nofollow || true === $nofollow) {
                    $rel[] = 'nofollow';
                }
                if ('1' === $sponsored || true === $sponsored) {
                    $rel[] = 'sponsored';
                }

                $rule = array(
                    'link_id'    => (int) $link_id,
                    'href'       => esc_url($href),
                    'keywords'   => $keywords,
                    'post_types' => array_values(array_unique(array_map('sanitize_key', $post_types))),
                    'rel'        => $rel,
                );

                $rules[] = apply_filters('tinypress_autolink_rule', $rule, $link_id);
            }

            return apply_filters('tinypress_autolink_rules', $rules);
        }

        private function get_allowed_post_statuses()
        {
            $settings = get_option('tinypress_settings', array());
            $allowed = (is_array($settings) && isset($settings['tinypress_allowed_post_statuses']))
                ? $settings['tinypress_allowed_post_statuses']
                : Utils::get_option('tinypress_allowed_post_statuses', array('publish'));
            
            if (! is_array($allowed)) {
                $allowed = array('publish');
            }

            if (empty($allowed)) {
                $allowed = array('publish');
            }

            return apply_filters('tinypress_autolink_allowed_statuses', $allowed);
        }

        /**
         * Parse keywords from raw input string
         * 
         * @param string $raw Raw keyword input (comma or newline separated)
         * @return array Parsed and sanitized keywords
         */
        private function parse_keywords($raw)
        {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return array();
            }

            $keywords = array();
            $lines = preg_split('/\r\n|\r|\n/', $raw);

            foreach ($lines as $line) {
                $line = trim(wp_strip_all_tags((string) $line));
                if ($line === '') {
                    continue;
                }

                $items = preg_split('/\s*,\s*/', $line, -1, PREG_SPLIT_NO_EMPTY);
                
                foreach ($items as $item) {
                    $item = trim($item);
                    
                    if ($item === '' || strlen($item) < 2) {
                        continue;
                    }

                    if (strlen($item) > 200) {
                        continue;
                    }

                    $item_lower = mb_strtolower($item, 'UTF-8');
                    $keywords_lower = array_map(function ($k) {
                        return mb_strtolower($k, 'UTF-8');
                    }, $keywords);
                    
                    if (! in_array($item_lower, $keywords_lower, true)) {
                        $keywords[] = $item;
                    }
                }
            }

            if (empty($keywords)) {
                return array();
            }

            usort($keywords, function ($a, $b) {
                return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
            });

            return apply_filters('tinypress_autolink_parsed_keywords', $keywords, $raw);
        }

        private function auto_link_html($html, $rules)
        {
            if (empty($html) || empty($rules)) {
                return $html;
            }

            $libxml_previous = libxml_use_internal_errors(true);

            try {
                $dom = new DOMDocument();
                $dom->encoding = 'UTF-8';
                
                $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="tinypress-autolink-root">' . $html . '</div></body></html>';

                $loaded = @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                if (! $loaded) {
                    throw new Exception('Failed to load HTML');
                }

                $root = $dom->getElementById('tinypress-autolink-root');
                if (! $root) {
                    throw new Exception('Root element not found');
                }

                $xpath = new DOMXPath($dom);
                $text_nodes = $xpath->query('.//text()[normalize-space(.) != ""]', $root);
                
                if (! $text_nodes || $text_nodes->length === 0) {
                    throw new Exception('No text nodes found');
                }

                $nodes_to_process = iterator_to_array($text_nodes);
                
                foreach ($nodes_to_process as $text_node) {
                    if (! $text_node instanceof DOMText) {
                        continue;
                    }

                    if ($this->is_excluded_text_node($text_node)) {
                        continue;
                    }

                    $new_node = $this->replace_in_text_node($dom, $text_node, $rules);
                    if ($new_node && $text_node->parentNode) {
                        $text_node->parentNode->replaceChild($new_node, $text_node);
                    }
                }

                $output = '';
                foreach ($root->childNodes as $child) {
                    $output .= $dom->saveHTML($child);
                }

                return $output;
            } catch (Exception $e) {
                do_action('tinypress_autolink_error', $e->getMessage(), $html);
                return $html;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($libxml_previous);
            }
        }

        private function is_excluded_text_node(DOMText $text_node)
        {
            $excluded = apply_filters('tinypress_autolink_excluded_elements', array(
                'a', 'script', 'style', 'code', 'pre', 'textarea', 'button', 'select', 'option'
            ));

            $parent = $text_node->parentNode;
            while ($parent && $parent instanceof DOMElement) {
                $node_name = strtolower($parent->nodeName);
                
                if (in_array($node_name, $excluded, true)) {
                    return true;
                }

                if ($parent->hasAttribute('data-no-autolink')) {
                    return true;
                }
                
                $parent = $parent->parentNode;
            }

            return false;
        }

        /**
         * Replace keywords with links in a text node
         * 
         * @param DOMDocument $dom       DOM document
         * @param DOMText     $text_node Text node to process
         * @param array       $rules     Autolink rules
         * @return DOMDocumentFragment|null Fragment with links or null
         */
        private function replace_in_text_node(DOMDocument $dom, DOMText $text_node, $rules)
        {
            $text = $text_node->wholeText;
            if ($text === '' || trim($text) === '') {
                return null;
            }

            $keyword_map = $this->build_keyword_map($rules);
            
            if (empty($keyword_map)) {
                return null;
            }

            $parts = $this->process_text_with_keywords($text, $keyword_map);
            
            if (! $this->has_links($parts)) {
                return null;
            }

            return $this->build_dom_fragment($dom, $parts);
        }

        /**
         * Build keyword map from rules with priority
         * 
         * @param array $rules Autolink rules
         * @return array Keyword map
         */
        private function build_keyword_map($rules)
        {
            $keyword_map = array();

            foreach ($rules as $rule) {
                $href = $rule['href'];
                $rel = $rule['rel'];

                foreach ($rule['keywords'] as $keyword) {
                    $keyword_lower = mb_strtolower($keyword, 'UTF-8');
                    $keyword_len = mb_strlen($keyword, 'UTF-8');
                    
                    if (! isset($keyword_map[$keyword_lower]) || $keyword_len > $keyword_map[$keyword_lower]['priority']) {
                        $keyword_map[$keyword_lower] = array(
                            'text'     => $keyword,
                            'href'     => $href,
                            'rel'      => $rel,
                            'priority' => $keyword_len,
                        );
                    }
                }
            }

            uasort($keyword_map, function ($a, $b) {
                return $b['priority'] <=> $a['priority'];
            });

            return $keyword_map;
        }

        /**
         * Process text with keywords to create parts array
         * 
         * @param string $text        Text to process
         * @param array  $keyword_map Keyword map
         * @return array Parts array
         */
        private function process_text_with_keywords($text, $keyword_map)
        {
            $parts = array(array('type' => 'text', 'value' => $text));

            foreach ($keyword_map as $keyword_data) {
                $keyword = $keyword_data['text'];
                $href = $keyword_data['href'];
                $rel = $keyword_data['rel'];
                $pattern = $this->build_keyword_pattern($keyword);

                $new_parts = array();

                foreach ($parts as $part) {
                    if ($part['type'] !== 'text') {
                        $new_parts[] = $part;
                        continue;
                    }

                    $subject = $part['value'];
                    
                    if ($subject === '' || false === mb_stripos($subject, $keyword, 0, 'UTF-8')) {
                        $new_parts[] = $part;
                        continue;
                    }

                    $split = preg_split($pattern, $subject, -1, PREG_SPLIT_DELIM_CAPTURE);
                    if (! is_array($split) || count($split) <= 1) {
                        $new_parts[] = $part;
                        continue;
                    }

                    for ($i = 0; $i < count($split); $i++) {
                        $piece = $split[$i];
                        if ($piece === '') {
                            continue;
                        }

                        if ($i % 2 === 1) {
                            $new_parts[] = array(
                                'type'  => 'link',
                                'value' => $piece,
                                'href'  => $href,
                                'rel'   => $rel,
                            );
                        } else {
                            $new_parts[] = array('type' => 'text', 'value' => $piece);
                        }
                    }
                }

                $parts = $new_parts;
            }

            return $parts;
        }

        private function has_links($parts)
        {
            foreach ($parts as $part) {
                if ($part['type'] === 'link') {
                    return true;
                }
            }
            return false;
        }

        private function build_dom_fragment(DOMDocument $dom, $parts)
        {
            $fragment = $dom->createDocumentFragment();

            foreach ($parts as $part) {
                if ($part['type'] === 'text') {
                    $fragment->appendChild($dom->createTextNode($part['value']));
                    continue;
                }

                $a = $dom->createElement('a');
                $a->setAttribute('href', esc_url($part['href']));

                if (! empty($part['rel'])) {
                    $a->setAttribute('rel', implode(' ', array_unique($part['rel'])));
                }

                if ('new_tab' === $this->global_target) {
                    $a->setAttribute('target', '_blank');

                    $rel = $a->getAttribute('rel');
                    $rel_parts = preg_split('/\s+/', (string) $rel, -1, PREG_SPLIT_NO_EMPTY);
                    if (! is_array($rel_parts)) {
                        $rel_parts = array();
                    }
                    $rel_parts[] = 'noopener';
                    $rel_parts[] = 'noreferrer';
                    $a->setAttribute('rel', implode(' ', array_unique($rel_parts)));
                }

                if ($this->global_color !== '') {
                    $a->setAttribute('style', 'color: ' . $this->global_color . ';');
                }

                $a->setAttribute('class', 'tinypress-autolink');

                $a->appendChild($dom->createTextNode($part['value']));
                $fragment->appendChild($a);
            }

            return $fragment;
        }

        private function build_keyword_pattern($keyword)
        {
            $keyword = (string) $keyword;
            if ($keyword === '') {
                return '';
            }

            $quoted = preg_quote($keyword, '/');
            $is_single_word = (bool) preg_match('/^[\p{L}\p{N}_-]+$/u', $keyword);

            if ($is_single_word) {
                return '/(\b' . $quoted . '\b)/iu';
            }

            return '/(\b' . $quoted . '\b)/iu';
        }
    }
}
