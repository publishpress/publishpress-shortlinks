<?php

/**
 * PublishPress Statuses Integration Class
 *
 * @author deji98
 */

defined('ABSPATH') || exit;

/**
 * Class TINYPRESS_Statuses
 */
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
class TINYPRESS_Statuses
{
    protected static $_instance = null;

    private const CACHE_GROUP = 'publishpress-shortlinks';
    private const CACHE_KEY_STATUS_TERMS = 'ppsl_status_terms_post_status_and_visibility_v1';

    /**
     * WordPress core statuses
     */
    private $core_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' );

    /**
     * Class constructor.
     */
    public function __construct()
    {
        add_filter('pb_settings_tinypress_settings_sections', array( $this, 'inject_custom_statuses_into_settings' ), 10, 1);

        add_action('admin_init', array( $this, 'modify_wpdk_pre_fields' ), 999);

        add_action('created_term', array( $this, 'maybe_invalidate_custom_statuses_cache_for_term' ), 10, 3);
        add_action('edited_term', array( $this, 'maybe_invalidate_custom_statuses_cache_for_term' ), 10, 3);
        add_action('delete_term', array( $this, 'maybe_invalidate_custom_statuses_cache_for_term' ), 10, 4);
    }

    public function modify_wpdk_pre_fields()
    {
        global $tinypress_wpdk;

        if (!isset($tinypress_wpdk) || !isset($tinypress_wpdk->admin_options)) {
            return;
        }

        $admin_options = $tinypress_wpdk->admin_options;

        if (!isset($admin_options->pre_fields) || !is_array($admin_options->pre_fields)) {
            return;
        }

        if (!$this->is_pp_statuses_active()) {
            return;
        }

        $custom_statuses = $this->get_custom_statuses();

        if (empty($custom_statuses)) {
            return;
        }

        foreach ($admin_options->pre_fields as $key => $field) {
            if (isset($field['id']) && $field['id'] === 'tinypress_allowed_post_statuses') {
                if (!isset($admin_options->pre_fields[$key]['options'])) {
                    $admin_options->pre_fields[$key]['options'] = array();
                }

                foreach ($custom_statuses as $status_name => $status_obj) {
                    $label = '';
                    if (! empty($status_obj->label)) {
                        $label = $status_obj->label;
                    } elseif (! empty($status_obj->labels) && is_object($status_obj->labels) && ! empty($status_obj->labels->name)) {
                        $label = $status_obj->labels->name;
                    } else {
                        $label = ucfirst(str_replace(array( '-', '_' ), ' ', $status_name));
                    }

                    $admin_options->pre_fields[$key]['options'][$status_name] = $label;
                }

                break;
            }
        }
    }

    /**
     * Check if PublishPress Statuses plugin is active
     *
     * @return bool
     */
    public function is_pp_statuses_active()
    {
        return defined('PUBLISHPRESS_STATUSES_VERSION') && class_exists('PublishPress_Statuses');
    }

    public function get_custom_statuses()
    {
        if (! $this->is_pp_statuses_active()) {
            return array();
        }

        $statuses = array();

        try {
            $truly_disabled = $this->get_truly_disabled_statuses();

            $pp_statuses = PublishPress_Statuses::instance();

            $pp_statuses->clearStatusCache();
            $all_statuses = $pp_statuses->getPostStatuses(array(), 'object', array('show_disabled' => true));

            if (! empty($all_statuses) && is_array($all_statuses)) {
                foreach ($all_statuses as $status_name => $status_obj) {
                    if (! is_object($status_obj)) {
                        continue;
                    }

                    if (in_array($status_name, $this->core_statuses)) {
                        continue;
                    }

                    if (strpos($status_name, '_') === 0) {
                        continue;
                    }

                    if (in_array($status_name, $truly_disabled)) {
                        continue;
                    }

                    $statuses[$status_name] = $status_obj;
                }
            }

            // Fallback: query post_status and post_visibility_pp taxonomy terms directly
            // from DB to catch any user-created statuses that the PP API may not return
            global $wpdb;
            $db_terms = wp_cache_get(self::CACHE_KEY_STATUS_TERMS, self::CACHE_GROUP);
            if ($db_terms === false) {
                $db_terms = $wpdb->get_results(
                    "SELECT t.term_id, t.name, t.slug, tt.taxonomy 
                     FROM {$wpdb->terms} t 
                     INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
                     WHERE tt.taxonomy IN ('post_status', 'post_visibility_pp')
                     ORDER BY t.term_id"
                );

                wp_cache_set(self::CACHE_KEY_STATUS_TERMS, $db_terms, self::CACHE_GROUP);
            }

            if (! empty($db_terms)) {
                foreach ($db_terms as $db_term) {
                    if (isset($statuses[$db_term->slug])) {
                        continue;
                    }

                    if (in_array($db_term->slug, $this->core_statuses)) {
                        continue;
                    }

                    if (strpos($db_term->slug, '_') === 0) {
                        continue;
                    }

                    if (in_array($db_term->slug, $truly_disabled)) {
                        continue;
                    }

                    $status_obj = new stdClass();
                    $status_obj->label = $db_term->name;
                    $status_obj->name = $db_term->slug;
                    $statuses[$db_term->slug] = $status_obj;
                }
            }
        } catch (Exception $e) {
            return array();
        }

        return $statuses;
    }

    private function get_truly_disabled_statuses()
    {
        $positions = get_option('publishpress_status_positions');

        if (! is_array($positions) || empty($positions)) {
            return array();
        }

        $disabled_index = array_search('_disabled', $positions);

        if ($disabled_index === false) {
            return array();
        }

        $disabled_statuses = array_slice($positions, $disabled_index + 1);

        $disabled_statuses = array_filter($disabled_statuses, function ($status) {
            return strpos($status, '_') !== 0;
        });

        return array_values($disabled_statuses);
    }

    public function invalidate_custom_statuses_cache()
    {
        wp_cache_delete(self::CACHE_KEY_STATUS_TERMS, self::CACHE_GROUP);
    }

    public function maybe_invalidate_custom_statuses_cache_for_term($term_id, $tt_id, $taxonomy, $deleted_term = null)
    {
        if (in_array($taxonomy, array('post_status', 'post_visibility_pp'))) {
            $this->invalidate_custom_statuses_cache();
        }
    }

    public function inject_custom_statuses_into_settings($field_sections)
    {
        if (! $this->is_pp_statuses_active()) {
            return $field_sections;
        }

        $custom_statuses = $this->get_custom_statuses();

        if (empty($custom_statuses)) {
            return $field_sections;
        }

        if (isset($field_sections['settings']['sections'])) {
            foreach ($field_sections['settings']['sections'] as $section_key => $section) {
                if (isset($section['title']) && $section['title'] === esc_html__('Post Status Visibility', 'tinypress')) {
                    if (isset($section['fields'])) {
                        foreach ($section['fields'] as $field_key => $field) {
                            if (isset($field['id']) && $field['id'] === 'tinypress_allowed_post_statuses') {
                                if (! isset($field_sections['settings']['sections'][$section_key]['fields'][$field_key]['options'])) {
                                    $field_sections['settings']['sections'][$section_key]['fields'][$field_key]['options'] = array();
                                }

                                foreach ($custom_statuses as $status_name => $status_obj) {
                                    $label = '';
                                    if (! empty($status_obj->label)) {
                                        $label = $status_obj->label;
                                    } elseif (! empty($status_obj->labels) && is_object($status_obj->labels) && ! empty($status_obj->labels->name)) {
                                        $label = $status_obj->labels->name;
                                    } else {
                                        $label = ucfirst(str_replace(array( '-', '_' ), ' ', $status_name));
                                    }

                                    $field_sections['settings']['sections'][$section_key]['fields'][$field_key]['options'][$status_name] = $label;
                                }

                                break 2; // Exit both loops once we've found and modified the field
                            }
                        }
                    }
                }
            }
        }

        return $field_sections;
    }

    /**
     * Check if a given status is a custom PublishPress status
     *
     * @param string $status_name The status name to check
     * @return bool
     */
    public static function is_custom_status($status_name)
    {
        $instance = self::instance();

        if (! $instance->is_pp_statuses_active()) {
            return false;
        }

        $custom_statuses = $instance->get_custom_statuses();
        return isset($custom_statuses[$status_name]);
    }

    /**
     * Get custom status label
     *
     * @param string $status_name The status name
     * @return string The status label
     */
    public static function get_status_label($status_name)
    {
        $instance = self::instance();

        if (! $instance->is_pp_statuses_active()) {
            return $status_name;
        }

        $custom_statuses = $instance->get_custom_statuses();

        if (isset($custom_statuses[$status_name])) {
            $status_obj = $custom_statuses[$status_name];

            if (! empty($status_obj->label)) {
                return $status_obj->label;
            } elseif (! empty($status_obj->labels) && is_object($status_obj->labels) && ! empty($status_obj->labels->name)) {
                return $status_obj->labels->name;
            }
        }

        return ucfirst(str_replace(array( '-', '_' ), ' ', $status_name));
    }

    /**
     * @return TINYPRESS_Statuses
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
}

TINYPRESS_Statuses::instance();

/**
 * Public API functions
 */
function tinypress_is_custom_status($status_name)
{
    return TINYPRESS_Statuses::is_custom_status($status_name);
}

function tinypress_get_status_label($status_name)
{
    return TINYPRESS_Statuses::get_status_label($status_name);
}
