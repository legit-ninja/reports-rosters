jQuery(document).ready(function($) {
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).attr('href').split('tab=')[1];

        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).parent().addClass('nav-tab-active');

        // Show/hide tab content
        $('.tab-content').hide();
        $('#' + targetTab + '-tab').show();

        // Update URL without reloading
        var url = new URL(window.location);
        url.searchParams.set('tab', targetTab);
        window.history.pushState({}, '', url);
    });
});
