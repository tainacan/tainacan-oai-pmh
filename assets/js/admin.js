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
            $('#btn-next-step').on('click', this.nextStep.bind(this));
            $('#btn-prev-step').on('click', this.prevStep.bind(this));
            $('#target-collection').on('change', this.onCollectionChange.bind(this));
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
                    $('#btn-next-step').show();
                })
                .fail(function () { TainacanOAI.notice('error', tainacanOAI.strings.error); })
                .always(function () { TainacanOAI.setLoading($btn, false); });
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
                set: $('#source-set').val()
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
                metadata_mapping: JSON.stringify(mapping)
            }).done(function (response) {
                if (!response.success) {
                    TainacanOAI.notice('error', TainacanOAI.errorMessage(response));
                    TainacanOAI.setLoading($btn, false);
                    return;
                }
                TainacanOAI.importData.import_id = response.data.import_id;
                $('#import-progress').show();
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

                    if (data.has_more) {
                        setTimeout(function () { TainacanOAI.processImport(); }, TainacanOAI.pollDelay);
                    } else {
                        TainacanOAI.notice('success', 'Import completed!');
                        TainacanOAI.setLoading($('#btn-start-import'), false);
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
                    }
                });
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
