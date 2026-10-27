/**
 * TinyPress Autolist AJAX Handler
 * Real-time validation and auto-save for post type configuration
 */

(function($) {
    'use strict';

    let saveTimeout = null;
    let rowUid = 0;

    function i18nText(key, fallback) {
        return (tinypressAutolist.i18n && tinypressAutolist.i18n[key]) ? tinypressAutolist.i18n[key] : fallback;
    }

    function formatText(template) {
        const args = Array.prototype.slice.call(arguments, 1);
        let position = 0;

        return String(template).replace(/%(?:(\d+)\$)?d/g, function(match, index) {
            const value = index ? args[parseInt(index, 10) - 1] : args[position++];
            return typeof value === 'undefined' ? match : value;
        });
    }

    function speak(message) {
        if (!message) {
            return;
        }

        if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
            window.wp.a11y.speak(String(message), 'polite');
        }
    }

    function escapeHtml(value) {
        return $('<div/>').text(value === null || typeof value === 'undefined' ? '' : String(value)).html();
    }

    const AutolistAjax = {
        init: function() {
            rowUid = $('.tinypress-autolist-row').length + 1000;
            this.bindEvents();
            this.initSortable();
            this.initSelect2($('.tinypress-autolist-post-type'));
            this.updateRowIndices();
        },

        scheduleSave: function(delay) {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                AutolistAjax.saveConfiguration();
            }, delay);
        },

        bindEvents: function() {
            const self = this;

            $(document).on('change', '.tinypress-autolist-post-type, .tinypress-autolist-behavior', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(function() {
                    AutolistAjax.saveConfiguration();
                }, 1000);
            });

            $(document).on('click', '.wpdk_settings--switcher', function() {
                const $switcher = $(this);
                const $hiddenInput = $switcher.find('input[name="tinypress[tinypress_autolist_enabled]"]');
                
                if ($hiddenInput.length > 0) {
                    clearTimeout(saveTimeout);
                    saveTimeout = setTimeout(function() {
                        AutolistAjax.saveConfiguration();
                    }, 800);
                }
            });

            $(document).on('click', '.tinypress-autolist-add', function(e) {
                e.preventDefault();
                self.addRow();
            });

            $(document).on('click', '.tinypress-autolist-remove', function(e) {
                e.preventDefault();
                if (confirm(tinypressAutolist.i18n.confirmDelete)) {
                    const $row = $(this).closest('.tinypress-autolist-row');
                    const $next = $row.nextAll('.tinypress-autolist-row').first();
                    const $prev = $row.prevAll('.tinypress-autolist-row').first();

                    $row.remove();
                    self.updateRowIndices();

                    // Keep keyboard focus in a sensible place after removal.
                    if ($next.length) {
                        $next.find('.tinypress-autolist-remove').trigger('focus');
                    } else if ($prev.length) {
                        $prev.find('.tinypress-autolist-remove').trigger('focus');
                    } else {
                        $('.tinypress-autolist-add').trigger('focus');
                    }

                    speak(i18nText('removed', 'Row removed.'));
                    self.saveConfiguration();
                }
            });

            // Keyboard / single-pointer alternative to drag-and-drop sorting.
            $(document).on('click', '.tinypress-autolist-move', function(e) {
                e.preventDefault();
                self.moveRow($(this).closest('.tinypress-autolist-row'), $(this).data('direction'));
            });
        },

        moveRow: function($row, direction) {
            const isUp = direction === 'up';
            const $target = isUp
                ? $row.prevAll('.tinypress-autolist-row').first()
                : $row.nextAll('.tinypress-autolist-row').first();
            const buttonSelector = isUp ? '.tinypress-autolist-move-up' : '.tinypress-autolist-move-down';
            const $rows = $('.tinypress-autolist-row');

            if (!$target.length) {
                speak(formatText(i18nText('movedTo', 'Row moved to position %1$d of %2$d.'), $rows.index($row) + 1, $rows.length));
                return;
            }

            if (isUp) {
                $row.insertBefore($target);
            } else {
                $row.insertAfter($target);
            }

            this.updateRowIndices();
            $row.find(buttonSelector).trigger('focus');

            const $updatedRows = $('.tinypress-autolist-row');
            speak(formatText(i18nText('movedTo', 'Row moved to position %1$d of %2$d.'), $updatedRows.index($row) + 1, $updatedRows.length));

            this.scheduleSave(1000);
        },

        initSortable: function() {
            const self = this;
            
            $('.tinypress-autolist-container').sortable({
                handle: '.tinypress-autolist-handle',
                axis: 'y',
                cursor: 'move',
                opacity: 0.7,
                update: function() {
                    self.updateRowIndices();
                    clearTimeout(saveTimeout);
                    saveTimeout = setTimeout(function() {
                        self.saveConfiguration();
                    }, 1000);
                }
            });
        },

        initSelect2: function($elements) {
            const self = this;
            
            $elements.each(function() {
                const $select = $(this);
                
                $select.ppma_select2({
                    ajax: {
                        url: tinypressAutolist.ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'tinypress_get_post_types',
                                nonce: tinypressAutolist.nonce,
                                search: params.term,
                                page: params.page || 1
                            };
                        },
                        processResults: function(response) {
                            if (response.success) {
                                return {
                                    results: response.data.results,
                                    pagination: response.data.pagination
                                };
                            }
                            return { results: [] };
                        },
                        cache: true
                    },
                    placeholder: tinypressAutolist.i18n.selectPostType,
                    minimumInputLength: 0,
                    width: '100%'
                });

                self.labelSelect2($select);
            });
        },

        // Give the select2 combobox the same accessible name as the hidden <select>.
        labelSelect2: function($select) {
            const labelId = $select.closest('.tinypress-autolist-field').find('.tinypress-autolist-post-type-label').attr('id');
            const $selection = $select.next('.ppma_select2-container').find('.ppma_select2-selection');

            if (!labelId || !$selection.length) {
                return;
            }

            let renderedId = $selection.data('tinypressRenderedId');
            if (typeof renderedId === 'undefined') {
                renderedId = $selection.find('.ppma_select2-selection__rendered').attr('id') || '';
                $selection.data('tinypressRenderedId', renderedId);
            }

            $selection.attr('aria-labelledby', $.trim(labelId + ' ' + renderedId));
        },

        saveConfiguration: function() {
            const config = [];
            const $saveIndicator = $('.tinypress-save-indicator');

            $('.tinypress-autolist-row').each(function() {
                const $row = $(this);
                const postType = $row.find('.tinypress-autolist-post-type').val();
                const behavior = $row.find('.tinypress-autolist-behavior').val();

                if (postType) {
                    config.push({
                        post_type: postType,
                        behavior: behavior
                    });
                }
            });

            const $switcherInput = $('input[name="tinypress[tinypress_autolist_enabled]"]');
            let switcherEnabled = '0';
            
            if ($switcherInput.length > 0) {
                const inputValue = $switcherInput.val();
                switcherEnabled = (inputValue === '1' || inputValue === 1) ? '1' : '0';
            }

            $saveIndicator.html('<span class="saving"><span class="dashicons dashicons-update spin" aria-hidden="true"></span> ' + escapeHtml(tinypressAutolist.i18n.saving) + '</span>').show();

            $.ajax({
                url: tinypressAutolist.ajaxurl,
                type: 'POST',
                data: {
                    action: 'tinypress_save_autolist_config',
                    nonce: tinypressAutolist.nonce,
                    config: config,
                    enabled: switcherEnabled
                },
                success: function(response) {
                    if (response.success) {
                        // Stays visible (no auto-fade) so the status can be read at any pace.
                        $saveIndicator.html('<span class="saved"><span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + escapeHtml(tinypressAutolist.i18n.saved) + '</span>');
                        speak(tinypressAutolist.i18n.saved);

                        AutolistAjax.updateRowIndices();
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : tinypressAutolist.i18n.error;
                        $saveIndicator.html('<span class="error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' + escapeHtml(errorMsg) + '</span>');
                        speak(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = tinypressAutolist.i18n.error + ': ';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg += xhr.responseJSON.data.message;
                    } else {
                        errorMsg += status;
                    }
                    $saveIndicator.html('<span class="error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' + escapeHtml(errorMsg) + '</span>');
                    speak(errorMsg);
                }
            });
        },

        addRow: function() {
            const $container = $('.tinypress-autolist-container');
            const index = $('.tinypress-autolist-row').length;
            const rowNumber = index + 1;
            const uid = 'tinypress-autolist-new-' + (++rowUid);
            const i18n = tinypressAutolist.i18n;

            const rowHtml = `
                <div class="tinypress-autolist-row" data-index="${index}">
                    <div class="tinypress-autolist-handle" aria-hidden="true">
                        <span class="dashicons dashicons-menu"></span>
                    </div>
                    <div class="tinypress-autolist-field">
                        <label class="screen-reader-text tinypress-autolist-post-type-label" id="${uid}-post-type-label" for="${uid}-post-type">${escapeHtml(formatText(i18nText('postTypeLabel', 'Post type (row %d)'), rowNumber))}</label>
                        <select id="${uid}-post-type" class="tinypress-autolist-post-type">
                            <option value="">${i18n.selectPostType}</option>
                        </select>
                    </div>
                    <div class="tinypress-autolist-field">
                        <label class="screen-reader-text tinypress-autolist-behavior-label" for="${uid}-behavior">${escapeHtml(formatText(i18nText('behaviorLabel', 'When to list shortlinks (row %d)'), rowNumber))}</label>
                        <select id="${uid}-behavior" class="tinypress-autolist-behavior">
                            <option value="never">${i18n.never}</option>
                            <option value="on_first_use_or_on_create" selected>${i18n.onFirstUseOrOnCreate}</option>
                            <option value="on_first_use">${i18n.onFirstUse}</option>
                            <option value="on_create">${i18n.onCreate}</option>
                            <option value="on_publish">${i18n.onPublish}</option>
                        </select>
                    </div>
                    <div class="tinypress-autolist-actions">
                        <button type="button" class="button tinypress-autolist-move tinypress-autolist-move-up" data-direction="up">
                            <span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
                            <span class="screen-reader-text">${escapeHtml(formatText(i18nText('moveUp', 'Move row %d up'), rowNumber))}</span>
                        </button>
                        <button type="button" class="button tinypress-autolist-move tinypress-autolist-move-down" data-direction="down">
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                            <span class="screen-reader-text">${escapeHtml(formatText(i18nText('moveDown', 'Move row %d down'), rowNumber))}</span>
                        </button>
                        <button type="button" class="button tinypress-autolist-remove">
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            <span class="screen-reader-text">${escapeHtml(formatText(i18nText('remove', 'Remove row %d'), rowNumber))}</span>
                        </button>
                    </div>
                </div>
            `;

            $container.append(rowHtml);

            const $newSelect = $container.find('.tinypress-autolist-row:last .tinypress-autolist-post-type');
            this.initSelect2($newSelect);
            this.updateRowIndices();

            $newSelect.ppma_select2('open');
        },

        // Keep data-index and the row numbers in accessible names in sync with the visual order.
        updateRowIndices: function() {
            $('.tinypress-autolist-row').each(function(index) {
                const $row = $(this);
                const rowNumber = index + 1;

                $row.attr('data-index', index);
                $row.find('.tinypress-autolist-post-type-label').text(formatText(i18nText('postTypeLabel', 'Post type (row %d)'), rowNumber));
                $row.find('.tinypress-autolist-behavior-label').text(formatText(i18nText('behaviorLabel', 'When to list shortlinks (row %d)'), rowNumber));
                $row.find('.tinypress-autolist-move-up .screen-reader-text').text(formatText(i18nText('moveUp', 'Move row %d up'), rowNumber));
                $row.find('.tinypress-autolist-move-down .screen-reader-text').text(formatText(i18nText('moveDown', 'Move row %d down'), rowNumber));
                $row.find('.tinypress-autolist-remove .screen-reader-text').text(formatText(i18nText('remove', 'Remove row %d'), rowNumber));
            });
        }
    };

    $(document).ready(function() {
        if ($('.tinypress-autolist-container').length) {
            AutolistAjax.init();
        }
    });

})(jQuery);
