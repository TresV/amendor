/**
 * Amendor — Elementor editor integration (experimental).
 *
 * Adds a floating search tool to the Elementor editor that highlights and
 * optionally replaces matching text in the current document by updating
 * Elementor's element models (with an in-editor undo). A deeper, backup-backed
 * replace flow remains available in the Amendor admin UI.
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
    var lastReplaceSnapshot = null;

    // Current search state (term/mode/regex + results).
    var currentTerm = '';
    var currentMode = 'partial';
    var currentRegex = null;
    var occurrences = [];

    // Field filtering (safe defaults + user control). Category -> enabled.
    var fieldState = {
        text: true,
        url: false,
        shortcode: false,
        code: false,
        other: false
    };

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

    function buildTermRegex(term, mode) {
        if (mode === 'regex') {
            try {
                return new RegExp(term, 'giu');
            } catch (e) {
                return null;
            }
        }
        var escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (mode === 'exact') {
            // Whole word/phrase, case-sensitive. Boundaries use alphanumerics
            // only (not \b, where _ counts as a word char), so "tool" matches
            // tool, tool_box and [tool_1] but not tools/toolbar; URLs, phrases
            // and punctuation ("sitemap.xml", "C++") still work.
            if (/[A-Za-z0-9]/.test(escaped.charAt(0))) {
                escaped = '(?<![A-Za-z0-9])' + escaped;
            }
            if (/[A-Za-z0-9]/.test(escaped.charAt(escaped.length - 1))) {
                escaped = escaped + '(?![A-Za-z0-9])';
            }
            return new RegExp(escaped, 'gu');
        }
        // Partial: case-insensitive substring.
        return new RegExp(escaped, 'giu');
    }

    function matchesTerm(value, term, mode) {
        var regex = buildTermRegex(term, mode);
        if (!regex) {
            return false;
        }
        regex.lastIndex = 0;
        return regex.test(value);
    }

    function fieldCategory(key) {
        if (key.charAt(0) === '_') {
            return 'other';
        }
        if (/^(editor|text|textarea|title|heading|subheading|sub_title|description|desc|caption|content|html|excerpt|alt|name|label|placeholder|prefix|suffix|button_text|title_text|description_text|tab_title|tab_content|testimonial_content|testimonial_name|testimonial_job|alert_title|alert_description|feature_title|feature_description|blockquote|quote|item_title|item_description|card_title|card_description|cta_title|cta_description|heading_title|heading_description|icon_title|icon_description|pricing_title|pricing_description|main_title|main_description|subtitle_text|link_text|text_before|text_after|content_before|content_after|title_a|title_b|description_text_a|description_text_b)$/i.test(key)) {
            return 'text';
        }
        if (/^(url|link|href|source|src|redirect_url|button_link|cta_link|image_link|website|external_url|target_url)$/i.test(key)) {
            return 'url';
        }
        if (/^(shortcode|wp_shortcode)$/i.test(key)) {
            return 'shortcode';
        }
        if (/^(custom_css|css|style|script|code)$/i.test(key)) {
            return 'code';
        }
        return 'other';
    }

    function fieldAllowed(key) {
        return !!fieldState[fieldCategory(key)];
    }

    function modelLabel(model) {
        var type = model.get('widgetType') || model.get('elType') || 'element';
        return String(type).replace(/-/g, ' ');
    }

    function makeSnippet(value, term) {
        var text = String(value).replace(/\s+/g, ' ').trim();
        var idx = text.toLowerCase().indexOf(String(term).toLowerCase());
        if (idx < 0) {
            idx = 0;
        }
        var start = Math.max(0, idx - 40);
        var end = Math.min(text.length, idx + String(term).length + 40);
        return (start > 0 ? '…' : '') + text.slice(start, end) + (end < text.length ? '…' : '');
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

    function collectOccurrences() {
        var out = [];
        collectModels().forEach(function (model) {
            var settings = model.get('settings');
            if (!settings || !settings.attributes) {
                return;
            }
            Object.keys(settings.attributes).forEach(function (key) {
                var value = settings.attributes[key];
                if (typeof value !== 'string' || !fieldAllowed(key)) {
                    return;
                }
                if (!matchesTerm(value, currentTerm, currentMode)) {
                    return;
                }
                out.push({
                    model: model,
                    id: model.get('id'),
                    key: key,
                    value: value,
                    label: modelLabel(model),
                    snippet: makeSnippet(value, currentTerm),
                    selected: true
                });
            });
        });
        return out;
    }

    function selectedOccurrences() {
        return occurrences.filter(function (o) {
            return o.selected;
        });
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

    function applyHighlights() {
        var regex = buildTermRegex(currentTerm, currentMode);
        clearHighlights();
        if (!currentTerm || !regex) {
            return;
        }
        ensureHighlightCss();
        var $doc = getPreviewDocument();
        if (!$doc) {
            return;
        }
        // One entry per container across selected occurrences.
        var seen = {};
        selectedOccurrences().forEach(function (o) {
            if (!o.id || seen[o.id]) {
                return;
            }
            seen[o.id] = true;
            var $el = $doc.find('[data-id="' + o.id + '"]');
            if (!$el.length) {
                return;
            }
            $el.addClass('amendor-match-highlight');
            highlighted += wrapTextMatches($el, regex, 'amendor-word-highlight');
        });
    }

    function updateStatus() {
        var $status = $('#amendor-editor-status');
        if (!currentTerm) {
            $status.hide();
            return;
        }
        if (!occurrences.length) {
            $status.text(i18n.none || 'No matches found.').show();
            return;
        }
        var sel = selectedOccurrences().length;
        var msg;
        if (sel === occurrences.length) {
            msg = (i18n.found || '%d match(es) highlighted').replace('%d', occurrences.length);
        } else {
            msg = (i18n.selectedOf || '%d of %d selected').replace('%d', sel).replace('%d', occurrences.length);
        }
        $status.text(msg).show();
    }

    function updateReplaceButton() {
        var $btn = $('#amendor-editor-replace-btn');
        var sel = selectedOccurrences().length;
        if (!$btn.length) {
            return;
        }
        $btn.text((i18n.replaceSelected || 'Replace Selected (%d)').replace('%d', sel))
            .prop('disabled', sel === 0);
    }

    function renderOccurrenceList() {
        var $list = $('#amendor-editor-results');
        if (!$list.length) {
            return;
        }
        $list.empty();
        if (!occurrences.length) {
            $list.hide();
            return;
        }
        $list.show();
        occurrences.forEach(function (o) {
            var $row = $('<label class="amendor-editor-occ"></label>')
                .css({ display: 'block', padding: '3px 2px', cursor: 'pointer' });
            var $cb = $('<input type="checkbox">')
                .prop('checked', o.selected)
                .on('change', function () {
                    o.selected = $(this).is(':checked');
                    applyHighlights();
                    updateStatus();
                    updateReplaceButton();
                })
                .css({ marginRight: '6px', verticalAlign: 'middle' });
            $row.append($cb);
            $row.append($('<span></span>').text(o.label + ' · ' + o.key)
                .css({ fontSize: '12px', fontWeight: '600', color: '#1d2327' }));
            $row.append($('<span></span>').text(o.snippet)
                .css({
                    display: 'block',
                    fontSize: '11px',
                    color: '#50575e',
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    paddingLeft: '18px'
                }));
            $list.append($row);
        });
    }

    function runSearch() {
        currentTerm = $.trim($('#amendor-editor-term').val());
        currentMode = $('#amendor-editor-mode').val() || 'partial';

        if (!currentTerm) {
            occurrences = [];
            clearHighlights();
            renderOccurrenceList();
            updateReplaceButton();
            $('#amendor-editor-status').hide();
            return;
        }

        currentRegex = buildTermRegex(currentTerm, currentMode);
        if (!currentRegex) {
            occurrences = [];
            clearHighlights();
            renderOccurrenceList();
            updateReplaceButton();
            $('#amendor-editor-status').text(i18n.invalidRegex || 'Invalid regular expression.').show();
            return;
        }

        occurrences = collectOccurrences();
        applyHighlights();
        renderOccurrenceList();
        updateReplaceButton();
        updateStatus();
    }

    function applyReplacement(value, regex, replacement, mode) {
        regex.lastIndex = 0;
        if (mode === 'regex') {
            return value.replace(regex, replacement);
        }
        // Literal modes: function replacer keeps $ in the replacement literal.
        return value.replace(regex, function () {
            return replacement;
        });
    }

    function setModelSetting(model, key, value) {
        var settings = model.get('settings');
        if (!settings || typeof settings.set !== 'function') {
            return false;
        }
        settings.set(key, value);
        return true;
    }

    function renderModel(model) {
        try {
            if (model.container && typeof model.container.render === 'function') {
                model.container.render();
                return;
            }
        } catch (e) { /* ignore */ }
        try {
            if (typeof model.render === 'function') {
                model.render();
            }
        } catch (e) { /* ignore */ }
    }

    function renderChangedModels(changes) {
        var rendered = {};
        changes.forEach(function (c) {
            var cid = c.model.cid || c.model.get('_id') || c.model.get('id');
            if (rendered[cid]) {
                return;
            }
            rendered[cid] = true;
            renderModel(c.model);
        });
    }

    function setUndoVisible(visible) {
        var $btn = $('#amendor-editor-undo');
        if ($btn.length) {
            $btn.toggle(!!visible);
        }
    }

    function runReplace() {
        var replacement = $('#amendor-editor-replace').val();
        var $status = $('#amendor-editor-status');

        if (!currentTerm) {
            $status.text(i18n.enterTerm || 'Enter a search term first.').show();
            return;
        }

        var regex = buildTermRegex(currentTerm, currentMode);
        if (!regex) {
            $status.text(i18n.invalidRegex || 'Invalid regular expression.').show();
            return;
        }

        var changes = [];
        selectedOccurrences().forEach(function (o) {
            var newValue = applyReplacement(o.value, regex, replacement, currentMode);
            if (newValue === o.value) {
                return;
            }
            changes.push({ model: o.model, key: o.key, oldValue: o.value, newValue: newValue });
        });

        if (!changes.length) {
            $status.text(i18n.none || 'No matches found.').show();
            return;
        }

        if (!window.confirm((i18n.confirmReplace || 'Replace %d value(s) on this page? You can undo afterwards.').replace('%d', changes.length))) {
            return;
        }

        changes.forEach(function (c) {
            setModelSetting(c.model, c.key, c.newValue);
        });
        renderChangedModels(changes);

        lastReplaceSnapshot = changes;
        setUndoVisible(true);
        runSearch(); // refresh list + highlights
        $status.text((i18n.replaced || '%d value(s) replaced').replace('%d', changes.length)).show();
    }

    function runUndo() {
        if (!lastReplaceSnapshot) {
            return;
        }
        lastReplaceSnapshot.forEach(function (c) {
            setModelSetting(c.model, c.key, c.oldValue);
        });
        renderChangedModels(lastReplaceSnapshot);
        lastReplaceSnapshot = null;
        setUndoVisible(false);
        runSearch();
        $('#amendor-editor-status').text(i18n.reverted || 'Changes restored.').show();
    }

    function buildPanel() {
        var panelCss = {
            position: 'fixed',
            right: '16px',
            bottom: '70px',
            width: '320px',
            background: '#ffffff',
            color: '#1d2327',
            border: '1px solid #dcdcde',
            borderRadius: '6px',
            boxShadow: '0 4px 20px rgba(0,0,0,.25)',
            padding: '12px',
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
        var btnPrimary = {
            flex: '1',
            padding: '6px 10px',
            fontSize: '13px',
            fontWeight: '600',
            color: '#ffffff',
            background: '#93003c',
            border: 'none',
            borderRadius: '3px',
            cursor: 'pointer'
        };
        var btnSecondary = {
            flex: '1',
            padding: '6px 10px',
            fontSize: '13px',
            color: '#1d2327',
            background: '#f0f0f1',
            border: '1px solid #c3c4c7',
            borderRadius: '3px',
            cursor: 'pointer'
        };

        panel = $('<div id="amendor-editor-panel"></div>').css(panelCss);
        panel.append($('<div></div>').text(i18n.title || 'Amendor Search')
            .css({ fontWeight: '700', fontSize: '14px', color: '#1d2327', marginBottom: '10px' }));
        panel.append($('<label></label>').text(i18n.placeholder || 'Search for text in this page...').css(labelCss));
        panel.append($('<input id="amendor-editor-term" type="text">').css(inputCss));
        panel.append($('<select id="amendor-editor-mode"></select>').css(inputCss)
            .append($('<option value="partial">').text(i18n.partial || 'Partial'))
            .append($('<option value="exact">').text(i18n.exact || 'Exact'))
            .append($('<option value="regex">').text(i18n.regex || 'Regex')));
        panel.append($('<label></label>').text(i18n.replace || 'Replace with').css(labelCss));
        panel.append($('<input id="amendor-editor-replace" type="text">').css(inputCss));

        // Field filter (collapsible).
        var $details = $('<details class="amendor-editor-fields"></details>')
            .css({ marginBottom: '8px' });
        $details.append($('<summary></summary>')
            .text(i18n.fields || 'Fields')
            .css({ fontSize: '12px', fontWeight: '600', color: '#1d2327', cursor: 'pointer' }));
        var fieldDefs = [
            { cat: 'text', label: i18n.fieldText || 'Text & content', def: true },
            { cat: 'url', label: i18n.fieldUrl || 'URLs & links', def: false },
            { cat: 'shortcode', label: i18n.fieldShortcode || 'Shortcodes', def: false },
            { cat: 'code', label: i18n.fieldCode || 'Code & CSS', def: false },
            { cat: 'other', label: i18n.fieldOther || 'Other (incl. internal)', def: false }
        ];
        fieldDefs.forEach(function (f) {
            fieldState[f.cat] = f.def;
            var $lbl = $('<label></label>')
                .css({ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: '#1d2327', margin: '2px 0' });
            $lbl.append($('<input type="checkbox" class="amendor-editor-field-toggle">')
                .prop('checked', f.def)
                .data('cat', f.cat));
            $lbl.append($('<span></span>').text(f.label));
            $details.append($lbl);
        });
        panel.append($details);

        panel.append($('<div></div>').css(rowCss)
            .append($('<button id="amendor-editor-highlight" type="button"></button>')
                .text(i18n.highlight || 'Highlight')
                .css(btnPrimary))
            .append($('<button id="amendor-editor-replace-btn" type="button"></button>')
                .text((i18n.replaceSelected || 'Replace Selected (%d)').replace('%d', 0))
                .prop('disabled', true)
                .css($.extend({}, btnPrimary, { background: '#006ba1' }))));
        panel.append($('<div></div>').css(rowCss)
            .append($('<button id="amendor-editor-clear" type="button"></button>')
                .text(i18n.clear || 'Clear')
                .css(btnSecondary))
            .append($('<button id="amendor-editor-undo" type="button"></button>')
                .text(i18n.undo || 'Undo')
                .css(btnSecondary)
                .hide()));

        panel.append($('<div id="amendor-editor-results"></div>').css({
            maxHeight: '300px',
            overflowY: 'auto',
            borderTop: '1px solid #dcdcde',
            marginBottom: '8px',
            paddingTop: '6px',
            display: 'none'
        }));

        panel.append($('<div id="amendor-editor-status"></div>')
            .css({ fontSize: '12px', fontWeight: '600', color: '#1d2327', marginBottom: '8px', display: 'none' }));
        panel.append($('<a id="amendor-editor-open" href="#" target="_blank"></a>')
            .text(i18n.open || 'Open in Amendor')
            .attr('href', vars.adminUrl + (vars.postId ? '&post=' + vars.postId : ''))
            .css({ display: 'inline-block', fontSize: '12px', fontWeight: '600', color: '#2271b1', textDecoration: 'underline', cursor: 'pointer' }));

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

    $(document).on('click', '#amendor-editor-highlight', runSearch);
    $(document).on('keydown', '#amendor-editor-term', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            runSearch();
        }
    });
    $(document).on('click', '#amendor-editor-replace-btn', runReplace);
    $(document).on('keydown', '#amendor-editor-replace', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            runReplace();
        }
    });
    $(document).on('click', '#amendor-editor-undo', runUndo);
    $(document).on('change', '.amendor-editor-field-toggle', function () {
        var cat = $(this).data('cat');
        fieldState[cat] = $(this).is(':checked');
        runSearch();
    });
    $(document).on('click', '#amendor-editor-clear', function () {
        clearHighlights();
        occurrences = [];
        renderOccurrenceList();
        updateReplaceButton();
        $('#amendor-editor-status').hide();
    });
});
