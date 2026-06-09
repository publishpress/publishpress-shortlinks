/**
 * Classic Editor bridge for the shared shortlink UI.
 */
(function(window, document) {
  'use strict';

  const state = {
    mode: null,
    editorId: null,
  };
  let quicktagsButtonRegistered = false;

  function getI18n() {
    const data = window.tinypressGutenberg || {};
    return data.i18n || {};
  }

  function escapeAttribute(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || typeof value === 'undefined' ? '' : String(value);
    return div.innerHTML;
  }

  function buildLink(detail, selectedText) {
    const url = detail.url || '';
    const text = selectedText || detail.text || url;
    const attributes = [
      'href="' + escapeAttribute(url) + '"',
      'class="tinypress-shortlink"',
    ];

    if (detail.target) {
      attributes.push('target="' + escapeAttribute(detail.target) + '"');
    }

    if (detail.rel) {
      attributes.push('rel="' + escapeAttribute(detail.rel) + '"');
    }

    return '<a ' + attributes.join(' ') + '>' + escapeHtml(text) + '</a>';
  }

  function openShortlinkUI(mode, editorId) {
    state.mode = mode;
    state.editorId = editorId || null;

    if (window.TinypressShortlinkUI && typeof window.TinypressShortlinkUI.show === 'function') {
      window.TinypressShortlinkUI.show();
      return;
    }

    window.alert((getI18n().error || 'Error loading shortlink interface'));
  }

  window.TinypressShortlinkClassicEditor = {
    open: openShortlinkUI,
  };

  document.addEventListener('tinypress-shortlink-insert', function(event) {
    if (!state.mode || !event.detail || !event.detail.url) {
      return;
    }

    if (state.mode === 'tinymce' && window.tinymce) {
      const editor = window.tinymce.get(state.editorId) || window.tinymce.activeEditor;
      if (editor) {
        const selectedText = editor.selection ? editor.selection.getContent({ format: 'text' }) : '';
        editor.insertContent(buildLink(event.detail, selectedText));
      }
    }

    if (state.mode === 'quicktags' && window.QTags) {
      const selectedText = window.getSelection ? window.getSelection().toString() : '';
      window.QTags.insertContent(buildLink(event.detail, selectedText));
    }

    state.mode = null;
    state.editorId = null;
  });

  function registerQuicktagsButton() {
    if (quicktagsButtonRegistered || !window.QTags || typeof window.QTags.addButton !== 'function') {
      return;
    }

    quicktagsButtonRegistered = true;

    window.QTags.addButton(
      'tinypress_shortlink',
      'shortlink',
      function() {
        openShortlinkUI('quicktags');
      },
      '',
      '',
      getI18n().shortlink || 'Shortlink'
    );
  }

  registerQuicktagsButton();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerQuicktagsButton);
  } else {
    registerQuicktagsButton();
  }

  window.addEventListener('load', registerQuicktagsButton);
})(window, document);
