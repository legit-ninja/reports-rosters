/**
 * Event Completion Handler
 * 
 * Handles the "Mark Event as Completed" button functionality with
 * confirmation dialog and AJAX request.
 * 
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.0
 */

jQuery(document).ready(function($) {
    /**
     * Handle Event Completed button click
     */
    $(document).on('click', '.mark-event-completed', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var eventSignature = $button.data('event-signature');
        var eventName = $button.data('event-name');
        
        // Validate data
        if (!eventSignature) {
            alert('Error: Missing event signature');
            return;
        }
        
        // Confirmation dialog
        var confirmMessage = 'Are you sure you want to mark "' + eventName + '" as completed?\n\n' +
                           'This will mark ALL roster entries for this event as completed and hide them from the active events list.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Disable button and show processing state
        var originalText = $button.text();
        $button.prop('disabled', true).text('Processing...');
        
        // AJAX request
        $.ajax({
            url: intersoccer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_mark_event_completed',
                nonce: intersoccer_ajax.nonce,
                event_signature: eventSignature
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    alert(response.data.message);
                    
                    // Reload page to reflect changes
                    window.location.reload();
                } else {
                    // Show error message
                    alert('Error: ' + (response.data.message || 'Unknown error occurred'));
                    
                    // Re-enable button
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                // Show error message
                alert('An error occurred while marking the event as completed. Please try again.');
                console.error('AJAX Error:', status, error);
                
                // Re-enable button
                $button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    /**
     * Handle Select All checkbox on roster details page
     */
    $('#selectAll').on('change', function() {
        $('.player-select').prop('checked', this.checked);
    });
});



