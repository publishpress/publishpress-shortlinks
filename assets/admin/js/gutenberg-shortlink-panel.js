/**
 * Block editor Shortlinks panel.
 *
 * This file is loaded directly by WordPress, so it must stay plain browser JS.
 * Do not add JSX here unless it is compiled before enqueueing.
 */
(function(wp, config, document) {
  'use strict';

  if (
    !wp ||
    !config ||
    !config.enabled ||
    !wp.components ||
    !wp.data ||
    !wp.element ||
    !wp.plugins
  ) {
    return;
  }

  const { Button, TextControl } = wp.components;
  const { createElement, RawHTML, useEffect, useState } = wp.element;
  const { registerPlugin } = wp.plugins;
  const { useDispatch, useSelect } = wp.data;
  const PluginDocumentSettingPanel =
    (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
    (wp.editPost && wp.editPost.PluginDocumentSettingPanel);

  if (!registerPlugin || !PluginDocumentSettingPanel) {
    return;
  }

  const META_KEY = config.metaKey || 'tiny_slug';
  const DEFAULT_I18N = {
    copied: 'Copied',
    copy: 'Copy',
    copyFailed: 'Copy failed',
    opensInNewTab: '(opens in a new tab)',
    editSettings: 'Edit shortlink settings',
    emptySlug: 'Enter a shortlink slug to create a shortlink URL.',
    panelTitle: 'Shortlinks',
    slugLabel: 'Shortlink Slug',
    urlLabel: 'Shortlink URL',
  };
  const i18n = Object.assign({}, DEFAULT_I18N, config.i18n || {});

  function sanitizeSlug(value) {
    const rawValue = value === null || typeof value === 'undefined' ? '' : String(value);

    if (typeof window.wpFeSanitizeTitle === 'function') {
      return window.wpFeSanitizeTitle(rawValue);
    }

    return rawValue
      .replace(/<[^>]+>/ig, '')
      .toLowerCase()
      .replace(/&(?:(?:nbsp)|(?:ndash)|(?:mdash));/g, '-')
      .replace(/[/.]/g, '-')
      .replace(/[^\w\s-]+/g, '')
      .replace(/\s+/g, '-')
      .replace(/-{2,}/g, '-');
  }

  function buildShortlinkUrl(slug) {
    const cleanSlug = sanitizeSlug(slug);

    if (!cleanSlug) {
      return '';
    }

    return String(config.shortlinkBaseUrl || '').replace(/\/+$/, '') + '/' + encodeURIComponent(cleanSlug);
  }

  function copyText(value) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(value).catch(function() {
        return fallbackCopyText(value);
      });
    }

    return fallbackCopyText(value);
  }

  function fallbackCopyText(value) {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);

    return Promise.resolve();
  }

  function speak(message) {
    if (message && wp.a11y && typeof wp.a11y.speak === 'function') {
      wp.a11y.speak(String(message), 'polite');
    }
  }

  function ShortlinksPanel() {
    const [hasInitializedDefault, setHasInitializedDefault] = useState(false);
    const [copied, setCopied] = useState(false);
    const editorData = useSelect(function(select) {
      const editor = select('core/editor');

      return {
        isNewPost: editor.isEditedPostNew(),
        meta: editor.getEditedPostAttribute('meta') || {},
      };
    }, []);
    const { editPost } = useDispatch('core/editor');
    const meta = editorData.meta;
    const slug = typeof meta[META_KEY] === 'undefined' ? '' : String(meta[META_KEY] || '');
    const shortlinkUrl = buildShortlinkUrl(slug);

    useEffect(function() {
      const defaultSlug = sanitizeSlug(config.defaultSlug || '');

      if (hasInitializedDefault) {
        return;
      }

      setHasInitializedDefault(true);

      if (editorData.isNewPost && !slug && defaultSlug) {
        editPost({
          meta: Object.assign({}, meta, {
            [META_KEY]: defaultSlug,
          }),
        });
      }
    }, [editorData.isNewPost, hasInitializedDefault, slug]);

    function updateSlug(value) {
      editPost({
        meta: Object.assign({}, meta, {
          [META_KEY]: sanitizeSlug(value),
        }),
      });
      setCopied(false);
    }

    function onCopy() {
      if (!shortlinkUrl) {
        return;
      }

      copyText(shortlinkUrl).then(function() {
        setCopied(true);
        speak(i18n.copied);
      }, function() {
        speak(i18n.copyFailed);
      });
    }

    return createElement(
      PluginDocumentSettingPanel,
      {
        name: 'tinypress-shortlinks',
        title: i18n.panelTitle,
        className: 'tinypress-gutenberg-shortlink-panel',
      },
      config.beforeShortlinkFieldHtml
        ? createElement(RawHTML, {
          className: 'tinypress-gutenberg-shortlink-panel__extension tinypress-gutenberg-shortlink-panel__extension--before',
        }, config.beforeShortlinkFieldHtml)
        : null,
      createElement(TextControl, {
        label: i18n.slugLabel,
        value: slug,
        onChange: updateSlug,
        __nextHasNoMarginBottom: true,
      }),
      createElement(
        'div',
        { className: 'tinypress-gutenberg-shortlink-panel__url' },
        createElement(
          'span',
          { className: 'tinypress-gutenberg-shortlink-panel__url-label' },
          i18n.urlLabel
        ),
        shortlinkUrl
          ? createElement(
            'code',
            { className: 'tinypress-gutenberg-shortlink-panel__url-value' },
            shortlinkUrl
          )
          : createElement(
            'span',
            { className: 'tinypress-gutenberg-shortlink-panel__empty' },
            i18n.emptySlug
          )
      ),
      createElement(
        'div',
        { className: 'tinypress-gutenberg-shortlink-panel__actions' },
        createElement(Button, {
          disabled: !shortlinkUrl,
          isSecondary: true,
          onClick: onCopy,
        }, copied ? i18n.copied : i18n.copy),
        config.linkedShortlinkEditUrl
          ? createElement(
            'a',
            {
              className: 'tinypress-gutenberg-shortlink-panel__settings',
              href: config.linkedShortlinkEditUrl,
              target: '_blank',
              rel: 'noopener noreferrer',
            },
            i18n.editSettings,
            createElement('span', { className: 'screen-reader-text' }, ' ' + i18n.opensInNewTab)
          )
          : null
      ),
      config.afterShortlinkFieldHtml
        ? createElement(RawHTML, {
          className: 'tinypress-gutenberg-shortlink-panel__extension tinypress-gutenberg-shortlink-panel__extension--after',
        }, config.afterShortlinkFieldHtml)
        : null
    );
  }

  registerPlugin('tinypress-shortlinks-panel', {
    icon: 'admin-links',
    render: ShortlinksPanel,
  });
})(window.wp, window.tinypressShortlinksMetabox || {}, document);
