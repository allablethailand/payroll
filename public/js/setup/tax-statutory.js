// 2026-09-03, Backlog Phase 9, T044 -- tb_statutory_item/tb_rate_history/currentItemCtx removed
// along with the "Master Rates" tab itself -- see tax-statutory.php's own header comment on this
// tab for the full reasoning/known-gap note. tb_company_setting is now the ONLY table on this
// page's rate-related tab.
//
// 2026-09-06, real bug found and fixed (explicit report + browser console error: "Uncaught
// ReferenceError: toDisplayDateTs is not defined" at showSrHistoryEditView) -- the comment above
// ALSO claimed toIsoDateTs()/toDisplayDateTs() were dead code and removed them here at T044, but
// that was wrong: the LATER T046 rework (statutoryRateModal's own Rate History tab,
// showSrHistoryEditView()/collectSrRateVersionFormData()) reused these exact function names
// assuming they still existed, without ever redefining them -- a genuine oversight across 2
// separate refactors, not a guess. This is why "Add Rate Version" (showSrHistoryEditView(null),
// which never calls toDisplayDateTs()) always worked while editing an EXISTING rate row (which
// does) threw a ReferenceError mid-function, aborting BEFORE reaching applySrCalcMethodFields() at
// the end -- leaving the form in its default markup state (flat-rate fields visible, since
// `#sr_rate_flat_fields` has no `d-none` in the base HTML) regardless of the item's real
// calc_method. Restored as local functions here, same dd/mm/yyyy <-> yyyy-mm-dd shape every other
// page's own toDisplayDateXX()/toIsoDateXX() pair uses (see e.g. payroll/approval.js's
// toDisplayDateAp()/toIsoDateAp()) -- this project's own convention is one local pair per file,
// never a shared global, precisely to avoid the cross-file name-collision class of bug already
// documented elsewhere in this codebase.
function toDisplayDateTs(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function toIsoDateTs(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
let tb_company_setting;
let currentCsItem = null;

function itemNameTs(row) {
    return (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '';
}
function categoryBadgeTs(cat) {
    const key = 'category_' + cat;
    return `<span class="badge bg-light text-dark border">${langData[key] || cat}</span>`;
}
// 2026-08-28, explicit request: "เก็บ Log ดำเนินการว่าแก้ไขล่าสุดเมื่อไหร่" -- updated_at/updated_by
// (falling back to created_at/created_by, see TaxStatutoryModel/CompanyStatutorySettingModel's own
// "editor" join comments) already existed on every one of these 3 tables, just never surfaced in
// the UI. dateField defaults to 'updated_at' (present on all 3 API responses); Company Settings
// passes 'last_edited_at' instead since that one is null until the company has actually customized
// anything (see CompanyStatutorySettingModel::list()'s own docblock on why it doesn't borrow the
// master item's own edit time).
function lastEditedCellTs(row, dateField) {
    dateField = dateField || 'updated_at';
    const raw = row[dateField];
    if (!raw) {
        return '<span class="text-muted small">-</span>';
    }
    const name = (currentLang === 'th' ? row.last_edited_by_name_th : row.last_edited_by_name_en)
        || row.last_edited_by_name_th || row.last_edited_by_name_en;
    // 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
    // แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้") -- dateField here is a real UTC timestamp
    // (updated_at/last_edited_at, both set via CURRENT_TIMESTAMP), not a plain calendar date, but
    // this was using formatDisplayDate() (the DATE-ONLY formatter -- raw substring, no timezone
    // conversion at all) instead of the UTC-aware formatDisplayDateTime(). Same day-boundary risk
    // as every other fix in this pass: a raw UTC date extracted before conversion can be off by a
    // full calendar day for a viewer far from UTC.
    const dateStr = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(raw).split(' ')[0] : raw;
    return `<div class="small">${escapeHtml(dateStr)}</div>${name ? `<div class="text-muted small">${escapeHtml(name)}</div>` : ''}`;
}

/* ---------- Company Statutory Settings (Part 2) ---------- */
function csHasOverrideTs(row) {
    return row.employee_rate_override !== null || row.employer_rate_override !== null
        || row.employee_amount_override !== null || row.employer_amount_override !== null;
}
function csRateInUseCellTs(row) {
    const hasOverride = csHasOverrideTs(row);
    const badge = hasOverride
        ? `<span class="badge bg-warning-subtle text-warning border me-1">${langData['custom_rate'] || 'Custom Rate'}</span>`
        : `<span class="badge bg-light text-dark border me-1">${langData['using_default'] || 'Using Default'}</span>`;
    let valueText = '';
    if (row.calc_method === 'flat_rate') {
        const empRate = hasOverride ? row.employee_rate_override : row.master_employee_rate;
        const erRate = hasOverride ? row.employer_rate_override : row.master_employer_rate;
        const parts = [];
        if (row.is_employee_applicable == 1 && empRate !== null) parts.push(`${Number(empRate)}%`);
        if (row.is_employer_applicable == 1 && erRate !== null) parts.push(`${Number(erRate)}%`);
        valueText = parts.join(' / ');
    } else if (row.calc_method === 'fixed_amount') {
        const empAmt = hasOverride ? row.employee_amount_override : row.master_employee_amount;
        const erAmt = hasOverride ? row.employer_amount_override : row.master_employer_amount;
        const parts = [];
        if (row.is_employee_applicable == 1 && empAmt !== null) parts.push(fmtNum(empAmt));
        if (row.is_employer_applicable == 1 && erAmt !== null) parts.push(fmtNum(erAmt));
        valueText = parts.join(' / ');
    } else if (row.calc_method === 'progressive_bracket') {
        valueText = langData['tax_brackets'] || 'Tax Brackets';
    } else {
        valueText = langData['calc_method_formula'] || 'Formula-based';
    }
    return `<div>${badge}</div><div class="small mt-1">${valueText}</div>`;
}
function csAdjustableCellTs(row) {
    const adjustable = Number(row.is_company_rate_editable) === 1 && ['flat_rate', 'fixed_amount'].includes(row.calc_method);
    return adjustable ? (langData['yes'] || 'Yes') : `<span class="text-muted">${langData['no'] || 'No'}</span>`;
}
// 2026-09-03, Backlog Phase 9, T046, explicit request: "merge Edit and Manage Rates into ONE
// button... icon must clearly communicate what the button does" -- fa-sliders (not a plain pencil)
// opens the single statutoryRateModal, tabs adapted per row.item_scope inside it (see
// openStatutoryRateModal()). Delete only ever shown for a company's own custom item (item_scope=
// 'custom') -- a master item has no delete action on this page at all, same as before T046.
function csActionButtonsTs(row) {
    const deleteBtn = row.item_scope === 'custom'
        ? `<button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-custom-item" data-id="${row.statutory_item_id}" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>`
        : '';
    return `<div class="d-flex gap-1 justify-content-center">
        <button type="button" class="btn btn-link btn-circle-action text-primary btn-manage-sr" data-id="${row.statutory_item_id}" title="${langData['manage'] || 'Manage'}"><i class="fa-solid fa-sliders"></i></button>
        ${deleteBtn}
    </div>`;
}
function initCompanySettingTable() {
    if ($.fn.DataTable.isDataTable('#tb_company_setting')) {
        $('#tb_company_setting').DataTable().ajax.reload(null, false);
        return;
    }
    tb_company_setting = $('#tb_company_setting').DataTable({
        responsive: true,
        paging: false,
        info: false,
        ajax: {
            url: `${BASE_URL}/api/company-statutory-setting.list`,
            // 2026-09-03, Backlog Phase 9, T044 -- fills #masterRateCountryLabel from THIS response
            // now (CompanyStatutorySettingModel::list()'s new countries_name_th/en join), same "read
            // the country name off the first row" pattern the removed Master Rates tab used, since
            // this table is now the only country-scoped list on the page.
            dataSrc: function (json) {
                const rows = json.data || [];
                const first = rows[0];
                const label = first
                    ? ((currentLang === 'en' ? first.countries_name_en : first.countries_name_th) || first.country_code)
                    : (langData['no_statutory_country_configured'] || '-');
                $('#masterRateCountryLabel').text(label);
                return rows;
            }
        },
        columns: [
            // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- status switch is the first
            // column now, same shared mechanism as every other table already converted. Keyed by
            // `statutory_item_id` (not a company_statutory_settings row id -- the company may not
            // have a row for this item yet at all), see CompanyStatutorySettingModel::
            // toggleStatus()'s own docblock and TaxStatutoryController::companySettingToggleStatus()'s
            // own comment on why the endpoint reads `id` for what is semantically an item id.
            { data: 'effective_status', render: (d, t, row) => renderStatusToggleHtml(row.statutory_item_id, d === 'active', '/api/company-statutory-setting.toggle-status') },
            { data: 'code', render: d => `<code class="fw-bold text-dark">${escapeHtml(d)}</code>` },
            { data: null, render: (d, t, row) => escapeHtml(itemNameTs(row)) },
            { data: 'category', render: d => categoryBadgeTs(d) },
            { data: null, className: 'text-end', render: (d, t, row) => csRateInUseCellTs(row) },
            { data: null, render: (d, t, row) => csAdjustableCellTs(row) },
            { data: null, orderable: false, render: (d, t, row) => lastEditedCellTs(row, 'last_edited_at') },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => csActionButtonsTs(row) }
        ],
        language: getTableLang(),
        drawCallback: function () { getTableLang(); },
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
        // rollout. Same `searching:false` gotcha as `tb_rate_history` above (see that table's own
        // comment) -- flipped to `searching:true` to keep the filter pipeline alive, native search
        // box hidden right below to preserve the original look.
        // 2026-09-02, real bug found and fixed: indices shifted +1 now that the status switch was
        // inserted at column 0, and `effective_status` itself dropped from the filter list
        // (interactive widget, not a plain display value, same exemption already applied
        // elsewhere) -- excludes the interactive status SWITCH (0), Last Updated (6, 2026-08-28
        // addition), and actions (7).
        searching: true,
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            // 2026-09-03, Backlog Phase 9, T046 -- "Add" for a company's own CUSTOM statutory item
            // (T045's whole point -- there was no UI to create one at all until now), injected the
            // same ".dt-search" convention every other Add button in this app uses. Hides only the
            // native search INPUT/LABEL inside .dt-search (not the whole container -- that would
            // also hide this button, since it's this container's own child) so the original
            // "no redundant search box" look is preserved while still hosting a toolbar button here.
            $searchDiv.find('input, label').not('.btn-add-custom-item, .btn-add-custom-item *').hide();
            if ($searchDiv.find('.btn-add-custom-item').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-custom-item">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="sr_add_custom_item">${langData['sr_add_custom_item'] || 'Add Custom Item'}</span>
                    </button>
                `);
            }
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 1, key: 'code' },
                    { index: 2, key: 'item_name' },
                    { index: 3, key: 'category' },
                    { index: 4, key: 'rate_in_use' },
                    { index: 5, key: 'adjustable' },
                ]
            });
        }
    });
}
// 2026-09-02, Platform Hardening Phase 1.1 follow-up -- reload after a successful status toggle,
// same pattern as every other converted table's own identical listener.
$(document).on('statusToggle:success', '#tb_company_setting', function () { tb_company_setting.ajax.reload(null, false); });
function masterRateDisplayTs(row) {
    if (row.calc_method === 'flat_rate') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.master_employee_rate !== null) parts.push(`${langData['modal_employee_rate'] || 'Employee'}: ${Number(row.master_employee_rate)}%`);
        if (row.is_employer_applicable == 1 && row.master_employer_rate !== null) parts.push(`${langData['modal_employer_rate'] || 'Employer'}: ${Number(row.master_employer_rate)}%`);
        return parts.join(', ');
    }
    if (row.calc_method === 'fixed_amount') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.master_employee_amount !== null) parts.push(`${langData['modal_employee_amount'] || 'Employee'}: ${fmtNum(row.master_employee_amount)}`);
        if (row.is_employer_applicable == 1 && row.master_employer_amount !== null) parts.push(`${langData['modal_employer_amount'] || 'Employer'}: ${fmtNum(row.master_employer_amount)}`);
        return parts.join(', ');
    }
    return '';
}
// 2026-09-03, Backlog Phase 9, T046 -- state for whichever item statutoryRateModal currently has
// open, shared by every tab's own handlers below (Setting/Details/Rate History all act on the SAME
// item). `id: null` means "not yet saved" (a brand-new custom item mid-creation) -- Rate History
// stays unreachable until the item itself has a real id (see openStatutoryRateModal()).
let currentSrItem = null;
let tb_sr_rate_history;

/** Setting tab content (master items only) -- was openCompanySettingModal()'s own modal-opening
 *  version before T046 folded it into one tab of statutoryRateModal; content/fields UNCHANGED. */
function openCompanySettingTabContent(row) {
    const adjustable = Number(row.is_company_rate_editable) === 1 && ['flat_rate', 'fixed_amount'].includes(row.calc_method);
    currentCsItem = {
        id: row.statutory_item_id,
        calc_method: row.calc_method,
        adjustable: adjustable
    };
    $('#companySettingForm')[0].reset();
    $('#companySettingForm .is-invalid').removeClass('is-invalid');
    $('#cs_is_active').prop('checked', row.effective_status === 'active');
    $('#cs_rate_fields').toggleClass('d-none', !adjustable || row.calc_method !== 'flat_rate');
    $('#cs_amount_fields').toggleClass('d-none', !adjustable || row.calc_method !== 'fixed_amount');
    $('#cs_employee_rate_override').val(row.employee_rate_override !== null ? row.employee_rate_override : '');
    $('#cs_employer_rate_override').val(row.employer_rate_override !== null ? row.employer_rate_override : '');
    $('#cs_employee_amount_override').val(row.employee_amount_override !== null ? row.employee_amount_override : '');
    $('#cs_employer_amount_override').val(row.employer_amount_override !== null ? row.employer_amount_override : '');
    $('#cs_remark').val(row.remark || '');
    if (adjustable) {
        const tpl = langData['company_setting_rate_hint'] || "Master default rate: {value}. Leave the fields below blank to use this default.";
        $('#cs_master_default_hint').text(tpl.replace('{value}', masterRateDisplayTs(row) || '-'));
    } else {
        $('#cs_master_default_hint').text(langData['not_adjustable_hint'] || "This item's rate is fixed by law and cannot be adjusted per company. You may only enable or disable it.");
    }
    // 2026-09-03, T046 -- "Update as system default" for THIS company's own rate override (see
    // CompanyStatutorySettingModel::promoteOverrideToMaster()'s own docblock). Shown only when
    // there's actually an override configured to promote -- the backend would refuse otherwise
    // anyway, but hiding it here avoids a guaranteed-to-fail click for the common "still on
    // default" case. Always rendered regardless of the viewer's own tax_statutory.promote_master
    // permission though (see statutoryRateModal's own markup comment on why).
    $('#srPromoteOverrideBtn').toggleClass('d-none', !csHasOverrideTs(row));
}
function collectCompanySettingFormData() {
    return {
        statutory_item_id: currentSrItem ? currentSrItem.id : null,
        is_active: $('#cs_is_active').is(':checked'),
        employee_rate_override: $('#cs_employee_rate_override').val(),
        employer_rate_override: $('#cs_employer_rate_override').val(),
        employee_amount_override: $('#cs_employee_amount_override').val(),
        employer_amount_override: $('#cs_employer_amount_override').val(),
        remark: $('#cs_remark').val().trim()
    };
}

/* ---------- Item Details tab (custom items only, T046) ---------- */
function resetSrDetailsForm() {
    $('#srDetailsForm')[0].reset();
    $('#srDetailsForm .is-invalid').removeClass('is-invalid');
    // 2026-09-04, T047 -- select2-remote fields (master_statutory_categories/_calc_bases) are
    // cleared via .empty() (removes any leftover <option> from a previously-open item), NOT a plain
    // .val('') -- an ajax-mode select2 has no option elements to select from at all until the user
    // actually searches, so .val('') alone would leave a stale option/label showing.
    $('#sr_item_category').empty().trigger('change');
    $('#sr_item_calc_method').val('flat_rate').trigger('change');
    $('#sr_item_calc_base').empty().trigger('change');
    $('#sr_item_rounding_mode').val('round').trigger('change');
    $('#sr_item_decimal_places').val(2);
    $('#sr_item_is_employee_applicable, #sr_item_is_employer_applicable').prop('checked', true);
    // A brand-new custom item's calc_method is freely choosable; an EXISTING one is locked (see
    // populateSrDetailsForm()) -- changing calc_method on a saved item would orphan its own rate
    // history (built around the OLD method's fields), same reasoning TaxStatutoryModel::save()'s
    // own docblock never had to state before because the old Master Rates form never let a
    // non-superadmin reach an item with real rate history attached in the first place.
    $('#sr_item_calc_method').prop('disabled', false);
    $('#sr_calc_method_lock_hint').text('');
    $('#srPromoteItemBtn').addClass('d-none');
}
function populateSrDetailsForm(row) {
    $('#sr_item_code').val(row.code);
    $('#sr_item_name_th').val(row.name_th);
    $('#sr_item_name_en').val(row.name_en);
    // 2026-09-04, T047 -- build the Option directly instead of a plain .val() -- these 2 fields are
    // select2-remote (ajax) now, with no <option> preloaded for an existing value, so a bare .val()
    // would silently fail to select/display anything (the same select2-remote-empty-preload bug
    // documented elsewhere in this app, e.g. employee/detail.js's own team_id/work_location_id
    // preload). Text comes straight from the SAME i18n keys the row's own badge rendering already
    // uses (categoryBadgeTs()) -- no extra lookup call needed since these are still a small, known
    // set locally.
    const categoryText = langData['category_' + row.category] || row.category;
    $('#sr_item_category').empty().append(new Option(categoryText, row.category, true, true)).trigger('change');
    $('#sr_item_calc_method').val(row.calc_method).trigger('change').prop('disabled', true);
    $('#sr_calc_method_lock_hint').text(langData['sr_calc_method_locked_hint'] || "Can't be changed after the item has rate history -- delete and recreate if genuinely needed.");
    const calcBaseText = langData['calc_base_' + row.calc_base] || row.calc_base;
    $('#sr_item_calc_base').empty().append(new Option(calcBaseText, row.calc_base, true, true)).trigger('change');
    $('#sr_item_rounding_mode').val(row.rounding_mode || 'round').trigger('change');
    $('#sr_item_decimal_places').val(row.decimal_places !== undefined && row.decimal_places !== null ? row.decimal_places : 2);
    $('#sr_item_is_employee_applicable').prop('checked', Number(row.is_employee_applicable) === 1);
    $('#sr_item_is_employer_applicable').prop('checked', Number(row.is_employer_applicable) === 1);
    // Always rendered regardless of the viewer's own tax_statutory.promote_master permission -- see
    // statutoryRateModal's own markup comment.
    $('#srPromoteItemBtn').removeClass('d-none');
}
function validateSrDetailsForm() {
    let firstInvalid = null;
    $('#srDetailsForm .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}
function collectSrDetailsFormData() {
    return {
        id: currentSrItem && currentSrItem.id ? currentSrItem.id : undefined,
        code: $('#sr_item_code').val().trim(),
        name_th: $('#sr_item_name_th').val().trim(),
        name_en: $('#sr_item_name_en').val().trim(),
        category: $('#sr_item_category').val(),
        calc_method: $('#sr_item_calc_method').val(),
        calc_base: $('#sr_item_calc_base').val(),
        rounding_mode: $('#sr_item_rounding_mode').val() || 'round',
        decimal_places: $('#sr_item_decimal_places').val() || 0,
        is_employee_applicable: $('#sr_item_is_employee_applicable').is(':checked'),
        is_employer_applicable: $('#sr_item_is_employer_applicable').is(':checked'),
        // A custom item is always enabled-by-default for its OWNING company (the only company that
        // will ever see it) -- default_is_active/is_company_rate_editable have no meaning for an
        // item the company owns outright, left at their column defaults server-side.
        default_is_active: true,
    };
}

/* ---------- Rate History tab (both scopes, T046) ---------- */
function srRateSummaryTs(row) {
    if (!currentSrItem) return '';
    if (currentSrItem.calc_method === 'flat_rate') {
        const parts = [];
        if (row.employee_rate !== null) parts.push(`${langData['modal_employee_rate'] || 'Employee'}: ${Number(row.employee_rate)}%`);
        if (row.employer_rate !== null) parts.push(`${langData['modal_employer_rate'] || 'Employer'}: ${Number(row.employer_rate)}%`);
        return parts.join(' / ');
    }
    if (currentSrItem.calc_method === 'fixed_amount') {
        const parts = [];
        if (row.employee_amount !== null) parts.push(`${langData['modal_employee_amount'] || 'Employee'}: ${fmtNum(row.employee_amount)}`);
        if (row.employer_amount !== null) parts.push(`${langData['modal_employer_amount'] || 'Employer'}: ${fmtNum(row.employer_amount)}`);
        return parts.join(' / ');
    }
    if (currentSrItem.calc_method === 'progressive_bracket') {
        return `${row.bracket_count || 0} ${langData['tax_brackets'] || 'Tax Brackets'}`;
    }
    return langData['calc_method_formula'] || 'Formula-based';
}
// 2026-09-03, T046 -- a real DataTable (client-side, small per-item list), NOT hand-rendered rows --
// same "every list uses DataTables" rule + same paging:false/info:false/searching:true-with-hidden-
// box/Excel-column-filter shape the old (T044-removed) tb_rate_history already established for this
// exact "rate versions for one item" use case. Initialized ONCE (guarded, same pattern as
// initCompanySettingTable()); re-opening the modal for a DIFFERENT item just calls ajax.reload() --
// the `data` callback below reads currentSrItem.id fresh on every reload, so it always targets
// whichever item the modal currently has open.
function initSrRateHistoryTable() {
    if ($.fn.DataTable.isDataTable('#tb_sr_rate_history')) {
        $('#tb_sr_rate_history').DataTable().ajax.reload(null, false);
        return;
    }
    tb_sr_rate_history = $('#tb_sr_rate_history').DataTable({
        responsive: true,
        paging: false,
        info: false,
        ajax: {
            url: `${BASE_URL}/api/statutory-item.rate-history.list`,
            dataSrc: 'data',
            data: function (d) { d.item_id = currentSrItem ? currentSrItem.id : 0; }
        },
        columns: [
            { data: 'effective_date', render: { display: d => formatDisplayDate(d), sort: d => d, filter: d => d } },
            { data: 'end_date', render: { display: d => d ? formatDisplayDate(d) : `<span class="badge bg-success-subtle text-success">${langData['current_version'] || 'Current'}</span>`, sort: d => d || '', filter: d => d || '' } },
            { data: null, className: 'text-end', render: (d, t, row) => srRateSummaryTs(row) },
            { data: null, orderable: false, render: (d, t, row) => lastEditedCellTs(row) },
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => `
                <button type="button" class="btn btn-link btn-circle-action text-warning btn-edit-sr-rate" data-id="${row.id}"><i class="fas fa-edit"></i></button>
                <button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-sr-rate" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
            ` }
        ],
        language: getTableLang(),
        drawCallback: function () { getTableLang(); },
        searching: true,
        initComplete: function () {
            const self = this.api();
            $(self.table().container()).find('.dt-search').hide();
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'effective_date' },
                    { index: 1, key: 'end_date' },
                ]
            });
        }
    });
}
function applySrCalcMethodFields(calcMethod) {
    $('#sr_rate_flat_fields').toggleClass('d-none', calcMethod !== 'flat_rate');
    $('#sr_rate_amount_fields').toggleClass('d-none', calcMethod !== 'fixed_amount');
    $('#sr_rate_bracket_fields').toggleClass('d-none', calcMethod !== 'progressive_bracket');
    $('#sr_rate_formula_fields').toggleClass('d-none', calcMethod !== 'formula');
    const showEmployee = currentSrItem ? currentSrItem.is_employee_applicable : true;
    const showEmployer = currentSrItem ? currentSrItem.is_employer_applicable : true;
    $('#sr_rate_employee_rate_wrapper, #sr_rate_employee_amount_wrapper').toggleClass('d-none', !showEmployee);
    $('#sr_rate_employer_rate_wrapper, #sr_rate_employer_amount_wrapper').toggleClass('d-none', !showEmployer);
    $('#sr_rate_employee_rate').toggleClass('required', calcMethod === 'flat_rate' && showEmployee);
    $('#sr_rate_employer_rate').toggleClass('required', calcMethod === 'flat_rate' && showEmployer);
    $('#sr_rate_employee_amount').toggleClass('required', calcMethod === 'fixed_amount' && showEmployee);
    $('#sr_rate_employer_amount').toggleClass('required', calcMethod === 'fixed_amount' && showEmployer);
    $('#sr_rate_formula_config').toggleClass('required', calcMethod === 'formula');
}
function srRecalcBracketRows() {
    $('#srBracketBody tr').each(function (idx) {
        if (idx === 0) return;
        const prevMax = $('#srBracketBody tr').eq(idx - 1).find('.sr-bracket-max').val();
        const min = prevMax !== '' ? (parseFloat(prevMax) + 0.01).toFixed(2) : '';
        $(this).find('.sr-bracket-min').val(min);
    });
}
function addSrBracketRow(min, max, rate) {
    const idx = $('#srBracketBody tr').length;
    const $row = $(`<tr>
        <td><input type="number" step="0.01" class="form-control form-control-sm sr-bracket-min" value="${min !== undefined ? min : ''}" ${idx > 0 ? 'readonly' : ''}></td>
        <td><input type="number" step="0.01" class="form-control form-control-sm sr-bracket-max" value="${max !== undefined && max !== null ? max : ''}" placeholder="${langData['no_upper_limit'] || 'No upper limit'}"></td>
        <td><input type="number" step="0.0001" min="0" max="100" class="form-control form-control-sm sr-bracket-rate" value="${rate !== undefined ? rate : ''}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-sr-bracket"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`);
    $('#srBracketBody').append($row);
    if (idx === 0 && min === undefined) {
        $row.find('.sr-bracket-min').val(0);
    }
}
function collectSrBrackets() {
    const brackets = [];
    $('#srBracketBody tr').each(function () {
        brackets.push({
            min_amount: $(this).find('.sr-bracket-min').val(),
            max_amount: $(this).find('.sr-bracket-max').val() === '' ? null : $(this).find('.sr-bracket-max').val(),
            rate: $(this).find('.sr-bracket-rate').val(),
        });
    });
    return brackets;
}
function showSrHistoryEditView(row) {
    $('#srHistoryListView').addClass('d-none');
    $('#srHistoryEditView').removeClass('d-none');
    $('#srRateVersionForm')[0].reset();
    $('#srRateVersionForm .is-invalid').removeClass('is-invalid');
    $('#sr_rate_id').val('');
    $('#srBracketBody').empty();
    $('#srRateCalcPreviewBase').val(30000);
    $('#srRateCalcPreviewResult').addClass('d-none').empty();
    const calcMethod = currentSrItem ? currentSrItem.calc_method : 'flat_rate';
    if (row) {
        $('#sr_rate_id').val(row.id);
        $('#sr_rate_effective_date').val(toDisplayDateTs(row.effective_date)).datepicker('update');
        $('#sr_rate_end_date').val(toDisplayDateTs(row.end_date)).datepicker('update');
        $('#sr_rate_employee_rate').val(row.employee_rate !== null ? row.employee_rate : '');
        $('#sr_rate_employer_rate').val(row.employer_rate !== null ? row.employer_rate : '');
        $('#sr_rate_employee_amount').val(row.employee_amount !== null ? row.employee_amount : '');
        $('#sr_rate_employer_amount').val(row.employer_amount !== null ? row.employer_amount : '');
        $('#sr_rate_min_base_amount').val(row.min_base_amount !== null ? row.min_base_amount : '');
        $('#sr_rate_max_base_amount').val(row.max_base_amount !== null ? row.max_base_amount : '');
        $('#sr_rate_remark').val(row.remark || '');
        $('#sr_rate_formula_config').val(row.formula_config ? JSON.stringify(JSON.parse(row.formula_config), null, 2) : '');
        if (calcMethod === 'progressive_bracket') {
            (row.brackets || []).forEach(b => addSrBracketRow(b.min_amount, b.max_amount, b.rate));
            if (!row.brackets || row.brackets.length === 0) addSrBracketRow(0, '', '');
        }
    } else {
        $('#sr_rate_effective_date').val('').datepicker('update');
        $('#sr_rate_end_date').val('').datepicker('update');
        if (calcMethod === 'progressive_bracket') addSrBracketRow(0, '', '');
    }
    applySrCalcMethodFields(calcMethod);
}
function hideSrHistoryEditView() {
    $('#srHistoryEditView').addClass('d-none');
    $('#srHistoryListView').removeClass('d-none');
}
function collectSrRateVersionFormData() {
    const data = {
        id: $('#sr_rate_id').val() || undefined,
        statutory_item_id: currentSrItem ? currentSrItem.id : null,
        effective_date: toIsoDateTs($('#sr_rate_effective_date').val()),
        end_date: $('#sr_rate_end_date').val() ? toIsoDateTs($('#sr_rate_end_date').val()) : null,
        min_base_amount: $('#sr_rate_min_base_amount').val(),
        max_base_amount: $('#sr_rate_max_base_amount').val(),
        remark: $('#sr_rate_remark').val().trim(),
    };
    const calcMethod = currentSrItem ? currentSrItem.calc_method : '';
    if (calcMethod === 'flat_rate') {
        data.employee_rate = $('#sr_rate_employee_rate').val();
        data.employer_rate = $('#sr_rate_employer_rate').val();
    } else if (calcMethod === 'fixed_amount') {
        data.employee_amount = $('#sr_rate_employee_amount').val();
        data.employer_amount = $('#sr_rate_employer_amount').val();
    } else if (calcMethod === 'progressive_bracket') {
        data.brackets = collectSrBrackets();
    } else if (calcMethod === 'formula') {
        data.formula_config = $('#sr_rate_formula_config').val();
    }
    return data;
}
function srRateVersionCalcPreviewFormulaStepsHtml(formula) {
    if (!formula) return '';
    let html = '';
    if (formula.type === 'flat_rate') {
        if (formula.employee_rate !== null) {
            html += `<div class="calc-preview-step"><span>${langData['calc_preview_step_employee_rate'] || 'Employee Rate'}</span>: <code>${formula.employee_rate}%</code></div>`;
            html += `<div class="calc-preview-step"><span>${langData['calc_preview_step_employee_raw_amount'] || 'Amount'}</span>: <code>${fmtNum(formula.employee_raw_amount)}</code></div>`;
        }
    } else if (formula.type === 'progressive_bracket') {
        (formula.steps || []).forEach(function (s) {
            const maxLabel = s.max !== null ? fmtNum(s.max) : (langData['no_upper_limit'] || 'No upper limit');
            html += `<div class="calc-preview-step"><span>${fmtNum(s.min)} - ${maxLabel} @ ${s.rate}%</span>: <code>${fmtNum(s.taxable)} × ${s.rate}% = ${fmtNum(s.tax)}</code></div>`;
        });
    }
    return html;
}

/**
 * 2026-09-03, Backlog Phase 9, T046 -- main entry point for the merged modal (replaces
 * openCompanySettingModal()'s old "one modal, one purpose" shape). `row` is null for "Add Custom
 * Item"; otherwise a row from tb_company_setting's own list() response (already carries
 * item_scope). Which of the 3 tabs are even visible is decided HERE, once, based on scope + whether
 * the item has a real id yet -- every tab's own populate function assumes it's only ever called
 * when relevant.
 */
function openStatutoryRateModal(row) {
    const isNew = !row;
    const scope = isNew ? 'custom' : row.item_scope;
    currentSrItem = {
        id: isNew ? null : row.statutory_item_id,
        scope: scope,
        calc_method: isNew ? 'flat_rate' : row.calc_method,
        is_employee_applicable: isNew ? true : Number(row.is_employee_applicable) === 1,
        is_employer_applicable: isNew ? true : Number(row.is_employer_applicable) === 1,
    };
    $('#sr_statutory_item_id').val(currentSrItem.id || '');
    $('#sr_item_scope').val(scope);
    $('#srModalItemName').text(isNew ? (langData['sr_new_custom_item'] || 'New Custom Item') : `(${row.code} - ${itemNameTs(row)})`);
    $('#srModalScopeBadgeMaster').toggleClass('d-none', scope !== 'master');
    $('#srModalScopeBadgeCustom').toggleClass('d-none', scope !== 'custom');

    $('#srDetailsTabItem').toggleClass('d-none', scope !== 'custom');
    $('#srSettingTabItem').toggleClass('d-none', scope !== 'master');
    // Rate History needs a real item id to fetch against -- unreachable until the Details tab has
    // been saved at least once for a brand-new custom item.
    $('#srHistoryTabItem').toggleClass('d-none', isNew);

    hideSrHistoryEditView();
    if (scope === 'custom') {
        resetSrDetailsForm();
        if (!isNew) populateSrDetailsForm(row);
        bootstrap.Tab.getOrCreateInstance(document.getElementById('sr-details-tab')).show();
    } else {
        openCompanySettingTabContent(row);
        // 2026-09-05, real bug found and fixed (explicit report: "ตรงภาษีเงินได้ กดเข้าไปแก้ไขไม่ขึ้น
        // Form 8 อัตราครับ ขึ้นแบบเดียวกับประกันสังคม" -- Personal Income Tax's edit click doesn't show
        // the 8-bracket form, it shows the same [default tab] as Social Security) -- a MASTER item
        // ALWAYS defaulted to the Setting tab regardless of whether that tab has anything useful to
        // show. Setting tab is only ever meaningful for an ADJUSTABLE item (flat_rate/fixed_amount
        // AND is_company_rate_editable=1, e.g. TH_SSO) -- it lets a company override the master
        // rate. A progressive_bracket item like TH_PIT (or any item with is_company_rate_editable=0)
        // can NEVER be overridden at all (see openCompanySettingTabContent()'s own `adjustable`
        // check just above), so its Setting tab only ever shows a static "not adjustable" hint --
        // the actual 8 PIT tax brackets live in the Rate History tab, one extra click away, which
        // read to the user as "nothing happened / looks the same as SSO's [equally tab-first, but
        // actually useful there] edit screen." Fixed by defaulting straight to the Rate History tab
        // for a non-adjustable master item instead, since that's the only tab with real content to
        // manage for one -- an adjustable item (SSO/PVD-style) is unaffected, still opens on Setting.
        const adjustable = Number(row.is_company_rate_editable) === 1 && ['flat_rate', 'fixed_amount'].includes(row.calc_method);
        const defaultTabId = adjustable ? 'sr-setting-tab' : 'sr-history-tab';
        bootstrap.Tab.getOrCreateInstance(document.getElementById(defaultTabId)).show();
    }
    // Rate History tab is hidden entirely for a brand-new item (srHistoryTabItem's own d-none
    // above) -- nothing to load yet, and manually touching #tb_sr_rate_history's DOM here (rather
    // than through the DataTables API) would desync its internal state for whenever a REAL item
    // does get opened later in the same page session.
    if (!isNew) {
        initSrRateHistoryTable();
    }
    new bootstrap.Modal(document.getElementById('statutoryRateModal')).show();
}

function initStatutoryRateModalUI() {
    $(document).on('click', '.btn-add-custom-item', function () {
        openStatutoryRateModal(null);
    });
    $(document).on('click', '.btn-manage-sr', function () {
        const itemId = $(this).data('id');
        const rowData = tb_company_setting.rows().data().toArray().find(r => Number(r.statutory_item_id) === Number(itemId));
        if (rowData) openStatutoryRateModal(rowData);
    });
    $(document).on('click', '.btn-delete-custom-item', function () {
        const id = $(this).data('id');
        showConfirm(langData['confirm_delete_title'] || 'Confirm Delete', langData['confirm_delete_message'] || 'Are you sure you want to delete this item?', function () {
            $.ajax({
                url: `${BASE_URL}/api/statutory-item.custom.delete`,
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () { showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.'); }
            });
        });
    });

    /* ---- Item Details tab (custom items) ---- */
    $(document).on('submit', '#srDetailsForm', function (e) {
        e.preventDefault();
        const invalidEl = validateSrDetailsForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectSrDetailsFormData();
        const $btn = $('#srDetailsForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.custom.save`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    // Newly-created item now has a real id -- unlock Rate History + lock calc_method,
                    // same as if the modal had been reopened on an already-saved row.
                    currentSrItem.id = res.id || currentSrItem.id;
                    $('#sr_statutory_item_id').val(currentSrItem.id);
                    $('#srHistoryTabItem').removeClass('d-none');
                    $('#sr_item_calc_method').prop('disabled', true);
                    $('#sr_calc_method_lock_hint').text(langData['sr_calc_method_locked_hint'] || "Can't be changed after the item has rate history -- delete and recreate if genuinely needed.");
                    $('#srPromoteItemBtn').removeClass('d-none');
                    initSrRateHistoryTable();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
    $(document).on('click', '#srPromoteItemBtn', function () {
        if (!currentSrItem || !currentSrItem.id) return;
        showConfirm(
            langData['sr_promote_item_confirm_title'] || 'Promote this item to system default?',
            langData['sr_promote_item_confirm_message'] || 'This item becomes visible to EVERY company in this country from now on. This cannot be undone.',
            function () {
                $.ajax({
                    url: `${BASE_URL}/api/statutory-item.custom.promote`,
                    method: 'POST', contentType: 'application/json', dataType: 'json',
                    data: JSON.stringify({ id: currentSrItem.id }),
                    success: function (res) {
                        if (res.status) {
                            showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                            bootstrap.Modal.getInstance(document.getElementById('statutoryRateModal')).hide();
                            if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                        } else {
                            showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                        }
                    },
                    error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
                });
            }
        );
    });

    /* ---- Company Setting tab (master items) ---- */
    $(document).on('submit', '#companySettingForm', function (e) {
        e.preventDefault();
        const payload = collectCompanySettingFormData();
        const $btn = $('#companySettingForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/company-statutory-setting.save`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('statutoryRateModal')).hide();
                    if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
    $(document).on('click', '#btnResetCompanySetting', function () {
        if (!currentCsItem) return;
        const title = langData['reset_confirm_title'] || 'Reset to system default?';
        const message = langData['reset_confirm_message'] || "This will remove your company's custom rate/enable setting for this item and fall back to the system default.";
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/company-statutory-setting.reset`,
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ statutory_item_id: currentCsItem.id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['reset_success'] || 'Reset to system default successfully.');
                        bootstrap.Modal.getInstance(document.getElementById('statutoryRateModal')).hide();
                        if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to reset data.');
                    }
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred.'); }
            });
        });
    });
    // 2026-09-03, T046 -- "Update as system default" for this company's own rate OVERRIDE (the
    // other promote case -- see srPromoteItemBtn above for promoting a whole custom item). Needs an
    // effective_date the backend doesn't have a sensible default for (it's a real, dated master rate
    // version) -- a plain SweetAlert2 input prompt rather than a new shared alert.js helper, since
    // this is the one place in the app that needs a date INPUT inside a confirm, not just yes/no.
    $(document).on('click', '#srPromoteOverrideBtn', function () {
        if (!currentCsItem) return;
        const today = new Date().toISOString().slice(0, 10);
        Swal.fire({
            icon: 'info',
            title: langData['sr_promote_override_confirm_title'] || 'Promote your override to system default?',
            html: `<p>${langData['sr_promote_override_confirm_message'] || 'Your own rate override becomes the new master default for EVERY company in this country from this date onward. This cannot be undone.'}</p>
                   <label class="form-label small mb-1 d-block text-start">${langData['modal_effective_date'] || 'Effective Date'}</label>
                   <input type="date" id="swalSrPromoteDate" class="swal2-input" value="${today}">`,
            showCancelButton: true,
            confirmButtonText: langData.yes || 'Yes',
            cancelButtonText: langData.no || 'No',
            preConfirm: () => {
                const val = document.getElementById('swalSrPromoteDate').value;
                if (!val) { Swal.showValidationMessage(langData['required_star_message'] || 'Please fill all fields marked with *'); }
                return val;
            }
        }).then(r => {
            if (!r.isConfirmed || !r.value) return;
            $.ajax({
                url: `${BASE_URL}/api/company-statutory-setting.promote`,
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ statutory_item_id: currentCsItem.id, effective_date: r.value }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                        bootstrap.Modal.getInstance(document.getElementById('statutoryRateModal')).hide();
                        if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                    }
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
            });
        });
    });

    /* ---- Rate History tab (both scopes) ---- */
    $(document).on('click', '#srAddRateVersionBtn', function () { showSrHistoryEditView(null); });
    $(document).on('click', '#srCancelRateVersionBtn', function () { hideSrHistoryEditView(); });
    $(document).on('click', '.btn-edit-sr-rate', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.rate-history.get`,
            method: 'GET', data: { id: id }, dataType: 'json',
            success: function (res) {
                if (res.status) showSrHistoryEditView(res.data);
                else showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
    });
    $(document).on('click', '.btn-delete-sr-rate', function () {
        const id = $(this).data('id');
        showConfirm(langData['confirm_delete_title'] || 'Confirm Delete', langData['confirm_delete_message'] || 'Are you sure you want to delete this item?', function () {
            $.ajax({
                url: `${BASE_URL}/api/statutory-item.rate-history.delete`,
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        initSrRateHistoryTable();
                        if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () { showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.'); }
            });
        });
    });
    $(document).on('click', '#srBtnAddBracketRow', function () {
        const lastMax = $('#srBracketBody tr:last .sr-bracket-max').val();
        addSrBracketRow(lastMax !== '' && lastMax !== undefined ? (parseFloat(lastMax) + 0.01).toFixed(2) : '', '', '');
    });
    $(document).on('click', '.btn-remove-sr-bracket', function () {
        $(this).closest('tr').remove();
        srRecalcBracketRows();
    });
    $(document).on('change', '.sr-bracket-max', function () { srRecalcBracketRows(); });
    $(document).on('click', '#srBtnRateCalcPreview', function () {
        const $btn = $(this);
        const sampleBase = parseFloat($('#srRateCalcPreviewBase').val());
        if (isNaN(sampleBase) || sampleBase < 0) {
            showWarning(langData['invalid_input'] || 'Please enter a valid value.');
            return;
        }
        const payload = collectSrRateVersionFormData();
        payload.sample_base_amount = sampleBase;
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>');
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.rate-version.preview`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    const stepsHtml = srRateVersionCalcPreviewFormulaStepsHtml(res.formula);
                    const empLabel = langData['calc_preview_step_employee_amount'] || 'Employee Amount';
                    const erLabel = langData['calc_preview_step_employer_amount'] || 'Employer Amount';
                    $('#srRateCalcPreviewResult').removeClass('d-none').html(`
                        <div class="calc-preview-amount mb-1">${empLabel}: ${fmtNum(res.employee_amount)} &nbsp;|&nbsp; ${erLabel}: ${fmtNum(res.employer_amount)}</div>
                        ${stepsHtml}
                    `);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Unable to compute preview.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while computing the preview.');
            }
        });
    });
    $(document).on('submit', '#srRateVersionForm', function (e) {
        e.preventDefault();
        const payload = collectSrRateVersionFormData();
        const $btn = $('#srRateVersionForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.rate-history.save`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    hideSrHistoryEditView();
                    initSrRateHistoryTable();
                    if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
}

$(document).ready(function () {
    // 2026-09-03, Backlog Phase 9, T044 -- Company Settings ("Statutory Rates") is now the sole/
    // FIRST tab (was 2nd, gated behind a shown.bs.tab click before this), so it's initialized
    // directly here instead of waiting for a tab-click event that never fires for an
    // already-active tab on initial page load. The shown.bs.tab branch below is kept (harmless,
    // initCompanySettingTable() itself guards against double-init and just reloads) so returning to
    // this tab after visiting another one still refreshes it.
    initCompanySettingTable();
    initStatutoryRateModalUI();
    if (typeof initSelect2 === 'function') {
        // 2026-09-04, T047 -- category/calc_base are now master_statutory_categories/_calc_bases
        // (select2-remote); calc_method/rounding_mode deliberately stay static/hardcoded (tied to
        // real calculation-engine code, see the migration's own docblock).
        initSelect2Remote('#sr_item_category');
        initSelect2Remote('#sr_item_calc_base');
        initSelect2('#sr_item_calc_method', { mode: 'static' });
        initSelect2('#sr_item_rounding_mode', { mode: 'static' });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#sr_rate_effective_date');
        initDatepicker('#sr_rate_end_date');
    }
    // 2026-09-05, real bug found and fixed (explicit report + screenshots: editing an EXISTING
    // rate-history row for a progressive_bracket MASTER item like TH_PIT showed the flat-rate form
    // instead of the 8-bracket table, even though the SAME item's Rate History LIST correctly showed
    // "8 ขั้นบันไดภาษี" moments earlier -- proving currentSrItem.calc_method WAS 'progressive_bracket'
    // right up until the Edit click, then wasn't by the time the edit form rendered). Root cause:
    // this handler unconditionally overwrites currentSrItem.calc_method from `#sr_item_calc_method`
    // (the Item Details tab's OWN calc_method picker, used only when creating/editing a CUSTOM
    // item's catalog definition) -- but that field is COMPLETELY IRRELEVANT to a MASTER item's Rate
    // History tab (`srDetailsTabItem` is hidden entirely for scope='master', see
    // openStatutoryRateModal()) and is left holding whatever value it was last set to by some
    // EARLIER, unrelated custom-item interaction in the same page session (default 'flat_rate' if
    // never touched at all). Any 'change' event on it -- however it fires -- was silently clobbering
    // the currently-open MASTER item's real calc_method with that leftover value. Guarded to only
    // ever apply while a CUSTOM item is genuinely open (the only scope where this select's value is
    // meant to represent currentSrItem.calc_method at all) -- a master item's calc_method, once set
    // from its own row data in openStatutoryRateModal(), is now never touched by this field again.
    $(document).on('change', '#sr_item_calc_method', function () {
        if (currentSrItem && currentSrItem.scope === 'custom') currentSrItem.calc_method = $(this).val();
    });
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'company-setting-tab') {
            initCompanySettingTable();
        }
        if (tabId === 'document-format-tab') {
            loadStatutoryFormatSettings();
        }
        if (tabId === 'nonresident-tax-tab') {
            loadNonResidentTaxSettings();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});

/* ---------- Non-Resident Foreign Tax (2026-09-02) ----------
 * Plain company-configurable flat withholding %, never a hardcoded rate this app asserts is
 * "correct" -- see NonResidentTaxSettingModel's own docblock for the full reasoning. Singleton
 * settings row per company, same load/save shape as Document Format's own version-picker above. */
function applyNonResidentTaxFieldsVisibility() {
    $('#nonresidentTaxFieldsWrap').toggleClass('d-none', !$('#nonresidentTaxEnabled').is(':checked'));
}
$(document).on('change', '#nonresidentTaxEnabled', applyNonResidentTaxFieldsVisibility);
// 2026-09-02, Platform Hardening Phase 1.2 -- dirty-check baseline for the new Cancel button,
// re-taken after every load and every successful save (same shared snapshotFormState()/
// confirmIfDirtyThen() from app.js Employee Detail's own cancelEmployeeEdit() uses).
let nonresidentTaxBaselineSnapshot = null;
function loadNonResidentTaxSettings() {
    $.ajax({
        url: `${BASE_URL}/api/nonresident-tax-setting.get`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const d = res.data || {};
            $('#nonresidentTaxEnabled').prop('checked', !!d.enabled);
            $('#nonresidentTaxFlatRate').val(d.flat_rate_percent !== null && d.flat_rate_percent !== undefined ? d.flat_rate_percent : '');
            $('#nonresidentTaxReferenceNote').val(d.reference_note || '');
            applyNonResidentTaxFieldsVisibility();
            if (typeof snapshotFormState === 'function') {
                nonresidentTaxBaselineSnapshot = snapshotFormState($('#nonresident-tax-pane'));
            }
        }
    });
}
$(document).on('click', '#nonresidentTaxSaveBtn', function () {
    const $btn = $(this);
    if (typeof setButtonLoading === 'function') setButtonLoading($btn, true);
    else $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/nonresident-tax-setting.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({
            enabled: $('#nonresidentTaxEnabled').is(':checked'),
            flat_rate_percent: $('#nonresidentTaxFlatRate').val(),
            reference_note: $('#nonresidentTaxReferenceNote').val(),
        }),
        success: function (res) {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                if (typeof snapshotFormState === 'function') {
                    nonresidentTaxBaselineSnapshot = snapshotFormState($('#nonresident-tax-pane'));
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '#nonresidentTaxCancelBtn', function () {
    confirmIfDirtyThen($('#nonresident-tax-pane'), nonresidentTaxBaselineSnapshot, loadNonResidentTaxSettings);
});

/* ---------- Document Format (statutory format version selector, 2026-08-29; redesigned as the full
 * "what must be filed" checklist for T049, 2026-09-04) ----------
 * See StatutoryFormatVersionModel's own docblock: a version PICKER (which known layout to file),
 * not a field editor -- ภ.ง.ด./สปส. byte layouts are government-mandated, not something a company
 * should freely edit the way Bank File Format lets them edit a bank's bulk-transfer layout.
 * 2026-09-04, T049: the backend now returns `{country_code, country_name_th, country_name_en,
 * items: [...]}` (was a bare array) -- each item's own `label` (bilingual, from
 * ReportGeneratorInterface::label()) is the label SOURCE OF TRUTH now, not a `statutory_form_<code>`
 * i18n key that would need a manual addition for every future country/form -- statutoryFormLabel()
 * below still falls back to the old i18n-key/raw-code path only for the rare case a row has no
 * `label` at all (should not happen in practice, defensive only). Items with
 * `has_version_picker=false` are informational-only rows (no `<select>`/Save -- there is exactly ONE
 * implementation, nothing to choose between) that complete the checklist alongside the 2 real
 * pickers, grouped under the SAME category section headers (`category_tax`/`category_social_
 * insurance`/`category_provident_fund`/`category_other`) T047's Statutory Rates tab already uses,
 * for visual consistency across this page. */
function statutoryFormLabel(item) {
    if (item.label && (item.label.th || item.label.en)) {
        return (currentLang === 'th' ? item.label.th : item.label.en) || item.label.th || item.label.en;
    }
    const key = 'statutory_form_' + item.form_code.toLowerCase();
    return langData[key] || item.form_code;
}
function statutoryVersionLabel(v) {
    return (currentLang === 'th' ? v.name_th : v.name_en) || v.name_th || v.name_en || v.version_code;
}
function loadStatutoryFormatSettings() {
    const $container = $('#statutoryFormatCards').html(`<div class="text-center text-secondary py-3"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $('#documentFormatCountryLabel').text('-');
    $.ajax({
        url: `${BASE_URL}/api/statutory-format-version.settings`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                $container.html(`<div class="text-danger small">${res.message || langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
                return;
            }
            const data = res.data || {};
            const countryLabel = currentLang === 'th' ? data.country_name_th : data.country_name_en;
            $('#documentFormatCountryLabel').text(countryLabel || data.country_name_th || data.country_name_en || data.country_code || '-');
            renderStatutoryFormatCards(data.items || []);
        },
        error: function () {
            $container.html(`<div class="text-danger small">${langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
        }
    });
}
const STATUTORY_FORMAT_CATEGORY_ORDER = ['tax', 'social_insurance', 'provident_fund', 'other'];
function renderStatutoryFormatCards(items) {
    const $container = $('#statutoryFormatCards').empty();
    if (!items.length) {
        $container.html(`<div class="text-secondary small">${langData['no_statutory_formats'] || 'No document formats are available yet.'}</div>`);
        return;
    }
    window.__statutoryFormVersionsCache = {};
    const grouped = {};
    items.forEach(function (item) {
        const cat = STATUTORY_FORMAT_CATEGORY_ORDER.includes(item.category) ? item.category : 'other';
        (grouped[cat] = grouped[cat] || []).push(item);
    });
    STATUTORY_FORMAT_CATEGORY_ORDER.forEach(function (cat) {
        const catItems = grouped[cat];
        if (!catItems || !catItems.length) return;
        const $section = $(`
            <div class="statutory-format-category-section mb-4">
                <h6 class="text-uppercase text-secondary small fw-bold mb-2" data-i18n="category_${cat}">${langData['category_' + cat] || cat}</h6>
                <div class="row g-3"></div>
            </div>
        `);
        const $row = $section.find('.row');
        catItems.forEach(function (item) {
            $row.append(renderStatutoryFormatCard(item));
        });
        $container.append($section);
    });
    updateText($container[0]);
}
function renderStatutoryFormatCard(item) {
    if (!item.has_version_picker) {
        // T049: informational-only row -- exactly ONE implementation exists, so a version picker
        // (implying a real choice) would be worse UX than none. Still shown so the checklist is
        // complete, not just the 2 forms that happen to have a picker.
        const verifiedBadge = item.is_verified
            ? `<span class="badge bg-success-subtle text-success mt-2" data-i18n="verified">${langData['verified'] || 'Verified'}</span>`
            : `<span class="badge bg-warning-subtle text-warning mt-2" data-i18n="draft_not_verified">${langData['draft_not_verified'] || 'DRAFT — not verified'}</span>`;
        return $(`
            <div class="col-md-6">
                <div class="card-surface p-3 h-100 d-flex flex-column bg-light bg-opacity-50">
                    <h6 class="fw-bold mb-2">${escapeHtml(statutoryFormLabel(item))}</h6>
                    <p class="text-muted small mb-0" data-i18n="statutory_format_no_version_choice">${langData['statutory_format_no_version_choice'] || 'Single implementation -- no format version to choose.'}</p>
                    <div class="mt-auto pt-2">${verifiedBadge}</div>
                </div>
            </div>
        `);
    }
    window.__statutoryFormVersionsCache[item.form_code] = item.versions;
    // 2026-08-29, real bug found and fixed (spotted from a live screenshot): the seed
    // name_th/name_en for a DRAFT version already spell out "ยังไม่ยืนยัน..." in the label
    // itself, and this used to ALSO append "(DRAFT — not verified)" after it -- redundant,
    // cluttered text ("...ยังไม่ยืนยันกับกรมสรรพากรอย่างเป็นทางการ) (ฉบับร่าง — ยังไม่ยืนยัน)"). The
    // colored badge below the dropdown already conveys verified/draft status clearly on its
    // own -- the option text now shows just the plain name.
    const options = item.versions.map(function (v) {
        const selected = v.id === item.selected_version_id ? 'selected' : '';
        return `<option value="${v.id}" ${selected}>${escapeHtml(statutoryVersionLabel(v))}</option>`;
    }).join('');
    const selectedVersion = item.versions.find(v => v.id === item.selected_version_id);
    const verifiedBadge = selectedVersion && !selectedVersion.is_verified
        ? `<span class="badge bg-warning-subtle text-warning mt-2" data-i18n="draft_not_verified">${langData['draft_not_verified'] || 'DRAFT — not verified'}</span>`
        : (selectedVersion ? `<span class="badge bg-success-subtle text-success mt-2" data-i18n="verified">${langData['verified'] || 'Verified'}</span>` : '');
    const $card = $(`
        <div class="col-md-6">
            <div class="card-surface p-3 h-100 d-flex flex-column">
                <h6 class="fw-bold mb-2">${escapeHtml(statutoryFormLabel(item))}</h6>
                <select class="form-select statutory-format-version-select mb-2" data-form-code="${item.form_code}"></select>
                <div class="statutory-format-badge-wrap">${verifiedBadge}</div>
                <div class="text-end mt-auto pt-2">
                    <button type="button" class="btn btn-primary btn-sm statutory-format-save-btn" data-form-code="${item.form_code}">
                        <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">${langData['save'] || 'Save'}</span>
                    </button>
                </div>
            </div>
        </div>
    `);
    $card.find('.statutory-format-version-select').html(options);
    return $card;
}
$(document).on('change', '.statutory-format-version-select', function () {
    const $card = $(this).closest('.card-surface');
    const versions = window.__statutoryFormVersionsCache && window.__statutoryFormVersionsCache[$(this).data('form-code')];
    // Toggle the verified/draft badge live as the selection changes -- avoids a full re-fetch just
    // to reflect a client-side dropdown change before Save is even clicked.
    if (!versions) return;
    const selected = versions.find(v => String(v.id) === String($(this).val()));
    const $badgeWrap = $card.find('.statutory-format-badge-wrap');
    if (selected) {
        $badgeWrap.html(selected.is_verified
            ? `<span class="badge bg-success-subtle text-success mt-2" data-i18n="verified">${langData['verified'] || 'Verified'}</span>`
            : `<span class="badge bg-warning-subtle text-warning mt-2" data-i18n="draft_not_verified">${langData['draft_not_verified'] || 'DRAFT — not verified'}</span>`);
    }
});
$(document).on('click', '.statutory-format-save-btn', function () {
    const formCode = $(this).data('form-code');
    const $select = $(`.statutory-format-version-select[data-form-code="${formCode}"]`);
    const versionId = $select.val();
    if (!versionId) return;
    const $btn = $(this);
    $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/statutory-format-version.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ form_code: formCode, version_id: versionId }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
