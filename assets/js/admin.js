/**
 * Tainacan OAI-PMH Enhanced - Admin JavaScript
 */
(function($) {
    'use strict';

    const TainacanOAI = {
        currentStep: 1,
        importData: {},

        init: function() {
            this.bindEvents();
            this.initChart();
        },

        bindEvents: function() {
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
            $('#target-collection').on('change', this.loadCollectionMetadata.bind(this));
        },

        // Dashboard
        testEndpoint: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_test_endpoint', nonce: tainacanOAI.nonce },
                success: function(response) {
                    const type = response.success ? 'success' : 'error';
                    const msg = response.success 
                        ? response.data.message + ' (' + response.data.time + 's)'
                        : response.data.message;
                    $('#endpoint-result').html('<div class="notice notice-' + type + '"><p>' + msg + '</p></div>');
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        reindex: function(e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_reindex)) return;
            
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_reindex', nonce: tainacanOAI.nonce },
                success: function(response) {
                    TainacanOAI.showNotice(response.success ? 'success' : 'error', response.data.message);
                    if (response.success) setTimeout(() => location.reload(), 1500);
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        reindexCollection: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const collectionId = $btn.data('collection');
            
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_reindex_collection', nonce: tainacanOAI.nonce, collection_id: collectionId },
                success: function(response) {
                    TainacanOAI.showNotice(response.success ? 'success' : 'error', response.data.message);
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        clearCache: function(e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_clear)) return;
            
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_clear_cache', nonce: tainacanOAI.nonce },
                success: function(response) {
                    TainacanOAI.showNotice(response.success ? 'success' : 'error', response.data.message);
                    if (response.success) setTimeout(() => location.reload(), 1500);
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        // Validation
        runValidation: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            $('#validation-results').html('<div class="oai-loading"><span class="spinner is-active"></span></div>');
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_validate', nonce: tainacanOAI.nonce },
                success: function(response) {
                    if (response.success) location.reload();
                    else TainacanOAI.showNotice('error', response.data.message);
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        // Harvesters
        unblockIP: function(e) {
            e.preventDefault();
            if (!confirm(tainacanOAI.strings.confirm_unblock)) return;
            
            const $btn = $(e.currentTarget);
            const ip = $btn.data('ip');
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_unblock_ip', nonce: tainacanOAI.nonce, ip: ip },
                success: function(response) {
                    if (response.success) $btn.closest('tr').fadeOut();
                    else TainacanOAI.showNotice('error', response.data.message);
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        // Importer
        fetchRepository: function(e) {
            e.preventDefault();
            const url = $('#source-url').val().trim();
            if (!url) { alert('Please enter a URL'); return; }
            
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_fetch_repository', nonce: tainacanOAI.nonce, url: url },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        TainacanOAI.importData.source_url = url;
                        TainacanOAI.importData.repository = data;
                        
                        $('#repo-name').text(data.repository_name);
                        $('#repo-email').text(data.admin_email);
                        $('#repo-earliest').text(data.earliest_datestamp);
                        $('#repository-info').show();
                        
                        TainacanOAI.fetchSets(url);
                        $('#btn-next-step').show();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                error: function() { alert('Connection error'); },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        fetchSets: function(url) {
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_fetch_sets', nonce: tainacanOAI.nonce, url: url },
                success: function(response) {
                    if (response.success) {
                        const $select = $('#source-set');
                        $select.find('option:not(:first)').remove();
                        response.data.forEach(function(set) {
                            $select.append($('<option>', { value: set.spec, text: set.name + ' (' + set.spec + ')' }));
                        });
                        TainacanOAI.importData.sets = response.data;
                    }
                }
            });
        },

        previewRecords: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_preview_records', nonce: tainacanOAI.nonce, url: this.importData.source_url, set: $('#source-set').val() },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        TainacanOAI.importData.preview = data;
                        TainacanOAI.importData.dc_fields = data.dc_fields;
                        TainacanOAI.importData.total = data.total;
                        
                        $('#preview-count').text('Found approximately ' + (data.total || 'unknown number of') + ' records');
                        
                        const $tbody = $('#preview-table tbody').empty();
                        data.records.forEach(function(record) {
                            $tbody.append('<tr><td>' + (record.identifier || '-') + '</td><td>' + (record.metadata.title || '-') + '</td><td>' + (record.datestamp || '-') + '</td></tr>');
                        });
                        
                        $('#preview-results').show();
                        $('#btn-next-step').show();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                complete: function() { TainacanOAI.setLoading($btn, false); }
            });
        },

        loadCollectionMetadata: function(e) {
            const collectionId = $(e.currentTarget).val();
            if (!collectionId) return;
            
            TainacanOAI.importData.collection_id = collectionId;
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_get_collection_metadata', nonce: tainacanOAI.nonce, collection_id: collectionId },
                success: function(response) {
                    if (response.success) {
                        TainacanOAI.importData.collection_metadata = response.data;
                        TainacanOAI.buildMappingTable();
                    }
                }
            });
        },

        buildMappingTable: function() {
            const $tbody = $('#mapping-table tbody').empty();
            const metadata = this.importData.collection_metadata || [];
            const dcFields = this.importData.dc_fields || [];
            const preview = this.importData.preview?.records?.[0] || {};
            
            dcFields.forEach(function(field) {
                const sampleValue = preview.metadata?.[field.name] || '';
                
                let $select = $('<select>', { name: 'mapping[' + field.name + ']', class: 'mapping-select' });
                $select.append($('<option>', { value: '', text: '-- Skip --' }));
                
                metadata.forEach(function(meta) {
                    $select.append($('<option>', {
                        value: meta.id,
                        text: meta.name,
                        selected: meta.dc_mapping === field.name
                    }));
                });
                
                $tbody.append('<tr><td><strong>dc:' + field.name + '</strong></td><td><small>' + (typeof sampleValue === 'string' ? sampleValue.substring(0, 80) : '-') + '</small></td><td>' + $select.prop('outerHTML') + '</td></tr>');
            });
        },

        startImport: function(e) {
            e.preventDefault();
            
            const mapping = {};
            $('#mapping-table .mapping-select').each(function() {
                const field = $(this).attr('name').match(/\[(.+)\]/)[1];
                const value = $(this).val();
                if (value) mapping[field] = value;
            });
            
            const $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: {
                    action: 'tainacan_oai_start_import',
                    nonce: tainacanOAI.nonce,
                    source_url: this.importData.source_url,
                    collection_id: this.importData.collection_id,
                    set_spec: $('#source-set').val(),
                    from_date: $('#from-date').val(),
                    until_date: $('#until-date').val(),
                    metadata_mapping: JSON.stringify(mapping)
                },
                success: function(response) {
                    if (response.success) {
                        TainacanOAI.importData.import_id = response.data.import_id;
                        $('#import-progress').show();
                        TainacanOAI.processImport();
                    } else {
                        alert('Error: ' + response.data.message);
                        TainacanOAI.setLoading($btn, false);
                    }
                },
                error: function() {
                    alert('Connection error');
                    TainacanOAI.setLoading($btn, false);
                }
            });
        },

        processImport: function() {
            $.ajax({
                url: tainacanOAI.ajax_url,
                type: 'POST',
                data: { action: 'tainacan_oai_process_import', nonce: tainacanOAI.nonce, import_id: this.importData.import_id },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const progress = data.total_records > 0 ? Math.round((data.total_imported / data.total_records) * 100) : 0;
                        
                        $('#import-progress-bar').css('width', progress + '%');
                        $('#import-status').text('Imported: ' + data.total_imported + ' / ' + data.total_records + ' (Failed: ' + data.failed + ')');
                        
                        if (data.has_more) {
                            setTimeout(function() { TainacanOAI.processImport(); }, 500);
                        } else {
                            TainacanOAI.showNotice('success', 'Import completed!');
                            TainacanOAI.setLoading($('#btn-start-import'), false);
                        }
                    } else {
                        TainacanOAI.showNotice('error', response.data.message);
                        TainacanOAI.setLoading($('#btn-start-import'), false);
                    }
                },
                error: function() {
                    TainacanOAI.showNotice('error', 'Connection error');
                    TainacanOAI.setLoading($('#btn-start-import'), false);
                }
            });
        },

        nextStep: function() {
            const $current = $('.import-step.active');
            const currentStep = parseInt($current.data('step'));
            
            if (currentStep === 1 && !this.importData.repository) { alert('Please connect to a repository first'); return; }
            if (currentStep === 3 && !$('#target-collection').val()) { alert('Please select a collection'); return; }
            
            if (currentStep === 4) {
                $('#summary-source').text(this.importData.source_url);
                $('#summary-set').text($('#source-set option:selected').text() || 'All');
                $('#summary-collection').text($('#target-collection option:selected').text());
                $('#summary-count').text(this.importData.total || 'Unknown');
            }
            
            $current.removeClass('active').hide();
            $current.next('.import-step').addClass('active').show();
            
            const newStep = currentStep + 1;
            $('#btn-prev-step').toggle(newStep > 1);
            $('#btn-next-step').toggle(newStep < 5);
        },

        prevStep: function() {
            const $current = $('.import-step.active');
            const currentStep = parseInt($current.data('step'));
            
            $current.removeClass('active').hide();
            $current.prev('.import-step').addClass('active').show();
            
            const newStep = currentStep - 1;
            $('#btn-prev-step').toggle(newStep > 1);
            $('#btn-next-step').show();
        },

        // Chart
        initChart: function() {
            const $canvas = $('#activity-chart');
            if (!$canvas.length || typeof Chart === 'undefined') return;
            
            const stats = $canvas.data('stats');
            if (!stats || !stats.length) return;
            
            new Chart($canvas[0].getContext('2d'), {
                type: 'line',
                data: {
                    labels: stats.map(d => d.date),
                    datasets: [{
                        label: 'Requests',
                        data: stats.map(d => d.total),
                        borderColor: '#187181',
                        backgroundColor: 'rgba(24, 113, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Errors',
                        data: stats.map(d => d.errors),
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
        },

        // Helpers
        setLoading: function($btn, loading) {
            if (loading) {
                $btn.prop('disabled', true).data('text', $btn.html());
                $btn.html('<span class="spinner is-active" style="margin:0;float:none;"></span>');
            } else {
                $btn.prop('disabled', false).html($btn.data('text'));
            }
        },

        showNotice: function(type, message) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.tainacan-oai-content').prepend($notice);
            setTimeout(function() { $notice.fadeOut(); }, 5000);
        }
    };

    $(document).ready(function() {
        TainacanOAI.init();
    });

})(jQuery);
