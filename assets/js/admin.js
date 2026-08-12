jQuery(function ($) {
    let bypassSearchIntercept = false;
    let activeSubmitAction = '';
    let searchCancelled = false;

    function ensureActionInput(form, actionValue) {
        let actionInput = form.find('#amendor-form-action');
        if (actionInput.length === 0) {
            actionInput = $('<input>').attr({ type: 'hidden', name: 'amendor_form_action', id: 'amendor-form-action' }).appendTo(form);
        }
        actionInput.val(actionValue);
        return actionInput;
    }

    function getVisibleCheckboxes() {
        return $('.amendor-preview-item:visible .amendor-result-checkbox');
    }

    function getVisibleCheckedCheckboxes() {
        return $('.amendor-preview-item:visible .amendor-result-checkbox:checked');
    }

    function getSelectedContentSources() {
        return $('.amendor-content-source-checkbox:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function getSelectedWidgetTypes() {
        const widgetSelect = $('#widget_types');
        if (!widgetSelect.length || widgetSelect.is(':disabled')) {
            return [];
        }

        return widgetSelect.val() || [];
    }

    function syncContentSourceUi() {
        const hasElementor = getSelectedContentSources().indexOf('elementor') !== -1;
        const widgetRow = $('#amendor-widget-filter-row');
        const widgetSelect = $('#widget_types');

        widgetRow.toggle(hasElementor);
        widgetSelect.prop('disabled', !hasElementor);
    }

    function updateSelectAllState() {
        const totalVisible = getVisibleCheckboxes().length;
        const checkedVisible = getVisibleCheckedCheckboxes().length;

        if (totalVisible === 0 || checkedVisible === 0) {
            $('#select-all-results').prop({ checked: false, indeterminate: false });
        } else if (checkedVisible === totalVisible) {
            $('#select-all-results').prop({ checked: true, indeterminate: false });
        } else {
            $('#select-all-results').prop({ checked: false, indeterminate: true });
        }
    }

    function updateBackupReminder() {
        const selectedItems = getVisibleCheckedCheckboxes().closest('.amendor-preview-item');
        const reminder = $('#backup-reminder');
        const text = reminder.find('.amendor-backup-reminder-text');

        if (!selectedItems.length) {
            reminder.hide();
            $('#replace-button').css('opacity', '1').attr('title', '');
            return;
        }

        const needsBackup = selectedItems.filter(function () {
            return parseInt($(this).data('backup-count'), 10) < 1;
        }).length > 0;

        reminder.slideDown(120);
        if (needsBackup) {
            text.text(amendor_admin_vars.backup_selection_warning);
            $('#replace-button').css('opacity', '0.8').attr('title', amendor_admin_vars.backup_selection_warning);
        } else {
            text.text(amendor_admin_vars.backup_selection_safe);
            $('#replace-button').css('opacity', '1').attr('title', '');
        }
    }

    function toggleActionButtons() {
        const hasVisibleSelection = getVisibleCheckedCheckboxes().length > 0;
        $('#preview-button, #replace-button').prop('disabled', !hasVisibleSelection);
        updateBackupReminder();
    }

    function setSearchProgressState(visible, text, isError) {
        const progress = $('#amendor-search-progress');
        const textNode = progress.find('.amendor-search-progress-text');
        const cancelButton = progress.find('#amendor-search-cancel');

        if (!visible) {
            progress.hide().removeClass('notice-error').addClass('notice-info');
            textNode.text('');
            cancelButton.hide();
            $('#search-button').prop('disabled', false);
            return;
        }

        progress.toggleClass('notice-error', !!isError).toggleClass('notice-info', !isError).show();
        textNode.text(text);
        cancelButton.toggle(!isError);
        $('#search-button').prop('disabled', true);
    }

    function setResultsLoadingState(loading, text) {
        const panel = $('#amendor-results-panel');
        panel.toggleClass('is-loading', !!loading);
        if (loading) {
            panel.find('.inside').prepend(
                $('<div class="notice notice-info inline amendor-inline-loader"><p></p></div>').find('p').text(text).end()
            );
            $('#preview-button').prop('disabled', true);
        } else {
            panel.find('.amendor-inline-loader').remove();
        }
    }

    function applyTypeFilter() {
        const selectedType = $('#filter-type').val();
        $('.amendor-preview-item').each(function () {
            $(this).toggle(!selectedType || $(this).data('type') === selectedType);
        });
    }

    function syncResultsPerPageInput() {
        const value = $('#results-per-page').val();
        if (value) {
            $('#amendor-results-per-page-input').val(value);
        }
    }

    function refreshResultsUi() {
        $('.amendor-accordion .amendor-preview-item').removeClass('open').find('.amendor-item-content').hide();
        applyTypeFilter();
        updateSelectAllState();
        toggleActionButtons();
        syncResultsPerPageInput();
    }

    function renderCachedSearchResults(form, paged) {
        const payload = {
            action: 'amendor_get_search_results',
            nonce: amendor_admin_vars.search_results_nonce,
            search: $.trim($('#search').val()),
            search_mode: $('#search_mode').val(),
            widget_types: getSelectedWidgetTypes(),
            content_sources: getSelectedContentSources(),
            field_keys: $.trim($('#field_keys').val() || ''),
            search_cache_key: $('#amendor-search-cache-key').val(),
            paged: paged || 1,
            results_per_page: $('#amendor-results-per-page-input').val() || ''
        };

        return $.post(ajaxurl, payload).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.results_html) {
                throw new Error('invalid-render-response');
            }

            $('#amendor-results-panel').replaceWith(response.data.results_html);
            $('#amendor-admin-notices').html(response.data.notices_html || '');
            $('#amendor-paged-input').val(response.data.paged || paged || 1);
            setSearchProgressState(false, '', false);
            refreshResultsUi();
        }).fail(function () {
            setSearchProgressState(true, amendor_admin_vars.search_progress_error, true);
            ensureActionInput(form, 'search');
            bypassSearchIntercept = true;
            window.setTimeout(function () {
                form.trigger('submit');
            }, 50);
        });
    }

    function runBatchedSearch(form, paged) {
        const search = $.trim($('#search').val());
        const searchMode = $('#search_mode').val();
        const widgetTypes = getSelectedWidgetTypes();
        const contentSources = getSelectedContentSources();
        const cacheField = $('#amendor-search-cache-key');

        if (!search) {
            ensureActionInput(form, 'search');
            bypassSearchIntercept = true;
            form.trigger('submit');
            return;
        }

        searchCancelled = false;
        cacheField.val('');
        setSearchProgressState(true, amendor_admin_vars.search_progress_label + ' 0%', false);

        function finishCancelled() {
            const progress = $('#amendor-search-progress');
            progress.toggleClass('notice-error', false).toggleClass('notice-info', true).show();
            progress.find('.amendor-search-progress-text').text(amendor_admin_vars.search_cancelled);
            progress.find('#amendor-search-cancel').hide();
            $('#search-button').prop('disabled', false);
            cacheField.val('');
        }

        function runStep(cacheKey, reset) {
            if (searchCancelled) {
                finishCancelled();
                return;
            }

            $.post(ajaxurl, {
                action: 'amendor_run_search_batch',
                nonce: amendor_admin_vars.search_batch_nonce,
                search: search,
                search_mode: searchMode,
                widget_types: widgetTypes,
                content_sources: contentSources,
                field_keys: $.trim($('#field_keys').val() || ''),
                search_cache_key: cacheKey || '',
                reset: reset ? 1 : 0
            }).done(function (response) {
                if (searchCancelled) {
                    finishCancelled();
                    return;
                }
                if (!response || !response.success || !response.data) {
                    throw new Error('invalid-search-response');
                }

                const data = response.data;
                cacheField.val(data.cache_key || '');
                setSearchProgressState(true, amendor_admin_vars.search_progress_label + ' ' + (data.progress_percent || 0) + '%', false);

                if (data.done) {
                    setSearchProgressState(true, amendor_admin_vars.search_progress_done, false);
                    $('#amendor-paged-input').val(paged || 1);
                    renderCachedSearchResults(form, paged || 1);
                    return;
                }

                runStep(data.cache_key, false);
            }).fail(function () {
                setSearchProgressState(true, amendor_admin_vars.search_progress_error, true);
                cacheField.val('');
                ensureActionInput(form, 'search');
                bypassSearchIntercept = true;
                window.setTimeout(function () {
                    form.trigger('submit');
                }, 50);
            });
        }

        runStep('', true);
    }

    function runPreviewRequest(form) {
        const selectedPosts = form.find('input[name="selected_posts[]"]:checked').serializeArray();

        if (!selectedPosts.length) {
            window.alert(amendor_admin_vars.alert_select_items);
            return;
        }

        setResultsLoadingState(true, amendor_admin_vars.preview_progress_label);

        const payload = form.serializeArray();
        payload.push({ name: 'action', value: 'amendor_run_preview' });
        payload.push({ name: 'nonce', value: amendor_admin_vars.preview_nonce });

        $.post(ajaxurl, payload).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.results_html) {
                throw new Error('invalid-preview-response');
            }

            $('#amendor-results-panel').replaceWith(response.data.results_html);
            $('#amendor-admin-notices').html(response.data.notices_html || '');
            refreshResultsUi();
        }).fail(function () {
            setResultsLoadingState(false, '');
            $('#amendor-admin-notices').html(
                $('<div class="notice notice-error is-dismissible"><p></p></div>').find('p').text(amendor_admin_vars.preview_progress_error).end()
            );
            ensureActionInput(form, 'preview_selected');
            bypassSearchIntercept = true;
            window.setTimeout(function () {
                form.trigger('submit');
            }, 50);
        }).always(function () {
            setResultsLoadingState(false, '');
        });
    }

    window.confirmReplaceAction = function () {
        const selectedCount = getVisibleCheckedCheckboxes().length;
        if (selectedCount === 0) {
            window.alert(amendor_admin_vars.alert_select_items);
            return false;
        }

        let message = amendor_admin_vars.confirm_replace_text.replace('%d', selectedCount) + '\n\n';
        message += amendor_admin_vars.confirm_replace_backup_notice + '\n\n';
        message += amendor_admin_vars.confirm_replace_warning + '\n\n';
        message += amendor_admin_vars.confirm_replace_proceed;

        if (selectedCount > 20) {
            message += '\n\n' + amendor_admin_vars.confirm_replace_large_batch_warning.replace('%d', selectedCount);
        }

        return window.confirm(message);
    };

    $('#search_mode').on('change', function () {
        $('#regex-help').toggle($(this).val() === 'regex');
    }).trigger('change');

    $('#elementor-search-form').on('click', 'button[type="submit"]', function () {
        activeSubmitAction = $(this).val() || '';
        ensureActionInput($('#elementor-search-form'), activeSubmitAction);
    });

    $('#add-bulk-pair').on('click', function () {
        const container = $('#bulk-replace-container');
        const newPair = container.find('.bulk-replace-pair:first').clone(true);
        newPair.find('input[type="text"]').val('');
        newPair.appendTo(container).find('input:first').focus();
    });

    $('#bulk-replace-container').on('click', '.remove-pair', function () {
        const pairs = $('#bulk-replace-container .bulk-replace-pair');
        if (pairs.length > 1) {
            $(this).closest('.bulk-replace-pair').remove();
        } else {
            $(this).closest('.bulk-replace-pair').find('input[type="text"]').val('');
        }
    });

    $(document).on('change', '#select-all-results', function () {
        getVisibleCheckboxes().prop('checked', $(this).prop('checked')).trigger('change');
    });

    $(document).on('change', '.amendor-result-checkbox', function () {
        updateSelectAllState();
        toggleActionButtons();
    });

    $(document).on('change', '#filter-type', function () {
        applyTypeFilter();
        updateSelectAllState();
        toggleActionButtons();
    });

    $(document).on('change', '.amendor-content-source-checkbox', function () {
        syncContentSourceUi();
    });

    $(document).on('click', '.amendor-recent-search', function () {
        const form = $('#elementor-search-form');
        const term = $(this).data('search');
        if (typeof term !== 'string') {
            return;
        }
        $('#search').val(term);
        ensureActionInput(form, 'search');
        activeSubmitAction = 'search';
        form.trigger('submit');
    });

    $(document).on('click', '#amendor-search-cancel', function () {
        searchCancelled = true;
        $(this).hide();
    });

    $(document).on('click', '#amendor-swap-run', function () {
        const oldUrl = $.trim($('#amendor-swap-old').val());
        const newUrl = $.trim($('#amendor-swap-new').val());
        if (!oldUrl) {
            window.alert(amendor_admin_vars.swap_require_old);
            return;
        }
        $('#search').val(oldUrl);
        $('#replace').val(newUrl);
        const form = $('#elementor-search-form');
        ensureActionInput(form, 'search');
        activeSubmitAction = 'search';
        form.trigger('submit');
    });

    $(document).on('click', '.amendor-json-diff-toggle', function () {
        $(this).closest('.amendor-json-diff').find('.amendor-json-diff-body').slideToggle(150);
    });

    $(document).on('change', '#results-per-page', function () {
        const form = $('#elementor-search-form');
        syncResultsPerPageInput();
        $('#amendor-paged-input').val(1);

        if ($('#amendor-search-cache-key').val()) {
            setSearchProgressState(true, amendor_admin_vars.search_progress_done, false);
            renderCachedSearchResults(form, 1);
            return;
        }

        ensureActionInput(form, 'search');
        bypassSearchIntercept = true;
        form.trigger('submit');
    });

    $(document).on('click', '.amendor-accordion .amendor-item-header', function (event) {
        if ($(event.target).is('input:checkbox, a, button') || $(event.target).closest('a, button').length) {
            return;
        }

        const item = $(this).closest('.amendor-preview-item');
        const content = item.find('.amendor-item-content');

        if (item.hasClass('open')) {
            item.removeClass('open');
            content.stop(true, true).slideUp(200);
            return;
        }

        item.addClass('open');
        content.stop(true, true).slideDown(200);
    });

    $(document).on('click', '#amendor-results-panel .tablenav-pages a', function (event) {
        const url = $(this).attr('href');
        const form = $('#elementor-search-form');
        let paged = 1;

        if (!form.length || !$(this).closest('#amendor-results-panel').length) {
            return;
        }

        event.preventDefault();

        try {
            const params = new URLSearchParams(new URL(url).search);
            if (params.has('paged')) {
                paged = parseInt(params.get('paged'), 10) || 1;
            }
        } catch (error) {
            const match = url.match(/[?&]paged=(\d+)/);
            if (match && match[1]) {
                paged = parseInt(match[1], 10) || 1;
            }
        }

        $('#amendor-paged-input').val(paged);

        ensureActionInput(form, 'search');
        activeSubmitAction = 'search';
        if ($('#amendor-search-cache-key').val()) {
            setSearchProgressState(true, amendor_admin_vars.search_progress_done, false);
            renderCachedSearchResults(form, paged);
            return;
        }

        runBatchedSearch(form, paged);
    });

    $('#elementor-search-form').on('submit', function (event) {
        const form = $(this);

        if (bypassSearchIntercept) {
            bypassSearchIntercept = false;
            setSearchProgressState(false, '', false);
            return;
        }

        if (activeSubmitAction === 'search') {
            event.preventDefault();
            runBatchedSearch(form, parseInt($('#amendor-paged-input').val(), 10) || 1);
            return;
        }

        if (activeSubmitAction === 'preview_selected') {
            event.preventDefault();
            runPreviewRequest(form);
        }
    });

    syncContentSourceUi();
    refreshResultsUi();
});
