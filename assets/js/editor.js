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
            '.amendor-match-highlight{outline:3px solid #ff5722 !important;box-shadow:0 0 0 6px rgba(255,87,34,.25) !important;}' +
            '</style>'
        );
    }

    function clearHighlights() {
        var $doc = getPreviewDocument();
        if ($doc) {
            $doc.find('.amendor-match-highlight').removeClass('amendor-match-highlight');
        }
        highlighted = 0;
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
            if (found) {
                var id = model.get('id');
                if (id) {
                    $doc.find('[data-id="' + id + '"]').addClass('amendor-match-highlight');
                    highlighted++;
                }
            }
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
            width: '280px',
            background: '#fff',
            border: '1px solid #ccd0d4',
            borderRadius: '4px',
            boxShadow: '0 2px 14px rgba(0,0,0,.18)',
            padding: '12px',
            zIndex: '99999',
            fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
            display: 'none'
        };
        var labelCss = { display: 'block', margin: '0 0 4px', fontWeight: '600', fontSize: '12px' };
        var inputCss = { width: '100%', boxSizing: 'border-box', marginBottom: '8px', padding: '4px 6px' };
        var rowCss = { display: 'flex', gap: '6px', marginBottom: '8px' };

        panel = $('<div id="amendor-editor-panel"></div>').css(panelCss);
        panel.append($('<div></div>').text(i18n.title || 'Amendor Search').css({ fontWeight: '600', marginBottom: '8px' }));
        panel.append($('<label></label>').text(i18n.placeholder || 'Search for text in this page...').css(labelCss));
        panel.append($('<input id="amendor-editor-term" type="text">').css(inputCss));
        panel.append($('<select id="amendor-editor-mode"></select>').css(inputCss)
            .append($('<option value="partial">').text(i18n.partial || 'Partial'))
            .append($('<option value="exact">').text(i18n.exact || 'Exact'))
            .append($('<option value="regex">').text(i18n.regex || 'Regex')));
        panel.append($('<div></div>').css(rowCss)
            .append($('<button id="amendor-editor-highlight" class="button button-primary"></button>').text(i18n.highlight || 'Highlight'))
            .append($('<button id="amendor-editor-clear" class="button"></button>').text(i18n.clear || 'Clear')));
        panel.append($('<div id="amendor-editor-status"></div>').css({ fontSize: '12px', color: '#666', marginBottom: '8px', display: 'none' }));
        panel.append($('<a id="amendor-editor-open" href="#" target="_blank" class="button button-link"></a>')
            .text(i18n.open || 'Open in Amendor')
            .attr('href', vars.adminUrl + (vars.postId ? '&post=' + vars.postId : '')));
        panel.append($('<p></p>').text(i18n.experimental || '').css({ fontSize: '11px', color: '#999', margin: '8px 0 0' }));

        $('body').append(panel);
    }

    function buildFab() {
        var fab = $('<button id="amendor-editor-fab" type="button" title="' + (i18n.title || 'Amendor Search') + '">🔍</button>')
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

    // --- Init ---
    buildPanel();
    var fab = buildFab();

    fab.on('click', function () {
        panel.toggle();
        if (panel.is(':visible')) {
            $('#amendor-editor-term').trigger('focus');
        }
    });

    $(document).on('keydown', function (event) {
        if (event.altKey && event.shiftKey && (event.key === 'F' || event.key === 'f')) {
            event.preventDefault();
            panel.show();
            $('#amendor-editor-term').trigger('focus');
        }
    });

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
