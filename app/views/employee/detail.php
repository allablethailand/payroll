<style>
/* 2026-08-30 (T025) -- see this bar's own markup comment (search "employee-payroll-participant-bar")
   for why: repoints the left-border accent from Bootstrap's stock amber .border-warning to this
   app's real brand orange, matching the numbered section badges and toggle buttons right next to it. */
.employee-payroll-participant-bar {
    border-left-color: #FF9900 !important;
}
/* 2026-08-30 (T025, optional polish) -- see the Salary tab <li>'s own markup comment. A thin
   vertical rule + extra left spacing right before the Salary tab, marking where "core HR" ends and
   "payroll-specific" (the exact tabs T020 hides together for a staff-only employee) begins. */
.employee-tab-group-divider {
    margin-left: .5rem;
    padding-left: .5rem;
    border-left: 1px solid #dee2e6;
}
/* Employee Detail profile header + completeness (2026-08-19, explicit request). */
.employee-avatar-lg {
    width: 56px;
    height: 56px;
    min-width: 56px;
    border-radius: 50%;
    /* 2026-08-20 color-consistency pass: was a plain #007aff blue that didn't match anything
       else in the app -- reuse the same orange gradient .page-header-card-icon/the profile-
       photo upload badge already use, instead of a one-off color. */
    background: linear-gradient(135deg, #ffab26, #FF9900);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.25rem;
    overflow: hidden;
}
/* 2026-08-30, real photo (synced or uploaded) shown here instead of the initial letter once
   renderProfileHeader() finds a profile_photo_path -- .has-photo drops the gradient background so
   it doesn't show through a transparent PNG's edges, object-fit:cover fills the circle cleanly. */
.employee-avatar-lg.has-photo { background: #eef0f2; }
.employee-avatar-lg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* 2026-08-30, explicit follow-up report ("หัวหลุดวงกลม"): bias the crop toward the top of the
       frame instead of dead-center, same fix applied to every other circular photo display in the
       app (see style.css's .profile-img-box img/#profilePreview and this page's own list.php). */
    object-position: center top;
}
.employee-completeness-summary {
    min-width: 220px;
}
.employee-completeness-summary-bar {
    height: 8px;
    flex-grow: 1;
    background-color: #eef0f2;
}
.completeness-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.9em;
    padding: .05em .4em;
    margin-left: .35em;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 700;
    color: #fff;
    vertical-align: middle;
}
/* Mobile No. field uses intl-tel-input (2026-08-20, replaced the old select2 country-code
   input-group). intl-tel-input's own .iti wrapper defaults to display:inline-block sized to its
   content, so it doesn't stretch to fill the .col-sm-4 the way every other .form-control in this
   form does -- force it to behave like a normal block-level field instead. */
#mobile_no_wrap .iti {
    display: block;
    width: 100%;
}
/* Dependent card icon badge (2026-08-20, "ปรับ Card ให้สวยขึ้น") -- same orange-gradient circular
   treatment as .employee-avatar-lg/.page-header-card-icon above, reused here instead of a one-off
   color so the new inline dependent cards read as part of the same page. */
.dependent-card-icon {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #ffab26, #FF9900);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    margin-top: 1.6rem;
}
/* #eedModal body scroll (2026-08-21, reported still not working after adding Bootstrap's own
   .modal-dialog-scrollable class -- that class/CSS mechanism is verified present and correct
   (node_modules/bootstrap/dist/css/bootstrap.min.css's own .modal-dialog-scrollable rule caps
   .modal-dialog height and sets .modal-body{overflow-y:auto}), and nothing else in this file or
   style.css overrides modal-content/modal-body, so the exact cause couldn't be reproduced/found
   in code review alone here. Reinforcing it explicitly and scoped to just this modal, redundant
   with the Bootstrap class but not dependent on it working, as a robust belt-and-suspenders fix. */
#eedModal .modal-dialog {
    max-height: calc(100vh - 3.5rem);
}
#eedModal .modal-content {
    max-height: calc(100vh - 3.5rem);
    overflow: hidden;
}
/* .modal-content is display:flex;flex-direction:column (Bootstrap's own rule) -- overflow-y:auto
   here is enough on its own, .modal-body naturally flexes to fill whatever space is left under
   the header/footer, no hardcoded height subtraction needed. */
#eedModal .modal-body {
    overflow-y: auto !important;
}
/* Responsive tab scroll (2026-08-21, explicit request: "ตอนนี้จอเล็กมันตกบรรทัดลงมา") -- Bootstrap's
   .nav-tabs wraps onto a second line by default once it can't fit (flex-wrap:wrap), which with 8
   tabs pushes tab-pane content down awkwardly on narrow screens. flex-nowrap + horizontal
   overflow scroll keeps it one line, scrollable, on any width instead. */
#employeeTabs {
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}
#employeeTabs .nav-item {
    flex: 0 0 auto;
}
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="employee">Employee</span>
            <!-- New-employee flow (2026-08-19, explicit request): no "New Employee" placeholder --
                 the 3rd breadcrumb level simply doesn't exist until a real employee_no is assigned by
                 the first save. Revealed by saveEmployee()'s success handler in detail.js. -->
            <span class="bc-separator<?= $employee_no ? '' : ' d-none' ?>" id="bcSeparatorCurrent"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current<?= $employee_no ? '' : ' d-none' ?>" id="bcCurrent"><?= htmlspecialchars($employee_no ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </h5>
    </nav>
    <!-- No .page-header-card here (2026-08-19, explicit follow-up request: "ไม่ต้องมี head ครับ
         ตอนนี้ขึ้นเป็นณุปกับชื่อสวยแล้ว") -- tried it, but #employeeProfileHeader below already reads
         as this page's header (avatar + name) once an employee exists, and duplicating a second
         static header above it was redundant. Employee List keeps its own .page-header-card --
         see that class's definition in style.css for the still-standing "apply to every OTHER page"
         convention; Detail is a deliberate exception to it, not a sign the convention was dropped. -->
    <!-- Profile header + completeness summary (2026-08-19, explicit request): hidden until an
         existing employee's data actually loads (loadEmployeeIfEditing() -> renderProfileHeader())
         -- nothing meaningful to summarize yet on the "New Employee" create flow. -->
    <div class="card-surface mb-4 employee-profile-header d-none" id="employeeProfileHeader">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="employee-avatar-lg" id="profileHeaderAvatar">?</div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h5 class="fw-bold mb-0" id="profileHeaderName">-</h5>
                    <span id="profileHeaderStatusBadge"></span>
                </div>
                <div class="text-muted small mt-1" id="profileHeaderMeta">-</div>
            </div>
            <div class="employee-verify-status-summary text-sm-end">
                <div class="text-muted small mb-1" data-i18n="verify_status_title">Verify Status</div>
                <span class="badge" id="profileVerifyStatusBadge"></span>
            </div>
            <div class="employee-completeness-summary">
                <div class="text-muted small mb-1 text-sm-end" data-i18n="profile_completeness_title">Profile Completeness</div>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress employee-completeness-summary-bar">
                        <div class="progress-bar" id="profileCompletenessBar" role="progressbar" style="width:0%;"></div>
                    </div>
                    <span class="fw-bold fs-5" id="profileCompletenessPercent">0%</span>
                </div>
            </div>
        </div>
        <!-- 2026-08-28, explicit request: "เพิ่มปุ่ม Re Sync รายบุคคลของพนักงาน และมีประวัติการ Sync โชว์
             ในหน้าพนักงานด้วย"; repositioned same-day follow-up ("ปรับปุ่ม Sync ใหม่ในตำแหน่งที่ดูดีขึ้น")
             -- moved OUT of the cramped avatar/verify-status/completeness stat row above (a 4th
             text-sm-end column there fought the other 3 for space, especially on medium screens) and
             into its own full-width strip along the bottom edge of the header card instead, separated
             by a border like a card footer -- room to breathe, and reads as a distinct "integration
             status" line rather than another stat crammed among the others.
             Whole strip starts d-none -- only ever shown once populateEmployeeForm() confirms
             IS_ORIGAMI_HR_LINKED is true (this company is connected to Origami HR at all); no longer
             ALSO gated on this employee already having origami_ref_id set (see detail.js's own
             updateOrigamiSyncSummary() docblock for why -- manually-created employees now get a
             working Sync button too, matched by employee_no against Origami instead of ref_id). -->
        <div class="employee-origami-sync-summary d-flex flex-wrap align-items-center justify-content-between gap-2 border-top pt-3 mt-3 d-none" id="employeeOrigamiSyncSummary">
            <div class="d-flex align-items-center gap-2 text-muted small">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span class="fw-semibold" data-i18n="origami_sync_summary_title">Origami Sync</span>
                <span>&middot;</span>
                <span id="profileLastSyncedText">-</span>
            </div>
            <!-- 2026-08-29, explicit request: "ปุ่ม Sync ให้เปลี่ยนเป็นสีฟ้าทั้งในหน้า List และ Detail" --
                 btn-outline-info specifically, not btn-outline-primary (this app's own --bs-primary
                 override repoints that at brand orange, see style.css's ".btn-primary" section; --bs-info
                 was never touched, so it's still Bootstrap's real cyan-blue). -->
            <button type="button" class="btn btn-outline-info btn-sm" id="btnResyncOneEmployee">
                <i class="fa-solid fa-rotate me-1"></i><span id="btnResyncOneEmployeeLabel" data-i18n="employee_sync_resync_one_button">Re-Sync from Origami</span>
            </button>
        </div>
    </div>
    <ul class="nav nav-tabs" id="employeeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-pane" type="button" role="tab" aria-controls="info-pane" aria-selected="true"><i class="fa-solid fa-circle-user me-1"></i><span data-i18n="employee_info">Employee Info</span><span class="completeness-tab-badge d-none" data-tab-key="info"></span></button>
        </li>
        <!-- New-employee flow (2026-08-19, explicit request): only the Info tab shows until the
             employee record actually exists -- jumping to Contact/Employment/Salary/etc before Info
             is even saved once meant editing tabs whose Save button had no employee_id to attach to
             yet (several already warn "Please save the employee's basic info first" for exactly this
             reason -- e.g. Earning/Deduction, Dependent/Parent). employee-secondary-tab is revealed by
             saveEmployee()'s success handler in detail.js the moment the FIRST save creates the row
             (currentEmployeeId was null going in), not tied to which tab that save happened on. -->
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-pane" type="button" role="tab" aria-controls="contact-pane" aria-selected="false"><i class="fa-solid fa-address-book me-1"></i><span data-i18n="contact">Contact</span><span class="completeness-tab-badge d-none" data-tab-key="contact"></span></button>
        </li>
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="employment-tab" data-bs-toggle="tab" data-bs-target="#employment-pane" type="button" role="tab" aria-controls="employment-pane" aria-selected="false"><i class="fa-solid fa-building-user me-1"></i><span data-i18n="employment">Employment</span><span class="completeness-tab-badge d-none" data-tab-key="employment"></span></button>
        </li>
        <!-- 2026-08-30 (T025, optional polish per the fork's own audit -- "core HR" (Info/Contact/
             Employment's org-placement half) vs "payroll-specific" (Salary through Family/Tax
             Allowance, the exact tabs T020's Payroll Participation toggle hides together) is a REAL
             functional boundary now, not just a visual grouping choice -- a thin divider here makes
             it readable in the tab bar itself, even before an admin toggles that switch. -->
        <li class="nav-item employee-secondary-tab employee-tab-group-divider<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="salary-tab" data-bs-toggle="tab" data-bs-target="#salary-pane" type="button" role="tab" aria-controls="salary-pane" aria-selected="false"><i class="fa-solid fa-file-invoice-dollar me-1"></i><span data-i18n="salary">Salary</span><span class="completeness-tab-badge d-none" data-tab-key="salary"></span></button>
        </li>
        <!-- Split out of the Salary tab (2026-08-19, explicit request: "รายรับ รายหัก อาจแยกออกมาจาก
             Tab เงินเดือน...และไม่ต้องมีการคิด %") -- deliberately no completeness-tab-badge span here
             and no key for it in EmployeeModel::calculateCompleteness()'s $tabs, so it never
             participates in the completeness score at all (items here are optional/variable per
             employee, same reasoning as why dependents/parents were never counted either). -->
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="earningDeduction-tab" data-bs-toggle="tab" data-bs-target="#earningDeduction-pane" type="button" role="tab" aria-controls="earningDeduction-pane" aria-selected="false"><i class="fa-solid fa-money-bill-transfer me-1"></i><span data-i18n="earning_deduction_assignments">Income & Deductions</span></button>
        </li>
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="social-tab" data-bs-toggle="tab" data-bs-target="#social-pane" type="button" role="tab" aria-controls="social-pane" aria-selected="false"><i class="fa-solid fa-hospital-user me-1"></i><span data-i18n="social_security">Social Security</span><span class="completeness-tab-badge d-none" data-tab-key="social"></span></button>
        </li>
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="family-tab" data-bs-toggle="tab" data-bs-target="#family-pane" type="button" role="tab" aria-controls="family-pane" aria-selected="false"><i class="fa-solid fa-people-roof me-1"></i><span data-i18n="family_tax">Family / Tax Allowance</span><span class="completeness-tab-badge d-none" data-tab-key="family"></span></button>
        </li>
        <!-- 2026-09-03: re-enabled (was hidden 2026-08-19 as "not needed for Payroll" -- that's no
             longer true now that Origami-synced passport/visa/work-permit document scans need
             somewhere to be viewed, see EmployeeSyncer::syncDocumentScans()'s own docblock). Same
             progressive-reveal gate as the other secondary tabs (earningDeduction/social/family) --
             a brand-new, not-yet-saved employee has no employee_id to attach a document to yet
             either way (uploadDocumentFile() itself already refuses without one). Backend/JS were
             never touched by the 2026-08-19 hide, so nothing else needed reverting here. -->
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
            <button class="nav-link text-secondary" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents-pane" type="button" role="tab" aria-controls="documents-pane" aria-selected="false"><i class="fa-solid fa-paperclip me-1"></i><span data-i18n="documents">Documents</span></button>
        </li>
        <!-- 2026-08-29, explicit request: "ต้องการอีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบ" -- new
             tab, only shown once a real employee is loaded (a brand-new employee has no login history
             to show yet -- see detail.js's own toggle on this <li> at the same point new-employee
             progressive reveal already hides other not-yet-relevant tabs). -->
        <li class="nav-item d-none" role="presentation" id="loginHistoryTabItem">
            <button class="nav-link text-secondary" id="login-history-tab" data-bs-toggle="tab" data-bs-target="#login-history-pane" type="button" role="tab" aria-controls="login-history-pane" aria-selected="false"><i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="login_history">Login History</span></button>
        </li>
        <!-- 2026-09-03, Platform Hardening Phase 3 Stage 5 -- per-employee permission override tab.
             Two conditions gate this <li>, ANDed together: `$employee_no` (a brand-new, not-yet-
             saved employee has no employee_id to attach overrides to yet, same progressive-reveal
             precedent as every other secondary tab on this page) AND `$canManagePermissionOverrides`
             (computed server-side in EmployeeController::detail(), same `rbac.view` check the
             Permission Matrix's own menu-visibility gate in header.php uses -- this tab is never even
             sent to the DOM for someone who can't already manage the Permission Matrix, not merely
             hidden client-side, since it lets its holder grant/deny ANY permission in the system to
             ANY employee). -->
        <?php if (!empty($canManagePermissionOverrides)): ?>
        <li class="nav-item d-none" role="presentation" id="permissionOverridesTabItem">
            <button class="nav-link text-secondary" id="permission-overrides-tab" data-bs-toggle="tab" data-bs-target="#permission-overrides-pane" type="button" role="tab" aria-controls="permission-overrides-pane" aria-selected="false"><i class="fa-solid fa-user-shield me-1"></i><span data-i18n="permission_overrides">Permission Overrides</span></button>
        </li>
        <?php endif; ?>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-5" id="employeeTabsContent">
        <div class="tab-pane fade show active" id="info-pane" role="tabpanel" aria-labelledby="info-tab" tabindex="0">
            <div class="d-flex justify-content-center mb-4">
                <div class="position-relative">
                    <label for="profile_photo_input" class="d-flex flex-column align-items-center justify-content-center rounded-circle bg-light border profile-upload-circle" style="width:100px;height:100px;">
                        <img id="profilePreview" class="rounded-circle w-100 h-100 d-none" src="" alt="">
                        <span id="profilePlaceholder" class="text-center">
                            <i class="fas fa-camera fs-5 d-block"></i>
                            <small class="text-secondary" data-i18n="upload_profile">Upload profile</small>
                        </span>
                    </label>
                    <span class="badge bg-brand rounded-circle d-flex align-items-center justify-content-center position-absolute bottom-0 end-0 profile-upload-badge"
                        onclick="document.getElementById('profile_photo_input').click()">
                        <i class="fas fa-camera"></i>
                    </span>
                    <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" class="d-none">
                    <!-- 2026-08-29: the file input above only ever fed a local FileReader preview --
                         nothing actually uploaded it or included it in saveEmployee()'s JSON payload
                         (a <input type="file"> can never survive JSON.stringify), so the photo always
                         reverted to nothing after a real save/reload. Same upload-then-hidden-field
                         convention as #emp_signature_path below: this hidden field is the real value
                         that travels with the form (collectEmployeeFormData() reads it generically,
                         populateEmployeeForm() sets it generically), set by uploadEmpPhotoBlob()'s
                         own AJAX call the moment a file is chosen -- not deferred until Save. -->
                    <input type="hidden" id="emp_profile_photo_path" name="profile_photo_path" value="">
                    <input type="hidden" id="emp_profile_photo_file_size" name="profile_photo_file_size" value="">
                    <input type="hidden" id="emp_profile_photo_thumbnail_path" name="profile_photo_thumbnail_path" value="">
                </div>
            </div>
            <!-- 2026-08-30 (Phase 3, T020, explicit request: field "จ่าย/ไม่จ่ายเงินเดือน", default =
                 จ่ายเงินเดือน) -- placed prominently above every tab's numbered section (not buried
                 inside one tab) since it governs which tabs/fields the rest of the WHOLE form shows;
                 lives outside #employeeProfileHeader (that card starts d-none until an existing
                 employee's data loads, but this toggle must be settable on the New Employee flow too,
                 before there's anything to summarize). Same .btn-check radio-group pattern as Employee
                 Type/Gender elsewhere on this page, not a plain checkbox (this project's own
                 established "checkbox -> Yes/No radio" convention). -->
            <!-- 2026-08-30 (T025, real color clash found while auditing this page for "แก้สีที่แย่งกัน"):
                 was Bootstrap's stock .border-warning (amber #ffc107, unmodified) -- sat directly
                 above the "1" section badge (brand orange #FF9900, see this page's own numbered
                 sections) and this same row's own brand-orange Yes/No toggle buttons, a 3rd distinct
                 orange/amber tone in the same visual neighborhood. .employee-payroll-participant-bar
                 (own rule, this file's <style> block) repoints the left border to brand orange --
                 needs !important because Bootstrap's own .border-start utility sets border-left-color
                 with !important too, same reason .border-warning won in the first place. -->
            <div class="card-surface p-3 mb-4 border-start border-4 employee-payroll-participant-bar d-flex flex-wrap align-items-center justify-content-between gap-3" id="employeePayrollParticipantBar">
                <div>
                    <div class="fw-bold" data-i18n="payroll_participant_label">Payroll Participation</div>
                    <div class="text-muted small" data-i18n="payroll_participant_hint">If set to "No Salary", every payroll-related field/tab is hidden and this employee is excluded from payroll runs and reports entirely.</div>
                </div>
                <div>
                    <div class="btn-group d-block" role="group" aria-label="Payroll participation">
                        <input type="radio" class="btn-check" name="is_payroll_participant_radio" id="payroll_participant_yes" value="1" checked>
                        <label class="btn btn-outline-brand" for="payroll_participant_yes" data-i18n="payroll_participant_yes">Pays Salary</label>
                        <input type="radio" class="btn-check" name="is_payroll_participant_radio" id="payroll_participant_no" value="0">
                        <label class="btn btn-outline-brand" for="payroll_participant_no" data-i18n="payroll_participant_no">No Salary</label>
                    </div>
                    <input type="hidden" name="is_payroll_participant" id="is_payroll_participant" value="1">
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="personal_information">Personal Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="type">Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="btn-group d-block" role="group" aria-label="Employee type">
                        <input type="radio" class="btn-check" name="employee_type_radio" id="type_domestic" value="domestic" checked>
                        <label class="btn btn-outline-brand" for="type_domestic" data-i18n="domestic">Domestic</label>
                        <input type="radio" class="btn-check" name="employee_type_radio" id="type_foreigner" value="foreigner">
                        <label class="btn btn-outline-brand" for="type_foreigner" data-i18n="foreigner">Foreigner</label>
                    </div>
                    <input type="hidden" name="employee_type" id="employee_type" value="domestic">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employee_status">Employee Status</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="employee_status" id="employee_status">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="active" data-i18n="status_active">Active</option>
                        <option value="probation" data-i18n="status_probation">Probation</option>
                        <option value="suspended" data-i18n="status_suspended">Suspended</option>
                        <option value="resigned" data-i18n="status_resigned">Resigned</option>
                        <option value="terminated" data-i18n="status_terminated">Terminated</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="title">Title</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="title" id="title">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="mr" data-i18n="title_mr">Mr.</option>
                        <option value="mrs" data-i18n="title_mrs">Mrs.</option>
                        <option value="ms" data-i18n="title_ms">Ms.</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="gender">Gender</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="btn-group d-block" role="group" aria-label="Gender">
                        <input type="radio" class="btn-check" name="gender_radio" id="gender_male" value="male" checked>
                        <label class="btn btn-outline-brand" for="gender_male" data-i18n="male">Male</label>
                        <input type="radio" class="btn-check" name="gender_radio" id="gender_female" value="female">
                        <label class="btn btn-outline-brand" for="gender_female" data-i18n="female">Female</label>
                        <input type="radio" class="btn-check" name="gender_radio" id="gender_other" value="other">
                        <label class="btn btn-outline-brand" for="gender_other" data-i18n="other">Other</label>
                    </div>
                    <input type="hidden" name="gender" id="gender" value="male">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="name_local">Name (Local)</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="name_th" id="name_th" data-i18n="name_th_placeholder" placeholder="e.g., สมชาย">
                </div>
                <div class="col-sm-2 mt-3">
                    <!-- 2026-08-30 (T023, explicit request: "นามสกุลไม่เป็น required field") -- red asterisk
                         + .required removed; first name (name_th/name_en) stays required, surname does not. -->
                    <label class="form-label"><span data-i18n="surname_local">Surname (Local)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="surname_th" id="surname_th" data-i18n="surname_th_placeholder" placeholder="e.g., ใจดี">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="name_en">Name (EN)</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="name_en" id="name_en" data-i18n="name_en_placeholder" placeholder="e.g., Somchai">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="surname_en">Surname (EN)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="surname_en" id="surname_en" data-i18n="surname_en_placeholder" placeholder="e.g., Jaidee">
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): cosmetic-only, not used in any statutory
                 calc/report. Field stays in the DOM (value still submits/saves/syncs normally). -->
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="nickname_local">Nickname (Local)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="nickname_th" id="nickname_th" data-i18n="employee_nickname_th_placeholder" placeholder="e.g., ชาย">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="nickname_en">Nickname (EN)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="nickname_en" id="nickname_en" data-i18n="employee_nickname_en_placeholder" placeholder="e.g., Chai">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="date_of_birth">Date of Birth</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker required" name="date_of_birth" id="date_of_birth" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="nationality">Nationality</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="nationality" id="nationality" data-api="/api/nationality.get" data-type="nationality"></select>
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): religion isn't used in any calc/report.
                 marital_status is superseded here by the dedicated "has spouse with no income" tax
                 allowance checkbox on the Family / Tax Allowance tab, which is what actually drives
                 the tax deduction -- this field is a duplicate HR record, not a second source of truth.
                 Both fields stay in the DOM (values still submit/save/sync normally). -->
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="religion">Religion</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="religion" id="religion" data-api="/api/religion.get" data-type="religion"></select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="marital_status">Marital Status</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native" name="marital_status" id="marital_status">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="single" data-i18n="single">Single</option>
                        <option value="married" data-i18n="married">Married</option>
                        <option value="divorced" data-i18n="divorced">Divorced</option>
                        <option value="widowed" data-i18n="widowed">Widowed</option>
                    </select>
                </div>
            </div>
            <div class="row d-none" id="militaryStatusGroup">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="military_status">Military Status</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native" name="military_status" id="military_status">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="exempted" data-i18n="exempted">Exempted</option>
                        <option value="served" data-i18n="served">Served</option>
                        <option value="not_yet" data-i18n="not_yet">Not yet drafted</option>
                        <option value="na" data-i18n="na">N/A</option>
                    </select>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="identification">Identification</span>
            </h6>
            <p class="text-secondary small ms-5 mb-4" data-i18n="identification_hint">*Fields shown here depend on the selected Type above.</p>
            <div class="d-none" id="sectionDomestic">
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="id_card_no">ID Card No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="id_card_no" id="id_card_no" maxlength="13" data-i18n="id_card_no_placeholder" placeholder="13-digit national ID number">
                        <div class="invalid-feedback" id="id_card_error" data-i18n="invalid_id_card">Invalid ID card number. Please try again.</div>
                    </div>
                    <!-- Hidden 2026-08-19 (not needed for Payroll): not used in any calc/report. -->
                    <div class="col-sm-2 mt-3 d-none">
                        <label class="form-label"><span data-i18n="id_card_expire">ID Card Expire Date</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 d-none">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="id_card_expire_date" id="id_card_expire_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-none" id="sectionForeigner">
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="tax_id_no">Tax ID No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="tax_id_no" id="tax_id_no" data-i18n="tax_id_placeholder" placeholder="e.g., 1234567890123">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="passport_no">Passport No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="passport_no" id="passport_no" data-i18n="passport_no_placeholder" placeholder="e.g., AA1234567">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="passport_expire">Passport Expire Date</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="passport_expire_date" id="passport_expire_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="work_permit_no">Work Permit No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="work_permit_no" id="work_permit_no" data-i18n="work_permit_no_placeholder" placeholder="e.g., WP-1234567">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="date_work_permit_issue">Date Work Permit Issue</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="date_work_permit_issue" id="date_work_permit_issue" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="date_work_permit_expire">Date Work Permit Expire</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="date_work_permit_expire" id="date_work_permit_expire" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
                <!-- 2026-09-02, extends the earlier Origami candidates.php field batch (passport_no/
                     work_permit_no/visa_type already existed) -- field shapes confirmed directly
                     from Origami's own candidates.php source, not guessed. Zero new JS wiring needed
                     -- collectEmployeeFormData()/populateEmployeeForm() already handle a plain named
                     input/datepicker generically once it's in EmployeeModel::allColumns(). -->
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="passport_issued_place">Passport Issued Place</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="passport_issued_place" id="passport_issued_place" data-i18n="place_example_placeholder" placeholder="e.g., Bangkok">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="passport_issue_date">Passport Issue Date</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="passport_issue_date" id="passport_issue_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="work_permit_issued_place">Work Permit Issued Place</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="work_permit_issued_place" id="work_permit_issued_place" data-i18n="place_example_placeholder" placeholder="e.g., Bangkok">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="visa_no">Visa No.</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="visa_no" id="visa_no" data-i18n="visa_no_placeholder" placeholder="e.g., V1234567">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="visa_issued_place">Visa Issued Place</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="visa_issued_place" id="visa_issued_place" data-i18n="place_example_placeholder" placeholder="e.g., Bangkok">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="visa_issue_date">Visa Issue Date</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="visa_issue_date" id="visa_issue_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="visa_type">Visa Type</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="visa_type" id="visa_type" data-i18n="visa_type_placeholder" placeholder="e.g., Non-B">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="date_visa_expire">Visa Expire Date</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="date_visa_expire" id="date_visa_expire" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
                <!-- 2026-09-02, explicit request following an AskUserQuestion exchange -- plain
                     per-employee flag, only takes effect when the company enables + sets a flat
                     rate in Tax & Statutory settings' "Non-Resident Foreign Tax" tab (off by
                     default everywhere, zero behavior change unless both are set). See
                     NonResidentTaxSettingModel's own docblock for the full reasoning. -->
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="tax_non_resident">Tax Non-Resident</label>
                    </div>
                    <div class="col-sm-10 mt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="tax_non_resident" id="tax_non_resident">
                            <label class="form-check-label text-muted small" for="tax_non_resident" data-i18n="tax_non_resident_hint">This employee is a tax non-resident (foreign worker present &lt;180 days/year in Thailand) -- only affects withholding if a flat rate is configured in Tax &amp; Statutory settings.</label>
                        </div>
                    </div>
                </div>
                <!-- 2026-09-02, explicit request: "ช่วยดูความเหมาะสมของ Form แต่ละกลุ่มอีกที...ส่วนไหนควรแยก"
                     -- promoted from a plain unnumbered <h6> sub-heading buried inside section "2
                     Identification" to its own numbered section (was genuinely a different topic:
                     recruitment agency/arrival logistics/foreign address, not an identity DOCUMENT
                     like ID/passport/visa above it) -- Signature renumbered 3->4 to make room.
                     foreign_worker_info (Thai-immigration-arrival-card-style reference data,
                     Origami's own m_employee_foreign) lives in its own table
                     (EmployeeForeignWorkerDetailModel), purely informational, never read by any
                     payroll calculation. Still nested inside #sectionForeigner (same
                     employee_type='foreigner' gate as everything else in this d-none block) --
                     only its heading changed, not its visibility condition. -->
                <h6 class="text-secondary fw-bold mt-4 mb-3">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                    <span data-i18n="foreign_worker_info_section">Foreign Worker Info</span>
                </h6>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="recruitment_agency">Recruitment Agency</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="recruitment_agency" id="recruitment_agency" data-i18n="recruitment_agency_placeholder" placeholder="e.g., ABC Recruitment Co., Ltd.">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="arrival_card_no">Arrival Card No.</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="arrival_card_no" id="arrival_card_no" data-i18n="arrival_card_no_placeholder" placeholder="e.g., TM.6 card number">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="arrival_date">Arrival Date</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="arrival_date" id="arrival_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="due_date">Due Date</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="due_date" id="due_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="arrival_by_vehicle">Arrival By (Vehicle)</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="arrival_by_vehicle" id="arrival_by_vehicle" data-i18n="arrival_by_vehicle_placeholder" placeholder="e.g., Flight TG123 / Bus">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_address">Address (Non-Thai)</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address" id="foreign_worker_address" data-i18n="address_line_1_placeholder" placeholder="House no., building, street">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_soi">Soi</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="soi" id="foreign_worker_soi" data-i18n="foreign_worker_soi_placeholder" placeholder="e.g., Soi 5">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_province">Province</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="province" id="foreign_worker_province" data-i18n="place_example_placeholder" placeholder="e.g., Bangkok">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_district">District</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="district" id="foreign_worker_district" data-i18n="foreign_worker_district_placeholder" placeholder="e.g., Watthana">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_sub_district">Sub-District</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="sub_district" id="foreign_worker_sub_district" data-i18n="foreign_worker_sub_district_placeholder" placeholder="e.g., Khlong Toei Nuea">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label" data-i18n="foreign_tel">Phone (Non-Thai)</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <span class="input-group-text" style="max-width:35%;">
                                <input type="text" class="form-control border-0 p-0" name="tel_code" id="foreign_worker_tel_code" placeholder="+00" style="width:100%;">
                            </span>
                            <input type="text" class="form-control" name="tel" id="foreign_worker_tel" data-i18n="phone_no_placeholder" placeholder="e.g., 0812345678">
                        </div>
                    </div>
                </div>
            </div>
            <!-- 2026-08-26, explicit request: "ย้าย Tab Signature มาไว้ใน Info" -- moved here from the
                 Contact tab (where it sat since it was first built), same upload-or-draw card as
                 Company Profile's own Authorized Signature section. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">4</label>
                <span data-i18n="employee_signature">Signature</span>
            </h6>
            <div class="row">
                <div class="col-sm-6 mt-3">
                    <div class="cp-logo-upload-card" id="empSignatureUploadCard">
                        <div class="cp-logo-preview-box" id="empSignaturePreviewBox">
                            <img id="empSignaturePreviewImg" src="" alt="Signature" class="d-none">
                            <div class="cp-logo-placeholder" id="empSignaturePlaceholder">
                                <i class="fa-solid fa-signature"></i>
                                <span data-i18n="no_signature_uploaded">No signature yet</span>
                            </div>
                        </div>
                        <div class="cp-logo-actions">
                            <label class="btn btn-outline-secondary btn-sm" for="emp_signature_file">
                                <i class="fa-solid fa-upload me-1"></i><span data-i18n="upload_signature">Upload Image</span>
                            </label>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="empDrawSignatureBtn">
                                <i class="fa-solid fa-pen-nib me-1"></i><span data-i18n="draw_signature">Draw Signature</span>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm d-none" id="empSignatureRemoveBtn">
                                <i class="fa-solid fa-trash me-1"></i><span data-i18n="remove">Remove</span>
                            </button>
                            <input type="file" id="emp_signature_file" accept=".jpg,.jpeg,.png,.svg" class="d-none">
                            <input type="hidden" name="signature_path" id="emp_signature_path">
                            <input type="hidden" name="signature_file_size" id="emp_signature_file_size">
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-5">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextContact">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <div class="tab-pane fade" id="contact-pane" role="tabpanel" aria-labelledby="contact-tab" tabindex="0">
            <!-- Hidden 2026-08-19 (not needed for Payroll): internal work contact info -- personal_email/
                 mobile_no/line_id below are the ones actually used by Payslip Distribution delivery.
                 Fields stay in the DOM (values still submit/save/sync normally) -- only display:none. -->
            <div class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-2">
                    <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                    <span data-i18n="work_contact">Work Contact Information</span>
                </h6>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="company_email">Company Email</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="email" class="form-control" name="company_email" id="company_email" data-i18n="company_email_placeholder" placeholder="e.g., name@company.com">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="office_tel">Telephone No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="office_tel" id="office_tel" data-i18n="phone_no_placeholder" placeholder="e.g., 0812345678">
                    </div>
                    <div class="col-sm-12 mt-3">
                        <input type="checkbox" class="me-2" name="send_signin_email" id="send_signin_email"><span data-i18n="send_signin_instruction">Send the sign-in instruction email</span>
                    </div>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="contact_information">Contact Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="personal_email">Personal Email Address</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="email" class="form-control required" name="personal_email" id="personal_email" data-i18n="personal_email_placeholder" placeholder="e.g., name@email.com">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="mobile_no">Mobile No.</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3" id="mobile_no_wrap">
                    <!-- Country flag/dial-code picker via intl-tel-input (2026-08-20, replaced the
                         old hardcoded 4-country select2 dropdown -- real flag icons + full country
                         list + number-length validation instead of a fixed TH/SG/MY/US list).
                         mobile_country_code stays a hidden input kept in sync by detail.js
                         (syncMobileCountryCode()) so the submitted shape ("+66" + national digits
                         in mobile_no) is unchanged from before -- backend validation/schema untouched. -->
                    <input type="tel" class="form-control required" name="mobile_no" id="mobile_no" maxlength="15" data-i18n="phone_no_placeholder" placeholder="e.g., 0812345678">
                    <input type="hidden" name="mobile_country_code" id="mobile_country_code" value="+66">
                </div>
                <!-- Hidden 2026-08-19 (not needed for Payroll): preboarding-portal onboarding action, not a payroll field. -->
                <div class="col-sm-12 mt-3 d-none">
                    <input type="checkbox" class="me-2" name="send_preboarding_email" id="send_preboarding_email"><span data-i18n="send_preboarding">Send preboarding access email.</span>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="line_id">LINE ID</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="line_id" id="line_id" data-i18n="line_id_placeholder" placeholder="e.g., somchai_j">
                </div>
            </div>
            <!-- 2026-08-26, explicit request: "เพิ่มให้ใส่ที่อยู่ของพนักงาน" -- re-shown (was hidden
                 2026-08-19 as part of a combined "registered/contact address and emergency contact"
                 decision, see CLAUDE.md/memory). Only ADDRESS is un-hidden here -- Emergency Contacts
                 (below) was not part of this request, so it stays in its own `d-none` wrapper.
                 Renumbered 2/3 (was 3/4) to fill the gap left by "Contact Information" above being "1"
                 -- these fields were never `.required` even before being hidden (made nullable in the
                 DB per the earlier trim), so re-showing them doesn't affect save validation or profile
                 completeness. The `.autocomplete-address`/`master-address-id-field` widget itself
                 (`public/js/input.js`) is delegated/page-agnostic -- the exact same mechanism Company
                 Profile's own address field already uses -- so it works immediately with zero JS
                 changes, matching this request's "ให้ล้อมาจากการตั้งค่าบริษัท". -->
            <div>
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                    <span data-i18n="register_address">Register Address</span>
                </h6>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_1">Address Line 1</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_1_register" id="address_line_1_register" data-i18n="address_line_1_placeholder" placeholder="House no., building, street">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_2">Address Line 2 (Optional)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_2_register" id="address_line_2_register" data-i18n="address_line_2_placeholder" placeholder="Sub-district, district, province">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 position-relative">
                        <input type="text" class="form-control autocomplete-address" id="search_address_register" autocomplete="off" data-i18n="map_search_placeholder" placeholder="Search for an address...">
                        <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                        <input type="hidden" name="master_address_id_register" class="master-address-id-field" id="master_address_id_register">
                    </div>
                </div>
                <!-- 2026-09-02, explicit request: "ถ้ามีส่งมาให้ให้ Admin Match เอง ต้องมีอะไรบอก และแสดงข้อมูลที่
                     Sync มาเพื่อให้ Admin รู้" -- address_line_1_register/address_line_2_register can
                     already be populated (from Origami sync OR manual entry) while
                     master_address_id_register stays empty (this app deliberately doesn't attempt
                     automatic text-to-master_addresses matching, see CLAUDE.md/this app's own
                     project_org_structure_sync-adjacent notes on province/district name-matching
                     risk) -- previously nothing on this page itself said so; the only signal was the
                     Recheck tab's generic "required field missing" flag on a completely separate
                     page. This alert shows right where the admin needs to act, with the actual text
                     to search with restated so they don't have to scroll back up to re-read it.
                     updateAddressMatchIndicator('register') in detail.js toggles it on
                     populateEmployeeForm() and hides it the moment a real match is picked via the
                     autocomplete-address widget below (or #use_register_address is unchecked with
                     nothing typed). -->
                <div class="alert alert-warning py-2 px-3 small mt-2 d-none" id="addressUnmatchedAlertRegister">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <span data-i18n="address_unmatched_warning">This address text hasn't been matched to a standard address record yet. Please search below and select a match.</span>
                    <span class="d-block mt-1"><span class="fw-semibold" data-i18n="address_unmatched_current_text">Current text</span>: <span id="addressUnmatchedTextRegister"></span></span>
                </div>
                <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="address_guide">Please enter your postal code, city/district, and state/province.</span></p>
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                    <span data-i18n="contact_address">Current Address</span>
                </h6>
                <div class="row">
                    <div class="col-sm-12 mt-3">
                        <input type="checkbox" class="me-2" name="use_register_address" id="use_register_address"><span data-i18n="use_register_address">Use register address</span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_1">Address Line 1</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_1_contact" id="address_line_1_contact" data-i18n="address_line_1_placeholder" placeholder="House no., building, street">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_2">Address Line 2 (Optional)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_2_contact" id="address_line_2_contact" data-i18n="address_line_2_placeholder" placeholder="Sub-district, district, province">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 position-relative">
                        <input type="text" class="form-control autocomplete-address" id="search_address_contact" autocomplete="off" data-i18n="map_search_placeholder" placeholder="Search for an address...">
                        <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                        <input type="hidden" name="master_address_id_contact" class="master-address-id-field" id="master_address_id_contact">
                    </div>
                </div>
                <!-- Same "not yet matched" indicator as the Register Address block above, own copy
                     of the same alert scoped to the Current Address fields -- see that block's own
                     comment for the full reasoning. -->
                <div class="alert alert-warning py-2 px-3 small mt-2 d-none" id="addressUnmatchedAlertContact">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <span data-i18n="address_unmatched_warning">This address text hasn't been matched to a standard address record yet. Please search below and select a match.</span>
                    <span class="d-block mt-1"><span class="fw-semibold" data-i18n="address_unmatched_current_text">Current text</span>: <span id="addressUnmatchedTextContact"></span></span>
                </div>
                <!-- 2026-08-26, explicit request: "ส่วนของที่อยู่ให้เพิ่มสามารถปักหมุด Location บน Map ได้"
                     -- one pin for the CONTACT address (where the employee can actually be reached),
                     OpenStreetMap/Leaflet (see #empMapPinModal near the end of this file for the map
                     itself -- a <template> tab-pane's content is inert until cloned, so the modal with
                     the live Leaflet map lives outside every tab-pane, same reasoning Company Profile's
                     own signature-pad modal already established). -->
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="map_location">Map Location</span></label>
                    </div>
                    <div class="col-sm-8 mt-3 d-flex align-items-center flex-wrap gap-2">
                        <!-- 2026-08-26, explicit request: "ถ้ามี Pin Location แล้วให้สามารถลบ Pin ได้ด้วยมี
                             สัญลักษร์บอกว่า Pin หรือยังไม่ Pin" -- status icon toggles red-pin/muted-circle
                             via #updateMapPinStatusUi() in detail.js, same show/hide convention as the
                             Signature card's own preview/placeholder pair above. -->
                        <i class="fa-solid fa-location-dot text-danger d-none" id="mapPinStatusIconPinned" title="Pinned"></i>
                        <i class="fa-regular fa-circle text-muted" id="mapPinStatusIconUnpinned" title="Not pinned"></i>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPinMapLocation">
                            <i class="fa-solid fa-map-location-dot me-1"></i><span data-i18n="pin_location_on_map">Pin Location on Map</span>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="btnRemoveMapPin">
                            <i class="fa-solid fa-trash me-1"></i><span data-i18n="remove">Remove</span>
                        </button>
                        <span class="text-muted small" id="mapLocationSummary"></span>
                        <input type="hidden" name="address_latitude" id="address_latitude">
                        <input type="hidden" name="address_longitude" id="address_longitude">
                    </div>
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): emergency contact is an HR-record field,
                 never read by any statutory calc/report/sync in this app -- stays hidden on its own
                 (2026-08-26: only Address above was un-hidden, this was not part of that request). -->
            <div class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">4</label>
                    <span data-i18n="emergency_contacts">Emergency Contacts</span>
                </h6>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="name">Name</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_name" id="emergency_name" data-i18n="emergency_name_placeholder" placeholder="e.g., Somsri">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="surname">Surname</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_surname" id="emergency_surname" data-i18n="surname_th_placeholder" placeholder="e.g., ใจดี">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="relationship">Relationship</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_relationship" id="emergency_relationship" data-i18n="emergency_relationship_placeholder" placeholder="e.g., Mother / Spouse / Friend">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="mobile_no">Mobile No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_mobile" id="emergency_mobile" maxlength="10" data-i18n="phone_no_placeholder" placeholder="e.g., 0812345678">
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-5">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextEmployment">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <div class="tab-pane fade" id="employment-pane" role="tabpanel" aria-labelledby="employment-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="corporate_information">Corporate Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="department">Department</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="department_id" id="department_id" data-api="/api/department.get" data-type="department">
                    </select>
                </div>
                <!-- 2026-08-28, explicit request: "ตรง Role อาจจะไม่ต้อง Require Field เพราะระบบนี้เข้ามา
                     ใช้งานได้แค่บางส่วน อยากให้ใส่ Role เฉพาะที่ต้องการ Set สิทธิ์ให้ดำนินการได้เท่านั้น" --
                     Role now governs system PERMISSIONS (PermissionModel::checkPermission() joins
                     employees.role_id -> structure_roles -> role_permissions), not payroll
                     eligibility -- most employees in this app never log into Payroll themselves at
                     all, so forcing every one of them to have a role was requiring something
                     unrelated to being "ready for payroll". Same optional treatment already
                     established for Team just below (not .required, excluded from
                     requiredColumns()/completenessColumns()/requiredFieldTabs() in
                     EmployeeModel.php) -- an empty Role no longer blocks save or dents completeness,
                     it now purely means "this person has no system permissions", which is correct
                     for the common case. -->
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="role">Role</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="role_id" id="role_id" data-api="/api/role.get" data-type="role">
                    </select>
                </div>
            </div>
            <!-- 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...เป็นบริษัท
                 ที่จ้าง outsource เพื่อไปอยู่กับหลาย Project" -- optional (not .required, unlike
                 Department/Role/Position/Branch): not every company using this app is an
                 outsourcing/staffing firm, so this shouldn't block save or count toward profile
                 completeness for everyone. Paired with Position (explicit follow-up request: "team
                 อยู่รายการเดียวโดด...ย้าย select ตัวต่อไปมาไว้ให้เป็นคู่") -- every field after Position
                 shifted up one slot to close the gap (Employee No.+Branch, Work Location+Shift),
                 leaving Employment Date alone at the END of the section instead of Team alone in the
                 MIDDLE -- a trailing lone field reads normally, a lone field mid-section looked broken. -->
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="team">Team</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="team_id" id="team_id" data-api="/api/team.get" data-type="team">
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="position">Position</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="position_id" id="position_id" data-api="/api/position.get" data-type="position">
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employee_no">Employee No.</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="employee_no" id="employee_no_input" data-i18n="employee_no_placeholder" placeholder="e.g., EMP0001">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="branch">Branch</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="branch_id" id="branch_id" data-api="/api/branch.get" data-type="branch">
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="work_location">Work Location</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="work_location_id" id="work_location_id" data-api="/api/work-location.options" data-type="location">
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="shift">Shift</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="shift_id" id="shift_id" data-api="/api/shift.options" data-type="shift">
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_date">Employment Date</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker required" name="employment_date" id="employment_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <!-- 2026-09-02, Origami candidates.php field batch: company-defined employment
                     classification (e.g. รายเดือน/รายวัน/สัญญาจ้าง), synced from Origami's
                     employment_type_ref_id/_code/_name -- NOT the same concept as the
                     employment_type radio above (full_time/part_time/daily/internship, a fixed
                     enum) or salary_type (pay frequency). Optional, same as Team -- auto-created
                     by sync (structure_employment_types), not required/completeness-gated. -->
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_type_classification">Employment Type Classification</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="employment_type_id" id="employment_type_id" data-api="/api/employment-type.get" data-type="employment_type">
                    </select>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="employment_details">Employment Details</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_status">Employment Status</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="employment_status" id="employment_status">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="probation" data-i18n="probation">Probation</option>
                        <option value="permanent" data-i18n="permanent">Permanent</option>
                        <option value="contract" data-i18n="contract">Contract</option>
                        <option value="resigned" data-i18n="resigned">Resigned</option>
                        <option value="terminated" data-i18n="terminated">Terminated</option>
                    </select>
                    <!-- 2026-09-02, explicit request: "สถานะการจ้างงาน กับ ประเภทการจ้างงาน ข้อมูลเหมือนไม่
                         สัมพันธ์กัน ถ้า ประเภทการจ้างงาน คือนักศึกษาฝึกงาน สถานะการจ้างงาน ควรเลือกอะไร" --
                         `employment_status` has no dedicated "intern" value at all
                         (probation/permanent/contract/resigned/terminated), yet the payroll engine
                         already treats `employment_type='internship'` as taking precedence over
                         `employment_status='probation'` wherever both matter (see
                         EmployeeModel::save()/PayrollRunModel's own "intern takes precedence over
                         probation" comments) -- confirmed via AskUserQuestion: auto-lock Employment
                         Status to Probation whenever Employment Type = Internship is ACTIVELY
                         selected, closing the ambiguity instead of leaving the admin to guess. See
                         applyEmploymentTypeInternLock() in detail.js -- deliberately only fires on a
                         genuine user selection (e.originalEvent present), never on the
                         programmatic populateEmployeeForm() load, so an existing intern record saved
                         with some OTHER status (e.g. resigned, from before this lock existed) is
                         never silently flipped back to probation just by opening the page. -->
                    <div class="form-text text-warning d-none" id="employmentStatusInternLockNote">
                        <i class="fa-solid fa-lock me-1"></i><span data-i18n="employment_status_intern_locked">Locked to Probation because Employment Type is Internship.</span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_type">Employment Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="employment_type" id="employment_type">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="full_time" data-i18n="full_time">Full-time</option>
                        <option value="part_time" data-i18n="part_time">Part-time</option>
                        <option value="daily" data-i18n="daily">Daily wage</option>
                        <option value="internship" data-i18n="internship">Internship</option>
                    </select>
                </div>
            </div>
            <!-- Resignation/Termination fields (2026-08-21, explicit request: "ใน Tab การจ้างงาน
                 ลาออก เลิกจ้าง อยากให้เพิ่มให้ใส่วันที่มีผล และวันที่สุดท้ายของการดึงไปทำรายงาน และใส่
                 เหตุผลได้ด้วย") -- shown only when Employment Status is Resigned/Terminated, same
                 show/hide-on-select convention as applyEmployeeTypeRequired()/
                 applyMilitaryStatusVisibility() elsewhere on this page (see
                 applyEmploymentEndFieldsVisibility() in detail.js). employment_end_date already
                 existed and is read extensively by PayrollRunModel for final-run pro-rating/
                 eligibility, but had no UI field until now -- effective date and this report-cutoff
                 date are deliberately two separate fields (confirmed with the user), not the same
                 date shown twice. None of the 3 are .required -- presence isn't save-blocking here,
                 same philosophy as most other fields on this page; only format matters where it does. -->
            <div class="row d-none" id="employmentEndFields">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="effective_date">Effective Date</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" name="employment_status_effective_date" id="employment_status_effective_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_last_report_date">Last Date for Reports</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" name="employment_end_date" id="employment_end_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="reason">Reason</span></label>
                </div>
                <div class="col-sm-10 mt-3">
                    <textarea class="form-control" name="employment_end_reason" id="employment_end_reason" maxlength="255" rows="2" data-i18n="employment_end_reason_placeholder" placeholder="e.g., Resignation, End of contract, Retirement"></textarea>
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): none of these 4 fields are read anywhere
                 in payroll calc/statutory reports/approval routing -- org-chart/HR-legal tracking
                 only. Fields stay in the DOM (values still submit/save/sync normally). -->
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="report_to">Report To</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="report_to_id" id="report_to_id" data-api="/api/employee.report_to.get" data-type="">
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="date_contract_expire">Date Contract Expire</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" name="date_contract_expire" id="date_contract_expire" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="holiday_calendar">Holiday Calendar</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" name="holiday_calendar_id" id="holiday_calendar_id">
                        <option value="" data-i18n="please_choose">Select an option</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="driver_license">Driver License No.</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="driver_license_no" id="driver_license_no" data-i18n="driver_license_no_placeholder" placeholder="e.g., 12345678">
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll, confirmed with user): attendance/HR-tracking
                 fields only -- neither is read anywhere in PayrollRunModel or app/services/* (grepped
                 before hiding). Same shape as report_to_id/date_contract_expire/holiday_calendar_id/
                 driver_license_no just above. required class removed from both selects AND from
                 EmployeeModel::requiredColumns() in the same commit -- otherwise hidden-but-required
                 fields would silently block every employee save. -->
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="workforce_type">Workforce Type</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native" name="workforce_type" id="workforce_type">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="office" data-i18n="office">Office</option>
                        <option value="field" data-i18n="field">Field</option>
                        <option value="remote" data-i18n="remote">Remote</option>
                        <option value="hybrid" data-i18n="hybrid">Hybrid</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="record_time_method">Time Record Method</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native" name="record_time_method" id="record_time_method">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="fingerprint" data-i18n="fingerprint">Fingerprint</option>
                        <option value="qr_code" data-i18n="qr_code">QR Code</option>
                        <option value="mobile_app" data-i18n="mobile_app">Mobile App</option>
                        <option value="manual" data-i18n="manual">Manual</option>
                        <option value="none" data-i18n="none">None</option>
                    </select>
                </div>
            </div>
            <!-- 2026-09-02, explicit request: "ตั้งค่าอัตรา OT น่าจะมาอยู่ที่การจ้างงานมากกว่า...ย้ายข้อมูลการ
                 จ่ายเงิน ไปไว้ Tab เงินเดือน" -- swapped places with "Payment Information" (now section 2
                 of the Salary tab, see that tab's own comment on this same move). OT eligibility is
                 an employment-term decision (same family as Employment Status/Type right above in
                 this same tab), and Payment Information had ended up split across 2 tabs already
                 (payment_method_id/bank details here, default_bank_account_id already on Salary --
                 see that field's own 2026-09-02 comment) which read as "different tab, looks odd" per
                 the explicit report. Section id/number unchanged (still slot "3" of this tab) --
                 only the CONTENT of this slot changed, so nothing else in this tab needed
                 renumbering. `#otRateSection`'s own id is untouched (never referenced any tab name).
                 The old T020 "#employmentPaymentSection" visibility toggle
                 (applyPayrollParticipantVisibility() in detail.js) is gone too -- now that Payment
                 lives entirely inside the Salary tab, which ALREADY hides its whole nav-item for a
                 staff-only employee (see PAYROLL_ONLY_TAB_BUTTON_IDS in that same file), a second,
                 separate hide of a sub-section within it was redundant. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="ot_rate_settings">OT Rate Settings</span>
            </h6>
            <div id="otRateSection">
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="ot_eligible">OT Eligible</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 pt-2">
                        <input type="checkbox" class="me-2" name="ot_eligible" id="ot_eligible"><span data-i18n="eligible_for_overtime">Eligible for overtime pay</span>
                    </div>
                </div>
                <div id="otRateDependentWrap" class="d-none">
                    <div class="row">
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span data-i18n="ot_rate_source">OT Rate Source</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <div class="btn-group d-block" role="group" id="otRateSourceRadioGroup">
                                <input type="radio" class="btn-check" name="ot_rate_source_radio" id="ot_rate_source_default" value="default" checked>
                                <label class="btn btn-outline-brand" for="ot_rate_source_default" data-i18n="ot_rate_source_default">Use Company Default</label>
                                <input type="radio" class="btn-check" name="ot_rate_source_radio" id="ot_rate_source_custom" value="custom">
                                <label class="btn btn-outline-brand" for="ot_rate_source_custom" data-i18n="ot_rate_source_custom">Set Individually per OT Type</label>
                            </div>
                        </div>
                        <div class="col-sm-2 mt-3 ot-rate-set-picker-toggle">
                            <label class="form-label"><span data-i18n="ot_rate_set_picker_label">OT Rate Set</span></label>
                        </div>
                        <div class="col-sm-4 mt-3 ot-rate-set-picker-toggle" id="otRateSetPickerWrapper">
                            <select class="form-select select2-remote" id="ot_rate_set_id" data-api="/api/ot-rate.set-options" data-type="ot_rate_set"></select>
                            <div class="small text-muted mt-1" id="otRateSetRecommendHint"></div>
                        </div>
                    </div>
                    <div id="otRateOverridesContainer" class="d-none mt-3">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th data-i18n="ot_scope">OT Type</th>
                                        <th data-i18n="calculation_method">Calculation Method</th>
                                        <th data-i18n="rate">Rate</th>
                                        <th data-i18n="calculation_base">Base</th>
                                    </tr>
                                </thead>
                                <tbody id="otRateOverridesBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-5">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextSalary">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <div class="tab-pane fade" id="salary-pane" role="tabpanel" aria-labelledby="salary-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="base_salary">Base Salary</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="salary_type">Salary Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <!-- 2026-08-31, explicit request/investigation: "ถ้าเป็นพนักงานรายวัน การระบุเงินเดือน
                         และการคำนวณจะเป็นแบบไหนครับ รายสัปดาห์ด้วย และรายปักษ์...ต้องครอบคลุมทั้งหมด" -- weekly/
                         semi_monthly/bi_weekly added, reusing the EXACT SAME enum values/i18n labels
                         (freq_weekly/freq_semi_monthly/freq_bi_weekly) as payroll_cycles.
                         payroll_frequency for internal consistency -- see PayrollRunModel::
                         recalculate()'s own comment for how base_salary_amount is interpreted for
                         each. Confirmed via AskUserQuestion: these employees should be assigned (via
                         cycle_id below) to a Payroll Cycle of matching frequency, not the company's
                         monthly cycle. -->
                    <select class="form-select select2-native required" name="salary_type" id="salary_type">
                        <option value="monthly" data-i18n="monthly">Monthly</option>
                        <option value="daily" data-i18n="daily">Daily</option>
                        <option value="hourly" data-i18n="hourly">Hourly</option>
                        <option value="weekly" data-i18n="freq_weekly">Weekly</option>
                        <option value="semi_monthly" data-i18n="freq_semi_monthly">Semi-Monthly</option>
                        <option value="bi_weekly" data-i18n="freq_bi_weekly">Bi-Weekly</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="base_salary_amount">Base Salary Amount</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control text-end required" name="base_salary_amount" id="base_salary_amount" data-i18n="base_salary_amount_placeholder" placeholder="e.g., 30000">
                        <span class="input-group-text" data-i18n="thb">THB</span>
                    </div>
                </div>
            </div>
            <!-- 2026-08-30, same-day follow-up ("ย้ายสิทธิ์การได้รับ OT มาไว้แทน รอบการจ่าย (Schedule)
                 สลับกัน จะได้อยู่ด้วยกัน") -- Payroll Schedule swapped up next to Effective Date;
                 OT Eligible swapped down to sit directly above the OT Rate Settings card it controls,
                 so the whole OT block (eligible flag + rate source + per-scope table) reads as one
                 group instead of being split across two separate rows. -->
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker required" name="salary_effective_date" id="salary_effective_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="modal_cycle">Payroll Schedule</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="cycle_id" id="cycle_id" data-api="/api/payroll-cycle.options">
                    </select>
                </div>
            </div>
            <!-- 2026-09-02, explicit request: "เมื่อเลือกรอบแล้ว ให้เลือกต่อได้ว่าจะใช้บัญชีไหนของรอบนั้น...ถ้าไม่
                 เลือก ใช้บัญชีที่ตั้งเป็น Default ของรอบนั้นอัตโนมัติ" -- moved here from the Employment tab
                 (where it briefly lived as part of the earlier same-day multi-bank-account payroll
                 feature) to sit next to cycle_id, since "which account of THIS cycle" only makes
                 sense once a cycle is actually picked. Options are now CYCLE-SCOPED
                 (api/employee.payment-account-options, cycle_id sent as a query param -- falls back
                 to the company's own is_default account when the cycle has none configured, see
                 EmployeePaymentMethodModel::scopedBankAccountOptions()'s own docblock), replacing the
                 old company-wide api/payroll-cycle.bank-account.options this field used briefly.
                 Only relevant while Payment Type (payment_method_id, section 2 below on this same
                 tab as of the 2026-09-02 Payment/OT tab swap -- previously on the Employment tab,
                 hence "cross-tab" here originally) resolves to something bank-related (transfer, or
                 a mixed line using transfer) -- see applyAccountPickerVisibility() in detail.js. -->
            <div class="row" id="sectionCycleBankAccount">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="default_bank_account_label">Paid From Company Account</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="default_bank_account_id" id="default_bank_account_id" data-api="/api/employee.payment-account-options" allow-clear="true"></select>
                    <div class="form-text" data-i18n="default_bank_account_hint">*Optional. Leave blank to use this cycle's own default account.</div>
                </div>
            </div>
            <!-- 2026-09-02, explicit request: "ย้ายข้อมูลการจ่ายเงิน ไปไว้ Tab เงินเดือนจะดีกว่าไหมครับ พอคนละ
                 Tab ดูแปลก" -- moved here from the Employment tab (swapped places with OT Rate
                 Settings, which moved there -- see that tab's own comment on this same move). Every
                 payment-related field is now on this ONE tab, next to `default_bank_account_id`/
                 `cycle_id` right above (which had already moved here on its own back on 2026-09-02,
                 for the same "belongs with the rest of payment info" reason -- see that field's own
                 comment, left as-is since it's genuinely tied to cycle_id specifically, not moved
                 again into this section). Section id/number unchanged (still slot "2" of this tab,
                 same slot OT Rate Settings used to occupy) -- Recurring Allowances/Recurring
                 Deductions/Tax Information/Internship/Probation below keep their own existing
                 numbers, nothing else needed renumbering.
                 `#employmentPaymentSection` was renamed `#paymentInformationSection` (the old name
                 referenced a tab it's no longer in) -- its one remaining JS reference
                 (applyPayrollParticipantVisibility()'s own T020 visibility toggle) was removed
                 entirely rather than renamed, since this whole tab already hides its own nav-item for
                 a staff-only employee (PAYROLL_ONLY_TAB_BUTTON_IDS in detail.js), making a second,
                 separate hide of a sub-section within it redundant. The client-side mixed-payment-
                 lines validation that used to live in saveSalaryTab() (duplicated there because that
                 function's own #btnNextSalary button used to sit right below this section) was
                 removed for the same reason -- saveEmployee()'s OWN copy of that same check (used by
                 EVERY other Save button on this page, #btnNextSocial included, which is this tab's
                 real closing Save button) already covers it correctly now that this section lives
                 here. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="payment_information">Payment Information</span>
            </h6>
            <div id="paymentInformationSection">
                <!-- 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) --
                     replaces the old bank/cash radio pair with a master_payment_methods-backed
                     picker (this project's own convention for a closed set that may grow later
                     without a code deploy -- see that table's own migration header). A normal named
                     select2-remote (not a radio+hidden-mirror pair like the old payment_type_radio),
                     so collectEmployeeFormData()'s generic [name] loop picks it up directly --
                     payment_method_id (master_payment_methods) is the sole source of truth end to
                     end now, the legacy payment_type enum mirror having been dropped entirely (see
                     database/migrations/2026-09-02_19_drop_legacy_payment_type.sql). -->
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="payment_type">Payment Type</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-remote required" name="payment_method_id" id="payment_method_id" data-api="/api/payment-method.options"></select>
                        <!-- JS-only companion field (never submitted -- no name attribute, same
                             "select without a name" convention #ot_rate_source's own dedicated-save
                             field uses) holding the resolved code (transfer/cash/check/mixed) so
                             applyPaymentMethodVisibility()/applyAccountPickerVisibility() in
                             detail.js don't need their own extra lookup. -->
                        <input type="hidden" id="payment_method_code">
                    </div>
                </div>
                <div class="row" id="sectionBankPayment">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_name">Bank</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-remote" name="bank_id" id="bank_id" data-api="/api/bank.get" data-type="bank">
                        </select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_account_no">Bank Account No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="bank_account_no" id="bank_account_no" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_account_name">Bank Account Name</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="bank_account_name" id="bank_account_name" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_branch">Bank Branch</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="bank_branch" id="bank_branch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch">
                    </div>
                </div>
                <!-- 2026-09-02, explicit request: mixed payment (แบ่งสัดส่วนระหว่าง 2 วิธีขึ้นไป) -- shown
                     only while payment_method_id resolves to 'mixed'. Repeatable line-item rows, same
                     "add/remove row" convention as this page's own Earning-Deduction/Recurring
                     Allowance tables -- each line: method (transfer/cash/check, never mixed itself) +
                     fixed-amount-or-percent-of-net + a bank account picker shown only on a transfer
                     line. Client-side validates percent lines sum to 100 before Save (SweetAlert2);
                     a set containing any fixed-amount line defers its own sum check to payroll-run
                     time (see EmployeePaymentMethodModel::validateMixedLines()'s own docblock). -->
                <div class="row d-none" id="sectionMixedPayment">
                    <div class="col-12 mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0" data-i18n="mixed_payment_lines">Payment Lines</label>
                            <button type="button" class="btn btn-sm btn-outline-brand" id="btnAddPaymentMethodLine">
                                <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_line">Add Line</span>
                            </button>
                        </div>
                        <div id="paymentMethodLinesWrap"></div>
                        <div class="form-text" data-i18n="mixed_payment_lines_hint">Percent lines must sum to exactly 100. A line paying by transfer needs its own bank account.</div>
                    </div>
                </div>
            </div>
            <!-- 2026-08-26, explicit request: "ส่วนของเงินเดือน...จะมีรายรับที่ได้ทุกเดือนเช่นพวกค่าตำแหน่ง
                 ค่ารถ ค่าน้ำมัน และอื่นๆ ให้เพิ่มส่วนนี้เข้าไปด้วย และระงับการจ่ายได้ รวมถึงการตั้งค่าส่วนนี้
                 เพิ่มเติมให้นำไปคำนวณในรอบการจ่ายด้วย" -- confirmed via AskUserQuestion: its own new
                 section here on the Salary tab (NOT folded into the Earning-Deduction tab below,
                 which stays loan/installment-only -- see EmployeeRecurringEarningModel's own
                 docblock). Small embedded DataTable, same "list uses DataTables, Add injected into
                 .dt-search" convention as tableEarning/tableDeduction below. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="recurring_earnings">Recurring Allowances</span>
            </h6>
            <p class="text-secondary small mb-3" data-i18n="recurring_earnings_hint">*Fixed monthly allowances (position/car/fuel allowance, etc.) that recur every payroll run until suspended or removed.</p>
            <table class="table table-bordered table-sm align-middle" id="tableRecurringEarning" style="width:100%">
                <thead>
                    <tr>
                        <th data-i18n="item_name">Item</th>
                        <th data-i18n="amount" style="width:130px;">Amount</th>
                        <th data-i18n="effective_date" style="width:110px;">Effective Date</th>
                        <th data-i18n="suspend_period" style="width:170px;">Suspend Period</th>
                        <th data-i18n="col_status" style="width:100px;">Status</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
            </table>
            <!-- 2026-08-31, explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ" -- direct
                 mirror of Recurring Allowances immediately above (own #tableRecurringDeduction DataTable
                 + #recurringDeductionModal), backed by EmployeeRecurringDeductionModel/the
                 api/employee.recurring-deduction.* endpoints. Catalog dropdown is pre-filtered
                 server-side to item_type='deduction' AND calculation_method='fixed_amount' items only,
                 same as the earning side's own fixed_amount-only restriction. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">4</label>
                <span data-i18n="recurring_deductions">Recurring Deductions</span>
            </h6>
            <p class="text-secondary small mb-3" data-i18n="recurring_deductions_hint">*Fixed monthly deductions (uniform fee, locker fee, etc.) that recur every payroll run until suspended or removed.</p>
            <table class="table table-bordered table-sm align-middle" id="tableRecurringDeduction" style="width:100%">
                <thead>
                    <tr>
                        <th data-i18n="item_name">Item</th>
                        <th data-i18n="amount" style="width:130px;">Amount</th>
                        <th data-i18n="effective_date" style="width:110px;">Effective Date</th>
                        <th data-i18n="suspend_period" style="width:170px;">Suspend Period</th>
                        <th data-i18n="col_status" style="width:100px;">Status</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
            </table>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">5</label>
                <span data-i18n="tax_information">Tax Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3 tax-calc-method-toggle">
                    <label class="form-label"><span data-i18n="tax_calculation_method">Tax Calculation Method</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3 tax-calc-method-toggle">
                    <select class="form-select select2-native required" name="tax_calculation_method" id="tax_calculation_method">
                        <option value="" data-i18n="please_choose">Select an option</option>
                        <option value="average" data-i18n="average_method">Average</option>
                        <option value="actual" data-i18n="actual_method">Actual</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="tax_exempt">Tax Exempt</span></label>
                </div>
                <div class="col-sm-4 mt-3 pt-2">
                    <input type="checkbox" class="me-2" name="tax_exempt" id="tax_exempt"><span data-i18n="exempt_from_wht">Exempt from withholding tax</span>
                </div>
            </div>
            <!-- 2026-08-31, explicit request: "และให้ครอบคลุมถึงเด็กฝึกงานบางคนที่ให้เงินเดือน แต่อยากให้ตั้ง
                 เงื่อนไขได้แบบ Probation...หรือใน Tab เงินเดือน ของหน้าพนักงาน ตอนเลือกประเภท Type ให้เลือก Set
                 ได้จากตรงนั้น เห็น Form แยกกันไปเลย...และในหน้าเงินเดือนก็แก้ไขได้เป็นรายบุคคลด้วย" -- confirmed
                 via AskUserQuestion: the company-wide Internship Pay Conditions default lives in
                 Payroll Configuration > Payroll Policies (own separate field set, mirrors Probation's
                 own card there -- see PayrollPolicyModel::internSettings()), this section is ONLY the
                 per-employee OVERRIDE of the ratio half of that (a toggle + a per-employee % field,
                 not a full per-employee copy of every intern_* setting -- defer PVD/Recurring
                 Allowances stay company-wide-only, same scope Probation itself has always had).
                 `employment_type` lives on the Employment tab, not this one -- #internPolicySection
                 shown/hidden here purely by reading that field's live value (applyInternPolicyVisibility()
                 in detail.js), same "read a field that lives on another tab of the same form" pattern
                 applyPayrollParticipantVisibility() itself already uses for #is_payroll_participant. -->
            <!-- 2026-09-02, explicit request: "แสดง checkbox/toggle เลือกก่อนว่า...ใช้นโยบายของบริษัท หรือ
                 ตั้งค่าแยกเฉพาะบุคคลนี้...Default = ใช้นโยบายของบริษัท...ถ้าเลือกใช้นโยบายบริษัท ไม่ต้องแสดง
                 Form ให้กรอก แสดงเป็นการ์ดข้อมูล (info display) สรุปว่านโยบายปัจจุบันจ่ายแบบไหน...ดีไซน์ให้สวยงาม
                 สอดคล้อง glassmorphism" -- redesigned from the old plain-checkbox-reveals-plain-input
                 pattern into: DEFAULT state = a read-only .settings-info-card (this app's own
                 established glassmorphism summary-card component, same classes as Payroll
                 Configuration's own #policyProbationCard/#policyInternCard) showing the CURRENT
                 EFFECTIVE company policy live (fetched via api/employee.payroll-policy-settings,
                 renderPolicyInfoCard() in detail.js); checking "Use custom settings for this
                 employee" reveals the SAME ratio-override field this section has always had (still
                 the only per-employee-overridable field -- defer_pvd/defer_recurring_earning/leave/
                 OT-default stay company-wide-only, unchanged scope). Probation gets an identical
                 sibling section right below (was missing per-employee override entirely before this
                 round -- genuine gap, not a redesign of something that existed). -->
            <div id="internPolicySection" class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">6</label>
                    <span data-i18n="intern_pay_policy">Internship Pay Policy</span>
                </h6>
                <div class="settings-info-card mb-3" id="internPolicyInfoCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-user-graduate"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_using_company_default_title">Using Company Policy</p>
                            <p class="settings-info-card-desc" data-i18n="policy_using_company_default_desc">This employee currently follows the company-wide Internship Pay Conditions set in Payroll Configuration.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body" id="internPolicyInfoCardBody"></div>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="me-2" id="internRatioOverrideToggle"><span data-i18n="policy_use_custom_for_employee" class="form-check-label">Use custom settings for this employee</span>
                </div>
                <!-- 2026-09-02, follow-up to close a review-flagged gap: "ตั้งค่าแยกเฉพาะบุคคลนี้" must
                     cover EVERY field the company policy has ("ครบทุกช่อง ไม่ตัดทอน"), not just the
                     ratio -- expanded from 1 field to all 7 intern_* override columns. Boolean-ish
                     fields use a 3-state select (blank=inherit company default, matching every other
                     tri-state override in this app) rather than a plain checkbox, since "explicitly
                     override to No" must be distinguishable from "don't override at all". -->
                <div class="row d-none" id="internRatioOverrideFieldsRow">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_intern_base_salary_ratio_label">Base Salary Ratio for Salaried Interns</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="1" max="100" step="0.01" class="form-control" name="intern_base_salary_ratio_override" id="intern_base_salary_ratio_override" data-i18n="policy_probation_base_salary_ratio_placeholder" placeholder="100 (no reduction)">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_intern_defer_pvd_label">Defer Provident Fund (PVD) contribution for interns</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="intern_defer_pvd_override" id="intern_defer_pvd_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_intern_defer_sso_label">Defer Social Security Fund (SSO) contribution for interns</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="intern_defer_sso_override" id="intern_defer_sso_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_intern_defer_recurring_label">Withhold Recurring Allowances (position/car/fuel, etc.) for interns</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="intern_defer_recurring_earning_override" id="intern_defer_recurring_earning_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_leave_days_limit_label">Leave Days Limit</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="0" step="1" class="form-control" name="intern_leave_days_limit_override" id="intern_leave_days_limit_override" data-i18n="policy_leave_days_limit_placeholder" placeholder="No limit">
                            <span class="input-group-text" data-i18n="days_suffix">days</span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_allow_leave_label">Allow leave requests during this period</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="intern_allow_leave_override" id="intern_allow_leave_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_intern_period_days_label">Standard Internship Period (days)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="0" step="1" class="form-control" name="intern_period_days_override" id="intern_period_days_override" data-i18n="policy_probation_period_days_placeholder" placeholder="Not set">
                            <span class="input-group-text" data-i18n="days_suffix">days</span>
                        </div>
                    </div>
                </div>
                <p class="text-secondary small mb-0" data-i18n="intern_base_salary_ratio_override_hint">Leave off to use this company's own Internship Pay Conditions default (set in Payroll Configuration > Payroll Policies). Only applies while this employee's Employment Type is Internship.</p>
            </div>
            <!-- 2026-09-02, explicit request: Probation never had a per-employee override section at
                 all before this round -- direct mirror of #internPolicySection immediately above,
                 own field (probation_base_salary_ratio_override), shown/hidden by Employment Status
                 = Probation instead of Employment Type = Internship. -->
            <div id="probationPolicySection" class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">7</label>
                    <span data-i18n="probation_pay_policy">Probation Pay Policy</span>
                </h6>
                <div class="settings-info-card mb-3" id="probationPolicyInfoCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-user-clock"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_using_company_default_title">Using Company Policy</p>
                            <p class="settings-info-card-desc" data-i18n="policy_using_company_default_desc_probation">This employee currently follows the company-wide Probation Pay Conditions set in Payroll Configuration.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body" id="probationPolicyInfoCardBody"></div>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="me-2" id="probationRatioOverrideToggle"><span data-i18n="policy_use_custom_for_employee" class="form-check-label">Use custom settings for this employee</span>
                </div>
                <!-- 2026-09-02, follow-up to close a review-flagged gap -- direct mirror of the
                     Internship section's own expanded override row immediately above, own field set. -->
                <div class="row d-none" id="probationRatioOverrideFieldsRow">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_probation_base_salary_ratio_label">Base Salary Ratio During Probation</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="1" max="100" step="0.01" class="form-control" name="probation_base_salary_ratio_override" id="probation_base_salary_ratio_override" data-i18n="policy_probation_base_salary_ratio_placeholder" placeholder="100 (no reduction)">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_probation_defer_pvd_label">Defer Provident Fund (PVD) contribution until probation passes</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="probation_defer_pvd_override" id="probation_defer_pvd_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_probation_defer_sso_label">Defer Social Security Fund (SSO) contribution until probation passes</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="probation_defer_sso_override" id="probation_defer_sso_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_probation_defer_recurring_label">Withhold Recurring Allowances (position/car/fuel, etc.) until probation passes</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="probation_defer_recurring_earning_override" id="probation_defer_recurring_earning_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_leave_days_limit_label">Leave Days Limit</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="0" step="1" class="form-control" name="probation_leave_days_limit_override" id="probation_leave_days_limit_override" data-i18n="policy_leave_days_limit_placeholder" placeholder="No limit">
                            <span class="input-group-text" data-i18n="days_suffix">days</span>
                        </div>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_allow_leave_label">Allow leave requests during this period</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select select2-static" name="probation_allow_leave_override" id="probation_allow_leave_override" data-option-keys="policy_override_use_default,yes,no" data-option-values=",1,0"></select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="policy_probation_period_days_label">Standard Probation Period (days)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="number" min="0" step="1" class="form-control" name="probation_period_days_override" id="probation_period_days_override" data-i18n="policy_probation_period_days_placeholder" placeholder="Not set">
                            <span class="input-group-text" data-i18n="days_suffix">days</span>
                        </div>
                    </div>
                </div>
                <p class="text-secondary small mb-0" data-i18n="probation_base_salary_ratio_override_hint">Leave off to use this company's own Probation Pay Conditions default (set in Payroll Configuration > Payroll Policies). Only applies while this employee's Employment Status is Probation.</p>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-5">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextSocial">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <!-- Split out of the Salary tab (2026-08-19, explicit request). No Save button on this pane
             at all -- unlike every other tab, there's nothing here to bulk-save: every item is its
             own record, created/edited/deleted immediately through #eedModal (see detail.js), so a
             tab-level Save would have nothing to actually submit. -->
        <div class="tab-pane fade" id="earningDeduction-pane" role="tabpanel" aria-labelledby="earningDeduction-tab" tabindex="0">
            <p class="text-secondary small mb-3" data-i18n="earning_deduction_assignments_hint">*Assign recurring or installment-based items from the payroll master list, such as loan deductions or split bonus payments.</p>
            <!-- Sub-tabs (2026-08-19, explicit request: "แยกเป็น Tab ย่อย รายรับ รายหัก", then follow-up
                 request: "ไปดูตัวอย่างจาก Company Setup") -- same rounded-pill-in-a-light-gray-bar look
                 as Company Setup's own structure sub-tabs (.structure-tabs-wrap/.structure-tabs in
                 app/views/setup/company-profile.php + their CSS in style.css), reused verbatim here
                 rather than plain Bootstrap nav-pills, so a secondary-level tab split reads the same
                 way anywhere in the app it shows up. Mechanism stays plain Bootstrap tabs underneath
                 (data-bs-toggle="pill", 2 real .tab-pane's) -- Company Setup's version swaps a single
                 content container via its own JS instead because its 6 tabs are unrelated CRUD
                 screens, not needed here for just 2 static tables. Both tables are real client-side
                 DataTables (ajax + dataSrc) -- Add button injected into .dt-search via initComplete,
                 same as every other DataTable in this app. -->
            <div class="bg-light rounded-3 p-2 mb-4 structure-tabs-wrap">
                <ul class="nav nav-pills flex-nowrap structure-tabs" id="eedSubTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="eedEarningSub-tab" data-bs-toggle="pill" data-bs-target="#eedEarningSub-pane" type="button" role="tab" aria-controls="eedEarningSub-pane" aria-selected="true"><i class="fa-solid fa-arrow-trend-up me-2"></i><span data-i18n="earning_singular">Income</span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="eedDeductionSub-tab" data-bs-toggle="pill" data-bs-target="#eedDeductionSub-pane" type="button" role="tab" aria-controls="eedDeductionSub-pane" aria-selected="false"><i class="fa-solid fa-arrow-trend-down me-2"></i><span data-i18n="deduction_singular">Deduction</span></button>
                    </li>
                </ul>
            </div>
            <div class="tab-content" id="eedSubTabsContent">
                <div class="tab-pane fade show active" id="eedEarningSub-pane" role="tabpanel" aria-labelledby="eedEarningSub-tab" tabindex="0">
                    <table class="table table-bordered table-sm align-middle" id="tableEarning" style="width:100%">
                        <thead>
                            <tr>
                                <th data-i18n="item_name">Item</th>
                                <th data-i18n="amount" style="width:150px;">Amount</th>
                                <th data-i18n="installment_progress" style="width:110px;">Installments</th>
                                <th data-i18n="effective_date" style="width:110px;">Effective Date</th>
                                <th data-i18n="col_status" style="width:100px;">Status</th>
                                <th style="width:130px;"></th>
                            </tr>
                        </thead>
                    </table>
                </div>
                <div class="tab-pane fade" id="eedDeductionSub-pane" role="tabpanel" aria-labelledby="eedDeductionSub-tab" tabindex="0">
                    <table class="table table-bordered table-sm align-middle" id="tableDeduction" style="width:100%">
                        <thead>
                            <tr>
                                <th data-i18n="item_name">Item</th>
                                <th data-i18n="amount" style="width:150px;">Amount</th>
                                <th data-i18n="installment_progress" style="width:110px;">Installments</th>
                                <th data-i18n="effective_date" style="width:110px;">Effective Date</th>
                                <th data-i18n="col_status" style="width:100px;">Status</th>
                                <th style="width:130px;"></th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="social-pane" role="tabpanel" aria-labelledby="social-tab" tabindex="0">
            <!-- Side-by-side (2026-08-19, explicit request): PVD only has a single checkbox visible
                 (its detail fields are hidden, see below), leaving the right half of the row empty
                 when stacked full-width -- SSO/PVD placed as two columns of the same row instead.
                 SSO's own detail fields (sso_no/sso_start_date) only show once "Enrolled" is checked
                 (#ssoDetailFields, toggled in detail.js) -- also explicit request.
                 Wrapped in .detail-section (2026-08-20, "อยากให้ปรับให้ดูสวยขึ้น") -- same lighter
                 bordered sub-card already used by every section on the Family tab, reused here
                 instead of a bare <div class="row"> for a consistent look across tabs. -->
            <div class="detail-section">
            <div class="row">
                <div class="col-sm-6">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                        <span data-i18n="social_security_fund">Social Security Fund (SSO)</span>
                    </h6>
                    <div class="mt-3">
                        <label class="form-label d-block mb-1"><span data-i18n="enrolled_in_sso">Enrolled in Social Security Fund</span></label>
                        <!-- Yes/No radio instead of a bare checkbox (2026-08-19, explicit request):
                             an unchecked checkbox looks identical to "not answered yet" and "answered
                             No" -- the radio pair always shows an explicit state. Still drives the
                             real (now hidden) sso_enrolled checkbox underneath, so
                             collectEmployeeFormData()'s generic :checkbox handling and the existing
                             #sso_enrolled change listener (toggles #ssoDetailFields) both need zero
                             changes -- see the toggle wiring in detail.js. -->
                        <div class="btn-group btn-group-sm" role="group" id="ssoEnrolledToggle">
                            <button type="button" class="btn btn-outline-brand" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                        <input type="checkbox" class="d-none" name="sso_enrolled" id="sso_enrolled">
                    </div>
                    <div id="ssoDetailFields" class="d-none">
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="sso_no">Social Security No.</span></label>
                            <input type="text" class="form-control" name="sso_no" id="sso_no" maxlength="13" data-i18n="sso_no_placeholder" placeholder="13-digit social security number">
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="sso_start_date">SSO Start Date</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" name="sso_start_date" id="sso_start_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <!-- 2026-09-02, real gap found and fixed (explicit report: "Smart Form...ตัวอย่างเช่น
                             กองทุนประกันสังคม (สปส.) เลือกไม่มี แต่ให้กรอก Rate") -- these 2 rate-override
                             fields used to sit OUTSIDE #ssoDetailFields (right after its closing tag),
                             so they stayed visible/editable even with "Enrolled in Social Security
                             Fund" set to No -- a rate override for a fund the employee isn't even
                             enrolled in makes no sense (and StatutoryCalculationEngine never reads it
                             in that case anyway, since it only applies an override to an item the
                             employee is actually enrolled in -- see calculateItem()'s own enrollment
                             gate). Moved inside #ssoDetailFields so the SAME #sso_enrolled change
                             handler (detail.js) that already shows/hides sso_no/sso_start_date now
                             covers these too, automatically, with zero new JS. Un-hidden 2026-09-02:
                             StatutoryCalculationEngine reads these as a real per-employee SSO rate
                             override (wins over company_statutory_settings' own override, which
                             itself wins over the master rate). Deliberately left BLANK by default (not
                             pre-filled with the standard rate) so an untouched field submits NULL =
                             "no override, use the company/master rate" -- see
                             database/migrations/2026-09-02_5_sso_contribution_rate_default_cleanup.sql
                             for why a hardcoded value="5.00" here was a real bug once this field went
                             live (it would have silently pinned every employee to 5% forever). Synced
                             from Origami's sso_employee_rate_percent when present (EmployeeSyncer). -->
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="sso_contribution_rate">Employee Contribution Rate Override (%)</span></label>
                            <input type="number" step="0.01" class="form-control" name="sso_contribution_rate" id="sso_contribution_rate" placeholder="5.00">
                            <div class="text-muted small" data-i18n="sso_rate_override_hint">Leave blank to use the company/standard rate.</div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="sso_employer_contribution_rate">Employer Contribution Rate Override (%)</span></label>
                            <input type="number" step="0.01" class="form-control" name="sso_employer_contribution_rate" id="sso_employer_contribution_rate" placeholder="5.00">
                            <div class="text-muted small" data-i18n="sso_rate_override_hint">Leave blank to use the company/standard rate.</div>
                        </div>
                    </div>
                    <!-- Hidden 2026-08-19 (not needed for Payroll): informational only (which hospital
                         the employee is registered at) -- doesn't affect the SSO contribution amount,
                         and isn't read by StatutoryCalculationEngine or the SSO reports (those use
                         sso_no). -->
                    <div class="mt-3 d-none">
                        <label class="form-label d-block"><span data-i18n="sso_hospital">Hospital</span></label>
                        <select class="form-select" name="sso_hospital_id" id="sso_hospital_id">
                            <option value="" data-i18n="please_choose">Select an option</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-6">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                        <span data-i18n="provident_fund">Provident Fund (PVD)</span>
                    </h6>
                    <div class="mt-3">
                        <label class="form-label d-block mb-1"><span data-i18n="enrolled_in_pvd">Enrolled in Provident Fund</span></label>
                        <div class="btn-group btn-group-sm" role="group" id="pvdEnrolledToggle">
                            <button type="button" class="btn btn-outline-brand" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                        <input type="checkbox" class="d-none" name="pvd_enrolled" id="pvd_enrolled">
                    </div>
                    <!-- Hidden 2026-08-19 (not needed for Payroll): fund name/dates/rates are never
                         read by the calculation engine or any report -- PVD rates come from
                         company-wide statutory settings, not these per-employee fields. Fields stay in
                         the DOM (values still submit/save/sync normally). -->
                    <div class="d-none">
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="pvd_fund_name">Fund Name</span></label>
                            <input type="text" class="form-control" name="pvd_fund_name" id="pvd_fund_name" data-i18n="pvd_fund_name_placeholder" placeholder="e.g., XYZ Provident Fund">
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="pvd_start_date">Start Date</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" name="pvd_start_date" id="pvd_start_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="pvd_employee_rate">Employee Rate (%)</span></label>
                            <input type="number" step="0.01" class="form-control" name="pvd_employee_rate" id="pvd_employee_rate" data-i18n="pvd_rate_placeholder" placeholder="e.g., 3.00">
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="pvd_employer_rate">Employer Rate (%)</span></label>
                            <input type="number" step="0.01" class="form-control" name="pvd_employer_rate" id="pvd_employer_rate" data-i18n="pvd_rate_placeholder" placeholder="e.g., 3.00">
                        </div>
                    </div>
                </div>
            </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): group insurance/welfare tracking, unrelated
                 to payroll calculation. Entire section stays in the DOM. -->
            <div class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                    <span data-i18n="group_insurance">Group Insurance / Welfare</span>
                </h6>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="insurance_plan">Insurance Plan</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <select class="form-select" name="insurance_plan_id" id="insurance_plan_id">
                            <option value="" data-i18n="please_choose">Select an option</option>
                        </select>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="insurance_start_date">Coverage Start Date</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" name="insurance_start_date" id="insurance_start_date" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-5">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextFamily">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <div class="tab-pane fade" id="family-pane" role="tabpanel" aria-labelledby="family-tab" tabindex="0">
            <!-- Redesigned layout (2026-08-19, explicit request: "ช่วยปรับ Design และการจัดวางให้ใหม่")
                 -- each of the 3 sections is now its own .detail-section (existing class, reused from
                 Payroll Run Detail's own sub-section pattern -- deliberately lighter than
                 .card-surface/.page-header-card since it already sits inside #employeeTabsContent's
                 own bordered white container; stacking full .card-surface cards in here would read as
                 cards-within-cards) so the 3 topics read as clearly separate blocks instead of
                 running together under plain <h6> dividers. Spouse's checkbox became the same
                 Yes/No-radio-drives-a-hidden-checkbox pattern as SSO/PVD Enrolled (2026-08-19,
                 explicit request applied consistently across the tab: an unchecked checkbox looks
                 identical to "not answered" and "answered No"). -->
            <div class="detail-section">
                <h6 class="text-secondary fw-bold mb-3 mt-0">
                    <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                    <span data-i18n="spouse">Spouse</span>
                </h6>
                <!-- Row layout matching Parents' question row (2026-08-21, explicit request: "ปุ่ม
                     ใช่ ไม่ใช่ ของคู่สมรส และบุตร วางในตำแหน่งเดียวกับพ่อแม่") -- was a stacked
                     label-above-toggle block, now the same col-sm-3/col-sm-9 row every Yes/No
                     question on this tab (Father/Mother, and now Spouse/Children too) uses. -->
                <div class="row">
                    <div class="col-sm-3 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="has_dependent_spouse">Has spouse with no income (eligible for tax allowance)</span></label>
                    </div>
                    <div class="col-sm-9">
                        <div class="btn-group btn-group-sm" role="group" id="hasSpouseToggle">
                            <button type="button" class="btn btn-outline-brand" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                        <input type="checkbox" class="d-none" name="has_spouse" id="has_spouse">
                    </div>
                </div>
                <div id="spouseDetailFields" class="row d-none">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="spouse_name">Spouse Name</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="spouse_name" id="spouse_name" data-i18n="parent_name_placeholder" placeholder="e.g., Somsak Jaidee">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="spouse_id_card_no">Spouse ID Card No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="spouse_id_card_no" id="spouse_id_card_no" maxlength="13" data-i18n="id_card_no_placeholder" placeholder="13-digit national ID number">
                    </div>
                </div>
            </div>
            <!-- Count-driven inline cards (2026-08-20, replaces the old count+Add-button+modal flow,
                 explicit request: "ปรับเป็นใส่จำนวน แล้วแสดง Card ลูกให้กรอก Auto 1 คน 1 แถว ไม่ต้อง
                 กดปุ่มเพิ่ม") -- typing a number in #dependentCount directly renders that many
                 full-width, inline-editable cards (see syncDependentCardCount()/renderDependentCards()
                 in detail.js), no Add-button click and no modal. "Does this employee have children?"
                 still defaults to Yes automatically once any real dependent card exists -- unchanged
                 from before. Reducing the count below however many cards already have data prompts a
                 SweetAlert2 confirm first (explicit request) rather than silently discarding it.
                 Saving happens only via the Family tab's single Save button (see saveFamilyTab() --
                 every visible card gets (re)saved together with everything else on this tab), not per
                 card, so there's no per-card Save button either. -->
            <div class="detail-section">
                <h6 class="text-secondary fw-bold mb-3 mt-0">
                    <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                    <span data-i18n="children_dependents">Children / Dependents</span>
                </h6>
                <!-- Row layout matching Parents' question row (2026-08-21, explicit request -- see
                     the matching comment on the Spouse question above). -->
                <div class="row">
                    <div class="col-sm-3 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="has_children_question">Does this employee have children?</span></label>
                    </div>
                    <div class="col-sm-9">
                        <div class="btn-group btn-group-sm" role="group" id="hasChildrenToggle">
                            <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                    </div>
                </div>
                <div id="childrenSection" class="d-none">
                    <div class="mt-3">
                        <div class="input-group" style="max-width:280px;">
                            <span class="input-group-text" data-i18n="number_of_children">Number of Children</span>
                            <input type="number" min="0" value="0" class="form-control" id="dependentCount" data-i18n="count_placeholder" placeholder="0">
                        </div>
                    </div>
                    <p class="text-secondary small mb-0 mt-3 d-none" id="dependentEmptyHint" data-i18n="child_empty_hint">Enter a number above to add dependent cards.</p>
                    <div class="mt-3" id="dependentCardsContainer"></div>
                </div>
            </div>
            <!-- Fixed Father/Mother slots (2026-08-19, explicit follow-up request: "มีพ่อแม่แค่ 2 คน
                 ให้มีคำถามว่า ใช้แม่ลดหย่อนไหม ใช้พ่อลดหย่อนไหม ถ้าใช่ค่อยให้ใส่ข้อมูล") -- replaces the
                 earlier generic count-and-add-N-cards flow (still used for Dependents, where the
                 count genuinely isn't known/fixed) with exactly 2 explicit Yes/No questions, since a
                 person only ever has 2 possible parents to claim, not an open-ended list. Still backed
                 by the same `employee_parents` table/API (relationship='father'/'mother' now fixed
                 per slot instead of a dropdown choice) -- see renderParentSlots()/PARENT_SLOTS in
                 detail.js. Toggling "No" only hides the fields, same non-destructive-toggle
                 convention as every other Yes/No question on this tab -- an already-saved
                 father/mother isn't deleted just by hiding the section; a small trash-icon button
                 next to Save is the actual delete action. -->
            <div class="detail-section">
                <h6 class="text-secondary fw-bold mb-3 mt-0">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                    <span data-i18n="parents">Parents (Tax Allowance)</span>
                </h6>
                <!-- Each parent is its own full-width row (2026-08-20, explicit request: "สิทธิ์
                     บิดามารดา ให้เป็นแถวใครแถวมัน") -- was a cramped col-sm-6/col-sm-6 pair, now
                     Father then Mother stacked, each using this page's own col-sm-2/col-sm-4
                     label/input row ratio (same as e.g. the Contact tab) instead of the half-width
                     stacked-label layout. Save button removed -- Father/Mother now save together
                     with everything else on this tab via the one Save button (saveFamilyTab() in
                     detail.js); only the immediate, already-confirmed Delete action remains here. -->
                <div class="row">
                    <div class="col-sm-3 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="claim_father_question">Claim father for tax allowance?</span></label>
                    </div>
                    <div class="col-sm-9">
                        <div class="btn-group btn-group-sm" role="group" id="useFatherToggle">
                            <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                    </div>
                </div>
                <div id="fatherDetailFields" class="row mt-3 d-none">
                    <input type="hidden" id="parent_father_id">
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="name">Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" id="parent_father_name" data-i18n="parent_name_placeholder" placeholder="e.g., Somsak Jaidee">
                    </div>
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="id_card_no">ID Card No.</span></label>
                    </div>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" id="parent_father_id_card_no" maxlength="13" data-i18n="id_card_no_placeholder" placeholder="13-digit national ID number">
                    </div>
                    <div class="col-sm-1 d-flex align-items-center justify-content-end">
                        <button type="button" class="btn btn-sm btn-link text-danger d-none" id="btnDeleteFather" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </div>
                <hr class="my-4 text-muted opacity-25">
                <div class="row">
                    <div class="col-sm-3 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="claim_mother_question">Claim mother for tax allowance?</span></label>
                    </div>
                    <div class="col-sm-9">
                        <div class="btn-group btn-group-sm" role="group" id="useMotherToggle">
                            <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                            <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                        </div>
                    </div>
                </div>
                <div id="motherDetailFields" class="row mt-3 d-none">
                    <input type="hidden" id="parent_mother_id">
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="name">Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" id="parent_mother_name" data-i18n="parent_name_placeholder" placeholder="e.g., Somsak Jaidee">
                    </div>
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="id_card_no">ID Card No.</span></label>
                    </div>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" id="parent_mother_id_card_no" maxlength="13" data-i18n="id_card_no_placeholder" placeholder="13-digit national ID number">
                    </div>
                    <div class="col-sm-1 d-flex align-items-center justify-content-end">
                        <button type="button" class="btn btn-sm btn-link text-danger d-none" id="btnDeleteMother" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-light border btn-cancel-employee-tab">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="btnNextDocuments">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <div class="tab-pane fade" id="documents-pane" role="tabpanel" aria-labelledby="documents-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="attached_documents">Attached Documents</span>
            </h6>
            <div class="row">
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="id_card_copy">ID Card Copy</label>
                    <input type="file" class="form-control" name="doc_id_card_copy" id="doc_id_card_copy" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="house_registration_copy">House Registration Copy</label>
                    <input type="file" class="form-control" name="doc_house_registration_copy" id="doc_house_registration_copy" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="work_permit_copy">Work Permit Copy</label>
                    <input type="file" class="form-control" name="doc_work_permit_copy" id="doc_work_permit_copy" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="employment_contract">Employment Contract</label>
                    <input type="file" class="form-control" name="doc_employment_contract" id="doc_employment_contract" accept="image/*,.pdf">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="bank_book_copy">Bank Book Copy</label>
                    <input type="file" class="form-control" name="doc_bank_book_copy" id="doc_bank_book_copy" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="resume">Resume / CV</label>
                    <input type="file" class="form-control" name="doc_resume" id="doc_resume" accept=".pdf,.doc,.docx">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="education_certificate">Education Certificate</label>
                    <input type="file" class="form-control" name="doc_education_certificate" id="doc_education_certificate" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="other_documents">Other Documents</label>
                    <input type="file" class="form-control" name="doc_other" id="doc_other" multiple accept="image/*,.pdf">
                </div>
            </div>
            <!-- 2026-09-03: 2 new types alongside EmployeeSyncer's own document-scan sync (see
                 EmployeeModel::documentTypes()'s own docblock) -- work_permit_copy already existed
                 above and is reused as-is for a synced work permit scan, so no 3rd field needed here
                 for that one. -->
            <div class="row">
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="passport_copy">Passport Copy</label>
                    <input type="file" class="form-control" name="doc_passport_copy" id="doc_passport_copy" accept="image/*,.pdf">
                </div>
                <div class="col-sm-3 mt-3">
                    <label class="form-label" data-i18n="visa_copy">Visa Copy</label>
                    <input type="file" class="form-control" name="doc_visa_copy" id="doc_visa_copy" accept="image/*,.pdf">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12 mt-4">
                    <table class="table table-bordered table-sm" id="tableDocumentList">
                        <thead>
                            <tr>
                                <th data-i18n="file_name">File Name</th>
                                <th data-i18n="document_type" style="width:200px;">Type</th>
                                <th data-i18n="uploaded_date" style="width:150px;">Uploaded</th>
                                <th data-i18n="source" style="width:120px;">Source</th>
                                <th style="width:100px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- 2026-08-29, explicit request: "ต้องการอีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบโดยแสดง
             ข้อมูลแบบละเอียดตามที่เก็บ...และสามารถ Filter ได้" -- server-side DataTable (this can grow
             unbounded, one row per login), scoped to this one employee via EmployeeLoginLogController's
             own employee_id param. Filter bar follows the simple inline-controls pattern (not the
             fuller collapsible .station-filter used on Employee List/Payroll Process, which is sized
             for a company-wide list with many more filterable dimensions than this 4-field tab needs). -->
        <div class="tab-pane fade" id="login-history-pane" role="tabpanel" aria-labelledby="login-history-tab" tabindex="0">
            <!-- 2026-08-30, same-day follow-up: "Tab ประวัติการเข้าใช้งานใน Employee Detail ยังไม่ใช่
                 Filter มาตรฐานครับ" -- was a bare `row g-2` (this project's own CLAUDE.md explicitly
                 says never to build a filter row this way), now the standard .station-filter
                 component (label + chevron-toggle + collapsible body + a separate Clear Filter
                 button shown only when a filter is active), same pattern as Employee List/Payroll
                 Process/Notifications. IDs on the 4 fields themselves are UNCHANGED, so
                 detail.js's own initLoginHistoryTable()/loadLoginHistoryFilterOptions() needed no
                 changes -- only the wrapper markup + a new toggle/clear-visibility JS pair. -->
            <div class="station-filter" id="loginHistoryStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="loginHistoryStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1" data-i18n="date_from">From</label>
                            <input type="text" class="form-control datepicker" id="loginHistoryFilterDateFrom">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1" data-i18n="date_to">To</label>
                            <input type="text" class="form-control datepicker" id="loginHistoryFilterDateTo">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1" data-i18n="device">Device</label>
                            <select class="form-select select2-native" id="loginHistoryFilterDevice"></select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1" data-i18n="browser">Browser</label>
                            <select class="form-select select2-native" id="loginHistoryFilterBrowser"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="loginHistoryFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearLoginHistoryFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm w-100" id="tableLoginHistory">
                    <thead>
                        <tr>
                            <th data-i18n="login_at">Login At</th>
                            <th data-i18n="logout_at">Logout At</th>
                            <th data-i18n="ip_address">IP Address</th>
                            <th data-i18n="location">Location</th>
                            <th data-i18n="timezone">Timezone</th>
                            <th data-i18n="device">Device</th>
                            <th data-i18n="operating_system">OS</th>
                            <th data-i18n="browser">Browser</th>
                            <!-- 2026-08-30, Phase 7 (T037/T038) -- surfaces the new is_active/ended_reason
                                 columns (employee_login_logs) so this audit table actually shows whether a
                                 session is still active and, if not, WHY it ended (new device login /
                                 switched to Origami / idle timeout) -- appended at the end (not inserted
                                 among the existing columns) so no existing column's sort index shifts. -->
                            <th data-i18n="status">Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($canManagePermissionOverrides)): ?>
        <!-- 2026-09-03, Platform Hardening Phase 3 Stage 5 -- see permissionOverridesTabItem's own
             comment above for the access-control reasoning. Not a DataTable -- a fixed, always-
             fetch-everything list (same reasoning as the Permission Matrix's own plain <table>: this
             is a permission list x ONE-employee grid, not a paginated record list). Lazy-initialized
             on first tab show (public/js/employee/detail.js's own shown.bs.tab handler), same
             DataTables-inside-a-hidden-tab caution as every other lazy tab on this page -- though
             this one isn't a DataTable, fetching before the pane is visible would still be wasted
             work for a tab most sessions never open. -->
        <div class="tab-pane fade" id="permission-overrides-pane" role="tabpanel" aria-labelledby="permission-overrides-tab" tabindex="0">
            <div class="alert alert-light border small mb-3" data-i18n="permission_overrides_hint">
                Override this employee's individual permissions on top of what their Role normally grants. Leaving a permission at "Inherit" means it simply follows their Role as usual.
            </div>
            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-primary btn-sm" id="btnSavePermissionOverrides">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="tablePermissionOverrides">
                    <thead class="table-light">
                        <tr>
                            <th data-i18n="permission">Permission</th>
                            <th class="text-center" style="width:120px;" data-i18n="inherited">Inherited</th>
                            <th class="text-center" style="width:280px;" data-i18n="override">Override</th>
                            <th class="text-center" style="width:220px;" data-i18n="scope">Scope</th>
                        </tr>
                    </thead>
                    <tbody id="permissionOverridesTableBody"></tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<!-- eedModal / recurringEarningModal / empSignaturePadModal / empMapPinModal moved to
     app/views/layout/modals.php (2026-08-30, modal consolidation). -->
<input type="hidden" id="employee_no" value="<?= htmlspecialchars($employee_no ?? '', ENT_QUOTES, 'UTF-8') ?>">
<script src="<?=asset('public/js/employee/detail.js')?>"></script>