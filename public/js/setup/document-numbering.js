/**
 * Document Numbering — Document & Approval > "Document Numbering" tab (2026-08-23, explicit
 * request: "ยังไม่สามารถตั้งค่าได้จริง" -- was a static mockup table, now backed by a real
 * per-company settings grid, DocumentNumberingModel/Controller). Fixed 4 rows (document_type_code
 * is code-tied, not user-extensible -- see the model's own docblock), no add/delete.
 *
 * 2026-09-04, Backlog Phase 11, T061 ("redesigned as cards with example settings shown; simplify
 * the form") -- rendered as one .settings-info-card per document type (same convention Data
 * Sync's own per-entity cards already use, see data-sync.js's dsRenderCards()) instead of a plain
 * table row -- the "simplify" part is showing the live preview (formatDocNumberPreview(), already
 * existed but was only ever rendered INSIDE the Edit modal) directly on the card body, so seeing
 * what a document type's numbering actually produces no longer requires opening anything. The
 * Edit modal itself (#documentNumberingModal, modals.php) is unchanged -- still the same 4 fields,
 * opened from the card's own Edit button instead of a table row's pencil icon.
 */
let documentNumberingRows = [];


const DOC_NUMBERING_TYPE_LABEL_KEYS = {
    PAYSLIP: 'doc_type_payslip',
    PAYROLL_RUN: 'doc_type_payroll_run',
    WHT_CERT: 'doc_type_wht_cert',
    BANK_TRANSFER: 'doc_type_bank_transfer',
};
// Icons purely decorative/identity, matching each document type's own real-world shape -- no
// meaning encoded beyond "help the eye tell the 4 cards apart at a glance".
const DOC_NUMBERING_TYPE_ICON = {
    PAYSLIP: 'fa-file-invoice-dollar',
    PAYROLL_RUN: 'fa-money-check-dollar',
    WHT_CERT: 'fa-file-shield',
    BANK_TRANSFER: 'fa-building-columns',
};
function docNumberingTypeLabel(code) {
    const key = DOC_NUMBERING_TYPE_LABEL_KEYS[code];
    return (key && langData[key]) || code;
}
function docNumberingResetLabel(cycle) {
    const key = { never: 'reset_cycle_never', yearly: 'reset_cycle_yearly', monthly: 'reset_cycle_monthly' }[cycle];
    return (key && langData[key]) || cycle;
}
// Preview: what the NEXT number would look like given the current settings. 2026-09-04, T061 --
// PAYSLIP/PAYROLL_RUN/BANK_TRANSFER are now real wired consumers (DocumentNumberingModel::
// generateNext(), server-side) -- this stays a client-side ESTIMATE for display purposes only
// (mirrors the server's own {YYYY}/{MM}/{DD}/{YYYYMMDD} substitution, but does NOT know the
// company's own {COMP_CODE} value, so that one placeholder is left as literal "{COMP_CODE}" text
// in the preview rather than guessed at -- the real generated document always has the correct
// value, only this preview is a simplification).
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
function renderDocumentNumberingCards() {
    const $wrap = $('#documentNumberingCards').empty();
    documentNumberingRows.forEach(row => {
        const isWht = row.document_type_code === 'WHT_CERT';
        // 2026-09-04, T061 -- WHT_CERT has no real generator anywhere in this codebase to wire to
        // (confirmed via AskUserQuestion) -- flagged here so an admin configuring it understands
        // why nothing ever actually gets stamped with this number, same "config-only, no consumer
        // yet" badge convention this app uses elsewhere for a built-ahead-of-its-consumer feature.
        const notWiredBadge = isWht
            ? `<span class="badge bg-secondary-subtle text-secondary mt-2" data-i18n="doc_numbering_not_wired">${langData['doc_numbering_not_wired'] || 'Not yet connected to a document'}</span>`
            : '';
        $wrap.append(`
            <div class="col-lg-3 col-md-6">
                <div class="settings-info-card h-100" data-doc-numbering-card="${row.document_type_code}">
                    <div class="settings-info-card-header">
                        <i class="fa-solid ${DOC_NUMBERING_TYPE_ICON[row.document_type_code] || 'fa-hashtag'}"></i>
                        <div>
                            <p class="settings-info-card-title mb-0">${escapeAttr(docNumberingTypeLabel(row.document_type_code))}</p>
                            <p class="settings-info-card-desc mb-0">${escapeAttr(row.prefix_format)} &middot; ${escapeAttr(docNumberingResetLabel(row.reset_cycle))}</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="text-secondary small mb-1" data-i18n="doc_numbering_next_example">Next number example</div>
                        <div class="fw-bold mb-2">${escapeAttr(formatDocNumberPreview(row))}</div>
                        ${notWiredBadge}
                        <div class="d-flex justify-content-end mt-2">
                            <button type="button" class="btn btn-outline-brand btn-sm btn-edit-doc-numbering" data-code="${row.document_type_code}">
                                <i class="fa-solid fa-pen me-1"></i><span data-i18n="edit">Edit</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>`);
    });
    updateText($wrap[0]);
}
function loadDocumentNumbering() {
    $.ajax({
        url: `${BASE_URL}/api/document-numbering.list`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                documentNumberingRows = res.data || [];
                renderDocumentNumberingCards();
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
    $('#documentNumberingPreview').html(`<i class="fa-regular fa-eye me-1"></i>${escapeAttr(formatDocNumberPreview(row))}`);
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
