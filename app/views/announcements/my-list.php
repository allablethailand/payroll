<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Announcements', 'href' => null, 'i18n' => 'announcement_menu'],
    ];
    $description = 'Announcements published to you.';
    $description_i18n = 'announcement_my_list_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>
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
    const statusBadge = statusBadgeHtml(row.acknowledged_at ? 'acknowledged' : 'pending', 'announcement_ack_status');
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
