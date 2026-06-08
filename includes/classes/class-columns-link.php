<?php

use WPDK\Utils;

/**
 * Class Link Columns
 *
 * Note: This class uses WordPress naming conventions instead of strict PSR-1/PSR-2 standards.
 */
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
class TINYPRESS_Column_link
{
    protected static $_instance = null;

    /**
     * TINYPRESS_Column_link Constructor.
     */
    public function __construct()
    {
        add_filter('manage_tinypress_link_posts_columns', array( $this, 'add_columns' ), 16, 1);
        add_action('manage_tinypress_link_posts_custom_column', array( $this, 'columns_content' ), 10, 2);
        add_filter('post_row_actions', array( $this, 'remove_row_actions' ), 10, 2);
        add_action('restrict_manage_posts', array( $this, 'render_link_type_filter' ));
        add_action('pre_get_posts', array( $this, 'filter_links_by_type' ));
        add_filter('screen_settings', array( $this, 'add_screen_options' ), 10, 2);
        add_action('admin_footer-edit.php', array( $this, 'render_screen_options_script' ));
        add_action('wp_ajax_tinypress_save_categories_filter_screen_option', array( $this, 'ajax_save_categories_filter_screen_option' ));

        foreach (get_post_types(array( 'public' => true )) as $post_type) {
            if (! in_array($post_type, array( 'attachment', 'tinypress_link' ))) {
                add_filter('manage_' . $post_type . '_posts_columns', array( $this, 'tinypress_copy_columns' ));
                add_action('manage_' . $post_type . '_posts_custom_column', array( $this, 'tinypress_copy_content' ), 10, 2);
            }
        }

        if (function_exists('rvy_in_revision_workflow')) {
            add_filter('manage_revisionary-q_columns', array( $this, 'add_revision_shortlink_column' ), 20, 1);
            add_action('revisionary_list_table_custom_col', array( $this, 'display_revision_shortlink_column' ), 10, 2);
        }
    }

    /**
     * Add shortlink column to revision listings
     *
     * @param array $columns
     * @return array
     */
    public function add_revision_shortlink_column($columns)
    {
        if (! Utils::get_option('tinypress_revision_column_enabled', true)) {
            return $columns;
        }

        $columns['tinypress-revision-shortlink'] = esc_html__('Shortlink', 'tinypress');
        return $columns;
    }

    /**
     * Display revision shortlink column content
     *
     * @param string $column
     * @param WP_Post $post
     * @return void
     */
    public function display_revision_shortlink_column($column, $post)
    {
        if ('tinypress-revision-shortlink' !== $column) {
            return;
        }

        $post_id = $post->ID;

        $tiny_slug = get_post_meta($post_id, 'tiny_slug', true);

        if (empty($tiny_slug)) {
            echo '<span class="tinypress-no-shortlink">' . esc_html__('No shortlink', 'tinypress') . '</span>';
            return;
        }

        echo '<div class="tinypress-column-content">';
        echo '<div class="single-link copy-link hint--top" data-tiny_slug="' . esc_attr(tinypress_get_tinyurl($post_id)) . '" aria-label="' . esc_attr(tinypress()::get_text_hint()) . '" data-text-copied="' . esc_attr(tinypress()::get_text_copied()) . '">';
        echo '<span class="dashicons dashicons-admin-links"></span>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * tinypress_copy_columns
     *
     * @param $columns
     *
     * @return array
     */
    public function tinypress_copy_columns($columns)
    {

        $columns['tinypress-link'] = esc_html__('Shortlinks', 'tinypress');

        return $columns;
    }

    /**
     * tinypress_copy_content
     *
     * @param $column
     * @param $post_id
     *
     * @return void
     */
    public function tinypress_copy_content($column_name, $post_id)
    {

        if ('tinypress-link' == $column_name) {
            echo '<div class="tinypress-column-content">';

            echo '<div class="single-link copy-link hint--top" data-tiny_slug="' . esc_attr(tinypress_get_tinyurl($post_id)) . '" aria-label="' . esc_attr(tinypress()::get_text_hint()) . '" data-text-copied="' . esc_attr(tinypress()::get_text_copied()) . '">';
            echo '<span class="dashicons dashicons-admin-links"></span>';
            echo '</div>';

            echo '</div>';
        }
    }


    /**
     * Remove row actions for Schedules post type
     *
     * @param $actions
     *
     * @return mixed
     */
    public function remove_row_actions($actions)
    {
        global $post;

        if ($post->post_type === 'tinypress_link') {
            unset($actions['inline hide-if-no-js']);
            unset($actions['view']);
            unset($actions['edit']);
            unset($actions['trash']);
            unset($actions['create_revision']);
            unset($actions['create_draft_revision']);
            unset($actions['edit_revision']);
            unset($actions['view_revision']);
        }

        return $actions;
    }

    /**
     * Add columns content
     *
     * @param $column_id
     * @param $post_id
     */
    public function columns_content($column_id, $post_id)
    {
        switch ($column_id) {
            case 'link-title':
                $source_post_id = Utils::get_meta('source_post_id', $post_id);
                $title_html = '<strong><a class="row-title" href="' . esc_url(get_edit_post_link($post_id)) . '">' . get_the_title($post_id) . '</a></strong>';

                $is_revision_link = get_post_meta($post_id, 'is_revision_link', true);
                if ('1' !== $is_revision_link && ! empty($source_post_id) && function_exists('rvy_in_revision_workflow')) {
                    $source_post = get_post(absint($source_post_id));
                    $is_revision_link = $source_post && rvy_in_revision_workflow($source_post->ID) ? '1' : '0';
                }

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title_html is constructed from esc_url() and get_the_title() which are already escaped
                echo $title_html;
                break;

            case 'short-link':
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tinypress_get_tiny_slug_copier() returns properly escaped HTML
                echo tinypress_get_tiny_slug_copier($post_id, false, array( 'wrapper_class' => 'mini' ));
                break;

            case 'link-type':
                $link_type = $this->get_link_type($post_id);
                $badge_class = 'internal' === $link_type ? 'internal-badge' : 'external-badge';
                $badge_text = 'internal' === $link_type ? esc_html__('Internal', 'tinypress') : esc_html__('External', 'tinypress');
                $tooltip_text = 'internal' === $link_type ? esc_html__('This links to your post', 'tinypress') : esc_html__('This links to an external website', 'tinypress');

                if ('revision' === $link_type) {
                    $badge_class = 'revision-badge';
                    $badge_text = esc_html__('Revision', 'tinypress');
                    $tooltip_text = esc_html__('This links to a revision', 'tinypress');

                    // Try to get the specific revision status for a more detailed tooltip
                    $source_post_id_for_type = Utils::get_meta('source_post_id', $post_id);
                    if (! empty($source_post_id_for_type)) {
                        $source_post_for_type = get_post($source_post_id_for_type);
                        if ($source_post_for_type && ! empty($source_post_for_type->post_mime_type)) {
                            $revision_status = $source_post_for_type->post_mime_type;
                            $status_labels = array(
                                'draft-revision'       => __('Not yet submitted', 'tinypress'),
                                'pending-revision'     => __('Submitted for approval', 'tinypress'),
                                'future-revision'      => __('Scheduled', 'tinypress'),
                                'revision-deferred'    => __('Deferred', 'tinypress'),
                                'revision-needs-work'  => __('Needs work', 'tinypress'),
                                'revision-rejected'    => __('Rejected', 'tinypress'),
                            );
                            if (isset($status_labels[ $revision_status ])) {
                                /* translators: %s: revision status label */
                                $tooltip_text = esc_html(sprintf(__('Revision: %s', 'tinypress'), $status_labels[ $revision_status ]));
                            }
                        }
                    }
                }

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge_text and $tooltip_text are escaped via esc_html__() and esc_html()
                echo '<span class="tinypress-link-type-badge ' . esc_attr($badge_class) . ' pp-tooltips-library" data-toggle="tooltip">' . $badge_text . '<span class="tinypress tooltip-text">' . $tooltip_text . '</span></span>';
                break;

            case 'click-count':
                if (! current_user_can('tinypress_view_shortlink_analytics')) {
                    break;
                }

                global $wpdb;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query; TINYPRESS_TABLE_REPORTS is a safe constant; result varies per post and is not reused
                $click_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = %d", $post_id));

                /* translators: %s: number of clicks */
                echo '<div class="click-count">' . esc_html(sprintf(__('Clicked %s times', 'tinypress'), $click_count)) . '</div>';
                break;

            case 'link-actions':
                echo '<div class="link-actions">';

                echo '<a href="' . esc_url(get_edit_post_link($post_id)) . '" class="action action-edit">' . esc_html__('Edit', 'tinypress') . '</a>';
                echo '<a href="' . esc_url(get_delete_post_link($post_id)) . '" class="action action-delete">' . esc_html__('Delete', 'tinypress') . '</a>';

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filter output is escaped by callbacks.
                echo apply_filters('TINYPRESS/Filters/link_actions', '', $post_id);

                echo '</div>';

                break;

            default:
                break;
        }
    }

    /**
     * Determine if a link is internal, external, or revision.
     *
     * @param int $post_id The post ID of the tinypress_link
     *
     * @return string 'internal', 'external', or 'revision'
     */
    private function get_link_type($post_id)
    {
        if ($this->is_revision_link($post_id)) {
            return 'revision';
        }

        $target_url = Utils::get_meta('target_url', $post_id);

        if (empty($target_url)) {
            return 'external';
        }

        // Get the site domain
        $site_url = get_site_url();
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);
        $target_host = wp_parse_url($target_url, PHP_URL_HOST);

        // Remove www. prefix for comparison (so www.example.com and example.com are treated the same)
        $site_host = preg_replace('/^www\./', '', $site_host);
        $target_host = preg_replace('/^www\./', '', $target_host);

        return ($site_host === $target_host) ? 'internal' : 'external';
    }

    /**
     * Check whether a shortlink points to a revision.
     *
     * @param int $post_id The tinypress_link post ID.
     * @return bool True if revision link.
     */
    private function is_revision_link($post_id)
    {
        $is_revision_link = get_post_meta($post_id, 'is_revision_link', true);

        if ('1' === $is_revision_link) {
            return true;
        }

        $source_post_id = Utils::get_meta('source_post_id', $post_id);

        if (empty($source_post_id) || ! function_exists('rvy_in_revision_workflow')) {
            return false;
        }

        $source_post = get_post(absint($source_post_id));

        return $source_post && rvy_in_revision_workflow($source_post->ID);
    }

    /**
     * Render link type filter on the shortlinks admin list.
     *
     * @param string $post_type Current post type.
     * @return void
     */
    public function render_link_type_filter($post_type)
    {
        if ('tinypress_link' !== $post_type) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter.
        $selected = isset($_GET['tinypress_link_type']) ? sanitize_key(wp_unslash($_GET['tinypress_link_type'])) : '';
        ?>
        <label class="screen-reader-text" for="tinypress-link-type-filter"><?php esc_html_e('Filter by link type', 'tinypress'); ?></label>
        <select id="tinypress-link-type-filter" name="tinypress_link_type">
            <option value=""><?php esc_html_e('All link types', 'tinypress'); ?></option>
            <option value="internal" <?php selected($selected, 'internal'); ?>><?php esc_html_e('Internal', 'tinypress'); ?></option>
            <option value="external" <?php selected($selected, 'external'); ?>><?php esc_html_e('External', 'tinypress'); ?></option>
            <option value="revision" <?php selected($selected, 'revision'); ?>><?php esc_html_e('Revision', 'tinypress'); ?></option>
        </select>
        <?php

        $show_category_filter = $this->should_show_category_filter();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter.
        $selected_category = isset($_GET['tinypress_link_cat']) ? sanitize_title(wp_unslash($_GET['tinypress_link_cat'])) : '';
        $category_terms = get_terms(array(
            'taxonomy'   => 'tinypress_link_cat',
            'hide_empty' => false,
        ));
        ?>
        <label class="screen-reader-text" for="tinypress-link-category-filter" <?php echo $show_category_filter ? '' : 'style="display: none;"'; ?>><?php esc_html_e('Filter by category', 'tinypress'); ?></label>
        <select id="tinypress-link-category-filter" name="tinypress_link_cat" <?php echo $show_category_filter ? '' : 'style="display: none;"'; ?>>
            <option value=""><?php esc_html_e('All categories', 'tinypress'); ?></option>
            <?php if (! is_wp_error($category_terms) && ! empty($category_terms)) : ?>
                <?php foreach ($category_terms as $category_term) : ?>
                    <option value="<?php echo esc_attr($category_term->slug); ?>" <?php selected($selected_category, $category_term->slug); ?>><?php echo esc_html($category_term->name); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php
    }

    /**
     * Filter shortlinks admin list by link type.
     *
     * @param WP_Query $query Current query.
     * @return void
     */
    public function filter_links_by_type($query)
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter.
        $selected = isset($_GET['tinypress_link_type']) ? sanitize_key(wp_unslash($_GET['tinypress_link_type'])) : '';

        if (in_array($selected, array( 'internal', 'external', 'revision' ), true)) {
            $link_ids = get_posts(array(
                'post_type'      => 'tinypress_link',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ));

            $matching_ids = array_filter($link_ids, function ($link_id) use ($selected) {
                return $selected === $this->get_link_type($link_id);
            });

            $query->set('post__in', ! empty($matching_ids) ? array_map('absint', $matching_ids) : array( 0 ));
        }

        if (! $this->should_show_category_filter()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter.
        $selected_category = isset($_GET['tinypress_link_cat']) ? sanitize_title(wp_unslash($_GET['tinypress_link_cat'])) : '';

        if ('' === $selected_category) {
            return;
        }

        $term = get_term_by('slug', $selected_category, 'tinypress_link_cat');

        if (! $term instanceof WP_Term) {
            $query->set('post__in', array( 0 ));
            return;
        }

        $tax_query = (array) $query->get('tax_query');
        $tax_query[] = array(
            'taxonomy' => 'tinypress_link_cat',
            'field'    => 'slug',
            'terms'    => array( $selected_category ),
        );

        $query->set('tax_query', $tax_query);
    }

    /**
     * Add Shortlinks filter controls to Screen Options.
     *
     * @param string    $settings Existing screen settings HTML.
     * @param WP_Screen $screen   Current screen.
     * @return string
     */
    public function add_screen_options($settings, $screen)
    {
        if (! $this->is_shortlinks_list_screen($screen)) {
            return $settings;
        }

        $checked = $this->should_show_category_filter();
        $nonce = wp_create_nonce('tinypress_categories_filter_screen_option');

        ob_start();
        ?>
        <fieldset class="metabox-prefs">
            <legend><?php esc_html_e('Filters', 'tinypress'); ?></legend>
            <label for="tinypress-show-categories-filter">
                <input type="checkbox" id="tinypress-show-categories-filter" name="tinypress_show_categories_filter" value="1" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($checked); ?> />
                <?php esc_html_e('Categories', 'tinypress'); ?>
            </label>
        </fieldset>
        <?php

        return $settings . ob_get_clean();
    }

    /**
     * Save the Categories filter Screen Option through AJAX.
     *
     * @return void
     */
    public function ajax_save_categories_filter_screen_option()
    {
        check_ajax_referer('tinypress_categories_filter_screen_option', 'nonce');

        if (! current_user_can('tinypress_view_shortlinks')) {
            wp_send_json_error(array( 'message' => esc_html__('Permission denied.', 'tinypress') ));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified above.
        $show_categories = isset($_POST['tinypress_show_categories_filter'])
            ? sanitize_key(wp_unslash($_POST['tinypress_show_categories_filter']))
            : '0';

        update_user_meta(get_current_user_id(), 'tinypress_show_categories_filter', '1' === $show_categories ? '1' : '0');
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json_success();
    }

    /**
     * Render instant-save behavior for the custom Categories Screen Option.
     *
     * @return void
     */
    public function render_screen_options_script()
    {
        if (! $this->is_shortlinks_list_screen(get_current_screen())) {
            return;
        }
        ?>
        <script>
        (function($) {
            $(document).on('change', '#tinypress-show-categories-filter', function() {
                var $checkbox = $(this);
                var showCategories = $checkbox.is(':checked') ? '1' : '0';
                var $categoryFilter = $('#tinypress-link-category-filter');
                var $categoryLabel = $('label[for="tinypress-link-category-filter"]');

                if (showCategories === '1') {
                    $categoryLabel.show();
                    $categoryFilter.show();
                } else {
                    $categoryFilter.val('').hide();
                    $categoryLabel.hide();
                }

                $.post(ajaxurl, {
                    action: 'tinypress_save_categories_filter_screen_option',
                    nonce: $checkbox.data('nonce'),
                    tinypress_show_categories_filter: showCategories
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Check if the Categories filter should be visible.
     *
     * @return bool
     */
    private function should_show_category_filter()
    {
        $value = get_user_meta(get_current_user_id(), 'tinypress_show_categories_filter', true);

        return '0' !== $value;
    }

    /**
     * Check if the current screen is the All Shortlinks list screen.
     *
     * @param WP_Screen|null $screen Current screen.
     * @return bool
     */
    private function is_shortlinks_list_screen($screen)
    {
        return $screen && 'edit-tinypress_link' === $screen->id;
    }

    /**
     * Add columns on Schedules listing
     *
     * @return string[]
     */
    public function add_columns($columns)
    {
        $new_columns = array(
            'cb'           => Utils::get_args_option('cb', $columns),
            'link-title'   => esc_html__('Link Title', 'tinypress'),
            'short-link'   => esc_html__('Shortlink', 'tinypress'),
            'link-type'    => esc_html__('Link Type', 'tinypress'),
        );

        if (current_user_can('tinypress_view_shortlink_analytics')) {
            $new_columns['click-count'] = esc_html__('Stats', 'tinypress');
        }

        $new_columns['link-actions'] = esc_html__('Actions', 'tinypress');

        return apply_filters('TINYPRESS/Filters/link_columns', $new_columns, $columns);
    }


    /**
     * @return TINYPRESS_Column_link
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
}
