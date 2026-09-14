<?php

/**
 * Per-user favorite Shortlinks.
 */
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
class TINYPRESS_Favorite_Links
{
    private const USER_META_KEY = 'tinypress_favorite_shortlinks';

    /**
     * Register hooks.
     */
    public function __construct()
    {
        add_action('manage_tinypress_link_posts_custom_column', array( $this, 'render_link_title_actions' ), 20, 2);
        add_action('admin_post_tinypress_toggle_favorite', array( $this, 'toggle_favorite' ));
        add_filter('views_edit-tinypress_link', array( $this, 'add_favorites_view' ), 20);
        add_action('pre_get_posts', array( $this, 'filter_favorite_links' ), 20);
        add_action('trashed_post', array( $this, 'remove_trashed_link_from_favorites' ));
    }

    /**
     * Render always-visible actions beneath the link title.
     *
     * @param string $column_id Current column ID.
     * @param int    $post_id   Shortlink post ID.
     * @return void
     */
    public function render_link_title_actions($column_id, $post_id)
    {
        if (
            'link-title' !== $column_id
            || 'trash' === get_post_status($post_id)
            || ! current_user_can('tinypress_view_shortlinks')
        ) {
            return;
        }

        $is_favorite = $this->is_favorite($post_id);
        $label = $is_favorite ? __('Remove from favorites', 'tinypress') : __('Add to favorites', 'tinypress');
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'tinypress_toggle_favorite',
                    'post_id' => absint($post_id),
                ),
                admin_url('admin-post.php')
            ),
            'tinypress_toggle_favorite_' . absint($post_id)
        );

        printf(
            '<div class="tinypress-link-title-actions"><a href="%1$s" class="tinypress-favorite-toggle%2$s" aria-label="%3$s" title="%3$s" aria-pressed="%4$s"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></a>',
            esc_url($url),
            $is_favorite ? ' is-favorite' : '',
            esc_attr($label),
            $is_favorite ? 'true' : 'false'
        );

        do_action('TINYPRESS/Actions/link_title_actions', $post_id);

        echo '</div>';
    }

    /**
     * Add a Favorites view beside the Published view.
     *
     * @param string[] $views Existing list-table views.
     * @return string[]
     */
    public function add_favorites_view($views)
    {
        if (! current_user_can('tinypress_view_shortlinks')) {
            return $views;
        }

        $favorite_count = count($this->get_favorites());
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
        $is_current = isset($_GET['tinypress_favorites']) && '1' === sanitize_key(wp_unslash($_GET['tinypress_favorites']));
        $url = add_query_arg(
            array(
                'post_type'           => 'tinypress_link',
                'tinypress_favorites' => '1',
            ),
            admin_url('edit.php')
        );
        $favorite_view = sprintf(
            '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
            esc_url($url),
            $is_current ? ' class="current" aria-current="page"' : '',
            esc_html__('Favorites', 'tinypress'),
            esc_html(number_format_i18n($favorite_count))
        );

        $ordered_views = array();
        foreach ($views as $view_name => $view) {
            $ordered_views[$view_name] = $view;
            if ('publish' === $view_name) {
                $ordered_views['tinypress_favorites'] = $favorite_view;
            }
        }

        if (! isset($ordered_views['tinypress_favorites'])) {
            $ordered_views['tinypress_favorites'] = $favorite_view;
        }

        return $ordered_views;
    }

    /**
     * Remove a trashed shortlink from every user's favorites.
     *
     * @param int $post_id Trashed post ID.
     * @return void
     */
    public function remove_trashed_link_from_favorites($post_id)
    {
        if ('tinypress_link' !== get_post_type($post_id)) {
            return;
        }

        $user_ids = get_users(
            array(
                'fields'   => 'ids',
                'meta_key' => self::USER_META_KEY,
            )
        );

        foreach ($user_ids as $user_id) {
            $favorites = get_user_meta($user_id, self::USER_META_KEY, true);

            if (! is_array($favorites) || ! in_array($post_id, array_map('absint', $favorites), true)) {
                continue;
            }

            $favorites = array_values(array_diff(array_map('absint', $favorites), array( $post_id )));

            if (empty($favorites)) {
                delete_user_meta($user_id, self::USER_META_KEY);
            } else {
                update_user_meta($user_id, self::USER_META_KEY, $favorites);
            }
        }
    }

    /**
     * Toggle the current user's favorite state for a shortlink.
     *
     * @return void
     */
    public function toggle_favorite()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        check_admin_referer('tinypress_toggle_favorite_' . $post_id);

        if (
            ! $post_id
            || 'tinypress_link' !== get_post_type($post_id)
            || ! current_user_can('tinypress_view_shortlinks')
        ) {
            wp_die(
                esc_html__('You are not allowed to favorite this shortlink.', 'tinypress'),
                esc_html__('Favorite could not be updated', 'tinypress'),
                array( 'response' => 403 )
            );
        }

        $favorites = $this->get_favorites();

        if (in_array($post_id, $favorites, true)) {
            $favorites = array_values(array_diff($favorites, array( $post_id )));
        } else {
            $favorites[] = $post_id;
            $favorites = array_values(array_unique(array_map('absint', $favorites)));
        }

        update_user_meta(get_current_user_id(), self::USER_META_KEY, $favorites);

        $redirect_url = wp_get_referer();
        if (! $redirect_url) {
            $redirect_url = admin_url('edit.php?post_type=tinypress_link');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Limit the All Shortlinks query to the current user's favorites.
     *
     * @param WP_Query $query Current query.
     * @return void
     */
    public function filter_favorite_links($query)
    {
        global $pagenow;

        if (
            ! is_admin()
            || 'edit.php' !== $pagenow
            || ! $query->is_main_query()
            || 'tinypress_link' !== $query->get('post_type')
        ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
        $selected = isset($_GET['tinypress_favorites']) ? sanitize_key(wp_unslash($_GET['tinypress_favorites'])) : '';

        if ('1' !== $selected) {
            return;
        }

        $favorites = $this->get_favorites();
        $existing_ids = $query->get('post__in');

        if (is_array($existing_ids) && ! empty($existing_ids)) {
            $favorites = array_values(array_intersect($favorites, array_map('absint', $existing_ids)));
        }

        $query->set('post__in', ! empty($favorites) ? $favorites : array( 0 ));
    }

    /**
     * Get sanitized favorite IDs for the current user.
     *
     * @return int[]
     */
    private function get_favorites()
    {
        $favorites = get_user_meta(get_current_user_id(), self::USER_META_KEY, true);

        if (! is_array($favorites)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('absint', $favorites))));
    }

    /**
     * Check whether a shortlink is one of the current user's favorites.
     *
     * @param int $post_id Shortlink post ID.
     * @return bool
     */
    private function is_favorite($post_id)
    {
        return in_array(absint($post_id), $this->get_favorites(), true);
    }
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
