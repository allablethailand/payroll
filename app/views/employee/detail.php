<style>
/* Employee Detail profile header + completeness (2026-08-19, explicit request). */
.employee-avatar-lg {
    width: 56px;
    height: 56px;
    min-width: 56px;
    border-radius: 50%;
    background: #007aff;
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
/* Mobile prefix select2 inside a Bootstrap input-group (2026-08-19, explicit request: "ให้อยู่ติดกัน
   เป็น input group"). This app's initSelect2()'s native-mode config always sets an inline
   width:100% on the .select2-container it creates (100% of the immediate parent, here the
   .input-group) -- without overriding that, the prefix dropdown would try to claim the input-group's
   FULL width instead of sitting compactly next to the mobile number field. !important is needed to
   beat that inline style. Border-radius flattened on the touching sides so the pair reads as one
   connected control, same as any other input-group in this app. */
.input-group > .select2-container {
    width: 130px !important;
    flex: 0 0 auto;
}
.input-group > .select2-container .select2-selection {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.input-group > .select2-container + .form-control {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
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
            <button class="nav-link text-secondary" id="earningDeduction-tab" data-bs-toggle="tab" data-bs-target="#earningDeduction-pane" type="button" role="tab" aria-controls="earningDeduction-pane" aria-selected="false"><i class="fa-solid fa-money-bill-transfer me-1"></i><span data-i18n="earning_deduction_assignments">Earnings & Deductions</span></button>
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
        <div class="tab-pane fade show active p-3 p-md-4" id="info-pane" role="tabpanel" aria-labelledby="info-tab" tabindex="0">
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
            <div class="d-flex justify-content-end mt-5">
                <button type="button" class="btn btn-warning" id="btnNextContact">
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
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <!-- Flag emoji + code (2026-08-19, explicit request: "มีธงชาติด้วย") -- plain
                             UTF-8 characters in the <option> text, no image assets/extra markup
                             needed; select2-native's default templating just renders option.text
                             as-is in both the closed state and the dropdown list. Default is the
                             first option (+66), same as employees.mobile_country_code's own DB
                             DEFAULT -- no explicit `selected` needed. -->
                        <select class="form-select select2-native" name="mobile_country_code" id="mobile_country_code" style="max-width:130px;flex:0 0 130px;">
                            <option value="+66">🇹🇭 +66</option>
                            <option value="+65">🇸🇬 +65</option>
                            <option value="+60">🇲🇾 +60</option>
                            <option value="+1">🇺🇸 +1</option>
                        </select>
                        <input type="text" class="form-control required" name="mobile_no" id="mobile_no" maxlength="10">
                    </div>
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
            <!-- Hidden 2026-08-19 (not needed for Payroll): registered/contact address and emergency
                 contact are HR-record fields, never read by any statutory calc/report/sync in this app.
                 required class removed from every field below AND from EmployeeModel::requiredColumns()
                 in the same commit -- otherwise hidden-but-still-required fields would silently block
                 every employee save (validateEmployeeForm() checks .required regardless of visibility). -->
            <div class="d-none">
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label>
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
                    <label class="label label-head bg-head-first rounded-2 text-white">4</label>
                    <span data-i18n="contact_address">Contact Address</span>
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
                <h6 class="text-secondary fw-bold mb-3 mt-5">
                    <label class="label label-head bg-head-first rounded-2 text-white">5</label>
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
                <button type="button" class="btn btn-warning" id="btnNextEmployment">
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
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="role">Role</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="role_id" id="role_id" data-api="/api/role.get" data-type="role">
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="position">Position</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="position_id" id="position_id" data-api="/api/position.get" data-type="position">
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="employee_no">Employee No.</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="employee_no" id="employee_no_input">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="branch">Branch</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="branch_id" id="branch_id" data-api="/api/branch.get" data-type="branch">
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="work_location">Work Location</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="work_location_id" id="work_location_id" data-api="/api/work-location.options" data-type="location">
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="shift">Shift</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote required" name="shift_id" id="shift_id" data-api="/api/shift.options" data-type="shift">
                    </select>
                </div>
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
                <button type="button" class="btn btn-warning" id="btnNextSalary">
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
                    <label class="form-label"><span data-i18n="modal_cycle">Payroll Cycle</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-remote" name="cycle_id" id="cycle_id" data-api="/api/payroll-cycle.options">
                    </select>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
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
                <button type="button" class="btn btn-warning" id="btnNextSocial">
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
                        <button class="nav-link active" id="eedEarningSub-tab" data-bs-toggle="pill" data-bs-target="#eedEarningSub-pane" type="button" role="tab" aria-controls="eedEarningSub-pane" aria-selected="true"><i class="fa-solid fa-arrow-trend-up me-2"></i><span data-i18n="earning_singular">Earning</span></button>
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
                 (#ssoDetailFields, toggled in detail.js) -- also explicit request. -->
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
                <button type="button" class="btn btn-warning" id="btnNextFamily">
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
                <div class="mt-3">
                    <label class="form-label d-block mb-1"><span data-i18n="has_dependent_spouse">Has spouse with no income (eligible for tax allowance)</span></label>
                    <div class="btn-group btn-group-sm" role="group" id="hasSpouseToggle">
                        <button type="button" class="btn btn-outline-brand" data-value="no"><span data-i18n="no">No</span></button>
                        <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                    </div>
                    <input type="checkbox" class="d-none" name="has_spouse" id="has_spouse">
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
            <!-- Card layout + has-children gate (2026-08-19, explicit request) -- table replaced with
                 a card per dependent, edited via a shared modal (#childModal, click the card's Edit
                 icon) rather than inline table-cell editing. "Does this employee have children?"
                 defaults to Yes automatically once any real dependent card exists (see
                 renderChildCards() in detail.js) -- the toggle+count are purely a client-side
                 add-helper (not persisted as their own field): entering a count and clicking Add
                 appends that many blank "click to fill in" cards on top of whatever's already saved,
                 it never deletes or auto-fills anything. Selecting "No" just hides the section again;
                 it does not delete existing dependents (an existing "Yes, has kids" employee should
                 never lose data just from toggling this while looking at the form). -->
            <div class="detail-section">
                <h6 class="text-secondary fw-bold mb-3 mt-0">
                    <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                    <span data-i18n="children_dependents">Children / Dependents</span>
                </h6>
                <div class="mt-3">
                    <label class="form-label d-block mb-1"><span data-i18n="has_children_question">Does this employee have children?</span></label>
                    <div class="btn-group btn-group-sm" role="group" id="hasChildrenToggle">
                        <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                        <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                    </div>
                </div>
                <div id="childrenSection" class="d-none">
                    <div class="mt-3">
                        <div class="input-group" style="max-width:320px;">
                            <span class="input-group-text" data-i18n="number_of_children">Number of Children</span>
                            <input type="number" min="1" value="1" class="form-control" id="dependentAddCount">
                            <button type="button" class="btn text-white" id="btnAddDependent" style="background-color:#FF9900;border-color:#FF9900;">
                                <i class="fas fa-plus me-1"></i><span data-i18n="add">Add</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-secondary small mb-0 mt-3 d-none" id="dependentEmptyHint" data-i18n="child_empty_hint">No dependents added yet -- enter a count above and click Add.</p>
                    <div class="row mt-3" id="dependentCardsContainer"></div>
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
                <div class="row">
                    <div class="col-sm-6">
                        <div class="mt-3">
                            <label class="form-label d-block mb-1"><span data-i18n="claim_father_question">Claim father for tax allowance?</span></label>
                            <div class="btn-group btn-group-sm" role="group" id="useFatherToggle">
                                <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                                <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                            </div>
                        </div>
                        <div id="fatherDetailFields" class="d-none mt-3">
                            <input type="hidden" id="parent_father_id">
                            <div class="mb-2">
                                <label class="form-label mb-1"><span data-i18n="name">Name</span></label>
                                <input type="text" class="form-control form-control-sm" id="parent_father_name">
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1"><span data-i18n="id_card_no">ID Card No.</span></label>
                                <input type="text" class="form-control form-control-sm" id="parent_father_id_card_no" maxlength="13">
                            </div>
                            <button type="button" class="btn btn-sm btn-warning" id="btnSaveFather"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
                            <button type="button" class="btn btn-sm btn-link text-danger d-none" id="btnDeleteFather" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="mt-3">
                            <label class="form-label d-block mb-1"><span data-i18n="claim_mother_question">Claim mother for tax allowance?</span></label>
                            <div class="btn-group btn-group-sm" role="group" id="useMotherToggle">
                                <button type="button" class="btn btn-outline-brand active" data-value="no"><span data-i18n="no">No</span></button>
                                <button type="button" class="btn btn-outline-brand" data-value="yes"><span data-i18n="yes">Yes</span></button>
                            </div>
                        </div>
                        <div id="motherDetailFields" class="d-none mt-3">
                            <input type="hidden" id="parent_mother_id">
                            <div class="mb-2">
                                <label class="form-label mb-1"><span data-i18n="name">Name</span></label>
                                <input type="text" class="form-control form-control-sm" id="parent_mother_name">
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-1"><span data-i18n="id_card_no">ID Card No.</span></label>
                                <input type="text" class="form-control form-control-sm" id="parent_mother_id_card_no" maxlength="13">
                            </div>
                            <button type="button" class="btn btn-sm btn-warning" id="btnSaveMother"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
                            <button type="button" class="btn btn-sm btn-link text-danger d-none" id="btnDeleteMother" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-4">
                <button type="button" class="btn btn-warning" id="btnNextDocuments">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="eedModalLabel">
                    <span data-i18n="add_earning_deduction">Add Earning / Deduction</span>
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
                            <button type="button" class="btn btn-outline-secondary active" data-mode="catalog"><i class="fa-solid fa-list me-1"></i><span data-i18n="manual_line_mode_catalog">From List</span></button>
                            <button type="button" class="btn btn-outline-secondary" data-mode="custom"><i class="fa-solid fa-pen me-1"></i><span data-i18n="manual_line_mode_custom">Custom Item</span></button>
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
                    <div class="row mb-3 d-none" id="eedCustomFields">
                        <div class="col-sm-7">
                            <label class="form-label mb-1 small text-muted"><span data-i18n="modal_custom_item_name">Item Name</span></label>
                            <input type="text" class="form-control" id="eed_custom_item_name" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label mb-1 small text-muted"><span data-i18n="modal_item_type">Type</span></label>
                            <select class="form-select select2-static" id="eed_custom_item_type" data-option-keys="breakdown_earnings,table_deduction_amount" data-option-values="earning,deduction"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4">
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
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="total_installments">Total Installments</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="1" min="1" class="form-control required" id="eed_total_installments" name="total_installments" value="1">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="amount_mode">Amount Mode</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="amount_mode" id="eed_mode_even" value="even_split" checked>
                                <label class="form-check-label" for="eed_mode_even" data-i18n="even_split">Split evenly across installments</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="amount_mode" id="eed_mode_custom" value="custom_per_installment">
                                <label class="form-check-label" for="eed_mode_custom" data-i18n="custom_per_installment">Set amount per installment</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3" id="eed_total_amount_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="total_amount">Total Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0.01" class="form-control required" id="eed_total_amount" name="total_amount">
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="eed_custom_amounts_wrapper">
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="installment_amounts">Amount per Installment</label>
                        </div>
                        <div class="col-sm-9" id="eed_custom_amounts_container"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="external_reference_no">Reference / Contract No.</label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="eed_external_reference_no" name="external_reference_no" maxlength="100">
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
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" data-i18n="save_item">Save Item</button>
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
<div class="modal fade" id="childModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="childModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="childModalLabel">
                    <span data-i18n="add_dependent">Add dependent</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="childForm" novalidate>
                <input type="hidden" id="child_id">
                <input type="hidden" id="child_entity">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="name">Name</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <input type="text" class="form-control required" id="child_name">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="id_card_no">ID Card No.</span></label>
                        </div>
                        <div class="col-sm-8">
                            <input type="text" class="form-control" id="child_id_card_no" maxlength="13">
                        </div>
                    </div>
                    <div class="row mb-3" id="childDobRow">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="date_of_birth">Date of Birth</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="child_date_of_birth" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="relationship">Relationship</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <select class="form-select select2-static required" id="child_relationship"></select>
                        </div>
                    </div>
                    <div class="row mb-3" id="childStudyingRow">
                        <div class="col-sm-8 offset-sm-4">
                            <input type="checkbox" class="me-2" id="child_studying"><span data-i18n="studying">Studying</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<input type="hidden" id="employee_no" value="<?= htmlspecialchars($employee_no ?? '', ENT_QUOTES, 'UTF-8') ?>">
<script src="<?=asset('public/js/employee/detail.js')?>"></script>