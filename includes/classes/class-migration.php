<?php

/**
 * PublishPress Shortlinks - Migration discovery and guidance.
 *
 * @package publishpress-shortlinks
 */

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Migration')) {
    /**
     * Detect compatible link management plugins and guide users to migration tools.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    class TINYPRESS_Migration
    {
        public static $instance;

        private const USER_META_DISMISSED_HASH = 'tinypress_migration_notice_dismissed_hash';
        private const USER_META_REMIND_AFTER   = 'tinypress_migration_notice_remind_after';
        private const REMIND_LATER_SECONDS     = 604800; // 7 days.
        private const BATCH_SIZE               = 25;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'add_submenu_page'), 21);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
            add_action('admin_notices', array($this, 'render_notice'));
            add_action('admin_init', array($this, 'handle_notice_action'));
            add_action('wp_ajax_tinypress_run_migration', array($this, 'ajax_run_migration'));
        }

        public static function get_instance()
        {
            if (! isset(self::$instance)) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Supported migration sources.
         *
         * @return array
         */
        public function get_sources()
        {
            return array(
                'pretty-links' => array(
                    'name'        => esc_html__('Pretty Links', 'tinypress'),
                    'description' => esc_html__('Import branded and affiliate links from Pretty Links.', 'tinypress'),
                    'paths'       => array(
                        'pretty-link/pretty-link.php',
                        'pretty-link-pro/pretty-link-pro.php',
                    ),
                    'direct_migration' => true,
                ),
                'betterlinks' => array(
                    'name'        => esc_html__('BetterLinks', 'tinypress'),
                    'description' => esc_html__('Import link redirects, slugs, and link settings from BetterLinks.', 'tinypress'),
                    'paths'       => array(
                        'betterlinks/betterlinks.php',
                        'betterlinks-pro/betterlinks-pro.php',
                    ),
                    'direct_migration' => true,
                ),
                'thirstyaffiliates' => array(
                    'name'        => esc_html__('ThirstyAffiliates', 'tinypress'),
                    'description' => esc_html__('Import affiliate links and redirect settings from ThirstyAffiliates.', 'tinypress'),
                    'paths'       => array(
                        'thirstyaffiliates/thirstyaffiliates.php',
                        'thirstyaffiliates-pro/thirstyaffiliates-pro.php',
                    ),
                    'direct_migration' => true,
                ),
                'url-shortify' => array(
                    'name'        => esc_html__('URL Shortify', 'tinypress'),
                    'description' => esc_html__('Import short links managed by URL Shortify.', 'tinypress'),
                    'paths'       => array(
                        'url-shortify/url-shortify.php',
                    ),
                    'direct_migration' => true,
                ),
                'linkcentral' => array(
                    'name'        => esc_html__('LinkCentral', 'tinypress'),
                    'description' => esc_html__('Import managed short links from LinkCentral.', 'tinypress'),
                    'paths'       => array(
                        'linkcentral/linkcentral.php',
                    ),
                    'direct_migration' => true,
                ),
                'shortlinkspro' => array(
                    'name'        => esc_html__('ShortLinks Pro', 'tinypress'),
                    'description' => esc_html__('Import short links, redirects, and link options from ShortLinks Pro.', 'tinypress'),
                    'paths'       => array(
                        'shortlinkspro/shortlinkspro.php',
                    ),
                    'direct_migration' => true,
                ),
                'easy-affiliate-links' => array(
                    'name'        => esc_html__('Easy Affiliate Links', 'tinypress'),
                    'description' => esc_html__('Import affiliate link redirects from Easy Affiliate Links.', 'tinypress'),
                    'paths'       => array(
                        'easy-affiliate-links/easy-affiliate-links.php',
                    ),
                    'direct_migration' => false,
                ),
                'simple-urls' => array(
                    'name'        => esc_html__('Simple URLs', 'tinypress'),
                    'description' => esc_html__('Import clean URL redirects from Simple URLs.', 'tinypress'),
                    'paths'       => array(
                        'simple-urls/plugin.php',
                        'simple-urls/simple-urls.php',
                    ),
                    'direct_migration' => false,
                ),
                'affiliate-links' => array(
                    'name'        => esc_html__('Affiliate Links', 'tinypress'),
                    'description' => esc_html__('Import cloaked affiliate links from Affiliate Links.', 'tinypress'),
                    'paths'       => array(
                        'affiliate-links/affiliate-links.php',
                    ),
                    'direct_migration' => false,
                ),
            );
        }

        public function add_submenu_page()
        {
            if (empty($this->get_active_sources())) {
                return;
            }

            add_submenu_page(
                'edit.php?post_type=tinypress_link',
                esc_html__('Migration', 'tinypress'),
                esc_html__('Migration', 'tinypress'),
                'manage_options',
                'tinypress-migration',
                array($this, 'render_page')
            );
        }

        public function enqueue_assets()
        {
            $screen = get_current_screen();

            if (! $screen || 'tinypress_link_page_tinypress-migration' !== $screen->id) {
                return;
            }

            wp_enqueue_script(
                'tinypress-migration',
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/migration.js',
                array('jquery'),
                tinypress_asset_version('assets/admin/js/migration.js'),
                true
            );

            wp_localize_script('tinypress-migration', 'tinypressMigration', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('tinypress_run_migration'),
                'i18n'     => array(
                    'running'  => esc_html__('Migration is running...', 'tinypress'),
                    'complete' => esc_html__('Migration complete.', 'tinypress'),
                    'failed'   => esc_html__('Migration failed.', 'tinypress'),
                    'deactivate' => esc_html__('Deactivate %s', 'tinypress'),
                ),
            ));
        }

        /**
         * Get active supported migration sources.
         *
         * @return array
         */
        public function get_active_sources()
        {
            $active_plugins = $this->get_active_plugin_files();
            $active_sources = array();

            foreach ($this->get_sources() as $source_key => $source) {
                foreach ($source['paths'] as $path) {
                    if (in_array($path, $active_plugins, true)) {
                        $source['active_path'] = $path;
                        $source['key'] = $source_key;
                        $active_sources[$source_key] = $source;
                        break;
                    }
                }
            }

            return $active_sources;
        }

        private function get_active_plugin_files()
        {
            $active = (array) get_option('active_plugins', array());

            if (is_multisite()) {
                $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
            }

            return array_values(array_unique($active));
        }

        private function get_detected_hash(array $active_sources)
        {
            $keys = array_keys($active_sources);
            sort($keys);

            return md5(implode('|', $keys));
        }

        public function handle_notice_action()
        {
            if (! current_user_can('manage_options') || empty($_GET['tinypress_migration_notice_action'])) {
                return;
            }

            $action = sanitize_key(wp_unslash($_GET['tinypress_migration_notice_action']));
            if (! in_array($action, array('remind_later', 'dismiss'), true)) {
                return;
            }

            if (empty($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'tinypress_migration_notice_action')) {
                return;
            }

            $active_sources = $this->get_active_sources();
            if (empty($active_sources)) {
                return;
            }

            $user_id = get_current_user_id();

            if ('remind_later' === $action) {
                update_user_meta($user_id, self::USER_META_REMIND_AFTER, time() + self::REMIND_LATER_SECONDS);
            } else {
                update_user_meta($user_id, self::USER_META_DISMISSED_HASH, $this->get_detected_hash($active_sources));
                delete_user_meta($user_id, self::USER_META_REMIND_AFTER);
            }

            wp_safe_redirect(remove_query_arg(array('tinypress_migration_notice_action', '_wpnonce')));
            exit;
        }

        public function render_notice()
        {
            if (! current_user_can('manage_options') || ! $this->is_shortlinks_admin_screen()) {
                return;
            }

            $active_sources = $this->get_active_sources();
            if (empty($active_sources) || ! $this->should_show_notice($active_sources)) {
                return;
            }

            $names = wp_list_pluck($active_sources, 'name');
            $migration_url = add_query_arg(
                array(
                    'post_type' => 'tinypress_link',
                    'page'      => 'tinypress-migration',
                ),
                admin_url('edit.php')
            );
            $remind_url = wp_nonce_url(
                add_query_arg('tinypress_migration_notice_action', 'remind_later'),
                'tinypress_migration_notice_action'
            );
            $dismiss_url = wp_nonce_url(
                add_query_arg('tinypress_migration_notice_action', 'dismiss'),
                'tinypress_migration_notice_action'
            );
            ?>
            <div class="notice notice-info tinypress-migration-notice">
                <p>
                    <strong><?php esc_html_e('PublishPress Shortlinks:', 'tinypress'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s: comma-separated plugin names */
                        esc_html__('We detected %s. You can review migration options for existing links.', 'tinypress'),
                        esc_html(implode(', ', $names))
                    );
                    ?>
                </p>
                <p class="tinypress-migration-notice-actions">
                    <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">
                        <?php esc_html_e('Review Migration Options', 'tinypress'); ?>
                    </a>
                    <a href="<?php echo esc_url($remind_url); ?>" class="button">
                        <?php esc_html_e('Remind me later', 'tinypress'); ?>
                    </a>
                    <a href="<?php echo esc_url($dismiss_url); ?>" class="button">
                        <?php esc_html_e('Dismiss', 'tinypress'); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        private function should_show_notice(array $active_sources)
        {
            $user_id = get_current_user_id();
            $dismissed_hash = (string) get_user_meta($user_id, self::USER_META_DISMISSED_HASH, true);

            if ($dismissed_hash && hash_equals($dismissed_hash, $this->get_detected_hash($active_sources))) {
                return false;
            }

            $remind_after = (int) get_user_meta($user_id, self::USER_META_REMIND_AFTER, true);

            return ! $remind_after || time() >= $remind_after;
        }

        private function is_shortlinks_admin_screen()
        {
            $screen = get_current_screen();

            if (! $screen) {
                return false;
            }

            if ('tinypress_link_page_tinypress-migration' === $screen->id) {
                return false;
            }

            $screen_ids = array(
                'edit-tinypress_link',
                'tinypress_link',
                'tinypress_link_page_settings',
                'tinypress_link_page_tinypress-logs',
                'edit-tinypress_link_cat',
                'edit-tinypress_link_category',
                'tinypress_link_page_tinypress-import-export',
                'tinypress_link_page_tinypress-link-checker',
                'tinypress_link_page_tinypress-reports',
            );

            return in_array($screen->id, $screen_ids, true);
        }

        public function render_page()
        {
            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'tinypress'));
            }

            $active_sources = $this->get_active_sources();

            if (empty($active_sources)) {
                wp_safe_redirect(admin_url('edit.php?post_type=tinypress_link'));
                exit;
            }

            $import_url     = add_query_arg(
                array(
                    'post_type' => 'tinypress_link',
                    'page'      => 'tinypress-import-export',
                ),
                admin_url('edit.php')
            );
            ?>
            <div class="wrap tinypress-migration-wrap">
                <h1><?php esc_html_e('Migration', 'tinypress'); ?></h1>
                <p class="description">
                    <?php esc_html_e('Already using another link management tool? Review detected plugins and prepare your existing links for import into PublishPress Shortlinks.', 'tinypress'); ?>
                </p>

                <div class="notice notice-success inline tinypress-migration-summary">
                    <p>
                        <?php
                        printf(
                            /* translators: %d: number of active migration sources */
                            esc_html__('%d supported migration source(s) detected.', 'tinypress'),
                            count($active_sources)
                        );
                        ?>
                    </p>
                </div>

                <div class="tinypress-migration-grid">
                    <?php if (empty($active_sources)) : ?>
                        <div class="tinypress-migration-empty">
                            <p><?php esc_html_e('No supported active link management plugins were detected on this site.', 'tinypress'); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($active_sources as $source_key => $source) : ?>
                        <div class="tinypress-migration-card is-active" data-source="<?php echo esc_attr($source_key); ?>">
                            <div class="tinypress-migration-card-header">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <h2><?php echo esc_html($source['name']); ?></h2>
                            </div>
                            <p><?php echo esc_html($source['description']); ?></p>
                            <p class="tinypress-migration-import-note">
                                <?php
                                printf(
                                    /* translators: %s: plugin name */
                                    esc_html__('Already have a CSV export from %s? Use Import Tools to upload and preview it before importing.', 'tinypress'),
                                    esc_html($source['name'])
                                );
                                ?>
                            </p>
                            <div class="tinypress-migration-actions">
                                <?php if (! empty($source['direct_migration'])) : ?>
                                    <button type="button" class="button button-primary tinypress-migration-run" data-source="<?php echo esc_attr($source_key); ?>">
                                        <?php esc_html_e('Migrate', 'tinypress'); ?>
                                    </button>
                                <?php else : ?>
                                    <button type="button" class="button button-primary" disabled>
                                        <?php esc_html_e('Direct migration unavailable', 'tinypress'); ?>
                                    </button>
                                <?php endif; ?>
                                <a class="button" href="<?php echo esc_url($import_url); ?>">
                                    <?php esc_html_e('Open Import Tools', 'tinypress'); ?>
                                </a>
                            </div>
                            <div class="tinypress-migration-progress" hidden>
                                <div class="tinypress-migration-progress-bar">
                                    <div class="tinypress-migration-progress-fill" style="width:0%"></div>
                                </div>
                                <span class="tinypress-migration-progress-text">0%</span>
                            </div>
                            <div class="tinypress-migration-result" aria-live="polite"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }

        public function ajax_run_migration()
        {
            check_ajax_referer('tinypress_run_migration', 'nonce');

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array('message' => esc_html__('Unauthorized.', 'tinypress')));
            }

            $source_key = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
            $offset     = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
            $limit      = isset($_POST['limit']) ? max(1, min(100, absint($_POST['limit']))) : self::BATCH_SIZE;

            $active_sources = $this->get_active_sources();
            if (empty($source_key) || ! isset($active_sources[$source_key])) {
                wp_send_json_error(array('message' => esc_html__('Migration source is not active.', 'tinypress')));
            }

            if (empty($active_sources[$source_key]['direct_migration'])) {
                wp_send_json_error(array('message' => esc_html__('Direct migration is not available for this plugin yet. Please use Import Tools with a CSV export.', 'tinypress')));
            }

            $total = $this->get_source_total($source_key);
            $rows  = $this->get_source_rows($source_key, $offset, $limit);

            $imported = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = array();

            foreach ($rows as $row) {
                $result = $this->import_source_row($source_key, $row);

                if (is_wp_error($result)) {
                    $skipped++;
                    $errors[] = $result->get_error_message();
                    continue;
                }

                if ('updated' === $result) {
                    $updated++;
                } else {
                    $imported++;
                }
            }

            $processed = count($rows);
            $next_offset = $offset + $processed;
            $done = $next_offset >= $total || 0 === $processed;

            wp_send_json_success(array(
                'total'       => $total,
                'processed'   => $next_offset,
                'batch_count' => $processed,
                'imported'    => $imported,
                'updated'     => $updated,
                'skipped'     => $skipped,
                'errors'      => array_slice($errors, 0, 3),
                'done'        => $done,
                'message'     => $done ? esc_html__('Migration complete.', 'tinypress') : esc_html__('Migration is running...', 'tinypress'),
                'source_name' => $active_sources[$source_key]['name'],
                'deactivate_url' => $done ? $this->get_deactivate_url($active_sources[$source_key]) : '',
            ));
        }

        private function get_deactivate_url(array $source)
        {
            if (empty($source['active_path'])) {
                return '';
            }

            return add_query_arg(
                array(
                    'action'   => 'deactivate',
                    'plugin'   => $source['active_path'],
                    '_wpnonce' => wp_create_nonce('deactivate-plugin_' . $source['active_path']),
                ),
                admin_url('plugins.php')
            );
        }

        private function table_exists($table)
        {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }

        private function get_source_total($source_key)
        {
            global $wpdb;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Migration imports intentionally read known source plugin tables directly.
            switch ($source_key) {
                case 'pretty-links':
                    $table = $wpdb->prefix . 'prli_links';
                    return $this->table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE url IS NOT NULL AND url != ''") : 0;

                case 'betterlinks':
                    $table = $wpdb->prefix . 'betterlinks';
                    return $this->table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE target_url IS NOT NULL AND target_url != ''") : 0;

                case 'url-shortify':
                    $table = $wpdb->prefix . 'kc_us_links';
                    return $this->table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE url IS NOT NULL AND url != ''") : 0;

                case 'shortlinkspro':
                    $table = $wpdb->prefix . 'shortlinkspro_links';
                    return $this->table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE url IS NOT NULL AND url != ''") : 0;

                case 'thirstyaffiliates':
                    return (int) $wpdb->get_var(
                        "SELECT COUNT(DISTINCT p.ID)
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ta_destination_url' AND pm.meta_value != ''
                        WHERE p.post_type = 'thirstylink' AND p.post_status NOT IN ('trash', 'auto-draft')"
                    );

                case 'linkcentral':
                    return (int) $wpdb->get_var(
                        "SELECT COUNT(DISTINCT p.ID)
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_linkcentral_destination_url' AND pm.meta_value != ''
                        WHERE p.post_type = 'linkcentral_link' AND p.post_status NOT IN ('trash', 'auto-draft')"
                    );
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return 0;
        }

        private function get_source_rows($source_key, $offset, $limit)
        {
            global $wpdb;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Migration imports intentionally read known source plugin tables directly.
            switch ($source_key) {
                case 'pretty-links':
                    $table = $wpdb->prefix . 'prli_links';
                    if (! $this->table_exists($table)) {
                        return array();
                    }
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT id, name, url, slug, nofollow, sponsored, redirect_type, link_status, description, created_at
                        FROM {$table}
                        WHERE url IS NOT NULL AND url != ''
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);

                case 'betterlinks':
                    $table = $wpdb->prefix . 'betterlinks';
                    if (! $this->table_exists($table)) {
                        return array();
                    }
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT ID, link_title, target_url, short_url, link_slug, nofollow, sponsored, param_forwarding, redirect_type, link_status, link_note, link_date
                        FROM {$table}
                        WHERE target_url IS NOT NULL AND target_url != ''
                        ORDER BY ID ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);

                case 'url-shortify':
                    $table = $wpdb->prefix . 'kc_us_links';
                    if (! $this->table_exists($table)) {
                        return array();
                    }
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT id, name, url, slug, nofollow, sponsored, params_forwarding, redirect_type, status, description, expires_at, created_at
                        FROM {$table}
                        WHERE url IS NOT NULL AND url != ''
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);

                case 'shortlinkspro':
                    $table = $wpdb->prefix . 'shortlinkspro_links';
                    if (! $this->table_exists($table)) {
                        return array();
                    }
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT id, title, url, slug, redirect_type, nofollow, sponsored, parameter_forwarding, created_at
                        FROM {$table}
                        WHERE url IS NOT NULL AND url != ''
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);

                case 'thirstyaffiliates':
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_date
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ta_destination_url' AND pm.meta_value != ''
                        WHERE p.post_type = 'thirstylink' AND p.post_status NOT IN ('trash', 'auto-draft')
                        ORDER BY p.ID ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);

                case 'linkcentral':
                    return $wpdb->get_results($wpdb->prepare(
                        "SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_date
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_linkcentral_destination_url' AND pm.meta_value != ''
                        WHERE p.post_type = 'linkcentral_link' AND p.post_status NOT IN ('trash', 'auto-draft')
                        ORDER BY p.ID ASC
                        LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ), ARRAY_A);
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return array();
        }

        private function import_source_row($source_key, array $row)
        {
            $data = $this->map_source_row($source_key, $row);

            if (empty($data['target_url'])) {
                return new WP_Error('missing_target_url', esc_html__('Missing target URL.', 'tinypress'));
            }

            $target_url = function_exists('tinypress_validate_target_url') ? tinypress_validate_target_url($data['target_url']) : esc_url_raw($data['target_url']);
            if (is_wp_error($target_url)) {
                return $target_url;
            }

            $slug = $this->sanitize_migration_slug($data['short_slug']);
            if ('' === $slug && function_exists('tinypress_create_url_slug')) {
                $slug = tinypress_create_url_slug();
            }

            if ('' === $slug) {
                return new WP_Error('missing_slug', esc_html__('Missing shortlink slug.', 'tinypress'));
            }

            $label = '' !== $data['label'] ? sanitize_text_field($data['label']) : $target_url;
            $link_id = $this->find_existing_link_id($slug);
            $is_update = (bool) $link_id;

            if ($is_update) {
                wp_update_post(array(
                    'ID'         => $link_id,
                    'post_title' => $label,
                ));
            } else {
                $link_id = wp_insert_post(array(
                    'post_title'  => $label,
                    'post_type'   => 'tinypress_link',
                    'post_status' => 'publish',
                    'post_author' => get_current_user_id(),
                ));

                if (is_wp_error($link_id) || empty($link_id)) {
                    return new WP_Error('create_failed', esc_html__('Could not create shortlink.', 'tinypress'));
                }
            }

            update_post_meta($link_id, 'target_url', $target_url);
            update_post_meta($link_id, 'tiny_slug', $slug);
            update_post_meta($link_id, 'link_status', ! empty($data['enabled']) ? '1' : '0');
            update_post_meta($link_id, 'redirection_method', $this->parse_redirect_method($data['redirect_method']));

            update_post_meta($link_id, 'redirection_no_follow_use_global', array());
            update_post_meta($link_id, 'redirection_no_follow', $this->parse_boolean($data['nofollow']) ? '1' : '0');
            update_post_meta($link_id, 'redirection_sponsored_use_global', array());
            update_post_meta($link_id, 'redirection_sponsored', $this->parse_boolean($data['sponsored']) ? '1' : '0');
            update_post_meta($link_id, 'redirection_parameter_forwarding_use_global', array());
            update_post_meta($link_id, 'redirection_parameter_forwarding', $this->parse_boolean($data['parameter_forwarding']) ? '1' : '0');

            if (! empty($data['notes'])) {
                update_post_meta($link_id, 'tiny_notes', sanitize_textarea_field($data['notes']));
            }

            if (! empty($data['expiration_date'])) {
                update_post_meta($link_id, 'enable_expiration_use_global', array());
                update_post_meta($link_id, 'enable_expiration', '1');
                update_post_meta($link_id, 'expiration_date', sanitize_text_field($data['expiration_date']));
            }

            update_post_meta($link_id, '_tinypress_migration_source', sanitize_key($source_key));
            update_post_meta($link_id, '_tinypress_migration_source_id', sanitize_text_field((string) $data['source_id']));

            return $is_update ? 'updated' : 'imported';
        }

        private function map_source_row($source_key, array $row)
        {
            $defaults = array(
                'source_id'             => '',
                'label'                 => '',
                'target_url'            => '',
                'short_slug'            => '',
                'redirect_method'       => 302,
                'nofollow'              => false,
                'sponsored'             => false,
                'parameter_forwarding'  => false,
                'enabled'               => true,
                'notes'                 => '',
                'expiration_date'       => '',
            );

            switch ($source_key) {
                case 'pretty-links':
                    return array_merge($defaults, array(
                        'source_id'       => $row['id'] ?? '',
                        'label'           => $row['name'] ?? '',
                        'target_url'      => $row['url'] ?? '',
                        'short_slug'      => $this->get_first_row_value($row, array('slug')),
                        'redirect_method' => $row['redirect_type'] ?? 302,
                        'nofollow'        => $row['nofollow'] ?? false,
                        'sponsored'       => $row['sponsored'] ?? false,
                        'enabled'         => 'disabled' !== ($row['link_status'] ?? 'enabled'),
                        'notes'           => $row['description'] ?? '',
                    ));

                case 'betterlinks':
                    return array_merge($defaults, array(
                        'source_id'            => $row['ID'] ?? '',
                        'label'                => $row['link_title'] ?? '',
                        'target_url'           => $row['target_url'] ?? '',
                        'short_slug'           => $this->get_first_row_value($row, array('short_url', 'link_slug')),
                        'redirect_method'      => $row['redirect_type'] ?? 302,
                        'nofollow'             => $row['nofollow'] ?? false,
                        'sponsored'            => $row['sponsored'] ?? false,
                        'parameter_forwarding' => $row['param_forwarding'] ?? false,
                        'enabled'              => 'publish' === ($row['link_status'] ?? 'publish'),
                        'notes'                => $row['link_note'] ?? '',
                    ));

                case 'shortlinkspro':
                    return array_merge($defaults, array(
                        'source_id'            => $row['id'] ?? '',
                        'label'                => $row['title'] ?? '',
                        'target_url'           => $row['url'] ?? '',
                        'short_slug'           => $this->get_first_row_value($row, array('slug')),
                        'redirect_method'      => $row['redirect_type'] ?? 307,
                        'nofollow'             => $row['nofollow'] ?? false,
                        'sponsored'            => $row['sponsored'] ?? false,
                        'parameter_forwarding' => $row['parameter_forwarding'] ?? false,
                        'enabled'              => true,
                    ));

                case 'url-shortify':
                    return array_merge($defaults, array(
                        'source_id'            => $row['id'] ?? '',
                        'label'                => $row['name'] ?? '',
                        'target_url'           => $row['url'] ?? '',
                        'short_slug'           => $this->get_first_row_value($row, array('slug')),
                        'redirect_method'      => $row['redirect_type'] ?? 302,
                        'nofollow'             => $row['nofollow'] ?? false,
                        'sponsored'            => $row['sponsored'] ?? false,
                        'parameter_forwarding' => $row['params_forwarding'] ?? false,
                        'enabled'              => ! empty($row['status']),
                        'notes'                => $row['description'] ?? '',
                        'expiration_date'      => $row['expires_at'] ?? '',
                    ));

                case 'thirstyaffiliates':
                    $post_id = (int) ($row['ID'] ?? 0);
                    $prefix = (string) get_option('ta_link_prefix_custom', '');
                    $slug = $row['post_name'] ?? '';
                    if ('' !== $prefix) {
                        $slug = trim($prefix, '/') . '/' . $slug;
                    }

                    $nofollow = get_post_meta($post_id, '_ta_no_follow', true);
                    $nofollow = 'global' === $nofollow ? get_option('ta_no_follow', false) : $nofollow;
                    $redirect_type = get_post_meta($post_id, '_ta_redirect_type', true);
                    $redirect_type = 'global' === $redirect_type ? get_option('ta_link_redirect_type', 302) : $redirect_type;
                    $parameter_forwarding = get_post_meta($post_id, '_ta_pass_query_str', true);
                    $parameter_forwarding = 'global' === $parameter_forwarding ? get_option('ta_pass_query_str', false) : $parameter_forwarding;

                    return array_merge($defaults, array(
                        'source_id'            => $post_id,
                        'label'                => $row['post_title'] ?? '',
                        'target_url'           => get_post_meta($post_id, '_ta_destination_url', true),
                        'short_slug'           => $slug,
                        'redirect_method'      => $redirect_type,
                        'nofollow'             => $nofollow,
                        'parameter_forwarding' => $parameter_forwarding,
                        'enabled'              => 'publish' === ($row['post_status'] ?? 'publish'),
                        'expiration_date'      => get_post_meta($post_id, '_ta_link_expire_date', true),
                    ));

                case 'linkcentral':
                    $post_id = (int) ($row['ID'] ?? 0);
                    $redirect_type = get_post_meta($post_id, '_linkcentral_redirection_type', true);
                    $redirect_type = 'default' === $redirect_type || '' === $redirect_type ? get_option('linkcentral_global_redirection_type', 307) : $redirect_type;

                    return array_merge($defaults, array(
                        'source_id'            => $post_id,
                        'label'                => $row['post_title'] ?? '',
                        'target_url'           => get_post_meta($post_id, '_linkcentral_destination_url', true),
                        'short_slug'           => $row['post_name'] ?? '',
                        'redirect_method'      => $redirect_type,
                        'nofollow'             => $this->parse_linkcentral_global_value(get_post_meta($post_id, '_linkcentral_nofollow', true), 'linkcentral_global_nofollow'),
                        'sponsored'            => $this->parse_linkcentral_global_value(get_post_meta($post_id, '_linkcentral_sponsored', true), 'linkcentral_global_sponsored'),
                        'parameter_forwarding' => $this->parse_linkcentral_global_value(get_post_meta($post_id, '_linkcentral_parameter_forwarding', true), 'linkcentral_global_parameter_forwarding'),
                        'enabled'              => 'publish' === ($row['post_status'] ?? 'publish'),
                    ));
            }

            return $defaults;
        }

        private function get_first_row_value(array $row, array $keys)
        {
            foreach ($keys as $key) {
                if (isset($row[$key]) && '' !== trim((string) $row[$key])) {
                    return $row[$key];
                }
            }

            return '';
        }

        private function parse_linkcentral_global_value($value, $option_name)
        {
            if ('default' === $value || '' === $value) {
                return get_option($option_name, false);
            }

            return $value;
        }

        private function find_existing_link_id($slug)
        {
            global $wpdb;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration checks existing shortlink slugs directly before creating/updating records.
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = 'tiny_slug'
                AND pm.meta_value = %s
                AND p.post_type = 'tinypress_link'
                ORDER BY p.ID DESC
                LIMIT 1",
                $slug
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        private function sanitize_migration_slug($slug)
        {
            $slug = trim((string) $slug);

            if ('' === $slug) {
                return '';
            }

            $parsed_url = wp_parse_url($slug);
            if (is_array($parsed_url) && ! empty($parsed_url['host'])) {
                $slug = isset($parsed_url['path']) ? $parsed_url['path'] : '';
            }

            $slug = str_replace('\\', '/', $slug);
            $slug = preg_replace('/[?#].*$/', '', $slug);
            $slug = trim($slug, " \t\n\r\0\x0B/");

            if ('' === $slug) {
                return '';
            }

            $prefix_settings = function_exists('tinypress_get_link_prefix_settings') ? tinypress_get_link_prefix_settings() : array();
            $prefix = ! empty($prefix_settings['enabled']) && ! empty($prefix_settings['slug']) ? trim((string) $prefix_settings['slug'], '/') : '';

            if ('' !== $prefix && 0 === strpos($slug . '/', $prefix . '/')) {
                $slug = ltrim(substr($slug, strlen($prefix)), '/');
            }

            $slug_parts = array_filter(array_map('sanitize_title', explode('/', $slug)), 'strlen');

            return implode('/', $slug_parts);
        }

        private function parse_boolean($value)
        {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'on', 'enabled'), true);
        }

        private function parse_redirect_method($value)
        {
            $method = absint($value);

            return in_array($method, array(301, 302, 307), true) ? $method : 302;
        }
    }
}
