/**
 * KiwiEvents — General Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Highlight current submenu
        if (typeof pagenow !== 'undefined' && pagenow === 'ke_event') {
            $('#toplevel_page_kiwi-events').addClass('wp-has-current-submenu wp-menu-open');
            $('#toplevel_page_kiwi-events > a').addClass('wp-has-current-submenu');
        }
    });

})(jQuery);
