(function($) {
    'use strict';

    function setProgress($card, percent) {
        percent = Math.max(0, Math.min(100, percent));
        $card.find('.tinypress-migration-progress-fill').css('width', percent + '%');
        $card.find('.tinypress-migration-progress-text').text(Math.round(percent) + '%');
    }

    function setResult($card, message, isError) {
        $card.find('.tinypress-migration-result')
            .toggleClass('is-error', !!isError)
            .toggleClass('is-success', !isError)
            .text(message || '');
    }

    function showCompleteNotice($card, data) {
        var sourceName = data.source_name || '';
        var deactivateUrl = data.deactivate_url || '';

        if (!sourceName || !deactivateUrl) {
            return;
        }

        var noticeId = 'tinypress-migration-complete-' + ($card.data('source') || '').toString();
        $('#' + noticeId).remove();

        var $notice = $('<div/>', {
            id: noticeId,
            class: 'notice notice-success is-dismissible tinypress-migration-complete-notice'
        });
        var $message = $('<p/>').text(
            'All ' + sourceName + ' links have been successfully migrated to PublishPress Shortlinks. You can now safely deactivate ' + sourceName + ' on your website. '
        );
        $('<a/>', {
            class: 'button button-secondary',
            href: deactivateUrl,
            text: (tinypressMigration.i18n.deactivate || 'Deactivate %s').replace('%s', sourceName)
        }).appendTo($message);

        $('<button/>', {
            type: 'button',
            class: 'notice-dismiss'
        }).append($('<span/>', {
            class: 'screen-reader-text',
            text: 'Dismiss this notice.'
        })).on('click', function() {
            $notice.remove();
        }).appendTo($notice);

        $notice.append($message);
        $('.tinypress-migration-grid').before($notice);
    }

    function runBatch($card, source, offset, totals) {
        $.ajax({
            url: tinypressMigration.ajax_url,
            type: 'POST',
            data: {
                action: 'tinypress_run_migration',
                nonce: tinypressMigration.nonce,
                source: source,
                offset: offset,
                limit: 25
            }
        }).done(function(response) {
            if (!response || !response.success) {
                setResult($card, response && response.data && response.data.message ? response.data.message : tinypressMigration.i18n.failed, true);
                $card.find('.tinypress-migration-run').prop('disabled', false);
                return;
            }

            var data = response.data || {};
            totals.imported += parseInt(data.imported || 0, 10);
            totals.updated += parseInt(data.updated || 0, 10);
            totals.skipped += parseInt(data.skipped || 0, 10);

            var total = parseInt(data.total || 0, 10);
            var processed = parseInt(data.processed || 0, 10);
            var percent = total > 0 ? (processed / total) * 100 : 100;
            setProgress($card, percent);

            if (data.done) {
                setProgress($card, 100);
                setResult(
                    $card,
                    tinypressMigration.i18n.complete + ' ' +
                    totals.imported + ' imported, ' +
                    totals.updated + ' updated, ' +
                    totals.skipped + ' skipped.',
                    false
                );
                showCompleteNotice($card, data);
                $card.find('.tinypress-migration-run').prop('disabled', false);
                return;
            }

            runBatch($card, source, processed, totals);
        }).fail(function() {
            setResult($card, tinypressMigration.i18n.failed, true);
            $card.find('.tinypress-migration-run').prop('disabled', false);
        });
    }

    $(document).on('click', '.tinypress-migration-run', function() {
        var $button = $(this);
        var source = $button.data('source');
        var $card = $button.closest('.tinypress-migration-card');

        $button.prop('disabled', true);
        $card.find('.tinypress-migration-progress').prop('hidden', false);
        setProgress($card, 0);
        setResult($card, tinypressMigration.i18n.running, false);

        runBatch($card, source, 0, {
            imported: 0,
            updated: 0,
            skipped: 0
        });
    });
})(jQuery);
