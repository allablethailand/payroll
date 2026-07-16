$(document).on('input change', '.required', function () {
    let value = $(this).val();
    if ($(this).is(':checkbox') || $(this).is(':radio')) {
        if ($(this).is(':checked')) {
            $(this).removeClass('is-invalid');
        } else {
            $(this).addClass('is-invalid');
        }
        return;
    }
    value = value ? value.trim() : '';
    if (value) {
        $(this).removeClass('is-invalid');
    }
});
function debounce(func, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => func.apply(this, args), delay);
    };
}
const GlobalAddressSearch = {
    fetchSuggestions: function(keyword, $input) {
        const $suggestionsBox = $input.siblings('.address-suggestions-box');
        const country = $('#registered_country').val() || (typeof registered_country !== 'undefined' ? registered_country : null) || 'TH';
        const lang = currentLang || 'en'; 
        if (keyword.length < 2) {
            $suggestionsBox.html('').addClass('d-none');
            return;
        }
        fetch(`${BASE_URL}/api/address/search?q=${encodeURIComponent(keyword)}&country=${country}&lang=${lang}`)
            .then(response => response.json())
            .then(data => {
                $suggestionsBox.empty();
                if (data && data.length > 0) {
                    data.forEach(item => {
                        const $btn = $('<button></button>', {
                            type: 'button',
                            class: 'list-group-item list-group-item-action select-address-item',
                            text: item.formatted_text
                        }).data('address-data', item);
                        $suggestionsBox.append($btn);
                    });
                    $suggestionsBox.removeClass('d-none');
                } else {
                    $suggestionsBox.addClass('d-none');
                }
            })
            .catch(error => console.error('Error fetching addresses:', error));
    }
};
$(document).on('input', '.autocomplete-address', debounce(function(e) {
    const $input = $(this);
    const keyword = $input.val().trim();
    GlobalAddressSearch.fetchSuggestions(keyword, $input);
}, 300));
$(document).on('click', '.select-address-item', function() {
    const $btn = $(this);
    const itemData = $btn.data('address-data');
    const $suggestionsBox = $btn.closest('.address-suggestions-box');
    const $container = $suggestionsBox.parent();
    $container.find('.autocomplete-address').val(itemData.formatted_text);
    $container.find('.master-address-id-field').val(itemData.id);
    $suggestionsBox.empty().addClass('d-none');
});
$(document).on('click', function(e) {
    if (!$(e.target).closest('.autocomplete-address, .address-suggestions-box').length) {
        $('.address-suggestions-box').addClass('d-none');
    }
});