<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="announcement_menu">Announcements</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-bullhorn"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="announcement_menu">Announcements</h5>
            <p class="page-header-card-desc" data-i18n="announcement_my_list_description">Announcements published to you.</p>
        </div>
    </div>
    <div id="annMyListContainer" class="d-flex flex-column gap-3"></div>
    <p class="text-muted small d-none" id="annMyListEmpty" data-i18n="announcement_none">No announcements yet.</p>
</div>

<script>
function escapeHtmlAnnMy(str) { return $('<div>').text(str === null || str === undefined ? '' : str).html(); }
function annMyItemHtml(row) {
    const title = currentLang === 'en' ? row.title_en : row.title_th;
    // 2026-09-07, Announcement CMS rich-text formatting -- body_th/body_en are now real, sanitized
    // HTML (see AnnouncementModel::sanitizeRichHtml()), authored exclusively by employees holding
    // announcement.manage -- rendered as-is (not escaped) via .ann-rich-content below, same trust
    // boundary/rendering approach as the Dashboard's own click-through modal.
    const body = currentLang === 'en' ? row.body_en : row.body_th;
    const statusBadge = row.acknowledged_at
        ? `<span class="badge bg-success-subtle text-success">${langData['announcement_acknowledged'] || 'Acknowledged'}</span>`
        : `<span class="badge bg-warning-subtle text-warning">${langData['announcement_pending'] || 'Pending'}</span>`;
    const cover = row.cover_image_path ? `<img src="${BASE_URL}/${row.cover_image_path}" alt="" class="ann-cover-banner">` : '';
    return `<div class="card-surface p-3">
        ${cover}
        <div class="d-flex justify-content-between align-items-start mb-1">
            <h6 class="fw-bold mb-0">${escapeHtmlAnnMy(title)}</h6>
            ${statusBadge}
        </div>
        <div class="mb-1 ann-rich-content">${body || ''}</div>
        <span class="text-muted small">${escapeHtmlAnnMy(formatDisplayDateTime ? formatDisplayDateTime(row.published_at) : (row.published_at || ''))}</span>
    </div>`;
}
$(document).ready(function () {
    $.ajax({
        url: `${BASE_URL}/api/announcement.my-list`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const rows = res.data || [];
            if (!rows.length) { $('#annMyListEmpty').removeClass('d-none'); return; }
            $('#annMyListContainer').html(rows.map(annMyItemHtml).join(''));
        }
    });
});
</script>
