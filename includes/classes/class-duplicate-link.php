<?php

/**
 * Copy Shortlink admin action.
 */

defined('ABSPATH') || exit;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
class TINYPRESS_Duplicate_Link
{
    /**
     * Register hooks.
     */
    public function __construct()
    {
        add_action('TINYPRESS/Actions/link_title_actions', array( $this, 'render_copy_action' ));
        add_action('admin_post_tinypress_duplicate_link', array( $this, 'duplicate_link' ));
        add_action('admin_notices', array( $this, 'render_admin_notice' ));
    }

    /**
     * Render the Copy action beneath the link title.
     *
     * @param int $post_id Shortlink post ID.
     * @return void
     */
    public function render_copy_action($post_id)
    {
        if (! current_user_can('tinypress_create_shortlinks') || ! current_user_can('edit_post', $post_id)) {
            return;
        }

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'tinypress_duplicate_link',
                    'post_id' => absint($post_id),
                ),
                admin_url('admin-post.php')
            ),
            'tinypress_duplicate_link_' . absint($post_id)
        );

        echo '<a href="' . esc_url($url) . '" class="tinypress-copy-link">' . esc_html__('Duplicate', 'tinypress') . '</a>';
    }

    /**
     * Create a draft copy of a shortlink and open it for review.
     *
     * @return void
     */
    public function duplicate_link()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        check_admin_referer('tinypress_duplicate_link_' . $post_id);

        if (
            ! $post_id
            || 'tinypress_link' !== get_post_type($post_id)
            || ! current_user_can('tinypress_create_shortlinks')
            || ! current_user_can('edit_post', $post_id)
        ) {
            wp_die(
                esc_html__('You are not allowed to duplicate this shortlink.', 'tinypress'),
                esc_html__('Shortlink duplication failed', 'tinypress'),
                array( 'response' => 403 )
            );
        }

        $duplicate_id = self::copy_record($post_id);
        if (is_wp_error($duplicate_id)) {
            wp_die(esc_html($duplicate_id->get_error_message()));
        }

        $edit_url = get_edit_post_link($duplicate_id, 'raw');
        $edit_url = add_query_arg('tinypress_duplicated', '1', $edit_url);

        wp_safe_redirect($edit_url);
        exit;
    }

    /** Copy through the same native service used by the admin Copy action. */
    public static function copy_record($post_id, $authorize_metadata = null)
    {
        if (!current_user_can('tinypress_create_shortlinks') || !current_user_can('read_post', $post_id) || !current_user_can('edit_post', $post_id) || get_post_type($post_id) !== 'tinypress_link') {
            return new WP_Error('tinypress_copy_denied', __('Shortlink duplication denied.', 'tinypress'));
        }
        $source = get_post($post_id);

        if (! $source instanceof WP_Post) {
            return new WP_Error('tinypress_missing_source', __('The original shortlink could not be found.', 'tinypress'));
        }

        $duplicate_id = wp_insert_post(
            wp_slash(array(
                'post_type'    => 'tinypress_link',
                'post_status'  => 'draft',
                'post_title'   => sprintf(
                    /* translators: %s: original shortlink title. */
                    __('Duplicate of %s', 'tinypress'),
                    $source->post_title
                ),
                'post_content' => $source->post_content,
                'post_excerpt' => $source->post_excerpt,
                'post_author'  => get_current_user_id(),
            )),
            true
        );

        if (is_wp_error($duplicate_id)) {
            return $duplicate_id;
        }

        $copy_keys = array_values(array_diff(array_keys(get_post_meta($post_id)), self::excluded_meta_keys()));
        if ($authorize_metadata && !$authorize_metadata($duplicate_id, $copy_keys)) {
            return new WP_Error('tinypress_copy_permission', __('The draft exists but metadata permissions prevented duplication.', 'tinypress'), array('id' => $duplicate_id));
        }
        $copied_meta = self::copy_post_meta($post_id, $duplicate_id);
        $copied_terms = self::copy_taxonomies($post_id, $duplicate_id);
        $slug = tinypress_create_url_slug();
        update_post_meta($duplicate_id, 'tiny_slug', $slug);
        if (!$copied_meta || !$copied_terms || get_post_meta($duplicate_id, 'tiny_slug', true) !== $slug) {
            return new WP_Error('tinypress_copy_incomplete', __('The draft exists but duplication was not fully confirmed.', 'tinypress'), array('id' => $duplicate_id));
        }

        return $duplicate_id;
    }

    private static function excluded_meta_keys()
    {
        $keys = array(
            '_edit_last',
            '_edit_lock',
            'tiny_slug',
            'source_post_id',
            'is_revision_link',
            '_tinypress_migration_source',
            '_tinypress_migration_source_id',
            '_tinypress_migration_legacy_path',
        );
        return apply_filters('tinypress_copy_excluded_meta_keys', $keys);
    }

    /**
     * Copy reusable shortlink metadata without copying record identity.
     *
     * @param int $source_id    Source shortlink ID.
     * @param int $duplicate_id Duplicate shortlink ID.
     * @return bool
     */
    private static function copy_post_meta($source_id, $duplicate_id)
    {
        $excluded_keys = self::excluded_meta_keys();
        $all_meta = get_post_meta($source_id);
        $complete = true;

        foreach ($all_meta as $meta_key => $values) {
            if (in_array($meta_key, $excluded_keys, true)) {
                continue;
            }

            foreach ($values as $value) {
                $complete = (bool) add_post_meta($duplicate_id, $meta_key, wp_slash(maybe_unserialize($value))) && $complete;
            }
        }
        return $complete;
    }

    /**
     * Copy all registered taxonomies assigned to the original shortlink.
     *
     * @param int $source_id    Source shortlink ID.
     * @param int $duplicate_id Duplicate shortlink ID.
     * @return bool
     */
    private static function copy_taxonomies($source_id, $duplicate_id)
    {
        $taxonomies = get_object_taxonomies('tinypress_link');
        $complete = true;

        foreach ($taxonomies as $taxonomy) {
            $term_ids = wp_get_object_terms($source_id, $taxonomy, array( 'fields' => 'ids' ));

            if (! is_wp_error($term_ids)) {
                $result = wp_set_object_terms($duplicate_id, array_map('absint', $term_ids), $taxonomy);
                $actual = wp_get_object_terms($duplicate_id, $taxonomy, array('fields' => 'ids'));
                $complete = !is_wp_error($result) && !is_wp_error($actual) && !array_diff($actual, $term_ids) && !array_diff($term_ids, $actual) && $complete;
            } else {
                $complete = false;
            }
        }
        return $complete;
    }

    /**
     * Confirm that a copy was created as a draft.
     *
     * @return void
     */
    public function render_admin_notice()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected redirect.
        if (empty($_GET['tinypress_duplicated']) || '1' !== sanitize_key(wp_unslash($_GET['tinypress_duplicated']))) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        esc_html_e('Shortlink duplicated as a draft. Review it, then publish when ready.', 'tinypress');
        echo '</p></div>';
    }
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
