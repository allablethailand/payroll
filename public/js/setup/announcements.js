/* Backlog Phase 10, T057 -- Announcement CMS management page (setup/announcements). Client-side
 * DataTable (small, bounded list per company). "Edit Recipients" reuses T055's assign-widget.js
 * (openAssignModal()/assignSummaryBadgeHtml()) against entity_type='announcement' server-side. */
let annTable = null;
let annCurrentAssignments = [];
let annAssignableOptionsCache = null;

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
            { data: null, render: (d, t, row) => escapeHtml(currentLang === 'th' ? row.title_th : row.title_en) },
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
function resetAnnouncementForm() {
    $('#ann_id').val('');
    $('#ann_title_th').val('');
    $('#ann_title_en').val('');
    $('#ann_body_th').val('');
    $('#ann_body_en').val('');
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
                $('#ann_body_th').val(row.body_th);
                $('#ann_body_en').val(row.body_en);
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
    const bodyTh = $('#ann_body_th').val().trim();
    const bodyEn = $('#ann_body_en').val().trim();
    if (!titleTh || !titleEn || !bodyTh || !bodyEn) {
        showWarning(langData['required_fields_missing'] || 'Please fill in all required fields.');
        return;
    }
    const payload = {
        title_th: titleTh, title_en: titleEn, body_th: bodyTh, body_en: bodyEn,
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
// 2026-09-04, Backlog Phase 11, T066 -- this page had no filter at all. Client-side DataTable
// (small, bounded per-company list -- see this file's own top-of-file comment), so filtering is a
// custom $.fn.dataTable.ext.search plugin reading the RAW row data (rowData.status/
// rowData.accept_required) rather than `.column(N).search()` against the rendered cell -- the
// status/accept_required columns render icons/badges (HTML), not the raw value, per this table's
// own plain-function `render:` (not the object-form {display,sort,filter} CLAUDE.md's own Table
// convention calls for on a formatted column) -- restructuring those render functions is out of
// this fix's own scope (a separate, narrower T067 finding covered only reports/annual-summary.js),
// so a raw-data search plugin sidesteps the mismatch entirely without touching the columns array.
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
    if ($('#tb_announcement').length) {
        initAnnouncementTable();
        initSelect2('#announcement_filter_status');
        initSelect2('#announcement_filter_accept_required');
    }
});
