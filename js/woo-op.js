// 1. Update woo-op.js to send the correct nonce parameter name
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
    var processText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.process_orders) 
        ? window.intersoccer_orders.strings.process_orders 
        : 'Process Orders';
    var processLink = $('<a href="#" class="page-title-action" id="intersoccer-process-processing-button-orders">' + processText + '</a>');
    addNewOrder.after(processLink);

    $('#intersoccer-process-processing-button-orders').on('click', function(e) {
        e.preventDefault(); // Prevent default link behavior
        console.log('InterSoccer: Process Orders link clicked on Orders page');
        
        var confirmMsg = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.confirm_process) 
            ? window.intersoccer_orders.strings.confirm_process 
            : 'Are you sure you want to process all pending orders? This will populate rosters for processing or on-hold orders and transition them to completed.';
        if (!confirm(confirmMsg)) {
            console.log('InterSoccer: Process orders cancelled by user on Orders page');
            return false;
        }
        
        console.log('InterSoccer: Process orders confirmed on Orders page, proceeding with AJAX');
        
        // Debug the nonce value
        console.log('InterSoccer: Using nonce value:', intersoccer_orders.nonce);
        
        $.ajax({
            url: intersoccer_orders.ajaxurl, // Use the localized ajaxurl
            type: 'POST',
            data: {
                action: 'intersoccer_process_existing_orders',
                // FIXED: Use the correct nonce field name that matches PHP
                intersoccer_rebuild_nonce_field: intersoccer_orders.nonce,
                // Also send it as 'nonce' for compatibility
                nonce: intersoccer_orders.nonce
            },
            beforeSend: function() {
                // Show a temporary processing notice
                var processingText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.processing) 
                    ? window.intersoccer_orders.strings.processing 
                    : 'Processing orders... Please wait.';
                var dismissText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.dismiss) 
                    ? window.intersoccer_orders.strings.dismiss 
                    : 'Dismiss this notice.';
                $('#intersoccer-process-status').remove();
                $('<div id="intersoccer-process-status" class="notice notice-info is-dismissible"><p>' + processingText + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button></div>').insertAfter(processLink);
            },
            success: function(response) {
                // Remove processing notice
                var dismissText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.dismiss) 
                    ? window.intersoccer_orders.strings.dismiss 
                    : 'Dismiss this notice.';
                var successText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.orders_processed) 
                    ? window.intersoccer_orders.strings.orders_processed 
                    : 'Orders processed. Check debug.log for details.';
                $('#intersoccer-process-status').remove();
                
                // Add success notice
                $('<div id="intersoccer-process-status" class="notice notice-success is-dismissible"><p>' + (response.data.message || successText) + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button></div>').insertAfter(processLink);
                console.log('InterSoccer: Process response on Orders page: ', response);
                
                // Refresh the page after 2 seconds
                setTimeout(function() {
                    location.reload();
                }, 2000);
            },
            error: function(xhr, status, error) {
                // Remove processing notice and show error
                $('#intersoccer-process-status').remove();
                
                // Log the full error response for debugging
                console.error('InterSoccer: Full AJAX error response:', xhr.responseText);
                console.error('InterSoccer: AJAX Error details:', {
                    status: status,
                    error: error,
                    responseJSON: xhr.responseJSON
                });
                
                var errorPrefix = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.processing_failed) 
                    ? window.intersoccer_orders.strings.processing_failed 
                    : 'Processing failed: ';
                var dismissText = (window.intersoccer_orders && window.intersoccer_orders.strings && window.intersoccer_orders.strings.dismiss) 
                    ? window.intersoccer_orders.strings.dismiss 
                    : 'Dismiss this notice.';
                $('<div id="intersoccer-process-status" class="notice notice-error is-dismissible"><p>' + errorPrefix + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button></div>').insertAfter(processLink);
            }
        });
    });
});