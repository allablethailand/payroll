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
// 2026-09-03, Platform Hardening Phase 2 -- app-wide "clear this date" affordance (a datepicker
// field, unlike a plain text input, has no obvious way to empty it once a date is picked -- you'd
// have to click into the text and manually delete the characters). bootstrap-datepicker has this
// built in (`clearBtn`, a "Clear" row at the bottom of the calendar popup) -- turned on below, in
// the ONE shared init function every `.datepicker` field in the app already goes through, so every
// field gets it with zero per-page work, same "one shared component" precedent as
// setButtonLoading()/renderStatusToggleHtml() (app.js).
//
// Real bug found and fixed while wiring this up: bootstrap-datepicker's own clearDates() (the
// function the Clear row calls) empties the input and fires ONLY its own custom 'changeDate' event
// -- unlike a normal calendar-click pick, which fires BOTH 'changeDate' AND a native 'change' (see
// _setDate() in the vendored source, node_modules/bootstrap-datepicker/js/bootstrap-datepicker.js).
// This app has a large number of pre-existing date filter fields wired via plain
// $(document).on('change', '#xxxDateFrom', ...) (Login History, Manual Entry's Attendance/Leave/
// OT/Import History filters, Report History, Export History, Email Queue Log, and more) -- every
// one of those would have silently stopped reacting to a Clear click: the field visibly empties,
// but the filter/table listening for a plain 'change' never re-fires. Forwarding
// 'changeDate' -> native 'change' ONLY when the field is now blank (the Clear case) closes this gap
// -- doing it unconditionally would double-fire 'change' on every ordinary pick, since _setDate()
// already fires it once on its own for that case. A delegated listener (not per-field) works
// regardless of init timing/order, so it's placed here rather than inside initDatepicker() itself.
$(document).on('changeDate', '.datepicker', function () {
    if ($(this).val() === '') {
        $(this).trigger('change');
    }
});
function initDatepicker(selector = '.datepicker', options = {}) {
    // 1. ตรวจสอบว่ามี jQuery และ Datepicker Plugin พร้อมใช้งานหรือไม่
    if (typeof $ === 'undefined' || !$.fn || !$.fn.datepicker) return;

    // 2. ดึงค่าภาษา ป้องกันกรณี variable currentLang ไม่ถูกกำหนดไว้
    const lang = (typeof currentLang !== 'undefined' && currentLang === 'th') ? 'th' : 'en';

    // Thai locale patch: the bundled bootstrap-datepicker.th.js (loaded in footer.php) translates
    // day/month names but never added a `clear` key, so a Thai-mode calendar would show the
    // English word "Clear" among otherwise-Thai text. Patched here (idempotent, cheap to re-check
    // every call) instead of editing the vendored node_modules file directly (would be silently
    // wiped out by the next `npm install`).
    if ($.fn.datepicker.dates && $.fn.datepicker.dates['th'] && !$.fn.datepicker.dates['th'].clear) {
        $.fn.datepicker.dates['th'].clear = 'ล้างค่า';
    }

    // 3. กำหนดค่าเริ่มต้น และรวมเข้ากับ options ที่ส่งเข้ามา
    const defaultOptions = {
        format: 'dd/mm/yyyy',
        autoclose: true,
        todayHighlight: true,
        clearBtn: true,
        language: lang,
        orientation: 'auto bottom' // ระบุทิศทางให้ชัดเจนเพื่อป้องกัน UI แสดงผลล้นจอ
    };
    const mergedOptions = $.extend(true, {}, defaultOptions, options);

    // 2026-09-03, Platform Hardening Phase 2 (placeholder standardization audit) -- real gap found:
    // 73 of the app's 74 `.datepicker` fields had NO placeholder at all (an audit grep across
    // app/views/), so an empty date field gave no hint at all about what format to type ("dd/mm/
    // yyyy" is enforced by `format` above but never shown until you already have a date picked).
    // Format strings like "dd/mm/yyyy" are a syntax pattern, not translatable vocabulary, so this
    // is left as a literal string regardless of language -- same reasoning most international
    // sites use for a date-format placeholder. Never overwrites a placeholder a field already has
    // (the one pre-existing exception, `#run_period_end`'s own "Period End", stays untouched).
    $(selector).each(function () {
        if (!$(this).attr('placeholder')) {
            $(this).attr('placeholder', mergedOptions.format);
        }
    });

    $(selector).datepicker(mergedOptions);
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
            config = {
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                ajax: {
                    url: apiUrl,
                    type: 'POST',
                    dataType: 'json',
                    delay: 250,
                    // 2026-08-21, real bug found/fixed: data-type/data-exclude-id read fresh from the
                    // live DOM attribute on EVERY search, not snapshotted into a closure var at init
                    // time (the old `extraData` object built once outside this callback, before
                    // e.g. #eed_ped_type_id's data-type is ever set by resetEedForm()) -- and via
                    // .attr() specifically, not $this.data(), since jQuery's .data() lazily caches a
                    // data-* attribute's value on first read and does NOT pick up later plain
                    // .attr('data-type', ...) changes (a caller would have to also call
                    // .data('type', ...) to keep jQuery's cache in sync -- setup/payroll-
                    // configuration.js:153 already works around this exact gotcha by setting both;
                    // this fixes it at the root instead so no caller has to remember that). Together
                    // these two bugs meant a select2-remote field whose filter type changes after
                    // first use (the EED catalog dropdown switching between "Add Earning"/"Add
                    // Deduction", #report_to_id's data-exclude-id set once the employee's own id is
                    // known) silently kept using whatever value was live at page-load, forever.
                    data: function (params) {
                        const extraData = {
                            type: $this.attr('data-type') || options.apiType || ''
                        };
                        const excludeId = $this.attr('data-exclude-id');
                        if (excludeId !== undefined && excludeId !== '') {
                            extraData.exclude_id = excludeId;
                        }
                        // 2026-09-01: same "read fresh from the live DOM attribute on every search"
                        // pattern as data-exclude-id above -- generic comma-separated state filter,
                        // first consumer is the Payroll Run "merge target" picker
                        // (api/payroll-run.options already reads a `states` POST param).
                        const states = $this.attr('data-states');
                        if (states !== undefined && states !== '') {
                            extraData.states = states;
                        }
                        // 2026-09-02, same "read fresh from the live DOM attribute on every search"
                        // pattern as data-exclude-id/data-states above -- generic cycle-id filter,
                        // first consumer is the Employee Salary tab's own cycle-scoped
                        // default_bank_account_id picker (api/employee.payment-account-options
                        // reads a `cycle_id` POST param).
                        const cycleId = $this.attr('data-cycle-id');
                        if (cycleId !== undefined && cycleId !== '') {
                            extraData.cycle_id = cycleId;
                        }
                        // 2026-09-02, same pattern -- first consumer is a mixed-payment line's own
                        // method picker (api/payment-method.options reads an `exclude_code` POST
                        // param), a line can never itself resolve to 'mixed'.
                        const excludeCode = $this.attr('data-exclude-code');
                        if (excludeCode !== undefined && excludeCode !== '') {
                            extraData.exclude_code = excludeCode;
                        }
                        // 2026-09-03, same "read fresh from the live DOM attribute on every search"
                        // pattern as data-exclude-id/data-states/data-cycle-id/data-exclude-code above
                        // -- first consumer is Manual Entry's Overtime "OT Rate" picker
                        // (api/ot-rate.options reads an `employee_id` POST param to scope results to
                        // that employee's own resolved OT Rate Set, see MasterModel::master()'s own
                        // 'ot_rate' case).
                        const employeeId = $this.attr('data-employee-id');
                        if (employeeId !== undefined && employeeId !== '') {
                            extraData.employee_id = employeeId;
                        }
                        // 2026-09-08, same "read fresh from the live DOM attribute on every search"
                        // pattern as the others above -- first consumer is the Payment Voucher report's
                        // own employee picker (#reportsPreviewEmployeeSelect), which reuses this same
                        // general-purpose api/employee.report_to.get endpoint but must never offer an
                        // employee flagged as not receiving salary (employees.is_payroll_participant=0)
                        // -- see EmployeeModel::reportToOptions()'s own docblock. Other reuse sites
                        // (Report-To manager picker, template "Assign To" pickers) leave this unset, so
                        // they keep seeing every employee exactly as before.
                        const payrollParticipantsOnly = $this.attr('data-payroll-participants-only');
                        if (payrollParticipantsOnly !== undefined && payrollParticipantsOnly !== '') {
                            extraData.payroll_participants_only = payrollParticipantsOnly;
                        }
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
            // 2026-09-10, Batch 3A item 7b: ajax + tags combo (Select2's own supported pattern, not
            // a new mechanism) -- lets a field search/pick an existing comp_id-scoped lookup row
            // (via the SAME api endpoint/shape every other select2-remote already uses) OR type a
            // brand-new name that doesn't exist yet. A typed tag submits with id===text===the typed
            // string (Select2's own default createTag behavior, made explicit here only to block a
            // whitespace-only tag) -- the SERVER side (CompanyLookupListModel::resolveOrCreate())
            // is what actually tells a real existing numeric id apart from new free text and
            // auto-creates the row, not this field. First consumers: #sso_hospital_id/#pvd_plan_id
            // (.select2-remote-tags class, see employee/detail.php).
            if (options.tags) {
                config.tags = true;
                config.createTag = function (params) {
                    const term = $.trim(params.term);
                    return term === '' ? null : { id: term, text: term, newTag: true };
                };
            }
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

// ============================================================================
// Dev Standards Phase 0 (T001/T002) -- see docs/ui-standards.md for the written standard.
// Both are zero-config: apply automatically to every matching element app-wide, current and
// future, with no per-page opt-in call needed (input.js loads on every page via header.php).
// ============================================================================

// T001 -- every <input type="number"> selects its whole value on focus, so typing immediately
// replaces it instead of requiring a manual select-all/backspace first. Delegated to `document` so
// it covers elements that don't exist yet at page-load time (SweetAlert2 modals, DataTables filter
// popovers, anything injected later) -- no per-field/per-page wiring required. Plain text inputs are
// deliberately NOT included (a text field's cursor position usually matters; a number field's
// almost never does -- you're replacing the whole number, not editing a substring of it).
$(document).on('focus', 'input[type="number"]', function () {
    this.select();
});

// T002 -- every <textarea> grows to fit its content (no scrollbar, no clipped text), both on initial
// render (a long pre-filled value must already be fully expanded the moment it appears) and while
// typing.
function autoExpandTextarea(el) {
    if (!el || el.tagName !== 'TEXTAREA') {
        return;
    }
    // Reset to 'auto' first, THEN read scrollHeight -- reading scrollHeight without resetting height
    // first would just report the CURRENT (possibly too-small) height back, since a taller content
    // can't shrink an already-fixed height down again on its own.
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
// Typing.
$(document).on('input', 'textarea', function () {
    autoExpandTextarea(this);
});
// Initial render pass -- covers every textarea already on the page with server-rendered content the
// moment the DOM is ready. A textarea that's inside a `display:none` ancestor at this point (a
// not-yet-shown Bootstrap modal/tab pane) reads scrollHeight as if empty -- re-expanded once it
// actually becomes visible, see the shown.bs.modal/shown.bs.tab handler below.
$(document).ready(function () {
    document.querySelectorAll('textarea').forEach(autoExpandTextarea);
});
$(document).on('shown.bs.modal shown.bs.tab', function (e) {
    $(e.target).find('textarea').each(function () { autoExpandTextarea(this); });
});
// Zero-config coverage for a value set PROGRAMMATICALLY (jQuery's `.val(x)` on a textarea sets
// `.value` under the hood, same as plain `el.value = x`) -- e.g. populateEmployeeForm()-style code
// loading an existing record's long saved text into a textarea well after the initial-render pass
// above already ran. Wrapping the native `value` SETTER on the prototype (rather than requiring
// every such call site to remember an explicit follow-up call) is deliberate: this app already has
// one well-documented bug class from exactly that "caller forgot the required follow-up call"
// pattern (bootstrap-datepicker's `.datepicker('update')`, see CLAUDE.md's Date Picker section) --
// this makes the same mistake structurally impossible for textareas instead of relying on every
// future page remembering a convention.
(function () {
    const nativeValueDescriptor = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
    if (nativeValueDescriptor && nativeValueDescriptor.configurable && nativeValueDescriptor.set) {
        Object.defineProperty(HTMLTextAreaElement.prototype, 'value', {
            get: nativeValueDescriptor.get,
            set: function (val) {
                nativeValueDescriptor.set.call(this, val);
                autoExpandTextarea(this);
            },
            configurable: true,
        });
    }
})();