jQuery(function ($) {
    if (typeof window.intersoccer_order_item_sync === 'undefined') {
        return;
    }

    // #region agent log
    fetch('http://127.0.0.1:7535/ingest/7427afce-080a-4607-92da-13d96f476bb6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'21e376'},body:JSON.stringify({sessionId:'21e376',runId:'run1',hypothesisId:'H3',location:'js/order-item-sync-controls.js:init',message:'Sync controls JS initialized',data:{controlsCount:$('.intersoccer-order-item-sync-controls').length,initialBadges:$('.intersoccer-order-item-sync-controls').map(function(){return {orderItemId:$(this).data('order-item-id'),badgeText:$(this).find('.intersoccer-sync-badge').text().trim()};}).get().slice(0,20)},timestamp:Date.now()})}).catch(()=>{});
    // #endregion

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

        // #region agent log
        fetch('http://127.0.0.1:7535/ingest/7427afce-080a-4607-92da-13d96f476bb6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'21e376'},body:JSON.stringify({sessionId:'21e376',runId:'run1',hypothesisId:'H4',location:'js/order-item-sync-controls.js:setBadge',message:'Badge updated',data:{orderItemId:$wrap.data('order-item-id')||null,state:state,label:label},timestamp:Date.now()})}).catch(()=>{});
        // #endregion
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
                // #region agent log
                fetch('http://127.0.0.1:7535/ingest/7427afce-080a-4607-92da-13d96f476bb6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'21e376'},body:JSON.stringify({sessionId:'21e376',runId:'run1',hypothesisId:'H2',location:'js/order-item-sync-controls.js:checkSyncDone',message:'Trace response summarized',data:{orderItemId:orderItemId,rosterRows:Array.isArray(resp.data.roster_rows)?resp.data.roster_rows.length:-1,inSync:summary.inSync},timestamp:Date.now()})}).catch(()=>{});
                // #endregion
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

    $(document).on('click', '.intersoccer-fix-sync', function (e) {
        e.preventDefault();
        var $wrap = $(this).closest('.intersoccer-order-item-sync-controls');
        var $result = $wrap.find('.intersoccer-order-item-sync-result');
        var orderItemId = parseInt($wrap.data('order-item-id'), 10) || 0;
        if (orderItemId <= 0) {
            setBadge($wrap, 'error', strings('badge_error', 'Error'));
            renderNotice($result, 'error', strings('fix_failed', 'Sync fix failed.'));
            return;
        }

        if (!window.confirm(strings('fix_confirm', 'Run safe sync fix for this order item?'))) {
            return;
        }

        setBusy($wrap, true);
        setBadge($wrap, 'info', strings('badge_fixing', 'Fixing...'));
        renderNotice($result, 'info', strings('fixing', 'Fixing sync...'));

        request('intersoccer_fix_reports_rosters_item_safe', { order_item_id: orderItemId })
            .done(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    var msg = resp && resp.data && resp.data.message ? resp.data.message : strings('fix_failed', 'Sync fix failed.');
                    setBadge($wrap, 'error', strings('badge_error', 'Error'));
                    renderNotice($result, 'error', msg);
                    return;
                }

                var data = resp.data;
                // #region agent log
                fetch('http://127.0.0.1:7535/ingest/7427afce-080a-4607-92da-13d96f476bb6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'21e376'},body:JSON.stringify({sessionId:'21e376',runId:'run1',hypothesisId:'H2',location:'js/order-item-sync-controls.js:fixSyncDone',message:'Fix response received',data:{orderItemId:orderItemId,status:data.status||null,reasonsAfter:data.reasons_after||[],fixResults:data.fix_results||{}},timestamp:Date.now()})}).catch(()=>{});
                // #endregion
                var status = data.status || 'no_action';
                var type = 'info';
                var message = data.message || strings('no_action', 'No changes were needed.');
                if (status === 'fixed' || status === 'fixed_partial') {
                    type = 'success';
                    message = strings('fixed', 'Fix applied.') + ' ' + message;
                    setBadge($wrap, 'success', strings('badge_fixed', 'Fixed'));
                } else if (status === 'in_sync') {
                    type = 'success';
                    message = strings('in_sync', 'In sync.');
                    setBadge($wrap, 'success', strings('badge_in_sync', 'In sync'));
                } else if (status === 'error') {
                    type = 'error';
                    setBadge($wrap, 'error', strings('badge_error', 'Error'));
                } else {
                    type = 'warning';
                    setBadge($wrap, 'warning', strings('badge_out_of_sync', 'Out of sync'));
                }

                var details = '';
                if (data.fix_results) {
                    details = '<p><strong>Rosters:</strong> +' + escHtml(data.fix_results.fixed_missing_in_rosters || 0) +
                        ' | <strong>Meta:</strong> +' + escHtml(data.fix_results.fixed_missing_in_woo_meta || 0) +
                        ' | <strong>Quarantined:</strong> ' + escHtml(data.fix_results.quarantined_missing_in_woo || 0) + '</p>';
                }
                renderNotice($result, type, message, details);
                refreshBadgeFromTrace($wrap);
            })
            .fail(function () {
                setBadge($wrap, 'error', strings('badge_error', 'Error'));
                renderNotice($result, 'error', strings('fix_failed', 'Sync fix failed.'));
            })
            .always(function () {
                setBusy($wrap, false);
            });
    });
});
