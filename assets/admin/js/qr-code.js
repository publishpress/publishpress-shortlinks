(function($, window, document) {
    'use strict';

    let tinypressQrObjectUrls = [];

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

            initQRCode(target_panel);
        });
    }

    function getQRCodeUrl(container) {
        let url = container.data('qr-url');

        if (!url && typeof tinypressQRCode !== 'undefined') {
            url = tinypressQRCode.url;
        }

        return $.trim(url || '');
    }

    function revokeOldObjectUrl(url) {
        if (!url || url.indexOf('blob:') !== 0 || !window.URL || !window.URL.revokeObjectURL) {
            return;
        }

        window.URL.revokeObjectURL(url);
        tinypressQrObjectUrls = tinypressQrObjectUrls.filter(function(objectUrl) {
            return objectUrl !== url;
        });
    }

    function getSvgDownloadUrl(svg) {
        let serializedSvg;
        let blob;
        let objectUrl;

        if (!svg) {
            return '';
        }

        serializedSvg = new XMLSerializer().serializeToString(svg);

        if (window.Blob && window.URL && window.URL.createObjectURL) {
            blob = new Blob([serializedSvg], {
                type: 'image/svg+xml;charset=utf-8'
            });
            objectUrl = window.URL.createObjectURL(blob);
            tinypressQrObjectUrls.push(objectUrl);

            return objectUrl;
        }

        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(serializedSvg);
    }

    function getGeneratedDownloadUrl(qrElement) {
        let image = qrElement.find('img').first(),
            canvas = qrElement.find('canvas').first(),
            svg = qrElement.find('svg').first()[0],
            imageSource = image.attr('src') || '';

        if (imageSource) {
            return imageSource;
        }

        if (canvas.length && canvas[0].toDataURL) {
            return canvas[0].toDataURL('image/png');
        }

        return getSvgDownloadUrl(svg);
    }

    function updateDownloadLink(container) {
        let qrElement = container.find('.qr-code').first(),
            downloader = container.find('.qr-download').first(),
            previousUrl = downloader.attr('href'),
            downloadUrl = getGeneratedDownloadUrl(qrElement),
            isSvg = downloadUrl.indexOf('image/svg+xml') !== -1 || downloadUrl.indexOf('blob:') === 0;

        if (!downloader.length || !downloadUrl) {
            return;
        }

        revokeOldObjectUrl(previousUrl);

        downloader
            .attr('href', downloadUrl)
            .attr('download', isSvg ? 'qr-code.svg' : 'qr-code.png')
            .removeAttr('aria-disabled');
    }

    function initQRCode(scope) {
        if (typeof QRCode === 'undefined') {
            return;
        }

        $(scope || document).find('.side-qr-code').each(function() {
            let container = $(this),
                qrElement = container.find('.qr-code').first(),
                url = getQRCodeUrl(container),
                previousUrl = container.find('.qr-download').first().attr('href');

            if (!qrElement.length || !url) {
                return;
            }

            if (container.data('tinypressQrRendered') === url && qrElement.children().length) {
                updateDownloadLink(container);
                return;
            }

            revokeOldObjectUrl(previousUrl);
            qrElement.empty();

            new QRCode(qrElement[0], {
                width: 180,
                height: 180,
                text: url
            });

            container.data('tinypressQrRendered', url);

            setTimeout(function() {
                updateDownloadLink(container);
            }, 0);
            setTimeout(function() {
                updateDownloadLink(container);
            }, 300);
            setTimeout(function() {
                updateDownloadLink(container);
            }, 1000);
        });
    }

    $(function() {
        initCategoriesQrTabs();
        initQRCode();

        $(document).on('click', '.wpdk_settings-nav-metabox a[data-section], .wpdk_settings-nav-options a[data-tab-id]', function() {
            setTimeout(function() {
                initQRCode();
            }, 0);
            setTimeout(function() {
                initQRCode();
            }, 250);
        });
    });

    window.tinypressInitQRCode = initQRCode;

})(jQuery, window, document);
