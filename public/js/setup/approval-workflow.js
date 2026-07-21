/**
 * Approval Workflow — drag-sort step editor (phase: UI). The workflow list/CRUD/monitor
 * backend endpoints land in a later phase; this file focuses on the step editor experience:
 * add/remove steps, drag-to-sort (SortableJS), per-step approver (user/role) picking via the
 * existing employee/role Select2 endpoints, and the joint-approve/timeout/escalation fields.
 * `currentSteps` is the source of truth; the DOM is rebuilt from it on structural changes
 * (add/delete/reorder) and updated in place for simple field edits (no rebuild, to avoid
 * losing focus/flicker while typing).
 */
let currentSteps = [];
let stepKeyCounter = 0;
let stepSortableInstance = null;
let tb_approval_workflow;

function newStepKey() {
    stepKeyCounter += 1;
    return 'step_' + stepKeyCounter + '_' + Date.now();
}

function emptyStep() {
    return {
        key: newStepKey(),
        step_name: '',
        approver_type: 'user',
        approver_id: '',
        approver_label: '',
        joint_approve_mode: 'any',
        timeout_hours: '',
        escalation_approver_type: '',
        escalation_approver_id: '',
        escalation_approver_label: ''
    };
}

function findStep(key) {
    return currentSteps.find(s => s.key === key);
}

function approverAjaxOptions(type) {
    return type === 'role'
        ? { mode: 'ajax', api: '/api/role.get', apiType: 'role', allowClear: true }
        : { mode: 'ajax', api: '/api/employee.report_to.get', allowClear: true };
}

function buildStepRowHtml(step, index) {
    return `
        <div class="step-editor-row d-flex align-items-start gap-2 border rounded p-2 mb-2" data-key="${step.key}">
            <div class="step-drag-handle text-secondary pt-2" style="cursor:grab;"><i class="fa-solid fa-grip-vertical"></i></div>
            <div class="step-order-badge badge bg-secondary rounded-pill mt-2" style="min-width:1.75rem;">${index + 1}</div>
            <div class="flex-grow-1">
                <div class="row g-2">
                    <div class="col-sm-4">
                        <input type="text" class="form-control form-control-sm step-name-input" name="step_name" value="${escapeHtmlAw(step.step_name)}" placeholder="${langData['step_name_placeholder'] || 'Step name (optional)'}">
                    </div>
                    <div class="col-sm-3">
                        <select class="form-select form-select-sm approver-type-select select2-static" data-option-keys="approver_type_user,approver_type_role" data-option-values="user,role"></select>
                    </div>
                    <div class="col-sm-5">
                        <select class="form-select form-select-sm approver-select select2-remote required"></select>
                    </div>
                </div>
                <div class="row g-2 mt-1 joint-approve-wrapper ${step.approver_type === 'role' ? '' : 'd-none'}">
                    <div class="col-sm-12">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" value="any" ${step.joint_approve_mode === 'any' ? 'checked' : ''}>
                            <label class="form-check-label small" data-i18n="joint_approve_any">${langData['joint_approve_any'] || 'Any one approver is enough'}</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" value="all" ${step.joint_approve_mode === 'all' ? 'checked' : ''}>
                            <label class="form-check-label small" data-i18n="joint_approve_all">${langData['joint_approve_all'] || 'Every current holder must approve'}</label>
                        </div>
                    </div>
                </div>
                <div class="mt-1">
                    <button type="button" class="btn btn-link btn-sm p-0 toggle-advanced" data-i18n="advanced_settings">${langData['advanced_settings'] || 'Advanced (timeout/escalation)'}</button>
                    <div class="advanced-fields d-none row g-2 mt-2">
                        <div class="col-sm-3">
                            <input type="number" min="1" class="form-control form-control-sm timeout-hours-input" value="${step.timeout_hours}" placeholder="${langData['timeout_hours_placeholder'] || 'Timeout (hours)'}">
                        </div>
                        <div class="col-sm-4">
                            <select class="form-select form-select-sm escalation-type-select select2-static" data-option-keys="approver_type_user,approver_type_role" data-option-values="user,role"></select>
                        </div>
                        <div class="col-sm-5">
                            <select class="form-select form-select-sm escalation-approver-select select2-remote"></select>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger delete-step-btn"><i class="fa-solid fa-trash"></i></button>
        </div>
    `;
}

function escapeHtmlAw(str) {
    // .text()/.html() round-trip escapes <, >, & but NOT quote characters (browsers don't
    // escape quotes inside text-node serialization) — and this value is interpolated into a
    // double-quoted HTML attribute below, so an unescaped " would break out of the attribute.
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

function initStepRowWidgets($row, step) {
    initSelect2($row.find('.approver-type-select'), { mode: 'static', selectedValue: step.approver_type });

    const $approverSelect = $row.find('.approver-select');
    initSelect2($approverSelect, approverAjaxOptions(step.approver_type));
    if (step.approver_id) {
        const opt = new Option(step.approver_label || step.approver_id, step.approver_id, true, true);
        $approverSelect.empty().append(opt).trigger('change.select2');
    }

    initSelect2($row.find('.escalation-type-select'), { mode: 'static', allowClear: true, selectedValue: step.escalation_approver_type });

    const $escSelect = $row.find('.escalation-approver-select');
    initSelect2($escSelect, approverAjaxOptions(step.escalation_approver_type || 'user'));
    if (step.escalation_approver_id) {
        const opt = new Option(step.escalation_approver_label || step.escalation_approver_id, step.escalation_approver_id, true, true);
        $escSelect.empty().append(opt).trigger('change.select2');
    }
    $row.find('.escalation-approver-select').closest('.col-sm-5').toggleClass('d-none', !step.escalation_approver_type);
}

function renderSteps() {
    const $wrap = $('#stepList').empty();
    currentSteps.forEach((step, index) => {
        const $row = $(buildStepRowHtml(step, index));
        $wrap.append($row);
        initStepRowWidgets($row, step);
    });
    $('#noStepsMessage').toggleClass('d-none', currentSteps.length > 0);
    initStepSortable();
}

function initStepSortable() {
    const el = document.getElementById('stepList');
    if (!el) return;
    if (stepSortableInstance) {
        stepSortableInstance.destroy();
        stepSortableInstance = null;
    }
    if (typeof Sortable === 'undefined') return;
    stepSortableInstance = Sortable.create(el, {
        handle: '.step-drag-handle',
        animation: 150,
        onEnd: function () {
            const newOrderKeys = $('#stepList .step-editor-row').map(function () { return $(this).data('key'); }).get();
            currentSteps.sort((a, b) => newOrderKeys.indexOf(a.key) - newOrderKeys.indexOf(b.key));
            renderSteps();
        }
    });
}

function addStepRow() {
    currentSteps.push(emptyStep());
    renderSteps();
}

function deleteStepRow(key) {
    showConfirm(
        langData['confirm_delete_step'] || 'Remove this step?',
        '',
        function () {
            currentSteps = currentSteps.filter(s => s.key !== key);
            renderSteps();
        }
    );
}

function resetWorkflowForm() {
    $('#workflowForm')[0].reset();
    $('#workflow_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    currentSteps = [];
    renderSteps();
    $('#workflow_status').val('active').trigger('change');
    $('#workflow_document_types').val(null).trigger('change');
    $('#workflowModalLabel').html('<i class="fa-solid fa-pen-to-square me-2"></i>' + (langData['approval_workflow'] || 'Approval Workflow'));
}

function validateWorkflowForm() {
    let firstInvalid = null;
    $('#workflowModal .required').each(function () {
        const $el = $(this);
        if ($el.closest('.d-none').length > 0) return;
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    if (currentSteps.length === 0 && !firstInvalid) {
        showWarning(langData['no_steps_yet'] || 'No steps yet — add at least one.');
        return true;
    }
    return firstInvalid;
}

/* ---------- Field-level state sync (event delegation, no full re-render) ---------- */
$(document).on('input', '.step-name-input', function () {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) step.step_name = $(this).val();
});
$(document).on('input', '.timeout-hours-input', function () {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) step.timeout_hours = $(this).val();
});
$(document).on('change', '.joint-mode-radio', function () {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) step.joint_approve_mode = $(this).val();
});
$(document).on('change', '.approver-type-select', function () {
    const $row = $(this).closest('.step-editor-row');
    const key = $row.data('key');
    const step = findStep(key);
    if (!step) return;
    step.approver_type = $(this).val();
    step.approver_id = '';
    step.approver_label = '';
    $row.find('.joint-approve-wrapper').toggleClass('d-none', step.approver_type !== 'role');
    const $approverSelect = $row.find('.approver-select');
    initSelect2($approverSelect, approverAjaxOptions(step.approver_type));
});
$(document).on('select2:select', '.approver-select', function (e) {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) {
        step.approver_id = e.params.data.id;
        step.approver_label = e.params.data.text;
    }
});
$(document).on('select2:clear', '.approver-select', function () {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) { step.approver_id = ''; step.approver_label = ''; }
});
$(document).on('change', '.escalation-type-select', function () {
    const $row = $(this).closest('.step-editor-row');
    const key = $row.data('key');
    const step = findStep(key);
    if (!step) return;
    step.escalation_approver_type = $(this).val();
    step.escalation_approver_id = '';
    step.escalation_approver_label = '';
    const $escSelect = $row.find('.escalation-approver-select');
    $escSelect.closest('.col-sm-5').toggleClass('d-none', !step.escalation_approver_type);
    initSelect2($escSelect, approverAjaxOptions(step.escalation_approver_type || 'user'));
});
$(document).on('select2:select', '.escalation-approver-select', function (e) {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) {
        step.escalation_approver_id = e.params.data.id;
        step.escalation_approver_label = e.params.data.text;
    }
});
$(document).on('select2:clear', '.escalation-approver-select', function () {
    const key = $(this).closest('.step-editor-row').data('key');
    const step = findStep(key);
    if (step) { step.escalation_approver_id = ''; step.escalation_approver_label = ''; }
});
$(document).on('click', '.toggle-advanced', function () {
    $(this).closest('.step-editor-row').find('.advanced-fields').toggleClass('d-none');
});
$(document).on('click', '.delete-step-btn', function () {
    deleteStepRow($(this).closest('.step-editor-row').data('key'));
});
$(document).on('click', '#btnAddStep', addStepRow);

/* ---------- Workflow list ---------- */
function workflowStatusBadge(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function workflowDocumentTypesCell(row) {
    const names = currentLang === 'th' ? row.document_type_names_th : row.document_type_names_en;
    return escapeHtmlAw(names || row.document_type_names_th || row.document_type_names_en || '');
}
function workflowActionButtons(row) {
    const toggleIcon = row.status === 'active' ? 'fa-toggle-on' : 'fa-toggle-off';
    const toggleTitle = row.status === 'active' ? (langData['deactivate'] || 'Deactivate') : (langData['activate'] || 'Activate');
    return `<div class="d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-workflow" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-duplicate-workflow" data-id="${row.id}" title="${langData['duplicate'] || 'Duplicate'}"><i class="fas fa-copy"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-toggle-workflow" data-id="${row.id}" data-status="${row.status}" title="${toggleTitle}"><i class="fa-solid ${toggleIcon}"></i></button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-workflow" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
function initApprovalWorkflowTable() {
    if ($.fn.DataTable.isDataTable('#tb_approval_workflow')) {
        $('#tb_approval_workflow').DataTable().ajax.reload(null, false);
        return;
    }
    tb_approval_workflow = $('#tb_approval_workflow').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/approval-workflow.list`,
            dataSrc: 'data'
        },
        columns: [
            { data: 'workflow_name', render: d => `<strong class="text-dark">${escapeHtmlAw(d)}</strong>` },
            { data: null, render: (d, t, row) => workflowDocumentTypesCell(row) },
            { data: 'step_count' },
            { data: 'status', render: d => workflowStatusBadge(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => workflowActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-workflow').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-workflow">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_workflow">${langData['add_workflow'] || 'Add Workflow'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
}

function populateWorkflowForm(row) {
    $('#workflow_id').val(row.id);
    $('#workflow_name').val(row.workflow_name);
    $('#workflow_description').val(row.description || '');
    $('#workflow_status').val(row.status).trigger('change');

    const $docTypes = $('#workflow_document_types');
    $docTypes.empty();
    (row.document_types || []).forEach(dt => {
        const label = (currentLang === 'th' ? dt.name_th : dt.name_en) || dt.name_th || dt.name_en || dt.code;
        $docTypes.append(new Option(label, dt.code, true, true));
    });
    $docTypes.trigger('change');

    currentSteps = (row.steps || []).map(s => ({
        key: newStepKey(),
        step_name: s.step_name || '',
        approver_type: s.approver_type,
        approver_id: String(s.approver_id),
        approver_label: (currentLang === 'th' ? s.approver_label_th : s.approver_label_en) || s.approver_label_th || s.approver_label_en || '',
        joint_approve_mode: s.joint_approve_mode,
        timeout_hours: s.timeout_hours || '',
        escalation_approver_type: s.escalation_approver_type || '',
        escalation_approver_id: s.escalation_approver_id ? String(s.escalation_approver_id) : '',
        escalation_approver_label: (currentLang === 'th' ? s.escalation_approver_label_th : s.escalation_approver_label_en) || s.escalation_approver_label_th || s.escalation_approver_label_en || ''
    }));
    renderSteps();
    $('#workflowModalLabel').html('<i class="fa-solid fa-pen-to-square me-2"></i>' + (langData['approval_workflow'] || 'Approval Workflow'));
}

$(document).on('click', '.btn-add-workflow', function () {
    resetWorkflowForm();
    new bootstrap.Modal(document.getElementById('workflowModal')).show();
});

$(document).on('click', '.btn-edit-workflow', function () {
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.get`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                resetWorkflowForm();
                populateWorkflowForm(res.data);
                new bootstrap.Modal(document.getElementById('workflowModal')).show();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
});

$(document).on('click', '.btn-duplicate-workflow', function () {
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.duplicate`,
        method: 'POST',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Success.');
                tb_approval_workflow.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});

$(document).on('click', '.btn-toggle-workflow', function () {
    const id = $(this).data('id');
    const newStatus = $(this).data('status') === 'active' ? 'inactive' : 'active';
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.toggle-status`,
        method: 'POST',
        data: { id, status: newStatus },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                tb_approval_workflow.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});

$(document).on('click', '.btn-delete-workflow', function () {
    const id = $(this).data('id');
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/approval-workflow.delete`,
            method: 'POST',
            data: { id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                    tb_approval_workflow.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'An error occurred.');
                }
            }
        });
    });
});

$(document).on('submit', '#workflowForm', function (e) {
    e.preventDefault();
    const firstInvalid = validateWorkflowForm();
    if (firstInvalid === true) {
        return;
    }
    if (firstInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: $('#workflow_id').val() || undefined,
        workflow_name: $('#workflow_name').val().trim(),
        description: $('#workflow_description').val().trim(),
        status: $('#workflow_status').val(),
        document_type_codes: $('#workflow_document_types').val() || [],
        steps: currentSteps.map(s => ({
            step_name: s.step_name,
            approver_type: s.approver_type,
            approver_id: s.approver_id,
            joint_approve_mode: s.joint_approve_mode,
            timeout_hours: s.timeout_hours,
            escalation_approver_type: s.escalation_approver_type,
            escalation_approver_id: s.escalation_approver_id
        }))
    };
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('workflowModal')).hide();
                tb_approval_workflow.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

/* ---------- Approval Monitor ---------- */
let tb_approval_monitor;
let currentDetailRequestId = null;

function requestStatusBadge(status) {
    const map = {
        pending: { cls: 'bg-warning-subtle text-warning', key: 'status_pending', fallback: 'Pending' },
        approved: { cls: 'bg-success-subtle text-success', key: 'status_approved', fallback: 'Approved' },
        rejected: { cls: 'bg-danger-subtle text-danger', key: 'status_rejected', fallback: 'Rejected' },
        cancelled: { cls: 'bg-secondary-subtle text-secondary', key: 'cancelled', fallback: 'Cancelled' }
    };
    const m = map[status] || { cls: 'bg-secondary-subtle text-secondary', key: '', fallback: status };
    return `<span class="badge ${m.cls}">${langData[m.key] || m.fallback}</span>`;
}

function initApprovalMonitorTable() {
    if ($.fn.DataTable.isDataTable('#tb_approval_monitor')) {
        $('#tb_approval_monitor').DataTable().ajax.reload(null, false);
        return;
    }
    tb_approval_monitor = $('#tb_approval_monitor').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/approval-request.list`,
            dataSrc: 'data',
            data: function (d) {
                d.status = $('#monitor_filter_status').val() || '';
                d.document_type_code = $('#monitor_filter_document_type').val() || '';
            }
        },
        columns: [
            { data: null, render: (d, t, row) => escapeHtmlAw((currentLang === 'th' ? row.document_type_name_th : row.document_type_name_en) || row.document_type_name_th || row.document_type_name_en || '') },
            { data: 'reference_label', render: d => escapeHtmlAw(d || '-') },
            { data: 'workflow_name', render: d => escapeHtmlAw(d) },
            { data: 'current_step_name', render: d => escapeHtmlAw(d || '-') },
            { data: 'status', render: d => requestStatusBadge(d) },
            { data: null, render: (d, t, row) => escapeHtmlAw((currentLang === 'th' ? row.requested_by_name_th : row.requested_by_name_en) || row.requested_by_name_th || row.requested_by_name_en || '-') },
            { data: 'requested_at' },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => `<button type="button" class="btn btn-sm btn-outline-secondary btn-view-request" data-id="${row.id}"><i class="fa-solid fa-eye"></i></button>`
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}

function renderRequestSummary(req) {
    const requesterName = (currentLang === 'th' ? req.requested_by_name_th : req.requested_by_name_en) || req.requested_by_name_th || req.requested_by_name_en || '-';
    const docTypeName = (currentLang === 'th' ? req.document_type_name_th : req.document_type_name_en) || req.document_type_name_th || req.document_type_name_en || '';
    $('#requestDetailSummary').html(`
        <div class="row g-2 small">
            <div class="col-sm-6"><strong>${langData['document_types'] || 'Document Type'}:</strong> ${escapeHtmlAw(docTypeName)}</div>
            <div class="col-sm-6"><strong>${langData['reference'] || 'Reference'}:</strong> ${escapeHtmlAw(req.reference_label || '-')}</div>
            <div class="col-sm-6"><strong>${langData['workflow_name'] || 'Workflow'}:</strong> ${escapeHtmlAw(req.workflow_name)}</div>
            <div class="col-sm-6"><strong>${langData['status'] || 'Status'}:</strong> ${requestStatusBadge(req.status)}</div>
            <div class="col-sm-6"><strong>${langData['requested_by'] || 'Requested By'}:</strong> ${escapeHtmlAw(requesterName)}</div>
            <div class="col-sm-6"><strong>${langData['requested_at'] || 'Requested At'}:</strong> ${escapeHtmlAw(req.requested_at)}</div>
        </div>
    `);
}

function renderRequestTimeline(logs) {
    const $wrap = $('#requestDetailTimeline').empty();
    if (logs.length === 0) {
        $wrap.append(`<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`);
        return;
    }
    logs.forEach(l => {
        const actorName = (currentLang === 'th' ? l.acted_by_name_th : l.acted_by_name_en) || l.acted_by_name_th || l.acted_by_name_en || '-';
        const actionKey = { approve: 'approve', reject: 'reject', cancel: 'cancel_request' }[l.action] || l.action;
        $wrap.append(`
            <div class="border-start ps-3 pb-3" style="border-color:#dee2e6 !important;">
                <div class="small text-secondary">${escapeHtmlAw(l.acted_at)}</div>
                <div><strong>${escapeHtmlAw(l.step_name_snapshot || '')}</strong> — ${langData[actionKey] || l.action} (${escapeHtmlAw(actorName)})</div>
                ${l.note ? `<div class="small text-secondary">${escapeHtmlAw(l.note)}</div>` : ''}
            </div>
        `);
    });
}

function openRequestDetail(id) {
    currentDetailRequestId = id;
    $.ajax({
        url: `${BASE_URL}/api/approval-request.get`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            renderRequestSummary(res.data);
            $('#requestActionArea').toggleClass('d-none', res.data.status !== 'pending');
            $('#requestActionNote').val('');
            new bootstrap.Modal(document.getElementById('requestDetailModal')).show();
        }
    });
    $.ajax({
        url: `${BASE_URL}/api/approval-request.logs`,
        method: 'GET',
        data: { request_id: id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                renderRequestTimeline(res.data);
            }
        }
    });
}

function actOnCurrentRequest(action) {
    if (!currentDetailRequestId) return;
    $.ajax({
        url: `${BASE_URL}/api/approval-request.act`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ request_id: currentDetailRequestId, action, note: $('#requestActionNote').val().trim() }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Success.');
                openRequestDetail(currentDetailRequestId);
                if (tb_approval_monitor) tb_approval_monitor.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred.'); }
    });
}

$(document).on('click', '.btn-view-request', function () {
    openRequestDetail($(this).data('id'));
});
$(document).on('click', '#btnApproveRequest', function () {
    actOnCurrentRequest('approve');
});
$(document).on('click', '#btnRejectRequest', function () {
    showConfirm(langData['confirm_reject_request'] || 'Reject this request?', '', function () {
        actOnCurrentRequest('reject');
    });
});
$(document).on('click', '#btnCancelRequest', function () {
    showConfirm(langData['confirm_cancel_request'] || 'Cancel this request?', '', function () {
        actOnCurrentRequest('cancel');
    });
});
$(document).on('change', '#monitor_filter_status, #monitor_filter_document_type', function () {
    if (tb_approval_monitor) tb_approval_monitor.ajax.reload(null, true);
});

$(document).ready(function () {
    initApprovalWorkflowTable();
    if (typeof initSelect2 === 'function') {
        initSelect2('#workflow_status', { mode: 'static' });
        initSelect2('#workflow_document_types', { mode: 'ajax', allowClear: true });
        initSelect2('#monitor_filter_status', { mode: 'static', allowClear: true });
        initSelect2('#monitor_filter_document_type', { mode: 'ajax', allowClear: true });
    }
    $('#approvalMonitorTabBtn').on('shown.bs.tab', function () {
        initApprovalMonitorTable();
    });
});
