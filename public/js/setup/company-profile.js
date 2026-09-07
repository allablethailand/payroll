let COUNTRY_MASTER_CONFIG = null;
const $pane = $('#setup-pane');
$(document).ready(function () {
    loadCountryConfig(function () {
        initPage('p1');
    });
});
$(document).on('click', '.setup-tabs .setup-menu', function () {
    const page = $(this).data('page');
    $('.setup-tabs .setup-menu').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');

    initPage(page);
});
function showCpLogoPreview(path) {
    if (path) {
        $('#cpLogoPreviewImg').attr('src', `${BASE_URL}/${path}`).removeClass('d-none');
        $('#cpLogoPlaceholder').addClass('d-none');
        $('#cpLogoRemoveBtn').removeClass('d-none');
    } else {
        $('#cpLogoPreviewImg').attr('src', '').addClass('d-none');
        $('#cpLogoPlaceholder').removeClass('d-none');
        $('#cpLogoRemoveBtn').addClass('d-none');
    }
}
$(document).on('change', '#cp_logo_file', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/company.upload-logo`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('input[name="logo_path"]').val(res.logo_path);
                $('input[name="logo_file_size"]').val(res.file_size || '');
                showCpLogoPreview(res.logo_path);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
    $(this).val('');
});
// Client-side only -- clears the hidden field so Save persists logo_path=null. The uploaded file
// itself isn't deleted from disk (same convention as Payslip Template/Employment Certificate's own
// logo fields -- re-uploading there just as silently orphans the old file too, no cleanup job exists
// anywhere in the app yet for any of the 3).
$(document).on('click', '#cpLogoRemoveBtn', function () {
    $('input[name="logo_path"]').val('');
    $('input[name="logo_file_size"]').val('');
    showCpLogoPreview(null);
});

/* ---------- Authorized Signature (2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized
   Signatory Name หรือสามารถเซ็นต์สดผ่านหน้าจอได้") -- upload-file path mirrors Company Logo above
   exactly; the live signature pad is a plain <canvas> with hand-written mouse/touch drawing (no new
   dependency, same precedent as Employment Certificate Template's own hand-rolled canvas
   interactions) that exports to a PNG Blob and posts through the SAME uploadSignature() endpoint a
   file-picker upload would, so the rest of this file (preview/hidden-field/remove) doesn't need to
   know which input method produced the image. ---------- */
function showCpSignaturePreview(path) {
    if (path) {
        $('#cpSignaturePreviewImg').attr('src', `${BASE_URL}/${path}`).removeClass('d-none');
        $('#cpSignaturePlaceholder').addClass('d-none');
        $('#cpSignatureRemoveBtn').removeClass('d-none');
    } else {
        $('#cpSignaturePreviewImg').attr('src', '').addClass('d-none');
        $('#cpSignaturePlaceholder').removeClass('d-none');
        $('#cpSignatureRemoveBtn').addClass('d-none');
    }
}
function uploadCpSignatureBlob(blob) {
    const formData = new FormData();
    formData.append('file', blob, 'signature.png');
    $.ajax({
        url: `${BASE_URL}/api/company.upload-signature`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('input[name="signature_path"]').val(res.signature_path);
                $('input[name="signature_file_size"]').val(res.file_size || '');
                showCpSignaturePreview(res.signature_path);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
}
$(document).on('change', '#cp_signature_file', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    uploadCpSignatureBlob(file);
    $(this).val('');
});
// Client-side only -- clears the hidden field so Save persists signature_path=null, same
// no-disk-cleanup convention as #cpLogoRemoveBtn above.
$(document).on('click', '#cpSignatureRemoveBtn', function () {
    $('input[name="signature_path"]').val('');
    $('input[name="signature_file_size"]').val('');
    showCpSignaturePreview(null);
});

let cpSignaturePadCtx = null;
let cpSignaturePadDrawing = false;
let cpSignaturePadHasStrokes = false;
function cpSignaturePadPos(canvas, e) {
    const rect = canvas.getBoundingClientRect();
    const point = (e.touches && e.touches[0]) ? e.touches[0] : e;
    return {
        x: (point.clientX - rect.left) * (canvas.width / rect.width),
        y: (point.clientY - rect.top) * (canvas.height / rect.height)
    };
}
function initCpSignaturePad() {
    const canvas = document.getElementById('cpSignaturePadCanvas');
    if (!canvas) return;
    cpSignaturePadCtx = canvas.getContext('2d');
    cpSignaturePadCtx.fillStyle = '#ffffff';
    cpSignaturePadCtx.fillRect(0, 0, canvas.width, canvas.height);
    cpSignaturePadCtx.lineWidth = 2.5;
    cpSignaturePadCtx.lineCap = 'round';
    cpSignaturePadCtx.strokeStyle = '#1a1a1a';
    cpSignaturePadHasStrokes = false;
    const startDraw = function (e) {
        e.preventDefault();
        cpSignaturePadDrawing = true;
        const p = cpSignaturePadPos(canvas, e);
        cpSignaturePadCtx.beginPath();
        cpSignaturePadCtx.moveTo(p.x, p.y);
    };
    const moveDraw = function (e) {
        if (!cpSignaturePadDrawing) return;
        e.preventDefault();
        const p = cpSignaturePadPos(canvas, e);
        cpSignaturePadCtx.lineTo(p.x, p.y);
        cpSignaturePadCtx.stroke();
        cpSignaturePadHasStrokes = true;
    };
    const endDraw = function () { cpSignaturePadDrawing = false; };
    canvas.onmousedown = startDraw;
    canvas.onmousemove = moveDraw;
    canvas.onmouseup = endDraw;
    canvas.onmouseleave = endDraw;
    canvas.ontouchstart = startDraw;
    canvas.ontouchmove = moveDraw;
    canvas.ontouchend = endDraw;
}
$(document).on('click', '#cpDrawSignatureBtn', function () {
    new bootstrap.Modal(document.getElementById('cpSignaturePadModal')).show();
});
$('#cpSignaturePadModal').on('shown.bs.modal', function () { initCpSignaturePad(); });
$(document).on('click', '#cpSignaturePadClearBtn', function () { initCpSignaturePad(); });
$(document).on('click', '#cpSignaturePadSaveBtn', function () {
    const canvas = document.getElementById('cpSignaturePadCanvas');
    if (!canvas) return;
    if (!cpSignaturePadHasStrokes) {
        showWarning(langData['draw_signature_hint'] || 'Draw with your mouse or finger, then click Save.');
        return;
    }
    canvas.toBlob(function (blob) {
        if (!blob) return;
        uploadCpSignatureBlob(blob);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('cpSignaturePadModal')).hide();
    }, 'image/png');
});
$(document).on('change', '#registered_country', function () {
    renderCountrySpecificForm($(this).val());
});
function loadCountryConfig(callback) {
    if (COUNTRY_MASTER_CONFIG !== null) {
        if (typeof callback === 'function') callback();
        return;
    }
    $.getJSON(`${BASE_URL}/public/json/country-config.json`, function (data) {
        COUNTRY_MASTER_CONFIG = data;
        if (typeof callback === 'function') callback();
    }).fail(function () {
        COUNTRY_MASTER_CONFIG = {};
        if (typeof callback === 'function') callback();
    });
}
let activePage = '';
function initPage(page) {
    activePage = page;
    switch (page) {
        case 'p1':
            loadCountryConfig(function () {
                if (activePage !== 'p1') {
                    return;
                }
                initProfilePane();
            });
            break;
        case 'p2':
            initBankPane();
            break;
        case 'p3':
            initStructurePane();
            break;
    }
}
function initProfilePane() {
    const html = $('#tmpl-profile-pane').html();
    $pane.html(html);
    const defaultCountry = 'TH';
    $('#registered_country').val(defaultCountry);
    renderCountrySpecificForm(defaultCountry);
    updateText($pane[0]);
    initSelect2Remote('.select2-remote');
    if (typeof initSelect2 === 'function') {
        initSelect2('#fiscal_year_start_month', { mode: 'static' });
        initSelect2('#base_currency', { mode: 'static' });
    }
    // 2026-09-04, Backlog Phase 9, T050 -- shared widget, see origami-sync-widget.js's own
    // docblock. #tmpl-profile-pane is re-rendered fresh every time this pane is opened, so
    // #cpCompanySyncBtnWrap is a brand-new empty container each call -- always (re-)init here
    // rather than guarding against a "second init", the widget's own idempotency check is for
    // DataTable initComplete re-fires on the SAME container, not this case.
    if (typeof initOrigamiSyncButton === 'function') {
        initOrigamiSyncButton({
            container: $('#cpCompanySyncBtnWrap'),
            url: `${BASE_URL}/api/company.sync-origami`,
            entityLabel: '',
            confirmTitle: langData['confirm_sync_company_title'] || 'Sync from Origami?',
            confirmMessage: langData['confirm_sync_company_message'] || 'This will overwrite the company name, tax ID, address, and logo with the current data from Origami. Any manual edits to these fields will be replaced. Continue?',
            onSuccess: function () { initCompanyData(); }
        });
    }
    initCompanyData();
}
function renderCountrySpecificForm(countryCode) {
    const $container = $('#dynamic_statutory_fields_container');
    if (!$container.length) return;
    const savedValues = {};
    $container.find('input, select, textarea').each(function () {
        const name = $(this).attr('name');
        if (name) savedValues[name] = $(this).val();
    });
    $container.empty();
    const config = (COUNTRY_MASTER_CONFIG && COUNTRY_MASTER_CONFIG[countryCode]) ? COUNTRY_MASTER_CONFIG[countryCode] : null;
    if (!config) {
        $('#tax_id_label').attr('data-i18n', 'tax.default_label').text('Tax ID / EIN');
        if (typeof updateText === 'function') updateText('#tax_id_label');
        return;
    }
    $('#tax_id_label').attr('data-i18n', config.taxLabelKey).text(config.taxDefaultText);
    const $baseCurrency = $('#base_currency');
    if ($baseCurrency.length) $baseCurrency.val(config.currency).trigger('change');
    const $companyTimezone = $('#company_timezone');
    if ($companyTimezone.length) $companyTimezone.val(config.timezone).trigger('change');
    let fieldsHtml = '';
    if (config.fields && Array.isArray(config.fields)) {
        config.fields.forEach(field => {
            const requiredAttr = field.required ? 'required' : '';
            const savedVal = savedValues[field.name] !== undefined ? savedValues[field.name] : '';
            fieldsHtml += `
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="${field.labelKey}">${field.labelDefault}</span> ${requiredMark(field.required)}
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control ${requiredAttr}" name="${field.name}" value="${savedVal}">
                </div>
            `;
        });
    }
    $container.append(fieldsHtml);
    if (typeof updateText === 'function') {
        updateText('#tax_id_label');
        updateText($container[0]);
    }
}
let currentCompanyAddresses = { th: '', en: '' };
function initCompanyData() {
    $.ajax({
        url: `${BASE_URL}/api/company.get`,
        method: 'POST',
        dataType: 'json',
        success: function (response) {
            if (response.status && response.data) {
                const data = response.data;
                currentCompanyAddresses.th = data.address_display_th || '';
                currentCompanyAddresses.en = data.address_display_en || '';
                $('#search_address').val(currentCompanyAddresses[currentLang]);
                $('#master_address_id').val(data.master_address_id || '');
                const countryCode = data.registered_country || 'TH';
                const $countrySelect = $('#registered_country');
                if ($countrySelect.hasClass('select2-hidden-accessible')) {
                    $countrySelect.empty();
                    const countryText = (currentLang === 'th') ? data.country_data.text_th : data.country_data.text_en;
                    const newOption = new Option(countryText, countryCode, true, true);
                    $(newOption).data('data', {
                        id: countryCode,
                        text: countryText,
                        text_th: data.country_data.text_th,
                        text_en: data.country_data.text_en
                    });
                    $countrySelect.append(newOption).trigger('change.select2');
                } else {
                    $countrySelect.val(countryCode);
                }
                renderCountrySpecificForm(countryCode);
                // renderCountrySpecificForm() just re-suggested a currency FROM the country (dead
                // code from before #base_currency existed, see that field's own view comment) -- the
                // company's own saved value always wins over that suggestion on a real data load.
                $('#base_currency').val(data.currency_code || 'THB').trigger('change');
                $('input[name="global_tax_id"]').val(data.global_tax_id || '');
                $('input[name="company_legal_name"]').val(data.company_legal_name || '');
                $('input[name="local_name"]').val(data.local_name || '');
                $('#fiscal_year_start_month').val(data.fiscal_year_start_month || 1).trigger('change');
                $('#prorate_divisor_days').val(data.prorate_divisor_days || 30);
                $('input[name="address_line_1"]').val(data.address_line_1 || '');
                $('input[name="address_line_2"]').val(data.address_line_2 || '');
                $('input[name="authorized_signatory_name"]').val(data.authorized_signatory_name || '');
                $('input[name="logo_path"]').val(data.logo_path || '');
                $('input[name="logo_file_size"]').val(data.logo_file_size || '');
                showCpLogoPreview(data.logo_path || null);
                $('input[name="signature_path"]').val(data.signature_path || '');
                $('input[name="signature_file_size"]').val(data.signature_file_size || '');
                showCpSignaturePreview(data.signature_path || null);
                if (data.statutory_data && typeof data.statutory_data === 'object') {
                    Object.keys(data.statutory_data).forEach(key => {
                        const $field = $(`[name="${key}"]`);
                        if ($field.length) {
                            $field.val(data.statutory_data[key]);
                        }
                    });
                }
                if (typeof updateText === 'function') {
                    updateText($pane[0]);
                }
            } else {
                const defaultCountry = 'TH';
                $('#registered_country').val(defaultCountry).trigger('change');
                renderCountrySpecificForm(defaultCountry);
                if (typeof updateText === 'function') {
                    updateText($pane[0]);
                }
            }
        },
        error: function (xhr, status, error) {
            console.error('Failed to load company profile data:', error);
        }
    });
}
$(document).on('click', '.save-company-profile', function () {
    let errors = [];
    const $btn = $(this);
    $pane.find('.required').each(function () {
        let value = $(this).val()?.trim() || '';
        if (!value) {
            $(this).addClass('is-invalid');
            errors.push(this.name || this.id);
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    if (errors.length) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        $('.is-invalid').first().focus();
        return;
    }
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    let formData = {
        registered_country: $('#registered_country').val(),
        currency_code: $('#base_currency').val() || 'THB',
        global_tax_id: $('input[name="global_tax_id"]').val()?.trim() || '',
        company_legal_name: $('input[name="company_legal_name"]').val()?.trim() || '',
        local_name: $('input[name="local_name"]').val()?.trim() || '',
        fiscal_year_start_month: parseInt($('#fiscal_year_start_month').val(), 10) || 1,
        prorate_divisor_days: parseInt($('#prorate_divisor_days').val(), 10) || 30,
        address_line_1: $('input[name="address_line_1"]').val()?.trim() || '',
        address_line_2: $('input[name="address_line_2"]').val()?.trim() || '',
        master_address_id: $('input[name="master_address_id"]').val() || null,
        authorized_signatory_name: $('input[name="authorized_signatory_name"]').val()?.trim() || '',
        logo_path: $('input[name="logo_path"]').val() || null,
        logo_file_size: $('input[name="logo_file_size"]').val() || null,
        signature_path: $('input[name="signature_path"]').val() || null,
        signature_file_size: $('input[name="signature_file_size"]').val() || null,
        statutory_data: {}
    };
    $('#dynamic_statutory_fields_container input').each(function () {
        const name = $(this).attr('name');
        if (name) {
            formData.statutory_data[name] = $(this).val()?.trim() || '';
        }
    });
    $.ajax({
        url: `${BASE_URL}/api/company.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(formData),
        success: function (res) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span data-i18n="save">Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                if (typeof showSuccess === 'function') {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                } else {
                    alert(res.message || langData['save_success'] || 'Saved successfully.');
                }
                initCompanyData();
            } else {
                if (typeof showWarning === 'function') {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                } else {
                    alert(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            }
        },
        error: function (xhr, status, error) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span data-i18n="save">Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            console.error('Save error:', error);
            if (typeof showWarning === 'function') {
                showWarning(langData['save_failed'] || 'Failed to save data.');
            } else {
                alert(langData['save_failed'] || 'Failed to save data.');
            }
        }
    });
});
$(document).on('click', '.cancel-company-profile', function () {
    const title = langData['confirm_cancel_title'] || 'Confirm Cancel';
    const message = langData['confirm_cancel_message'] || 'Are you sure you want to cancel this operation?';
    showConfirm(
        title,
        message,
        function () {
            initPage('p1');
        },
        function () {
        }
    );
});
let activeBankSubPage = 'accounts';
function initBankPane() {
    $pane.html($('#tmpl-bank-pane').html());
    updateText($pane[0]);
    activeBankSubPage = 'accounts';
    initBankSubPage('accounts');
}
/** 2026-08-29: 2nd sub-tab ("Bank File Format") added alongside the existing Bank Accounts list --
 *  see tmpl-bank-format-subpane's own comment. Mirrors initStructurePane()'s own sub-tab dispatch
 *  pattern (data-page attribute + a small switch), just scoped with its own data-bank-page
 *  attribute/id prefix so it doesn't collide with Organizational Structure's identical-looking
 *  pill markup elsewhere on this same page. */
function initBankSubPage(page) {
    activeBankSubPage = page;
    const $subPane = $('#bank-sub-pane');
    if (page === 'format') {
        $subPane.html($('#tmpl-bank-format-subpane').html());
        updateText($subPane[0]);
        initBffFormatList();
    } else {
        $subPane.html($('#tmpl-bank-accounts-subpane').html());
        updateText($subPane[0]);
        initBankAccountTable();
    }
}
$(document).on('click', '#bank-sub-tab-accounts, #bank-sub-tab-format', function () {
    $('#bank-sub-tab-accounts, #bank-sub-tab-format').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');
    initBankSubPage($(this).data('bank-page'));
});
function maskAccountNo(accountNo) {
    if (!accountNo) return '-';
    const digits = String(accountNo).replace(/\D/g, '');
    if (digits.length <= 4) return accountNo;
    return `••••-${digits.slice(-4)}`;
}
function initBankAccountTable() {
    const tableId = '#tb_bank_account';
    if ($.fn.DataTable.isDataTable(tableId)) {
        $(tableId).DataTable().ajax.reload(null, false);
        return;
    }
    structureTables['bank_account'] = $(tableId).DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- default sort points to column 1
        // (bank) now that column 0 is the non-orderable status switch. BankAccountModel::list()'s
        // own sortColumns[1] already resolves to `mb.bank_name_th` (the bank column's real content),
        // so this is not a regression the way PED Type's own equivalent change was (see that
        // table's own comment) -- if anything a slight improvement over the old default (colIndex 0
        // resolved to a bare `ba.id` sort, not a meaningful column to sort by at all).
        order: [[1, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/bank_account.list`,
            type: 'POST',
            data: function (d, settings) {
                // Built from `settings` (not the outer `structureTables['bank_account']` variable) --
                // see table-column-filter.js's getColumnFilterValues() docblock for why.
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        columns: [
            // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- status switch is the first
            // column now, same shared mechanism as every other table already converted.
            { data: 'status', orderable: false, render: (d, t, row) => renderStatusToggleHtml(row.id, d === 'active', '/api/bank_account.toggle-status') },
            {
                data: null,
                render: function (data, type, row) {
                    const name = (currentLang === 'th') ? row.bank_name_th : row.bank_name_en;
                    return `${row.bank_code || ''} - ${name || ''}`;
                }
            },
            {
                data: 'account_no',
                render: function (data) {
                    return maskAccountNo(data);
                }
            },
            { data: 'account_name' },
            // orderable:false -- sidesteps BankAccountModel::list()'s own server-side sortColumns
            // index map entirely rather than risking shifting indices for the columns after it.
            { data: 'company_code', defaultContent: '-', orderable: false },
            { data: 'branch_name', defaultContent: '-' },
            {
                data: 'account_type',
                render: function (data) {
                    const key = data === 'current' ? 'current' : 'savings';
                    return `<span data-i18n="${key}">${langData[key] || key}</span>`;
                }
            },
            {
                data: 'is_default',
                className: 'text-center',
                render: function (data) {
                    return data ? `<span class="badge bg-primary" data-i18n="default">Default</span>` : `-`;
                }
            },
            {
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into
                // the Responsive expand row.
                data: null,
                orderable: false,
                className: 'text-center all',
                // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                // ".btn-circle-action" section) replace the old adjacent .btn-group.
                render: (data, type, row) => `
                    <div class="d-flex gap-1 justify-content-center">
                        <button class="btn btn-link btn-circle-action text-warning btn-open-modal manage-bank_account" data-action="edit" data-type="bank_account" data-id="${row.id}" data-i18n-title="edit">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="btn btn-link btn-circle-action text-danger btn-delete-item delete-bank_account" data-type="bank_account" data-id="${row.id}" data-i18n-title="delete">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                `
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            let self = this.api();
            let $wrapper = $(self.table().container());
            let $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.manage-bank_account').length === 0) {
                let btn = `
                    <button class="btn btn-primary btn-open-modal manage-bank_account ms-1" data-action="add" data-type="bank_account" data-id="">
                        <i class="fa-solid fa-plus me-2"></i><span data-i18n="bank_account">${langData['bank_account'] || 'Bank Account'}</span>
                    </button>
                `;
                $searchDiv.append(btn);
            }
            updateText($wrapper[0]);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, server mode.
            // 2026-09-02, real bug found and fixed (explicit report: "พอเพิ่ม switch เข้าไปแล้ว column
            // เลื่อนครับ") -- this map was ALREADY drifted from the real column layout before
            // today's status-switch change too (index 3 was labeled 'branch_name' here but the
            // actual column at index 3 was `company_code`, added in an earlier round without this
            // map ever being updated -- the exact same bug class the user is reporting, found while
            // fixing this table). Rebuilt against the table's real current column order: excludes
            // the interactive status SWITCH (0), the masked account_no (2, not the raw filterable
            // value), `company_code` (4 -- not yet backend-filterable, no exprMap entry for it in
            // BankAccountModel::columnFilterExprMap(), out of scope to add here), the boolean
            // is_default icon (7), and actions (8).
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 1, key: 'bank_name' },
                    { index: 3, key: 'account_name' },
                    { index: 5, key: 'branch_name' },
                    { index: 6, key: 'account_type' },
                ],
                fetchValues: function (key, done) {
                    $.ajax({
                        url: `${BASE_URL}/api/bank_account.column-values`,
                        method: 'POST',
                        data: { column: key, column_filters: getColumnFilterValues(self) },
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { self.ajax.reload(null, false); }
            });
        },
        drawCallback: function () {
            getTableLang();
            let self = this.api();
            let $wrapper = $(self.table().container());
            updateText($wrapper[0]);
        }
    });
}

function initStructurePane() {
    $pane.html($('#tmpl-structure-pane').html());
    updateText($pane[0]); 
    initStructure('p1');
}
$(document).on('click', '#setup-pane .structure-menu', function () {
    // 2026-08-29, real bug found and fixed (explicit bug report: "คลิกแล้วไม่เห็นธนาคารครับ" -- Bank
    // File Format sub-tab showed no data on click): this handler is delegated on `document` and
    // scoped only to `#setup-pane .structure-menu` -- Bank File Format's own sub-tab pills
    // (#bank-sub-tab-accounts/#bank-sub-tab-format) intentionally reuse the SAME .structure-menu
    // class for styling (per this app's own Tab convention -- reuse the class, don't invent a new
    // one) and also live inside #setup-pane, so BOTH this handler and Bank's own dedicated one
    // fired on every click. This one used to call initStructure($(this).data('page')) unconditionally
    // -- Bank's buttons carry data-bank-page, not data-page, so `page` came through undefined; that
    // alone didn't crash (initStructure() bails out early when #structure-pane-content isn't in the
    // DOM), but it was still doing pointless work and toggling .active/aria-selected on Bank's own
    // buttons a beat before Bank's real handler ran. Guarded so this Organizational-Structure-only
    // handler only ever reacts to an element that's actually one of ITS OWN tabs (real data-page).
    const page = $(this).data('page');
    if (page === undefined) {
        return;
    }
    $('#setup-pane .structure-menu').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');
    initStructure(page);
});
let structureTables = {};
function initStructure(page) {
    const $structureContent = $('#structure-pane-content');
    if (!$structureContent.length) return;
    switch(page) {
        case 'p1':
            $structureContent.html($('#tmpl-branch-pane').html());
            initStructureTable('branch', '#tb_branch');
            break;
        case 'p2':
            $structureContent.html($('#tmpl-role-pane').html());
            initStructureTable('role', '#tb_role');
            break;
        case 'p3':
            $structureContent.html($('#tmpl-department-pane').html());
            initStructureTable('department', '#tb_department');
            break;
        case 'p4':
            $structureContent.html($('#tmpl-position-pane').html());
            initStructureTable('position', '#tb_position');
            break;
        case 'p5':
            $structureContent.html($('#tmpl-rank-pane').html());
            initStructureTable('rank', '#tb_rank');
            break;
        case 'p6':
            // 2026-08-31: was tmpl-permission-pane (RBAC Permission Matrix + this section together)
            // -- the Permission Matrix moved out to its own standalone page (setup/permissions),
            // this pill now shows only the unrelated Notification Preferences by Role section that
            // happened to share the same tab before.
            $structureContent.html($('#tmpl-notification-role-pane').html());
            updateText($structureContent[0]);
            if (typeof initNotificationRoleMatrix === 'function') { initNotificationRoleMatrix(); }
            break;
        // 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้เป็นการ
        // เพิ่มการตั้งค่าเช่นเดียวกับ Department" -- 7th Organization Structure sub-tab.
        case 'p7':
            $structureContent.html($('#tmpl-team-pane').html());
            initStructureTable('team', '#tb_team');
            break;
    }
}
// 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout,
// server mode (see table-column-filter.js's own docblock). Frontend column KEY -> DataTable column
// INDEX per structure type -- must stay in sync with getStructureColumns()'s own per-type array
// below, and with CompanyProfileController::structureFilterMap()'s matching KEY set on the backend
// (the KEY strings are what travel over the wire in `column_filters`, matched on both sides).
// Excludes boolean-icon columns (is_default/lock_stamp/salary_access/ot_eligible) and
// computed/composite columns (rank's own salary_min-salary_max range) -- same exclusion policy as
// every other table in this rollout -- plus the actions column, always last.
// 2026-09-02, Platform Hardening Phase 1.1 -- every index below shifted +1 (status switch inserted
// as the new column 0 in getStructureColumns(), see that function's own comment), and each type's
// own `status` entry moved to `index: 0` to match its new position instead of wherever it used to
// sit near the end.
const STRUCTURE_FILTER_COLUMNS = {
    branch: [
        { index: 0, key: 'status' }, { index: 1, key: 'branch_code' }, { index: 2, key: 'name' }, { index: 3, key: 'tax_branch_id' },
        { index: 4, key: 'sso_branch_code' }, { index: 6, key: 'location' },
    ],
    role: [
        { index: 0, key: 'status' }, { index: 1, key: 'name' },
    ],
    department: [
        { index: 0, key: 'status' }, { index: 1, key: 'department_code' }, { index: 2, key: 'name' }, { index: 3, key: 'cost_center' },
    ],
    position: [
        { index: 0, key: 'status' }, { index: 1, key: 'position_code' }, { index: 2, key: 'name' }, { index: 3, key: 'position_allowance' },
    ],
    rank: [
        { index: 0, key: 'status' }, { index: 1, key: 'rank_code' }, { index: 2, key: 'name' },
    ],
    team: [
        { index: 0, key: 'status' }, { index: 1, key: 'team_code' }, { index: 2, key: 'name' }, { index: 3, key: 'client_name' },
    ],
};
// 2026-09-02, Platform Hardening Phase 1.1 -- ONE delegated listener covers all 6 structure tables'
// reload-after-toggle, instead of 6 separate bindings -- the endpoint each switch used
// (/api/structure.<type>.toggle-status, see statusRender() above) already tells us which `type`
// (and therefore which `structureTables[type]` entry) to reload.
$(document).on('statusToggle:success', '#tb_branch, #tb_role, #tb_department, #tb_position, #tb_rank, #tb_team', function (e) {
    const endpoint = $(e.target).data('endpoint') || '';
    const match = /^\/api\/structure\.([a-z_]+)\.toggle-status$/.exec(endpoint);
    const type = match ? match[1] : null;
    if (type && structureTables[type]) {
        structureTables[type].ajax.reload(null, false);
    }
});
// Bank Account's own endpoint (/api/bank_account.toggle-status) doesn't match the `structure.<type>`
// prefix the listener above parses, so it gets its own small listener instead of overloading that
// regex for one exception.
$(document).on('statusToggle:success', '#tb_bank_account', function () {
    if (structureTables['bank_account']) {
        structureTables['bank_account'].ajax.reload(null, false);
    }
});
function initStructureTable(type, tableId) {
    if ($.fn.DataTable.isDataTable(tableId)) {
        $(tableId).DataTable().ajax.reload(null, false);
        return;
    }
    structureTables[type] = $(tableId).DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        // 2026-09-02, Platform Hardening Phase 1.1 -- was `[[0, 'asc']]` back when column 0 was
        // always the entity's own code/name column; now column 0 is the (non-orderable) status
        // switch, so the default landing sort moves to column 1 (still each type's own code/name
        // column in every case, see getStructureColumns()'s own new column order).
        order: [[1, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/structure.${type}`,
            type: "POST",
            data: function (d, settings) {
                d.status = $('#filter_status').val() || 'Active';
                // Built from `settings` (DataTables' own 2nd arg to ajax.data), NOT the outer
                // `structureTables[type]` variable -- same "first request runs synchronously during
                // construction, before that assignment completes" reasoning as Employee List's own
                // fix, see table-column-filter.js's getColumnFilterValues() docblock.
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        columns: getStructureColumns(type),
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            let self = this.api();
            let $wrapper = $(self.table().container());
            let $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find(`.manage-${type}`).length === 0) {
                let btn = `
                    <button class="btn btn-primary btn-open-modal manage-${type} ms-1" data-action="add" data-type="${type}" data-id="">
                        <i class="fa-solid fa-plus me-1"></i>
                        <span data-i18n="${type}">${type}</span>
                    </button>
                `;
                $searchDiv.append(btn);
            }
            // 2026-08-28, explicit request: "ส่วนของ Department หรือข้อมูลที่ดึง Filter ได้ตอนนี้
            // เพิ่มปุ่มให้ Sync ได้ด้วย แต่...ถ้าไม่ใช่บริษัทที่มาจาก Origami ปุ่ม Sync จะไม่ขึ้น" -- see
            // public/js/setup/org-structure-sync.js for the picker this opens (review-first:
            // fetch candidates -> tick New/Existing -> apply only what's selected). Department/
            // Position/Team only -- their candidate source (OrigamiEmployeeCandidateClient::
            // fetchFilterOptions()) has no `branches` key at all (confirmed by reading it), so
            // Branch can't use this same picker. Role/Rank genuinely have no Origami-side
            // equivalent of any kind. Gated on IS_ORIGAMI_HR_LINKED same as below.
            const ORG_SYNC_ENTITY_TYPES = ['department', 'position', 'team'];
            if (ORG_SYNC_ENTITY_TYPES.includes(type) && typeof IS_ORIGAMI_HR_LINKED !== 'undefined' && IS_ORIGAMI_HR_LINKED) {
                if ($searchDiv.find('.btn-open-org-sync').length === 0) {
                    let syncBtn = `
                        <button type="button" class="btn btn-outline-secondary btn-open-org-sync ms-1" data-entity-type="${type}">
                            <i class="fa-solid fa-rotate me-1"></i><span data-i18n="employee_sync_button">Sync from Origami</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-open-org-sync-log ms-1" data-entity-type="${type}">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="employee_sync_log_button">Sync Log</span>
                        </button>
                    `;
                    $searchDiv.append(syncBtn);
                }
            }
            // 2026-09-04, Backlog Phase 9, T050 -- Branch DOES have a real Origami-side master now
            // (api/hr/master/branches, wired via MasterDataSyncOrchestrator/BranchSyncer since
            // 2026-09-02) even though it can't use org-structure-sync.js's review-first picker
            // above (see that block's own comment) -- uses the simpler direct-overwrite shared
            // widget instead, same one Company Profile's own sync button uses.
            if (type === 'branch' && typeof IS_ORIGAMI_HR_LINKED !== 'undefined' && IS_ORIGAMI_HR_LINKED && typeof initOrigamiSyncButton === 'function') {
                initOrigamiSyncButton({
                    container: $searchDiv,
                    url: `${BASE_URL}/api/master-data-sync.sync-one`,
                    payload: { entity_type: 'branch' },
                    entityLabel: langData['branch'] || 'Branch',
                    onSuccess: function () { self.ajax.reload(null, false); }
                });
            }
            let $input = $searchDiv.find('input').off(`.${type}Search`);
            $input.on(`keypress.${type}Search`, function (e) {
                if (e.keyCode === 13) {
                    self.search(this.value).draw();
                }
                if (this.value === "") {
                    self.search(this.value).draw();
                }
            });
            updateText($wrapper[0]);
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: STRUCTURE_FILTER_COLUMNS[type] || [],
                fetchValues: function (key, done) {
                    $.ajax({
                        url: `${BASE_URL}/api/structure.column-values`,
                        method: 'POST',
                        data: { type: type, column: key, column_filters: getColumnFilterValues(self) },
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { self.ajax.reload(null, false); }
            });
        },
        drawCallback: function () {
            getTableLang(); 
            let self = this.api();
            let $wrapper = $(self.table().container());
            updateText($wrapper[0]);
        }
    });
}
// 2026-08-31, explicit request: Assign Employees modal -- attribute-safe escaping for the button's
// own data-label (a plain .text().html() escapes <,>,& but not the double quote a data- attribute
// needs, same bug class documented elsewhere in this app, e.g. escapeAttrEct() in
// employment-certificate-template.js).
// Maps a structureConfig() type to the *_name_th/*_name_en column prefix getLocaleText() below
// already reads for every OTHER column in this same table -- every one of the 6 types follows this
// exact "{type}_name_th"/"{type}_name_en" naming convention (confirmed against structureConfig()
// itself), so one small map covers all of them instead of a switch per type.
function structureNameFieldByType(type) {
    return `${type}_name`;
}
function getStructureColumns(type) {
    // 2026-08-31, explicit request: "เพิ่มปุ่มให้สามารถ Assign ได้...และมีอีกปุ่มสำหรับกด View เพื่อดูเฉพาะ
    // พนักงานที่อยู่ใน Master นั้น" -- ONE shared helper called for all 6 structure types (see the 6
    // getStructureColumns() call sites below), so adding these 2 buttons here applies everywhere
    // with zero per-type special-casing (same "one shared mechanism" precedent Edit/Delete already
    // established). Both open #structureAssignModal (public/js/setup/structure-assign.js), a fully
    // generic component driven by data-type/data-id/data-label -- View pre-filters to the
    // "employees in this Master" card only (same modal, different initial state).
    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
    // ".btn-circle-action" section) replace the old adjacent .btn-group.
    const getActionButtons = (row, type) => {
        const label = getLocaleText(row, structureNameFieldByType(type)) || '';
        return `
            <div class="d-flex gap-1 justify-content-center flex-wrap">
                <button class="btn btn-link btn-circle-action text-warning btn-open-modal manage-${type}" data-action="edit" data-type="${type}" data-id="${row.id}" data-i18n-title="edit">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn btn-link btn-circle-action text-primary btn-structure-assign" data-type="${type}" data-id="${row.id}" data-label="${escapeAttr(label)}" data-i18n-title="assign_employees">
                    <i class="fa-solid fa-user-plus"></i>
                </button>
                <button class="btn btn-link btn-circle-action text-secondary btn-structure-view-assigned" data-type="${type}" data-id="${row.id}" data-label="${escapeAttr(label)}" data-i18n-title="view_assigned_employees">
                    <i class="fa-solid fa-users"></i>
                </button>
                <button class="btn btn-link btn-circle-action text-danger btn-delete-item delete-${type}" data-type="${type}" data-id="${row.id}" data-i18n-title="delete">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
        `;
    };
    // 2026-09-02, Platform Hardening Phase 1.1, explicit request -- was a plain read-only badge
    // (status changed only via the Add/Edit modal's own `status` select field, which is now GONE
    // for these 6 entity types, see formSchemas' own comment on this same round). Takes `type`
    // explicitly (not read off the row) since this closure is shared across all 6 `case` branches
    // below, each needing its own `/api/structure.<type>.toggle-status` endpoint
    // (CompanyProfileModel::toggleStructureStatus(), one generic method for all 6). `orderable:
    // false` on the column itself (not here) -- see the column definitions' own comment for why.
    const statusRender = (structureType) => (data, t, row) => renderStatusToggleHtml(row.id, data === 'active', `/api/structure.${structureType}.toggle-status`);
    const getLocaleText = (row, field) => {
        return row[`${field}_${currentLang}`] || row[`${field}_th`] || row[field] || '-';
    };
    switch(type) {
        case 'branch':
            return [
                // 2026-09-02, Platform Hardening Phase 1.1 -- status switch is the first column now.
                // `orderable: false` here -- this table is serverSide:true, and an app-wide audit
                // found this file's own sortColumns index maps already had real, pre-existing drift
                // unrelated to this change (documented in project memory) -- inserting a new sortable
                // column here would need a full, separate audit/fix of every OTHER column's index
                // mapping too, out of scope for this round. Non-orderable sidesteps that risk
                // entirely without regressing anything that already sorted correctly.
                { data: "status", orderable: false, render: statusRender('branch') },
                { data: "branch_code" },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'branch_name')
                },
                { data: "tax_branch_id", defaultContent: "-" },
                { data: "sso_branch_code", defaultContent: "-" },
                {
                    data: "is_default",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<span class="badge bg-primary" data-i18n="default">Default</span>` : `-`;
                    }
                },
                { data: "location", defaultContent: "-" },
                {
                    data: "lock_stamp",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<i class="fa-solid fa-lock text-warning"></i>` : `<i class="fa-solid fa-lock-open text-muted"></i>`;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'branch')
                }
            ];
        case 'role':
            return [
                { data: "status", orderable: false, render: statusRender('role') },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'role_name')
                },
                {
                    data: "salary_access",
                    render: function (data) {
                        return data ? `
                            <span class="badge bg-info" data-i18n="allowed">Allowed</span>
                        ` : `
                            <span class="badge bg-secondary" data-i18n="restricted">Restricted</span>
                        `;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'role')
                }
            ];
        case 'department':
            return [
                { data: "status", orderable: false, render: statusRender('department') },
                { data: "department_code" },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'department_name')
                },
                { data: "cost_center", defaultContent: "-" },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'department')
                }
            ];
        case 'position':
            return [
                { data: "status", orderable: false, render: statusRender('position') },
                { data: "position_code" },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'position_name')
                },
                {
                    data: "position_allowance",
                    className: "text-end",
                    render: function (data) {
                        let amount = data ? parseFloat(data) : 0;
                        return amount.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'position')
                }
            ];
        case 'rank':
            return [
                { data: "status", orderable: false, render: statusRender('rank') },
                { data: "rank_code" },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'rank_name')
                },
                {
                    data: null,
                    className: 'text-end',
                    render: function (data, type, row) {
                        let min = row.salary_min ? parseFloat(row.salary_min).toLocaleString('th-TH') : '0';
                        let max = row.salary_max ? parseFloat(row.salary_max).toLocaleString('th-TH') : 'Max';
                        return `${min} - ${max}`;
                    }
                },
                {
                    data: "ot_eligible",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<span class="badge bg-info" data-i18n="yes">Yes</span>` : `<span class="badge bg-light text-dark" data-i18n="no">No</span>`;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'rank')
                }
            ];
        // 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้เป็น
        // การเพิ่มการตั้งค่าเช่นเดียวกับ Department" -- same shape as the 'department' case above,
        // plus client_name (the client/project this team is deployed to).
        case 'team':
            return [
                { data: "status", orderable: false, render: statusRender('team') },
                { data: "team_code" },
                {
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'team_name')
                },
                { data: "client_name", defaultContent: "-" },
                {
                    data: null,
                    orderable: false,
                    className: "text-center all",
                    render: (data, type, row) => getActionButtons(row, 'team')
                }
            ];
    }
}
const apiEndpointPrefix = {
    branch: 'structure.branch',
    role: 'structure.role',
    department: 'structure.department',
    position: 'structure.position',
    rank: 'structure.rank',
    team: 'structure.team',
    bank_account: 'bank_account'
};
const formSchemas = {
    branch: {
        fields: [
            { name: 'branch_code', label: 'branch_code', type: 'text', required: true },
            { name: 'branch_name_th', label: 'branch_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'branch_name_en', label: 'branch_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'tax_branch_id', label: 'tax_branch_id', type: 'text' },
            { name: 'sso_branch_code', label: 'sso_branch_code', type: 'text' },
            { name: 'location', label: 'location', type: 'text' },
            { name: 'is_default', label: 'default', type: 'checkbox' },
            { name: 'lock_stamp', label: 'lock_stamp', type: 'checkbox' }
            // 2026-09-02, Platform Hardening Phase 1.1, explicit request: "ย้าย 'สถานะ' ออกจาก modal
            // ไปไว้ที่แถวในตาราง" (same T014 precedent PayrollEarningDeductionTypeModel::save()
            // already established) -- `status` field removed from this modal for all 6 structure
            // entity types; the row-level toggle switch is now the only way to change it. Backend
            // (CompanyProfileModel::saveStructure()) preserves the existing status on every save from
            // here since this field no longer sends one at all -- see that method's own comment.
        ]
    },
    role: {
        fields: [
            { name: 'role_name_th', label: 'role_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'role_name_en', label: 'role_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'salary_access', label: 'salary_access', type: 'checkbox' }
            // 2026-08-28: can_process_payroll/can_approve_payroll/can_finalize_payroll checkboxes
            // used to live here (structure_roles booleans with no other UI). REMOVED 2026-09-03,
            // Platform Hardening Phase 3 -- those columns are dropped entirely, folded into the real
            // permissions/role_permissions system as payroll_run.process/.approve/.finalize instead.
            // Granting them for a role is now done via the Permission Matrix screen, same as every
            // other permission -- no special-casing on this Role modal anymore.
            // 2026-09-02, Platform Hardening Phase 1.1 -- `status` field removed, same as branch's
            // own comment above.
        ]
    },
    department: {
        fields: [
            { name: 'department_code', label: 'department_code', type: 'text', required: true },
            { name: 'department_name_th', label: 'department_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'department_name_en', label: 'department_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'cost_center', label: 'cost_center', type: 'text' }
        ]
    },
    position: {
        fields: [
            { name: 'position_code', label: 'position_code', type: 'text', required: true },
            { name: 'position_name_th', label: 'position_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'position_name_en', label: 'position_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'position_allowance', label: 'allowance_base', type: 'number', step: '0.01' }
        ]
    },
    rank: {
        fields: [
            { name: 'rank_code', label: 'rank_code', type: 'text', required: true },
            { name: 'rank_name_th', label: 'rank_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'rank_name_en', label: 'rank_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'salary_min', label: 'salary_range', type: 'number', step: '0.01', legal_key: 'min' },
            { name: 'salary_max', label: 'salary_range', type: 'number', step: '0.01', legal_key: 'max' },
            { name: 'ot_eligible', label: 'ot_eligible', type: 'checkbox' }
        ]
    },
    // 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้เป็นการเพิ่ม
    // การตั้งค่าเช่นเดียวกับ Department" -- same shape as 'department' above, plus client_name
    // (confirmed via AskUserQuestion: Team needs a separate client/scope field, not just code+name).
    team: {
        fields: [
            { name: 'team_code', label: 'team_code', type: 'text', required: true },
            { name: 'team_name_th', label: 'team_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'team_name_en', label: 'team_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'client_name', label: 'team_client_name', type: 'text' }
            // 2026-09-02, Platform Hardening Phase 1.1 -- `status` field removed, same as branch's
            // own comment above.
        ]
    },
    bank_account: {
        fields: [
            { name: 'bank_id', label: 'bank_name', type: 'select2', required: true, api: '/api/bank.get', apiType: 'bank', displayTextTh: 'bank_name_th', displayTextEn: 'bank_name_en', displayPrefix: 'bank_code' },
            { name: 'account_no', label: 'account_no', type: 'text', required: true },
            { name: 'account_name', label: 'account_name', type: 'text', required: true },
            // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย"
            // -- the bank-registered Company/Service Code (Krungsri's own "712" example) is now set
            // PER ACCOUNT here instead of as a shared constant on the bank file format, so a
            // company with multiple accounts (and multiple payroll cycles each settling from a
            // different one) can give each its own code. Optional -- not every bank assigns one.
            { name: 'company_code', label: 'bank_account_company_code', type: 'text' },
            { name: 'branch_name', label: 'branch_name', type: 'text' },
            { name: 'account_type', label: 'account_type', type: 'select', optionKeys: ['savings', 'current'] },
            { name: 'is_default', label: 'default', type: 'checkbox' }
            // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- `status` field removed from this
            // schema, same convention as branch/role/department/position/rank/team above: the new
            // row switch (toggleBankAccountStatus()) is now the only way to change it.
        ]
    }
};
$(document).on('click', '.btn-open-modal', function (e) {
    e.preventDefault();
    const btn = $(this);
    const action = btn.data('action'); 
    const type = btn.data('type'); 
    const schema = formSchemas[type];
    if (!schema) return;
    $('#systemModal .modal-header').html(`
        <h5 class="modal-title"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="${type}">${langData[type]}</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    `);
    let rowData = {};
    if (action === 'edit') {
        const table = $(`#tb_${type}`).DataTable();
        rowData = table.row(btn.closest('tr')).data() || {};
    }
    let bodyHtml = `<form id="modalForm" data-type="${type}" data-action="${action}">`;
    bodyHtml += `<input type="hidden" name="id" value="${rowData.id || ''}">`;
    schema.fields.forEach(field => {
        const val = rowData[field.name] !== undefined && rowData[field.name] !== null ? rowData[field.name] : '';
        const requiredAttr = field.required ? 'required' : '';
        bodyHtml += `<div class="mb-3 row">
            <label class="col-sm-3 col-form-label text-end"><span data-i18n="${field.label}">${langData[field.label]}</span> ${(field.legal_key) ? `(<span data-i18n="${field.legal_key}">${langData[field.legal_key]}</span>)` : ``} ${requiredMark(field.required)}</label>
            <div class="col-sm-9">`;
        if (field.type === 'select') {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<select class="form-select select2-static ${requiredClass}" name="${field.name}" data-option-keys="${field.optionKeys.join(',')}" ${requiredAttr}></select>`;
        } else if (field.type === 'select2') {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<select class="form-select select2-remote ${requiredClass}" name="${field.name}" data-api="${field.api}" data-type="${field.apiType}" ${requiredAttr}></select>`;
        } else if (field.type === 'checkbox') {
            let checked = val === true || val == 1 ? 'checked' : '';
            bodyHtml += `
                <div class="form-check form-switch pt-2">
                    <input class="form-check-input" type="checkbox" name="${field.name}" value="1" ${checked}>
                </div>`;
        } else {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<input type="${field.type}" class="form-control ${requiredClass}" name="${field.name}" value="${val}" ${requiredAttr} ${field.step ? `step="${field.step}"` : ''}>`;
        }
        bodyHtml += `</div></div>`;
    });
    bodyHtml += `</form>`;
    $('#systemModal .modal-body').html(bodyHtml);
    $('#systemModal .modal-footer').html(`
        <button type="button" class="btn btn-primary" id="btnSubmitModalForm" data-i18n="save">Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
    `);
    if (typeof initSelect2 === 'function') {
        initSelect2('#systemModal .select2-remote', { mode: 'ajax' });
    }
    schema.fields.forEach(field => {
        if (field.type === 'select2' && rowData[field.name]) {
            const $sel = $(`#systemModal [name="${field.name}"]`);
            const textField = currentLang === 'th' ? field.displayTextTh : field.displayTextEn;
            let label = textField ? (rowData[textField] || '') : '';
            if (field.displayPrefix && rowData[field.displayPrefix]) {
                label = `${rowData[field.displayPrefix]} - ${label}`;
            }
            const opt = new Option(label, rowData[field.name], true, true);
            $sel.append(opt).trigger('change');
        }
        if (field.type === 'select') {
            const $sel = $(`#systemModal [name="${field.name}"]`);
            const selectedValue = rowData[field.name] !== undefined && rowData[field.name] !== ''
                ? rowData[field.name]
                : field.optionKeys[0];
            initSelect2($sel, { mode: 'static', keys: field.optionKeys, selectedValue: selectedValue });
        }
    });
    const $modalEl = $('#systemModal');
    $modalEl.modal('show');
    if (typeof updateText === 'function') updateText($modalEl[0]);
});
// 2026-09-02, real Origami `GET /api/hr/company` endpoint confirmed live -- see
// CompanySyncModel::sync()'s own docblock. Always overwrites (Origami is the data owner), so
// this asks for confirmation first, same as any other destructive-to-manual-edits action.
// 2026-09-04, Backlog Phase 9, T050 -- this bespoke handler was retired in favor of the shared
// initOrigamiSyncButton() widget call in initProfilePane() above (same id-less button, injected
// into #cpCompanySyncBtnWrap, same confirm wording, same behavior).
$(document).on('click', '#btnSubmitModalForm', function () {
    const $btn = $(this);
    const $form = $('#modalForm');
    const type = $form.data('type');
    const schema = formSchemas[type];
    if (!schema) return;
    let hasError = false;
    $form.find('.required').each(function () {
        const value = $(this).val()?.trim() || '';
        if (!value) {
            $(this).addClass('is-invalid');
            hasError = true;
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    if (hasError) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        $form.find('.is-invalid').first().focus();
        return;
    }
    const payload = { id: $form.find('[name="id"]').val() || '' };
    schema.fields.forEach(field => {
        const $field = $form.find(`[name="${field.name}"]`);
        if (field.type === 'checkbox') {
            payload[field.name] = $field.is(':checked');
        } else {
            payload[field.name] = $field.val()?.trim() ?? '';
        }
    });
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/${apiEndpointPrefix[type] || `structure.${type}`}.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html('<span data-i18n="save">Save</span>');
            if (res.status) {
                $('#systemModal').modal('hide');
                showSuccess(langData['save_success'] || 'Saved successfully.');
                if (structureTables[type]) {
                    structureTables[type].ajax.reload(null, false);
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html('<span data-i18n="save">Save</span>');
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '.btn-delete-item', function () {
    const $btn = $(this);
    const type = $btn.data('type');
    const id = $btn.data('id');
    if (!type || !id) return;
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/${apiEndpointPrefix[type] || `structure.${type}`}.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    if (structureTables[type]) {
                        structureTables[type].ajax.reload(null, false);
                    }
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () {
                showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
            }
        });
    });
});

/* ---------- Bank File Format settings (2026-08-29) ----------
 * See tmpl-bank-format-subpane's own comment for the feature context. Deliberately a plain
 * sidebar-list + detail-panel layout (not a DataTable) -- the format count is small and fixed
 * (master_bank_file_formats, ~16 rows), same "fixed set, not a paginated record list" reasoning
 * the Permission Matrix grid already uses elsewhere in this app. */
let bffFormats = [];
let bffSelectedFormatId = null;
let bffCurrentDetail = null;

function bffFormatLabel(f) {
    const bankName = (currentLang === 'th' ? f.bank_name_th : f.bank_name_en) || f.bank_name_th || f.bank_name_en || '';
    const formatName = (currentLang === 'th' ? f.name_th : f.name_en) || f.name_th || f.name_en || f.code;
    return bankName ? `${bankName} — ${formatName}` : formatName;
}

function initBffFormatList() {
    $('#bffFormatList').html(`<div class="text-center text-secondary py-3"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $.ajax({
        url: `${BASE_URL}/api/bank-file-format.list`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            // 2026-08-29: a permission/company-context failure used to `return` here silently,
            // leaving the loading spinner frozen forever with no explanation -- found while
            // investigating "คลิกแล้วไม่เห็นธนาคารครับ" (empty-looking panel on click).
            if (!res.status) {
                $('#bffFormatList').html(`<div class="text-danger small">${res.message || langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
                return;
            }
            // PDO returns every column as a string (e.g. id:"9"), but default_format_id comes back
            // as a real PHP int -- Number()-normalize both sides before comparing so the default
            // star badge / auto-preselect actually match instead of failing every strict `===`.
            bffFormats = (res.data || []).map(f => Object.assign({}, f, { id: Number(f.id) }));
            const defaultFormatId = res.default_format_id !== null && res.default_format_id !== undefined ? Number(res.default_format_id) : null;
            if (!bffFormats.length) {
                $('#bffFormatList').html(`<div class="text-secondary small">${langData['no_bank_file_formats'] || 'No bank formats are available.'}</div>`);
                return;
            }
            bffRenderFormatList(defaultFormatId);
            const preselect = defaultFormatId !== null && bffFormats.some(f => f.id === defaultFormatId)
                ? defaultFormatId
                : bffFormats[0].id;
            bffSelectFormat(preselect);
        },
        error: function () {
            $('#bffFormatList').html(`<div class="text-danger small">${langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
        }
    });
}

function bffRenderFormatList(defaultFormatId) {
    const $list = $('#bffFormatList').empty();
    bffFormats.forEach(function (f) {
        const isDefault = defaultFormatId && f.id === defaultFormatId;
        const isActive = f.id === bffSelectedFormatId;
        const verifiedBadge = f.has_own_override
            ? (f.is_verified
                ? `<span class="badge bg-success-subtle text-success" data-i18n="verified">Verified</span>`
                : `<span class="badge bg-warning-subtle text-warning" data-i18n="draft_not_verified">DRAFT — not verified</span>`)
            : `<span class="badge bg-secondary-subtle text-secondary" data-i18n="using_default_template">Using default template</span>`;
        const $item = $(`
            <button type="button" class="btn btn-light text-start bff-format-item ${isActive ? 'active border-warning' : ''}" data-id="${f.id}">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small">${escapeHtml(bffFormatLabel(f))}</span>
                    ${isDefault ? `<i class="fa-solid fa-star text-warning ms-1" title="${langData['default'] || 'Default'}"></i>` : ''}
                </div>
                <div class="mt-1">${verifiedBadge}</div>
            </button>
        `);
        $list.append($item);
    });
    updateText($list[0]);
}

$(document).on('click', '.bff-format-item', function () {
    bffSelectFormat($(this).data('id'));
});

function bffSelectFormat(id) {
    bffSelectedFormatId = id;
    $('.bff-format-item').removeClass('active border-warning');
    $(`.bff-format-item[data-id="${id}"]`).addClass('active border-warning');
    $.ajax({
        url: `${BASE_URL}/api/bank-file-format.get`,
        method: 'GET',
        dataType: 'json',
        data: { bank_file_format_id: id },
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || (langData['save_failed'] || 'An error occurred while loading the data.'));
                return;
            }
            bffCurrentDetail = res.data;
            const f = bffFormats.find(x => x.id === id);
            $('#bffDetailFormatName').text(f ? bffFormatLabel(f) : '');
            $('#bffDraftBadge').toggleClass('d-none', !!res.data.config.is_verified);
            bffPopulateConfigForm(res.data.config);
            bffRenderFieldsTable(res.data.fields);
            $('#bffDetailEmpty').addClass('d-none');
            $('#bffDetailPanel').removeClass('d-none');
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function bffPopulateConfigForm(config) {
    $('#bffDelimiterType').val(config.delimiter_type);
    $('#bffDelimiterChar').val(config.delimiter_char || ',');
    $('#bffLineEnding').val(config.line_ending);
    $('#bffTextEncoding').val(config.text_encoding);
    $('#bffHasHeaderRow').prop('checked', !!config.has_header_row);
    $('#bffHasTrailerRow').prop('checked', !!config.has_trailer_row);
    $('#bffIsVerified').prop('checked', !!config.is_verified);
    $('#bffDelimiterCharWrap').toggleClass('d-none', config.delimiter_type === 'fixed_width');
}
$(document).on('change', '#bffDelimiterType', function () {
    $('#bffDelimiterCharWrap').toggleClass('d-none', $(this).val() === 'fixed_width');
});

$(document).on('click', '#bffSaveConfigBtn', function () {
    if (!bffSelectedFormatId) return;
    const payload = {
        bank_file_format_id: bffSelectedFormatId,
        delimiter_type: $('#bffDelimiterType').val(),
        delimiter_char: $('#bffDelimiterChar').val(),
        line_ending: $('#bffLineEnding').val(),
        text_encoding: $('#bffTextEncoding').val(),
        has_header_row: $('#bffHasHeaderRow').is(':checked'),
        has_trailer_row: $('#bffHasTrailerRow').is(':checked'),
        is_verified: $('#bffIsVerified').is(':checked'),
    };
    $.ajax({
        url: `${BASE_URL}/api/bank-file-format.save-config`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bffSelectFormat(bffSelectedFormatId);
                initBffFormatList();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

function bffRowTypeBadge(rowType) {
    const map = { header: 'bg-info-subtle text-info', detail: 'bg-primary-subtle text-primary', trailer: 'bg-secondary-subtle text-secondary' };
    return `<span class="badge ${map[rowType] || 'bg-light text-dark'}" data-i18n="row_type_${rowType}">${langData['row_type_' + rowType] || rowType}</span>`;
}
function bffSourceSummary(field) {
    if (field.source_type === 'constant') {
        return `<span class="text-secondary small" data-i18n="source_type_constant">Fixed Value</span>: "${escapeHtml(field.constant_value || '')}"`;
    }
    if (field.source_type === 'blank') {
        return `<span class="text-secondary small" data-i18n="source_type_blank">Blank</span>`;
    }
    const label = bffCurrentDetail && bffCurrentDetail.source_fields && bffCurrentDetail.source_fields[field.source_field]
        ? bffCurrentDetail.source_fields[field.source_field][currentLang === 'en' ? 'en' : 'th']
        : field.source_field;
    return escapeHtml(label || field.source_field || '');
}

function bffRenderFieldsTable(fieldsGrouped) {
    const $body = $('#bffFieldsBody').empty();
    ['header', 'detail', 'trailer'].forEach(function (rowType) {
        (fieldsGrouped[rowType] || []).forEach(function (field) {
            const label = currentLang === 'en' ? field.field_label_en : field.field_label_th;
            const $tr = $(`
                <tr>
                    <td>${bffRowTypeBadge(rowType)}</td>
                    <td>${field.sort_order}</td>
                    <td>${escapeHtml(label)}</td>
                    <td>${bffSourceSummary(field)}</td>
                    <td>${field.width || '-'}</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-link bff-edit-field-btn" data-id="${field.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
                        <button type="button" class="btn btn-sm btn-link text-danger bff-delete-field-btn" data-id="${field.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            `);
            $body.append($tr);
        });
    });
    if (!$body.children().length) {
        $body.append(`<tr><td colspan="6" class="text-center text-secondary py-3">${langData['no_fields_configured'] || 'No fields configured yet.'}</td></tr>`);
    }
    updateText($body[0]);
}

function bffPopulateSourceFieldSelect() {
    const $select = $('#bffFieldSourceField').empty();
    const sourceFields = (bffCurrentDetail && bffCurrentDetail.source_fields) || {};
    Object.keys(sourceFields).forEach(function (key) {
        const label = sourceFields[key][currentLang === 'en' ? 'en' : 'th'] || key;
        $select.append(new Option(label, key));
    });
}
function bffToggleFieldModalSections() {
    const sourceType = $('#bffFieldSourceType').val();
    $('#bffFieldSourceFieldWrap').toggleClass('d-none', sourceType !== 'employee_field');
    $('#bffFieldConstantWrap').toggleClass('d-none', sourceType !== 'constant');
    const dataType = $('#bffFieldDataType').val();
    $('#bffFieldDecimalWrap').toggleClass('d-none', dataType !== 'number');
    $('#bffFieldDateFormatWrap').toggleClass('d-none', dataType !== 'date');
}
$(document).on('change', '#bffFieldSourceType, #bffFieldDataType', bffToggleFieldModalSections);

function bffResetFieldModal() {
    $('#bffFieldId').val('');
    $('#bffFieldLabelTh').val('').removeClass('is-invalid');
    $('#bffFieldLabelEn').val('').removeClass('is-invalid');
    $('#bffFieldRowType').val('detail');
    $('#bffFieldSortOrder').val(0);
    $('#bffFieldSourceType').val('employee_field');
    bffPopulateSourceFieldSelect();
    $('#bffFieldConstantValue').val('');
    $('#bffFieldDataType').val('text');
    $('#bffFieldDecimalPlaces').val(2);
    $('#bffFieldDateFormat').val('Ymd');
    $('#bffFieldWidth').val('');
    $('#bffFieldPadChar').val(' ');
    $('#bffFieldPadDirection').val('right');
    bffToggleFieldModalSections();
}

$(document).on('click', '#bffAddFieldBtn', function () {
    bffResetFieldModal();
    $('#bffFieldModal .modal-title').attr('data-i18n', 'add_field').text(langData['add_field'] || 'Add Field');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bffFieldModal')).show();
});

$(document).on('click', '.bff-edit-field-btn', function () {
    // 2026-08-29, real bug found and fixed (explicit report: "คลิกแก้ไขไม่ได้ครับ" -- clicking Edit
    // silently did nothing): PDO returns `id` as a PHP string ("125", not int 125 -- confirmed via
    // direct query, this project's PDO connection doesn't force native int types), so
    // json_encode() serializes it as a JSON STRING too. jQuery's $(el).data('id'), reading the raw
    // data-id="125" DOM attribute, auto-converts a purely-numeric attribute value to a JS NUMBER.
    // The strict === comparison below was therefore comparing a string "125" (f.id, from the API
    // response) against a number 125 (fieldId, from jQuery) -- ALWAYS false, so `field` stayed null
    // for every field and the early `if (!field) return;` silently aborted, no error shown at all.
    // Fixed by normalizing both sides to Number before comparing.
    const fieldId = Number($(this).data('id'));
    let field = null;
    ['header', 'detail', 'trailer'].forEach(function (rt) {
        (bffCurrentDetail.fields[rt] || []).forEach(function (f) { if (Number(f.id) === fieldId) field = f; });
    });
    if (!field) return;
    bffResetFieldModal();
    $('#bffFieldModal .modal-title').attr('data-i18n', 'edit_field').text(langData['edit_field'] || 'Edit Field');
    $('#bffFieldId').val(field.id);
    $('#bffFieldLabelTh').val(field.field_label_th);
    $('#bffFieldLabelEn').val(field.field_label_en);
    $('#bffFieldRowType').val(field.row_type);
    $('#bffFieldSortOrder').val(field.sort_order);
    $('#bffFieldSourceType').val(field.source_type);
    if (field.source_type === 'employee_field') $('#bffFieldSourceField').val(field.source_field);
    $('#bffFieldConstantValue').val(field.constant_value || '');
    $('#bffFieldDataType').val(field.data_type);
    $('#bffFieldDecimalPlaces').val(field.decimal_places !== null && field.decimal_places !== undefined ? field.decimal_places : 2);
    $('#bffFieldDateFormat').val(field.date_format || 'Ymd');
    $('#bffFieldWidth').val(field.width || '');
    $('#bffFieldPadChar').val(field.pad_char || ' ');
    $('#bffFieldPadDirection').val(field.pad_direction || 'right');
    bffToggleFieldModalSections();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bffFieldModal')).show();
});

$(document).on('click', '#bffFieldSaveBtn', function () {
    if (!bffSelectedFormatId) return;
    const labelTh = $('#bffFieldLabelTh').val().trim();
    const labelEn = $('#bffFieldLabelEn').val().trim();
    $('#bffFieldLabelTh').toggleClass('is-invalid', !labelTh);
    $('#bffFieldLabelEn').toggleClass('is-invalid', !labelEn);
    if (!labelTh || !labelEn) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        bank_file_format_id: bffSelectedFormatId,
        id: $('#bffFieldId').val() || undefined,
        row_type: $('#bffFieldRowType').val(),
        sort_order: $('#bffFieldSortOrder').val(),
        field_label_th: labelTh,
        field_label_en: labelEn,
        source_type: $('#bffFieldSourceType').val(),
        source_field: $('#bffFieldSourceField').val(),
        constant_value: $('#bffFieldConstantValue').val(),
        data_type: $('#bffFieldDataType').val(),
        decimal_places: $('#bffFieldDecimalPlaces').val(),
        date_format: $('#bffFieldDateFormat').val(),
        width: $('#bffFieldWidth').val(),
        pad_char: $('#bffFieldPadChar').val() || ' ',
        pad_direction: $('#bffFieldPadDirection').val(),
    };
    $.ajax({
        url: `${BASE_URL}/api/bank-file-format.save-field`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('bffFieldModal')).hide();
                bffSelectFormat(bffSelectedFormatId);
                initBffFormatList();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

$(document).on('click', '.bff-delete-field-btn', function () {
    const fieldId = $(this).data('id');
    showConfirm(
        langData['confirm_delete_title'] || 'Confirm Delete',
        langData['confirm_delete_message'] || 'Are you sure you want to delete this record?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/bank-file-format.delete-field`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ bank_file_format_id: bffSelectedFormatId, id: fieldId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                        bffSelectFormat(bffSelectedFormatId);
                        initBffFormatList();
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () {
                    showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                }
            });
        }
    );
});

$(document).on('click', '#bffResetBtn', function () {
    if (!bffSelectedFormatId) return;
    showConfirm(
        langData['reset_to_default'] || 'Reset to Default',
        langData['confirm_reset_format_message'] || 'This discards your customization for this format and reverts to the system default template. Continue?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/bank-file-format.reset`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ bank_file_format_id: bffSelectedFormatId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                        bffSelectFormat(bffSelectedFormatId);
                        initBffFormatList();
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                    }
                },
                error: function () {
                    showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
                }
            });
        }
    );
});

$(document).on('click', '#bffViewLogBtn', function () {
    if (!bffSelectedFormatId) return;
    $('#bffLogModalBody').html(`<div class="text-center text-secondary py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</div>`);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bffLogModal')).show();
    $.ajax({
        url: `${BASE_URL}/api/bank-file-format.edit-logs`,
        method: 'GET',
        dataType: 'json',
        data: { bank_file_format_id: bffSelectedFormatId },
        success: function (res) {
            if (!res.status || !res.data || !res.data.length) {
                $('#bffLogModalBody').html(`<div class="text-center text-secondary py-3">${langData['no_export_history'] || 'No history yet.'}</div>`);
                return;
            }
            let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light text-secondary"><tr>
                    <th>${langData['table_generated_at'] || 'Date/Time'}</th>
                    <th>${langData['action'] || 'Action'}</th>
                    <th>${langData['table_generated_by'] || 'By'}</th>
                </tr></thead><tbody>`;
            res.data.forEach(function (row) {
                const by = (currentLang === 'th' ? row.changed_by_name_th : row.changed_by_name_en) || row.changed_by_name_th || row.changed_by_name_en || '-';
                const actionKey = 'bff_action_' + row.action;
                html += `<tr>
                    <td>${formatDisplayDateTime(row.changed_at)}</td>
                    <td><span data-i18n="${actionKey}">${langData[actionKey] || row.action}</span></td>
                    <td>${escapeHtml(by)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#bffLogModalBody').html(html);
            updateText($('#bffLogModalBody')[0]);
        },
        error: function () {
            $('#bffLogModalBody').html(`<div class="text-center text-danger py-3">${langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
        }
    });
});