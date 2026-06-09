/**
 * TinyMCE button registration for the Classic Editor.
 */
(function(window) {
  'use strict';

  if (!window.tinymce || !window.tinymce.PluginManager) {
    return;
  }

  const shortlinkIconSvg = [
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">',
    '<rect x="2" y="2" width="20" height="20" rx="4" fill="#655897"/>',
    '<path d="M9.3 14.7l-1.4 1.4a2.3 2.3 0 0 1-3.3-3.3l3.1-3.1a2.3 2.3 0 0 1 3.3 0" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
    '<path d="M14.7 9.3l1.4-1.4a2.3 2.3 0 0 1 3.3 3.3l-3.1 3.1a2.3 2.3 0 0 1-3.3 0" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
    '<path d="M9.8 14.2l4.4-4.4" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"/>',
    '</svg>',
  ].join('');

  window.tinymce.PluginManager.add('tinypress_shortlink', function(editor) {
    editor.addButton('tinypress_shortlink', {
      title: (window.tinypressGutenberg && window.tinypressGutenberg.i18n && window.tinypressGutenberg.i18n.shortlink) || 'Shortlink',
      image: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(shortlinkIconSvg),
      onclick: function() {
        if (window.TinypressShortlinkClassicEditor && typeof window.TinypressShortlinkClassicEditor.open === 'function') {
          window.TinypressShortlinkClassicEditor.open('tinymce', editor.id);
        }
      },
    });
  });
})(window);
