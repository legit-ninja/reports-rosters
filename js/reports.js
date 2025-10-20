// reports.js
jQuery(document).ready(function($) {
    // Function to update report via AJAX
    function intersoccerUpdateReport() {
        var start_date = $("#start_date").val();
        var end_date = $("#end_date").val();
        var year = $("#year").val();
        var region = $("#region").val();
        var columns = $("input[name='columns[]']:checked").map(function() { return this.value; }).get();
        
        $.ajax({
            url: intersoccerReports.ajaxurl,
            type: "POST",
            data: {
                action: "intersoccer_filter_report",
                nonce: intersoccerReports.nonce,
                start_date: start_date,
                end_date: end_date,
                year: year,
                region: region,
                columns: columns
            },
            success: function(response) {
                if (response.success) {
                    $("#intersoccer-report-table").html(response.data.table);
                    $("#intersoccer-report-totals").html(response.data.totals);
                } else {
                    console.error("Filter error: " + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX error: " + error);
            }
        });
    }

    // Initial load
    intersoccerUpdateReport();

    // Trigger update on input changes
    $("#start_date, #end_date").datepicker({
        dateFormat: "yy-mm-dd",
        changeMonth: true,
        changeYear: true,
        onSelect: function() {
            intersoccerUpdateReport();
        }
    });
    $("#region, #year, input[name='columns[]']").on("change", function() {
        intersoccerUpdateReport();
    });
});