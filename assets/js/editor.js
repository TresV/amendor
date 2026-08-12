/**
 * Amendor — Elementor editor integration (experimental).
 *
 * Adds a floating search tool that highlights matching widgets in the
 * current document. Replacement is performed in the Amendor admin UI.
 */
jQuery(function ($) {
    'use strict';

    if (!window.elementor || !window.amendor_editor_vars) {
        return;
    }

    var vars = window.amendor_editor_vars;
    var i18n = vars.i18n || {};
    var panel;
    var highlighted = 0;

    function getPreviewDocument() {
        if (elementor.$previewContents && elementor.$previewContents.length) {
            return elementor.$previewContents;
        }
        var iframe = document.getElementById('elementor-preview-iframe');
        if (iframe && iframe.contentDocument) {
            return $(iframe.contentDocument);
        }
        return null;
    }

    function matchesTerm(value, term, mode) {
        if (mode === 'exact') {
            return value.indexOf(term) !== -1;
        }
        if (mode === 'regex') {
            try {
                return new RegExp(term, 'iu').test(value);
            } catch (e) {
                return false;
            }
        }
        return value.toLowerCase().indexOf(term.toLowerCase()) !== -1;
    }

    function collectModels() {
        var models = [];
        if (!elementor.elements || !elementor.elements.models) {
            return models;
        }
        (function walk(list) {
            list.forEach(function (model) {
                if (!model || !model.get) {
                    return;
                }
                models.push(model);
                var inner = model.get('elements');
                if (inner && inner.models) {
                    walk(inner.models);
                }
            });
        })(elementor.elements.models);
        return models;
    }

    function ensureHighlightCss() {
        var $doc = getPreviewDocument();
        if (!$doc || $doc.find('#amendor-editor-highlight-style').length) {
            return;
        }
        $doc.find('head').append(
            '<style id="amendor-editor-highlight-style">' +
            '.amendor-match-highlight{outline:2px dashed #ff5722 !important;}' +
            '.amendor-word-highlight{background:#ffdd57 !important;color:#1d2327 !important;border-radius:2px !important;box-shadow:0 0 0 1px rgba(255,165,0,.5) !important;padding:0 1px !important;}' +
            '</style>'
        );
    }

    function clearHighlights() {
        var $doc = getPreviewDocument();
        if ($doc) {
            $doc.find('.amendor-match-highlight').removeClass('amendor-match-highlight');
            // Unwrap <mark> nodes, restoring the original text nodes.
            $doc.find('.amendor-word-highlight').each(function () {
                var el = this;
                while (el.firstChild) {
                    el.parentNode.insertBefore(el.firstChild, el);
                }
                el.parentNode.removeChild(el);
            });
        }
        highlighted = 0;
    }

    function buildTermRegex(term, mode) {
        if (mode === 'regex') {
            try {
                return new RegExp(term, 'giu');
            } catch (e) {
                return null;
            }
        }
        var escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return new RegExp(escaped, 'giu');
    }

    function wrapTextMatches($root, regex, markClass) {
        var doc = $root[0].ownerDocument || document;
        var count = 0;
        var walker = doc.createTreeWalker($root[0], NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || !node.nodeValue.length) {
                    return NodeFilter.FILTER_REJECT;
                }
                var parent = node.parentNode;
                if (parent && parent.nodeType === 1) {
                    var tag = parent.tagName.toLowerCase();
                    if (tag === 'mark' || tag === 'script' || tag === 'style' || tag === 'textarea') {
                        return NodeFilter.FILTER_REJECT;
                    }
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });

        var textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        textNodes.forEach(function (node) {
            var text = node.nodeValue;
            regex.lastIndex = 0;
            var match;
            var last = 0;
            var nodeCount = 0;
            var frag = doc.createDocumentFragment();
            while ((match = regex.exec(text)) !== null) {
                if (match.index > last) {
                    frag.appendChild(doc.createTextNode(text.slice(last, match.index)));
                }
                var mark = doc.createElement('mark');
                mark.className = markClass;
                mark.textContent = match[0];
                frag.appendChild(mark);
                nodeCount++;
                count++;
                last = match.index + match[0].length;
                if (match[0].length === 0) {
                    regex.lastIndex++;
                }
            }
            if (nodeCount && last < text.length) {
                frag.appendChild(doc.createTextNode(text.slice(last)));
            }
            if (nodeCount) {
                node.parentNode.replaceChild(frag, node);
            }
        });

        return count;
    }

    function runHighlight() {
        var term = $.trim($('#amendor-editor-term').val());
        var mode = $('#amendor-editor-mode').val() || 'partial';
        var $status = $('#amendor-editor-status');

        clearHighlights();
        if (!term) {
            $status.hide();
            return;
        }

        var regex = buildTermRegex(term, mode);
        if (!regex) {
            $status.text(i18n.invalidRegex || 'Invalid regular expression.').show();
            return;
        }

        ensureHighlightCss();
        var $doc = getPreviewDocument();
        if (!$doc) {
            $status.text(i18n.none || 'No matches found.').show();
            return;
        }

        highlighted = 0;
        collectModels().forEach(function (model) {
            var settings = model.get('settings');
            var found = false;
            if (settings && settings.attributes) {
                Object.keys(settings.attributes).forEach(function (key) {
                    var value = settings.attributes[key];
                    if (typeof value === 'string' && matchesTerm(value, term, mode)) {
                        found = true;
                    }
                });
            }
            if (!found) {
                return;
            }
            var id = model.get('id');
            if (!id) {
                return;
            }
            var $el = $doc.find('[data-id="' + id + '"]');
            if (!$el.length) {
                return;
            }
            $el.addClass('amendor-match-highlight');
            highlighted += wrapTextMatches($el, regex, 'amendor-word-highlight');
        });

        $status.text(highlighted > 0
            ? (i18n.found || '%d match(es) highlighted').replace('%d', highlighted)
            : (i18n.none || 'No matches found.')).show();
    }

    function buildPanel() {
        var panelCss = {
            position: 'fixed',
            right: '16px',
            bottom: '70px',
            width: '300px',
            background: '#ffffff',
            color: '#1d2327',
            border: '1px solid #dcdcde',
            borderRadius: '6px',
            boxShadow: '0 4px 20px rgba(0,0,0,.25)',
            padding: '14px',
            zIndex: '99999',
            fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
            fontSize: '13px',
            lineHeight: '1.4',
            display: 'none'
        };
        var labelCss = { display: 'block', margin: '0 0 4px', fontWeight: '600', fontSize: '12px', color: '#1d2327' };
        var inputCss = {
            width: '100%',
            boxSizing: 'border-box',
            marginBottom: '8px',
            padding: '6px 8px',
            fontSize: '13px',
            color: '#1d2327',
            background: '#ffffff',
            border: '1px solid #8c8f94',
            borderRadius: '3px'
        };
        var rowCss = { display: 'flex', gap: '6px', marginBottom: '8px' };

        panel = $('<div id="amendor-editor-panel"></div>').css(panelCss);
        panel.append($('<div></div>').text(i18n.title || 'Amendor Search')
            .css({ fontWeight: '700', fontSize: '14px', color: '#1d2327', marginBottom: '10px' }));
        panel.append($('<label></label>').text(i18n.placeholder || 'Search for text in this page...').css(labelCss));
        panel.append($('<input id="amendor-editor-term" type="text">').css(inputCss));
        panel.append($('<select id="amendor-editor-mode"></select>').css(inputCss)
            .append($('<option value="partial">').text(i18n.partial || 'Partial'))
            .append($('<option value="exact">').text(i18n.exact || 'Exact'))
            .append($('<option value="regex">').text(i18n.regex || 'Regex')));
        panel.append($('<div></div>').css(rowCss)
            .append($('<button id="amendor-editor-highlight" type="button"></button>')
                .text(i18n.highlight || 'Highlight')
                .css({
                    flex: '1',
                    padding: '6px 10px',
                    fontSize: '13px',
                    fontWeight: '600',
                    color: '#ffffff',
                    background: '#93003c',
                    border: 'none',
                    borderRadius: '3px',
                    cursor: 'pointer'
                }))
            .append($('<button id="amendor-editor-clear" type="button"></button>')
                .text(i18n.clear || 'Clear')
                .css({
                    flex: '1',
                    padding: '6px 10px',
                    fontSize: '13px',
                    color: '#1d2327',
                    background: '#f0f0f1',
                    border: '1px solid #c3c4c7',
                    borderRadius: '3px',
                    cursor: 'pointer'
                })));
        panel.append($('<div id="amendor-editor-status"></div>')
            .css({ fontSize: '12px', fontWeight: '600', color: '#1d2327', marginBottom: '8px', display: 'none' }));
        panel.append($('<a id="amendor-editor-open" href="#" target="_blank"></a>')
            .text(i18n.open || 'Open in Amendor')
            .attr('href', vars.adminUrl + (vars.postId ? '&post=' + vars.postId : ''))
            .css({ display: 'inline-block', fontSize: '12px', fontWeight: '600', color: '#2271b1', textDecoration: 'underline', cursor: 'pointer' }));
        panel.append($('<p></p>').text(i18n.experimental || '')
            .css({ fontSize: '12px', color: '#50575e', margin: '8px 0 0' }));

        $('body').append(panel);
    }

    function buildFab() {
        var fab = $('<button id="amendor-editor-fab" type="button" title="' + (i18n.title || 'Amendor Search') + ' (Alt+Shift+F)">🔍</button>')
            .css({
                position: 'fixed',
                right: '16px',
                bottom: '16px',
                width: '44px',
                height: '44px',
                borderRadius: '50%',
                border: 'none',
                background: '#93003c',
                color: '#fff',
                fontSize: '20px',
                cursor: 'pointer',
                boxShadow: '0 2px 8px rgba(0,0,0,.3)',
                zIndex: '99998'
            });
        $('body').append(fab);
        return fab;
    }

    // --- Shortcut (top document + preview iframe) ---
    function isPanelShortcut(event) {
        return event.altKey && event.shiftKey &&
            (event.key === 'F' || event.key === 'f' || event.code === 'KeyF');
    }

    function handlePanelShortcut(event) {
        if (!isPanelShortcut(event)) {
            return;
        }
        event.preventDefault();
        panel.show();
        $('#amendor-editor-term').trigger('focus');
    }

    var shortcutsBound = false;
    var lastPreviewDoc = null;

    function bindShortcuts() {
        if (!shortcutsBound) {
            $(document).on('keydown', handlePanelShortcut);
            shortcutsBound = true;
        }
        // While editing, focus lives inside the preview iframe, so keydown
        // never bubbles up to the top document. Listen there as well.
        var $doc = getPreviewDocument();
        if ($doc && $doc.length && $doc[0] !== lastPreviewDoc) {
            $doc.on('keydown', handlePanelShortcut);
            lastPreviewDoc = $doc[0];
        }
    }

    // The preview iframe reloads on save/page switch — re-bind inside it.
    var $iframe = $('#elementor-preview-iframe');
    if ($iframe.length) {
        $iframe.on('load', bindShortcuts);
    }

    // --- Init ---
    buildPanel();
    var fab = buildFab();

    fab.on('click', function () {
        panel.toggle();
        if (panel.is(':visible')) {
            $('#amendor-editor-term').trigger('focus');
        }
    });

    bindShortcuts();

    $(document).on('click', '#amendor-editor-highlight', runHighlight);
    $(document).on('keydown', '#amendor-editor-term', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            runHighlight();
        }
    });
    $(document).on('click', '#amendor-editor-clear', function () {
        clearHighlights();
        $('#amendor-editor-status').hide();
    });
});
