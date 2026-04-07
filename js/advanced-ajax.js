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

    function renderDiagnosticsSummary(data) {
        var summary = data.summary || {};
        var reasonCounts = data.reason_counts || {};
        var reasonHtml = '';
        Object.keys(reasonCounts).sort().forEach(function(key) {
            reasonHtml += '<li><code>' + esc(key) + '</code>: <strong>' + esc(reasonCounts[key]) + '</strong></li>';
        });
        if (!reasonHtml) {
            reasonHtml = '<li>No mismatches detected.</li>';
        }

        var html = '' +
            '<div class="notice notice-info" style="padding:10px 12px; margin:8px 0;">' +
            '<p><strong>Woo rows:</strong> ' + esc(summary.woo_rows || 0) +
            ' | <strong>Roster rows:</strong> ' + esc(summary.roster_rows || 0) +
            ' | <strong>Intersection:</strong> ' + esc(summary.intersection || 0) +
            ' | <strong>Only Woo:</strong> ' + esc(summary.only_woo || 0) +
            ' | <strong>Only Rosters:</strong> ' + esc(summary.only_rosters || 0) +
            ' | <strong>Mismatches:</strong> ' + esc(summary.mismatch_rows || 0) +
            ' | <strong>Elapsed:</strong> ' + esc(summary.elapsed_ms || 0) + 'ms</p>' +
            '<p><strong>Reason counts</strong></p><ul style="margin-left:18px;">' + reasonHtml + '</ul>' +
            '</div>';
        $('#intersoccer-diagnostics-summary').html(html);
    }

    function renderDiagnosticsMismatches(data) {
        var rows = data.mismatches || [];
        if (!rows.length) {
            $('#intersoccer-diagnostics-mismatches').html('<p>No mismatch rows returned.</p>');
            return;
        }

        var html = '' +
            '<table class="widefat striped" style="margin-top:8px;">' +
            '<thead><tr>' +
            '<th>Order Item</th><th>Order</th><th>Woo Venue</th><th>Roster Venue</th>' +
            '<th>Woo Course Day</th><th>Roster Course Day</th><th>Reasons</th>' +
            '</tr></thead><tbody>';

        rows.forEach(function(row) {
            html += '<tr>' +
                '<td>' + esc(row.order_item_id) + '</td>' +
                '<td>' + esc(row.order_id) + '</td>' +
                '<td>' + esc(row.woo_venue) + '</td>' +
                '<td>' + esc(row.roster_venue) + '</td>' +
                '<td>' + esc(row.woo_course_day) + '</td>' +
                '<td>' + esc(row.roster_course_day) + '</td>' +
                '<td>' + esc((row.reasons || []).join(', ')) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#intersoccer-diagnostics-mismatches').html(html);
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
        var payload = {
            action: 'intersoccer_run_reports_rosters_diagnostics',
            nonce: intersoccer_ajax.nonce,
            year: $('#diag-year').val(),
            activity_type: $('#diag-activity-type').val(),
            season_type: $('#diag-season-type').val(),
            region: $('#diag-region').val(),
            exclude_buyclub: $('#diag-exclude-buyclub').is(':checked') ? 1 : 0,
            limit: 200,
            offset: 0
        };
        $('#intersoccer-diagnostics-summary').html('<p>Running diagnostics...</p>');
        $('#intersoccer-diagnostics-mismatches').empty();

        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: payload,
            success: function(response) {
                if (!response || !response.success) {
                    var msg = response && response.data && response.data.message ? response.data.message : 'Diagnostics failed.';
                    $('#intersoccer-diagnostics-summary').html('<div class="notice notice-error"><p>' + esc(msg) + '</p></div>');
                    return;
                }
                renderDiagnosticsSummary(response.data || {});
                renderDiagnosticsMismatches(response.data || {});
            },
            error: function(xhr) {
                var text = (xhr && xhr.responseText) ? xhr.responseText : 'Network error.';
                $('#intersoccer-diagnostics-summary').html('<div class="notice notice-error"><p>' + esc(text) + '</p></div>');
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
        var payload = {
            action: 'intersoccer_fix_reports_rosters_issues_safe',
            nonce: intersoccer_ajax.nonce,
            year: $('#diag-year').val(),
            activity_type: $('#diag-activity-type').val(),
            season_type: $('#diag-season-type').val(),
            region: $('#diag-region').val(),
            exclude_buyclub: $('#diag-exclude-buyclub').is(':checked') ? 1 : 0,
            limit: 200,
            offset: 0
        };

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
