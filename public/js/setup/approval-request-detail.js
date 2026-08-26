/**
 * Generic Approval Request detail modal (`#requestDetailModal`) -- 2026-08-26, explicit request:
 * "การอนุมัติให้เป็นรูปแบบเดียวกับ Approve Process ครับมี timeline ให้กดดู" (make the approval detail
 * look like the Payroll Approve Process page, with a clickable timeline).
 *
 * Extracted OUT of payslip-request.js (where this used to live as a plain bordered list) so BOTH
 * Payslip Requests and the new Employment Certificate Requests tab (same page, `requests.php`) share
 * ONE modal + ONE set of rendering functions -- this is intentionally document-type-agnostic, driven
 * only by an `approval_request_id`, exactly like the generic engine endpoints it calls
 * (`GET api/approval-request.get`, `GET api/approval-request.logs`, `POST api/approval-request.act`).
 *
 * NOT modeled on `public/js/payroll/approval.js`'s own `.apv-*` timeline -- that one is tightly
 * coupled to `payroll_run_audit_logs`/`PayrollRunModel::approvalFlow()` (see the research this was
 * built from), which doesn't exist for an arbitrary approval_request_id. This is a lighter, genuinely
 * generic re-design of the OLD plain-list timeline (`approval_request_logs` + a "Requested"/"Pending"
 * bookend stage), styled with the same visual language (colored circular stage markers connected by a
 * vertical line, badges) as the Approve Process page, without reaching into payroll-specific tables.
 *
 * Usage: `openApprovalRequestDetail(approvalRequestId, onActed)` -- `onActed` is called once after a
 * successful approve/reject/cancel, so the caller's own DataTable can reload itself; the modal never
 * assumes which table is currently open.
 */
let currentDetailRequestId = null;
let currentDetailReloadFn = null;

function escapeHtmlArd(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

/** Generic status for an `approval_requests` row itself (pending/approved/rejected/cancelled only --
 *  NOT payslip_requests' own extra sent/send_failed, or employment_certificate_requests' own extra
 *  issued/issue_failed -- those are each document type's own DataTable column, rendered by that
 *  tab's own JS, not this shared modal). */
function approvalStatusBadge(status) {
    const map = {
        pending: { cls: 'bg-warning-subtle text-warning', key: 'status_pending', fallback: 'Pending' },
        approved: { cls: 'bg-success-subtle text-success', key: 'status_approved', fallback: 'Approved' },
        rejected: { cls: 'bg-danger-subtle text-danger', key: 'status_rejected', fallback: 'Rejected' },
        cancelled: { cls: 'bg-secondary-subtle text-secondary', key: 'cancelled', fallback: 'Cancelled' }
    };
    const m = map[status] || { cls: 'bg-secondary-subtle text-secondary', key: '', fallback: status };
    return `<span class="badge ${m.cls}">${langData[m.key] || m.fallback}</span>`;
}

function renderRequestSummary(req) {
    const requesterName = (currentLang === 'th' ? req.requested_by_name_th : req.requested_by_name_en) || req.requested_by_name_th || req.requested_by_name_en || '-';
    const docTypeName = (currentLang === 'th' ? req.document_type_name_th : req.document_type_name_en) || req.document_type_name_th || req.document_type_name_en || '';
    $('#requestDetailSummary').html(`
        <div class="row g-2 small">
            <div class="col-sm-6"><strong>${langData['document_types'] || 'Document Type'}:</strong> ${escapeHtmlArd(docTypeName)}</div>
            <div class="col-sm-6"><strong>${langData['reference'] || 'Reference'}:</strong> ${escapeHtmlArd(req.reference_label || '-')}</div>
            <div class="col-sm-6"><strong>${langData['workflow_name'] || 'Workflow'}:</strong> ${escapeHtmlArd(req.workflow_name)}</div>
            <div class="col-sm-6"><strong>${langData['status'] || 'Status'}:</strong> ${approvalStatusBadge(req.status)}</div>
            <div class="col-sm-6"><strong>${langData['requested_by'] || 'Requested By'}:</strong> ${escapeHtmlArd(requesterName)}</div>
            <div class="col-sm-6"><strong>${langData['requested_at'] || 'Requested At'}:</strong> ${escapeHtmlArd(req.requested_at)}</div>
        </div>
    `);
}

/** One stage = a colored marker + connector line + content block. `variant` picks the marker/badge
 *  color (requested/approve/reject/cancel/pending), matching the Approve Process page's own visual
 *  language without depending on any of its payroll-specific data. */
function artStageHtml(variant, iconClass, titleHtml, bodyHtml) {
    return `
        <div class="art-stage art-stage-${variant}">
            <div class="art-stage-rail">
                <div class="art-stage-marker"><i class="fa-solid ${iconClass}"></i></div>
                <div class="art-stage-line"></div>
            </div>
            <div class="art-stage-content">
                <div class="art-stage-head">${titleHtml}</div>
                <div class="art-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}

function renderRequestTimeline(logs, request) {
    const $wrap = $('#requestDetailTimeline').addClass('art-timeline').empty();

    const requesterName = (currentLang === 'th' ? request.requested_by_name_th : request.requested_by_name_en) || request.requested_by_name_th || request.requested_by_name_en || '-';
    $wrap.append(artStageHtml(
        'requested', 'fa-paper-plane',
        `<span class="art-stage-title">${langData['request_submitted'] || 'Request Submitted'}</span>`,
        `${escapeHtmlArd(requesterName)} — ${escapeHtmlArd(request.requested_at)}`
    ));

    const actionMeta = {
        approve: { variant: 'approve', icon: 'fa-check', badgeCls: 'art-badge-approved', key: 'approve', fallback: 'Approved' },
        reject: { variant: 'reject', icon: 'fa-xmark', badgeCls: 'art-badge-rejected', key: 'reject', fallback: 'Rejected' },
        cancel: { variant: 'cancel', icon: 'fa-ban', badgeCls: 'art-badge-cancelled', key: 'cancel_request', fallback: 'Cancelled' }
    };
    logs.forEach(l => {
        const actorName = (currentLang === 'th' ? l.acted_by_name_th : l.acted_by_name_en) || l.acted_by_name_th || l.acted_by_name_en || '-';
        const meta = actionMeta[l.action] || { variant: 'pending', icon: 'fa-circle', badgeCls: 'art-badge-pending', key: '', fallback: l.action };
        $wrap.append(artStageHtml(
            meta.variant, meta.icon,
            `<span class="art-stage-title">${escapeHtmlArd(l.step_name_snapshot || '')}</span><span class="art-badge ${meta.badgeCls}">${langData[meta.key] || meta.fallback}</span>`,
            `${escapeHtmlArd(actorName)} — ${escapeHtmlArd(l.acted_at)}` + (l.note ? `<div class="art-stage-note">${escapeHtmlArd(l.note)}</div>` : '')
        ));
    });

    if (request.status === 'pending') {
        $wrap.append(artStageHtml(
            'pending', 'fa-hourglass-half',
            `<span class="art-stage-title">${langData['status_pending'] || 'Pending'}</span>`,
            langData['waiting_next_approver'] || 'Waiting for the next approver.'
        ));
    }
}

function openApprovalRequestDetail(id, onActed) {
    currentDetailRequestId = id;
    currentDetailReloadFn = typeof onActed === 'function' ? onActed : null;
    // Two independent requests (summary + logs) settle in whichever order the network returns them
    // -- the timeline needs BOTH (logs for the stages, the request itself for the "Requested"/
    // "Pending" bookend stages and the status), so render only once both are in hand.
    let request = null;
    let logs = null;
    function renderTimelineIfReady() {
        if (request !== null && logs !== null) {
            renderRequestTimeline(logs, request);
        }
    }
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
            request = res.data;
            renderRequestSummary(res.data);
            $('#requestActionArea').toggleClass('d-none', res.data.status !== 'pending');
            $('#requestActionNote').val('');
            new bootstrap.Modal(document.getElementById('requestDetailModal')).show();
            renderTimelineIfReady();
        }
    });
    $.ajax({
        url: `${BASE_URL}/api/approval-request.logs`,
        method: 'GET',
        data: { request_id: id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            logs = res.data;
            renderTimelineIfReady();
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
                openApprovalRequestDetail(currentDetailRequestId, currentDetailReloadFn);
                if (currentDetailReloadFn) currentDetailReloadFn();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred.'); }
    });
}

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
