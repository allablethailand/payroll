<?php
declare(strict_types=1);

/**
 * status_map.php -- docs/design/rules.md §5 (Phase Design Round 2, item 5). THE single source of
 * label-key + tone for every status enum this app renders as a colored badge -- paired with PHP
 * `statusBadge($enum, $context)` and JS `statusBadgeHtml(enum, context)` (both in
 * public/js/app.js/app/helpers/helpers.php), which are the ONLY two things allowed to read this
 * file. No view/JS file may hardcode a badge color/tone for a status value directly anymore -- that
 * was the entire "map สถานะกระจาย" problem this item exists to fix.
 *
 * Shape: [context => [enum_value => ['label_key' => string, 'tone' => 'neutral'|'warning'|'danger'|
 * 'success', 'direction'? => 'forward'|'back']]]. `direction` only ever appears on
 * 'payroll_process_tab' entries -- it drives status-tabs.php's own reversed-group grouping/arrow (§6
 * decision, already shipped) and has no meaning for a plain single-status badge anywhere else.
 *
 * `label_key` is always an EXISTING key in BOTH public/lang/th.json and public/lang/en.json (checked
 * via `php scripts/check-lang.php` for every new key this file's own construction required) --
 * reused verbatim wherever this app already had one, never re-invented.
 *
 * Tone is a judgment call answering "does the user need to DO something about this status right
 * now?" (§5: ต้องทำ -> warning/danger, จบแล้ว -> success, แค่รู้ -> neutral) -- confirmed with the user
 * against a full survey of every enum in the schema before writing this file (see the session's own
 * research pass). Several values are DELIBERATELY a different tone than whatever ad-hoc Bootstrap
 * class (often blue, `bg-info`/`bg-primary` -- §3 kills blue outright) a page happened to render them
 * with before this file existed -- those pages are round 4's migration job, not fixed here.
 *
 * CONFIRMED, not guessed -- every value below and its tone were reviewed with the user directly
 * before this file was written (see this round's own commit message for the full list of what
 * changed from each value's PRIOR ad-hoc rendering, and why).
 */

// --- run_state (payroll_runs.state) -- the 8 REAL enum values only. -----------------------------
// 'approved' is WARNING, not success/neutral -- confirmed explicitly: for whoever runs payroll,
// "approved" still means "you need to go pay this," matching the Dashboard's own "งานที่ต้องทำ"
// framing (รออนุมัติ/อนุมัติแล้วรอทำต่อ/ค้างนาน, §2) -- this is DELIBERATELY a different tone than
// 'approval_status' (below)'s own 'approved' => success, for a completely different reason:
// approval_status describes a REQUEST that reached a terminal, done state (nothing more to do);
// run_state's 'approved' describes a RUN that still needs a payment action taken. Two different
// questions, two different answers, by design -- not an inconsistency to "fix" into agreement.
// Order matches the real Payroll Process station-row/status-tabs pipeline order (payroll/index.php,
// components.php's own demo) verbatim -- rejected THEN need_info THEN cancelled, not alphabetical --
// since $payrollProcessTab below is built by iterating this array in order, getting this wrong would
// silently reorder the pipeline's own reversed group.
$runState = [
    'draft' => ['label_key' => 'state_draft', 'tone' => 'neutral'],
    'pending_approval' => ['label_key' => 'state_pending_approval', 'tone' => 'warning'],
    'approved' => ['label_key' => 'state_approved', 'tone' => 'warning'],
    'paid' => ['label_key' => 'state_paid', 'tone' => 'success'],
    'locked' => ['label_key' => 'state_locked', 'tone' => 'neutral'],
    'rejected' => ['label_key' => 'state_rejected', 'tone' => 'danger'],
    'need_info' => ['label_key' => 'state_need_info', 'tone' => 'warning'],
    'cancelled' => ['label_key' => 'state_cancelled', 'tone' => 'neutral'],
];

// --- payroll_process_tab -- drives status-tabs.php's own pipeline on the Payroll Process page. ---
// Derived FROM $runState (never hand-duplicated) so the two can never silently drift apart on tone
// -- only 2 things are added on top: the synthetic 'pending_sync' key (not a real payroll_runs.state
// value at all -- Payroll Process's own "unlinked sync" station, prepended first since it's the
// pipeline's own starting point) and 'direction' (already-shipped status-tabs.php decision: the 3
// reversed/exception steps -- rejected/need_info/cancelled -- are 'back', everything else 'forward').
$payrollProcessTab = ['pending_sync' => ['label_key' => 'state_pending_sync', 'tone' => 'neutral', 'direction' => 'forward']];
$backDirectionKeys = ['rejected', 'need_info', 'cancelled'];
foreach ($runState as $key => $entry) {
    $entry['direction'] = in_array($key, $backDirectionKeys, true) ? 'back' : 'forward';
    $payrollProcessTab[$key] = $entry;
}

// --- approval_status -- the ONE generic instance-status shape shared verbatim today by
// approval_requests/leave_requests/overtime_records (and, extended below, payslip_requests/
// employment_certificate_requests) -- confirmed via direct grep this is already the single most
// consistent pattern in the whole app (6 independent files agree on these 4 colors already). ------
$approvalStatus = [
    'pending' => ['label_key' => 'status_pending', 'tone' => 'warning'],
    'approved' => ['label_key' => 'status_approved', 'tone' => 'success'],
    'rejected' => ['label_key' => 'status_rejected', 'tone' => 'danger'],
    'cancelled' => ['label_key' => 'cancelled', 'tone' => 'neutral'],
];

return [
    'run_state' => $runState,
    'payroll_process_tab' => $payrollProcessTab,
    'approval_status' => $approvalStatus,

    // 2 extra terminal states on top of the generic approval_status shape, each its own request's
    // own "phase 2" outcome (§5's own architecture: the generic verdict is phase 1, the document's
    // own issuance/delivery attempt is phase 2 -- see EmploymentCertificateRequestModel/
    // PayslipRequestModel's own docblocks).
    'payslip_request_status' => $approvalStatus + [
        'sent' => ['label_key' => 'status_sent', 'tone' => 'success'],
        'send_failed' => ['label_key' => 'status_send_failed', 'tone' => 'danger'],
    ],
    'employment_certificate_request_status' => $approvalStatus + [
        'issued' => ['label_key' => 'ecr_status_issued', 'tone' => 'success'],
        'issue_failed' => ['label_key' => 'ecr_status_issue_failed', 'tone' => 'danger'],
    ],

    // employees.employee_status -- confirmed with the user: kept as its OWN context, never merged
    // with employment_status below even though 3 of their values share a label -- different DB
    // column, different question ("is this person currently employed at all" vs. "what KIND of
    // employment do they have"), i18n keys reused where the label is genuinely the same word, but
    // the two maps are edited independently.
    'employee_status' => [
        'active' => ['label_key' => 'status_active', 'tone' => 'success'],
        'probation' => ['label_key' => 'status_probation', 'tone' => 'warning'],
        'suspended' => ['label_key' => 'status_suspended', 'tone' => 'warning'],
        'resigned' => ['label_key' => 'status_resigned', 'tone' => 'danger'],
        'terminated' => ['label_key' => 'status_terminated', 'tone' => 'danger'],
    ],
    // employees.employment_status -- see comment above, deliberately separate from employee_status.
    'employment_status' => [
        'probation' => ['label_key' => 'status_probation', 'tone' => 'warning'],
        'permanent' => ['label_key' => 'status_permanent', 'tone' => 'success'],
        'contract' => ['label_key' => 'status_contract', 'tone' => 'neutral'],
        'resigned' => ['label_key' => 'status_resigned', 'tone' => 'danger'],
        'terminated' => ['label_key' => 'status_terminated', 'tone' => 'danger'],
    ],

    // payslip_delivery_logs / DocumentDeliveryLogModel's own unioned success/failed outcome.
    'document_delivery_status' => [
        'success' => ['label_key' => 'document_delivery_status_success', 'tone' => 'success'],
        'failed' => ['label_key' => 'document_delivery_status_failed', 'tone' => 'danger'],
    ],

    // sync_batches.status (Data Sync page). 'running' is WARNING (not neutral) -- confirmed: an
    // in-progress sync reads better as "something is happening, wait" than a flat gray "just FYI."
    'sync_batch_status' => [
        'running' => ['label_key' => 'data_sync_status_running', 'tone' => 'warning'],
        'completed' => ['label_key' => 'data_sync_status_completed', 'tone' => 'success'],
        'failed' => ['label_key' => 'data_sync_status_failed', 'tone' => 'danger'],
    ],

    // payroll_run_details.calc_status.
    'payroll_calc_status' => [
        'pending' => ['label_key' => 'calc_status_pending', 'tone' => 'neutral'],
        'calculated' => ['label_key' => 'calc_status_calculated', 'tone' => 'success'],
        'error' => ['label_key' => 'calc_status_error', 'tone' => 'danger'],
    ],

    // employee_earning_deductions.status (installment PLAN, not the per-line status below).
    // 'completed' is NEUTRAL (not success) -- confirmed: a finished loan/installment plan is just a
    // fact from then on, not something worth celebrating every time the row is seen.
    'eed_status' => [
        'active' => ['label_key' => 'status_active', 'tone' => 'success'],
        'paused' => ['label_key' => 'paused', 'tone' => 'warning'],
        'completed' => ['label_key' => 'completed', 'tone' => 'neutral'],
        'cancelled' => ['label_key' => 'cancelled', 'tone' => 'neutral'],
    ],
    // employee_earning_deduction_installments.status (one PER-LINE row's own status).
    'eed_installment_status' => [
        'pending' => ['label_key' => 'installment_status_pending', 'tone' => 'neutral'],
        'processed' => ['label_key' => 'installment_status_processed', 'tone' => 'success'],
        'skipped' => ['label_key' => 'installment_status_skipped', 'tone' => 'warning'],
    ],

    // payroll_remittances.status. 'transferred' is WARNING (not neutral/success) -- confirmed:
    // "transferred but not yet confirmed received" is still an open/pending state in spirit.
    'remittance_status' => [
        'pending' => ['label_key' => 'remittance_status_pending', 'tone' => 'warning'],
        'transferred' => ['label_key' => 'remittance_status_transferred', 'tone' => 'warning'],
        'success' => ['label_key' => 'remittance_status_success', 'tone' => 'success'],
        'failed' => ['label_key' => 'remittance_status_failed', 'tone' => 'danger'],
    ],

    // attendance_records.status. 'holiday' is NEUTRAL (not blue/info) -- confirmed: purely
    // informational, nothing for the viewer to act on.
    'attendance_status' => [
        'present' => ['label_key' => 'status_present', 'tone' => 'success'],
        'absent' => ['label_key' => 'status_absent', 'tone' => 'danger'],
        'leave' => ['label_key' => 'status_leave', 'tone' => 'warning'],
        'holiday' => ['label_key' => 'holiday', 'tone' => 'neutral'],
    ],

    // employee_recurring_earnings' own derived is_suspended_now flag -- a DIFFERENT concept from
    // employee_status's own 'suspended' value above (a whole employee vs. one recurring allowance
    // line), kept as its own context on purpose, same reasoning as employee_status/employment_status.
    'recurring_earning_status' => [
        'active' => ['label_key' => 'status_active', 'tone' => 'success'],
        'suspended' => ['label_key' => 'paused', 'tone' => 'warning'],
    ],

    // data_source (manual/sync/import) -- confirmed: this is NOT a status at all (§5: "Badge =
    // สถานะเท่านั้น ไม่ใช่ label ทั่วไป...ที่มา -> เป็นข้อความธรรมดา"), it's a category/origin tag. Kept
    // here ONLY as a temporary bridge so any page migrated to statusBadge() in round 4 before this
    // gets cleaned up doesn't break -- every value is 'neutral' on purpose (no tone question even
    // applies to a category tag). **Round 4 should stop rendering this as a badge entirely** (plain
    // text or its own dedicated column instead) rather than keep calling statusBadge() with this
    // context long-term.
    'data_source' => [
        'manual' => ['label_key' => 'source_manual', 'tone' => 'neutral'],
        'sync' => ['label_key' => 'source_sync', 'tone' => 'neutral'],
        'import' => ['label_key' => 'source_import', 'tone' => 'neutral'],
    ],
];
