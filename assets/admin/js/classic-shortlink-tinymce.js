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
    '<path d="M8.5 14.8L6.3 17a2.5 2.5 0 1 1-3.5-3.5l5.2-5.2a2.5 2.5 0 1 1 3.5 3.5l-.8.8" fill="none" stroke="#FFCC00" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>',
    '<path d="M15.5 9.2l2.2-2.2a2.5 2.5 0 1 1 3.5 3.5L16 15.7a2.5 2.5 0 1 1-3.5-3.5l.8-.8" fill="none" stroke="#FFCC00" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>',
    '<path d="M10 14l4-4" fill="none" stroke="#FFCC00" stroke-width="3" stroke-linecap="round"/>',
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
