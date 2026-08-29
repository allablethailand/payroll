<style>
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
        <li class="nav-item employee-secondary-tab<?= $employee_no ? '' : ' d-none' ?>" role="presentation">
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
        <!-- Hidden 2026-08-19 (not needed for Payroll, agreed alongside the rest of the field trim --
             this specific tab/pane hide was missed in that pass, corrected here): pure file
             attachments, unrelated to payroll processing. Pane below stays d-none too; its own
             upload JS is otherwise untouched, so re-showing both later is a two-line revert. -->
        <li class="nav-item d-none" role="presentation">
            <button class="nav-link text-secondary" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents-pane" type="button" role="tab" aria-controls="documents-pane" aria-selected="false"><i class="fa-solid fa-paperclip me-1"></i><span data-i18n="documents">Documents</span></button>
        </li>
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                    <input type="text" class="form-control required" name="name_th" id="name_th">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="surname_local">Surname (Local)</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="surname_th" id="surname_th">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="name_en">Name (EN)</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="name_en" id="name_en">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="surname_en">Surname (EN)</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="surname_en" id="surname_en">
                </div>
            </div>
            <!-- Hidden 2026-08-19 (not needed for Payroll): cosmetic-only, not used in any statutory
                 calc/report. Field stays in the DOM (value still submits/saves/syncs normally). -->
            <div class="row d-none">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="nickname_local">Nickname (Local)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="nickname_th" id="nickname_th">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="nickname_en">Nickname (EN)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="nickname_en" id="nickname_en">
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                        <input type="text" class="form-control" name="id_card_no" id="id_card_no" maxlength="13">
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
                        <input type="text" class="form-control" name="tax_id_no" id="tax_id_no">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="passport_no">Passport No.</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="passport_no" id="passport_no">
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
                        <input type="text" class="form-control" name="work_permit_no" id="work_permit_no">
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
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="visa_type">Visa Type</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="visa_type" id="visa_type">
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
            </div>
            <!-- 2026-08-26, explicit request: "ย้าย Tab Signature มาไว้ใน Info" -- moved here from the
                 Contact tab (where it sat since it was first built), same upload-or-draw card as
                 Company Profile's own Authorized Signature section. -->
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
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
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-5">
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
                        <input type="email" class="form-control" name="company_email" id="company_email">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="office_tel">Telephone No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="office_tel" id="office_tel">
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
                    <input type="email" class="form-control required" name="personal_email" id="personal_email">
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
                    <input type="tel" class="form-control required" name="mobile_no" id="mobile_no" maxlength="15">
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
                    <input type="text" class="form-control" name="line_id" id="line_id">
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
                        <input type="text" class="form-control" name="address_line_1_register" id="address_line_1_register">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_2">Address Line 2 (Optional)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_2_register" id="address_line_2_register">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 position-relative">
                        <input type="text" class="form-control autocomplete-address" id="search_address_register" autocomplete="off">
                        <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                        <input type="hidden" name="master_address_id_register" class="master-address-id-field" id="master_address_id_register">
                    </div>
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
                        <input type="text" class="form-control" name="address_line_1_contact" id="address_line_1_contact">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="address_line_2">Address Line 2 (Optional)</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="address_line_2_contact" id="address_line_2_contact">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span></label>
                    </div>
                    <div class="col-sm-4 mt-3 position-relative">
                        <input type="text" class="form-control autocomplete-address" id="search_address_contact" autocomplete="off">
                        <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                        <input type="hidden" name="master_address_id_contact" class="master-address-id-field" id="master_address_id_contact">
                    </div>
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
                        <input type="text" class="form-control" name="emergency_name" id="emergency_name">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="surname">Surname</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_surname" id="emergency_surname">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="relationship">Relationship</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_relationship" id="emergency_relationship">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="mobile_no">Mobile No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="emergency_mobile" id="emergency_mobile" maxlength="10">
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-5">
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
                    <input type="text" class="form-control required" name="employee_no" id="employee_no_input">
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
                        <option value="probation" data-i18n="probation">Probation</option>
                        <option value="permanent" data-i18n="permanent">Permanent</option>
                        <option value="contract" data-i18n="contract">Contract</option>
                        <option value="resigned" data-i18n="resigned">Resigned</option>
                        <option value="terminated" data-i18n="terminated">Terminated</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employment_type">Employment Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="employment_type" id="employment_type">
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                    <textarea class="form-control" name="employment_end_reason" id="employment_end_reason" maxlength="255" rows="2"></textarea>
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="driver_license">Driver License No.</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="driver_license_no" id="driver_license_no">
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
                        <option value="" data-i18n="please_choose">Please choose.</option>
                        <option value="fingerprint" data-i18n="fingerprint">Fingerprint</option>
                        <option value="qr_code" data-i18n="qr_code">QR Code</option>
                        <option value="mobile_app" data-i18n="mobile_app">Mobile App</option>
                        <option value="manual" data-i18n="manual">Manual</option>
                        <option value="none" data-i18n="none">None</option>
                    </select>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="payment_information">Payment Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="payment_type">Payment Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="btn-group d-block" role="group" aria-label="Payment type">
                        <input type="radio" class="btn-check" name="payment_type_radio" id="payment_bank" value="bank" checked>
                        <label class="btn btn-outline-brand" for="payment_bank" data-i18n="bank">Bank</label>
                        <input type="radio" class="btn-check" name="payment_type_radio" id="payment_cash" value="cash">
                        <label class="btn btn-outline-brand" for="payment_cash" data-i18n="cash">Cash</label>
                    </div>
                    <input type="hidden" name="payment_type" id="payment_type" value="bank">
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
                    <input type="text" class="form-control" name="bank_account_no" id="bank_account_no">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="bank_account_name">Bank Account Name</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="bank_account_name" id="bank_account_name">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="bank_branch">Bank Branch</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="bank_branch" id="bank_branch">
                </div>
            </div>
            <div class="d-flex justify-content-end mt-5">
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
                    <select class="form-select select2-native required" name="salary_type" id="salary_type">
                        <option value="monthly" data-i18n="monthly">Monthly</option>
                        <option value="daily" data-i18n="daily">Daily</option>
                        <option value="hourly" data-i18n="hourly">Hourly</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="base_salary_amount">Base Salary Amount</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control text-end required" name="base_salary_amount" id="base_salary_amount">
                        <span class="input-group-text" data-i18n="thb">THB</span>
                    </div>
                </div>
            </div>
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
                    <label class="form-label"><span data-i18n="ot_eligible">OT Eligible</span></label>
                </div>
                <div class="col-sm-4 mt-3 pt-2">
                    <input type="checkbox" class="me-2" name="ot_eligible" id="ot_eligible"><span data-i18n="eligible_for_overtime">Eligible for overtime pay</span>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="modal_cycle">Payroll Schedule</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="cycle_id" id="cycle_id" data-api="/api/payroll-cycle.options">
                    </select>
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
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
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
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="tax_information">Tax Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="tax_calculation_method">Tax Calculation Method</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="tax_calculation_method" id="tax_calculation_method">
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
            <div class="d-flex justify-content-end mt-5">
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
                            <input type="text" class="form-control" name="sso_no" id="sso_no" maxlength="13">
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="sso_start_date">SSO Start Date</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" name="sso_start_date" id="sso_start_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <!-- Hidden 2026-08-19 (not needed for Payroll): informational only (which hospital
                         the employee is registered at) -- doesn't affect the SSO contribution amount,
                         and isn't read by StatutoryCalculationEngine or the SSO reports (those use
                         sso_no). -->
                    <div class="mt-3 d-none">
                        <label class="form-label d-block"><span data-i18n="sso_hospital">Hospital</span></label>
                        <select class="form-select" name="sso_hospital_id" id="sso_hospital_id">
                            <option value="" data-i18n="please_choose">Please choose.</option>
                        </select>
                    </div>
                    <!-- Hidden 2026-08-19 (not needed for Payroll): SSO contribution rate is
                         company-wide (company_statutory_settings), never read per-employee by the
                         calculation engine. -->
                    <div class="mt-3 d-none">
                        <label class="form-label d-block"><span data-i18n="sso_contribution_rate">Contribution Rate (%)</span></label>
                        <input type="number" step="0.01" class="form-control" name="sso_contribution_rate" id="sso_contribution_rate" value="5.00">
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
                            <input type="text" class="form-control" name="pvd_fund_name" id="pvd_fund_name">
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
                            <input type="number" step="0.01" class="form-control" name="pvd_employee_rate" id="pvd_employee_rate">
                        </div>
                        <div class="mt-3">
                            <label class="form-label d-block"><span data-i18n="pvd_employer_rate">Employer Rate (%)</span></label>
                            <input type="number" step="0.01" class="form-control" name="pvd_employer_rate" id="pvd_employer_rate">
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
                            <option value="" data-i18n="please_choose">Please choose.</option>
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
            <div class="d-flex justify-content-end mt-5">
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
                        <input type="text" class="form-control" name="spouse_name" id="spouse_name">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="spouse_id_card_no">Spouse ID Card No.</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="spouse_id_card_no" id="spouse_id_card_no" maxlength="13">
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
                            <input type="number" min="0" value="0" class="form-control" id="dependentCount">
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
                        <input type="text" class="form-control" id="parent_father_name">
                    </div>
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="id_card_no">ID Card No.</span></label>
                    </div>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" id="parent_father_id_card_no" maxlength="13">
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
                        <input type="text" class="form-control" id="parent_mother_name">
                    </div>
                    <div class="col-sm-2 align-self-center">
                        <label class="form-label mb-0"><span data-i18n="id_card_no">ID Card No.</span></label>
                    </div>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" id="parent_mother_id_card_no" maxlength="13">
                    </div>
                    <div class="col-sm-1 d-flex align-items-center justify-content-end">
                        <button type="button" class="btn btn-sm btn-link text-danger d-none" id="btnDeleteMother" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
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
            <div class="row">
                <div class="col-sm-12 mt-4">
                    <table class="table table-bordered table-sm" id="tableDocumentList">
                        <thead>
                            <tr>
                                <th data-i18n="file_name">File Name</th>
                                <th data-i18n="document_type" style="width:200px;">Type</th>
                                <th data-i18n="uploaded_date" style="width:150px;">Uploaded</th>
                                <th style="width:100px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="eedModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="eedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="eedModalLabel">
                    <span data-i18n="add_earning_deduction">Add Income / Deduction</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="eedForm" novalidate>
                <input type="hidden" id="eed_id" name="id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="sec_general_info">General Information</span>
                    </h6>
                    <!-- Catalog vs custom item toggle (2026-08-19, explicit request: "ในส่วนของ Item
                         ให้สามารถใส่เองได้ โดยบอกว่าเป็นรายได้หรือรายหัก") -- same shape as the Payroll
                         Run Detail page's own manual-line custom-item toggle (#manualLineModeToggle in
                         app/views/payroll/detail.php), reusing its exact lang keys for consistency. -->
                    <div class="d-flex justify-content-end mb-3">
                        <div class="btn-group btn-group-sm" role="group" id="eedModeToggle">
                            <button type="button" class="btn btn-outline-brand active" data-mode="catalog"><i class="fa-solid fa-list me-1"></i><span data-i18n="manual_line_mode_catalog">From List</span></button>
                            <button type="button" class="btn btn-outline-brand" data-mode="custom"><i class="fa-solid fa-pen me-1"></i><span data-i18n="manual_line_mode_custom">Custom Item</span></button>
                        </div>
                    </div>
                    <div class="row mb-3" id="eedCatalogFields">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="eed_ped_type_id" name="ped_type_id" data-api="/api/employee.earning-deduction.options" data-type=""></select>
                        </div>
                    </div>
                    <!-- Type selector removed from here (2026-08-21, explicit request: "เลือกแล้วว่า
                         จะเป็นเงินได้หรือเงินหัก ตอนที่เลือกแบบกรอกเอง ไม่ต้องมีให้เลือกอีก...มันดู
                         ซ้ำซ้อน") -- which Add button opened this modal (context, see resetEedForm())
                         already fixes earning-vs-deduction for the whole modal session (the catalog
                         dropdown above is itself pre-filtered to that same type, so switching to/from
                         custom mode never changes it either) -- asking again here was redundant.
                         #eed_custom_item_type stays as a plain hidden field carrying that fixed type
                         for the save payload + interest-section gating (see applyEedInterestVisibility()
                         in detail.js), it's just never user-facing anymore. -->
                    <div class="row mb-3 d-none" id="eedCustomFields">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_custom_item_name">Item Name</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="eed_custom_item_name" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                        </div>
                        <input type="hidden" id="eed_custom_item_type" name="custom_item_type">
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <!-- 2026-08-21, explicit request ("Form ยังดูไม่สมดุล"): was col-sm-4, leaving
                                 col-sm-5 of dead space to the right -- every other single-field row in
                                 this modal (Item, Interest, Amount per Installment, Reference, Notes)
                                 already uses col-sm-9, matched here for the same reason. -->
                            <div class="input-group">
                                <input type="text" class="form-control datepicker required" id="eed_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="sec_installment_settings">Installment Settings</span>
                    </h6>
                    <!-- Total Installments + Amount merged into one row (2026-08-21, explicit request:
                         "Form ยังดูไม่สมดุล") -- each used to be its own col-sm-3/col-sm-3 row, leaving
                         col-sm-6 of dead space to the right of every one of them. Pairing the two
                         numbers that directly drive the installment schedule together (both feed
                         computeInstallmentSchedule()) fills the row properly and reads as a more
                         natural "how many periods, how much total" narrative than having Interest
                         sandwiched between them like before -- Interest (which configures HOW that
                         schedule gets computed) now follows both inputs instead of splitting them. -->
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="total_installments">Total Installments</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="1" min="1" class="form-control required" id="eed_total_installments" name="total_installments" value="1">
                        </div>
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" id="eed_principal_amount_label">
                                <span data-i18n="total_amount">Total Amount</span>
                                <span data-i18n="principal_amount_label" class="d-none">Principal Amount</span>
                                <span class="text-danger">*</span>
                            </label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0.01" class="form-control required" id="eed_principal_amount" name="principal_amount">
                        </div>
                    </div>
                    <!-- Interest support (2026-08-20, explicit request: "อยากให้มีการกำหนดได้ค่าคิด
                         ดอกเบี้ยหรือไม่คิดดอกเบี้ย...ถ้ามีการคิดดอกเบี้ย ก็ต้องกำหนดต่อได้ว่าดอกเบี้ย
                         แบบไหน คงที่ ลดต้นลดดอก"). Same btn-outline-brand toggle-group convention as
                         every other yes/no choice on this page (type/gender/payment-type, SSO/PVD
                         enrolled, etc). interest_rate is % PER INSTALLMENT PERIOD, not annual -- see
                         EmployeeEarningDeductionModel::computeInstallmentSchedule()'s docblock for why.
                         2026-08-21 follow-up (explicit request: "รายรับให้ตัดเรื่องดอกเบี้ยไปเลย มีแค่
                         รายหักที่บอกว่าคิดหรือไม่คิดดอกเบี้ย") -- this whole section only makes sense
                         for deductions (a loan/salary deduction can carry interest, an earning never
                         does), so it's now hidden entirely for earning items -- see
                         applyEedInterestVisibility() in detail.js, driven by #eed_custom_item_type
                         (the fixed session item type, see the comment above #eedCustomFields). -->
                    <div id="eedInterestSection">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="interest_label">Interest</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="btn-group btn-group-sm" role="group" id="eedInterestToggle">
                                    <button type="button" class="btn btn-outline-brand active" data-value="none"><span data-i18n="interest_none">No Interest</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-value="has_interest"><span data-i18n="interest_has">With Interest</span></button>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="eedInterestDetailWrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="interest_type">Interest Type</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9 d-flex align-items-center flex-wrap gap-2">
                                <div class="btn-group btn-group-sm" role="group" id="eedInterestTypeToggle">
                                    <button type="button" class="btn btn-outline-brand active" data-value="fixed"><span data-i18n="interest_fixed">Flat</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-value="reducing_balance"><span data-i18n="interest_reducing_balance">Reducing Balance</span></button>
                                </div>
                                <div class="input-group input-group-sm" style="max-width:180px;">
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="eed_interest_rate" name="interest_rate" placeholder="0.00">
                                    <span class="input-group-text" data-i18n="interest_rate_suffix">% / installment</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Always-visible, always-editable installment schedule (2026-08-20, explicit
                         request: "ให้คำนวณให้อัตโนมัติหากใส่ยอดหักทั้งหมด แต่สามารถแก้ไขได้" +
                         "Status ของแต่ละงวดการจ่าย...จ่ายแล้วหรือรอจ่าย") -- replaces the old
                         even_split/custom_per_installment radio pair; amounts are pre-filled by
                         GET /api/employee.earning-deduction.preview-installments (debounced, fires on
                         principal/installment-count/interest changes) but every cell stays a plain
                         editable input. Status column only appears once an existing assignment's real
                         installments (with their pending/processed/skipped status) are loaded -- a
                         brand-new Add has no installments yet, so nothing to show there. -->
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="installment_amounts">Amount per Installment</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="table-responsive eed-installment-table-wrap">
                                <table class="table table-sm table-striped align-middle mb-0" id="eedInstallmentTable">
                                    <thead>
                                        <tr>
                                            <th class="text-muted small" style="width:15%;" data-i18n="installment_no_col">#</th>
                                            <th class="text-muted small" data-i18n="installment_amount_col">Amount</th>
                                            <th class="text-muted small d-none" id="eedInstallmentStatusHeader" data-i18n="installment_status_col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="eedInstallmentTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="external_reference_no">Reference / Contract No.</label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="eed_external_reference_no" name="external_reference_no" maxlength="100">
                        </div>
                    </div>
                    <!-- Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร โดยเลือก
                         พนักงานได้ว่าจะหักของคนนี้ไปให้คนนี้") -- only meaningful on a deduction, so
                         toggled alongside the interest section (both deduction-only) by
                         applyEedInterestVisibility() in detail.js. Reuses /api/employee.report_to.get
                         (data-exclude-id set to the employee whose detail page this is, same pattern
                         already documented in input.js for #report_to_id) rather than a new endpoint. -->
                    <div class="row mb-3 d-none" id="eedPayeeWrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="eed_payee_employee_id" name="payee_employee_id" data-api="/api/employee.report_to.get" data-type="employee"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="notes">Notes</label>
                        </div>
                        <div class="col-sm-9">
                            <textarea class="form-control" id="eed_notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" id="eedSaveBtn" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Recurring Allowance modal -- 2026-08-26, explicit request, see EmployeeRecurringEarningModel's
     own docblock. Catalog-only (no free-text/custom item, unlike #eedModal above) since this
     feature is specifically "assign one of the company's own fixed-amount earning types to this
     employee at their own flat monthly amount". -->
<div class="modal fade" id="recurringEarningModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="recurringEarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="recurringEarningModalLabel">
                    <span data-i18n="add_recurring_earning">Add Recurring Allowance</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="recurringEarningForm" novalidate>
                <input type="hidden" id="ere_id" name="id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <select class="form-select select2-remote required" id="ere_ped_type_id" name="ped_type_id" data-api="/api/employee.recurring-earning.type-options"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="amount">Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control text-end required" id="ere_amount" name="amount">
                                <span class="input-group-text" data-i18n="thb">THB</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker required" id="ere_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-2"><span data-i18n="suspend_period">Suspend Period</span></h6>
                    <!-- Confirmed via AskUserQuestion: "suspend" is a date RANGE, not a plain on/off
                         toggle -- both fields set together or neither, enforced server-side too
                         (EmployeeRecurringEarningModel::save()). -->
                    <p class="text-secondary small mb-3" data-i18n="suspend_period_hint">*Optional. While set, this allowance is skipped in any payroll run whose pay period overlaps this range, then resumes automatically afterward.</p>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_from">Suspend From</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="ere_suspended_from" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_to">Suspend To</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="ere_suspended_to" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0" data-i18n="notes">Notes</label>
                        </div>
                        <div class="col-sm-8">
                            <textarea class="form-control" id="ere_notes" rows="2" maxlength="255"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" id="ereSaveBtn" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Shared Dependent/Parent edit modal (2026-08-19, explicit request: card layout, "กด edit" opens a
     modal rather than inline table-cell editing) -- one modal for both entity types (#child_entity
     tracks which), same generic dependent/parent pairing already used server-side
     (EmployeeModel::childConfig()). date_of_birth/studying only apply to a dependent, hidden for a
     parent. Relationship is now a static dropdown (#child_relationship) instead of free text -- its
     option set is swapped per entity type in detail.js (children get legitimate/adopted child,
     parents get father/mother/spouse's father/spouse's mother), since the DB column itself is a
     plain, uncontrolled varchar never read by any calc/report (only ever displayed), so there's no
     schema reason the two entity types must share one option list. -->
<!-- #childModal removed (2026-08-20) -- Dependents are now filled in directly on their inline
     card in #dependentCardsContainer (see renderDependentCards()/dependentCardHtml() in
     detail.js), no modal needed. This was the only remaining consumer of the modal (Parents
     moved to inline father/mother fields back on 2026-08-19), so removing it also resolves the
     "modal body doesn't scroll" report -- there's no modal left to have that bug. -->
<!-- 2026-08-26, explicit request: "เพิ่มการเก็บลายเซ็นต์ของพนักงานแต่ละคนได้" -- signature-pad modal,
     direct port of Company Profile's own #cpSignaturePadModal (plain mouse/touch canvas drawing, no
     new dependency). -->
<div class="modal fade" id="empSignaturePadModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-pen-nib me-2"></i><span data-i18n="draw_signature">Draw Signature</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <canvas id="empSignaturePadCanvas" class="cp-signature-pad-canvas" width="500" height="220"></canvas>
                <p class="text-muted small mt-2 mb-0" data-i18n="draw_signature_hint">Draw with your mouse or finger, then click Save.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="empSignaturePadClearBtn"><i class="fa-solid fa-eraser me-1"></i><span data-i18n="clear">Clear</span></button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="empSignaturePadSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<!-- 2026-08-26, explicit request: "ส่วนของที่อยู่ให้เพิ่มสามารถปักหมุด Location บน Map ได้" -- OpenStreetMap
     + Leaflet map-pin modal. A Nominatim (OSM's own free geocoder) search box jumps the map to an
     address; clicking anywhere on the map (or dragging the marker) drops/moves the pin. Leaflet needs
     a real visible, correctly-sized container to compute its tile grid against -- initialized on
     `shown.bs.modal` (after the modal has finished sizing), with `invalidateSize()` called right after
     in case the container was already created once before (same "recompute after the modal is
     actually visible" precedent as the Payslip/Employment Certificate Template editors' own
     fullscreen-modal canvas resize). -->
<div class="modal fade" id="empMapPinModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-map-location-dot me-2"></i><span data-i18n="pin_location_on_map">Pin Location on Map</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-2" id="empMapSearchInput" data-i18n="map_search_placeholder" placeholder="Search for an address...">
                <div id="empMapPinContainer" style="width:100%;height:360px;border-radius:8px;overflow:hidden;"></div>
                <p class="text-muted small mt-2 mb-0" data-i18n="map_pin_hint">Click anywhere on the map, or drag the marker, to set the location.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="empMapPinSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<input type="hidden" id="employee_no" value="<?= htmlspecialchars($employee_no ?? '', ENT_QUOTES, 'UTF-8') ?>">
<script src="<?=asset('public/js/employee/detail.js')?>"></script>