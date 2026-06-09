/**
 * Shared shortlink UI for WordPress editors.
 *
 * This file is loaded directly by WordPress, so it must stay plain browser JS.
 * Do not add JSX here unless it is compiled before enqueueing.
 */
(function(window, document, $) {
  'use strict';

  const DEFAULT_I18N = {
    shortlink: 'Shortlink',
    searchPlaceholder: 'Search shortlinks...',
    insertShortlink: 'Insert Shortlink',
    createNew: 'Create New Shortlink',
    openInNewTab: 'Open in New Tab',
    nofollow: 'Nofollow',
    sponsoredLink: 'Sponsored Link',
    label: 'Label',
    labelPlaceholder: 'Optional shortlink label',
    targetUrl: 'Target URL',
    create: 'Create',
    cancel: 'Cancel',
    close: 'Close',
    options: 'Options',
    creating: 'Creating...',
    created: 'Shortlink created successfully.',
    error: 'Error',
    invalidUrl: 'Please enter a valid URL',
    noResults: 'No shortlinks found',
  };

  function getConfig() {
    const raw = window.tinypressGutenberg || {};
    const canCreateShortlinks =
      raw.canCreateShortlinks === true ||
      raw.canCreateShortlinks === 1 ||
      raw.canCreateShortlinks === '1';

    return {
      ajaxurl: raw.ajaxurl || '',
      nonce: raw.nonce || '',
      canCreateShortlinks,
      i18n: Object.assign({}, DEFAULT_I18N, raw.i18n || {}),
    };
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || typeof value === 'undefined' ? '' : String(value);
    return div.innerHTML;
  }

  function normalizeResult(result) {
    if (!result || typeof result !== 'object') {
      return null;
    }

    const url = result.url || result.shortlink_url || '';
    if (!url) {
      return null;
    }

    return {
      id: result.id || '',
      title: result.title || result.slug || url,
      slug: result.slug || '',
      url,
      targetUrl: result.target_url || result.targetUrl || '',
    };
  }

  function getModalHTML(config) {
    const i18n = config.i18n;

    return `
      <div id="tinypress-shortlink-modal" class="tinypress-shortlink-modal" aria-hidden="true">
        <div class="tinypress-shortlink-modal-backdrop"></div>
        <div class="tinypress-shortlink-modal-content" role="dialog" aria-modal="true" aria-labelledby="tinypress-shortlink-title">
          <div class="tinypress-shortlink-modal-header">
            <h2 id="tinypress-shortlink-title">${escapeHtml(i18n.shortlink)}</h2>
            <button type="button" class="tinypress-shortlink-modal-close" aria-label="${escapeHtml(i18n.close)}">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>

          <div class="tinypress-shortlink-modal-body">
            <div class="tinypress-shortlink-search-section">
              <div class="tinypress-shortlink-search-wrapper">
                <input
                  type="search"
                  class="tinypress-shortlink-search-input"
                  placeholder="${escapeHtml(i18n.searchPlaceholder)}"
                  autocomplete="off"
                />
                <button type="button" class="tinypress-shortlink-insert-btn" title="${escapeHtml(i18n.insertShortlink)}" aria-label="${escapeHtml(i18n.insertShortlink)}">
                  <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                </button>
                <button type="button" class="tinypress-shortlink-toggle-options" title="${escapeHtml(i18n.options)}" aria-label="${escapeHtml(i18n.options)}" aria-expanded="false">
                  <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
              </div>
            </div>

            <div class="tinypress-shortlink-results-wrapper" aria-live="polite"></div>

            <div class="tinypress-shortlink-advanced-panel" style="display: none;">
              <div class="tinypress-shortlink-options">
                <label>
                  <input type="checkbox" class="tinypress-shortlink-option" data-option="openInNewTab" />
                  ${escapeHtml(i18n.openInNewTab)}
                </label>
                <label>
                  <input type="checkbox" class="tinypress-shortlink-option" data-option="noFollow" />
                  ${escapeHtml(i18n.nofollow)}
                </label>
                <label>
                  <input type="checkbox" class="tinypress-shortlink-option" data-option="sponsored" />
                  ${escapeHtml(i18n.sponsoredLink)}
                </label>
              </div>

              ${config.canCreateShortlinks ? `
                <div class="tinypress-shortlink-create-section">
                  <h4>${escapeHtml(i18n.createNew)}</h4>
                  <div class="tinypress-shortlink-form-group">
                    <label>${escapeHtml(i18n.label)}</label>
                    <input type="text" class="tinypress-shortlink-create-label" placeholder="${escapeHtml(i18n.labelPlaceholder)}" />
                  </div>
                  <div class="tinypress-shortlink-form-group">
                    <label>${escapeHtml(i18n.targetUrl)}</label>
                    <input type="url" class="tinypress-shortlink-create-url" placeholder="https://example.com" required />
                  </div>
                  <button type="button" class="tinypress-shortlink-create-btn button button-primary">
                    ${escapeHtml(i18n.create)}
                  </button>
                </div>
              ` : ''}
            </div>
          </div>

          <div class="tinypress-shortlink-modal-footer">
            <button type="button" class="button button-secondary tinypress-shortlink-modal-close-btn">
              ${escapeHtml(i18n.cancel)}
            </button>
          </div>
        </div>
      </div>
    `;
  }

  class ShortlinkUIManager {
    constructor() {
      this.modal = null;
      this.config = getConfig();
      this.options = {
        openInNewTab: false,
        noFollow: false,
        sponsored: false,
      };
      this.results = [];
      this.selectedIndex = -1;
      this.searchTimer = null;
    }

    init() {
      this.config = getConfig();

      if (!document.getElementById('tinypress-shortlink-modal')) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = getModalHTML(this.config).trim();

        if (tempDiv.firstElementChild) {
          document.body.appendChild(tempDiv.firstElementChild);
        }
      }

      this.modal = document.getElementById('tinypress-shortlink-modal');

      if (!this.modal || this.modal.dataset.tinypressReady === '1') {
        return;
      }

      this.attachEventListeners();
      this.modal.dataset.tinypressReady = '1';
    }

    attachEventListeners() {
      this.modal.querySelectorAll('.tinypress-shortlink-modal-close, .tinypress-shortlink-modal-close-btn').forEach((button) => {
        button.addEventListener('click', () => this.close());
      });

      const backdrop = this.modal.querySelector('.tinypress-shortlink-modal-backdrop');
      if (backdrop) {
        backdrop.addEventListener('click', () => this.close());
      }

      const searchInput = this.modal.querySelector('.tinypress-shortlink-search-input');
      if (searchInput) {
        searchInput.addEventListener('input', () => this.queueSearch());
        searchInput.addEventListener('keydown', (event) => this.onSearchKeyDown(event));
      }

      const insertButton = this.modal.querySelector('.tinypress-shortlink-insert-btn');
      if (insertButton) {
        insertButton.addEventListener('click', () => this.insertSelectedOrSearch());
      }

      const optionsToggle = this.modal.querySelector('.tinypress-shortlink-toggle-options');
      if (optionsToggle) {
        optionsToggle.addEventListener('click', () => this.toggleOptions());
      }

      this.modal.querySelectorAll('.tinypress-shortlink-option').forEach((checkbox) => {
        checkbox.addEventListener('change', (event) => {
          const option = event.target.dataset.option;
          if (option && Object.prototype.hasOwnProperty.call(this.options, option)) {
            this.options[option] = event.target.checked;
          }
        });
      });

      const createButton = this.modal.querySelector('.tinypress-shortlink-create-btn');
      if (createButton) {
        createButton.addEventListener('click', () => this.createShortlink());
      }

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && this.isOpen()) {
          this.close();
        }
      });
    }

    show() {
      this.init();

      if (!this.modal) {
        return false;
      }

      this.config = getConfig();
      this.modal.style.display = 'block';
      this.modal.setAttribute('aria-hidden', 'false');

      const searchInput = this.modal.querySelector('.tinypress-shortlink-search-input');
      if (searchInput) {
        window.setTimeout(() => searchInput.focus(), 0);
      }

      return true;
    }

    close() {
      if (this.modal) {
        this.modal.style.display = 'none';
        this.modal.setAttribute('aria-hidden', 'true');
      }

      this.reset();
    }

    isOpen() {
      return this.modal && this.modal.getAttribute('aria-hidden') === 'false';
    }

    reset() {
      if (!this.modal) {
        return;
      }

      const searchInput = this.modal.querySelector('.tinypress-shortlink-search-input');
      const labelInput = this.modal.querySelector('.tinypress-shortlink-create-label');
      const urlInput = this.modal.querySelector('.tinypress-shortlink-create-url');
      const resultsWrapper = this.modal.querySelector('.tinypress-shortlink-results-wrapper');
      const advancedPanel = this.modal.querySelector('.tinypress-shortlink-advanced-panel');
      const optionsToggle = this.modal.querySelector('.tinypress-shortlink-toggle-options');

      if (searchInput) {
        searchInput.value = '';
      }

      if (labelInput) {
        labelInput.value = '';
      }

      if (urlInput) {
        urlInput.value = '';
      }

      if (resultsWrapper) {
        resultsWrapper.innerHTML = '';
      }

      if (advancedPanel) {
        advancedPanel.style.display = 'none';
      }

      if (optionsToggle) {
        optionsToggle.setAttribute('aria-expanded', 'false');
      }

      this.modal.querySelectorAll('.tinypress-shortlink-option').forEach((checkbox) => {
        checkbox.checked = false;
      });

      this.options = {
        openInNewTab: false,
        noFollow: false,
        sponsored: false,
      };
      this.results = [];
      this.selectedIndex = -1;
    }

    toggleOptions() {
      const advancedPanel = this.modal.querySelector('.tinypress-shortlink-advanced-panel');
      const optionsToggle = this.modal.querySelector('.tinypress-shortlink-toggle-options');

      if (!advancedPanel) {
        return;
      }

      const isOpen = advancedPanel.style.display !== 'none';
      advancedPanel.style.display = isOpen ? 'none' : 'block';

      if (optionsToggle) {
        optionsToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      }
    }

    queueSearch() {
      window.clearTimeout(this.searchTimer);

      this.searchTimer = window.setTimeout(() => {
        const searchInput = this.modal.querySelector('.tinypress-shortlink-search-input');
        const searchTerm = searchInput ? searchInput.value.trim() : '';

        if (searchTerm.length >= 2) {
          this.search();
        } else {
          this.clearResults();
        }
      }, 250);
    }

    onSearchKeyDown(event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        this.selectResult(this.selectedIndex + 1);
        return;
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        this.selectResult(this.selectedIndex - 1);
        return;
      }

      if (event.key === 'Enter') {
        event.preventDefault();
        this.insertSelectedOrSearch();
      }
    }

    clearResults() {
      const resultsWrapper = this.modal.querySelector('.tinypress-shortlink-results-wrapper');
      if (resultsWrapper) {
        resultsWrapper.innerHTML = '';
      }

      this.results = [];
      this.selectedIndex = -1;
    }

    search() {
      const searchInput = this.modal.querySelector('.tinypress-shortlink-search-input');
      const searchTerm = searchInput ? searchInput.value.trim() : '';
      const resultsWrapper = this.modal.querySelector('.tinypress-shortlink-results-wrapper');

      if (!searchTerm) {
        this.showNotice('empty', this.config.i18n.noResults);
        return;
      }

      if (!$ || !this.config.ajaxurl || !this.config.nonce) {
        this.showNotice('error', this.config.i18n.error);
        return;
      }

      if (resultsWrapper) {
        resultsWrapper.innerHTML = '<div class="tinypress-shortlink-loading"><span class="spinner is-active"></span></div>';
      }

      $.post(
        this.config.ajaxurl,
        {
          action: 'tinypress_search_shortlinks',
          search: searchTerm,
          page: 1,
          nonce: this.config.nonce,
        },
        (response) => {
          if (!response || !response.success) {
            const message = response && response.data && response.data.message ? response.data.message : this.config.i18n.error;
            this.showNotice('error', message);
            return;
          }

          const results = Array.isArray(response.data && response.data.results)
            ? response.data.results.map(normalizeResult).filter(Boolean)
            : [];

          this.displayResults(results);
        }
      ).fail(() => {
        this.showNotice('error', this.config.i18n.error);
      });
    }

    displayResults(results) {
      const resultsWrapper = this.modal.querySelector('.tinypress-shortlink-results-wrapper');

      this.results = results || [];
      this.selectedIndex = this.results.length ? 0 : -1;

      if (!resultsWrapper) {
        return;
      }

      if (!this.results.length) {
        resultsWrapper.innerHTML = `<div class="tinypress-shortlink-no-results">${escapeHtml(this.config.i18n.noResults)}</div>`;
        return;
      }

      const html = this.results.map((result, index) => `
        <button type="button" class="tinypress-shortlink-result-item${index === this.selectedIndex ? ' is-selected' : ''}" data-index="${index}">
          <span class="tinypress-shortlink-result-title">${escapeHtml(result.title)}</span>
          <span class="tinypress-shortlink-result-url">${escapeHtml(result.url)}</span>
          ${result.targetUrl ? `<span class="tinypress-shortlink-result-target">${escapeHtml(result.targetUrl)}</span>` : ''}
        </button>
      `).join('');

      resultsWrapper.innerHTML = `<div class="tinypress-shortlink-results">${html}</div>`;

      resultsWrapper.querySelectorAll('.tinypress-shortlink-result-item').forEach((item) => {
        item.addEventListener('click', () => {
          this.selectResult(parseInt(item.dataset.index, 10));
        });

        item.addEventListener('dblclick', () => {
          this.selectResult(parseInt(item.dataset.index, 10));
          this.insertSelectedOrSearch();
        });
      });
    }

    selectResult(index) {
      if (!this.results.length) {
        this.selectedIndex = -1;
        return;
      }

      if (index < 0) {
        index = this.results.length - 1;
      } else if (index >= this.results.length) {
        index = 0;
      }

      this.selectedIndex = index;

      this.modal.querySelectorAll('.tinypress-shortlink-result-item').forEach((item) => {
        item.classList.toggle('is-selected', parseInt(item.dataset.index, 10) === this.selectedIndex);
      });
    }

    insertSelectedOrSearch() {
      const result = this.results[this.selectedIndex];

      if (result) {
        this.insertShortlink(result);
        return;
      }

      this.search();
    }

    createShortlink() {
      const labelInput = this.modal.querySelector('.tinypress-shortlink-create-label');
      const urlInput = this.modal.querySelector('.tinypress-shortlink-create-url');
      const createButton = this.modal.querySelector('.tinypress-shortlink-create-btn');
      const label = labelInput ? labelInput.value.trim() : '';
      const targetUrl = urlInput ? urlInput.value.trim() : '';

      if (!targetUrl || !this.isValidUrl(targetUrl)) {
        this.showNotice('error', this.config.i18n.invalidUrl);
        return;
      }

      if (!$ || !this.config.ajaxurl || !this.config.nonce) {
        this.showNotice('error', this.config.i18n.error);
        return;
      }

      if (createButton) {
        createButton.disabled = true;
        createButton.textContent = this.config.i18n.creating;
      }

      $.post(
        this.config.ajaxurl,
        {
          action: 'tinypress_create_shortlink',
          label: label,
          target_url: targetUrl,
          nonce: this.config.nonce,
        },
        (response) => {
          if (createButton) {
            createButton.disabled = false;
            createButton.textContent = this.config.i18n.create;
          }

          if (!response || !response.success) {
            const message = response && response.data && response.data.message ? response.data.message : this.config.i18n.error;
            this.showNotice('error', message);
            return;
          }

          const result = normalizeResult(response.data);
          if (!result) {
            this.showNotice('error', this.config.i18n.error);
            return;
          }

          if (urlInput) {
            urlInput.value = '';
          }

          if (labelInput) {
            labelInput.value = '';
          }

          this.showNotice('success', this.config.i18n.created);
          this.insertShortlink(result);
        }
      ).fail(() => {
        if (createButton) {
          createButton.disabled = false;
          createButton.textContent = this.config.i18n.create;
        }

        this.showNotice('error', this.config.i18n.error);
      });
    }

    insertShortlink(result) {
      const rel = this.buildRel();
      const detail = {
        url: result.url,
        text: result.title || result.url,
        target: this.options.openInNewTab ? '_blank' : '',
        rel,
      };

      document.dispatchEvent(new CustomEvent('tinypress-shortlink-insert', { detail }));
      this.close();
    }

    buildRel() {
      const rel = [];

      if (this.options.noFollow) {
        rel.push('nofollow');
      }

      if (this.options.sponsored) {
        rel.push('sponsored');
      }

      if (this.options.openInNewTab) {
        rel.push('noopener', 'noreferrer');
      }

      return rel.filter((value, index, values) => values.indexOf(value) === index).join(' ');
    }

    showNotice(type, message) {
      const resultsWrapper = this.modal.querySelector('.tinypress-shortlink-results-wrapper');
      const className = type === 'error'
        ? 'tinypress-shortlink-error'
        : type === 'success'
          ? 'tinypress-shortlink-success'
          : 'tinypress-shortlink-no-results';

      if (resultsWrapper) {
        resultsWrapper.innerHTML = `<div class="${className}">${escapeHtml(message)}</div>`;
      }
    }

    isValidUrl(value) {
      try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
      } catch (error) {
        return false;
      }
    }
  }

  window.TinypressShortlinkUI = new ShortlinkUIManager();

  function initializeUI() {
    window.TinypressShortlinkUI.init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUI);
  } else {
    initializeUI();
  }
})(window, document, window.jQuery);
