<div class="container container-body">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
        <span class="bc-root">Payroll</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-parent" data-i18n="settings">Settings</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-current" data-i18n="earnings_deductions">Earnings & Deductions</span>
    </h5>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="text-secondary fw-bold m-0">
                <i class="fa-solid fa-calculator me-2"></i>
                <span data-i18n="earning_deduction_management">Earnings & Deductions Management</span>
            </h5>
            <p class="text-muted small m-0 mt-1">กำหนดและจัดการประเภทรายรับ (Earnings) และรายหัก (Deductions) เพื่อนำไปคำนวณในสลิปเงินเดือน</p>
        </div>
        <button type="button" class="btn btn-warning text-white px-3 d-flex align-items-center gap-2" 
                style="background-color: #ff9900; border-color: #ff9900;"
                data-bs-toggle="modal" data-bs-target="#itemModal" onclick="resetItemForm()">
            <i class="fas fa-plus"></i> <span data-i18n="add_new_item">Add New Item</span>
        </button>
    </div>
    <ul class="nav nav-tabs mb-4 border-bottom" id="payrollItemsTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active px-4 py-2" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings-pane" type="button" role="tab" aria-controls="earnings-pane" aria-selected="true">
                <i class="fa-solid fa-arrow-trend-up me-2 text-success"></i><span data-i18n="tab_earnings">Earnings (รายรับ)</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions-pane" type="button" role="tab" aria-controls="deductions-pane" aria-selected="false">
                <i class="fa-solid fa-arrow-trend-down me-2 text-danger"></i><span data-i18n="tab_deductions">Deductions (รายหัก)</span>
            </button>
        </li>
    </ul>
    <div class="tab-content" id="payrollItemsTabContent">
        <div class="tab-pane fade show active" id="earnings-pane" role="tabpanel" aria-labelledby="earnings-tab" tabindex="0">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="earningsTable">
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
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="deductionsTable">
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