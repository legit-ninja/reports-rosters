jQuery(document).ready(function($) {
    if (typeof intersoccer_ajax === 'undefined') {
        return;
    }
    // Handle attendance status change
    $('.attendance-status').on('change', function() {
        var $select = $(this);
        var event_id = $select.data('event-id');
        var player_name = $select.data('player-name');
        var date = $select.data('date');
        var status = $select.val();

        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_save_attendance',
                nonce: intersoccer_ajax.nonce,
                event_id: event_id,
                player_name: player_name,
                date: date,
                status: status,
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('An error occurred while saving attendance.');
            }
        });
    });

    // Handle coach notes form submission
    $('#coach-notes-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var data = {
            action: 'intersoccer_save_coach_notes',
            nonce: intersoccer_ajax.nonce,
            event_id: $form.find('input[name="event_id"]').val(),
            player_id: $form.find('select[name="player_id"]').val(),
            date: $form.find('input[name="date"]').val(),
            notes: $form.find('textarea[name="notes"]').val(),
            incident_report: $form.find('textarea[name="incident_report"]').val(),
        };

        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $form[0].reset();
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('An error occurred while saving notes.');
            }
        });
    });

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    var diagnosticsState = {
        offset: 0
    };

    function collectDiagnosticsPayload() {
        var orderStatuses = [];
        $('.diag-order-status:checked').each(function() {
            orderStatuses.push($(this).val());
        });

        return {
            action: 'intersoccer_run_reports_rosters_diagnostics',
            nonce: intersoccer_ajax.nonce,
            year: $('#diag-year').val(),
            activity_type: $('#diag-activity-type').val(),
            season_type: $('#diag-season-type').val(),
            region: $('#diag-region').val(),
            exclude_buyclub: $('#diag-exclude-buyclub').is(':checked') ? 1 : 0,
            order_id: $('#diag-order-id').val(),
            reason_filter: $('#diag-reason-filter').val(),
            order_statuses: orderStatuses,
            limit: $('#diag-page-size').val() || 200,
            offset: diagnosticsState.offset || 0
        };
    }

    function collectSafeFixPayload() {
        var payload = collectDiagnosticsPayload();
        payload.action = 'intersoccer_fix_reports_rosters_issues_safe';
        return payload;
    }

    function renderDiagnosticsSummary(data) {
        var summary = data.summary || {};
        var reasonCounts = data.reason_counts || {};
        var reasonHtml = '';
        Object.keys(reasonCounts).sort().forEach(function(key) {
            reasonHtml += '<li><button type="button" class="button-link intersoccer-diag-reason-filter" data-reason="' + esc(key) + '"><code>' + esc(key) + '</code></button>: <strong>' + esc(reasonCounts[key]) + '</strong></li>';
        });
        if (!reasonHtml) {
            reasonHtml = '<li>No mismatches detected.</li>';
        }

        var filteredNote = '';
        if (summary.filtered_mismatch_rows != null && summary.mismatch_rows != null
            && summary.filtered_mismatch_rows !== summary.mismatch_rows) {
            filteredNote = ' | <strong>Filtered:</strong> ' + esc(summary.filtered_mismatch_rows);
        }

        var html = '' +
            '<div class="notice notice-info" style="padding:10px 12px; margin:8px 0;">' +
            '<p><strong>Woo rows:</strong> ' + esc(summary.woo_rows || 0) +
            ' | <strong>Roster rows:</strong> ' + esc(summary.roster_rows || 0) +
            ' | <strong>Intersection:</strong> ' + esc(summary.intersection || 0) +
            ' | <strong>Only Woo:</strong> ' + esc(summary.only_woo || 0) +
            ' | <strong>Only Rosters:</strong> ' + esc(summary.only_rosters || 0) +
            ' | <strong>Mismatches:</strong> ' + esc(summary.mismatch_rows || 0) + filteredNote +
            ' | <strong>Elapsed:</strong> ' + esc(summary.elapsed_ms || 0) + 'ms</p>' +
            '<p><strong>Reason counts</strong> (click to filter)</p><ul style="margin-left:18px;">' + reasonHtml + '</ul>' +
            '</div>';
        $('#intersoccer-diagnostics-summary').html(html);
    }

    function renderDiagnosticsPagination(data) {
        var pagination = data.pagination || {};
        var total = pagination.total || 0;
        var limit = pagination.limit || 200;
        var offset = pagination.offset || 0;
        var returned = pagination.returned || 0;

        if (total <= 0) {
            $('#intersoccer-diagnostics-pagination').empty();
            return;
        }

        var start = total === 0 ? 0 : offset + 1;
        var end = offset + returned;
        var prevDisabled = offset <= 0 ? ' disabled' : '';
        var nextDisabled = (offset + limit) >= total ? ' disabled' : '';

        var html = '<p class="intersoccer-diagnostics-pagination-bar">' +
            '<button type="button" class="button" id="intersoccer-diag-prev"' + prevDisabled + '>Previous</button> ' +
            '<button type="button" class="button" id="intersoccer-diag-next"' + nextDisabled + '>Next</button> ' +
            '<span style="margin-left:8px;">Showing ' + esc(start) + '–' + esc(end) + ' of ' + esc(total) + '</span>' +
            '</p>';
        $('#intersoccer-diagnostics-pagination').html(html);
    }

    function renderDiagnosticsMismatches(data) {
        var rows = data.mismatches || [];
        if (!rows.length) {
            $('#intersoccer-diagnostics-mismatches').html('<p>No mismatch rows returned for the current filters.</p>');
            return;
        }

        var html = '' +
            '<table class="widefat striped intersoccer-sync-queue-table" style="margin-top:8px;">' +
            '<thead><tr>' +
            '<th>Participant</th><th>Product</th><th>Activity</th><th>Order</th><th>Status</th><th>Date</th><th>Reasons</th><th>Actions</th>' +
            '</tr></thead><tbody>';

        rows.forEach(function(row) {
            var orderLink = row.edit_order_url
                ? '<a href="' + esc(row.edit_order_url) + '">#' + esc(row.order_id) + '</a>'
                : esc(row.order_id);
            var participant = row.participant_name || row.roster_player_name || '—';
            var orderDate = row.order_date ? String(row.order_date).substring(0, 10) : '—';

            html += '<tr data-order-item-id="' + esc(row.order_item_id) + '">' +
                '<td>' + esc(participant) + '<br><span class="description">Item ' + esc(row.order_item_id) + '</span></td>' +
                '<td>' + esc(row.product_name || '—') + '</td>' +
                '<td>' + esc(row.activity_type || '—') + '</td>' +
                '<td>' + orderLink + '</td>' +
                '<td>' + esc(row.order_status || '—') + '</td>' +
                '<td>' + esc(orderDate) + '</td>' +
                '<td><code>' + esc((row.reasons || []).join(', ')) + '</code></td>' +
                '<td>' +
                '<button type="button" class="button button-secondary intersoccer-queue-fix-sync" data-order-item-id="' + esc(row.order_item_id) + '">Fix Sync</button>' +
                '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#intersoccer-diagnostics-mismatches').html(html);
    }

    function runDiagnosticsRequest() {
        var payload = collectDiagnosticsPayload();
        $('#intersoccer-diagnostics-summary').html('<p>Running sync scan...</p>');
        $('#intersoccer-diagnostics-mismatches').empty();
        $('#intersoccer-diagnostics-pagination').empty();

        return $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: payload
        });
    }

    function handleDiagnosticsResponse(response) {
        if (!response || !response.success) {
            var msg = response && response.data && response.data.message ? response.data.message : 'Sync scan failed.';
            $('#intersoccer-diagnostics-summary').html('<div class="notice notice-error"><p>' + esc(msg) + '</p></div>');
            return;
        }
        renderDiagnosticsSummary(response.data || {});
        renderDiagnosticsMismatches(response.data || {});
        renderDiagnosticsPagination(response.data || {});
        if (response.data && response.data.pagination) {
            diagnosticsState.offset = response.data.pagination.offset || 0;
        }
    }

    function renderSafeFixResults(data) {
        function showSafeFixToast(payload) {
            $('#intersoccer-safe-fix-toast').remove();
            var line = 'Safe fix: ' + esc(payload.run_id || '-') +
                ' | rosters ' + esc(payload.fixed_missing_in_rosters || 0) +
                ', meta ' + esc(payload.fixed_missing_in_woo_meta || 0) +
                ', quarantined ' + esc(payload.quarantined_missing_in_woo || 0);
            $('<div/>', {
                id: 'intersoccer-safe-fix-toast',
                text: line,
                css: {
                    position: 'fixed',
                    bottom: '24px',
                    right: '24px',
                    zIndex: 2147483647,
                    maxWidth: '440px',
                    padding: '14px 18px',
                    background: '#1d2327',
                    color: '#fff',
                    border: '2px solid #72aee6',
                    borderRadius: '4px',
                    boxShadow: '0 4px 12px rgba(0,0,0,.35)',
                    fontSize: '14px',
                    lineHeight: '1.45'
                }
            }).appendTo('body');
        }
        var html = '' +
            '<div class="notice notice-success" style="padding:10px 12px; margin:8px 0;">' +
            '<p><strong>Run ID:</strong> ' + esc(data.run_id || '-') + '</p>' +
            '<p><strong>Fixed missing in rosters:</strong> ' + esc(data.fixed_missing_in_rosters || 0) +
            ' | <strong>Fixed missing Woo meta:</strong> ' + esc(data.fixed_missing_in_woo_meta || 0) +
            ' | <strong>Quarantined missing in Woo:</strong> ' + esc(data.quarantined_missing_in_woo || 0) +
            ' | <strong>Skipped (non-activity_type):</strong> ' + esc(data.skipped_non_activity_type || 0) + '</p>' +
            '<p><strong>Elapsed:</strong> ' + esc(data.elapsed_ms || 0) + 'ms</p>' +
            '<p>' + esc(data.message || 'Safe fix completed.') + '</p>' +
            '<p><em>Please run diagnostics again to verify updated counts.</em></p>' +
            '</div>';

        if ((data.errors || []).length) {
            html += '<div class="notice notice-warning" style="padding:10px 12px; margin:8px 0;">' +
                '<p><strong>Warnings</strong></p><ul style="margin-left:18px;">';
            (data.errors || []).forEach(function(item) {
                html += '<li>' + esc(item) + '</li>';
            });
            html += '</ul></div>';
        }
        var $target = $('#intersoccer-diagnostics-fix-results');
        if ($target.length) {
            $target.show().css({
                display: 'block',
                visibility: 'visible',
                opacity: 1
            });
            $target.html(html);
            $('#intersoccer-diagnostics-summary').prepend(
                '<div class="notice notice-success"><p><strong>Safe fix completed:</strong> ' +
                esc(data.run_id || '-') +
                ' | Fixed rosters: ' + esc(data.fixed_missing_in_rosters || 0) +
                ', Fixed meta: ' + esc(data.fixed_missing_in_woo_meta || 0) +
                ', Quarantined: ' + esc(data.quarantined_missing_in_woo || 0) +
                '</p></div>'
            );
            var $button = $('#intersoccer-fix-issues-safe');
            $button.closest('p').next('.intersoccer-safe-fix-inline-result').remove();
            $button.closest('p').after('<div class="intersoccer-safe-fix-inline-result" style="margin-top:8px;">' + html + '</div>');
            showSafeFixToast(data);
            if ($target[0] && $target[0].scrollIntoView) {
                $target[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        } else {
            $('#intersoccer-diagnostics-summary').prepend('<div class="notice notice-warning"><p>Safe fix completed, but dedicated result panel was not found. Showing output here.</p></div>');
            $('#intersoccer-diagnostics-summary').append(html);
            showSafeFixToast(data);
        }
    }

    $('#intersoccer-reports-rosters-diagnostics-form').on('submit', function(e) {
        e.preventDefault();
        diagnosticsState.offset = 0;
        runDiagnosticsRequest()
            .done(handleDiagnosticsResponse)
            .fail(function(xhr) {
                var text = (xhr && xhr.responseText) ? xhr.responseText : 'Network error.';
                $('#intersoccer-diagnostics-summary').html('<div class="notice notice-error"><p>' + esc(text) + '</p></div>');
            });
    });

    $(document).on('click', '.intersoccer-diag-reason-filter', function(e) {
        e.preventDefault();
        var reason = $(this).data('reason') || '';
        $('#diag-reason-filter').val(reason);
        diagnosticsState.offset = 0;
        runDiagnosticsRequest()
            .done(handleDiagnosticsResponse)
            .fail(function(xhr) {
                var text = (xhr && xhr.responseText) ? xhr.responseText : 'Network error.';
                $('#intersoccer-diagnostics-summary').html('<div class="notice notice-error"><p>' + esc(text) + '</p></div>');
            });
    });

    $(document).on('click', '#intersoccer-diag-prev', function(e) {
        e.preventDefault();
        var limit = parseInt($('#diag-page-size').val(), 10) || 200;
        diagnosticsState.offset = Math.max(0, (diagnosticsState.offset || 0) - limit);
        runDiagnosticsRequest().done(handleDiagnosticsResponse);
    });

    $(document).on('click', '#intersoccer-diag-next', function(e) {
        e.preventDefault();
        var limit = parseInt($('#diag-page-size').val(), 10) || 200;
        diagnosticsState.offset = (diagnosticsState.offset || 0) + limit;
        runDiagnosticsRequest().done(handleDiagnosticsResponse);
    });

    $(document).on('click', '.intersoccer-queue-fix-sync', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var orderItemId = parseInt($btn.data('order-item-id'), 10) || 0;
        if (orderItemId <= 0) {
            return;
        }
        if (!window.confirm('Run safe sync fix for order item ' + orderItemId + '?')) {
            return;
        }

        $btn.prop('disabled', true).text('Fixing...');
        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_fix_reports_rosters_item_safe',
                nonce: intersoccer_ajax.nonce,
                order_item_id: orderItemId
            },
            success: function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data && response.data.message ? response.data.message : 'Fix Sync failed.';
                    alert(msg);
                    $btn.prop('disabled', false).text('Fix Sync');
                    return;
                }
                runDiagnosticsRequest().done(function(refreshResponse) {
                    handleDiagnosticsResponse(refreshResponse);
                    $btn.prop('disabled', false).text('Fix Sync');
                });
            },
            error: function() {
                alert('Fix Sync failed due to a network error.');
                $btn.prop('disabled', false).text('Fix Sync');
            }
        });
    });

    $('#intersoccer-reports-rosters-trace-form').on('submit', function(e) {
        e.preventDefault();
        var payload = {
            action: 'intersoccer_trace_reports_rosters_item',
            nonce: intersoccer_ajax.nonce,
            order_id: $('#trace-order-id').val(),
            order_item_id: $('#trace-order-item-id').val()
        };

        $('#intersoccer-diagnostics-trace').text('Loading trace...');
        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: payload,
            success: function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data && response.data.message ? response.data.message : 'Trace failed.';
                    $('#intersoccer-diagnostics-trace').text(msg);
                    return;
                }
                $('#intersoccer-diagnostics-trace').text(JSON.stringify(response.data || {}, null, 2));
            },
            error: function(xhr) {
                var text = (xhr && xhr.responseText) ? xhr.responseText : 'Network error.';
                $('#intersoccer-diagnostics-trace').text(text);
            }
        });
    });

    $('#intersoccer-fix-issues-safe').on('click', function(e) {
        e.preventDefault();
        var payload = collectSafeFixPayload();

        $('#intersoccer-diagnostics-fix-results').html('<p>Running safe fixes...</p>');
        $('#intersoccer-fix-issues-safe').prop('disabled', true);

        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: payload,
            success: function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data && response.data.message ? response.data.message : 'Safe fix failed.';
                    $('#intersoccer-diagnostics-fix-results').html('<div class="notice notice-error"><p>' + esc(msg) + '</p></div>');
                    return;
                }
                renderSafeFixResults(response.data || {});
            },
            error: function(xhr) {
                var text = (xhr && xhr.responseText) ? xhr.responseText : 'Network error.';
                $('#intersoccer-diagnostics-fix-results').html('<div class="notice notice-error"><p>' + esc(text) + '</p></div>');
            },
            complete: function() {
                $('#intersoccer-fix-issues-safe').prop('disabled', false);
            }
        });
    });
});
