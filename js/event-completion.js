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
            var errorMsg = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.error_missing_signature) 
                ? window.intersoccer_ajax.strings.error_missing_signature 
                : 'Error: Missing event signature';
            alert(errorMsg);
            return;
        }
        
        // Confirmation dialog
        var confirmPrefix = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.confirm_complete_prefix) 
            ? window.intersoccer_ajax.strings.confirm_complete_prefix 
            : 'Are you sure you want to mark "';
        var confirmSuffix = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.confirm_complete_suffix) 
            ? window.intersoccer_ajax.strings.confirm_complete_suffix 
            : '" as completed?\n\nThis will mark ALL roster entries for this event as completed and hide them from the active events list.';
        var confirmMessage = confirmPrefix + eventName + confirmSuffix;
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Disable button and show processing state
        var originalText = $button.text();
        var processingText = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.processing) 
            ? window.intersoccer_ajax.strings.processing 
            : 'Processing...';
        $button.prop('disabled', true).text(processingText);
        
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
                    var errorPrefix = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.error_prefix) 
                        ? window.intersoccer_ajax.strings.error_prefix 
                        : 'Error: ';
                    var unknownError = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.unknown_error) 
                        ? window.intersoccer_ajax.strings.unknown_error 
                        : 'Unknown error occurred';
                    alert(errorPrefix + (response.data.message || unknownError));
                    
                    // Re-enable button
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                // Show error message
                var errorMsg = (window.intersoccer_ajax && window.intersoccer_ajax.strings && window.intersoccer_ajax.strings.complete_error) 
                    ? window.intersoccer_ajax.strings.complete_error 
                    : 'An error occurred while marking the event as completed. Please try again.';
                alert(errorMsg);
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



