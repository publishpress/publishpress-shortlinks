/**
 * TinyPress Autolist AJAX Handler
 * Real-time validation and auto-save for post type configuration
 */

(function($) {
    'use strict';

    let saveTimeout = null;

    const AutolistAjax = {
        init: function() {
            this.bindEvents();
            this.initSortable();
            this.initSelect2($('.tinypress-autolist-post-type'));
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
                    $(this).closest('.tinypress-autolist-row').remove();
                    self.saveConfiguration();
                }
            });
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
            });
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

            $saveIndicator.html('<span class="saving"><span class="dashicons dashicons-update spin"></span> ' + tinypressAutolist.i18n.saving + '</span>').show();

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
                        $saveIndicator.html('<span class="saved"><span class="dashicons dashicons-yes"></span> ' + tinypressAutolist.i18n.saved + '</span>');
                        setTimeout(function() {
                            $saveIndicator.fadeOut();
                        }, 2000);
                        
                        AutolistAjax.updateRowIndices();
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : tinypressAutolist.i18n.error;
                        $saveIndicator.html('<span class="error"><span class="dashicons dashicons-warning"></span> ' + errorMsg + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = tinypressAutolist.i18n.error + ': ';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg += xhr.responseJSON.data.message;
                    } else {
                        errorMsg += status;
                    }
                    $saveIndicator.html('<span class="error"><span class="dashicons dashicons-warning"></span> ' + errorMsg + '</span>');
                }
            });
        },

        addRow: function() {
            const $container = $('.tinypress-autolist-container');
            const index = $('.tinypress-autolist-row').length;
            
            const rowHtml = `
                <div class="tinypress-autolist-row" data-index="${index}">
                    <div class="tinypress-autolist-handle">
                        <span class="dashicons dashicons-menu"></span>
                    </div>
                    <div class="tinypress-autolist-field">
                        <select class="tinypress-autolist-post-type">
                            <option value="">${tinypressAutolist.i18n.selectPostType}</option>
                        </select>
                    </div>
                    <div class="tinypress-autolist-field">
                        <select class="tinypress-autolist-behavior">
                            <option value="never">${tinypressAutolist.i18n.never}</option>
                            <option value="on_first_use" selected>${tinypressAutolist.i18n.onFirstUse}</option>
                            <option value="on_publish">${tinypressAutolist.i18n.onPublish}</option>
                        </select>
                    </div>
                    <div class="tinypress-autolist-actions">
                        <button type="button" class="button tinypress-autolist-remove">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
            `;
            
            $container.append(rowHtml);

            const $newSelect = $container.find('.tinypress-autolist-row:last .tinypress-autolist-post-type');
            this.initSelect2($newSelect);
 
            $newSelect.ppma_select2('open');
        },

        updateRowIndices: function() {
            $('.tinypress-autolist-row').each(function(index) {
                $(this).attr('data-index', index);
            });
        }
    };

    $(document).ready(function() {
        if ($('.tinypress-autolist-container').length) {
            AutolistAjax.init();
        }
    });

})(jQuery);
