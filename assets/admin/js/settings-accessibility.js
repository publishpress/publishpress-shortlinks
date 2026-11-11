(function(window, document) {
    'use strict';

    var config = window.tinypressSettingsAccessibility || {};

    function setupSettingsAccessibility() {
        var layout = document.querySelector('.tinypress-settings-layout');

        if (!layout) {
            return;
        }

        var search = layout.querySelector('.wpdk_settings-search input');

        if (search) {
            if (!search.id) {
                search.id = 'tinypress-settings-search';
            }

            var hasLabel = Array.prototype.some.call(layout.querySelectorAll('label'), function(label) {
                return label.htmlFor === search.id;
            });

            if (!hasLabel) {
                var label = document.createElement('label');
                label.className = 'screen-reader-text';
                label.htmlFor = search.id;
                label.textContent = config.searchLabel || 'Search settings';
                search.parentNode.insertBefore(label, search);
            }
        }

        var navigation = layout.querySelector('.wpdk_settings-nav-options');

        if (!navigation) {
            return;
        }

        navigation.setAttribute('role', 'navigation');
        navigation.setAttribute('aria-label', config.navigationLabel || 'Settings sections');

        // Tab state (aria-current, aria-controls) is handled by the settings
        // framework (wp-dev-kit main.js) for both this page and edit-screen
        // metaboxes, so it is not duplicated here.
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSettingsAccessibility);
    } else {
        setupSettingsAccessibility();
    }
}(window, document));
