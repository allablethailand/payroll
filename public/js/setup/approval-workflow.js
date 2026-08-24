/**
 * Approval Workflow settings page — 2026-08-24 redesign (explicit request: "ให้แบ่งเป็น Tab
 * อนุมัติงวดเงินเดือน และ อนุมัติ Pay Slip ไปเลยให้ตั้งค่า และในแต่ละ Tab ก็ให้จัดการได้เลย 1 Tab ต่อ 1
 * Flow ไม่ต้องเปิด Modal เข้าไปจัดการ แต่เป็นการเปิดแก้ไข แถว by แถว มีปุ่ม Save แยกตามแถว และมีปุ่มในการ
 * บันทึก Sort ในกรณีทีมีการลากจัดตำแหน่ง ให้เหมือน [origami's own approval settings page]").
 *
 * Replaces the earlier DataTable-of-workflows + single big modal editor entirely. Two fixed pills
 * (`#approvalFlowDocTypeTabs`), one per document type this page configures (PAYROLL_RUN_APPROVAL,
 * SLIP_REQUEST_APPROVAL) — each pill IS that document type's one flow (ApprovalWorkflowModel::
 * getByDocumentType(), no workflow list/picker, no document-type multi-select, no workflow_name
 * field — auto-derived server-side the first time a step is saved). Steps render as in-page
 * "cards" that are either a compact VIEW row or, when being edited, the same rich editor UI the
 * old modal used (approver chips, joint-mode radios, AND/OR/Finish toggle, requires-previous-step
 * switch) — just with its own Save/Cancel footer instead of one shared form submit, and toggled
 * per-row via Edit/Cancel instead of a modal open/close. Drag-reorder (SortableJS, handle only on
 * VIEW rows) updates the in-memory order and badge numbers immediately but does NOT autosave on
 * drop — a "Save Order" button appears until explicitly clicked, per explicit request (the
 * reference app it otherwise mirrors DOES autosave on drop; this one deliberately doesn't).
 *
 * `flowSteps` is the source of truth for the CURRENT tab (each item: key, id [null = never saved],
 * step_name, approvers[], joint_approve_mode, group_type, requires_previous_step, editing,
 * _original [snapshot for Cancel on an existing step]). Switching tabs reloads it from scratch via
 * loadFlow() — no cross-tab state is kept.
 */
let currentDocType = 'PAYROLL_RUN_APPROVAL';
let currentFlow = null; // null = this company has never configured a flow for this document type
let flowSteps = [];
let sortDirty = false;
let stepKeyCounter = 0;
let approverKeyCounter = 0;
let stepSortableInstance = null;

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
        id: null,
        step_name: '',
        approvers: [emptyApprover()],
        joint_approve_mode: 'any',
        group_type: 'and',
        requires_previous_step: false,
        editing: true,
        _original: null
    };
}

function findStep(key) {
    return flowSteps.find(s => s.key === key);
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

function escapeHtmlAw(str) {
    // .text()/.html() round-trip escapes <, >, & but NOT quote characters (browsers don't
    // escape quotes inside text-node serialization) — and this value is interpolated into a
    // double-quoted HTML attribute below, so an unescaped " would break out of the attribute.
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
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

function buildApproverChipViewHtml(a) {
    return `
        <span class="awf-approver-chip awf-approver-chip-view">
            <span class="awf-approver-chip-icon"><i class="fa-solid ${a.approver_type === 'role' ? 'fa-user-group' : 'fa-user'}"></i></span>
            ${escapeHtmlAw(a.approver_label || '')}
        </span>
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

/* ---------- Row rendering: VIEW (compact, static) vs EDIT (the rich card editor) ---------- */
function buildStepViewHtml(step, index) {
    const approversHtml = step.approvers.map(buildApproverChipViewHtml).join('');
    const jointLabel = step.joint_approve_mode === 'all' ? (langData['joint_approve_all'] || 'Every eligible person must approve') : (langData['joint_approve_any'] || 'Any one approver is enough');
    const groupMeta = GROUP_TYPE_META[step.group_type] || GROUP_TYPE_META.and;
    const groupLabel = langData[groupMeta.key] || groupMeta.fallback;
    const reqLabel = step.requires_previous_step ? (langData['requires_previous_step'] || 'Wait for earlier sequenced steps') : (langData['always_open'] || 'Always open for approval');
    const stepLabel = step.step_name ? escapeHtmlAw(step.step_name) : `${langData['step_name_default'] || 'Step'} ${index + 1}`;
    return `
        <div class="awf-step-card awf-step-view" data-key="${step.key}">
            <div class="awf-step-marker">
                <div class="awf-step-badge">${index + 1}</div>
                <div class="awf-step-connector"></div>
            </div>
            <div class="awf-step-drag" title="${langData['drag_to_reorder'] || 'Drag to reorder'}"><i class="fa-solid fa-grip-vertical"></i></div>
            <div class="awf-step-body">
                <div class="awf-step-view-name">${stepLabel}</div>
                <div class="step-approvers-list">${approversHtml}</div>
                <div class="awf-step-view-meta">
                    <span class="awf-meta-pill"><i class="fa-solid fa-users"></i>${jointLabel}</span>
                    <span class="awf-meta-pill awf-meta-pill-${step.group_type}"><i class="fa-solid ${groupMeta.icon}"></i>${groupLabel}</span>
                    <span class="awf-meta-pill"><i class="fa-solid fa-link"></i>${reqLabel}</span>
                </div>
            </div>
            <div class="awf-step-view-actions">
                <button type="button" class="btn btn-link text-warning btn-edit-flow-step" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="btn btn-link text-danger btn-delete-flow-step" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    `;
}

function buildStepEditHtml(step, index) {
    const approversHtml = step.approvers.map(a => buildApproverRowHtml(step, a)).join('');
    return `
        <div class="awf-step-card awf-step-editing" data-key="${step.key}">
            <div class="awf-step-marker">
                <div class="awf-step-badge">${index + 1}</div>
                <div class="awf-step-connector"></div>
            </div>
            <div class="awf-step-body">
                <input type="text" class="form-control form-control-sm awf-step-name-input step-name-input mb-2" name="step_name" value="${escapeHtmlAw(step.step_name)}" placeholder="${langData['step_name_placeholder'] || 'Step name (optional)'}">

                <div class="step-approvers-list">${approversHtml}</div>
                <button type="button" class="btn btn-link btn-sm p-0 mb-1 awf-add-approver-btn btn-add-approver"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_approver">${langData['add_approver'] || 'Approver'}</span></button>

                <div class="d-flex flex-wrap align-items-center gap-3 small mt-1">
                    <div class="form-check form-check-inline m-0">
                        <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" id="jointany_${step.key}" value="any" ${step.joint_approve_mode === 'any' ? 'checked' : ''}>
                        <label class="form-check-label" for="jointany_${step.key}">${langData['joint_approve_any'] || 'Any one approver is enough'}</label>
                    </div>
                    <div class="form-check form-check-inline m-0">
                        <input type="radio" class="form-check-input joint-mode-radio" name="joint_mode_${step.key}" id="jointall_${step.key}" value="all" ${step.joint_approve_mode === 'all' ? 'checked' : ''}>
                        <label class="form-check-label" for="jointall_${step.key}">${langData['joint_approve_all'] || 'Every eligible person must approve'}</label>
                    </div>
                </div>

                <hr class="awf-step-divider">

                <div class="awf-step-footer">
                    <div class="btn-group btn-group-sm awf-group-toggle" role="group">${groupTypeToggleHtml(step)}</div>
                    <div class="form-check form-switch m-0 awf-seq-switch">
                        <input type="checkbox" class="form-check-input requires-previous-checkbox" id="reqprev_${step.key}" ${step.requires_previous_step ? 'checked' : ''}>
                        <label class="form-check-label small" for="reqprev_${step.key}"><i class="fa-solid fa-link me-1"></i>${langData['requires_previous_step'] || 'Wait for earlier sequenced steps'}</label>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-light btn-sm awf-step-cancel-btn">${langData['cancel'] || 'Cancel'}</button>
                    <button type="button" class="btn btn-primary btn-sm awf-step-save-btn"><i class="fa-solid fa-check me-1"></i>${langData['save'] || 'Save'}</button>
                </div>
            </div>
        </div>
    `;
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

function renderFlowSteps() {
    const $wrap = $('#flowStepList').empty();
    flowSteps.forEach((step, index) => {
        const $row = $(step.editing ? buildStepEditHtml(step, index) : buildStepViewHtml(step, index));
        $wrap.append($row);
        if (step.editing) initStepRowWidgets($row, step);
    });
    $('#flowNoStepsMessage').toggleClass('d-none', flowSteps.length > 0);
    initStepSortable();
}

function initStepSortable() {
    const el = document.getElementById('flowStepList');
    if (!el) return;
    if (stepSortableInstance) {
        stepSortableInstance.destroy();
        stepSortableInstance = null;
    }
    if (typeof Sortable === 'undefined') return;
    stepSortableInstance = Sortable.create(el, {
        handle: '.awf-step-drag', // only present on VIEW rows -- a row mid-edit can't be dragged
        animation: 150,
        onEnd: function () {
            const newOrderKeys = $('#flowStepList .awf-step-card').map(function () { return $(this).data('key'); }).get();
            flowSteps.sort((a, b) => newOrderKeys.indexOf(a.key) - newOrderKeys.indexOf(b.key));
            sortDirty = true;
            $('#btnSaveSort, #sortUnsavedHint').removeClass('d-none');
            renderFlowSteps();
        }
    });
}

/* ---------- Flow status (top of the panel) ---------- */
function renderFlowStatus() {
    const $badge = $('#flowStatusBadge');
    const $toggleBtn = $('#btnToggleFlowStatus');
    const $hint = $('#flowStatusHint');
    if (!currentFlow) {
        $badge.addClass('d-none');
        $toggleBtn.addClass('d-none');
        $hint.text('');
        return;
    }
    const isActive = currentFlow.status === 'active';
    $badge.removeClass('d-none bg-success-subtle text-success bg-secondary-subtle text-secondary')
        .addClass(isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary')
        .text(isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive'));
    $toggleBtn.removeClass('d-none').html(`<i class="fa-solid ${isActive ? 'fa-toggle-on' : 'fa-toggle-off'} me-1"></i>${isActive ? (langData['deactivate'] || 'Deactivate') : (langData['activate'] || 'Activate')}`);
    $hint.text(isActive ? (langData['flow_status_active_hint'] || '') : (langData['flow_status_inactive_hint'] || ''));
}

/* ---------- Load / switch tabs ---------- */
function loadFlow(documentTypeCode) {
    currentDocType = documentTypeCode;
    sortDirty = false;
    $('#btnSaveSort, #sortUnsavedHint').addClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.flow-get`,
        method: 'GET',
        data: { document_type_code: documentTypeCode },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            currentFlow = res.data || null;
            flowSteps = ((currentFlow && currentFlow.steps) || []).map(s => ({
                key: newStepKey(),
                id: Number(s.id),
                step_name: s.step_name || '',
                approvers: (s.approvers && s.approvers.length ? s.approvers.map(a => ({
                    key: newApproverKey(),
                    approver_type: a.approver_type,
                    approver_id: String(a.approver_id),
                    approver_label: (currentLang === 'th' ? a.approver_label_th : a.approver_label_en) || a.approver_label_th || a.approver_label_en || ''
                })) : [emptyApprover()]),
                joint_approve_mode: s.joint_approve_mode,
                group_type: s.group_type || 'and',
                requires_previous_step: !!Number(s.requires_previous_step),
                editing: false,
                _original: null
            }));
            renderFlowStatus();
            renderFlowSteps();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}

/* ---------- Field-level state sync (event delegation, no full re-render for simple edits) ---------- */
$(document).on('input', '.step-name-input', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (step) step.step_name = $(this).val();
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
    renderFlowSteps();
});
$(document).on('click', '.delete-approver-btn', function () {
    const $row = $(this).closest('.awf-step-card');
    const step = findStep($row.data('key'));
    if (!step) return;
    const approverKey = $(this).closest('.awf-approver-chip').data('approver-key');
    step.approvers = step.approvers.filter(a => a.key !== approverKey);
    renderFlowSteps();
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

/* ---------- Row lifecycle: add / edit / cancel / save / delete ---------- */
$(document).on('click', '#btnAddFlowStep', function () {
    const step = emptyStep();
    flowSteps.push(step);
    renderFlowSteps();
    const el = document.querySelector(`.awf-step-card[data-key="${step.key}"]`);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

$(document).on('click', '.btn-edit-flow-step', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (!step) return;
    step._original = JSON.parse(JSON.stringify({
        step_name: step.step_name,
        approvers: step.approvers,
        joint_approve_mode: step.joint_approve_mode,
        group_type: step.group_type,
        requires_previous_step: step.requires_previous_step
    }));
    step.editing = true;
    renderFlowSteps();
});

$(document).on('click', '.awf-step-cancel-btn', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (!step) return;
    if (!step.id) {
        // Never saved -- Cancel discards the row entirely.
        flowSteps = flowSteps.filter(s => s.key !== key);
    } else {
        if (step._original) {
            Object.assign(step, step._original);
        }
        step.editing = false;
        step._original = null;
    }
    renderFlowSteps();
});

$(document).on('click', '.btn-delete-flow-step', function () {
    const key = $(this).closest('.awf-step-card').data('key');
    const step = findStep(key);
    if (!step || !step.id) return;
    showConfirm(langData['confirm_delete_step'] || 'Remove this step?', '', function () {
        $.ajax({
            url: `${BASE_URL}/api/approval-workflow.step-delete`,
            method: 'POST',
            data: { step_id: step.id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    flowSteps = flowSteps.filter(s => s.key !== key);
                    renderFlowSteps();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});

$(document).on('click', '.awf-step-save-btn', function () {
    const $card = $(this).closest('.awf-step-card');
    const key = $card.data('key');
    const step = findStep(key);
    if (!step) return;
    if (!step.approvers.length || step.approvers.some(a => !a.approver_id)) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        document_type_code: currentDocType,
        step_id: step.id || undefined,
        step_name: step.step_name,
        approvers: step.approvers.map(a => ({ approver_type: a.approver_type, approver_id: a.approver_id })),
        joint_approve_mode: step.joint_approve_mode,
        group_type: step.group_type,
        requires_previous_step: step.requires_previous_step
    };
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.step-save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                step.id = res.step_id;
                step.editing = false;
                step._original = null;
                if (!currentFlow) currentFlow = { id: res.workflow_id, status: 'active' };
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                renderFlowStatus();
                renderFlowSteps();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

/* ---------- Save Order (explicit button, not autosaved on drop) ---------- */
$(document).on('click', '#btnSaveSort', function () {
    const stepIds = flowSteps.filter(s => s.id).map(s => s.id);
    if (!stepIds.length) return;
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.steps-sort`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ document_type_code: currentDocType, step_ids: stepIds }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                sortDirty = false;
                $('#btnSaveSort, #sortUnsavedHint').addClass('d-none');
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

/* ---------- Flow-level enable/disable toggle ---------- */
$(document).on('click', '#btnToggleFlowStatus', function () {
    if (!currentFlow) return;
    const newStatus = currentFlow.status === 'active' ? 'inactive' : 'active';
    $.ajax({
        url: `${BASE_URL}/api/approval-workflow.toggle-status`,
        method: 'POST',
        data: { id: currentFlow.id, status: newStatus },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                loadFlow(currentDocType);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

/* ---------- Document-type pills ---------- */
$(document).on('click', '#approvalFlowDocTypeTabs .nav-link', function () {
    $('#approvalFlowDocTypeTabs .nav-link').removeClass('active');
    $(this).addClass('active');
    loadFlow($(this).data('document-type'));
});

$(document).ready(function () {
    loadFlow(currentDocType);
});
