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
            add_action('wp_ajax_tinypress_preview_import', array( $this, 'handle_preview_import' ));
            add_action('wp_ajax_tinypress_get_field_mapping', array( $this, 'handle_get_field_mapping' ));
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
                'preview_nonce' => wp_create_nonce('tinypress_preview_import'),
                'field_mapping_nonce' => wp_create_nonce('tinypress_get_field_mapping'),
                'i18n'     => array(
                    'importing'      => esc_html__('Importing...', 'tinypress'),
                    'import_done'    => esc_html__('Import complete!', 'tinypress'),
                    'import_error'   => esc_html__('Import failed. Please check the file and try again.', 'tinypress'),
                    'no_file'        => esc_html__('Please select a CSV file.', 'tinypress'),
                    'confirm_import' => esc_html__('This will import shortlinks from the CSV file. Continue?', 'tinypress'),
                    'previewing'     => esc_html__('Previewing...', 'tinypress'),
                    'preview_error'  => esc_html__('Preview failed.', 'tinypress'),
                    'see_more'       => esc_html__('See More Rows', 'tinypress'),
                    'see_less'       => esc_html__('Show Less', 'tinypress'),
                    'preview'        => esc_html__('Preview', 'tinypress'),
                    'import_csv'     => esc_html__('Import CSV', 'tinypress'),
                    'import_success' => esc_html__('%d shortlinks successfully imported', 'tinypress'),
                    'import_failure' => esc_html__('%d shortlinks failed to import', 'tinypress'),
                    'slug_error'     => esc_html__('Error(s) for TinyPress Slug:', 'tinypress'),
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
            $trimmed_value = ltrim($value, " \t\r\n");

            if ('' !== $trimmed_value && in_array($trimmed_value[0], array( '=', '+', '-', '@' ), true)) {
                $value = "\t" . $value;
            }

            return $value;
        }

        /**
         * Write a CSV row with formula-injection protection applied to every cell.
         *
         * @param resource $handle CSV output handle.
         * @param array    $row    Row values.
         */
        private function write_csv_row($handle, array $row)
        {
            // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
            fputcsv($handle, array_map(array( $this, 'sanitize_csv_cell' ), $row));
        }

        /**
         * Get a plugin setting with a system fallback.
         *
         * @param string $key Setting key.
         * @param mixed  $default Default value.
         * @return mixed
         */
        private function get_settings_value($key, $default = null)
        {
            $settings = get_option('tinypress_settings', array());
            if (is_array($settings) && array_key_exists($key, $settings)) {
                return $settings[$key];
            }

            return Utils::get_option($key, $default);
        }

        /**
         * Return yes/no for a boolean-like value.
         *
         * @param mixed $value Boolean-ish value.
         * @return string
         */
        private function boolean_to_csv($value)
        {
            return $this->parse_boolean($value) ? 'yes' : 'no';
        }

        /**
         * Check whether a per-link toggle is configured to use global settings.
         *
         * @param int    $post_id Link post ID.
         * @param string $setting_key Per-link setting key.
         * @param string $use_global_key Per-link use-global key.
         * @return bool
         */
        private function is_using_global_toggle($post_id, $setting_key, $use_global_key)
        {
            $use_global = get_post_meta($post_id, $use_global_key, true);

            if (in_array($use_global, array( 'enabled', 'disabled' ), true)) {
                return false;
            }

            if (is_array($use_global) && in_array('1', $use_global, true)) {
                return true;
            }

            if ('1' === (string) $use_global) {
                return true;
            }

            return ! metadata_exists('post', $post_id, $setting_key)
                && ! metadata_exists('post', $post_id, $use_global_key);
        }

        /**
         * Resolve a toggle setting for export, preserving mode and effective value.
         *
         * @param int    $post_id Link post ID.
         * @param string $setting_key Per-link setting key.
         * @param string $use_global_key Per-link use-global key.
         * @param string $global_key Global setting key.
         * @param mixed  $default System default.
         * @return array
         */
        private function get_toggle_export_value($post_id, $setting_key, $use_global_key, $global_key, $default)
        {
            $mode = $this->is_using_global_toggle($post_id, $setting_key, $use_global_key) ? 'use_global' : 'custom';

            if ('use_global' === $mode) {
                $value = $this->get_settings_value($global_key, $default);
            } elseif ('enabled' === get_post_meta($post_id, $use_global_key, true)) {
                $value = true;
            } elseif ('disabled' === get_post_meta($post_id, $use_global_key, true)) {
                $value = false;
            } else {
                $value = get_post_meta($post_id, $setting_key, true);
            }

            return array(
                'mode'  => $mode,
                'value' => $this->boolean_to_csv($value),
            );
        }

        /**
         * Resolve redirect method mode and effective value for export.
         *
         * @param int $post_id Link post ID.
         * @return array
         */
        private function get_redirect_method_export_value($post_id)
        {
            $method = get_post_meta($post_id, 'redirection_method', true);

            if ('' === $method) {
                return array(
                    'mode'  => 'use_global',
                    'value' => (string) $this->parse_redirect_method(
                        $this->get_settings_value('tinypress_global_redirection_method', 302),
                        302
                    ),
                );
            }

            return array(
                'mode'  => 'custom',
                'value' => (string) $this->parse_redirect_method($method, 302),
            );
        }

        /**
         * Get autolink keywords as a comma-separated CSV value.
         *
         * @param int $post_id Link post ID.
         * @return string
         */
        private function get_autolink_keywords_for_csv($post_id)
        {
            $autolink_keywords = get_post_meta($post_id, 'autolink_keywords', true);

            if (empty($autolink_keywords)) {
                $nested_data = get_post_meta($post_id, 'tinypress_meta_side_tinypress_link', true);
                if (is_array($nested_data) && isset($nested_data['autolink_keywords'])) {
                    $autolink_keywords = $nested_data['autolink_keywords'];
                }
            }

            if (is_array($autolink_keywords)) {
                return implode(',', array_map('trim', $autolink_keywords));
            }

            return str_replace(array( "\r\n", "\r", "\n" ), ',', (string) $autolink_keywords);
        }

        /**
         * Validate target URLs consistently with normal shortlink creation.
         *
         * @param string $url Raw URL.
         * @return string|WP_Error
         */
        private function validate_target_url($url)
        {
            if (function_exists('tinypress_validate_target_url')) {
                return tinypress_validate_target_url($url);
            }

            return new WP_Error('invalid_url', esc_html__('Invalid URL.', 'tinypress'));
        }

        /**
         * Supported TinyPress import fields. Missing fields stay null; empty cells stay ''.
         *
         * @return array
         */
        private function get_supported_import_fields()
        {
            return array(
                'label',
                'target_url',
                'short_slug',
                'post_status',
                'link_status',
                'redirect_method',
                'nofollow',
                'sponsored',
                'parameter_forwarding',
                'expiration_enabled',
                'expiration_date',
                'expiration_time',
                'expired_redirect_url',
                'notes',
                'autolink_keywords',
            );
        }

        /**
         * Build a row array with null for missing fields and strings for present cells.
         *
         * @param array $row CSV row.
         * @param array $col_map Mapped columns.
         * @return array
         */
        private function normalize_import_row(array $row, array $col_map)
        {
            $data = array_fill_keys($this->get_supported_import_fields(), null);

            foreach ($col_map as $col_name => $col_index) {
                if (! array_key_exists($col_name, $data)) {
                    continue;
                }

                $data[ $col_name ] = isset($row[ $col_index ]) ? trim((string) $row[ $col_index ]) : '';
            }

            return $data;
        }

        /**
         * Check whether an import field existed in the CSV header.
         *
         * @param array  $data Normalized row.
         * @param string $field Field key.
         * @return bool
         */
        private function import_field_exists(array $data, $field)
        {
            return array_key_exists($field, $data) && null !== $data[ $field ];
        }

        /**
         * Parse a mode column.
         *
         * @param mixed $value Raw mode value.
         * @return string
         */
        private function parse_setting_mode($value)
        {
            $value = strtolower(trim((string) $value));

            if (in_array($value, array( 'global', 'use_global', 'use global', 'use-global' ), true)) {
                return 'use_global';
            }

            if (in_array($value, array( 'custom', 'override', 'per_link', 'per-link' ), true)) {
                return 'custom';
            }

            return '';
        }

        /**
         * Apply a toggle setting from import data.
         *
         * @param int    $link_id Link post ID.
         * @param array  $data Normalized row.
         * @param string $value_field Value field key.
         * @param string $setting_key Per-link setting key.
         * @param string $use_global_key Per-link use-global key.
         */
        private function apply_imported_toggle($link_id, array $data, $value_field, $setting_key, $use_global_key)
        {
            update_post_meta($link_id, $use_global_key, array());

            if ($this->import_field_exists($data, $value_field)) {
                update_post_meta($link_id, $setting_key, $this->parse_boolean($data[ $value_field ]) ? '1' : '0');
            }
        }

        /**
         * Export all shortlinks as CSV.
         *
         */
        private function export_csv()
        {
            $filename = 'shortlinks-export-' . gmdate('Y-m-d-His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $output = fopen('php://output', 'w');

            $this->write_csv_row($output, array(
                'label',
                'target_url',
                'short_slug',
                'post_status',
                'link_status',
                'redirect_method',
                'nofollow',
                'sponsored',
                'parameter_forwarding',
                'expiration_enabled',
                'expiration_date',
                'expiration_time',
                'expired_redirect_url',
                'notes',
                'autolink_keywords',
                'date_created',
            ));

            $paged    = 1;
            $per_page = 500;

            do {
                $link_ids = get_posts(array(
                    'post_type'      => 'tinypress_link',
                    'post_status'    => array( 'publish', 'draft', 'tinypress_suspended' ),
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'fields'         => 'ids',
                ));

                foreach ($link_ids as $link_id) {
                    $link = get_post($link_id);
                    if (! $link) {
                        continue;
                    }

                    $redirect_method      = $this->get_redirect_method_export_value($link->ID);
                    $nofollow             = $this->get_toggle_export_value($link->ID, 'redirection_no_follow', 'redirection_no_follow_use_global', 'tinypress_global_no_follow', true);
                    $sponsored            = $this->get_toggle_export_value($link->ID, 'redirection_sponsored', 'redirection_sponsored_use_global', 'tinypress_global_sponsored', false);
                    $parameter_forwarding = $this->get_toggle_export_value($link->ID, 'redirection_parameter_forwarding', 'redirection_parameter_forwarding_use_global', 'tinypress_global_parameter_forwarding', false);
                    $expiration           = $this->get_toggle_export_value($link->ID, 'enable_expiration', 'enable_expiration_use_global', 'tinypress_global_enable_expiration', false);
                    $autolink_keywords    = $this->get_autolink_keywords_for_csv($link->ID);

                    $link_status = get_post_meta($link->ID, 'link_status', true);
                    if ('' === $link_status) {
                        $link_status = '1';
                    }

                    $expiration_date = get_post_meta($link->ID, 'expiration_date', true);
                    $expiration_time = get_post_meta($link->ID, 'expiration_time', true);
                    if ('use_global' === $expiration['mode']) {
                        $expiration_date = $this->get_settings_value('tinypress_global_expiration_date', $expiration_date);
                        $expiration_time = $this->get_settings_value('tinypress_global_expiration_time', $expiration_time);
                    }

                    $this->write_csv_row($output, array(
                        $link->post_title,
                        get_post_meta($link->ID, 'target_url', true),
                        get_post_meta($link->ID, 'tiny_slug', true),
                        $link->post_status,
                        '1' === (string) $link_status ? 'enabled' : 'disabled',
                        $redirect_method['value'],
                        $nofollow['value'],
                        $sponsored['value'],
                        $parameter_forwarding['value'],
                        $expiration['value'],
                        $expiration_date,
                        $expiration_time,
                        get_post_meta($link->ID, 'expired_redirect_url', true),
                        get_post_meta($link->ID, 'tiny_notes', true),
                        $autolink_keywords,
                        $link->post_date,
                    ));
                }

                $paged++;
            } while (count($link_ids) === $per_page);

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
            $mapped_headers = $this->map_common_columns($header);

            // Create col_map using mapped column names
            $col_map = array();
            $detected_target_url = false;

            foreach ($mapped_headers as $idx => $mapping) {
                $mapped_name = strtolower($mapping['mapped']);
                $col_map[$mapped_name] = $idx;

                if ($mapped_name === 'target_url') {
                    $detected_target_url = true;
                }
            }

            if (! $detected_target_url) {
                fclose($handle);
                wp_send_json_error(array(
                    'message' => esc_html__('CSV must contain a column for target URL (e.g., "url", "target_url", "destination_url").', 'tinypress')
                ));
            }

            $imported = 0;
            $updated  = 0;
            $failed   = 0;
            $errors   = array();

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $data = $this->normalize_import_row($row, $col_map);

                $target_url = $this->validate_target_url($data['target_url']);
                if (is_wp_error($target_url)) {
                    $failed++;
                    $errors[] = array(
                        'slug'   => $this->import_field_exists($data, 'short_slug') ? sanitize_title($data['short_slug']) : 'unknown',
                        'reason' => $target_url->get_error_message(),
                    );
                    continue;
                }

                $label = $this->import_field_exists($data, 'label') && '' !== $data['label'] ? sanitize_text_field($data['label']) : $target_url;
                $slug  = $this->import_field_exists($data, 'short_slug') && '' !== $data['short_slug'] ? sanitize_title($data['short_slug']) : '';

                $link_id = null;

                if (! empty($slug)) {
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tiny_slug' AND meta_value = %s LIMIT 1",
                        $slug
                    ));
                    if ($existing) {
                        $link_id = (int) $existing;
                        $is_update = true;
                    }
                }

                if (!$link_id) {
                    if (empty($slug)) {
                        $slug = tinypress_create_url_slug();
                    }

                    $post_status = $this->parse_post_status($this->import_field_exists($data, 'post_status') ? $data['post_status'] : null);
                    $link_status = $this->parse_link_status($this->import_field_exists($data, 'link_status') ? $data['link_status'] : null);

                    $link_id = wp_insert_post(array(
                        'post_title'  => $label,
                        'post_type'   => 'tinypress_link',
                        'post_status' => $post_status,
                        'post_author' => get_current_user_id(),
                    ));

                    if (is_wp_error($link_id) || empty($link_id)) {
                        $failed++;
                        $errors[] = array(
                            'slug'   => ! empty($slug) ? $slug : 'unknown',
                            'reason' => sprintf(
                                /* translators: %s: error message */
                                __('Failed to create shortlink: %s', 'tinypress'),
                                is_wp_error($link_id) ? $link_id->get_error_message() : esc_html__('Unknown error.', 'tinypress')
                            ),
                        );
                        continue;
                    }
                    $is_update = false;
                } else {
                    wp_update_post(array(
                        'ID'         => $link_id,
                        'post_title' => $label,
                    ));
                }

                update_post_meta($link_id, 'target_url', $target_url);
                update_post_meta($link_id, 'tiny_slug', $slug);
                update_post_meta($link_id, 'link_status', $link_status ?? $this->parse_link_status(null));

                if ($this->import_field_exists($data, 'redirect_method') && '' !== $data['redirect_method']) {
                    update_post_meta($link_id, 'redirection_method', $this->parse_redirect_method($data['redirect_method'], 302));
                } else {
                    update_post_meta($link_id, 'redirection_method', '');
                }

                $this->apply_imported_toggle($link_id, $data, 'nofollow', 'redirection_no_follow', 'redirection_no_follow_use_global');
                $this->apply_imported_toggle($link_id, $data, 'sponsored', 'redirection_sponsored', 'redirection_sponsored_use_global');
                $this->apply_imported_toggle($link_id, $data, 'parameter_forwarding', 'redirection_parameter_forwarding', 'redirection_parameter_forwarding_use_global');

                update_post_meta($link_id, 'enable_expiration_use_global', array());
                $expiration_enabled = $this->import_field_exists($data, 'expiration_enabled')
                    ? $this->parse_boolean($data['expiration_enabled'])
                    : ($this->import_field_exists($data, 'expiration_date') && '' !== $data['expiration_date']);
                update_post_meta($link_id, 'enable_expiration', $expiration_enabled ? '1' : '0');

                if ($this->import_field_exists($data, 'expiration_date')) {
                    update_post_meta($link_id, 'expiration_date', sanitize_text_field($data['expiration_date']));
                }
                if ($this->import_field_exists($data, 'expiration_time')) {
                    update_post_meta($link_id, 'expiration_time', sanitize_text_field($data['expiration_time']));
                }
                if ($this->import_field_exists($data, 'expired_redirect_url')) {
                    update_post_meta($link_id, 'expired_redirect_url', '' === $data['expired_redirect_url'] ? '' : esc_url_raw($data['expired_redirect_url']));
                }
                if ($this->import_field_exists($data, 'notes')) {
                    update_post_meta($link_id, 'tiny_notes', sanitize_textarea_field($data['notes']));
                }

                // Autolink keywords
                if ($this->import_field_exists($data, 'autolink_keywords')) {
                    $keywords = array_map('trim', explode(',', $data['autolink_keywords']));
                    $keywords = array_filter($keywords);
                    update_post_meta($link_id, 'autolink_keywords', ! empty($keywords) ? implode("\n", $keywords) : '');
                }

                if ($is_update) {
                    $updated++;
                } else {
                    $imported++;
                }
            }

            fclose($handle);

            // Build message with import results
            $message = '';
            if ($imported > 0) {
                $message .= sprintf(
                    /* translators: %d: number of successfully imported shortlinks */
                    esc_html__('%d shortlinks successfully imported', 'tinypress'),
                    $imported
                );
            }

            if ($updated > 0) {
                if (! empty($message)) {
                    $message .= ' | ';
                }
                $message .= sprintf(
                    /* translators: %d: number of updated shortlinks */
                    esc_html__('%d shortlinks updated', 'tinypress'),
                    $updated
                );
            }

            if ($failed > 0) {
                if (! empty($message)) {
                    $message .= ' | ';
                }
                $message .= sprintf(
                    /* translators: %d: number of failed imports */
                    esc_html__('%d shortlinks failed to import', 'tinypress'),
                    $failed
                );
            }

            wp_send_json_success(array(
                'imported' => $imported,
                'updated'  => $updated,
                'failed'   => $failed,
                'errors'   => $errors,
                'message'  => $message,
            ));
        }

        /**
         * Parse a boolean value from various formats.
         *
         * @param mixed $value Value to parse.
         * @return bool True/false based on the value.
         */
        private function parse_boolean($value)
        {
            if (empty($value)) {
                return false;
            }

            $value_lower = strtolower((string) $value);

            // Check for various truthy formats
            return in_array($value_lower, array( '1', 'yes', 'true', 'on', 'y' ), true);
        }

        /**
         * Parse redirect method from various formats.
         *
         * @param mixed $value Value to parse.
         * @return int Valid redirect method code.
         */
        private function parse_redirect_method($value, $default = 302)
        {
            if (empty($value)) {
                return $default;
            }

            // Extract numeric value
            $method = (int) $value;

            // Validate it's a supported method
            if (! in_array($method, array( 301, 302, 307 ), true)) {
                return $default;
            }

            return $method;
        }

        /**
         * Parse WordPress post status.
         *
         * @param mixed $value Value to parse.
         * @return string
         */
        private function parse_post_status($value)
        {
            if (empty($value)) {
                return 'publish';
            }

            $value_lower = strtolower(sanitize_key((string) $value));
            $allowed     = array( 'publish', 'draft', 'pending', 'private', 'future', 'tinypress_suspended' );

            return in_array($value_lower, $allowed, true) ? $value_lower : 'publish';
        }

        /**
         * Parse link enabled/disabled status.
         *
         * @param mixed $value Value to parse.
         * @return string
         */
        private function parse_link_status($value)
        {
            if (null === $value) {
                return '1';
            }

            if ('' === trim((string) $value)) {
                return '';
            }

            return $this->parse_boolean($value) || in_array(strtolower((string) $value), array( 'enabled', 'active' ), true) ? '1' : '0';
        }

        /**
         * Map common column name variations to standard TinyPress fields.
         * This allows compatibility with other plugins like Pretty Links.
         *
         * @param array $headers CSV headers.
         * @return array Mapped headers with detected target_url column.
         */
        private function map_common_columns($headers)
        {
            $headers_lower = array_map(function ($header) {
                return strtolower(trim((string) $header));
            }, $headers);

            // Column name variations mapping
            $column_aliases = array(
                'target_url'       => array( 'target_url', 'target', 'url', 'destination_url', 'destination', 'redirect_url', 'link_url', 'forward_url' ),
                'label'            => array( 'label', 'title', 'name', 'link_name', 'text' ),
                'short_slug'       => array( 'short_slug', 'slug', 'short_code', 'short_link', 'code' ),
                'post_status'      => array( 'post_status', 'wp_status' ),
                'link_status'      => array( 'status', 'link_status', 'enabled', 'active' ),
                'redirect_method'  => array( 'redirect_method', 'redirect_type', 'method' ),
                'nofollow'         => array( 'nofollow', 'no_follow', 'rel_nofollow' ),
                'sponsored'        => array( 'sponsored', 'is_sponsored' ),
                'parameter_forwarding' => array( 'parameter_forwarding', 'param_forwarding', 'forward_params' ),
                'expiration_enabled' => array( 'expiration_enabled', 'enable_expiration', 'expires', 'is_expiring' ),
                'expiration_date'   => array( 'expiration_date', 'expires_at', 'expiry_date' ),
                'expiration_time'   => array( 'expiration_time', 'expiry_time' ),
                'expired_redirect_url' => array( 'expired_redirect_url', 'expiration_redirect_url', 'expired_url' ),
                'notes'            => array( 'notes', 'description', 'comment' ),
                'autolink_keywords' => array( 'autolink_keywords', 'keywords', 'tags' ),
            );

            $mapped_headers = array();
            foreach ($headers as $idx => $original_header) {
                $header_lower = $headers_lower[$idx];
                $mapped_name = $original_header;

                // Check if this header matches any of our aliases
                foreach ($column_aliases as $standard_name => $aliases) {
                    if (in_array($header_lower, $aliases, true)) {
                        $mapped_name = $standard_name;
                        break;
                    }
                }

                $mapped_headers[$idx] = array(
                    'original' => $original_header,
                    'mapped'   => $mapped_name,
                );
            }

            return $mapped_headers;
        }

        /**
         * Handle import preview via AJAX.
         */
        public function handle_preview_import()
        {
            check_ajax_referer('tinypress_preview_import', 'nonce');

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
            $original_header = $header;
            $mapped_headers = $this->map_common_columns($header);

            // Create col_map using mapped column names
            $col_map = array();
            $detected_target_url = false;

            foreach ($mapped_headers as $idx => $mapping) {
                $mapped_name = strtolower($mapping['mapped']);
                $col_map[$mapped_name] = $idx;

                if ($mapped_name === 'target_url') {
                    $detected_target_url = true;
                }
            }

            if (! $detected_target_url) {
                fclose($handle);
                wp_send_json_error(array(
                    'message' => esc_html__('CSV must contain a column for target URL (e.g., "url", "target_url", "destination_url").', 'tinypress')
                ));
            }

            $preview_rows  = array();
            $total_rows    = 0;
            $preview_limit = 10;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $data = $this->normalize_import_row($row, $col_map);

                if (is_wp_error($this->validate_target_url($data['target_url']))) {
                    continue;
                }

                $total_rows++;

                if (count($preview_rows) < $preview_limit) {
                    $row_data = array();

                    for ($i = 0; $i < count($original_header); $i++) {
                        $value = isset($row[$i]) ? trim((string) $row[$i]) : '';
                        $row_data[$i] = '' !== $value ? $value : '-';
                    }

                    $preview_rows[] = $row_data;
                }
            }

            fclose($handle);

            // Build field mapping information for display
            $field_mappings = array();
            foreach ($mapped_headers as $idx => $mapping) {
                if ($mapping['original'] !== $mapping['mapped']) {
                    $field_mappings[] = sprintf(
                        /* translators: 1: original column name, 2: mapped field name */
                        esc_html__('"%1$s" → %2$s', 'tinypress'),
                        esc_html($mapping['original']),
                        esc_html($mapping['mapped'])
                    );
                }
            }

            $mapping_message = '';
            if (! empty($field_mappings)) {
                $mapping_message = sprintf(
                    /* translators: %s: field mappings */
                    esc_html__('Field mappings: %s', 'tinypress'),
                    implode(', ', $field_mappings)
                );
            }

            $field_mapping_rows = array();
            foreach ($mapped_headers as $idx => $mapping) {
                if ($mapping['original'] !== $mapping['mapped']) {
                    $field_mapping_rows[] = array(
                        'original' => (string) $mapping['original'],
                        'mapped'   => (string) $mapping['mapped'],
                    );
                }
            }

            wp_send_json_success(array(
                'columns'           => $original_header,
                'mapped_headers'    => $mapped_headers,
                'field_mappings'    => $field_mappings,
                'field_mapping_rows' => $field_mapping_rows,
                'preview'           => $preview_rows,
                'total_rows'        => $total_rows,
                'preview_limit'     => $preview_limit,
                'initial_rows'      => 5,
                'mapping_message'   => $mapping_message,
                'message'           => sprintf(
                    /* translators: 1: number of preview rows, 2: total number of importable rows */
                    esc_html__('Showing %1$d preview row(s) from %2$d importable row(s).', 'tinypress'),
                    count($preview_rows),
                    $total_rows
                ),
            ));
        }

        /**
         * Get available field mapping for Shortlinks import.
         */
        public function handle_get_field_mapping()
        {
            check_ajax_referer('tinypress_get_field_mapping', 'nonce');

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array( 'message' => esc_html__('Unauthorized.', 'tinypress') ));
            }

            $supported_fields = array(
                'label'                    => esc_html__('Link Label', 'tinypress'),
                'target_url'               => esc_html__('Target URL', 'tinypress'),
                'short_slug'               => esc_html__('Short Slug', 'tinypress'),
                'post_status'              => esc_html__('Post Status', 'tinypress'),
                'link_status'              => esc_html__('Link Status', 'tinypress'),
                'redirect_method'          => esc_html__('Redirect Method', 'tinypress'),
                'nofollow'                 => esc_html__('NoFollow', 'tinypress'),
                'sponsored'                => esc_html__('Sponsored', 'tinypress'),
                'parameter_forwarding'     => esc_html__('Parameter Forwarding', 'tinypress'),
                'expiration_enabled'       => esc_html__('Expiration Enabled', 'tinypress'),
                'expiration_date'          => esc_html__('Expiration Date', 'tinypress'),
                'expiration_time'          => esc_html__('Expiration Time', 'tinypress'),
                'expired_redirect_url'     => esc_html__('Expired Redirect URL', 'tinypress'),
                'notes'                    => esc_html__('Notes', 'tinypress'),
                'autolink_keywords'        => esc_html__('Autolink Keywords', 'tinypress'),
            );

            wp_send_json_success(array(
                'fields' => $supported_fields,
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
            $count = 0;
            foreach (array( 'publish', 'draft', 'tinypress_suspended' ) as $status_key) {
                $count += isset($total_links->{$status_key}) ? (int) $total_links->{$status_key} : 0;
            }
            ?>
            <div class="wrap tinypress-import-export-wrap">
                <h1><?php esc_html_e('Import / Export Shortlinks', 'tinypress'); ?></h1>

                <h2 class="nav-tab-wrapper tinypress-ie-tabs">
                    <a href="#tinypress-export-panel" class="nav-tab tinypress-ie-tab nav-tab-active" data-tab="tinypress-export-panel">
                        <?php esc_html_e('Export', 'tinypress'); ?>
                    </a>
                    <a href="#tinypress-import-panel" class="nav-tab tinypress-ie-tab" data-tab="tinypress-import-panel">
                        <?php esc_html_e('Import', 'tinypress'); ?>
                    </a>
                </h2>

                <div class="tinypress-ie-tab-panels">
                    <div id="tinypress-export-panel" class="tinypress-ie-tab-panel is-active">
                    <div class="tinypress-ie-card">
                        <div class="tinypress-ie-card-header">
                            <span class="dashicons dashicons-upload"></span>
                            <h2><?php esc_html_e('Export', 'tinypress'); ?></h2>
                        </div>
                        <div class="tinypress-ie-card-body">
                            <p><?php esc_html_e('Download all your shortlinks as a CSV file. The export includes labels, target URLs, slugs, redirect settings, global-setting modes, and more. Password protection settings are not exported.', 'tinypress'); ?></p>
                            <p class="tinypress-ie-count">
                                <?php
                                printf(
                                /* translators: %d: number of shortlinks */
                                    esc_html__('%d shortlink(s) will be exported.', 'tinypress'),
                                    (int) $count
                                );
                                ?>
                            </p>

                            <a href="<?php echo esc_url($export_url); ?>" class="tinypress-import-export button button-primary button-large">
                                <span class="dashicons dashicons-upload"></span>
                                <?php esc_html_e('Export CSV', 'tinypress'); ?>
                            </a>
                        </div>
                    </div>
                    </div>

                    <div id="tinypress-import-panel" class="tinypress-ie-tab-panel">
                    <div class="tinypress-ie-card">
                        <div class="tinypress-ie-card-header">
                            <span class="dashicons dashicons-download"></span>
                            <h2><?php esc_html_e('Import', 'tinypress'); ?></h2>
                        </div>
                        <div class="tinypress-ie-card-body">
                            <p><?php esc_html_e('Upload a CSV file to bulk-create shortlinks. The CSV must contain at minimum a "target_url" column. Password protection settings are intentionally not imported.', 'tinypress'); ?></p>
                            <p class="tinypress-ie-columns-info">
                                <strong><?php esc_html_e('Supported columns:', 'tinypress'); ?></strong><br>
                                <code>label</code>, <code>target_url</code>, <code>short_slug</code>, <code>post_status</code>, <code>link_status</code>, <code>redirect_method_mode</code>, <code>redirect_method</code>, <code>nofollow_mode</code>, <code>nofollow</code>, <code>sponsored_mode</code>, <code>sponsored</code>, <code>parameter_forwarding_mode</code>, <code>parameter_forwarding</code>, <code>expiration_mode</code>, <code>expiration_enabled</code>, <code>expiration_date</code>, <code>expiration_time</code>, <code>expired_redirect_url</code>, <code>notes</code>, <code>autolink_keywords</code>
                            </p>
                            
                            <div id="tinypress-file-input-section">
                                <form id="tinypress-import-form" enctype="multipart/form-data">
                                    <input type="file" name="csv_file" id="tinypress-csv-file" accept=".csv">
                                    <button type="submit" class="import-button button-primary button-hero" id="tinypress-import-btn">
                                        <span class="dashicons dashicons-download"></span>
                                        <?php esc_html_e('Import CSV', 'tinypress'); ?>
                                    </button>
                                </form>
                            </div>

                            <!-- File Selected Actions -->
                            <div id="tinypress-file-selected-actions" style="display:none;">
                                <button type="button" class="import-preview-button button" id="tinypress-preview-btn">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <?php esc_html_e('Preview', 'tinypress'); ?>
                                </button>
                                <button type="button" class="import-button button button-primary" id="tinypress-import-selected-btn">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Import CSV', 'tinypress'); ?>
                                </button>
                                <button type="button" class="import-preview-button button" id="tinypress-change-file-btn">
                                    <?php esc_html_e('Change File', 'tinypress'); ?>
                                </button>
                            </div>

                            <!-- Progress Bar -->
                            <div id="tinypress-progress-section" style="display:none;">
                                <div class="tinypress-progress-container">
                                    <div class="tinypress-progress-bar">
                                        <div class="tinypress-progress-fill" style="width: 0%"></div>
                                    </div>
                                    <span id="tinypress-progress-text">0%</span>
                                </div>
                            </div>

                            <!-- Preview Section -->
                            <div id="tinypress-preview-section" style="display:none;">
                                <div class="tinypress-preview-header">
                                    <h3><?php esc_html_e('Import Preview', 'tinypress'); ?></h3>
                                    <p id="tinypress-preview-message"></p>
                                </div>
                                <div class="tinypress-preview-table-wrapper">
                                    <table class="tinypress-preview-table">
                                        <thead id="tinypress-preview-thead">
                                        </thead>
                                        <tbody id="tinypress-preview-rows">
                                        </tbody>
                                    </table>
                                </div>
                                <div id="tinypress-expand-container" style="display:none; text-align: center; padding-top: 12px;">
                                    <button type="button" class="import-preview-button button" id="tinypress-expand-table-btn">
                                        <?php esc_html_e('See More Rows', 'tinypress'); ?>
                                    </button>
                                </div>
                                <div class="tinypress-preview-actions">
                                    <button type="button" class="import-preview-button button" id="tinypress-cancel-import-btn">
                                        <?php esc_html_e('Cancel', 'tinypress'); ?>
                                    </button>
                                    <button type="button" class="import-button button-primary" id="tinypress-confirm-import-btn">
                                        <span class="dashicons dashicons-download"></span>
                                        <?php esc_html_e('Import', 'tinypress'); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Import Result Section -->
                            <div id="tinypress-import-result" style="display:none;"></div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
