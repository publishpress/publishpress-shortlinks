(function(window, document) {
    'use strict';

    var config = window.tinypressSettingsAccessibility || {};

    function getSection(layout, tabId) {
        var sections = layout.querySelectorAll('.wpdk_settings-section');

        for (var index = 0; index < sections.length; index++) {
            if (sections[index].getAttribute('data-section-id') === tabId) {
                return sections[index];
            }
        }

        return null;
    }

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

        var links = navigation.querySelectorAll('a[data-tab-id]');

        Array.prototype.forEach.call(links, function(link, index) {
            var section = getSection(layout, link.getAttribute('data-tab-id'));

            if (!link.id) {
                link.id = 'tinypress-settings-link-' + index;
            }

            if (!section) {
                return;
            }

            if (!section.id) {
                section.id = 'tinypress-settings-section-' + index;
            }

            link.setAttribute('aria-controls', section.id);
            section.setAttribute('role', 'region');
            section.setAttribute('aria-labelledby', link.id);
        });

        function syncNavigationState() {
            Array.prototype.forEach.call(links, function(link) {
                var section = getSection(layout, link.getAttribute('data-tab-id'));
                var isActive = link.classList.contains('wpdk_settings-active');

                if (isActive) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }

                if (section) {
                    section.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                }
            });
        }

        syncNavigationState();

        navigation.addEventListener('click', function(event) {
            var link = event.target.closest('a[data-tab-id]');

            if (!link || !navigation.contains(link)) {
                return;
            }

            window.setTimeout(syncNavigationState, 0);
        });

        window.addEventListener('hashchange', syncNavigationState);

        if (window.jQuery) {
            window.jQuery(window).on('pb_settings.hashchange', syncNavigationState);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSettingsAccessibility);
    } else {
        setupSettingsAccessibility();
    }
}(window, document));
