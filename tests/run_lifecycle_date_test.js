/**
 * 2026-09-24, tiny round B -- `runLifecycleCancelledFromState(run)`/`runLifecycleStepDate(run, step)`
 * (public/js/app.js), pure functions with no jQuery/DOM dependency, extracted the same
 * brace-matching way tests/tcf_format_value_test.js already does for table-column-filter.js (this
 * repo ships no jsdom, and app.js itself is full of top-level jQuery calls that would throw outside
 * a browser -- extracting just these 2 function bodies avoids loading any of that).
 *
 * What this locks: both functions now read straight off the run's own columns
 * (`run.cancelled_from_state`, `run[step.dateField]`) -- `.get()` stopped carrying a full
 * `run.audit_log` this round (see docs/decisions/2026-09-24-tiny2-get-audit-log-removal.md), so a
 * leftover `audit_log` array on a run object (e.g. a stale cached payload, or a caller that still
 * builds one some other way) must NOT be read anymore, even when present and non-empty.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const appJsPath = path.join(root, 'public', 'js', 'app.js');
const appJsSource = fs.readFileSync(appJsPath, 'utf8');

function fn(text, name) {
    const startIdx = text.indexOf(`function ${name}(`);
    if (startIdx === -1) throw new Error(`${name}() not found -- renamed/removed?`);
    let depth = 0;
    let i = text.indexOf('{', startIdx);
    for (; i < text.length; i++) {
        if (text[i] === '{') depth++;
        else if (text[i] === '}' && --depth === 0) break;
    }
    if (depth !== 0) throw new Error(`${name}() -- no matching closing brace`);
    return text.slice(startIdx, i + 1);
}

const extracted = [
    fn(appJsSource, 'runLifecycleCancelledFromState'),
    fn(appJsSource, 'runLifecycleStepDate'),
    'module.exports = { runLifecycleCancelledFromState, runLifecycleStepDate };',
].join('\n');

const Module = require('module');
const m = new Module(appJsPath);
m._compile(extracted, appJsPath);
const api = m.exports;

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) {
        passed++;
        console.log(`  PASS  ${label}`);
    } else {
        failed++;
        console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`);
    }
}

// Matches app.js's own RUN_LIFECYCLE_STEPS entries -- not imported directly (extraction only pulls
// the 2 functions under test), just the shape both functions actually read (`step.key`/`dateField`).
const STEP_SUBMIT = { key: 'pending_approval', dateField: 'submitted_at' };
const STEP_APPROVED = { key: 'approved', dateField: 'approved_at' };
const STEP_DRAFT = { key: 'draft', dateField: 'created_at' };

// A realistic leftover audit_log array (same shape getAuditLog() used to return) -- present on the
// run object but must be completely ignored by both functions now.
const STALE_AUDIT_LOG = [
    { action: 'submit', performed_at: '2026-08-29 10:00:00' },
    { action: 'approve', performed_at: '2026-08-29 10:05:00' },
    { action: 'cancel', from_state: 'approved', performed_at: '2026-09-01 09:00:00' },
];

console.log('=== runLifecycleStepDate: column has a value ===');
check('reads run[step.dateField] directly',
    api.runLifecycleStepDate({ submitted_at: '2026-09-20 08:00:00' }, STEP_SUBMIT) === '2026-09-20 08:00:00');
check('draft step reads created_at the same way (no special-case branch anymore)',
    api.runLifecycleStepDate({ created_at: '2026-09-01 00:00:00' }, STEP_DRAFT) === '2026-09-01 00:00:00');

console.log('\n=== runLifecycleStepDate: column is NULL/missing ===');
check('null column -> null (same as the old fallback)',
    api.runLifecycleStepDate({ submitted_at: null }, STEP_SUBMIT) === null);
check('missing column entirely -> null', api.runLifecycleStepDate({}, STEP_APPROVED) === null);
check('empty-string column -> null (falsy, same as the old `|| null` fallback)',
    api.runLifecycleStepDate({ submitted_at: '' }, STEP_SUBMIT) === null);

console.log('\n=== runLifecycleStepDate: a leftover run.audit_log must NOT be read ===');
check('audit_log present with a matching action -- column value wins, audit_log ignored entirely',
    api.runLifecycleStepDate({ submitted_at: '2026-09-24 12:00:00', audit_log: STALE_AUDIT_LOG }, STEP_SUBMIT)
        === '2026-09-24 12:00:00');
check('audit_log present but column is null -- result is null, NOT the audit_log\'s own stale date',
    api.runLifecycleStepDate({ submitted_at: null, audit_log: STALE_AUDIT_LOG }, STEP_SUBMIT) === null);

console.log('\n=== runLifecycleCancelledFromState: cancelled_from_state present ===');
check('reads run.cancelled_from_state directly',
    api.runLifecycleCancelledFromState({ cancelled_from_state: 'approved' }) === 'approved');

console.log('\n=== runLifecycleCancelledFromState: cancelled_from_state absent ===');
check('falls back to \'draft\' (same as the old last-resort fallback)',
    api.runLifecycleCancelledFromState({}) === 'draft');
check('null cancelled_from_state also falls back to \'draft\'',
    api.runLifecycleCancelledFromState({ cancelled_from_state: null }) === 'draft');

console.log('\n=== runLifecycleCancelledFromState: a leftover run.audit_log must NOT be read ===');
check('audit_log\'s own last cancel entry (from_state: \'approved\') is ignored -- cancelled_from_state column wins',
    api.runLifecycleCancelledFromState({ cancelled_from_state: 'locked', audit_log: STALE_AUDIT_LOG }) === 'locked');
check('audit_log present but cancelled_from_state is null -- falls back to \'draft\', NOT the audit_log\'s from_state',
    api.runLifecycleCancelledFromState({ cancelled_from_state: null, audit_log: STALE_AUDIT_LOG }) === 'draft');

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
