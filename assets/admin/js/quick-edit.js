(function ($) {
	'use strict';

	if (typeof inlineEditPost === 'undefined') {
		return;
	}

	var originalEdit = inlineEditPost.edit;
	inlineEditPost.edit = function (id) {
		originalEdit.apply(this, arguments);

		var postId = typeof id === 'object' ? parseInt(this.getId(id), 10) : parseInt(id, 10);
		if (!postId) {
			return;
		}

		var $row = $('#post-' + postId);
		var $editRow = $('#edit-' + postId);
		var $data = $row.find('.tinypress-quick-edit-data');
		var $quickEditFields = $editRow.find('.tinypress-quick-edit-fields .inline-edit-col');
		var $postDate = $editRow.find('.inline-edit-date').detach().addClass('hidden');
		var $postStatus = $editRow.find('.tinypress-quick-edit-post-status');

		// Keep only Shortlinks fields and the Shortlinks category checklist.
		$editRow.find('fieldset').not('.tinypress-quick-edit-fields, .inline-edit-categories').remove();
		$quickEditFields.append($postDate);
		if ('1' === $postStatus.attr('data-can-publish') && !$postStatus.find('option[value="publish"]').length) {
			$('<option>', {value: 'publish', text: tinypressQuickEdit.publishedLabel}).insertAfter($postStatus.find('option[value="-1"]'));
		}
		$postStatus.val('-1');
		$editRow.find('.tinypress-quick-edit-link-title').val($data.attr('data-link-title') || '');
		$editRow.find('.tinypress-quick-edit-target-url').val($data.attr('data-target-url') || '');
		$editRow.find('.tinypress-quick-edit-tiny-slug').val($data.attr('data-tiny-slug') || '');
		$editRow.find('.tinypress-quick-edit-link-status').val($data.attr('data-link-status') || '1');
	};

	var originalSave = inlineEditPost.save;
	inlineEditPost.save = function (id) {
		var $editRow = $(id).closest('tr.inline-edit-row');
		var linkTitle = $editRow.find('.tinypress-quick-edit-link-title').get(0);
		var targetUrl = $editRow.find('.tinypress-quick-edit-target-url').get(0);
		var $postStatus = $editRow.find('.tinypress-quick-edit-post-status');
		var preservePostStatus = '-1' === $postStatus.val();

		if (linkTitle && !linkTitle.checkValidity()) {
			linkTitle.reportValidity();
			return false;
		}

		if (targetUrl) {
			var allowedSchemes = ['http:', 'https:', 'ftp:', 'ftps:', 'mailto:'];
			var validScheme = false;

			try {
				validScheme = allowedSchemes.indexOf(new URL(targetUrl.value.trim()).protocol) !== -1;
			} catch (error) {
				validScheme = false;
			}

			targetUrl.setCustomValidity(validScheme ? '' : tinypressQuickEdit.invalidUrl);

			if (!targetUrl.checkValidity()) {
				targetUrl.reportValidity();
				return false;
			}
		}

		if (preservePostStatus) {
			$postStatus.prop('disabled', true);
		}

		var result = originalSave.apply(this, arguments);

		if (preservePostStatus) {
			$postStatus.prop('disabled', false);
		}

		return result;
	};
})(jQuery);
