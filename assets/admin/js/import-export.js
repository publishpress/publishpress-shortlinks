(function($) {
    'use strict';

    var currentFile = null;
    var previewData = null;
    var visiblePreviewRows = 5;

    /**
     * Escape HTML special characters
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    $(document).ready(function() {
        var $fileInput              = $('#tinypress-csv-file');
        var $form                   = $('#tinypress-import-form');
        var $fileInputSection       = $('#tinypress-file-input-section');
        var $fileSelectedActions    = $('#tinypress-file-selected-actions');
        var $previewBtn             = $('#tinypress-preview-btn');
        var $changeFileBtn          = $('#tinypress-change-file-btn');
        var $importSelectedBtn      = $('#tinypress-import-selected-btn');
        var $confirmImportBtn       = $('#tinypress-confirm-import-btn');
        var $cancelImportBtn        = $('#tinypress-cancel-import-btn');
        var $expandTableBtn         = $('#tinypress-expand-table-btn');
        var $previewSection         = $('#tinypress-preview-section');
        var $progressSection         = $('#tinypress-progress-section');
        var $result                 = $('#tinypress-import-result');

        $fileInput.on('change', function() {
            if (this.files.length > 0) {
                $fileInputSection.hide();
                $fileSelectedActions.show();
                currentFile = this.files[0];
            }
        });

        $changeFileBtn.on('click', function() {
            currentFile = null;
            $fileInput.val('');
            $fileSelectedActions.hide();
            $fileInputSection.show();
            $previewSection.hide();
            $result.hide().empty();
            previewData = null;
        });

        $previewBtn.on('click', function(e) {
            e.preventDefault();
            if (!currentFile) {
                alert(tinypressImportExport.i18n.no_file);
                return;
            }
            performPreview(currentFile);
        });

        $importSelectedBtn.on('click', function() {
            if (!currentFile) {
                alert(tinypressImportExport.i18n.no_file);
                return;
            }

            if (!confirm(tinypressImportExport.i18n.confirm_import)) {
                return;
            }

            performImport(currentFile);
        });

        $cancelImportBtn.on('click', function() {
            $previewSection.hide();
            $fileSelectedActions.show();
            $result.hide().empty();
            previewData = null;
        });

        $confirmImportBtn.on('click', function() {
            if (!currentFile) {
                alert(tinypressImportExport.i18n.no_file);
                return;
            }
            performImport(currentFile);
        });

        $expandTableBtn.on('click', function() {
            if (!previewData || !previewData.preview) {
                return;
            }

            if (visiblePreviewRows >= previewData.preview.length) {
                visiblePreviewRows = previewData.initial_rows || 5;
            } else {
                visiblePreviewRows = Math.min(visiblePreviewRows + 5, previewData.preview.length);
            }

            updateVisiblePreviewRows();
        });

        $form.on('submit', function(e) {
            e.preventDefault();
        });

        /**
         * Perform preview of the import
         */
        function performPreview(file) {
            $previewBtn.prop('disabled', true).text(tinypressImportExport.i18n.previewing);
            $result.hide().removeClass('success error').empty();

            var formData = new FormData();
            formData.append('action', 'tinypress_preview_import');
            formData.append('nonce', tinypressImportExport.preview_nonce);
            formData.append('csv_file', file);

            $.ajax({
                url: tinypressImportExport.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $previewBtn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-visibility"></span> ' + escapeHtml(tinypressImportExport.i18n.preview || 'Preview')
                    );

                    if (response.success) {
                        previewData = response.data;
                        visiblePreviewRows = response.data.initial_rows || 5;
                        buildPreviewTable(response.data.columns, response.data.preview);

                        var previewMessage = response.data.message;
                        if (response.data.mapping_message) {
                            previewMessage += '<div class="tinypress-field-mapping-info">' + 
                                response.data.mapping_message + '</div>';
                        }
                        
                        $('#tinypress-preview-message').html(previewMessage);
                        $fileSelectedActions.hide();
                        $previewSection.show();
                    } else {
                        $result.addClass('error').html(
                            '<strong>' + escapeHtml(response.data.message || tinypressImportExport.i18n.preview_error) + '</strong>'
                        ).show();
                    }
                },
                error: function() {
                    $previewBtn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-visibility"></span> ' + escapeHtml(tinypressImportExport.i18n.preview || 'Preview')
                    );
                    $result.addClass('error').html(
                        '<strong>' + tinypressImportExport.i18n.preview_error + '</strong>'
                    ).show();
                }
            });
        }

        /**
         * Build preview table dynamically based on columns
         */
        function buildPreviewTable(columns, rows) {
            var $thead = $('#tinypress-preview-thead');
            var $tbody = $('#tinypress-preview-rows');
            var $expandContainer = $('#tinypress-expand-container');
            var initialRows = previewData && previewData.initial_rows ? previewData.initial_rows : 5;

            $thead.empty();
            var $headerRow = $('<tr>');
            $.each(columns, function(i, col) {
                $headerRow.append($('<th>').text(col));
            });
            $thead.append($headerRow);

            $tbody.empty();
            $.each(rows, function(rowIndex, rowData) {
                var $row = $('<tr>');
                if (rowIndex >= visiblePreviewRows) {
                    $row.hide();
                }
                $.each(rowData, function(colIndex, cellData) {
                    $row.append($('<td>').text(cellData));
                });
                $tbody.append($row);
            });

            if (rows.length > initialRows) {
                $expandContainer.show();
                updateVisiblePreviewRows();
            } else {
                $expandContainer.hide();
            }
        }

        /**
         * Update visible preview rows in 5-row steps.
         */
        function updateVisiblePreviewRows() {
            var rows = previewData && previewData.preview ? previewData.preview : [];
            var initialRows = previewData && previewData.initial_rows ? previewData.initial_rows : 5;
            var $tbody = $('#tinypress-preview-rows');

            $tbody.find('tr').each(function(index) {
                $(this).toggle(index < visiblePreviewRows);
            });

            if (rows.length <= initialRows) {
                $('#tinypress-expand-container').hide();
                return;
            }

            $('#tinypress-expand-container').show();
            $expandTableBtn.text(
                visiblePreviewRows >= rows.length
                    ? (tinypressImportExport.i18n.see_less || 'Show Less')
                    : (tinypressImportExport.i18n.see_more || 'See More Rows')
            );
        }

        /**
         * Perform the actual import
         */
        function performImport(file) {
            $progressSection.show();
            $previewSection.hide();
            $fileSelectedActions.hide();
            $result.hide().removeClass('success error').empty();
            updateProgress(0);

            var formData = new FormData();
            formData.append('action', 'tinypress_import_csv');
            formData.append('nonce', tinypressImportExport.nonce);
            formData.append('csv_file', file);

            var progressInterval = setInterval(function() {
                var currentProgress = parseInt($('.tinypress-progress-fill').css('width')) / 
                                    parseInt($('.tinypress-progress-bar').css('width')) * 100;
                if (currentProgress < 90) {
                    updateProgress(currentProgress + Math.random() * 20);
                }
            }, 200);

            $.ajax({
                url: tinypressImportExport.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    clearInterval(progressInterval);
                    updateProgress(100);

                    setTimeout(function() {
                        $progressSection.hide();

                        if (response.success) {
                            var html = '';
                            var imported = response.data.imported || 0;
                            var failed = response.data.failed || 0;

                            if (imported > 0) {
                                html += '<div class="tinypress-import-success">';
                                html += '<strong>' + escapeHtml((tinypressImportExport.i18n.import_success || '%d successfully imported').replace('%d', imported)) + '</strong>';
                                html += '</div>';
                            }

                            if (failed > 0 && response.data.errors && response.data.errors.length > 0) {
                                html += '<div class="tinypress-import-failure">';
                                html += '<strong>' + escapeHtml((tinypressImportExport.i18n.import_failure || '%d failed to import').replace('%d', failed)) + '</strong>';
                                html += '<div class="tinypress-error-list">';
                                $.each(response.data.errors, function(i, errObj) {
                                    html += '<div class="tinypress-error-item">';
                                    html += '<div class="tinypress-error-header">' + escapeHtml(tinypressImportExport.i18n.slug_error || 'Error(s) for TinyPress Slug:') + ' <strong>' + escapeHtml(errObj.slug) + '</strong></div>';
                                    html += '<div class="tinypress-error-message">' + escapeHtml(errObj.reason) + '</div>';
                                    html += '</div>';
                                });
                                html += '</div>';
                                html += '</div>';
                            }

                            if (!html && response.data.message) {
                                html = '<div class="tinypress-import-success"><strong>' + escapeHtml(response.data.message) + '</strong></div>';
                            }

                            $result.addClass('success').html(html).show();
                        } else {
                            $result.addClass('error').html(
                                '<strong>' + escapeHtml(response.data.message || tinypressImportExport.i18n.import_error) + '</strong>'
                            ).show();
                        }

                        currentFile = null;
                        previewData = null;
                        $fileInput.val('');
                        $fileInputSection.show();
                        $fileSelectedActions.hide();
                    }, 500);
                },
                error: function() {
                    clearInterval(progressInterval);
                    $progressSection.hide();
                    $fileInputSection.show();
                    $result.addClass('error').html(
                        '<strong>' + tinypressImportExport.i18n.import_error + '</strong>'
                    ).show();

                    currentFile = null;
                    previewData = null;
                    $fileInput.val('');
                }
            });
        }

        /**
         * Update progress bar
         */
        function updateProgress(percent) {
            percent = Math.min(percent, 100);
            percent = Math.max(percent, 0);
            var $fill = $('.tinypress-progress-fill');
            $fill.css('width', percent + '%');
            $('#tinypress-progress-text').text(Math.round(percent) + '%');
        }
    });

})(jQuery);
