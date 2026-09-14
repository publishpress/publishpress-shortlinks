<?php

/**
 * Copy Shortlink admin action.
 */
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

        echo '<a href="' . esc_url($url) . '" class="tinypress-copy-link">' . esc_html__('Copy', 'tinypress') . '</a>';
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
                esc_html__('You are not allowed to copy this shortlink.', 'tinypress'),
                esc_html__('Shortlink copy failed', 'tinypress'),
                array( 'response' => 403 )
            );
        }

        $source = get_post($post_id);

        if (! $source instanceof WP_Post) {
            wp_die(
                esc_html__('The original shortlink could not be found.', 'tinypress'),
                esc_html__('Shortlink copy failed', 'tinypress'),
                array( 'response' => 404 )
            );
        }

        $duplicate_id = wp_insert_post(
            array(
                'post_type'    => 'tinypress_link',
                'post_status'  => 'draft',
                'post_title'   => sprintf(
                    /* translators: %s: original shortlink title. */
                    __('Copy of %s', 'tinypress'),
                    $source->post_title
                ),
                'post_content' => $source->post_content,
                'post_excerpt' => $source->post_excerpt,
                'post_author'  => get_current_user_id(),
            ),
            true
        );

        if (is_wp_error($duplicate_id)) {
            wp_die(
                esc_html($duplicate_id->get_error_message()),
                esc_html__('Shortlink copy failed', 'tinypress'),
                array( 'response' => 500 )
            );
        }

        $this->copy_post_meta($post_id, $duplicate_id);
        $this->copy_taxonomies($post_id, $duplicate_id);
        update_post_meta($duplicate_id, 'tiny_slug', tinypress_create_url_slug());

        $edit_url = get_edit_post_link($duplicate_id, 'raw');
        $edit_url = add_query_arg('tinypress_duplicated', '1', $edit_url);

        wp_safe_redirect($edit_url);
        exit;
    }

    /**
     * Copy reusable shortlink metadata without copying record identity.
     *
     * @param int $source_id    Source shortlink ID.
     * @param int $duplicate_id Duplicate shortlink ID.
     * @return void
     */
    private function copy_post_meta($source_id, $duplicate_id)
    {
        $excluded_keys = array(
            '_edit_last',
            '_edit_lock',
            'tiny_slug',
            'source_post_id',
            'is_revision_link',
            '_tinypress_migration_source',
            '_tinypress_migration_source_id',
            '_tinypress_migration_legacy_path',
        );
        $all_meta = get_post_meta($source_id);

        foreach ($all_meta as $meta_key => $values) {
            if (in_array($meta_key, $excluded_keys, true)) {
                continue;
            }

            foreach ($values as $value) {
                add_post_meta($duplicate_id, $meta_key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Copy all registered taxonomies assigned to the original shortlink.
     *
     * @param int $source_id    Source shortlink ID.
     * @param int $duplicate_id Duplicate shortlink ID.
     * @return void
     */
    private function copy_taxonomies($source_id, $duplicate_id)
    {
        $taxonomies = get_object_taxonomies('tinypress_link');

        foreach ($taxonomies as $taxonomy) {
            $term_ids = wp_get_object_terms($source_id, $taxonomy, array( 'fields' => 'ids' ));

            if (! is_wp_error($term_ids)) {
                wp_set_object_terms($duplicate_id, array_map('absint', $term_ids), $taxonomy);
            }
        }
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
        esc_html_e('Shortlink copied as a draft. Review it, then publish when ready.', 'tinypress');
        echo '</p></div>';
    }
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
