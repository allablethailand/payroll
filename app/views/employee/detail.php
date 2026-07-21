<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="employee">Employee</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current">
                <?php if ($employee_no): ?>
                    <?= htmlspecialchars($employee_no, ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <span data-i18n="new_employee">New Employee</span>
                <?php endif; ?>
            </span>
        </h5>
    </nav>
    <ul class="nav nav-tabs" id="employeeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-pane" type="button" role="tab" aria-controls="info-pane" aria-selected="true"><i class="fa-solid fa-circle-user me-1"></i><span data-i18n="employee_info">Employee Info</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-pane" type="button" role="tab" aria-controls="contact-pane" aria-selected="false"><i class="fa-solid fa-address-book me-1"></i><span data-i18n="contact">Contact</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="employment-tab" data-bs-toggle="tab" data-bs-target="#employment-pane" type="button" role="tab" aria-controls="employment-pane" aria-selected="false"><i class="fa-solid fa-building-user me-1"></i><span data-i18n="employment">Employment</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="salary-tab" data-bs-toggle="tab" data-bs-target="#salary-pane" type="button" role="tab" aria-controls="salary-pane" aria-selected="false"><i class="fa-solid fa-file-invoice-dollar me-1"></i><span data-i18n="salary">Salary</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="social-tab" data-bs-toggle="tab" data-bs-target="#social-pane" type="button" role="tab" aria-controls="social-pane" aria-selected="false"><i class="fa-solid fa-hospital-user me-1"></i><span data-i18n="social_security">Social Security</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="family-tab" data-bs-toggle="tab" data-bs-target="#family-pane" type="button" role="tab" aria-controls="family-pane" aria-selected="false"><i class="fa-solid fa-people-roof me-1"></i><span data-i18n="family_tax">Family / Tax Allowance</span></button>
        </li>
        <li class="nav-item" role="presentation">
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
                            <i class="fas fa-camera text-secondary fs-5 d-block"></i>
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
            <div class="row">
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
            <div class="row">
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
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="id_card_expire">ID Card Expire Date</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
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
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
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
                    <input type="text" class="form-control required" name="mobile_no" id="mobile_no" maxlength="10">
                </div>
                <div class="col-sm-12 mt-3">
                    <input type="checkbox" class="me-2" name="send_preboarding_email" id="send_preboarding_email"><span data-i18n="send_preboarding">Send preboarding access email.</span>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="line_id">LINE ID</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="line_id" id="line_id">
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="register_address">Register Address</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="address_line_1">Address Line 1</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="address_line_1_register" id="address_line_1_register">
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
                    <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3 position-relative">
                    <input type="text" class="form-control required autocomplete-address" id="search_address_register" autocomplete="off">
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
                    <label class="form-label"><span data-i18n="address_line_1">Address Line 1</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="address_line_1_contact" id="address_line_1_contact">
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
                    <label class="form-label"><span data-i18n="search_address_label">Sub-district / City / Postcode</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3 position-relative">
                    <input type="text" class="form-control required autocomplete-address" id="search_address_contact" autocomplete="off">
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
                    <label class="form-label"><span data-i18n="name">Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="emergency_name" id="emergency_name">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="surname">Surname</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="emergency_surname" id="emergency_surname">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="relationship">Relationship</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="emergency_relationship" id="emergency_relationship">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="mobile_no">Mobile No.</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="emergency_mobile" id="emergency_mobile" maxlength="10">
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
                    <select class="form-select" name="work_location_id" id="work_location_id">
                        <option value="" data-i18n="please_choose">Please choose.</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="shift">Shift</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" name="shift_id" id="shift_id">
                        <option value="" data-i18n="please_choose">Please choose.</option>
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
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="report_to">Report To</span> <span class="text-danger">*</span></label>
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
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="holiday_calendar">Holiday Calendar</span> <span class="text-danger">*</span></label>
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
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="workforce_type">Workforce Type</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="workforce_type" id="workforce_type">
                        <option value="" data-i18n="please_choose">Please choose.</option>
                        <option value="office" data-i18n="office">Office</option>
                        <option value="field" data-i18n="field">Field</option>
                        <option value="remote" data-i18n="remote">Remote</option>
                        <option value="hybrid" data-i18n="hybrid">Hybrid</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="record_time_method">Time Record Method</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select select2-native required" name="record_time_method" id="record_time_method">
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
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="earning_deduction_assignments">Earnings & Deductions</span>
            </h6>
            <p class="text-secondary small ms-5 mb-3" data-i18n="earning_deduction_assignments_hint">*Assign recurring or installment-based items from the payroll master list, such as loan deductions or split bonus payments.</p>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <table class="table table-bordered table-sm align-middle" id="tableEarningDeduction">
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
                        <tbody></tbody>
                    </table>
                    <button type="button" class="btn btn-sm text-white" id="btnAddEarningDeduction" style="background-color:#FF9900;border-color:#FF9900;">
                        <i class="fas fa-plus me-1"></i><span data-i18n="add_item">Add item</span>
                    </button>
                </div>
            </div>
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
        </div>
        <div class="tab-pane fade" id="social-pane" role="tabpanel" aria-labelledby="social-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="social_security_fund">Social Security Fund (SSO)</span>
            </h6>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <input type="checkbox" class="me-2" name="sso_enrolled" id="sso_enrolled"><span data-i18n="enrolled_in_sso">Enrolled in Social Security Fund</span>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="sso_no">Social Security No.</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="sso_no" id="sso_no" maxlength="13">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="sso_hospital">Hospital</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" name="sso_hospital_id" id="sso_hospital_id">
                        <option value="" data-i18n="please_choose">Please choose.</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="sso_start_date">SSO Start Date</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" name="sso_start_date" id="sso_start_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="sso_contribution_rate">Contribution Rate (%)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="number" step="0.01" class="form-control" name="sso_contribution_rate" id="sso_contribution_rate" value="5.00">
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="provident_fund">Provident Fund (PVD)</span>
            </h6>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <input type="checkbox" class="me-2" name="pvd_enrolled" id="pvd_enrolled"><span data-i18n="enrolled_in_pvd">Enrolled in Provident Fund</span>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="pvd_fund_name">Fund Name</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="pvd_fund_name" id="pvd_fund_name">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="pvd_start_date">Start Date</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" name="pvd_start_date" id="pvd_start_date" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="pvd_employee_rate">Employee Rate (%)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="number" step="0.01" class="form-control" name="pvd_employee_rate" id="pvd_employee_rate">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="pvd_employer_rate">Employer Rate (%)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="number" step="0.01" class="form-control" name="pvd_employer_rate" id="pvd_employer_rate">
                </div>
            </div>
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
        <div class="tab-pane fade" id="family-pane" role="tabpanel" aria-labelledby="family-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label>
                <span data-i18n="spouse">Spouse</span>
            </h6>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <input type="checkbox" class="me-2" name="has_spouse" id="has_spouse"><span data-i18n="has_dependent_spouse">Has spouse with no income (eligible for tax allowance)</span>
                </div>
            </div>
            <div class="row">
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
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label>
                <span data-i18n="children_dependents">Children / Dependents</span>
            </h6>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <table class="table table-bordered table-sm" id="tableDependent">
                        <thead>
                            <tr>
                                <th data-i18n="name">Name</th>
                                <th data-i18n="id_card_no" style="width:180px;">ID Card No.</th>
                                <th data-i18n="date_of_birth" style="width:160px;">Date of Birth</th>
                                <th data-i18n="relationship" style="width:150px;">Relationship</th>
                                <th data-i18n="studying" style="width:100px;">Studying</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-brand" id="btnAddDependent">
                        <i class="fas fa-plus me-1"></i><span data-i18n="add_dependent">Add dependent</span>
                    </button>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-5">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label>
                <span data-i18n="parents">Parents (Tax Allowance)</span>
            </h6>
            <div class="row">
                <div class="col-sm-12 mt-3">
                    <table class="table table-bordered table-sm" id="tableParent">
                        <thead>
                            <tr>
                                <th data-i18n="name">Name</th>
                                <th data-i18n="id_card_no" style="width:180px;">ID Card No.</th>
                                <th data-i18n="relationship" style="width:150px;">Relationship</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-brand" id="btnAddParent">
                        <i class="fas fa-plus me-1"></i><span data-i18n="add_parent">Add parent</span>
                    </button>
                </div>
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
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote required" id="eed_ped_type_id" name="ped_type_id" data-api="/api/employee.earning-deduction.options" data-type=""></select>
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
<input type="hidden" id="employee_no" value="<?= htmlspecialchars($employee_no ?? '', ENT_QUOTES, 'UTF-8') ?>">
<script src="<?=asset('public/js/employee/detail.js')?>"></script>