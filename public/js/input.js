function requiredMark(isRequired) {
    return isRequired ? '<span class="text-danger">*</span>' : '';
}
$(document).on('input change', '.required', function () {
    let value = $(this).val();

    // 1. จัดการ Checkbox และ Radio
    if ($(this).is(':checkbox') || $(this).is(':radio')) {
        if ($(this).is(':checked')) {
            $(this).removeClass('is-invalid');
        } else {
            $(this).addClass('is-invalid');
        }
        return;
    }

    // 2. จัดการข้อมูลประเภทอื่นๆ (ป้องกัน Error จาก Array หรือค่าน้อยกว่า String)
    let isValid = false;

    if (Array.isArray(value)) {
        // กรณีเป็น Select Multiple ให้เช็กว่ามีเลือกไว้ไหม
        isValid = value.length > 0;
    } else {
        // แปลงเป็น String เสมอก่อนแล้วค่อย trim() ป้องกันปัญหา value เป็นประเภทอื่น
        let strValue = value ? String(value).trim() : '';
        isValid = strValue.length > 0;
    }

    // 3. ปรับ Class ตามผลการตรวจสอบ
    if (isValid) {
        $(this).removeClass('is-invalid');
    } else {
        $(this).addClass('is-invalid');
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
    const $trigger = $suggestionsBox.siblings('.autocomplete-address');
    const groupId = $trigger.data('addressGroup');
    if (groupId) {
        const fieldMap = {
            subdistrict: itemData.sub_district || '',
            district: itemData.city || '',
            province: itemData.state || '',
            postcode: itemData.postcode || ''
        };
        $(`[data-address-group="${groupId}"]`).each(function () {
            const field = $(this).data('addressField');
            if (field && fieldMap[field] !== undefined) {
                $(this).val(fieldMap[field]).trigger('change');
            }
        });
    } else {
        const $container = $suggestionsBox.parent();
        $container.find('.autocomplete-address').val(itemData.formatted_text);
        $container.find('.master-address-id-field').val(itemData.id);
    }
    $suggestionsBox.empty().addClass('d-none');
});
$(document).on('click', function(e) {
    if (!$(e.target).closest('.autocomplete-address, .address-suggestions-box').length) {
        $('.address-suggestions-box').addClass('d-none');
    }
});
function initDatepicker(selector = '.datepicker', options = {}) {
    // 1. ตรวจสอบว่ามี jQuery และ Datepicker Plugin พร้อมใช้งานหรือไม่
    if (typeof $ === 'undefined' || !$.fn || !$.fn.datepicker) return;

    // 2. ดึงค่าภาษา ป้องกันกรณี variable currentLang ไม่ถูกกำหนดไว้
    const lang = (typeof currentLang !== 'undefined' && currentLang === 'th') ? 'th' : 'en';

    // 3. กำหนดค่าเริ่มต้น และรวมเข้ากับ options ที่ส่งเข้ามา
    const defaultOptions = {
        format: 'dd/mm/yyyy',
        autoclose: true,
        todayHighlight: true,
        language: lang,
        orientation: 'auto bottom' // ระบุทิศทางให้ชัดเจนเพื่อป้องกัน UI แสดงผลล้นจอ
    };

    $(selector).datepicker($.extend(true, {}, defaultOptions, options));
}
function initSelect2(selector, options = {}) {
    $(selector).each(function () {
        const $this = $(this);
        const $modal = $this.closest('.modal');
        const originalTabIndex = $this.attr('tabindex') || '0';
        const isStatic = options.mode === 'static' || (!options.mode && $this.hasClass('select2-static'));
        const isNative = !isStatic && (options.mode === 'native' || (!options.mode && $this.hasClass('select2-native')));
        let config;
        if (isStatic) {
            const keys = options.keys || (($this.data('optionKeys') || '') + '').split(',').filter(Boolean);
            const explicitValues = options.values || (($this.data('optionValues') || '') + '').split(',').filter(Boolean);
            const data = keys.map((key, idx) => ({ id: explicitValues[idx] !== undefined ? explicitValues[idx] : key, text: getLangValue(key) || key }));
            config = {
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: !!options.allowClear,
                data: data,
                placeholder: {
                    id: '',
                    text: langData['select_option'] || 'Select an option'
                },
                language: {
                    noResults: () => langData['no_results'] || 'No results found'
                },
                minimumResultsForSearch: options.searchable ? 0 : Infinity
            };
        } else if (isNative) {
            // Keeps the native look (no Select2 dropdown chrome beyond the required init) while
            // reading whichever <option> elements already exist in the DOM -- no ajax, no fixed
            // data-option-keys list. Callers repopulate options themselves (e.g. a cascading
            // dropdown fetched once via a plain $.ajax call) then call .trigger('change').
            config = {
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: !!options.allowClear,
                placeholder: {
                    id: '',
                    text: langData['select_option'] || 'Select an option'
                },
                language: {
                    noResults: () => langData['no_results'] || 'No results found'
                },
                minimumResultsForSearch: options.searchable === false ? Infinity : 0
            };
        } else {
            const apiUrl = ($this.data('api') || options.api) ? `${BASE_URL}${$this.data('api') || options.api}` : null;
            if (!apiUrl) return;
            const extraData = {
                type: $this.data('type') || options.apiType || ''
            };
            if ($this.data('excludeId') !== undefined && $this.data('excludeId') !== '') {
                extraData.exclude_id = $this.data('excludeId');
            }
            config = {
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                ajax: {
                    url: apiUrl,
                    type: 'POST',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return $.extend({
                            searchTerm: params.term,
                            page: params.page || 1,
                            limit: 10
                        }, extraData);
                    },
                    processResults: function (res, params) {
                        params.page = params.page || 1;
                        const data = res.data || res.status || {};
                        const items = (data.items || []).map(item => {
                            const localizedText = (currentLang === 'th') ? item.text_th : item.text_en;
                            return {
                                ...item,
                                id: item.id,
                                text: localizedText || item.text_th || item.text_en || item.text
                            };
                        });
                        const total = parseInt(data.total_count || 0);
                        return {
                            results: items,
                            pagination: {
                                more: (params.page * 10) < total
                            }
                        };
                    },
                    cache: true
                },
                language: {
                    searching: () => langData['searching'] || "Searching...",
                    noResults: () => langData['no_results'] || "No results found",
                    inputTooShort: () => langData['input_too_short'] || "Please enter more characters"
                },
                placeholder: {
                    id: '',
                    text: langData['select_option'] || 'Select an option'
                },
                minimumInputLength: 0
            };
        }
        if ($modal.length) {
            config.dropdownParent = $modal;
        }
        if ($this.hasClass("select2-hidden-accessible")) {
            $this.select2('destroy');
        }
        $this.select2(config);
        const $container = $this.next('.select2-container');
        if ($container.length) {
            $container.find('.select2-selection').attr('tabindex', originalTabIndex);
        }
        if (isStatic && options.selectedValue !== undefined && options.selectedValue !== '' && options.selectedValue !== null) {
            $this.val(options.selectedValue).trigger('change.select2');
        }
    });
}
function initSelect2Remote(selector) {
    initSelect2(selector, { mode: 'ajax' });
}
function initDateRangePicker(selector, callback) {
    $(selector).daterangepicker({
        opens: 'left',
        autoUpdateInput: false,
        alwaysShowCalendars: true, 
        ranges: {
            [langData['today'] || 'Today']: [moment(), moment()],
            [langData['yesterday'] || 'Yesterday']: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            [langData['last_7_days'] || 'Last 7 Days']: [moment().subtract(6, 'days'), moment()],
            [langData['last_30_days'] || 'Last 30 Days']: [moment().subtract(29, 'days'), moment()],
            [langData['this_week'] || 'This Week']: [moment().startOf('week'), moment().endOf('week')],
            [langData['last_week'] || 'Last Week']: [moment().subtract(1, 'week').startOf('week'), moment().subtract(1, 'week').endOf('week')],
            [langData['this_month'] || 'This Month']: [moment().startOf('month'), moment().endOf('month')],
            [langData['last_month'] || 'Last Month']: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            [langData['this_year'] || 'This Year']: [moment().startOf('year'), moment().endOf('year')],
            [langData['last_year'] || 'Last Year']: [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
        },
        locale: {
            format: date_format,
            applyLabel: langData['apply'] || 'Apply',
            cancelLabel: langData['clear'] || 'Clear',
            customRangeLabel: langData['custom_range'] || 'Custom Range'
        }
    });
    $(selector).on('apply.daterangepicker', function(ev, picker) {
        let selectedDate = picker.startDate.format(date_format) + ' - ' + picker.endDate.format(date_format);
        $(this).val(selectedDate);
        if (typeof callback === 'function') {
            callback(selectedDate); 
        }
    });
    $(selector).on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
        if (typeof callback === 'function') {
            callback('');
        }
    });
}
function initMonthYearPicker(selector, callback) {
    $(selector).datepicker('destroy');
    $(selector).datepicker({
        format: "mm/yyyy",
        startView: "months",
        minViewMode: "months",
        autoclose: true,
        clearBtn: true,
        container: 'body' 
    }).on('changeDate', function () {
        if (typeof callback === 'function') {
            callback();
        }
    }).on('clearDate', function () {
        if (typeof callback === 'function') {
            callback();
        }
    });
}
$(document).on('select2:select', '.select2-remote', function (e) {
    const data = e.params.data;
    $(this).find('option:selected').data('data', data);
});