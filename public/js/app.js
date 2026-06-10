$(document).ready(function() {
    let currentLang = localStorage.getItem('preferred_language') || 'en';
    loadLanguage(currentLang);
    $('.nav-lang-btn').on('click', function(e) {
        e.stopPropagation();
        $('#languageMenu').toggleClass('active');
    });
    $('.dropdown-lang-item').on('click', function(e) {
        e.preventDefault();
        const selectedValue = $(this).data('value');
        loadLanguage(selectedValue);
        $('#languageMenu').removeClass('active');
    });
    $(document).on('click', function() {
        $('#languageMenu').removeClass('active');
    });
    function loadLanguage(lang) {
        const langPath = `${BASE_URL}/public/lang/${lang}.json?v=${Date.now()}`; 
        $.getJSON(langPath, function(translations) {
            const activeMenuItem = $(`.dropdown-lang-item[data-value="${lang}"]`);
            if (activeMenuItem.length) {
                const textBtn = activeMenuItem.data('lang');
                const flagBtn = activeMenuItem.data('flag');
                $('.text-current-lang').text(textBtn);
                $('.current-flag').attr('src', flagBtn);
            }
            $('[data-i18n]').each(function() {
                const key = $(this).data('i18n');
                if (translations[key]) {
                    $(this).text(translations[key]);
                }
            });
            localStorage.setItem('preferred_language', lang);
            console.log(`[i18n] โหลดภาษาสำเร็จ: ${lang.toUpperCase()}`);
        }).fail(function() {
            console.error(`ไม่สามารถโหลดไฟล์ภาษาจากพาธ: ${langPath} ได้`);
        });
    }
    $('.nav-btn-hamberger').on('click', function(e) {
        e.stopPropagation();
        $('#origamiSidebar').toggleClass('active');
        $('#sidebarOverlay').toggleClass('active');
    });
    $('#sidebarOverlay').on('click', function() {
        $('#origamiSidebar').removeClass('active');
        $('#sidebarOverlay').removeClass('active');
    });
    $('.submenu-toggle').on('click', function(e) {
        e.preventDefault();
        const $parent = $(this).parent('.menu-item');
        const $submenu = $(this).next('.submenu');
        $submenu.slideToggle(250);
        $parent.toggleClass('open');
        $parent.siblings('.has-submenu').removeClass('open').find('.submenu').slideUp(200);
    });
});