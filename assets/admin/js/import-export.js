(function($) {
    'use strict';

    $(document).ready(function() {
        var $form      = $('#tinypress-import-form');
        var $fileInput = $('#tinypress-csv-file');
        var $btn       = $('#tinypress-import-btn');
        var $result    = $('#tinypress-import-result');

        $form.on('submit', function(e) {
            e.preventDefault();

            var file = $fileInput[0].files[0];
            if (!file) {
                alert(tinypressImportExport.i18n.no_file);
                return;
            }

            if (!confirm(tinypressImportExport.i18n.confirm_import)) {
                return;
            }

            var formData = new FormData();
            formData.append('action', 'tinypress_import_csv');
            formData.append('nonce', tinypressImportExport.nonce);
            formData.append('csv_file', file);

            $btn.prop('disabled', true).text(tinypressImportExport.i18n.importing);
            $result.hide().removeClass('success error').empty();

            $.ajax({
                url: tinypressImportExport.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-upload"></span> Import CSV'
                    );

                    if (response.success) {
                        var html = '<strong>' + response.data.message + '</strong>';

                        if (response.data.errors && response.data.errors.length > 0) {
                            html += '<ul class="import-errors">';
                            $.each(response.data.errors, function(i, err) {
                                html += '<li>' + err + '</li>';
                            });
                            html += '</ul>';
                        }

                        $result.addClass('success').html(html).show();
                    } else {
                        $result.addClass('error').html(
                            '<strong>' + (response.data.message || tinypressImportExport.i18n.import_error) + '</strong>'
                        ).show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-upload"></span> Import CSV'
                    );
                    $result.addClass('error').html(
                        '<strong>' + tinypressImportExport.i18n.import_error + '</strong>'
                    ).show();
                }
            });
        });
    });

})(jQuery);
