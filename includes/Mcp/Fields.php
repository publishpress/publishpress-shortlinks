<?php

/** Typed contracts derived from the registered native settings and metabox controls. */

namespace PublishPress\Shortlinks\Mcp;

\defined('ABSPATH') || exit;
class Fields
{
    public static function definitions(string $scope, int $id = 0): array
    {
        $unique = $scope === 'settings' ? 'tinypress_settings' : 'tinypress_meta_main';
        $sections = \WPDK_Settings::$args['sections'][$unique] ?? [];
        $fields = [];
        $walk = static function (array $nodes) use (&$walk, &$fields) {
            foreach ($nodes as $node) {
                if (isset($node['id'], $node['type'])) {
                    $fields[$node['id']] = $node;
                }
                foreach (['sections', 'fields'] as $children) {
                    if (isset($node[$children]) && is_array($node[$children])) {
                        // Repeater children belong to their row, not the enclosing patch.
                        if ($children !== 'fields' || !isset($node['type'])) {
                            $walk($node[$children]);
                        }
                    }
                }
            }
        };
        $walk($sections);
        if ($scope === 'link' && isset($fields['autolink_alt_text'])) {
            $options = \TINYPRESS_Meta_boxes::get_autolink_alt_text_options($id);
            // Registration accepts the union; object-specific discovery and writes use native choices.
            $fields['autolink_alt_text']['options'] = $id ? $options : ['post_title' => __('Post Title', 'tinypress')] + $options;
            $fields['autolink_alt_text']['default'] = key($options);
        }
        return $fields;
    }

    public static function schema(array $field): ?array
    {
        $id = $field['id'];
        if (preg_match('/link_password|license|api_key|secret|token|recipient|email|pro_teaser/i', $id)) {
            return null;
        }
        if (!empty($field['attributes']['disabled']) || !empty($field['disabled'])) {
            return null;
        }
        $type = $field['type'];
        if ($type === 'switcher' || (isset($field['default']) && is_bool($field['default']) && $type === 'text')) {
            return ['type' => 'boolean'];
        }
        if (in_array($type, ['checkbox', 'select', 'radio', 'button_set'], true) && isset($field['options']) && is_array($field['options'])) {
            $choice = ['type' => 'string', 'enum' => array_map('strval', array_keys($field['options']))];
            return $type === 'checkbox' || !empty($field['multiple']) ? Registry::listOf($choice, 100) : $choice;
        }
        if (in_array($id, ['tinypress_enabled_post_types', 'tinypress_autolink_post_types'], true)) {
            $instance = \TINYPRESS_Settings::instance();
            $options = $id === 'tinypress_enabled_post_types' ? $instance->get_public_post_type_options() : $instance->get_post_type_options();
            return Registry::listOf(['type' => 'string', 'enum' => array_merge(['__all__'], array_keys($options))], 100);
        }
        if ($id === 'tinypress_non_public_status_messages') {
            return Registry::object(array_fill_keys(array_keys(tinypress_get_supported_post_status_options(false)), Registry::text(20000)));
        }
        if ($id === 'tinypress_autolist_post_types') {
            return Registry::listOf(Registry::object(['post_type' => ['type' => 'string', 'enum' => array_values(array_diff(get_post_types(['public' => true]), ['attachment', 'tinypress_link']))], 'behavior' => ['type' => 'string', 'enum' => ['never', 'on_first_use', 'on_create', 'on_first_use_or_on_create']]], ['post_type', 'behavior']), 100);
        }
        if (in_array($id, ['tinypress_autolink_custom_excluded_elements', 'tinypress_autolink_excluded_blocks'], true)) {
            $name = Registry::text(200);
            if ($id === 'tinypress_autolink_excluded_blocks') {
                $name = ['type' => 'string', 'enum' => array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered())];
            } else {
                $name['pattern'] = '^[a-zA-Z][a-zA-Z0-9-]*$';
            }
            return Registry::listOf(Registry::object(['name' => $name, 'enabled' => ['type' => 'boolean']], ['name', 'enabled']), 100);
        }
        if ($type === 'repeater' && isset($field['fields'])) {
            $children = [];
            foreach ($field['fields'] as $child) {
                $schema = self::schema($child);
                if ($schema === null) {
                    return null;
                }
                $schema['description'] = wp_strip_all_tags(($child['title'] ?? $child['id']) . ' ' . ($child['desc'] ?? ''));
                if (array_key_exists('default', $child)) {
                    $hidden = [];
                    $safe = self::exportValues([$child['id'] => self::project($child['default'], $schema)], '', $hidden);
                    if (array_key_exists($child['id'], $safe)) {
                        $schema['default'] = $safe[$child['id']];
                    } else {
                        $schema['writeOnly'] = true;
                    }
                }
                $children[$child['id']] = $schema;
            }
            return Registry::listOf(Registry::object($children), 100);
        }
        if (in_array($type, ['number', 'spinner'], true)) {
            return ['type' => 'integer', 'minimum' => (int) ($field['attributes']['min'] ?? $field['min'] ?? 0), 'maximum' => (int) ($field['attributes']['max'] ?? $field['max'] ?? 1000000)];
        }
        if (in_array($type, ['text', 'textarea', 'color', 'datetime', 'wp_editor'], true) || $id === 'tiny_slug') {
            $schema = Registry::text(20000);
            if ($type === 'color') {
                $schema['pattern'] = '^(|transparent|#[a-fA-F0-9]{3}([a-fA-F0-9]{3})?)$';
            }
            return $schema;
        }
        return null;
    }

    public static function schemas(string $scope, int $id = 0): array
    {
        $result = [];
        foreach (self::definitions($scope, $id) as $key => $field) {
            $schema = self::schema($field);
            if ($schema !== null) {
                $result[$key] = $schema;
            }
        }
        return $result;
    }

    public static function project($value, array $schema)
    {
        if ($schema['type'] === 'object') {
            $result = [];
            foreach ((array) $value as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    $result[$key] = self::project($child, $schema['properties'][$key]);
                }
            }
            return (object) $result;
        }
        if ($schema['type'] === 'array') {
            if ($value === '' || $value === null || $value === false) {
                $value = [];
            }
            if (isset($schema['items']['enum'])) {
                $value = array_values(array_intersect((array) $value, $schema['items']['enum']));
            }
            return array_map(static function ($child) use ($schema) {
                return self::project($child, $schema['items']);
            }, array_values((array) $value));
        }
        if ($schema['type'] === 'boolean') {
            return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
        }
        return Registry::project($value ?? '', $schema);
    }

    public static function native($value, array $schema)
    {
        if ($schema['type'] === 'boolean') {
            return $value ? '1' : '0';
        }
        if (in_array($schema['type'], ['array', 'object'], true)) {
            $result = [];
            foreach ((array) $value as $key => $child) {
                $result[$key] = self::native($child, $schema['type'] === 'array' ? $schema['items'] : $schema['properties'][$key]);
            }
            return $result;
        }
        return $value;
    }

    public static function clean(array $patch, string $scope, int $id = 0)
    {
        $definitions = self::definitions($scope, $id);
        $schemas = self::schemas($scope, $id);
        $clean = [];
        foreach ($patch as $key => $value) {
            $field = $definitions[$key];
            $value = self::native($value, $schemas[$key]);
            if (in_array($key, ['tinypress_autolist_post_types', 'tinypress_autolink_custom_excluded_elements', 'tinypress_autolink_excluded_blocks'], true)) {
                $identity = $key === 'tinypress_autolist_post_types' ? 'post_type' : 'name';
                $identifiers = array_column($value, $identity);
                if (count($identifiers) !== count(array_unique($identifiers))) {
                    return Registry::error(__('Supply each native list identifier only once.', 'tinypress'));
                }
            }

            if ($schemas[$key]['type'] === 'object') {
                $raw = $scope === 'settings' ? (array) get_option('tinypress_settings', []) : [];
                $value = array_merge((array) ($raw[$key] ?? []), $value);
            }
            if ($field['type'] === 'datetime' && $value !== '') {
                $format = $field['settings']['dateFormat'] ?? '';
                $format = str_replace('K', 'A', $format);
                $date = $format ? \DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone()) : false;
                if (!$date || $date->format($format) !== $value) {
                    return Registry::error(__('Use the native date or time format returned by discovery.', 'tinypress'));
                }
            }

            if (isset($field['validate']) && is_callable($field['validate']) && call_user_func($field['validate'], $value)) {
                return Registry::error(__('A native field validator rejected this value.', 'tinypress'));
            }
            $clean[$key] = isset($field['sanitize']) && is_callable($field['sanitize']) ? call_user_func($field['sanitize'], $value) : wp_kses_post_deep($value);
        }
        if ($scope === 'settings') {
            $filtered = apply_filters('pb_settings_tinypress_settings_save', $clean, null);
        } else {
            $existing = [];
            foreach ($definitions as $key => $field) {
                if (metadata_exists('post', $id, $key)) {
                    $existing[$key] = get_post_meta($id, $key, true);
                }
            }
            $filtered = apply_filters('pb_settings_tinypress_meta_main_save', array_merge($existing, $clean), $id, null);
        }
        return array_intersect_key($filtered, $clean);
    }

    public static function read(string $scope, int $id = 0): array
    {
        $raw = $scope === 'settings' ? (array) get_option('tinypress_settings', []) : [];
        $fields = $values = $unavailable = [];
        $redacted = [];
        foreach (self::definitions($scope, $id) as $key => $field) {
            $schema = self::schema($field);
            if ($schema === null) {
                $unavailable[] = $key;
                continue;
            }
            $value = $scope === 'settings' ? ($raw[$key] ?? $field['default'] ?? null) : (metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : ($field['default'] ?? null));
            if ($scope === 'settings' && strpos($key, 'tinypress_role_') === 0) {
                $value = \TINYPRESS_Hooks::normalize_role_list($value);
            }
            if ($key === 'tinypress_autolink_excluded_blocks' && class_exists('Shortlinks_Pro_Autolink')) {
                $value = \Shortlinks_Pro_Autolink::get_instance()->sanitize_excluded_blocks($value);
            }
            if ($scope === 'link' && $id && substr($key, -11) === '_use_global' && isset($schema['enum']) && in_array('1', $schema['enum'], true) && (in_array('enabled', $schema['enum'], true) || in_array('custom', $schema['enum'], true))) {
                $local = substr($key, 0, -11);
                $value = \TINYPRESS_Meta_boxes::get_inheritance_mode($id, $local, $key, in_array('custom', $schema['enum'], true));
            }
            if ($key === 'post_title' && $scope === 'link' && $id) {
                $value = get_post_field('post_title', $id);
            }
            $nativeFallback = $key === 'autolink_alt_text' && $value === 'post_title' && !in_array('post_title', $schema['enum'] ?? [], true);
            if ($nativeFallback) {
                // Native autolinking falls back to the Shortlink label for external destinations.
                $value = 'shortlink_label';
            }
            $values[$key] = self::project($value, $schema);
            $safeDefault = self::exportValues([$key => self::project($field['default'] ?? null, $schema)], '', $redacted);
            $fields[$key] = ['label' => $field['title'] ?? $key, 'description' => $field['subtitle'] ?? $field['desc'] ?? '', 'schema' => $schema, 'native_format' => $field['settings']['dateFormat'] ?? null, 'native_fallback' => $nativeFallback, 'default' => $safeDefault[$key] ?? null];
        }
        $values = self::exportValues($values, '', $redacted);
        foreach ($redacted as $path) {
            $root = explode('.', $path)[0];
            if (isset($fields[$root])) {
                $fields[$root]['redacted'] = true;
            }
        }
        return ['settings' => (object) $values, 'fields' => (object) $fields, 'native_only_fields' => $unavailable, 'redacted_fields' => array_values(array_unique($redacted))];
    }

    /** Preserve list shape while withholding credentials from known URL controls. */
    private static function exportValues($value, string $path, array &$redacted)
    {
        $object = is_object($value);
        $result = [];
        foreach ((array) $value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_string($child) && preg_match('/(?:^|_)(?:url|destination)$/', (string) $key) && Registry::credentialUrl($child)) {
                $redacted[] = $childPath;
                continue;
            }
            $result[$key] = is_array($child) || is_object($child) ? self::exportValues($child, $childPath, $redacted) : $child;
        }
        return $object ? (object) $result : $result;
    }
}
