jQuery(document).ready(function($) {
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
});
