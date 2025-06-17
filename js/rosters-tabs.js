jQuery(document).ready(function($) {
    console.log('rosters-tabs.js loaded'); // Debug log to confirm script load
    $('.intersoccer-view-roster').on('click', function(e) {
        console.log('Button clicked for variation ID:', $(this).data('variation-id')); // Debug log
        e.preventDefault();
        var variationId = $(this).data('variation-id');
        var detailsDiv = $('#roster-details-' + variationId);

        if (detailsDiv.length) {
            if (detailsDiv.is(':empty')) {
                detailsDiv.html('<p>Loading...</p>').slideDown(); // Show loading state
                $.ajax({
                    url: intersoccer_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_get_roster_details',
                        nonce: intersoccer_ajax.nonce,
                        variation_id: variationId
                    },
                    success: function(response) {
                        console.log('AJAX success:', response); // Debug log
                        if (response.success) {
                            detailsDiv.html(response.data.details).slideDown();
                        } else {
                            detailsDiv.html('<p>Error: ' + (response.data.message || 'Unknown error') + '</p>').slideDown();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX error:', status, error); // Debug log
                        detailsDiv.html('<p>Error loading roster details: ' + error + '</p>').slideDown();
                    }
                });
            } else {
                detailsDiv.slideToggle();
            }
        } else {
            console.error('Details div not found for variation ID: ' + variationId);
        }
    });
});
