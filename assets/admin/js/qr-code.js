(function($, window, document) {
    'use strict';

    if (typeof tinypressQRCode === 'undefined') {
        return;
    }

    $(document).on('ready', function() {
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
    });

})(jQuery, window, document);
