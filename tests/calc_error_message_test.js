/**
 * 2026-09-21, 3e-2a -- calcErrorMessageRd()/calcErrorItemsRd()/calcAdvisoryCodesRd(), the 3 helpers
 * that turn `payroll_run_details.calc_errors` into what an admin reads.
 *
 * What is locked here is the round's own reason for existing: no code an admin can see reaches the
 * screen as a machine identifier. Every code the engine can push, plus the derived one this round
 * adds, plus a deliberately unknown one, has to come back as a real sentence in BOTH languages --
 * and "a real sentence" is checked as "not equal to the code, and not equal to any lang KEY either",
 * so a missing translation cannot pass by returning its own key name.
 *
 * The code list is not invented here: it is every code pushed in PayrollRunModel::recalculate() and
 * SyncPayResolver::resolve(), read off those files when this was written (see the round's own
 * decision doc for the grep). A code added to either without a sentence here fails this test.
 *
 * Run with: node tests/calc_error_message_test.js
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const formatSource = fs.readFileSync(path.join(root, 'public', 'js', 'format-helpers.js'), 'utf8');
const LANG = {
    th: JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'th.json'), 'utf8')),
    en: JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'en.json'), 'utf8')),
};

function fn(text, name) {
    const startIdx = text.indexOf('function ' + name + '(');
    if (startIdx === -1) throw new Error(name + '() not found -- renamed/removed?');
    let depth = 0;
    let i = text.indexOf('{', startIdx);
    for (; i < text.length; i++) {
        if (text[i] === '{') depth++;
        else if (text[i] === '}' && --depth === 0) break;
    }
    if (depth !== 0) throw new Error(name + '() -- no matching closing brace');
    return text.slice(startIdx, i + 1);
}

// The 4 functions under test, taken from the real file -- never a copy pasted into this test.
// langData is the only global any of them touches (confirmed: no jQuery, no DOM), so a plain `let`
// here is the whole environment they need.
const api = new Function([
    'let langData = {};',
    fn(formatSource, 'calcErrorMessageRd'),
    fn(formatSource, 'calcErrorMessagesRd'),
    fn(formatSource, 'calcErrorItemsRd'),
    fn(formatSource, 'calcAdvisoryCodesRd'),
    'return { setLang: function (d) { langData = d; },',
    '  calcErrorMessageRd: calcErrorMessageRd, calcErrorMessagesRd: calcErrorMessagesRd,',
    '  calcErrorItemsRd: calcErrorItemsRd, calcAdvisoryCodesRd: calcAdvisoryCodesRd };',
].join('\n'))();

let passed = 0;
let failed = 0;
function check(label, cond, detail) {
    if (cond) { passed++; console.log('  PASS  ' + label); return; }
    failed++;
    console.log('  FAIL  ' + label + (detail !== undefined ? ' -- ' + detail : ''));
}

/* ---------- 1. every code an admin can see becomes a sentence, in both languages ---------- */
// Exact-match codes: PayrollRunModel.php:3442/3445/3517/3541/3619/3890/4073/4674 + SyncPayResolver.php:460
// (salary_type_hourly_not_supported is retired as a NEW push but still translatable -- old runs carry it).
const EXACT_CODES = [
    'profile_incomplete', 'missing_base_salary', 'no_manual_lines', 'daily_salary_no_shift_pattern',
    'salary_type_hourly_not_supported', 'hourly_salary_no_attendance_data', 'sync_actual_days_no_data',
    'no_attendance_data_this_period', 'ot_not_calculated_ineligible', 'mixed_payment_lines_mismatch',
];
// Prefixed codes: PayrollRunModel.php:4629/4193, SyncPayResolver.php:821/438, + this round's derived one.
const PREFIXED_CODES = [
    'no_rate_configured:TH_SSO',
    'transfer_payee_not_in_run:LOAN_REPAY',
    'working_days_fallback_with_attendance_deduction:late',
    'missing_ot_rate_weekday', 'missing_ot_rate_weekend', 'missing_ot_rate_holiday',
    'prorate_zero_days:0/30',
];
// Neither: a scope Origami does not send today, and a code nothing pushes at all.
const UNKNOWN_CODES = ['missing_ot_rate_nightshift', 'zz_fixture_unknown'];
const ALL_CODES = EXACT_CODES.concat(PREFIXED_CODES, UNKNOWN_CODES);

// A message equal to one of these means the key is missing from the json and a fallback returned the
// key's own name -- which would otherwise look like a perfectly good translation to a string test.
const ALL_KEYS = new Set(Object.keys(LANG.th).concat(Object.keys(LANG.en)));

['th', 'en'].forEach(function (lang) {
    console.log('\n=== 1.' + lang + ': every code -> a sentence (' + ALL_CODES.length + ' codes) ===');
    api.setLang(LANG[lang]);
    ALL_CODES.forEach(function (code) {
        const msg = api.calcErrorMessageRd(code);
        check(lang + ': "' + code + '" is not returned raw', msg !== code, msg);
        check(lang + ': "' + code + '" is not a lang key name', !ALL_KEYS.has(msg), msg);
        check(lang + ': "' + code + '" is a non-empty string', typeof msg === 'string' && msg.trim().length > 0, JSON.stringify(msg));
    });
});

/* ---------- 2. the codes that carry a value actually SHOW that value ---------- */
console.log('\n=== 2. value-carrying codes interpolate ===');
api.setLang(LANG.th);
check('no_rate_configured:TH_SSO names the item', api.calcErrorMessageRd('no_rate_configured:TH_SSO').indexOf('TH_SSO') !== -1);
check('transfer_payee_not_in_run:LOAN_REPAY names the item', api.calcErrorMessageRd('transfer_payee_not_in_run:LOAN_REPAY').indexOf('LOAN_REPAY') !== -1);
check('working_days_fallback...:late names the event', api.calcErrorMessageRd('working_days_fallback_with_attendance_deduction:late').indexOf('late') !== -1);
check('prorate_zero_days:0/30 says both 0 and 30', (function () {
    const m = api.calcErrorMessageRd('prorate_zero_days:0/30');
    return m.indexOf('0') !== -1 && m.indexOf('30') !== -1;
})());
check('an unknown OT scope falls to the {scope} template, naming the scope',
    api.calcErrorMessageRd('missing_ot_rate_nightshift').indexOf('nightshift') !== -1,
    api.calcErrorMessageRd('missing_ot_rate_nightshift'));
check('...and the 3 real scopes each use their OWN sentence, not that template',
    ['weekday', 'weekend', 'holiday'].every(function (sc) {
        return api.calcErrorMessageRd('missing_ot_rate_' + sc) === LANG.th['calc_error_missing_ot_rate_' + sc];
    }));
check('a wholly unknown code reads as the one neutral sentence',
    api.calcErrorMessageRd('zz_fixture_unknown') === LANG.th['calc_error_unknown'],
    api.calcErrorMessageRd('zz_fixture_unknown'));

/* ---------- 3. calcErrorItemsRd() keeps the code next to its sentence ---------- */
console.log('\n=== 3. calcErrorItemsRd() ===');
const items = api.calcErrorItemsRd(['profile_incomplete', 'zz_fixture_unknown']);
check('one item per code, in order', items.length === 2 && items[0].code === 'profile_incomplete' && items[1].code === 'zz_fixture_unknown');
check('each item carries the same sentence calcErrorMessageRd() gives',
    items.every(function (it) { return it.message === api.calcErrorMessageRd(it.code); }));
check('the raw code survives on the item even when the sentence hides it',
    items[1].code === 'zz_fixture_unknown' && items[1].message.indexOf('zz_fixture_unknown') === -1);
check('null/undefined -> empty list, never a throw',
    api.calcErrorItemsRd(null).length === 0 && api.calcErrorItemsRd(undefined).length === 0);
check('calcErrorMessagesRd() still returns plain strings (signature unchanged)', (function () {
    const msgs = api.calcErrorMessagesRd(['profile_incomplete']);
    return Array.isArray(msgs) && typeof msgs[0] === 'string' && msgs[0] === api.calcErrorMessageRd('profile_incomplete');
})());

/* ---------- 4. calcAdvisoryCodesRd(): when prorate 0 becomes a note ---------- */
console.log('\n=== 4. calcAdvisoryCodesRd() ===');
const adv = function (row) { return api.calcAdvisoryCodesRd(row); };
const hasProrate = function (codes) { return codes.some(function (c) { return c.indexOf('prorate_zero_days:') === 0; }); };

check('prorate_days 0 adds the note', hasProrate(adv({ calc_warnings: [], prorate_days: 0, prorate_total_days: 30 })));
check('...and it carries 0 and the total', adv({ calc_warnings: [], prorate_days: 0, prorate_total_days: 30 })[0] === 'prorate_zero_days:0/30');
check('null does NOT add it', !hasProrate(adv({ calc_warnings: [], prorate_days: null, prorate_total_days: null })));
check('undefined does NOT add it', !hasProrate(adv({ calc_warnings: [] })));
check('empty string does NOT add it', !hasProrate(adv({ calc_warnings: [], prorate_days: '', prorate_total_days: 30 })));
// PayrollRunModel::getDetails() does not cast prorate_days, so the real API sends "0"/"15" as
// STRINGS -- this pair is the regression guard for the bug that shipped past the first draft of this
// file (a typeof === 'number' check, which made the note fire on nothing at all in the real app).
check('the STRING "0" DOES add it -- this is what the API really sends',
    hasProrate(adv({ calc_warnings: [], prorate_days: '0', prorate_total_days: '30' })),
    JSON.stringify(adv({ calc_warnings: [], prorate_days: '0', prorate_total_days: '30' })));
check('...and the STRING "15" still does not', !hasProrate(adv({ calc_warnings: [], prorate_days: '15', prorate_total_days: '30' })));
check('a string total reads back into the code unchanged',
    adv({ calc_warnings: [], prorate_days: '0', prorate_total_days: '30' })[0] === 'prorate_zero_days:0/30');
check('15 does NOT add it', !hasProrate(adv({ calc_warnings: [], prorate_days: 15, prorate_total_days: 30 })));
check('a row already carrying hourly_salary_no_attendance_data does NOT get a second note',
    !hasProrate(adv({ calc_warnings: ['hourly_salary_no_attendance_data'], prorate_days: 0, prorate_total_days: 30 })),
    JSON.stringify(adv({ calc_warnings: ['hourly_salary_no_attendance_data'], prorate_days: 0, prorate_total_days: 30 })));
check('...but a DIFFERENT attendance warning does not suppress it',
    hasProrate(adv({ calc_warnings: ['no_attendance_data_this_period'], prorate_days: 0, prorate_total_days: 30 })));
check('existing warnings are kept, and the note is appended after them', (function () {
    const c = adv({ calc_warnings: ['ot_not_calculated_ineligible'], prorate_days: 0, prorate_total_days: 30 });
    return c.length === 2 && c[0] === 'ot_not_calculated_ineligible' && c[1].indexOf('prorate_zero_days:') === 0;
})());
check('the row object is never mutated', (function () {
    const row = { calc_warnings: ['ot_not_calculated_ineligible'], prorate_days: 0, prorate_total_days: 30 };
    adv(row);
    return row.calc_warnings.length === 1;
})());
check('a missing total still produces a usable sentence, never the word "undefined"', (function () {
    api.setLang(LANG.th);
    const c = adv({ calc_warnings: [], prorate_days: 0 });
    return c[0] === 'prorate_zero_days:0/?' && api.calcErrorMessageRd(c[0]).indexOf('undefined') === -1;
})());
check('null/undefined row -> empty list, never a throw', adv(null).length === 0 && adv(undefined).length === 0);
check('a row with no warnings and no proration is silent', adv({ calc_warnings: [], prorate_days: null }).length === 0);

console.log('');
console.log('-'.repeat(50));
console.log('Passed: ' + passed + ', Failed: ' + failed);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
