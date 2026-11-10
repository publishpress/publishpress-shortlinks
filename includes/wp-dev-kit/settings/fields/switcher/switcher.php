<?php

// phpcs:ignoreFile -- Third-party library (wp-dev-kit); not maintained by this plugin

if (! defined('ABSPATH')) {
    die;
} // Cannot access directly.
/**
 *
 * Field: switcher
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (! class_exists('WPDK_Settings_Field_switcher')) {
    class WPDK_Settings_Field_switcher extends WPDK_Settings_Fields
    {
        public function __construct($field, $value = '', $unique = '', $where = '', $parent = '')
        {
            parent::__construct($field, $value, $unique, $where, $parent);
        }

        public function render()
        {

            $active     = (! empty($this->value)) ? ' wpdk_settings--active' : '';
            $text_on    = (! empty($this->field['text_on'])) ? $this->field['text_on'] : esc_html__('On');
            $text_off   = (! empty($this->field['text_off'])) ? $this->field['text_off'] : esc_html__('Off');
            $text_width = (! empty($this->field['text_width'])) ? ' style="width: ' . esc_attr($this->field['text_width']) . 'px;"' : '';

            echo $this->field_before();

            echo '<div class="wpdk_settings--switcher-wrapper">';

            $label_id = (! empty($this->field['label'])) ? $this->a11y_id('label') : '';
            $a11y     = $this->a11y_attributes('control', $label_id);

            // Keyboard operable switch: role/state here, key handling in main.js.
            $switch_atts = ' role="switch" tabindex="0" aria-checked="' . (! empty($this->value) ? 'true' : 'false') . '"';

            foreach ($a11y as $a11y_key => $a11y_value) {
                $switch_atts .= ' ' . esc_attr($a11y_key) . '="' . esc_attr($a11y_value) . '"';
            }

            echo '<div class="wpdk_settings--switcher' . esc_attr($active) . '"' . $text_width . $switch_atts . '>';
            echo '<span class="wpdk_settings--on" aria-hidden="true">' . esc_attr($text_on) . '</span>';
            echo '<span class="wpdk_settings--off" aria-hidden="true">' . esc_attr($text_off) . '</span>';
            echo '<span class="wpdk_settings--ball"></span>';
            echo '<input type="hidden" name="' . esc_attr($this->field_name()) . '" value="' . esc_attr($this->value) . '"' . $this->field_attributes(array(), false) . ' />';
            echo '</div>';

            echo (! empty($this->field['label'])) ? '<span class="wpdk_settings--label"' . ($label_id ? ' id="' . esc_attr($label_id) . '"' : '') . '>' . esc_attr($this->field['label']) . '</span>' : '';

            echo '</div>';

            echo $this->field_after();
        }
    }
}
