jQuery(function ($) {
    if (typeof window.intersoccer_order_item_sync === 'undefined') {
        return;
    }

    function escHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function strings(key, fallback) {
        var bag = window.intersoccer_order_item_sync.strings || {};
        return bag[key] || fallback;
    }

    function renderNotice($container, type, text, extraHtml) {
        var html = '<div class="notice inline notice-' + escHtml(type) + '"><p>' + escHtml(text) + '</p>';
        if (extraHtml) {
            html += extraHtml;
        }
        html += '</div>';
        $container.html(html);
    }

    function summarizeTrace(trace) {
        var rosterRows = Array.isArray(trace.roster_rows) ? trace.roster_rows : [];
        var health = trace.sync_health && typeof trace.sync_health === 'object' ? trace.sync_health : null;

        if (health && typeof health.in_sync === 'boolean') {
            if (health.in_sync) {
                return {
                    inSync: true,
                    type: 'success',
                    message: strings('in_sync', 'In sync.'),
                    details: '<p><strong>Roster rows:</strong> ' + escHtml(health.roster_row_count || rosterRows.length) + '</p>'
                };
            }
            var detailMsg = strings('out_of_sync', 'Out of sync.');
            if (health.roster_row_count === 0) {
                detailMsg += ' Missing roster row.';
            } else if (health.roster_row_count > 1) {
                detailMsg += ' Multiple roster rows found.';
            } else if (health.has_incomplete_player || health.needs_resync) {
                detailMsg += ' Roster row exists but player data is incomplete.';
            }
            return {
                inSync: false,
                type: 'warning',
                message: detailMsg,
                details: '<p><strong>Roster rows:</strong> ' + escHtml(health.roster_row_count || rosterRows.length) + '</p>'
            };
        }

        if (!rosterRows.length) {
            return {
                inSync: false,
                type: 'warning',
                message: strings('out_of_sync', 'Out of sync.') + ' Missing roster row.',
                details: '<p><strong>Roster rows:</strong> 0</p>'
            };
        }

        if (rosterRows.length > 1) {
            return {
                inSync: false,
                type: 'warning',
                message: strings('out_of_sync', 'Out of sync.') + ' Multiple roster rows found.',
                details: '<p><strong>Roster rows:</strong> ' + escHtml(rosterRows.length) + '</p>'
            };
        }

        return {
            inSync: true,
            type: 'success',
            message: strings('in_sync', 'In sync.'),
            details: '<p><strong>Roster rows:</strong> 1</p>'
        };
    }

    function setBusy($wrap, busy) {
        $wrap.find('.intersoccer-check-sync, .intersoccer-fix-sync').prop('disabled', busy);
        $wrap.find('.intersoccer-sync-spinner').toggleClass('is-active', busy);
    }

    function setBadge($wrap, state, label) {
        var $badge = $wrap.find('.intersoccer-sync-badge');
        if (!$badge.length) {
            return;
        }
        $badge
            .removeClass('intersoccer-sync-badge-unchecked intersoccer-sync-badge-info intersoccer-sync-badge-success intersoccer-sync-badge-warning intersoccer-sync-badge-error')
            .addClass('intersoccer-sync-badge-' + state)
            .text(label);
    }

    function request(action, payload) {
        return $.ajax({
            url: window.intersoccer_order_item_sync.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: $.extend({
                action: action,
                nonce: window.intersoccer_order_item_sync.nonce
            }, payload || {})
        });
    }

    function refreshBadgeFromTrace($wrap) {
        var orderItemId = parseInt($wrap.data('order-item-id'), 10) || 0;
        if (orderItemId <= 0) {
            setBadge($wrap, 'error', strings('badge_error', 'Error'));
            return $.Deferred().resolve().promise();
        }

        setBadge($wrap, 'info', strings('badge_checking', 'Checking...'));
        return request('intersoccer_trace_reports_rosters_item', { order_item_id: orderItemId })
            .done(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    setBadge($wrap, 'error', strings('badge_error', 'Error'));
                    return;
                }
                var summary = summarizeTrace(resp.data);
                setBadge(
                    $wrap,
                    summary.inSync ? 'success' : 'warning',
                    summary.inSync ? strings('badge_in_sync', 'In sync') : strings('badge_out_of_sync', 'Out of sync')
                );
            })
            .fail(function () {
                setBadge($wrap, 'error', strings('badge_error', 'Error'));
            });
    }

    $('.intersoccer-order-item-sync-controls').each(function () {
        refreshBadgeFromTrace($(this));
    });

    $(document).on('click', '.intersoccer-check-sync', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.intersoccer-order-item-sync-controls');
        var $result = $wrap.find('.intersoccer-order-item-sync-result');
        var orderItemId = parseInt($wrap.data('order-item-id'), 10) || 0;
        if (orderItemId <= 0) {
            setBadge($wrap, 'error', strings('badge_error', 'Error'));
            renderNotice($result, 'error', strings('check_failed', 'Sync check failed.'));
            return;
        }

        setBusy($wrap, true);
        setBadge($wrap, 'info', strings('badge_checking', 'Checking...'));
        renderNotice($result, 'info', strings('checking', 'Checking sync...'));

        request('intersoccer_trace_reports_rosters_item', { order_item_id: orderItemId })
            .done(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    setBadge($wrap, 'error', strings('badge_error', 'Error'));
                    renderNotice($result, 'error', strings('check_failed', 'Sync check failed.'));
                    return;
                }
                var summary = summarizeTrace(resp.data);
                setBadge($wrap, summary.inSync ? 'success' : 'warning', summary.inSync ? strings('badge_in_sync', 'In sync') : strings('badge_out_of_sync', 'Out of sync'));
                renderNotice($result, summary.type, summary.message, summary.details);
            })
            .fail(function () {
                setBadge($wrap, 'error', strings('badge_error', 'Error'));
                renderNotice($result, 'error', strings('check_failed', 'Sync check failed.'));
            })
            .always(function () {
                setBusy($wrap, false);
            });
    });

});
