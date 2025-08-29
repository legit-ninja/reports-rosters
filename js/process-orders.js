jQuery(document).ready(function($) {
    console.log('InterSoccer: Process Orders script loaded');

    // Handler for per-order button on WooCommerce > Orders page
    $(document).on('click', '.wc-action-button-process-orders', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var nonce = $button.data('nonce');
        console.log('InterSoccer: Per-order button clicked, sending AJAX for order ' + orderId);
        console.log('InterSoccer: Using nonce: ' + nonce);

        $button.prop('disabled', true).text('Processing...');

        $.ajax({
            url: intersoccer_process_orders.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_process_orders',
                nonce: nonce,
                order_id: orderId
            },
            success: function(response) {
                if (response.success) {
                    $button.text('Processed').addClass('processed');
                    console.log('InterSoccer: Success for order ' + orderId + ': ' + response.data.message);
                    alert(response.data.message);
                    location.reload();
                } else {
                    $button.text('Process Order').prop('disabled', false);
                    console.log('InterSoccer: Error for order ' + orderId + ': ' + response.data.message);
                    alert('Error: ' + response.data.message);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $button.text('Process Order').prop('disabled', false);
                console.log('InterSoccer: AJAX error for order ' + orderId + ': ' + textStatus + ' - ' + errorThrown);
                alert('Error: Failed to process order.');
            }
        });
    });
});