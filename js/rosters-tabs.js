jQuery(document).ready(function($) {
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).attr('href').substring(1); // Extract #tab-camp, etc.

        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Show/hide tab content
        $('.tab-content').hide();
        $('#' + targetTab).show();

        // Update URL without reloading
        var url = new URL(window.location);
        url.searchParams.set('tab', targetTab.replace('tab-', ''));
        window.history.pushState({}, '', url);
    });

    // Set initial tab based on URL parameter
    var urlParams = new URLSearchParams(window.location.search);
    var initialTab = urlParams.get('tab') || 'camp';
    $('.nav-tab[href="#tab-' + initialTab + '"]').trigger('click');
});