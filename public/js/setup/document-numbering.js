/**
 * Document Numbering — Document & Approval > "Document Numbering" tab (2026-08-23, explicit
 * request: "ยังไม่สามารถตั้งค่าได้จริง" -- was a static mockup table, now backed by a real
 * per-company settings grid, DocumentNumberingModel/Controller). Fixed 4 rows (document_type_code
 * is code-tied, not user-extensible -- see the model's own docblock), no add/delete, matching the
 * Permission Matrix's "fixed grid" precedent -- so this deliberately does NOT use DataTables.
 */
let documentNumberingRows = [];

function escapeHtmlDn(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

const DOC_NUMBERING_TYPE_LABEL_KEYS = {
    PAYSLIP: 'doc_type_payslip',
    PAYROLL_RUN: 'doc_type_payroll_run',
    WHT_CERT: 'doc_type_wht_cert',
    BANK_TRANSFER: 'doc_type_bank_transfer',
};
function docNumberingTypeLabel(code) {
    const key = DOC_NUMBERING_TYPE_LABEL_KEYS[code];
    return (key && langData[key]) || code;
}
function docNumberingResetLabel(cycle) {
    const key = { never: 'reset_cycle_never', yearly: 'reset_cycle_yearly', monthly: 'reset_cycle_monthly' }[cycle];
    return (key && langData[key]) || cycle;
}
// Preview only -- nothing in this app yet actually consumes prefix_format/digit_count/
// current_number to stamp a real number onto a generated document (see the model's own
// docblock); this just shows what the NEXT number would look like given the current settings.
function formatDocNumberPreview(row) {
    const now = new Date();
    const yyyy = String(now.getFullYear());
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const prefix = (row.prefix_format || '')
        .replace(/\{YYYYMMDD\}/g, yyyy + mm + dd)
        .replace(/\{YYYY\}/g, yyyy)
        .replace(/\{MM\}/g, mm)
        .replace(/\{DD\}/g, dd);
    const nextNumber = (Number(row.current_number) || 0) + 1;
    return prefix + String(nextNumber).padStart(Number(row.digit_count) || 1, '0');
}
function renderDocumentNumberingRows() {
    const $body = $('#documentNumberingBody').empty();
    documentNumberingRows.forEach(row => {
        $body.append(`<tr>
            <td class="fw-bold">${escapeHtmlDn(docNumberingTypeLabel(row.document_type_code))}</td>
            <td>${escapeHtmlDn(row.prefix_format)}</td>
            <td class="text-end">${escapeHtmlDn(row.digit_count)}</td>
            <td class="text-end">${escapeHtmlDn(String(row.current_number).padStart(Number(row.digit_count) || 1, '0'))}</td>
            <td>${escapeHtmlDn(docNumberingResetLabel(row.reset_cycle))}</td>
            <td><button type="button" class="btn btn-sm btn-outline-secondary btn-edit-doc-numbering" data-code="${row.document_type_code}"><i class="fa-solid fa-pen"></i></button></td>
        </tr>`);
    });
}
function loadDocumentNumbering() {
    $.ajax({
        url: `${BASE_URL}/api/document-numbering.list`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                documentNumberingRows = res.data || [];
                renderDocumentNumberingRows();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function updateDocumentNumberingPreview() {
    const row = {
        prefix_format: $('#dn_prefix_format').val(),
        digit_count: $('#dn_digit_count').val(),
        current_number: $('#dn_current_number').val(),
    };
    $('#documentNumberingPreview').html(`<i class="fa-regular fa-eye me-1"></i>${escapeHtmlDn(formatDocNumberPreview(row))}`);
}
$(document).on('click', '.btn-edit-doc-numbering', function () {
    const code = $(this).data('code');
    const row = documentNumberingRows.find(r => r.document_type_code === code);
    if (!row) return;
    $('#documentNumberingForm')[0].reset();
    $('.is-invalid', '#documentNumberingForm').removeClass('is-invalid');
    $('#dn_document_type_code').val(row.document_type_code);
    $('#documentNumberingModalTypeLabel').text(docNumberingTypeLabel(row.document_type_code));
    $('#dn_prefix_format').val(row.prefix_format);
    $('#dn_digit_count').val(row.digit_count);
    $('#dn_current_number').val(row.current_number);
    initSelect2('#dn_reset_cycle', { mode: 'static', selectedValue: row.reset_cycle });
    updateDocumentNumberingPreview();
    new bootstrap.Modal(document.getElementById('documentNumberingModal')).show();
});
$(document).on('input', '#dn_prefix_format, #dn_digit_count, #dn_current_number', updateDocumentNumberingPreview);
$(document).on('submit', '#documentNumberingForm', function (e) {
    e.preventDefault();
    let firstInvalid = null;
    $('#documentNumberingModal .required').each(function () {
        const $el = $(this);
        if (!($el.val() || '').toString().trim()) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    if (firstInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        document_type_code: $('#dn_document_type_code').val(),
        prefix_format: $('#dn_prefix_format').val().trim(),
        digit_count: Number($('#dn_digit_count').val()),
        current_number: Number($('#dn_current_number').val()),
        reset_cycle: $('#dn_reset_cycle').val(),
    };
    const $btn = $(this).find('[type="submit"]');
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/document-numbering.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('documentNumberingModal')).hide();
                loadDocumentNumbering();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
$(document).ready(function () {
    // tab-run isn't the default-active tab on this page (tab-flow is) -- load lazily on first
    // view, same shown.bs.tab pattern every other non-default tab on this page already uses.
    let loadedOnce = false;
    $('button[data-bs-target="#tab-run"]').on('shown.bs.tab', function () {
        if (!loadedOnce) {
            loadedOnce = true;
            loadDocumentNumbering();
        }
    });
});
