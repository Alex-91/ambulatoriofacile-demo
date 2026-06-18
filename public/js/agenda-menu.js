$(document).on('click', '.agenda-menu-toggle', function(e) {
    e.preventDefault();

    var $parent = $(this).closest('.agenda-menu-parent');
    var $submenu = $parent.children('.agenda-submenu').first();
    var $arrow = $(this).find('.agenda-menu-arrow').first();

    $submenu.slideToggle(150);
    $parent.toggleClass('open');

    if ($parent.hasClass('open')) {
        $arrow.removeClass('fa-angle-left').addClass('fa-angle-down');
    } else {
        $arrow.removeClass('fa-angle-down').addClass('fa-angle-left');
    }
});