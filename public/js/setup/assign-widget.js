/**
 * 2026-09-04, Backlog Phase 10, T055 -- generic, reusable "Assign to Department/Position/Team/
 * Employee" widget. Generalizes the department/position/team/employee checkbox-grid + search +
 * select-all-per-column pattern already independently built 3 times this session (most closely
 * OtRateSetModel/setup-rules.js's own #otModal Assign-To section, the only prior 4-scope-type
 * version -- Payslip/Employment Certificate Template's own "Assign To" round only ever had 3 types,
 * department/team/employee, no Position).
 *
 * DELIBERATELY MAKES NO AJAX CALL OF ITS OWN, not even to read option lists -- this is a pure
 * presentation/collection layer. The CALLER is responsible for:
 *   1. Fetching `assignableOptions` (EntityAssignmentModel::assignableOptions()'s own shape --
 *      {departments, positions, teams, employees}, each [{id, label}]) through THAT feature's own
 *      already-permission-gated endpoint (e.g. bundled into its "get one record" response), and
 *      passing it into openAssignModal().
 *   2. Persisting whatever the user checked, via THAT feature's own already-permission-gated save
 *      endpoint (server-side calling EntityAssignmentModel::saveAssignments() in the same request/
 *      transaction as its own data) -- openAssignModal()'s onSave callback only ever receives the
 *      collected {scope_type, scope_id} array in memory, this widget never calls fetch()/$.ajax()
 *      itself. See EntityAssignmentModel's own docblock for the full "why no shared HTTP endpoint"
 *      reasoning -- this is a deliberate scope boundary of T055, not an oversight.
 *
 * Usage:
 *   assignSummaryBadgeHtml(assignments)              -- HTML string, e.g. for a card's own "Assigned
 *                                                        to: ..." tag (T054's own literal ask).
 *   openAssignModal({ entityLabel, assignableOptions, currentAssignments, onSave(newAssignments) })
 *                                                     -- opens the shared #entityAssignModal (see
 *                                                        modals.php); onSave fires once, with the
 *                                                        freshly-collected assignment array, right
 *                                                        before the modal closes on Save.
 */
const EAW_LIST_ELS = { department: '#eawAssignDepartments', position: '#eawAssignPositions', team: '#eawAssignTeams', employee: '#eawAssignEmployees' };
let eawOnSaveCallback = null;

function eawEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function eawAssignListHtml(options, scopeType, checkedIds) {
    if (!options || !options.length) {
        return `<div class="text-muted small p-1">-</div>`;
    }
    return options.map(function (o) {
        const id = o.id;
        const checked = checkedIds.indexOf(String(id)) !== -1 ? 'checked' : '';
        const label = String(o.label || '');
        return `<div class="form-check eaw-assign-item" data-label="${eawEscapeHtml(label.toLowerCase())}">
            <input class="form-check-input eaw-assign-checkbox" type="checkbox" value="${id}" data-scope-type="${scopeType}" id="eaw_assign_${scopeType}_${id}" ${checked}>
            <label class="form-check-label small" for="eaw_assign_${scopeType}_${id}">${eawEscapeHtml(label)}</label>
        </div>`;
    }).join('');
}

function eawBuildLists(assignableOptions, currentAssignments) {
    const checkedByType = { department: [], position: [], team: [], employee: [] };
    (currentAssignments || []).forEach(function (a) {
        if (checkedByType[a.scope_type]) {
            checkedByType[a.scope_type].push(String(a.scope_id));
        }
    });
    const data = assignableOptions || { departments: [], positions: [], teams: [], employees: [] };
    $('#eawAssignDepartments').html(eawAssignListHtml(data.departments, 'department', checkedByType.department));
    $('#eawAssignPositions').html(eawAssignListHtml(data.positions, 'position', checkedByType.position));
    $('#eawAssignTeams').html(eawAssignListHtml(data.teams, 'team', checkedByType.team));
    $('#eawAssignEmployees').html(eawAssignListHtml(data.employees, 'employee', checkedByType.employee));
    // Reset search/select-all controls on every rebuild so stale filter text/checked state from a
    // previous modal open never carries over -- same convention as OT Rate's own buildOtAssignLists().
    $('.eaw-assign-search').val('');
    $('.eaw-assign-select-all').prop('checked', false);
}

/** T054's own literal ask: "a tag saying so" on a card when an entity is scoped to specific groups.
 *  Empty/unscoped renders a neutral "everyone" badge; scoped renders a count-per-type summary. */
function assignSummaryBadgeHtml(assignments) {
    const list = assignments || [];
    if (!list.length) {
        const txt = (typeof langData !== 'undefined' && langData['eaw_everyone']) || 'All Employees';
        return `<span class="badge bg-light text-dark border">${eawEscapeHtml(txt)}</span>`;
    }
    const counts = {};
    list.forEach(function (a) {
        counts[a.scope_type] = (counts[a.scope_type] || 0) + 1;
    });
    const i18nKeys = { department: 'eaw_count_department', position: 'eaw_count_position', team: 'eaw_count_team', employee: 'eaw_count_employee' };
    const fallback = { department: 'Department(s)', position: 'Position(s)', team: 'Team(s)', employee: 'Employee(s)' };
    const parts = [];
    ['department', 'position', 'team', 'employee'].forEach(function (t) {
        if (counts[t]) {
            const labelTxt = (typeof langData !== 'undefined' && langData[i18nKeys[t]]) || fallback[t];
            parts.push(counts[t] + ' ' + labelTxt);
        }
    });
    return `<span class="badge bg-warning-subtle text-warning border" title="${eawEscapeHtml(parts.join(', '))}"><i class="fa-solid fa-filter me-1"></i>${eawEscapeHtml(parts.join(', '))}</span>`;
}

/**
 * @param {Object} opts
 * @param {string} [opts.entityLabel] - modal title, e.g. "Assign: Late Deduction"
 * @param {Object} opts.assignableOptions - EntityAssignmentModel::assignableOptions() shape
 * @param {Array}  opts.currentAssignments - [{scope_type, scope_id}, ...]
 * @param {Function} opts.onSave - called with the freshly-collected assignment array on Save click
 */
function openAssignModal(opts) {
    const options = opts || {};
    eawOnSaveCallback = typeof options.onSave === 'function' ? options.onSave : null;
    const titleTxt = options.entityLabel || (typeof langData !== 'undefined' && langData['eaw_modal_title']) || 'Assign To';
    $('#eawModalTitle').text(titleTxt);
    eawBuildLists(options.assignableOptions, options.currentAssignments);
    new bootstrap.Modal(document.getElementById('entityAssignModal')).show();
}

function eawCollectAssignments() {
    const assignments = [];
    $('.eaw-assign-checkbox:checked').each(function () {
        assignments.push({ scope_type: $(this).data('scope-type'), scope_id: parseInt($(this).val(), 10) });
    });
    return assignments;
}

// Search + select-all per column -- "Select All" only affects currently-VISIBLE (non-filtered-out)
// rows, matching the same established convention as OT Rate's/Payslip-ECT Template's own Assign-To
// checkbox lists.
$(document).on('input', '.eaw-assign-search', function () {
    const scopeType = $(this).data('scope-type');
    const term = $(this).val().toLowerCase().trim();
    const $list = $(EAW_LIST_ELS[scopeType]);
    $list.find('.eaw-assign-item').each(function () {
        $(this).toggleClass('d-none', term !== '' && $(this).data('label').indexOf(term) === -1);
    });
});
$(document).on('change', '.eaw-assign-select-all', function () {
    const scopeType = $(this).data('scope-type');
    const checked = $(this).is(':checked');
    $(EAW_LIST_ELS[scopeType]).find('.eaw-assign-item:not(.d-none) .eaw-assign-checkbox').prop('checked', checked);
});
$(document).on('click', '#eawSaveBtn', function () {
    const assignments = eawCollectAssignments();
    if (eawOnSaveCallback) {
        eawOnSaveCallback(assignments);
    }
    const modalEl = document.getElementById('entityAssignModal');
    const instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.hide();
});
