/**
 * Roster details admin: type-to-search destination roster + sync hidden #targetRosterSelect.
 */
(function ($) {
    'use strict';

    function parseTargets() {
        var $el = $('#intersoccer-roster-move-targets');
        if (!$el.length) {
            return [];
        }
        try {
            return JSON.parse($el.text());
        } catch (e) {
            return [];
        }
    }

    function eligibleTargets(all, allowCross) {
        return all.filter(function (t) {
            return allowCross || !t.cross_gender;
        });
    }

    function init() {
        var $move = $('#moveOptions');
        if (!$move.length) {
            return;
        }

        var allTargets = parseTargets();
        var $search = $('#targetRosterSearch');
        var $select = $('#targetRosterSelect');
        var $allow = $('#allowCrossGender');

        if (!$search.length || !$select.length || typeof $.fn.autocomplete !== 'function') {
            return;
        }

        function filterPool() {
            return eligibleTargets(allTargets, $allow.is(':checked'));
        }

        function syncSearchFromSelect() {
            var vid = String($select.val() || '');
            if (!vid) {
                $search.val('');
                return;
            }
            var pool = filterPool();
            var found = null;
            for (var i = 0; i < pool.length; i++) {
                if (String(pool[i].variation_id) === vid) {
                    found = pool[i];
                    break;
                }
            }
            if (found) {
                $search.val(found.label);
            } else {
                $search.val('');
            }
        }

        function clearIfInvalid() {
            var vid = String($select.val() || '');
            if (!vid) {
                return;
            }
            var pool = filterPool();
            var ok = false;
            for (var j = 0; j < pool.length; j++) {
                if (String(pool[j].variation_id) === vid) {
                    ok = true;
                    break;
                }
            }
            if (!ok) {
                $select.val('').trigger('change');
                $search.val('');
            }
        }

        var acOptions = {
            minLength: 1,
            source: function (request, response) {
                var raw = (request.term || '').trim();
                var pool = filterPool();
                var term = raw;
                if ($.ui.autocomplete && $.ui.autocomplete.escapeRegex) {
                    term = $.ui.autocomplete.escapeRegex(raw);
                }
                var matcher = term ? new RegExp(term, 'i') : null;
                var out = [];
                for (var k = 0; k < pool.length; k++) {
                    var t = pool[k];
                    if (!matcher || matcher.test(t.label)) {
                        out.push({ label: t.label, value: String(t.variation_id) });
                    }
                }
                response(out);
            },
            select: function (event, ui) {
                $select.val(ui.item.value);
                $search.val(ui.item.label);
                $select.trigger('change');
                return false;
            },
            focus: function () {
                return false;
            }
        };
        $search.autocomplete(acOptions);

        $allow.on('change.intersoccerRosterMove', clearIfInvalid);

        $('#bulkActionSelect').on('change.intersoccerRosterMove', function () {
            if ($(this).val() !== 'move') {
                $search.val('');
            }
        });

        $select.on('change.intersoccerRosterMoveDisplay', function () {
            syncSearchFromSelect();
        });

        syncSearchFromSelect();
    }

    $(init);
})(jQuery);
