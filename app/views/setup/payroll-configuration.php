<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_cycle">Payroll Cycle</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-calendar-day me-2"></i>
            <span data-i18n="company_management_title">Payroll Cycle Management</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="company_management_description">Define and manage employee payroll cycles, with the flexibility to categorize by employee groups or employment types.</p>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="cycle-tab" data-bs-toggle="tab" data-bs-target="#cycle-pane" type="button" role="tab" aria-controls="cycle-pane" aria-selected="true">
                <i class="fa-regular fa-calendar-days me-2"></i><span data-i18n="cycle">Cycle</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings-pane" type="button" role="tab" aria-controls="earnings-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-day me-2"></i><span data-i18n="earnings">Earnings</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions-pane" type="button" role="tab" aria-controls="deductions-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="deductions">Deductions</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="cycle-pane" role="tabpanel" aria-labelledby="cycle-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <button type="button" class="btn btn-warning text-white px-3 d-flex align-items-center gap-2" style="background-color: #ff9900; border-color: #ff9900;" data-bs-toggle="modal" data-bs-target="#payrollCycleModal" onclick="resetForm()"><i class="fas fa-plus"></i> <span data-i18n="add_cycle">Add Payroll Cycle</span></button>
                <div class="mt-5 mb-5">
                    <table class="table table-hover table-border align-middle w-100" id="payrollCycleTable">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th scope="col" style="width: 25%;" data-i18n="table_cycle_name">Cycle Name</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_frequency">Frequency</th>
                                <th scope="col" style="width: 20%;" data-i18n="table_cutoff">Attendance Cut-off</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_payment_day">Payment Day</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_bank_format">Bank Format</th>
                                <th scope="col" style="width: 10%; text-align: center;" data-i18n="table_actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="cycle-row-1">
                                <td>
                                    <strong class="text-dark">Office Staff Cycle</strong>
                                    <div class="text-muted small">พนักงานประจำสำนักงาน</div>
                                </td>
                                <td><span class="badge bg-primary-subtle text-primary px-2 py-1">Monthly</span></td>
                                <td>Every 25th of the month</td>
                                <td>Every 30th of the month</td>
                                <td>KBANK_SMART</td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editCycle(1)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCycle(1)" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr id="cycle-row-2">
                                <td>
                                    <strong class="text-dark">Part-time / Subcontract</strong>
                                    <div class="text-muted small">พนักงานรายสัปดาห์ / คลังสินค้า</div>
                                </td>
                                <td><span class="badge bg-info-subtle text-info px-2 py-1">Weekly</span></td>
                                <td>Every Friday</td>
                                <td>Every Monday</td>
                                <td>SCB</td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editCycle(2)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCycle(2)" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal fade" id="payrollCycleModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payrollCycleModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header border-bottom-0 pt-4 px-4">
                                <h5 class="modal-title fw-bold text-secondary" id="payrollCycleModalLabel">
                                    <span data-i18n="modal_title_add">Create New Payroll Cycle</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form id="payrollCycleForm" novalidate>
                                <input type="hidden" name="cycle_id" id="cycle_id">
                                <div class="modal-body px-4 py-0">
                                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0" style="background-color: #ff9900;">1</label> 
                                        <span data-i18n="modal_sec_general">Cycle Information</span>
                                    </h6>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_cycle_name">Cycle Name</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <input type="text" class="form-control" id="cycle_name" name="cycle_name" required placeholder="e.g., Office Staff Cycle / Part-time Weekly">
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_frequency">Payroll Frequency</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select" id="payroll_frequency" name="payroll_frequency" required>
                                                <option value="">-- Select Frequency --</option>
                                                <option value="monthly">Monthly (รายเดือน)</option>
                                                <option value="semi_monthly">Semi-Monthly (รายปักษ์)</option>
                                                <option value="weekly">Weekly (รายสัปดาห์)</option>
                                                <option value="bi_weekly">Bi-Weekly (ราย 2 สัปดาห์)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <hr class="my-4 text-muted opacity-25">
                                    <h6 class="text-secondary fw-bold mb-3">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0" style="background-color: #ff9900;">2</label> 
                                        <span data-i18n="modal_sec_dates">Cut-off & Payment Settings</span>
                                    </h6>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_attendance_cutoff">Attendance Cut-off</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select" id="attendance_cutoff_day" name="attendance_cutoff_day" required>
                                                <option value="">-- Select Day --</option>
                                                <option value="20">Every 20th of the month</option>
                                                <option value="25">Every 25th of the month</option>
                                                <option value="last_day">Last day of the month</option>
                                                <option value="friday">Every Friday (สำหรับรายสัปดาห์)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_payment_day">Payment Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select" id="payroll_payment_day" name="payroll_payment_day" required>
                                                <option value="">-- Select Day --</option>
                                                <option value="25">Every 25th of the month</option>
                                                <option value="30">Every 30th of the month</option>
                                                <option value="last_day">Last day of the month</option>
                                                <option value="monday">Every Monday (สำหรับรายสัปดาห์)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3">
                                            <label class="form-label pt-1"><span data-i18n="modal_ot_cutoff">OT Cut-off Type</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <div class="form-check form-check-inline mt-1">
                                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_same" value="same" checked>
                                                <label class="form-check-label" for="ot_same">Same as Attendance</label>
                                            </div>
                                            <div class="form-check form-check-inline mt-1">
                                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_custom" value="custom">
                                                <label class="form-check-label" for="ot_custom">Custom Definition</label>
                                            </div>
                                        </div>
                                    </div>
                                    <hr class="my-4 text-muted opacity-25">
                                    <h6 class="text-secondary fw-bold mb-3">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0" style="background-color: #ff9900;">3</label> 
                                        <span data-i18n="modal_sec_bank">Bank File Configuration</span>
                                    </h6>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_bank_format">Bank Text Format</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select" id="bank_file_format" name="bank_file_format" required>
                                                <option value="">-- Select Bank File Format --</option>
                                                <option value="KBANK_SMART">Kasikorn Bank (K-Smart)</option>
                                                <option value="SCB">Siam Commercial Bank (SCB Business Net)</option>
                                                <option value="BBL">Bangkok Bank (iBIZ)</option>
                                                <option value="DBS_IDEAL">DBS IDEAL (Singapore)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer border-top-0 px-4 pb-4 pt-3">
                                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_settings">Save Cycle</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <script>
                    let cycleDataTable;
                    $(document).ready(function() {
                        cycleDataTable = $('#payrollCycleTable').DataTable({
                            "paging": true,
                            "lengthChange": true,
                            "searching": true,
                            "ordering": true,
                            "info": true,
                            "autoWidth": false,
                            "responsive": true,
                            "pageLength": 10,
                            "columnDefs": [
                                { "orderable": false, "targets": 5 } 
                            ],
                            "language": {
                                "search": "Search:",
                                "lengthMenu": "Display _MENU_ records per page",
                                "zeroRecords": "No matching records found",
                                "infoEmpty": "No records available",
                                "infoFiltered": "(filtered from _MAX_ total records)"
                            }
                        });
                    });
                    function resetForm() {
                        document.getElementById('payrollCycleForm').reset();
                        document.getElementById('cycle_id').value = '';
                        document.getElementById('payrollCycleModalLabel').innerText = 'Create New Payroll Cycle';
                    }
                    function editCycle(id) {
                        resetForm();
                        document.getElementById('payrollCycleModalLabel').innerText = 'Edit Payroll Cycle';
                        document.getElementById('cycle_id').value = id;
                        if (id === 1) {
                            document.getElementById('cycle_name').value = 'Office Staff Cycle';
                            document.getElementById('payroll_frequency').value = 'monthly';
                            document.getElementById('attendance_cutoff_day').value = '25';
                            document.getElementById('payroll_payment_day').value = '30';
                            document.getElementById('bank_file_format').value = 'KBANK_SMART';
                        } else if (id === 2) {
                            document.getElementById('cycle_name').value = 'Part-time / Subcontract';
                            document.getElementById('payroll_frequency').value = 'weekly';
                            document.getElementById('attendance_cutoff_day').value = 'friday';
                            document.getElementById('payroll_payment_day').value = 'monday';
                            document.getElementById('bank_file_format').value = 'SCB';
                        }
                        var myModal = new bootstrap.Modal(document.getElementById('payrollCycleModal'));
                        myModal.show();
                    }
                    function deleteCycle(id) {
                        if (confirm("Are you sure you want to delete this payroll cycle? This action cannot be undone.")) {
                            const rowElement = document.getElementById(`cycle-row-${id}`);
                            if (rowElement) {
                                cycleDataTable.row($(rowElement)).remove().draw(false);
                            }
                        }
                    }
                </script>
            </div>
        </div>
        <div class="tab-pane fade" id="earnings-pane" role="tabpanel" aria-labelledby="earnings-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="earningsTable">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 15%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 25%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_tax_type">Tax Treatment</th>
                            <th scope="col" style="width: 15%;" data-i18n="col_sso">SSO Cal</th>
                            <th scope="col" style="width: 15%;" data-i18n="col_pf">Provident Fund</th>
                            <th scope="col" style="width: 10%; text-align: center;" data-i18n="col_actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="earning-row-1">
                            <td><code class="fw-bold text-dark">E001</code></td>
                            <td><div><strong>Incentive</strong></div><div class="text-muted small">เงินจูงใจพิเศษ</div></td>
                            <td><span class="badge bg-success-subtle text-success">Taxable (คำนวณภาษี)</span></td>
                            <td><i class="fa-solid fa-circle-check text-success fs-5"></i></td>
                            <td><i class="fa-solid fa-circle-xmark text-muted fs-5"></i></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editItem('earning', 1)"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem('earning', 1)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr id="earning-row-2">
                            <td><code class="fw-bold text-dark">E002</code></td>
                            <td><div><strong>Meal Allowance</strong></div><div class="text-muted small">ค่าอาหาร</div></td>
                            <td><span class="badge bg-secondary-subtle text-secondary">Non-Taxable (ยกเว้นภาษี)</span></td>
                            <td><i class="fa-solid fa-circle-xmark text-muted fs-5"></i></td>
                            <td><i class="fa-solid fa-circle-xmark text-muted fs-5"></i></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editItem('earning', 2)"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem('earning', 2)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="deductions-pane" role="tabpanel" aria-labelledby="deductions-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="deductionsTable">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 15%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 30%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 25%;" data-i18n="col_deduct_type">Tax Deduction Impact</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_cycles">Linked Cycles</th>
                            <th scope="col" style="width: 10%; text-align: center;" data-i18n="col_actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="deduction-row-1">
                            <td><code class="fw-bold text-dark">D001</code></td>
                            <td><div><strong>Unpaid Leave</strong></div><div class="text-muted small">หักมาสาย / ขาดงาน</div></td>
                            <td><span class="badge bg-danger-subtle text-danger">Reduce Gross Income (หักก่อนภาษี)</span></td>
                            <td><span class="badge bg-light text-dark border">All Cycles</span></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editItem('deduction', 1)"><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem('deduction', 1)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="itemModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="itemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-secondary" id="itemModalLabel">
                    <span data-i18n="modal_add_title">Add Payroll Config Item</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="itemForm" novalidate>
                <input type="hidden" id="item_id" name="item_id"> 
                <div class="modal-body px-4 py-0">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head rounded-2 text-white px-2 py-0" style="background-color: #ff9900;">1</label> 
                        <span data-i18n="sec_general_info">General Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0">Item Type <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="item_type" id="type_earning" value="earning" checked onchange="toggleFormFields()">
                                <label class="form-check-label" for="type_earning">Earnings (รายรับ)</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="item_type" id="type_deduction" value="deduction" onchange="toggleFormFields()">
                                <label class="form-check-label" for="type_deduction">Deductions (รายหัก)</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0">Code / รหัสรายการ <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="text" class="form-control" id="item_code" name="item_code" required placeholder="e.g., E003 / D002">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0">Item Name (EN) <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="item_name_en" name="item_name_en" required placeholder="English name">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0">ชื่อรายการ (TH) <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="item_name_th" name="item_name_th" required placeholder="ชื่อภาษาไทย">
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head rounded-2 text-white px-2 py-0" style="background-color: #ff9900;">2</label> 
                        <span data-i18n="sec_calculation_rules">Calculation & Legal Settings</span>
                    </h6>
                    <div id="earnings_fields_wrapper">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0">Tax Treatment</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select" id="tax_treatment" name="tax_treatment">
                                    <option value="taxable">Taxable (นำไปคำนวated ภาษีปกติ)</option>
                                    <option value="non_taxable">Non-Taxable (ได้รับการยกเว้นภาษี)</option>
                                    <option value="one_time_tax">One-time Tax (คำนวณภาษีแบบจ่ายครั้งเดียว)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3">
                                <label class="form-label pt-1">Statutory Calculations</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="calc_sso" name="calc_sso" value="1">
                                    <label class="form-check-label" for="calc_sso">คำนวณเงินสมทบประกันสังคม (SSO)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="calc_pf" name="calc_pf" value="1">
                                    <label class="form-check-label" for="calc_pf">คำนวณกองทุนสำรองเลี้ยงชีพ (Provident Fund)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="deductions_fields_wrapper" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0">Tax Deduction Impact</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select" id="tax_deduct_impact" name="tax_deduct_impact">
                                    <option value="before_tax">Reduce Gross Income (หักก่อนคำนวณภาษี ทำให้ภาษีลดลง)</option>
                                    <option value="after_tax">Net Deduction (หักหลังคำนวณภาษี/หักจากยอดสุทธิ)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 px-4 pb-4 pt-3">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    let earningsDataTable;
    let deductionsDataTable;
    $(document).ready(function() {
        earningsDataTable = $('#earningsTable').DataTable({
            "responsive": true,
            "autoWidth": false,
            "columnDefs": [{ "orderable": false, "targets": 5 }]
        });
        deductionsDataTable = $('#deductionsTable').DataTable({
            "responsive": true,
            "autoWidth": false,
            "columnDefs": [{ "orderable": false, "targets": 4 }]
        });
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        });
    });
    function toggleFormFields() {
        if (document.getElementById('type_earning').checked) {
            document.getElementById('earnings_fields_wrapper').style.display = 'block';
            document.getElementById('deductions_fields_wrapper').style.display = 'none';
        } else {
            document.getElementById('earnings_fields_wrapper').style.display = 'none';
            document.getElementById('deductions_fields_wrapper').style.display = 'block';
        }
    }
    function resetItemForm() {
        document.getElementById('itemForm').reset();
        document.getElementById('item_id').value = '';
        document.getElementById('itemModalLabel').innerText = 'Add Payroll Config Item';
        document.getElementById('type_earning').disabled = false;
        document.getElementById('type_deduction').disabled = false;
        toggleFormFields();
    }
    function editItem(type, id) {
        resetItemForm();
        document.getElementById('itemModalLabel').innerText = 'Edit Payroll Config Item';
        document.getElementById('item_id').value = id;
        document.getElementById('type_earning').disabled = true;
        document.getElementById('type_deduction').disabled = true;
        if (type === 'earning') {
            document.getElementById('type_earning').checked = true;
            if (id === 1) {
                document.getElementById('item_code').value = 'E001';
                document.getElementById('item_name_en').value = 'Incentive';
                document.getElementById('item_name_th').value = 'เงินจูงใจพิเศษ';
                document.getElementById('tax_treatment').value = 'taxable';
                document.getElementById('calc_sso').checked = true;
                document.getElementById('calc_pf').checked = false;
            }
        } else if (type === 'deduction') {
            document.getElementById('type_deduction').checked = true;
            if (id === 1) {
                document.getElementById('item_code').value = 'D001';
                document.getElementById('item_name_en').value = 'Unpaid Leave';
                document.getElementById('item_name_th').value = 'หักมาสาย / ขาดงาน';
                document.getElementById('tax_deduct_impact').value = 'before_tax';
            }
        }
        toggleFormFields();
        var myModal = new bootstrap.Modal(document.getElementById('itemModal'));
        myModal.show();
    }
    function deleteItem(type, id) {
        if (confirm("Are you sure you want to delete this config item?")) {
            if (type === 'earning') {
                const row = document.getElementById(`earning-row-${id}`);
                if (row) earningsDataTable.row($(row)).remove().draw(false);
            } else {
                const row = document.getElementById(`deduction-row-${id}`);
                if (row) deductionsDataTable.row($(row)).remove().draw(false);
            }
        }
    }
</script>