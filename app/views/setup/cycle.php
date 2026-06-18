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