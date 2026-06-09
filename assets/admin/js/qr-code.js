(function($, window, document) {
    'use strict';

    function initCategoriesQrTabs() {
        $(document).on('click', '.tinypress-categories-qr-tab', function() {
            let tab = $(this),
                container = tab.closest('.tinypress-categories-qr-tabs'),
                target_id = tab.data('target'),
                target_panel = container.find('#' + target_id);

            if (!target_panel.length) {
                return;
            }

            container.find('.tinypress-categories-qr-tab').removeClass('is-active').attr('aria-selected', 'false');
            container.find('.tinypress-categories-qr-panel').removeClass('is-active').prop('hidden', true);

            tab.addClass('is-active').attr('aria-selected', 'true');
            target_panel.addClass('is-active').prop('hidden', false);
        });
    }

    function initQRCode() {
        if (typeof tinypressQRCode === 'undefined' || typeof QRCode === 'undefined') {
            return;
        }

        let side_qr_container = $('.side-qr-code'),
            qr_code = new QRCode('qr-code', {
                width: 180,
                height: 180
            }),
            el_qr_code = side_qr_container.find('.qr-code'),
            el_qr_downloader = side_qr_container.find('.qr-download');

        qr_code.makeCode(tinypressQRCode.url);

        setTimeout(function() {
            el_qr_downloader.attr('href', el_qr_code.find('img').attr('src')).attr('download', 'qr-code.png');
        }, 300);
    }

    $(function() {
        initCategoriesQrTabs();
        initQRCode();
    });

})(jQuery, window, document);
