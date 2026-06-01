/**
 * Gutenberg Shortlink Format Registration
 * Uses WordPress globals without requiring build system
 */
(function(wp) {
  if (!wp) return;

  const { __ } = wp.i18n;
  const { registerFormatType } = wp.richText;
  const { BlockControls, RichTextShortcut } = wp.blockEditor;
  const { ToolbarGroup, ToolbarButton, Icon } = wp.components;
  const { Component, Fragment } = wp.element;

  /**
   * Shortlink Icon Component
   */
function ShortlinkIcon() {
  return wp.element.createElement(
    'svg',
    {
      className: 'tinypress-shortlink-editor-icon',
      xmlns: 'http://www.w3.org/2000/svg',
      width: '24',
      height: '24',
      viewBox: '0 0 24 24',
      role: 'img',
      'aria-hidden': 'true',
      focusable: 'false'
    },

    wp.element.createElement('rect', {
      x: '2',
      y: '2',
      width: '20',
      height: '20',
      rx: '4',
      fill: '#655897'
    }),

    wp.element.createElement('path', {
      d: 'M9.3 14.7l-1.4 1.4a2.3 2.3 0 0 1-3.3-3.3l3.1-3.1a2.3 2.3 0 0 1 3.3 0',
      fill: 'none',
      stroke: '#FCB223',
      strokeWidth: '2.2',
      strokeLinecap: 'round',
      strokeLinejoin: 'round'
    }),

    wp.element.createElement('path', {
      d: 'M14.7 9.3l1.4-1.4a2.3 2.3 0 0 1 3.3 3.3l-3.1 3.1a2.3 2.3 0 0 1-3.3 0',
      fill: 'none',
      stroke: '#FFFFFF',
      strokeWidth: '2.2',
      strokeLinecap: 'round',
      strokeLinejoin: 'round'
    }),

    wp.element.createElement('path', {
      d: 'M9.8 14.2l4.4-4.4',
      fill: 'none',
      stroke: '#FCB223',
      strokeWidth: '2.2',
      strokeLinecap: 'round'
    })
  );
}

  /**
   * Shortlink Edit Component
   */
  class ShortlinkEdit extends Component {
    constructor(props) {
      super(props);
      this.state = {
        showUI: false,
      };

      this.addShortlink = this.addShortlink.bind(this);
      this.stopAddingShortlink = this.stopAddingShortlink.bind(this);
      this.onRemoveFormat = this.onRemoveFormat.bind(this);
      this.onShortlinkInsert = this.onShortlinkInsert.bind(this);
    }

    componentDidMount() {
      document.addEventListener('tinypress-shortlink-insert', this.onShortlinkInsert);
    }

    componentWillUnmount() {
      document.removeEventListener('tinypress-shortlink-insert', this.onShortlinkInsert);
    }

    addShortlink() {
      const attemptShow = (attempts = 0) => {
        if (window.TinypressShortlinkUI && typeof window.TinypressShortlinkUI.show === 'function') {
          const didShow = window.TinypressShortlinkUI.show();
          if (didShow) {
            return;
          }
        }
        
        if (attempts < 10) {
          setTimeout(() => attemptShow(attempts + 1), 100);
        } else {
          console.error('Shortlink UI failed to initialize after 10 attempts');
          if (typeof tinypressGutenberg !== 'undefined' && tinypressGutenberg.i18n) {
            alert(tinypressGutenberg.i18n.error);
          } else {
            alert('Error loading shortlink interface');
          }
        }
      };
      
      attemptShow();
    }

    stopAddingShortlink() {
      this.setState({ showUI: false });
    }

    onRemoveFormat() {
      const { value, onChange } = this.props;
      const { removeFormat } = wp.richText;
      onChange(removeFormat(value, 'tinypress/shortlink'));
    }

    onShortlinkInsert(event) {
      const { value, onChange } = this.props;
      const { create, insert, isCollapsed, applyFormat, slice, getTextContent } = wp.richText;
      const { url, text, target, rel } = event.detail;

      const selectedText = getTextContent(slice(value));
      const linkText = selectedText || text || url;

      const format = {
        type: 'tinypress/shortlink',
        attributes: {
          url: url,
          target: target,
          rel: rel,
        }
      };

      if (isCollapsed(value)) {
        const toInsert = applyFormat(create({ text: linkText }), format, 0, linkText.length);
        onChange(insert(value, toInsert));
      } else {
        onChange(applyFormat(value, format));
      }
    }

    render() {
      const { isActive } = this.props;

      return wp.element.createElement(
        Fragment,
        null,
        wp.element.createElement(RichTextShortcut, {
          type: 'primary',
          character: 'l',
          onUse: this.addShortlink,
        }),
        wp.element.createElement(RichTextShortcut, {
          type: 'primaryShift',
          character: 'l',
          onUse: this.onRemoveFormat,
        }),
        wp.element.createElement(
          BlockControls,
          null,
          wp.element.createElement(
            ToolbarGroup,
            null,
            isActive ? wp.element.createElement(ToolbarButton, {
              icon: wp.element.createElement(Icon, { icon: wp.element.createElement(ShortlinkIcon) }),
              title: tinypressGutenberg.i18n.shortlink,
              onClick: this.onRemoveFormat,
              isActive: isActive,
            }) : wp.element.createElement(ToolbarButton, {
              icon: wp.element.createElement(Icon, { icon: wp.element.createElement(ShortlinkIcon) }),
              title: tinypressGutenberg.i18n.shortlink,
              onClick: this.addShortlink,
              isActive: isActive,
            })
          )
        )
      );
    }
  }

  /**
   * Register the shortlink format type
   */
  if (registerFormatType) {
    registerFormatType('tinypress/shortlink', {
      title: tinypressGutenberg.i18n.shortlink,
      tagName: 'a',
      className: 'tinypress-shortlink',
      attributes: {
        url: 'href',
        target: 'target',
        rel: 'rel',
      },
      edit: ShortlinkEdit,
    });
  }
})(window.wp);
