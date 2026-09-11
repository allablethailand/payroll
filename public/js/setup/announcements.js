/* Backlog Phase 10, T057 -- Announcement CMS management page (setup/announcements). Client-side
 * DataTable (small, bounded list per company). "Edit Recipients" reuses T055's assign-widget.js
 * (openAssignModal()/assignSummaryBadgeHtml()) against entity_type='announcement' server-side.
 *
 * 2026-09-07, explicit follow-up: "แก้ไข เพิ่มเป็น CMS แบบ 100% จัดรูปแบบเนื้อหาได้ สามารถแนบปกได้ ใส่
 * Subject ได้ รองรับการจัดการเนื้อหาแบบ 2 ภาษา" -- Create/Edit/Delete and 2-language content already
 * existed (see save()/delete() below, and title_th/title_en+body_th/body_en's own long-standing
 * shape) -- "Subject" is the existing Title field (title_th/title_en's own i18n label is literally
 * "หัวข้อ" = Subject/Title in Thai, see public/lang/th.json), no new field was needed for that part.
 * The 2 genuinely new pieces this round are rich-text body formatting (Quill, see
 * initAnnQuillEditors() below) and a cover image (see the ann_cover_* handlers below). */
let annTable = null;
let annCurrentAssignments = [];
let annAssignableOptionsCache = null;
let annQuillTh = null;
let annQuillEn = null;

/** Quill's OWN default Image-button behavior embeds the picked file as a base64 `data:` URI directly
 *  in the content -- deliberately overridden here to upload for real instead (see
 *  AnnouncementController::uploadContentImage()'s own docblock for why: body_th/body_en is a
 *  65,535-byte TEXT column, and AnnouncementModel::sanitizeRichHtml() strips `data:` URLs from every
 *  src/href as a blanket XSS defense anyway, which would silently leave a broken <img> behind). */
function annQuillImageHandler() {
    const quill = this.quill;
    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('accept', 'image/png,image/jpeg,image/gif,image/webp');
    input.onchange = function () {
        const file = input.files && input.files[0];
        if (!file) { return; }
        const formData = new FormData();
        formData.append('file', file);
        const range = quill.getSelection(true);
        $.ajax({
            url: `${BASE_URL}/api/announcement.upload-content-image`, method: 'POST', data: formData,
            processData: false, contentType: false, dataType: 'json',
            success: function (res) {
                if (res.status) { quill.insertEmbed(range.index, 'image', res.url, 'user'); quill.setSelection(range.index + 1); }
                else { showWarning(res.message || langData['upload_failed'] || 'Failed to upload file.'); }
            }
        });
    };
    input.click();
}
/** Lazy-init (only once) -- called every time the modal opens since Quill can't init into a hidden
 *  (display:none, inside an un-shown Bootstrap modal) container and get correct toolbar sizing; once
 *  created the SAME instance is reused on every subsequent open (re-running `new Quill(...)` on an
 *  already-quill-ified element throws), its content just gets cleared/repopulated each time instead. */
function initAnnQuillEditors() {
    if (annQuillTh && annQuillEn) { return; }
    const toolbarOptions = {
        container: [
            [{ header: [1, 2, 3, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ color: [] }, { background: [] }],
            [{ list: 'ordered' }, { list: 'bullet' }],
            [{ align: [] }],
            ['link', 'image'],
            ['clean']
        ],
        handlers: { image: annQuillImageHandler }
    };
    annQuillTh = new Quill('#ann_body_th_editor', { theme: 'snow', modules: { toolbar: toolbarOptions } });
    annQuillEn = new Quill('#ann_body_en_editor', { theme: 'snow', modules: { toolbar: toolbarOptions } });
}

function annStatusBadge(status) {
    return status === 'published'
        ? `<span class="badge bg-success-subtle text-success">${langData['announcement_status_published'] || 'Published'}</span>`
        : `<span class="badge bg-secondary-subtle text-secondary">${langData['announcement_status_draft'] || 'Draft'}</span>`;
}
function annActionBtns(row) {
    const isDraft = row.status === 'draft';
    let html = '<div class="d-flex gap-1 justify-content-center flex-wrap">';
    if (isDraft) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-warning" onclick="openAnnouncementModal(${row.id})" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>`;
        html += `<button type="button" class="btn btn-link btn-circle-action text-success" onclick="publishAnnouncement(${row.id})" title="${langData['announcement_publish'] || 'Publish'}"><i class="fa-solid fa-paper-plane"></i></button>`;
    }
    if (row.status === 'published' && !row.is_dashboard_featured) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-primary" onclick="setFeaturedAnnouncement(${row.id})" title="${langData['announcement_set_featured'] || 'Feature on Dashboard'}"><i class="fa-regular fa-star"></i></button>`;
    }
    html += `<button type="button" class="btn btn-link btn-circle-action text-danger" onclick="deleteAnnouncement(${row.id})" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    html += '</div>';
    return html;
}
function initAnnouncementTable() {
    if (annTable) { annTable.ajax.reload(null, false); return; }
    annTable = $('#tb_announcement').DataTable({
        ajax: { url: `${BASE_URL}/api/announcement.list`, dataSrc: 'data' },
        columns: [
            { data: 'status', render: (d) => annStatusBadge(d) },
            { data: null, render: (d, t, row) => {
                const title = escapeHtml(currentLang === 'th' ? row.title_th : row.title_en);
                if (!row.cover_image_path) { return title; }
                return `<div class="d-flex align-items-center gap-2"><img src="${BASE_URL}/${row.cover_image_path}" class="ann-cover-thumb" alt=""> <span>${title}</span></div>`;
            } },
            { data: 'accept_required', className: 'text-center', render: (d) => d ? `<i class="fa-solid fa-check text-success"></i>` : `<i class="fa-solid fa-minus text-muted"></i>` },
            { data: null, className: 'text-center', render: (d, t, row) => row.status === 'published' ? `${row.acknowledged_count} / ${row.recipient_count}` : '<span class="text-muted">-</span>' },
            { data: 'is_dashboard_featured', className: 'text-center', render: (d) => d ? `<i class="fa-solid fa-star text-warning"></i>` : '' },
            { data: null, render: (d, t, row) => formatDisplayDateTime ? formatDisplayDateTime(row.updated_at || row.created_at) : (row.updated_at || row.created_at || '') },
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => annActionBtns(row) }
        ],
        ordering: false, lengthChange: false, pageLength: pageLength,
        language: (typeof getTableLang === 'function') ? getTableLang() : {},
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-announcement').length === 0) {
                $searchDiv.append(`<button type="button" class="btn btn-primary ms-1 btn-add-announcement" onclick="openAnnouncementModal()"><i class="fa-solid fa-plus me-1"></i><span>${langData['announcement_new'] || 'New Announcement'}</span></button>`);
            }
        }
    });
}
function annSetCoverPreview(path) {
    $('#ann_cover_image_path').val(path || '');
    if (path) {
        $('#annCoverPreviewImg').attr('src', `${BASE_URL}/${path}`).removeClass('d-none');
        $('#annCoverPlaceholder').addClass('d-none');
        $('#annCoverRemoveBtn').removeClass('d-none');
    } else {
        $('#annCoverPreviewImg').attr('src', '').addClass('d-none');
        $('#annCoverPlaceholder').removeClass('d-none');
        $('#annCoverRemoveBtn').addClass('d-none');
    }
}
function resetAnnouncementForm() {
    $('#ann_id').val('');
    $('#ann_title_th').val('');
    $('#ann_title_en').val('');
    initAnnQuillEditors();
    annQuillTh.setText('');
    annQuillEn.setText('');
    annSetCoverPreview(null);
    $('#ann_accept_required').prop('checked', false);
    annCurrentAssignments = [];
    $('#ann_assign_badge').html(assignSummaryBadgeHtml([]));
    $('#announcementModalTitle').text(langData['announcement_new'] || 'New Announcement');
}
function openAnnouncementModal(id) {
    resetAnnouncementForm();
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/announcement.get`, method: 'GET', data: { id: id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['load_failed'] || 'Failed to load data.'); return; }
                const row = res.data;
                $('#ann_id').val(row.id);
                $('#ann_title_th').val(row.title_th);
                $('#ann_title_en').val(row.title_en);
                annQuillTh.root.innerHTML = row.body_th || '';
                annQuillEn.root.innerHTML = row.body_en || '';
                annSetCoverPreview(row.cover_image_path || null);
                $('#ann_accept_required').prop('checked', !!row.accept_required);
                annCurrentAssignments = (row.assignments || []).map(a => ({ scope_type: a.scope_type, scope_id: a.scope_id }));
                $('#ann_assign_badge').html(assignSummaryBadgeHtml(annCurrentAssignments));
                $('#announcementModalTitle').text(langData['announcement_edit'] || 'Edit Announcement');
                new bootstrap.Modal(document.getElementById('announcementModal')).show();
            }
        });
    } else {
        new bootstrap.Modal(document.getElementById('announcementModal')).show();
    }
}
$(document).on('click', '#annCoverRemoveBtn', function () {
    annSetCoverPreview(null);
});
$(document).on('change', '#ann_cover_file', function () {
    const file = this.files && this.files[0];
    if (!file) { return; }
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/announcement.upload-cover`, method: 'POST', data: formData,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) { annSetCoverPreview(res.cover_image_path); }
            else { showWarning(res.message || langData['upload_failed'] || 'Failed to upload file.'); }
        },
        complete: function () { $('#ann_cover_file').val(''); }
    });
});
$(document).on('click', '#annOpenAssignBtn', function () {
    function open() {
        openAssignModal({
            entityLabel: langData['announcement_recipients'] || 'Recipients',
            assignableOptions: annAssignableOptionsCache,
            currentAssignments: annCurrentAssignments,
            onSave: function (newAssignments) {
                annCurrentAssignments = newAssignments;
                $('#ann_assign_badge').html(assignSummaryBadgeHtml(annCurrentAssignments));
            }
        });
    }
    if (annAssignableOptionsCache) { open(); return; }
    $.ajax({
        url: `${BASE_URL}/api/announcement.assignable-options`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (res.status) { annAssignableOptionsCache = res.data; open(); }
        }
    });
});
$(document).on('click', '#annSaveBtn', function () {
    const titleTh = $('#ann_title_th').val().trim();
    const titleEn = $('#ann_title_en').val().trim();
    // Quill's own getText() always includes a trailing "\n" even when empty -- trim() before checking.
    const bodyThPlain = annQuillTh.getText().trim();
    const bodyEnPlain = annQuillEn.getText().trim();
    if (!titleTh || !titleEn || !bodyThPlain || !bodyEnPlain) {
        showWarning(langData['required_fields_missing'] || 'Please fill in all required fields.');
        return;
    }
    const payload = {
        title_th: titleTh, title_en: titleEn,
        body_th: annQuillTh.root.innerHTML, body_en: annQuillEn.root.innerHTML,
        cover_image_path: $('#ann_cover_image_path').val() || null,
        accept_required: $('#ann_accept_required').is(':checked'),
        assignments: annCurrentAssignments,
    };
    const id = $('#ann_id').val();
    if (id) { payload.id = id; }
    $.ajax({
        url: `${BASE_URL}/api/announcement.save`, method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('announcementModal'))?.hide();
                annTable.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        }
    });
});
function publishAnnouncement(id) {
    showConfirm(
        langData['announcement_publish_confirm_title'] || 'Publish this announcement?',
        langData['announcement_publish_confirm_message'] || 'The recipient list is stamped at this exact moment and can never be changed afterward. Content also becomes read-only.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/announcement.publish`, method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) { showSuccess(res.message || 'Published.'); annTable.ajax.reload(null, false); }
                    else { showWarning(res.message || langData['save_failed'] || 'Failed to publish.'); }
                }
            });
        }
    );
}
function setFeaturedAnnouncement(id) {
    $.ajax({
        url: `${BASE_URL}/api/announcement.set-featured`, method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify({ id: id }),
        success: function (res) {
            if (res.status) { showSuccess(res.message || 'Featured.'); annTable.ajax.reload(null, false); }
            else { showWarning(res.message || langData['save_failed'] || 'Failed.'); }
        }
    });
}
function deleteAnnouncement(id) {
    showConfirm(
        langData['confirm_delete_title'] || 'Delete this item?',
        langData['confirm_delete_message'] || 'This action cannot be undone.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/announcement.delete`, method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) { showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.'); annTable.ajax.reload(null, false); }
                    else { showWarning(res.message || langData['delete_failed'] || 'Failed to delete.'); }
                }
            });
        }
    );
}
function annUpdateClearFilterVisibility() {
    const hasFilter = !!($('#announcement_filter_status').val() || $('#announcement_filter_accept_required').val());
    $('#announcementFilterClearRow').toggleClass('d-none', !hasFilter);
}
$(document).on('change', '#announcement_filter_status, #announcement_filter_accept_required', function () {
    annUpdateClearFilterVisibility();
    if (annTable) annTable.draw();
});
$(document).on('click', '#announcementStationFilterToggle', function () {
    const $filter = $('#announcementStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('click', '#btnClearAnnouncementFilter', function () {
    $('#announcement_filter_status').val('').trigger('change.select2');
    $('#announcement_filter_accept_required').val('').trigger('change.select2');
    annUpdateClearFilterVisibility();
    if (annTable) annTable.draw();
});
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    if ($('#tb_announcement').length) {
        // 2026-09-04, Backlog Phase 11, T066 -- this page had no filter at all. Client-side
        // DataTable (small, bounded per-company list -- see this file's own top-of-file comment),
        // so filtering is a custom $.fn.dataTable.ext.search plugin reading the RAW row data
        // (rowData.status/rowData.accept_required) rather than `.column(N).search()` against the
        // rendered cell -- the status/accept_required columns render icons/badges (HTML), not the
        // raw value, per this table's own plain-function `render:` (not the object-form
        // {display,sort,filter} CLAUDE.md's own Table convention calls for on a formatted column)
        // -- restructuring those render functions is out of this fix's own scope (a separate,
        // narrower T067 finding covered only reports/annual-summary.js), so a raw-data search
        // plugin sidesteps the mismatch entirely without touching the columns array.
        // 2026-09-07, real bug found and fixed (explicit report: "Uncaught TypeError: Cannot read
        // properties of undefined (reading 'ext')" at page load) -- this registration used to be a
        // TOP-LEVEL statement in this file, executing the instant the script tag was parsed. This
        // page's own <script src="announcements.js"> tag (app/views/setup/announcements.php) sits
        // in the page's own content, which Controller::view() always renders BEFORE footer.php --
        // and footer.php is where DataTables' own core script (node_modules/datatables.net/js/
        // dataTables.js) is loaded, so `$.fn.dataTable` didn't exist yet at that point in the page's
        // parse order. Moved inside this `ready()` handler (which only fires once the WHOLE page,
        // footer scripts included, has finished loading) fixes it at the root -- same reason every
        // other DataTable-related call on this page (and every other page in this app) already
        // lives inside a ready handler instead of running at the top level.
        $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
            if (settings.nTable.id !== 'tb_announcement' || !rowData) return true;
            const statusFilter = $('#announcement_filter_status').val();
            if (statusFilter && rowData.status !== statusFilter) return false;
            const acceptFilter = $('#announcement_filter_accept_required').val();
            if (acceptFilter !== '' && acceptFilter !== null && acceptFilter !== undefined) {
                const wantsAccept = acceptFilter === '1';
                if (Boolean(Number(rowData.accept_required)) !== wantsAccept) return false;
            }
            return true;
        });
        initAnnouncementTable();
        initSelect2('#announcement_filter_status');
        initSelect2('#announcement_filter_accept_required');
    }
    });
});
