/**
 * Tainacan OAI-PMH Enhanced - Admin JavaScript
 *
 * Conventions:
 *  - All untrusted strings (from remote OAI servers, user input, error messages)
 *    MUST be inserted via .text() / $('<elem>',{text:...}). NEVER via .html().
 *  - All AJAX has explicit error() handlers — no silent network failures.
 *  - Importer polling backs off on error to avoid hammering the server.
 */
(function ($) {
    'use strict';

    const TainacanOAI = {
        currentStep: 1,
        importData: {},
        pollDelay: 750,
        pollFailures: 0,

        init: function () {
            this.bindEvents();
            this.initChart();
        },

        bindEvents: function () {
            // Dashboard
            $('#btn-test-endpoint').on('click', this.testEndpoint.bind(this));
            $('#btn-reindex').on('click', this.reindex.bind(this));
            $('#btn-clear-cache').on('click', this.clearCache.bind(this));
            $('.btn-reindex-collection').on('click', this.reindexCollection.bind(this));

            // Validation
            $('#btn-validate').on('click', this.runValidation.bind(this));

            // Harvesters
            $('.btn-unblock-ip').on('click', this.unblockIP.bind(this));

            // Importer
            $('#btn-fetch-repository').on('click', this.fetchRepository.bind(this));
            $('#btn-preview-records').on('click', this.previewRecords.bind(this));
            $('#btn-start-import').on('click', this.startImport.bind(this));
            $('#btn-stop-import').on('click', this.stopImport.bind(this));
            $('#btn-next-step').on('click', this.nextStep.bind(this));
            $('#btn-prev-step').on('click', this.prevStep.bind(this));
            $('#target-collection').on('change', this.onCollectionChange.bind(this));

            // Restore wizard state from previous session if reasonably fresh
            this.restoreWizardState();

            // Harvest sources (scheduled)
            $('#btn-new-harvest-source').on('click', this.openHarvestModal.bind(this, null));
            $('#btn-cancel-harvest').on('click', this.closeHarvestModal.bind(this));
            $('#btn-save-harvest').on('click', this.saveHarvestSource.bind(this));
            $('#harvest-collection').on('change', this.loadHarvestMapping.bind(this));
            $('#harvest-sources-table').on('click', '.btn-run-harvest', this.runHarvestSource.bind(this));
            $('#harvest-sources-table').on('click', '.btn-edit-harvest', this.editHarvestSource.bind(this));
            $('#harvest-sources-table').on('click', '.btn-delete-harvest', this.deleteHarvestSource.bind(this));
            $('#harvest-sources-table').on('click', '.btn-toggle-harvest', this.toggleHarvestSource.bind(this));
            $('#toggle-all-harvest-errors').on('click', function (e) {
                e.preventDefault();
                $('.oai-harvest-log-row').toggle();
            });

            // Activity log: toggle / clear / show-full (delegated for all tables)
            $(document).on('click', '.oai-toggle-log', this.toggleImportLog.bind(this));
            $(document).on('click', '.oai-clear-import-log', this.clearImportLog.bind(this));
            $(document).on('click', '.oai-load-full-log', this.loadFullImportLog.bind(this));
            $(document).on('click', '.oai-clear-harvest-log', this.clearHarvestLog.bind(this));
            $(document).on('click', '.oai-load-full-harvest-log', this.loadFullHarvestLog.bind(this));

            // Delete a previous import (history + optionally items)
            $(document).on('click', '.oai-delete-import', this.deleteImport.bind(this));
        },

        // ---------- helpers ----------

        ajax: function (action, data, opts) {
            opts = opts || {};
            return $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                dataType: 'json',
                timeout: opts.timeout || 60000,
                data: $.extend({ action: action, nonce: tainacanOAI.nonce }, data || {})
            });
        },

        notice: function (type, message) {
            const $notice = $('<div>', { 'class': 'notice notice-' + type + ' is-dismissible' })
                .append($('<p>', { text: message }));
            $('.tainacan-oai-content').prepend($notice);
            setTimeout(function () { $notice.fadeOut(); }, 5000);
        },

        showNotice: function (type, message) { return this.notice(type, message); },

        setLoading: function ($btn, loading) {
            if (!$btn || !$btn.length) return;
            if (loading) {
                if (!$btn.data('original-html')) $btn.data('original-html', $btn.html());
                $btn.prop('disabled', true);
                $btn.html('<span class="spinner is-active" style="margin:0;float:none;"></span>');
            } else {
                $btn.prop('disabled', false);
                if ($btn.data('original-html')) $btn.html($btn.data('original-html'));
            }
        },

        errorMessage: function (response) {
            return (response && response.data && response.data.message) || tainacanOAI.strings.error;
        },

        // Render a value (string OR array) as a short, escaped text sample.
        sampleText: function (value, maxLen) {
            maxLen = maxLen || 120;
            if (value === null || value === undefined || value === '') return '';
            const text = Array.isArray(value)
                ? value.filter(Boolean).slice(0, 3).join(' | ')
                : String(value);
            return text.length > maxLen ? text.substring(0, maxLen - 1) + '…' : text;
        },

        // ---------- Dashboard ----------

        testEndpoint: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_test_endpoint').done(function (response) {
                const $box = $('#endpoint-result').empty();
                const type = response.success ? 'success' : 'error';
                const msg = response.success
                    ? response.data.message + ' (' + response.data.time + 's)'
                    : TainacanOAI.errorMessage(response);
                $box.append($('<div>', { 'class': 'notice notice-' + type })
                    .append($('<p>', { text: msg })));
            }).fail(function () {
                TainacanOAI.notice('error', tainacanOAI.strings.error);
            }).always(function () { TainacanOAI.setLoading($btn, false); });
        },

        reindex: function (e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_reindex)) return;
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_reindex', null, { timeout: 600000 })
                .done(function (response) {
                    TainacanOAI.notice(response.success ? 'success' : 'error', TainacanOAI.errorMessage(response) || response.data.message);
                    if (response.success) setTimeout(function () { location.reload(); }, 1500);
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        reindexCollection: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const collectionId = $btn.data('collection');
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_reindex_collection', { collection_id: collectionId }, { timeout: 600000 })
                .done(function (response) {
                    TainacanOAI.notice(response.success ? 'success' : 'error', response.data.message);
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        clearCache: function (e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_clear)) return;
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_clear_cache')
                .done(function (response) {
                    TainacanOAI.notice(response.success ? 'success' : 'error', response.data.message);
                    if (response.success) setTimeout(function () { location.reload(); }, 1500);
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        // ---------- Validation ----------

        runValidation: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            $('#validation-results').empty().append(
                $('<div>', { 'class': 'oai-loading' }).append('<span class="spinner is-active"></span>')
            );

            this.ajax('tainacan_oai_validate', null, { timeout: 120000 })
                .done(function (response) {
                    if (response.success) location.reload();
                    else TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        // ---------- Harvesters ----------

        unblockIP: function (e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_unblock)) return;
            const $btn = $(e.currentTarget);
            const ip = $btn.data('ip');
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_unblock_ip', { ip: ip })
                .done(function (response) {
                    if (response.success) $btn.closest('tr').fadeOut();
                    else TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        // ---------- Importer ----------

        fetchRepository: function (e) {
            e.preventDefault();
            const url = $('#source-url').val().trim();
            if (!url) { alert(tainacanOAI.strings.error); return; }

            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_fetch_repository', { url: url })
                .done(function (response) {
                    if (!response.success) {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                        return;
                    }
                    const data = response.data;
                    TainacanOAI.importData.source_url = url;
                    TainacanOAI.importData.repository = data;

                    $('#repo-name').text(data.repository_name || '-');
                    $('#repo-email').text(data.admin_email || '-');
                    $('#repo-earliest').text(data.earliest_datestamp || '-');
                    $('#repository-info').show();

                    TainacanOAI.fetchSets(url);
                    TainacanOAI.fetchMetadataFormats(url);
                    $('#btn-next-step').show();
                    TainacanOAI.saveWizardState();
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        // Pulls supported metadataPrefix values from the upstream and populates
        // the wizard dropdown. Recommends xoai when present (preserves qualified
        // DSpace field names) and falls back to oai_dc when nothing else is offered.
        fetchMetadataFormats: function (url) {
            this.ajax('tainacan_oai_fetch_metadata_formats', { url: url })
                .done(function (response) {
                    if (!response.success) return;
                    const formats = response.data || [];
                    const $select = $('#metadata-format');
                    $select.empty();

                    // Curated descriptions for the formats we know how to parse
                    const known = {
                        'oai_dc': 'Unqualified Dublin Core (15 fields, lossy)',
                        'qdc':    'Qualified Dublin Core (dcterms:* qualifiers preserved)',
                        'xoai':   'DSpace native (full qualified names like dc.contributor.author) — recommended for DSpace'
                    };

                    const supported = {};
                    formats.forEach(function (f) { supported[f.prefix] = f; });

                    // Always offer the three we can parse, marking unsupported ones
                    ['xoai', 'qdc', 'oai_dc'].forEach(function (k) {
                        const present = !!supported[k];
                        const text = k + ' — ' + (known[k] || k) + (present ? '' : ' (not advertised by this server)');
                        $select.append($('<option>', { value: k, text: text, disabled: !present }));
                    });

                    // Default: xoai if available (richest), else oai_dc
                    const def = supported['xoai'] ? 'xoai' : 'oai_dc';
                    $select.val(def);
                    TainacanOAI.importData.metadata_prefix = def;
                    $('#metadata-format-group').show();

                    $select.off('change.oai').on('change.oai', function () {
                        TainacanOAI.importData.metadata_prefix = $(this).val();
                        // Invalidate any preview already done with the old format
                        TainacanOAI.importData.preview = null;
                        TainacanOAI.importData.dc_fields = [];
                        TainacanOAI.saveWizardState();
                    });

                    TainacanOAI.saveWizardState();
                });
        },

        fetchSets: function (url) {
            this.ajax('tainacan_oai_fetch_sets', { url: url }).done(function (response) {
                if (!response.success) return;
                const $select = $('#source-set');
                $select.find('option:not(:first)').remove();
                (response.data || []).forEach(function (set) {
                    $select.append($('<option>', {
                        value: set.spec,
                        text: (set.name || set.spec) + ' (' + set.spec + ')'
                    }));
                });
                TainacanOAI.importData.sets = response.data;
            });
        },

        previewRecords: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_preview_records', {
                url: this.importData.source_url,
                set: $('#source-set').val(),
                metadata_prefix: this.importData.metadata_prefix || 'oai_dc'
            }).done(function (response) {
                if (!response.success) {
                    TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    return;
                }
                const data = response.data;
                TainacanOAI.importData.preview = data;
                TainacanOAI.importData.dc_fields = data.dc_fields || [];
                TainacanOAI.importData.total = data.total;

                $('#preview-count').text(
                    'Found approximately ' + (data.total !== null ? data.total : 'unknown number of') + ' records'
                );

                const $tbody = $('#preview-table tbody').empty();
                (data.records || []).forEach(function (record) {
                    const md = record.metadata || {};
                    const $tr = $('<tr>')
                        .append($('<td>', { text: record.identifier || '-' }))
                        .append($('<td>', { text: TainacanOAI.sampleText(md.title, 80) || '-' }))
                        .append($('<td>', { text: record.datestamp || '-' }));
                    $tbody.append($tr);
                });

                $('#preview-results').show();
                $('#btn-next-step').show();
            }).fail(function () {
                TainacanOAI.notice('error', tainacanOAI.strings.error);
            }).always(function () { TainacanOAI.setLoading($btn, false); });
        },

        onCollectionChange: function (e) {
            const collectionId = $(e.currentTarget).val();
            if (!collectionId) return;
            this.importData.collection_id = collectionId;
            // Eager-load: fetch the mapping rows now so step 4 is ready when user clicks Next.
            this.loadMappingRows();
        },

        /**
         * Calls the backend's full mapping builder, which returns:
         *   - rows: standard 15 DC + extra source fields, with sample/multi flags + suggestion
         *   - collection_metadata: list of available target metadata
         * Re-renders the mapping table from authoritative server-side data.
         */
        loadMappingRows: function () {
            const collectionId = this.importData.collection_id;
            const sourceFields = this.importData.dc_fields || [];
            if (!collectionId) return;

            const $tbody = $('#mapping-table tbody').empty().append(
                $('<tr>').append($('<td>', { colspan: 3, text: 'Loading mapping…' }))
            );

            this.ajax('tainacan_oai_build_mapping', {
                collection_id: collectionId,
                source_fields: JSON.stringify(sourceFields)
            }).done(function (response) {
                if (!response.success) {
                    TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    return;
                }
                TainacanOAI.importData.collection_metadata = response.data.collection_metadata;
                TainacanOAI.renderMappingTable(response.data.rows, response.data.collection_metadata);
            }).fail(function () {
                TainacanOAI.notice('error', tainacanOAI.strings.error);
            });
        },

        renderMappingTable: function (rows, collectionMetadata) {
            const $tbody = $('#mapping-table tbody').empty();

            rows.forEach(function (row) {
                const $tr = $('<tr>');
                if (!row.present_in_source) $tr.addClass('oai-row-empty');

                // Column 1: source field name (DC: prefix only for standard ones)
                const fieldLabel = row.is_standard_dc ? 'dc:' + row.name : row.name;
                const $name = $('<td>').append($('<strong>', { text: fieldLabel }));
                if (row.is_multi) $name.append(' ').append($('<span>', { 'class': 'oai-badge oai-badge-multi', text: '× ' + row.occurrences }));
                if (!row.present_in_source) $name.append(' ').append($('<small>', { text: '(not in sample)' }));
                $tr.append($name);

                // Column 2: sample value (escaped)
                $tr.append($('<td>').append($('<small>', { text: TainacanOAI.sampleText(row.sample, 100) || '—' })));

                // Column 3: target metadatum select
                const $select = $('<select>', { 'class': 'mapping-select', name: 'mapping[' + row.name + ']' });
                $select.append($('<option>', { value: '', text: '— Skip —' }));
                (collectionMetadata || []).forEach(function (meta) {
                    if (meta.is_core) return; // core_title/description not selectable
                    const isSuggested = String(meta.id) === String(row.suggested_metadatum_id);
                    const labelParts = [meta.name];
                    if (meta.required) labelParts.push('*');
                    if (meta.multiple) labelParts.push('[multi]');
                    if (meta.type) labelParts.push('(' + meta.type + ')');
                    $select.append($('<option>', {
                        value: meta.id,
                        text: labelParts.join(' '),
                        selected: isSuggested
                    }));
                });
                $tr.append($('<td>').append($select));

                $tbody.append($tr);
            });

            if (!rows.length) {
                $tbody.append($('<tr>').append($('<td>', { colspan: 3, text: 'No mappable fields found.' })));
            }
        },

        startImport: function (e) {
            e.preventDefault();

            const mapping = {};
            $('#mapping-table .mapping-select').each(function () {
                const m = ($(this).attr('name') || '').match(/\[(.+)\]/);
                if (!m) return;
                const value = $(this).val();
                if (value) mapping[m[1]] = value;
            });

            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            this.pollDelay = 750;
            this.pollFailures = 0;

            this.ajax('tainacan_oai_start_import', {
                source_url: this.importData.source_url,
                collection_id: this.importData.collection_id,
                set_spec: $('#source-set').val(),
                from_date: $('#from-date').val(),
                until_date: $('#until-date').val(),
                metadata_mapping: JSON.stringify(mapping),
                // Per-run override of the global "Download Bitstreams" setting
                download_bitstreams: $('#import-download-bitstreams').is(':checked') ? 1 : 0,
                metadata_prefix: this.importData.metadata_prefix || 'oai_dc'
            }).done(function (response) {
                if (!response.success) {
                    TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    TainacanOAI.setLoading($btn, false);
                    return;
                }
                TainacanOAI.importData.import_id = response.data.import_id;
                $('#import-progress').show();
                $('#btn-stop-import').show().prop('disabled', false).text('Stop import');
                TainacanOAI.processImport();
            }).fail(function () {
                TainacanOAI.notice('error', tainacanOAI.strings.error);
                TainacanOAI.setLoading($btn, false);
            });
        },

        processImport: function () {
            this.ajax('tainacan_oai_process_import', { import_id: this.importData.import_id }, { timeout: 300000 })
                .done(function (response) {
                    if (!response.success) {
                        TainacanOAI.pollFailures++;
                        if (TainacanOAI.pollFailures < 3) {
                            // transient error: back off and retry
                            TainacanOAI.pollDelay = Math.min(TainacanOAI.pollDelay * 2, 15000);
                            setTimeout(function () { TainacanOAI.processImport(); }, TainacanOAI.pollDelay);
                        } else {
                            TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                            TainacanOAI.setLoading($('#btn-start-import'), false);
                            $('#btn-stop-import').hide();
                        }
                        return;
                    }
                    TainacanOAI.pollFailures = 0;
                    TainacanOAI.pollDelay = 750;

                    const data = response.data;
                    const progress = data.total_records > 0
                        ? Math.round((data.total_imported / data.total_records) * 100)
                        : 0;

                    $('#import-progress-bar').css('width', progress + '%');
                    $('#import-status').text(
                        'Imported: ' + data.total_imported +
                        (data.total_records ? ' / ' + data.total_records : '') +
                        ' (Failed: ' + data.failed + ', Skipped: ' + (data.skipped || 0) + ')'
                    );

                    // Server-side cancellation took effect — stop polling
                    if (data.status === 'cancelled') {
                        TainacanOAI.notice('warning', 'Import cancelled. ' + data.total_imported + ' item(s) already imported are preserved.');
                        TainacanOAI.setLoading($('#btn-start-import'), false);
                        $('#btn-stop-import').hide();
                        TainacanOAI.clearWizardState();
                        return;
                    }

                    if (data.has_more) {
                        setTimeout(function () { TainacanOAI.processImport(); }, TainacanOAI.pollDelay);
                    } else {
                        TainacanOAI.notice('success', 'Import completed!');
                        TainacanOAI.setLoading($('#btn-start-import'), false);
                        $('#btn-stop-import').hide();
                        TainacanOAI.clearWizardState();
                    }
                })
                .fail(function () {
                    TainacanOAI.pollFailures++;
                    if (TainacanOAI.pollFailures < 5) {
                        TainacanOAI.pollDelay = Math.min(TainacanOAI.pollDelay * 2, 30000);
                        setTimeout(function () { TainacanOAI.processImport(); }, TainacanOAI.pollDelay);
                    } else {
                        TainacanOAI.notice('error', tainacanOAI.strings.error);
                        TainacanOAI.setLoading($('#btn-start-import'), false);
                        $('#btn-stop-import').hide();
                    }
                });
        },

        /**
         * Cooperative cancellation: marks the import row as 'cancelled' on the
         * server. The currently-running batch finishes whatever record it's on
         * (cancellation is checked every 5 records) and the JS poller picks up
         * the new status on its next response.
         */
        stopImport: function (e) {
            if (e) e.preventDefault();
            if (!this.importData.import_id) return;
            if (!confirm('Stop this import? Items already imported will be kept; no new items will be created.')) return;

            const $btn = $('#btn-stop-import');
            $btn.prop('disabled', true).text('Stopping…');

            this.ajax('tainacan_oai_stop_import', { import_id: this.importData.import_id })
                .done(function (response) {
                    if (response.success) {
                        TainacanOAI.notice('info', response.data.message);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                        $btn.prop('disabled', false).text('Stop import');
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false).text('Stop import');
                });
        },

        // ---------- Wizard state persistence (localStorage) ----------

        WIZARD_STATE_KEY: 'tainacan_oai_wizard_state',
        WIZARD_STATE_TTL: 3600 * 1000, // 1 hour

        // Snapshot the parts of importData a reload would need to rebuild the
        // wizard at the same point — without re-fetching the upstream OAI server.
        // Called after each major transition so a stray reload doesn't cost the
        // user their format choice + mapping work.
        saveWizardState: function () {
            try {
                const state = {
                    ts: Date.now(),
                    source_url: this.importData.source_url || '',
                    metadata_prefix: this.importData.metadata_prefix || 'oai_dc',
                    set_spec: $('#source-set').val() || '',
                    collection_id: this.importData.collection_id || '',
                    dc_fields: this.importData.dc_fields || [],
                    repository: this.importData.repository || null,
                    sets: this.importData.sets || [],
                    preview_total: this.importData.total || null
                };
                localStorage.setItem(this.WIZARD_STATE_KEY, JSON.stringify(state));
            } catch (e) { /* localStorage may be disabled — non-fatal */ }
        },

        restoreWizardState: function () {
            if (!$('#import-wizard').length) return; // not on importer tab
            let state;
            try {
                const raw = localStorage.getItem(this.WIZARD_STATE_KEY);
                if (!raw) return;
                state = JSON.parse(raw);
            } catch (e) { return; }

            if (!state || !state.ts) return;
            if (Date.now() - state.ts > this.WIZARD_STATE_TTL) {
                this.clearWizardState();
                return;
            }

            // Don't restore values onto fields automatically — the upstream may
            // have changed and we don't want stale form state. Just keep the
            // metadata_prefix in importData so when the user reconnects the
            // dropdown re-selects what they had before.
            this.importData.metadata_prefix = state.metadata_prefix;
            this.importData.dc_fields = state.dc_fields || [];

            if (state.source_url) {
                $('#source-url').val(state.source_url);
                this.notice('info',
                    'Resumed wizard state from a previous session. Click "Connect" to refetch the metadata format you had selected (' +
                    (state.metadata_prefix || 'oai_dc') + ').'
                );
            }
        },

        clearWizardState: function () {
            try { localStorage.removeItem(this.WIZARD_STATE_KEY); } catch (e) {}
        },

        nextStep: function () {
            const $current = $('.import-step.active');
            const currentStep = parseInt($current.data('step'));

            if (currentStep === 1 && !this.importData.repository) {
                alert('Please connect to a repository first');
                return;
            }
            if (currentStep === 3 && !$('#target-collection').val()) {
                alert('Please select a collection');
                return;
            }

            // Going to step 4: ensure mapping is freshly loaded with current preview + collection
            if (currentStep === 3) this.loadMappingRows();

            if (currentStep === 4) {
                $('#summary-source').text(this.importData.source_url);
                $('#summary-set').text($('#source-set option:selected').text() || 'All');
                $('#summary-collection').text($('#target-collection option:selected').text());
                $('#summary-count').text(this.importData.total !== null && this.importData.total !== undefined ? this.importData.total : 'Unknown');
            }

            $current.removeClass('active').hide();
            $current.next('.import-step').addClass('active').show();

            const newStep = currentStep + 1;
            $('#btn-prev-step').toggle(newStep > 1);
            $('#btn-next-step').toggle(newStep < 5);
        },

        prevStep: function () {
            const $current = $('.import-step.active');
            const currentStep = parseInt($current.data('step'));

            $current.removeClass('active').hide();
            $current.prev('.import-step').addClass('active').show();

            const newStep = currentStep - 1;
            $('#btn-prev-step').toggle(newStep > 1);
            $('#btn-next-step').show();
        },

        // ---------- Delete a previous import ----------

        /**
         * Two-step confirmation flow:
         *   1. Backend reports how many items the import created
         *   2. Ask whether to ALSO trash those items, or just remove the history row
         *   3. Final confirmation, then call ajax_delete_import
         * Items go to WP Trash so they can be recovered manually.
         */
        deleteImport: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const id = $btn.data('import-id');

            this.setLoading($btn, true);
            this.ajax('tainacan_oai_count_import_items', { import_id: id })
                .done(function (response) {
                    TainacanOAI.setLoading($btn, false);
                    if (!response.success) {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                        return;
                    }
                    const count = parseInt(response.data.count, 10) || 0;

                    let deleteItems = false;
                    if (count > 0) {
                        deleteItems = confirm(
                            'This import has ' + count + ' associated item(s) in Tainacan.\n\n' +
                            'OK   = move those items (and their attachments) to Trash\n' +
                            'Cancel = keep items, only remove the history row'
                        );
                    }

                    const finalMsg = deleteItems
                        ? 'Are you sure?\n\n' + count + ' item(s) will be moved to Trash and the import history row will be deleted.'
                        : 'Remove the import history row?\nImported items will NOT be touched.';
                    if (!confirm(finalMsg)) return;

                    TainacanOAI.setLoading($btn, true);
                    TainacanOAI.ajax('tainacan_oai_delete_import', {
                        import_id: id,
                        delete_items: deleteItems ? 1 : 0
                    }, { timeout: 600000 })
                        .done(function (r) {
                            if (r.success) {
                                TainacanOAI.notice('success', r.data.message);
                                setTimeout(function () { location.reload(); }, 1200);
                            } else {
                                TainacanOAI.notice('error', TainacanOAI.errorMessage(r));
                            }
                        })
                        .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                        .always(function () { TainacanOAI.setLoading($btn, false); });
                })
                .fail(function () {
                    TainacanOAI.setLoading($btn, false);
                    TainacanOAI.notice('error', tainacanOAI.strings.error);
                });
        },

        // ---------- Activity log viewer ----------

        // Renders one parsed log line into the pane with level coloring
        renderLogLine: function (line) {
            let cls = 'oai-log-info';
            if (line.indexOf('[ERROR]') !== -1) cls = 'oai-log-error';
            else if (line.indexOf('[WARN]') !== -1) cls = 'oai-log-warn';
            return $('<div>', { 'class': 'oai-log-line ' + cls, text: line });
        },

        toggleImportLog: function (e) {
            e.preventDefault();
            const id = $(e.currentTarget).data('import-id');
            $('#oai-log-' + id).toggle();
        },

        clearImportLog: function (e) {
            e.preventDefault();
            if (!confirm('Clear the activity log for this import? Items already imported are not affected.')) return;
            const id = $(e.currentTarget).data('import-id');

            this.ajax('tainacan_oai_clear_import_log', { import_id: id })
                .done(function (response) {
                    if (response.success) {
                        $('#oai-log-' + id).remove();
                        $('button.oai-toggle-log[data-import-id="' + id + '"]').remove();
                        TainacanOAI.notice('success', response.data.message);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); });
        },

        loadFullImportLog: function (e) {
            e.preventDefault();
            const id = $(e.currentTarget).data('import-id');
            const $pane = $('#oai-log-pane-' + id).empty().append($('<div>', { text: 'Loading…' }));

            this.ajax('tainacan_oai_get_import_log', { import_id: id })
                .done(function (response) {
                    if (!response.success) {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                        return;
                    }
                    TainacanOAI.fillLogPane($pane, response.data.log);
                });
        },

        clearHarvestLog: function (e) {
            e.preventDefault();
            if (!confirm('Clear the activity log for this harvest source?')) return;
            const id = $(e.currentTarget).data('source-id');

            this.ajax('tainacan_oai_clear_harvest_log', { id: id })
                .done(function (response) {
                    if (response.success) {
                        $('#harvest-log-' + id).remove();
                        TainacanOAI.notice('success', response.data.message);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                });
        },

        loadFullHarvestLog: function (e) {
            e.preventDefault();
            const id = $(e.currentTarget).data('source-id');
            const $pane = $('#harvest-log-pane-' + id).empty().append($('<div>', { text: 'Loading…' }));

            this.ajax('tainacan_oai_get_harvest_log', { id: id })
                .done(function (response) {
                    if (!response.success) {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                        return;
                    }
                    TainacanOAI.fillLogPane($pane, response.data.log);
                });
        },

        fillLogPane: function ($pane, logText) {
            $pane.empty();
            const lines = String(logText || '').split('\n').filter(Boolean);
            if (lines.length === 0) {
                $pane.append($('<div>', { 'class': 'oai-log-line', text: '(log is empty)' }));
                return;
            }
            lines.forEach(function (line) {
                $pane.append(TainacanOAI.renderLogLine(line));
            });
        },

        // ---------- Harvest sources (scheduled) ----------

        openHarvestModal: function (sourceData) {
            const isEdit = sourceData && sourceData.id;
            $('#harvest-modal-title').text(isEdit
                ? tainacanOAI.strings.edit_harvest || 'Edit Harvest Source'
                : tainacanOAI.strings.new_harvest || 'New Harvest Source');

            $('#harvest-id').val(isEdit ? sourceData.id : '');
            $('#harvest-label').val(isEdit ? sourceData.label : '');
            $('#harvest-url').val(isEdit ? sourceData.source_url : '');
            $('#harvest-collection').val(isEdit ? sourceData.collection_id : '');
            $('#harvest-set').val(isEdit ? (sourceData.set_spec || '') : '');
            $('#harvest-schedule').val(isEdit ? sourceData.schedule : 'daily');
            $('#harvest-active').prop('checked', isEdit ? !!parseInt(sourceData.is_active) : true);
            $('#harvest-bitstreams').prop('checked', isEdit ? !!parseInt(sourceData.download_bitstreams) : true);

            this._pendingMapping = isEdit && sourceData.metadata_mapping ? sourceData.metadata_mapping : {};

            // Reset mapping table; trigger load if collection is set
            $('#harvest-mapping-table tbody').html('<tr><td colspan="2" style="text-align:center;color:#999;">Select a collection to load mapping options.</td></tr>');
            if ($('#harvest-collection').val()) this.loadHarvestMapping();

            $('#harvest-source-modal').show();
        },

        closeHarvestModal: function () {
            $('#harvest-source-modal').hide();
            this._pendingMapping = {};
        },

        loadHarvestMapping: function () {
            const collectionId = $('#harvest-collection').val();
            const $tbody = $('#harvest-mapping-table tbody').empty();
            if (!collectionId) {
                $tbody.append('<tr><td colspan="2" style="text-align:center;color:#999;">Select a collection.</td></tr>');
                return;
            }
            $tbody.append('<tr><td colspan="2" style="text-align:center;">Loading…</td></tr>');

            // We don't have a preview yet at this point — feed an empty source_fields
            // so the backend returns the 15 standard DC rows.
            this.ajax('tainacan_oai_build_mapping', {
                collection_id: collectionId,
                source_fields: '[]'
            }).done(function (response) {
                if (!response.success) {
                    $tbody.html('<tr><td colspan="2" style="color:#d63638;">' + TainacanOAI.errorMessage(response) + '</td></tr>');
                    return;
                }
                $tbody.empty();
                const rows = response.data.rows || [];
                const meta = response.data.collection_metadata || [];
                const pending = TainacanOAI._pendingMapping || {};

                rows.forEach(function (row) {
                    const $tr = $('<tr>');
                    $tr.append($('<td>').append($('<strong>', { text: 'dc:' + row.name })));

                    const $select = $('<select>', { 'class': 'harvest-mapping-select', name: 'mapping[' + row.name + ']' });
                    $select.append($('<option>', { value: '', text: '— Skip —' }));
                    meta.forEach(function (m) {
                        if (m.is_core) return;
                        const isSelected = pending[row.name]
                            ? String(m.id) === String(pending[row.name])
                            : String(m.id) === String(row.suggested_metadatum_id);
                        $select.append($('<option>', {
                            value: m.id,
                            text: m.name + (m.required ? ' *' : '') + (m.multiple ? ' [multi]' : ''),
                            selected: isSelected
                        }));
                    });
                    $tr.append($('<td>').append($select));
                    $tbody.append($tr);
                });
            }).fail(function () {
                $tbody.html('<tr><td colspan="2" style="color:#d63638;">Failed to load mapping options.</td></tr>');
            });
        },

        saveHarvestSource: function () {
            const mapping = {};
            $('#harvest-mapping-table .harvest-mapping-select').each(function () {
                const m = ($(this).attr('name') || '').match(/\[(.+)\]/);
                if (!m) return;
                const value = $(this).val();
                if (value) mapping[m[1]] = value;
            });

            const data = {
                id: $('#harvest-id').val() || 0,
                label: $('#harvest-label').val().trim(),
                source_url: $('#harvest-url').val().trim(),
                collection_id: $('#harvest-collection').val(),
                set_spec: $('#harvest-set').val().trim(),
                schedule: $('#harvest-schedule').val(),
                is_active: $('#harvest-active').is(':checked') ? 1 : 0,
                download_bitstreams: $('#harvest-bitstreams').is(':checked') ? 1 : 0,
                metadata_mapping: JSON.stringify(mapping)
            };

            if (!data.label || !data.source_url || !data.collection_id) {
                alert('Label, URL and Collection are required.');
                return;
            }

            const $btn = $('#btn-save-harvest');
            this.setLoading($btn, true);

            this.ajax('tainacan_oai_save_harvest_source', data)
                .done(function (response) {
                    if (response.success) {
                        TainacanOAI.notice('success', response.data.message);
                        TainacanOAI.closeHarvestModal();
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        editHarvestSource: function (e) {
            e.preventDefault();
            const id = $(e.currentTarget).closest('tr').data('source-id');

            this.ajax('tainacan_oai_get_harvest_source', { id: id })
                .done(function (response) {
                    if (response.success) TainacanOAI.openHarvestModal(response.data);
                    else TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); });
        },

        deleteHarvestSource: function (e) {
            e.preventDefault();
            if (!confirm('Delete this harvest source? Items already imported will not be removed.')) return;

            const id = $(e.currentTarget).closest('tr').data('source-id');
            this.ajax('tainacan_oai_delete_harvest_source', { id: id })
                .done(function (response) {
                    if (response.success) {
                        TainacanOAI.notice('success', response.data.message);
                        $('tr[data-source-id="' + id + '"]').fadeOut();
                        $('#harvest-errors-' + id).remove();
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                });
        },

        runHarvestSource: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const id = $btn.closest('tr').data('source-id');

            this.setLoading($btn, true);
            this.notice('info', 'Running harvest — this may take several minutes…');

            // Long-running synchronous request; bump timeout substantially.
            this.ajax('tainacan_oai_run_harvest_source', { id: id }, { timeout: 1800000 })
                .done(function (response) {
                    if (response.success) {
                        const s = response.data.stats;
                        TainacanOAI.notice(
                            'success',
                            'Created ' + s.created + ', Updated ' + s.updated +
                            ', Skipped ' + s.skipped + ', Failed ' + s.failed +
                            ', Deleted ' + s.deleted + ' (in ' + s.pages + ' page(s))'
                        );
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                })
                .fail(function () {
                    // Even on JS timeout the server keeps running — refresh later
                    TainacanOAI.notice('warning', 'Connection timed out, but the harvest is still running on the server. Reload in a few minutes.');
                })
                .always(function () { TainacanOAI.setLoading($btn, false); });
        },

        toggleHarvestSource: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const id = $btn.closest('tr').data('source-id');

            this.ajax('tainacan_oai_toggle_harvest_source', { id: id })
                .done(function (response) {
                    if (response.success) {
                        TainacanOAI.notice('success', response.data.is_active ? 'Source resumed.' : 'Source paused.');
                        setTimeout(function () { location.reload(); }, 600);
                    } else {
                        TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    }
                });
        },

        // ---------- Chart ----------

        initChart: function () {
            const $canvas = $('#activity-chart');
            if (!$canvas.length || typeof Chart === 'undefined') return;

            const stats = $canvas.data('stats');
            if (!stats || !stats.length) return;

            new Chart($canvas[0].getContext('2d'), {
                type: 'line',
                data: {
                    labels: stats.map(function (d) { return d.date; }),
                    datasets: [{
                        label: 'Requests',
                        data: stats.map(function (d) { return d.total; }),
                        borderColor: '#187181',
                        backgroundColor: 'rgba(24, 113, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Errors',
                        data: stats.map(function (d) { return d.errors; }),
                        borderColor: '#dc3545',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
    };

    $(document).ready(function () {
        TainacanOAI.init();
    });

})(jQuery);
