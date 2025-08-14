jQuery(document).ready(function($) {
    console.log('InterSoccer: woo-op.js loaded on Orders page');

    // Target the "Add new order" link
    var addNewOrder = $('.page-title-action');
    if (!addNewOrder.length) {
        console.error('InterSoccer: No .page-title-action (Add new order) found for button placement');
        return;
    }
    console.log('InterSoccer: Target for button placement: .page-title-action');

    // Inject the replicated "Process Orders" link after the "Add new order" link
    var processLink = $('<a href="#" class="page-title-action" id="intersoccer-process-processing-button-orders">Process Orders</a>');
    addNewOrder.after(processLink);

    $('#intersoccer-process-processing-button-orders').on('click', function(e) {
        e.preventDefault(); // Prevent default link behavior
        console.log('InterSoccer: Process Orders link clicked on Orders page');
        
        if (!confirm('Are you sure you want to process all pending orders? This will populate rosters for processing or on-hold orders and transition them to completed.')) {
            console.log('InterSoccer: Process orders cancelled by user on Orders page');
            return false;
        }
        
        console.log('InterSoccer: Process orders confirmed on Orders page, proceeding with AJAX');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'intersoccer_process_existing_orders',
                intersoccer_rebuild_nonce_field: intersoccer_orders.nonce
            },
            beforeSend: function() {
                // Show a temporary processing notice
                $('#intersoccer-process-status').remove();
                $('<div id="intersoccer-process-status" class="notice notice-info is-dismissible"><p>Processing orders... Please wait.</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>').insertAfter(processLink);
            },
            success: function(response) {
                // Remove processing notice
                $('#intersoccer-process-status').remove();
                
                // Add success notice
                $('<div id="intersoccer-process-status" class="notice notice-success is-dismissible"><p>' + (response.data.message || 'Orders processed. Check debug.log for details.') + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>').insertAfter(processLink);
                console.log('InterSoccer: Process response on Orders page: ', response);
                
                // Refresh the page after 2 seconds
                setTimeout(function() {
                    location.reload();
                }, 2000);
            },
            error: function(xhr, status, error) {
                // Remove processing notice and show error
                $('#intersoccer-process-status').remove();
                $('<div id="intersoccer-process-status" class="notice notice-error is-dismissible"><p>Processing failed: ' + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>').insertAfter(processLink);
                console.error('InterSoccer: AJAX Error on Orders page: ', status, error, xhr.responseText);
            }
        });
    });
});