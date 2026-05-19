<?php

/**
 * PublishPress Shortlinks - Bulk Import/Export Module
 *
 * Provides CSV import and export for shortlinks.
 *
 * @package publishpress-shortlinks
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Import_Export')) {

    class TINYPRESS_Import_Export
    {
        public static $instance;

        public function __construct()
        {
            add_action('admin_menu', array( $this, 'add_submenu_page' ), 20);
            add_action('admin_init', array( $this, 'handle_export' ));
            add_action('wp_ajax_tinypress_import_csv', array( $this, 'handle_import' ));
            add_action('admin_enqueue_scripts', array( $this, 'enqueue_assets' ));
        }

        public static function get_instance()
        {
            if (! isset(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Add Import/Export submenu page.
         */
        public function add_submenu_page()
        {
            add_submenu_page(
                'edit.php?post_type=tinypress_link',
                esc_html__('Import / Export', 'tinypress'),
                esc_html__('Import / Export', 'tinypress'),
                'manage_options',
                'tinypress-import-export',
                array( $this, 'render_page' )
            );
        }

        /**
         * Enqueue assets on the import/export page.
         */
        public function enqueue_assets()
        {
            $screen = get_current_screen();
            if (! $screen || $screen->id !== 'tinypress_link_page_tinypress-import-export') {
                return;
            }

            wp_enqueue_style(
                'tinypress-import-export',
                TINYPRESS_PLUGIN_URL . 'assets/admin/css/import-export.css',
                array(),
                TINYPRESS_PLUGIN_VERSION
            );

            wp_enqueue_script(
                'tinypress-import-export',
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/import-export.js',
                array( 'jquery' ),
                TINYPRESS_PLUGIN_VERSION,
                true
            );

            wp_localize_script('tinypress-import-export', 'tinypressImportExport', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('tinypress_import_csv'),
                'i18n'     => array(
                    'importing'      => esc_html__('Importing...', 'tinypress'),
                    'import_done'    => esc_html__('Import complete!', 'tinypress'),
                    'import_error'   => esc_html__('Import failed. Please check the file and try again.', 'tinypress'),
                    'no_file'        => esc_html__('Please select a CSV file.', 'tinypress'),
                    'confirm_import' => esc_html__('This will import shortlinks from the CSV file. Continue?', 'tinypress'),
                ),
            ));
        }

        /**
         * Handle CSV export via admin_init (before headers are sent).
         */
        public function handle_export()
        {
            if (! isset($_GET['tinypress_export_csv']) || $_GET['tinypress_export_csv'] !== '1') {
                return;
            }

            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Unauthorized.', 'tinypress'));
            }

            if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'tinypress_export_csv')) {
                wp_die(esc_html__('Security check failed.', 'tinypress'));
            }

            $this->export_csv();
        }

        /**
         * Prevent CSV formula injection by prefixing dangerous leading characters.
         *
         * @param string $value Cell value to sanitize.
         * @return string Sanitized value safe for CSV export.
         */
        private function sanitize_csv_cell($value)
        {
            $value = (string) $value;
            if ('' !== $value && in_array($value[0], array( '=', '+', '-', '@', "\t", "\r" ), true)) {
                $value = "\t" . $value;
            }
            return $value;
        }

        /**
         * Export all shortlinks as CSV.
         */
        private function export_csv()
        {
            $links = get_posts(array(
                'post_type'      => 'tinypress_link',
                'post_status'    => array( 'publish', 'draft', 'tinypress_suspended' ),
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ));

            $filename = 'shortlinks-export-' . gmdate('Y-m-d-His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $output = fopen('php://output', 'w');

            // CSV header row
            // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
            fputcsv($output, array(
                'label',
                'target_url',
                'short_slug',
                'status',
                'redirect_method',
                'nofollow',
                'sponsored',
                'parameter_forwarding',
                'password_protected',
                'expiration_date',
                'expired_redirect_url',
                'notes',
                'date_created',
            ));

            foreach ($links as $link) {
                $link_status = get_post_meta($link->ID, 'link_status', true);
                if ('' === $link_status) {
                    $link_status = '1';
                }

                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
                fputcsv($output, array(
                    $this->sanitize_csv_cell($link->post_title),
                    get_post_meta($link->ID, 'target_url', true),
                    get_post_meta($link->ID, 'tiny_slug', true),
                    $link_status === '1' ? 'enabled' : 'disabled',
                    get_post_meta($link->ID, 'redirection_method', true) ?: '302',
                    get_post_meta($link->ID, 'redirection_no_follow', true) === '1' ? 'yes' : 'no',
                    get_post_meta($link->ID, 'redirection_sponsored', true) === '1' ? 'yes' : 'no',
                    get_post_meta($link->ID, 'redirection_parameter_forwarding', true) === '1' ? 'yes' : 'no',
                    get_post_meta($link->ID, 'password_protection', true) === '1' ? 'yes' : 'no',
                    get_post_meta($link->ID, 'expiration_date', true),
                    get_post_meta($link->ID, 'expired_redirect_url', true),
                    $this->sanitize_csv_cell(get_post_meta($link->ID, 'tiny_notes', true)),
                    $link->post_date,
                ));
            }

            fclose($output);
            die();
        }

        /**
         * Handle CSV import via AJAX.
         */
        public function handle_import()
        {
            check_ajax_referer('tinypress_import_csv', 'nonce');

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array( 'message' => esc_html__('Unauthorized.', 'tinypress') ));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(array( 'message' => esc_html__('No file uploaded or upload error.', 'tinypress') ));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $file = $_FILES['csv_file'];

            // Validate file type
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'csv') {
                wp_send_json_error(array( 'message' => esc_html__('Please upload a CSV file.', 'tinypress') ));
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $handle = fopen($file['tmp_name'], 'r');
            if (! $handle) {
                wp_send_json_error(array( 'message' => esc_html__('Could not read the file.', 'tinypress') ));
            }

            // Read header row
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if (! $header) {
                fclose($handle);
                wp_send_json_error(array( 'message' => esc_html__('Empty CSV file.', 'tinypress') ));
            }

            // Normalize headers
            $header = array_map('trim', $header);
            $header = array_map('strtolower', $header);

            // Map columns — required: target_url; optional: label, short_slug, etc.
            $col_map = array_flip($header);

            if (! isset($col_map['target_url'])) {
                fclose($handle);
                wp_send_json_error(array( 'message' => esc_html__('CSV must contain a "target_url" column.', 'tinypress') ));
            }

            $imported = 0;
            $skipped  = 0;
            $errors   = array();

            while (( $row = fgetcsv($handle, 0, ',', '"', '\\') ) !== false) {
                $data = array();
                foreach ($col_map as $col_name => $col_index) {
                    $data[ $col_name ] = isset($row[ $col_index ]) ? trim($row[ $col_index ]) : '';
                }

                $target_url = esc_url_raw($data['target_url']);
                if (empty($target_url)) {
                    $skipped++;
                    continue;
                }

                $label = ! empty($data['label']) ? sanitize_text_field($data['label']) : $target_url;
                $slug  = ! empty($data['short_slug']) ? sanitize_title($data['short_slug']) : '';

                // If slug provided, check for uniqueness
                if (! empty($slug)) {
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tiny_slug' AND meta_value = %s LIMIT 1",
                        $slug
                    ));
                    if ($existing) {
                        $slug = ''; // Will generate a new one
                    }
                }

                if (empty($slug)) {
                    $slug = tinypress_create_url_slug();
                }

                $post_status = 'publish';
                if (isset($data['status']) && $data['status'] === 'disabled') {
                    $post_status = 'draft';
                }

                $link_id = wp_insert_post(array(
                    'post_title'  => $label,
                    'post_type'   => 'tinypress_link',
                    'post_status' => $post_status,
                ));

                if (is_wp_error($link_id)) {
                    $errors[] = sprintf(
                        /* translators: %s: target URL */
                        esc_html__('Failed to import: %s', 'tinypress'),
                        $target_url
                    );
                    continue;
                }

                update_post_meta($link_id, 'target_url', $target_url);
                update_post_meta($link_id, 'tiny_slug', $slug);
                update_post_meta($link_id, 'link_status', $post_status === 'publish' ? '1' : '0');

                // Redirect method
                $redirect_method = ! empty($data['redirect_method']) ? absint($data['redirect_method']) : 302;
                if (! in_array($redirect_method, array( 301, 302, 307 ), true)) {
                    $redirect_method = 302;
                }
                update_post_meta($link_id, 'redirection_method', $redirect_method);

                // Boolean fields
                if (isset($data['nofollow']) && strtolower($data['nofollow']) === 'yes') {
                    update_post_meta($link_id, 'redirection_no_follow', '1');
                }
                if (isset($data['sponsored']) && strtolower($data['sponsored']) === 'yes') {
                    update_post_meta($link_id, 'redirection_sponsored', '1');
                }
                if (isset($data['parameter_forwarding']) && strtolower($data['parameter_forwarding']) === 'yes') {
                    update_post_meta($link_id, 'redirection_parameter_forwarding', '1');
                }
                if (isset($data['password_protected']) && strtolower($data['password_protected']) === 'yes') {
                    update_post_meta($link_id, 'password_protection', '1');
                }

                // Optional text fields
                if (! empty($data['expiration_date'])) {
                    update_post_meta($link_id, 'enable_expiration', '1');
                    update_post_meta($link_id, 'expiration_date', sanitize_text_field($data['expiration_date']));
                }
                if (! empty($data['expired_redirect_url'])) {
                    update_post_meta($link_id, 'expired_redirect_url', esc_url_raw($data['expired_redirect_url']));
                }
                if (! empty($data['notes'])) {
                    update_post_meta($link_id, 'tiny_notes', sanitize_textarea_field($data['notes']));
                }

                $imported++;
            }

            fclose($handle);

            wp_send_json_success(array(
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
                'message'  => sprintf(
                    /* translators: 1: imported count, 2: skipped count */
                    esc_html__('Successfully imported %1$d shortlink(s). %2$d skipped.', 'tinypress'),
                    $imported,
                    $skipped
                ),
            ));
        }

        /**
         * Render the Import/Export admin page.
         */
        public function render_page()
        {
            $export_url = wp_nonce_url(
                add_query_arg(array(
                    'post_type'           => 'tinypress_link',
                    'page'                => 'tinypress-import-export',
                    'tinypress_export_csv' => '1',
                ), admin_url('edit.php')),
                'tinypress_export_csv'
            );

            $total_links = wp_count_posts('tinypress_link');
            $count = isset($total_links->publish) ? (int) $total_links->publish : 0;
            ?>
            <div class="wrap tinypress-import-export-wrap">
                <h1><?php esc_html_e('Import / Export Shortlinks', 'tinypress'); ?></h1>

                <div class="tinypress-ie-grid">
                    <!-- Export Card -->
                    <div class="tinypress-ie-card">
                        <div class="tinypress-ie-card-header">
                            <span class="dashicons dashicons-download"></span>
                            <h2><?php esc_html_e('Export', 'tinypress'); ?></h2>
                        </div>
                        <div class="tinypress-ie-card-body">
                            <p><?php esc_html_e('Download all your shortlinks as a CSV file. The export includes labels, target URLs, slugs, redirect settings, and more.', 'tinypress'); ?></p>
                            <p class="tinypress-ie-count">
                                <?php
                                printf(
                                    /* translators: %d: number of shortlinks */
                                    esc_html__('%d shortlink(s) will be exported.', 'tinypress'),
                                    (int) $count
                                );
                                ?>
                            </p>
                            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary button-hero">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Export CSV', 'tinypress'); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Import Card -->
                    <div class="tinypress-ie-card">
                        <div class="tinypress-ie-card-header">
                            <span class="dashicons dashicons-upload"></span>
                            <h2><?php esc_html_e('Import', 'tinypress'); ?></h2>
                        </div>
                        <div class="tinypress-ie-card-body">
                            <p><?php esc_html_e('Upload a CSV file to bulk-create shortlinks. The CSV must contain at minimum a "target_url" column.', 'tinypress'); ?></p>
                            <p class="tinypress-ie-columns-info">
                                <strong><?php esc_html_e('Supported columns:', 'tinypress'); ?></strong><br>
                                <code>label</code>, <code>target_url</code>, <code>short_slug</code>, <code>status</code>, <code>redirect_method</code>, <code>nofollow</code>, <code>sponsored</code>, <code>parameter_forwarding</code>, <code>password_protected</code>, <code>expiration_date</code>, <code>expired_redirect_url</code>, <code>notes</code>
                            </p>
                            <form id="tinypress-import-form" enctype="multipart/form-data">
                                <input type="file" name="csv_file" id="tinypress-csv-file" accept=".csv">
                                <button type="submit" class="button button-primary button-hero" id="tinypress-import-btn">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php esc_html_e('Import CSV', 'tinypress'); ?>
                                </button>
                            </form>
                            <div id="tinypress-import-result" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
