<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="announcement_menu">Announcements</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-bullhorn"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="announcement_menu">Announcements</h5>
            <p class="page-header-card-desc" data-i18n="announcement_page_description">Create and publish company-wide announcements. Once published, an announcement's content and recipient list can no longer be edited.</p>
        </div>
    </div>

    <!-- 2026-09-04, Backlog Phase 11, T066: this page had no filter at all (the only list-style page
         in an app-wide audit with no `.station-filter` and no documented exemption). Client-side
         DataTable (small, bounded per-company list, see announcements.js's own top-of-file comment),
         so filtering is wired via DataTables' own column search API (announcements.js), not a
         server round-trip. -->
    <div class="station-filter" id="announcementStationFilter">
        <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="announcementStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="col_status">Status</label>
                    <select class="form-select select2-static" id="announcement_filter_status"
                        data-option-keys="announcement_status_draft,announcement_status_published" data-option-values="draft,published"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="announcement_accept_required">Accept Required</label>
                    <select class="form-select select2-static" id="announcement_filter_accept_required"
                        data-option-keys="yes,no" data-option-values="1,0"></select>
                </div>
            </div>
        </div>
    </div>
    <div class="station-filter-clear-row d-none" id="announcementFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearAnnouncementFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

    <table class="table table-hover table-border align-middle w-100" id="tb_announcement">
        <thead class="table-light text-secondary">
            <tr>
                <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
                <th scope="col" style="width: 26%;" data-i18n="table_title">Title</th>
                <th scope="col" style="width: 10%;" data-i18n="announcement_accept_required">Accept Required</th>
                <th scope="col" style="width: 14%;" data-i18n="announcement_recipients">Recipients</th>
                <th scope="col" style="width: 10%;" data-i18n="announcement_featured">Featured</th>
                <th scope="col" style="width: 15%;" data-i18n="table_last_updated">Last Updated</th>
                <th scope="col" style="width: 15%; text-align: center;"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="announcementModalTitle" data-i18n="announcement_new">New Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ann_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label mb-1" data-i18n="title_th">Title (Thai)</label>
                        <input type="text" class="form-control" id="ann_title_th" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1" data-i18n="title_en">Title (English)</label>
                        <input type="text" class="form-control" id="ann_title_en" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1" data-i18n="body_th">Body (Thai)</label>
                        <textarea class="form-control" id="ann_body_th" rows="5"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1" data-i18n="body_en">Body (English)</label>
                        <textarea class="form-control" id="ann_body_en" rows="5"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="ann_accept_required">
                            <label class="form-check-label" for="ann_accept_required" data-i18n="announcement_accept_required">Accept Required</label>
                        </div>
                        <p class="text-muted small mb-0" data-i18n="announcement_accept_required_hint">If on, the employee must explicitly click Accept before the announcement is considered handled (no dismiss without acting). If off, a plain Dismiss/OK is enough.</p>
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1" data-i18n="announcement_recipients">Recipients</label>
                        <div>
                            <span id="ann_assign_badge"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="annOpenAssignBtn">
                                <i class="fa-solid fa-user-shield me-1"></i><span data-i18n="announcement_edit_recipients">Edit Recipients</span>
                            </button>
                        </div>
                        <p class="text-muted small mb-0 mt-1" data-i18n="announcement_recipients_hint">Leave unscoped to send to every currently-active employee. Only active (not resigned/terminated) employees can ever receive an announcement.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><span data-i18n="cancel">Cancel</span></button>
                <button type="button" class="btn btn-primary" id="annSaveBtn"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<script src="<?=asset('public/js/setup/announcements.js')?>"></script>
