<?php

/*
* @Author       pluginbazar
* Copyright:    2022 pluginbazar
*/

use WPDK\Utils;

defined('ABSPATH') || exit;


if (! function_exists('tinypress')) {
    /**
     * @return TINYPRESS_Functions
     */
    function tinypress()
    {
        global $tinypress;

        if (empty($tinypress)) {
            $tinypress = new TINYPRESS_Functions();
        }

        return $tinypress;
    }
}


if (! function_exists('tinypress_generate_random_string')) {
    /**
     * Generate random string
     *
     * @param int $length
     *
     * @return string
     */
    function tinypress_generate_random_string($length = 5)
    {
        $characters       = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString     = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[ wp_rand(0, $charactersLength - 1) ];
        }

        return strtolower($randomString);
    }
}


if (! function_exists('tinypress_create_url_slug')) {
    /**Create url slug
     *
     * @param string $given_string
     *
     * @return mixed|string
     */
    function tinypress_create_url_slug($given_string = '')
    {
        global $wpdb;

        $given_string = empty($given_string) ? tinypress_generate_random_string() : $given_string;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Collision check for unique slug generation; must query directly
        $post_id      = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value like %s", $given_string));

        if (! empty($post_id)) {
            $given_string = tinypress_create_url_slug();
        }

        return $given_string;
    }
}


if (! function_exists('tinypress_get_ip_address')) {
    /**get user ip
     *
     * @return mixed
     */

    function tinypress_get_ip_address()
    {
        // phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Not a VIP environment; IP collection is required for analytics tracking
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        // Only use X-Forwarded-For if behind a trusted reverse proxy
        if (defined('TINYPRESS_TRUSTED_PROXY') && $ip === TINYPRESS_TRUSTED_PROXY) {
            if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $forwarded_ips = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
                $candidate     = trim($forwarded_ips[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                }
            }
        }

        // phpcs:enable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}


if (! function_exists('tinypress_get_tiny_slug_copier')) {
    /**
     * TinyPress get tiny slug copier
     *
     * @param $post_id
     * @param $display_input_field
     * @param $args
     *
     * @return false|string
     */
    function tinypress_get_tiny_slug_copier($post_id = 0, $display_input_field = false, $args = array())
    {
        global $post;

        $default_string   = Utils::get_args_option('default', $args);
        $wrapper_class    = Utils::get_args_option('wrapper_class', $args);
        $preview          = (bool) Utils::get_args_option('preview', $args, false);
        $preview_text     = Utils::get_args_option('preview_text', $args);
        $tiny_slug        = Utils::get_meta('tiny_slug', $post_id, $default_string);
        $link_prefix_slug = '';

        if ('1' == Utils::get_option('tinypress_link_prefix')) {
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');
        }

        ob_start();

        echo '<div class="tiny-slug-wrap ' . esc_attr($wrapper_class) . '">';

        echo '<div class="tiny-slug-preview hint--top" aria-label="' . esc_attr(tinypress()::get_text_hint()) . '" data-text-copied="' . esc_attr(tinypress()::get_text_copied()) . '">';

        echo '<span class="tiny-slug-inner">';
        if ($preview) {
            echo '<span class="preview"> ' . esc_html($preview_text) . ' </span>';
        } else {
            echo '<span class="prefix">' . esc_url(site_url('/' . $link_prefix_slug . '/')) . '</span>';
            echo '<span class="tiny-slug"> ' . esc_html($tiny_slug) . ' </span>';
        }
        echo '</span>';
        echo '</div>';

        if ($display_input_field) {
            echo '<div class="tinypress-slug-field">';
            if ('tinypress_link' == $post->post_type) {
                echo '<input type="text" class="tinypress-tiny-slug" name="tinypress_meta_main[tiny_slug]" value="' . esc_attr($tiny_slug) . '" placeholder="ad34o">';
            } else {
                echo '<input type="text" class="tinypress-tiny-slug" name="tinypress_meta_side_' . esc_attr($post->post_type) . '[tiny_slug]" value="' . esc_attr($tiny_slug) . '" placeholder="ad34o">';
            
                $link_posts = get_posts(array(
                'post_type'      => 'tinypress_link',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find linked tinypress_link entry by source_post_id
                    array(
                        'key'     => 'source_post_id',
                        'value'   => absint($post_id),
                        'compare' => '='
                    )
                ),
                'fields'         => 'ids'
                ));
            
                if (! empty($link_posts)) {
                    $edit_url = get_edit_post_link($link_posts[0]);
                    echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="tinypress-settings-link">' . esc_html__('Edit shortlink settings', 'tinypress') . '</a>';
                }
            }
            echo '</div>';
        }

        echo '</div>';

        return ob_get_clean();
    }
}


if (! function_exists('tinypress_get_roles')) {
    /**
     * Get user roles
     *
     * @return array
     */

    function tinypress_get_roles()
    {

        $role  = array();
        $roles = wp_roles()->roles;

        foreach ($roles as $key => $value) {
            $role[ $key ] = $value['name'] ?? $key;
        }

        return $role;
    }
}


if (! function_exists('tinypress_create_shorten_url')) {
    /**
     * Create shorten url
     *
     * @param $args
     *
     * @return int|mixed|WP_Error|null
     */
    function tinypress_create_shorten_url($args = array())
    {

        if (empty($target_url = Utils::get_args_option('target_url', $args))) {
            return new WP_Error(404, esc_html__('Target url not found.', 'tinypress'));
        }

        $allowed_schemes = array( 'http', 'https', 'ftp', 'ftps', 'mailto' );
        $parsed_scheme   = wp_parse_url($target_url, PHP_URL_SCHEME);

        if (empty($parsed_scheme) || ! in_array($parsed_scheme, $allowed_schemes, true)) {
            return new WP_Error('invalid_url', esc_html__('Invalid URL scheme. Only http, https, ftp, ftps, and mailto are allowed.', 'tinypress'));
        }

        $target_url = esc_url_raw($target_url, $allowed_schemes);

        if (empty($target_url)) {
            return new WP_Error('invalid_url', esc_html__('Invalid URL.', 'tinypress'));
        }

        if (empty($tiny_slug = Utils::get_args_option('tiny_slug', $args, tinypress_create_url_slug()))) {
            return new WP_Error(404, esc_html__('Tiny slug could not created.', 'tinypress'));
        }

        $post_title  = wp_strip_all_tags(Utils::get_args_option('post_title', $args));
        $redirection = Utils::get_args_option('redirection', $args, 302);
        $notes       = Utils::get_args_option('notes', $args);
        $url_args    = array(
            'post_title'  => $post_title,
            'post_type'   => 'tinypress_link',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        );

        $new_url_id = wp_insert_post($url_args);

        if (empty($post_title)) {
            wp_update_post(array(
                'ID'         => $new_url_id,
                /* translators: %s: link ID number */
                'post_title' => esc_html(sprintf(__('Link - %s', 'tinypress'), $new_url_id)),
            ));
        }

        if (is_wp_error($new_url_id)) {
            return $new_url_id;
        }

        update_post_meta($new_url_id, 'target_url', $target_url);
        update_post_meta($new_url_id, 'tiny_slug', $tiny_slug);
        update_post_meta($new_url_id, 'redirection', $redirection);
        update_post_meta($new_url_id, 'notes', $notes);

        return tinypress_get_tinyurl($new_url_id);
    }
}


if (! function_exists('tinypress_get_tinyurl')) {
    /**
     * Return tinyurl from tinypress link ID
     *
     * @param $tinypress_link_id
     *
     * @return mixed|null
     */
    function tinypress_get_tinyurl($tinypress_link_id = '')
    {

        if (empty($tinypress_link_id) || $tinypress_link_id == 0) {
            $tinypress_link_id = get_the_ID();
        }

        $tinyurl_parts[] = site_url();

        // if custom prefix enabled then add it
        if ('1' == Utils::get_option('tinypress_link_prefix')) {
            $tinyurl_parts[] = Utils::get_option('tinypress_link_prefix_slug', 'go');
        }

        // added the tiny slug
        $tiny_slug = Utils::get_meta('tiny_slug', $tinypress_link_id);
        
        if (empty($tiny_slug)) {
            $tiny_slug = tinypress_create_url_slug();
            update_post_meta($tinypress_link_id, 'tiny_slug', $tiny_slug);
        }
        
        $tinyurl_parts[] = $tiny_slug;

        return apply_filters('TINYPRESS/Filters/get_tinyurl', implode('/', $tinyurl_parts), $tinypress_link_id, $tinyurl_parts);
    }
}


if (! function_exists('tinypress_is_auto_listed')) {
    /**
     * Check if a tinypress_link is auto-listed from a post type
     *
     * @param int $link_id
     *
     * @return bool
     */
    function tinypress_is_auto_listed($link_id)
    {
        $source_post_id = Utils::get_meta('source_post_id', $link_id);
        return ! empty($source_post_id);
    }
}
