/**
 * Approval Workflow — drag-sort step editor. Add/remove steps, drag-to-sort (SortableJS),
 * per-step APPROVER LIST (2026-08-23: a step used to hold a single user-or-role approver;
 * changed to a repeatable list so one step can name multiple people, per explicit request:
 * "การอนุมัติใน 1 รายการสามารถมีได้มากกว่า 1 แถว และในแต่ละแถวย่อยก็สามารถใส่ได้หลายคน") picked via
 * the existing employee/role Select2 endpoints, and the joint-approve field.
 *
 * 2026-08-23, second change the same day: added per-step GATING (`requires_previous_step` --
 * freely toggle whether a step must wait its turn behind earlier gated steps, independent of the
 * others: e.g. step 1 gates step 2, but step 3 can be approved anytime) and per-step GROUP TYPE
 * (`group_type`: and/or/finish, ported from origami's AND/OR/Finish semantics -- see
 * ApprovalRequestModel::recomputeVerdict() for exactly how these combine into the overall
 * verdict). The old "Advanced (timeout/escalation)" section was dropped entirely at the same time
 * (explicit request -- those fields were config-only and never actually used by any engine).
 *
 * `currentSteps` is the source of truth (each step carries an `approvers` array plus
 * group_type/requires_previous_step); the DOM is rebuilt from it on structural changes (add/
 * delete/reorder) and updated in place for simple field edits (no rebuild, to avoid losing focus/
 * flicker while typing).
 */
let currentSteps = [];
let stepKeyCounter = 0;
let approverKeyCounter = 0;
let stepSortableInstance = null;
let tb_approval_workflow;

function newStepKey() {
    stepKeyCounter += 1;
    return 'step_' + stepKeyCounter + '_' + Date.now();
}

function newApproverKey() {
    approverKeyCounter += 1;
    return 'apv_' + approverKeyCounter + '_' + Date.now();
}

function emptyApprover() {
    return { key: newApproverKey(), approver_type: 'user', approver_id: '', approver_label: '' };
}

function emptyStep() {
    return {
        key: newStepKey(),
        step_name: '',
        approvers: [emptyApprover()],
        joint_approve_mode: 'any',
        group_type: 'and',
        requires_previous_step: false
    };
}

function findStep(key) {
    return currentSteps.find(s => s.key === key);
}

function findApprover(stepKey, approverKey) {
    const step = findStep(stepKey);
    if (!step) return null;
    return step.approvers.find(a => a.key === approverKey) || null;
}

function approverAjaxOptions(type) {
    return type === 'role'
        ? { mode: 'ajax', api: '/api/role.get', apiType: 'role', allowClear: true }
        : { mode: 'ajax', api: '/api/employee.report_to.get', allowClear: true };
}

function buildApproverRowHtml(step, approver) {
    return `
        <div class="awf-approver-chip" data-approver-key="${approver.key}">
            <span class="awf-approver-chip-icon approver-type-icon"><i class="fa-solid ${approver.approver_type === 'role' ? 'fa-user-group' : 'fa-user'}"></i></span>
            <select class="form-select form-select-sm approver-type-select select2-static" style="max-width:150px;" data-option-keys="approver_type_user,approver_type_role" data-option-values="user,role"></select>
            <select class="form-select form-select-sm approver-select select2-remote required flex-grow-1"></select>
            ${step.approvers.length > 1 ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 px-1 delete-approver-btn"><i class="fa-solid fa-xmark"></i></button>` : ''}
        </div>
    `;
}

const GROUP_TYPE_META = {
    and: { key: 'group_type_and', fallback: 'AND — must approve', icon: 'fa-link' },
    or: { key: 'group_type_or', fallback: 'OR — one is enough', icon: 'fa-code-fork' },
    finish: { key: 'group_type_finish', fallback: 'Finish — decides everything', icon: 'fa-flag-checkered' }
};

function groupTypeToggleHtml(step) {
    return Object.keys(GROUP_TYPE_META).map(gt => {
        const meta = GROUP_TYPE_META[gt];
        const active = step.group_type === gt;
        return `<button type="button" class="btn btn-outline-secondary group-type-btn ${active ? 'active-group' : ''}" data-group-type="${gt}"><i class="fa-solid ${meta.icon} me-1"></i>${langData[meta.key] || meta.fallback}</button>`;
    }).join('');
}

function buildStepRowHtml(step, index) {
    const approversHtml = step.approvers.map(a => buildApproverRowHtml(step, a)).join('');
    return `
        <div class="awf-step-card" data-key="${step.key}">
            <div class="awf-step-marker">
                <div class="awf-step-badge">${index + 1}</div>
                <div class="awf-step-connector"></div>
            </div>
            <div class="awf-step-drag" title="${langData['drag_to_reorder'] || 'Drag to reorder'}"><i class="fa-solid fa-grip-vertical"></i></div>
            <div class="awf-step-body">
                <input type="text" class="form-control form-control-sm awf-step-name-input step-name-input mb-2" name="step_name" value="${escapeHtmlAw(step.step_name)}" placeholder="${langData['step_name_placeholder'] || 'Step name (optional)'}">

                <div class="step-approvers-list">${approversHtml}</div>
                <button type="button" class="btn btn-link btn-sm p-0 mb-1 awf-add-approver-btn btn-add-approver"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_approver">${langData['add_approver'] || 'Approver'}</span></button>

                <div class="d-flex flex-wrap align-items-center gap-3 small mt-1">
                    <div class="form-check form-check-inline m-0">
                        <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" id="jointany_${step.key}" value="any" ${step.joint_approve_mode === 'any' ? 'checked' : ''}>
                        <label class="form-check-label" for="jointany_${step.key}" data-i18n="joint_approve_any">${langData['joint_approve_any'] || 'Any one approver is enough'}</label>
                    </div>
                    <div class="form-check form-check-inline m-0">
                        <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" id="jointall_${step.key}" value="all" ${step.joint_approve_mode === 'all' ? 'checked' : ''}>
                        <label class="form-check-label" for="jointall_${step.key}" data-i18n="joint_approve_all">${langData['joint_approve_all'] || 'Every eligible person must approve'}</label>
                    </div>
                </div>

                <hr class="awf-step-divider">

                <div class="awf-step-footer">
                    <div class="btn-group btn-group-sm awf-group-toggle" role="group">${groupTypeToggleHtml(step)}</div>
                    <div class="form-check form-switch m-0 awf-seq-switch">
                        <input type="checkbox" class="form-check-input requires-previous-checkbox" id="reqprev_${step.key}" ${step.requires_previous_step ? 'checked' : ''}>
                        <label class="form-check-label small" for="reqprev_${step.key}" data-i18n="requires_previous_step"><i class="fa-solid fa-link me-1"></i>${langData['requires_previous_step'] || 'Wait for earlier sequenced steps'}</label>
                    </div>
                </div>
            </div>
            <button type="button" class="awf-step-remove delete-step-btn" title="${langData['confirm_delete_step'] || 'Remove this step?'}"><i class="fa-solid fa-trash"></i></button>
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
    step.approvers.forEach(approver => {
        const $approverRow = $row.find(`.awf-approver-chip[data-approver-key="${approver.key}"]`);
        initSelect2($approverRow.find('.approver-type-select'), { mode: 'static', selectedValue: approver.approver_type });

        const $approverSelect = $approverRow.find('.approver-select');
        initSelect2($approverSelect, approverAjaxOptions(approver.approver_type));
        if (approver.approver_id) {
            const opt = new Option(approver.approver_label || approver.approver_id, approver.approver_id, true, true);
            $approverSelect.empty().append(opt).trigger('change.select2');
        }
    });
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
        handle: '.awf-step-drag',
        animation: 150,
        onEnd: function () {
            const newOrderKeys = $('#stepList .awf-step-card').map(function () { return $(this).data('key'); }).get();
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
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (step) step.step_name = $(this).val();
});
$(document).on('input', '.timeout-hours-input', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (step) step.timeout_hours = $(this).val();
});
$(document).on('change', '.joint-mode-radio', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (step) step.joint_approve_mode = $(this).val();
});
$(document).on('change', '.approver-type-select', function () {
    const $approverRow = $(this).closest('.awf-approver-chip');
    const stepKey = $(this).closest('.awf-step-card').data('key');
    const approverKey = $approverRow.data('approver-key');
    const approver = findApprover(stepKey, approverKey);
    if (!approver) return;
    approver.approver_type = $(this).val();
    approver.approver_id = '';
    approver.approver_label = '';
    $approverRow.find('.approver-type-icon i').toggleClass('fa-user-group', approver.approver_type === 'role').toggleClass('fa-user', approver.approver_type !== 'role');
    const $approverSelect = $approverRow.find('.approver-select');
    initSelect2($approverSelect, approverAjaxOptions(approver.approver_type));
});
$(document).on('select2:select', '.approver-select', function (e) {
    const $approverRow = $(this).closest('.awf-approver-chip');
    const stepKey = $(this).closest('.awf-step-card').data('key');
    const approverKey = $approverRow.data('approver-key');
    const approver = findApprover(stepKey, approverKey);
    if (approver) {
        approver.approver_id = e.params.data.id;
        approver.approver_label = e.params.data.text;
    }
});
$(document).on('select2:clear', '.approver-select', function () {
    const $approverRow = $(this).closest('.awf-approver-chip');
    const stepKey = $(this).closest('.awf-step-card').data('key');
    const approverKey = $approverRow.data('approver-key');
    const approver = findApprover(stepKey, approverKey);
    if (approver) { approver.approver_id = ''; approver.approver_label = ''; }
});
$(document).on('click', '.btn-add-approver', function () {
    const $row = $(this).closest('.awf-step-card');
    const step = findStep($row.data('key'));
    if (!step) return;
    step.approvers.push(emptyApprover());
    renderSteps();
});
$(document).on('click', '.delete-approver-btn', function () {
    const $row = $(this).closest('.awf-step-card');
    const step = findStep($row.data('key'));
    if (!step) return;
    const approverKey = $(this).closest('.awf-approver-chip').data('approver-key');
    step.approvers = step.approvers.filter(a => a.key !== approverKey);
    renderSteps();
});
$(document).on('click', '.group-type-btn', function () {
    const $row = $(this).closest('.awf-step-card');
    const step = findStep($row.data('key'));
    if (!step) return;
    step.group_type = $(this).data('group-type');
    $row.find('.group-type-btn').each(function () {
        $(this).toggleClass('active-group', $(this).data('group-type') === step.group_type);
    });
});
$(document).on('change', '.requires-previous-checkbox', function () {
    const $row = $(this).closest('.awf-step-card');
    const step = findStep($row.data('key'));
    if (step) step.requires_previous_step = $(this).is(':checked');
});
$(document).on('click', '.delete-step-btn', function () {
    deleteStepRow($(this).closest('.awf-step-card').data('key'));
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
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-workflow" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link text-primary border-start btn-duplicate-workflow" data-id="${row.id}" title="${langData['duplicate'] || 'Duplicate'}"><i class="fas fa-copy"></i></button>
        <button type="button" class="btn btn-link text-primary border-start btn-toggle-workflow" data-id="${row.id}" data-status="${row.status}" title="${toggleTitle}"><i class="fa-solid ${toggleIcon}"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-workflow" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
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
            { data: 'step_count', className: 'text-center', render: d => `<span class="badge rounded-pill text-bg-light border">${d}</span>` },
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
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_workflow">${langData['add_workflow'] || 'Workflow'}</span>
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
        approvers: (s.approvers && s.approvers.length ? s.approvers.map(a => ({
            key: newApproverKey(),
            approver_type: a.approver_type,
            approver_id: String(a.approver_id),
            approver_label: (currentLang === 'th' ? a.approver_label_th : a.approver_label_en) || a.approver_label_th || a.approver_label_en || ''
        })) : [emptyApprover()]),
        joint_approve_mode: s.joint_approve_mode,
        group_type: s.group_type || 'and',
        requires_previous_step: !!Number(s.requires_previous_step)
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
            approvers: s.approvers.map(a => ({ approver_type: a.approver_type, approver_id: a.approver_id })),
            joint_approve_mode: s.joint_approve_mode,
            group_type: s.group_type,
            requires_previous_step: s.requires_previous_step
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

$(document).ready(function () {
    initApprovalWorkflowTable();
    if (typeof initSelect2 === 'function') {
        initSelect2('#workflow_status', { mode: 'static' });
        initSelect2('#workflow_document_types', { mode: 'ajax', allowClear: true });
    }
});
