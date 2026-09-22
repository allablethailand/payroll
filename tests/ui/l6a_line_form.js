/**
 * tiny-L6a measurement: the pencil on any row of the Calculation Breakdown modal opens ONE form,
 * and the cell editor it replaced is gone.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/l6a_line_form.js <PHPSESSID> <runToken>
 *
 * 2 cells (a logic/form round, per CLAUDE.md's context rules): 1400 th light, 430 th dark.
 * Everything it writes goes to the throwaway run tests/ui/mksession.php made -- never run 752.
 */
'use strict';
const { openContext, closeAll, ensureCellTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/l6a_line_form.js <PHPSESSID> <runToken> <employeeId>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const FORM = '#manualLineFormModal';

async function openBreakdown(page) {
    // The run's own table hides its row actions until the row is hovered, so this one is clicked
    // through the DOM -- it is how the round REACHES what it measures, not part of what it measures.
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    await page.evaluate((id) => document.querySelector(`.btn-view-breakdown[data-employee-id="${id}"]`).click(), employeeId);
    await page.waitForSelector('#breakdownLineOverrideWrap tr.lo-row', { timeout: 30000 });
    await page.waitForTimeout(400);
}
// Which sections the open form is really showing, and whether anything in it is disabled.
async function formState(page) {
    return page.evaluate((sel) => {
        const m = document.querySelector(sel);
        const vis = (s) => { const e = m.querySelector(s); return !!e && e.offsetParent !== null; };
        const btn = m.querySelector('#btnSaveManualLine');
        return {
            open: m.classList.contains('show'),
            title: (m.querySelector('.modal-title') || {}).textContent.trim(),
            item: vis('#manualLineItemCol'),
            amount: vis('#manualLineAmountCol'),
            note: vis('.manual-line-note-row'),
            payeeEdit: vis('#manualLinePayeeTypeWrapper'),
            payeeReadonly: vis('#manualLinePayeeReadonly'),
            computedHint: vis('#manualLineComputedHint'),
            useComputed: vis('#btnLineFormUseComputed'),
            // Every control in the modal that is disabled while the form sits idle.
            disabled: Array.from(m.querySelectorAll('input,select,textarea,button'))
                .filter((e) => e.disabled && e.offsetParent !== null)
                .map((e) => e.id || e.className),
            saveText: btn ? btn.textContent.trim() : '',
            saveFits: btn ? btn.scrollWidth <= btn.clientWidth : true,
        };
    }, FORM);
}
async function clickPencil(page, code) {
    const sel = `#breakdownLineOverrideWrap tr.lo-row[data-item-code="${code}"] .lo-edit-btn`;
    await page.locator(sel).scrollIntoViewIfNeeded();
    await page.locator(sel).click();
    await page.waitForSelector(`${FORM}.show`, { timeout: 15000 });
    await page.waitForTimeout(600);
}
async function closeForm(page) {
    await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        window.jQuery(el).data('dirtyGuardBypass', true);
        window.bootstrap.Modal.getInstance(el).hide();
    }, FORM);
    await page.waitForTimeout(400);
}

async function runCell(opts) {
    const label = `${opts.width}x${opts.height} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    // 2026-09-21, a0: a cell named "dark" was measuring LIGHT -- see ensureCellTheme() in harness.js.
    if (!await ensureCellTheme(page, opts.colorScheme, { label, when: 'first load', check, log: console.log })) return report();
    await openBreakdown(page);

    // 1 -- the inline cell editor is gone: no input of any kind inside a money cell, on any row.
    const inlineInputs = await page.locator('#breakdownLineOverrideWrap .lo-amount-cell input').count();
    check(`${label}: inline inputs in .lo-amount-cell = 0`, inlineInputs === 0, String(inlineInputs));
    const rows = await page.evaluate(() => Array.from(document.querySelectorAll('#breakdownLineOverrideWrap tr.lo-row'))
        .filter((r) => !r.classList.contains('d-none'))
        .map((r) => ({
            code: r.getAttribute('data-item-code'),
            type: r.getAttribute('data-line-type'),
            amount: r.getAttribute('data-amount'),
            pencil: !!r.querySelector('.lo-edit-btn'),
            buttons: r.querySelectorAll('.lo-action-cell button').length,
        })));
    check(`${label}: every editable row carries exactly one action button`,
        rows.filter((r) => r.pencil).every((r) => r.buttons === 1),
        JSON.stringify(rows.map((r) => r.buttons)));

    // 2 -- the form, opened from a calculated row (base salary) and from a hand-added one.
    const baseRow = rows.find((r) => r.code === '__base_salary__' && r.pencil);
    check(`${label}: the base salary row is there to open`, !!baseRow);
    if (baseRow) {
        check(`${label}: the pencil is visible without hovering`,
            await page.locator('#breakdownLineOverrideWrap tr.lo-row .lo-edit-btn').first().isVisible());
        await clickPencil(page, '__base_salary__');
        const st = await formState(page);
        check(`${label}: base -> form opens on the item's own name`, st.open && st.title.length > 0, st.title);
        check(`${label}: base -> amount + note only, no item picker, no destination section`,
            st.amount && st.note && !st.item && !st.payeeEdit && !st.payeeReadonly,
            JSON.stringify({ item: st.item, payeeEdit: st.payeeEdit, payeeReadonly: st.payeeReadonly }));
        check(`${label}: base -> the calculated figure is stated under the amount`, st.computedHint);
        check(`${label}: base -> nothing in the form is disabled`, st.disabled.length === 0, st.disabled.join(','));
        await closeForm(page);
    }
    const ped = rows.find((r) => r.pencil && r.code !== '__base_salary__' && r.type !== 'statutory');
    if (ped) {
        await clickPencil(page, ped.code);
        const st = await formState(page);
        check(`${label}: ${ped.code} -> form opens, no item picker`, st.open && !st.item);
        check(`${label}: ${ped.code} -> at most one destination section, never both`,
            !(st.payeeEdit && st.payeeReadonly));
        check(`${label}: ${ped.code} -> nothing disabled`, st.disabled.length === 0, st.disabled.join(','));
        await closeForm(page);
    } else {
        console.log(`  SKIP  ${label}: this run has no non-statutory calculated line besides base salary`);
    }
    const manualPencil = page.locator('#breakdownManualLines .manual-line-edit-btn').first();
    if (await manualPencil.count()) {
        await manualPencil.scrollIntoViewIfNeeded();
        await manualPencil.click();
        await page.waitForSelector(`${FORM}.show`, { timeout: 15000 });
        await page.waitForTimeout(700);
        const st = await formState(page);
        check(`${label}: manual -> the item picker IS part of this form`, st.open && st.item, JSON.stringify(st));
        check(`${label}: manual -> nothing disabled`, st.disabled.length === 0, st.disabled.join(','));
        await closeForm(page);
    } else {
        console.log(`  SKIP  ${label}: no hand-added line on this run`);
    }

    // 3 -- edit the base amount through the form, then put it back with the left-slot button.
    if (baseRow) {
        const before = baseRow.amount;
        /* 2026-09-21, a0, real damage found by reading what this round had written: the figure used
           to be `before + 111`, so every run built on the last one -- and because the typing below
           was APPENDING rather than replacing, the override on the fixture run climbed
           28,500 -> 2,850,028,611 -> the decimal(15,2) ceiling in 2 rounds. A target that does not
           depend on the current value cannot ratchet, and 2 fixed figures still make the assertion
           ("the table shows the NEW figure") mean what it says. */
        const next = (parseFloat(before.replace(/,/g, '')) || 0) === 31000 ? '32000' : '31000';
        await clickPencil(page, '__base_salary__');
        /* `page.fill()` is not enough on a `.money-input`: initMoneyInputs() reformats on every
           `input` event and puts the caret back at the end, so the characters land AFTER what is
           already there ("28500" + "28611" -> 2850028611, measured in the history table). Select
           all, delete, then type -- and read the field back before saving, so a money input that
           starts swallowing keystrokes again fails HERE instead of writing a figure nobody typed. */
        const $amount = page.locator('#manualLineAmount');
        await $amount.click();
        await $amount.press('Control+a');
        await $amount.press('Delete');
        await $amount.pressSequentially(next, { delay: 20 });
        const typed = await page.inputValue('#manualLineAmount');
        check(`${label}: the amount field holds exactly what was typed`,
            typed.replace(/,/g, '') === next, `${JSON.stringify(typed)} vs ${next}`);
        await page.locator('#btnSaveManualLine').click();
        // Caught mid-flight: the button says what it is doing, in the app's own words, without
        // outgrowing itself.
        const loading = await page.evaluate(() => {
            const b = document.querySelector('#btnSaveManualLine');
            return b ? { text: b.textContent.trim(), fits: b.scrollWidth <= b.clientWidth, disabled: b.disabled } : null;
        });
        check(`${label}: saving -> the button says so and is disabled`,
            !!loading && loading.disabled && /กำลังบันทึก|Saving/.test(loading.text), JSON.stringify(loading));
        check(`${label}: saving -> the label does not overflow its button`, !loading || loading.fits,
            loading ? String(loading.text.length) : '');
        await page.waitForSelector(`${FORM}.show`, { state: 'hidden', timeout: 30000 });
        /* Wait for the ROW to change, not for a fixed number of milliseconds. A save reloads the
           whole table (loadSyncLineOverridesRd + loadRunDetail), and at 430 that took longer than
           the 1500ms this used to sleep -- so the round read the old figure and reported a write
           that had in fact landed (confirmed against the history table: 31000 -> 32000 was there).
           A timeout here still fails the check, but only when nothing really changed. */
        let after = before;
        try {
            await page.waitForFunction((args) => {
                const r = document.querySelector('#breakdownLineOverrideWrap tr.lo-row[data-item-code="__base_salary__"]');
                return !!r && r.getAttribute('data-amount') !== args.before;
            }, { before }, { timeout: 15000 });
        } catch (e) { /* leave `after` at `before` -- the check below is what reports it */ }
        after = await page.getAttribute('#breakdownLineOverrideWrap tr.lo-row[data-item-code="__base_salary__"]', 'data-amount');
        check(`${label}: the table shows the new figure`, after !== before, `${before} -> ${after}`);

        // ...and the left-slot button, which only exists now that the row carries an override.
        await clickPencil(page, '__base_salary__');
        const st2 = await formState(page);
        /* 2026-09-21, a0: it does NOT, and deliberately so. 974b1ac4 deleted
           #btnLineFormUseComputed along with the dropdown and the history modal: "back to what the
           system calculated" is the top row of the row's own history panel now, where every other
           value that line has ever held is already listed -- one way back, not two (rules.md 5).
           So the assertion is the other way round, and it checks that the ONE way back is really
           there on the row rather than just that the old one is gone. */
        check(`${label}: the form offers no second way back to the calculated value`, !st2.useComputed);
        const historyWayBack = await page.evaluate(() => !!document.querySelector(
            '#breakdownLineOverrideWrap tr.lo-row[data-item-code="__base_salary__"] .lo-history-toggle'));
        check(`${label}: ...because the row's own history is the one way back`, historyWayBack);
        if (st2.useComputed) {
            await page.locator('#btnLineFormUseComputed').click();
            await page.waitForSelector('.swal2-confirm', { timeout: 15000 });
            await page.locator('.swal2-confirm').click();
            await page.waitForTimeout(2500);
            // The row is back on the engine's own figure -- which is NOT necessarily what it read
            // before this round started (it may have been carrying an override already). What is
            // true either way is that it no longer carries one at all, and that the button offering
            // to drop it is gone with it.
            const after2 = await page.evaluate(() => {
                const r = document.querySelector('#breakdownLineOverrideWrap tr.lo-row[data-item-code="__base_salary__"]');
                return { amount: r.getAttribute('data-amount'), action: r.getAttribute('data-orig-action') || '' };
            });
            check(`${label}: the override is dropped, the row is back on the calculated figure`,
                after2.action === '' && after2.amount !== after, `${after} -> ${after2.amount} (action="${after2.action}")`);
            await clickPencil(page, '__base_salary__');
            const st3 = await formState(page);
            check(`${label}: with no override left, the form no longer offers to drop one`, !st3.useComputed);
            await closeForm(page);
        } else {
            await closeForm(page);
        }
    }

    // 4 -- nothing sideways, nothing in the console.
    // When it is not 0, say WHAT is wider than the page -- a bare number sends the next round
    // hunting for it by hand.
    const overflowInfo = await page.evaluate(() => {
        const doc = document.documentElement;
        const over = Math.max(0, doc.scrollWidth - doc.clientWidth);
        if (!over) return { over: 0, widest: [] };
        const limit = doc.clientWidth;
        const widest = Array.from(document.querySelectorAll('body *'))
            // `position: fixed` is in the viewport's own coordinate space, not the document's, so a
            // drawer parked at `right: -380px` sits past the edge without being what the document
            // scrolls to reach. Ranking it first sends the reader after the wrong element.
            .filter(el => getComputedStyle(el).position !== 'fixed')
            .map((el) => ({ el, r: el.getBoundingClientRect() }))
            .filter(x => x.r.width > 0 && x.r.right > limit + 0.5)
            .sort((a, b) => b.r.right - a.r.right)
            .slice(0, 5)
            .map(x => ({
                tag: x.el.tagName.toLowerCase(),
                cls: String(x.el.className || '').slice(0, 60),
                id: x.el.id || '',
                right: Math.round(x.r.right),
                width: Math.round(x.r.width),
            }));
        return { over, limit, widest };
    });
    const overflow = overflowInfo.over;
    if (overflow) console.log(`  MEASURED  horizontal overflow ${overflow}px past ${overflowInfo.limit}px: ${JSON.stringify(overflowInfo.widest)}`);
    check(`${label}: horizontal page overflow = 0`, overflow === 0, String(overflow));
    const r = report();
    check(`${label}: console errors = 0`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors).slice(0, 3)));
    console.log(`  blocked: preference-save ${r.blockedPreferenceSaves}, recalculate ${r.blockedRecalculates}`);
    return r;
}

(async () => {
    const reports = [];
    reports.push(await runCell({ width: 1400, height: 950, lang: 'th', colorScheme: 'light' }));
    reports.push(await runCell({ width: 430, height: 932, lang: 'th', colorScheme: 'dark' }));
    await closeAll();
    console.log(`\nPassed: ${passed}, Failed: ${failed}`);
    console.log('blockedPreferenceSaves total: ' + reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0));
    console.log('blockedRecalculates total: ' + reports.reduce((a, r) => a + r.blockedRecalculates, 0));
    process.exit(failed === 0 ? 0 : 1);
})().catch(async (e) => { console.error(e); await closeAll(); process.exit(1); });
