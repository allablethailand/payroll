/**
 * tiny-L6b measurement: one money column in the adjustments table, "ระบบ: x" as a sub-line under the
 * figure it is compared with, a worded add button, and the recurring line's 2-request save.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/l6b_table_and_form.js <PHPSESSID> <runToken> <employeeId>
 *
 * Needs the run made by `php tests/ui/mksession.php --with-recurring` -- the recurring line and the
 * base-salary override it creates are what half of this measures. Everything it writes goes to that
 * throwaway run -- never run 752.
 *
 * 4 cells (a layout round touches CSS, so all 4): 1400 th light/dark, 430 th light/dark, plus one
 * 1400 en light pass over the 3 strings this round changed.
 */
'use strict';
const { openContext, closeAll, ensureCellTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
const recurringCode = process.argv[5] || 'TINYL6TMP';
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/l6b_table_and_form.js <PHPSESSID> <runToken> <employeeId> [recurringItemCode]');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const FORM = '#manualLineFormModal';
const WRAP = '#breakdownLineOverrideWrap';

async function openBreakdown(page) {
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    await page.evaluate((id) => document.querySelector(`.btn-view-breakdown[data-employee-id="${id}"]`).click(), employeeId);
    await page.waitForSelector(`${WRAP} tr.lo-row`, { timeout: 30000 });
    await page.waitForTimeout(600);
}
async function clickPencil(page, code) {
    const sel = `${WRAP} tr.lo-row[data-item-code="${code}"] .lo-edit-btn`;
    await page.locator(sel).scrollIntoViewIfNeeded();
    await page.locator(sel).click();
    await page.waitForSelector(`${FORM}.show`, { timeout: 15000 });
    await page.waitForTimeout(800);
}
// Closing past the guard, for the steps where the guard is not what is being measured.
async function closeFormBypassingGuard(page) {
    await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        window.jQuery(el).data('dirtyGuardBypass', true);
        window.bootstrap.Modal.getInstance(el).hide();
    }, FORM);
    await page.waitForTimeout(500);
}

async function runCell(opts) {
    const label = `${opts.width}x${opts.height} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    await ctx.context.addInitScript((lang) => {
        try { localStorage.setItem('preferred_language', lang); } catch (e) { /* private mode */ }
    }, opts.lang);
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    // 2026-09-21, a0: a cell named "dark" was measuring LIGHT -- see ensureCellTheme() in harness.js.
    if (!await ensureCellTheme(page, opts.colorScheme, { label, when: 'first load', check, log: console.log })) return report();
    await openBreakdown(page);

    // 1 -- B3: one money column, and every row agrees with the header about that.
    const grid = await page.evaluate((wrap) => {
        const table = document.querySelector(wrap + ' table.lo-table');
        const ths = Array.from(table.querySelectorAll('thead th'));
        const rows = Array.from(table.querySelectorAll('tr.lo-row'));
        return {
            headCols: ths.length,
            headMoney: ths.filter((t) => t.classList.contains('col-money')).length,
            computedCol: table.querySelectorAll('.lo-computed-col, .lo-computed-cell').length,
            rowMoneyCounts: Array.from(new Set(rows.map((r) => r.querySelectorAll('td.col-money').length))),
            groupSpans: Array.from(new Set(Array.from(table.querySelectorAll('tr.lo-group td, tr.lo-hidden-row td'))
                .map((td) => td.getAttribute('colspan')))),
        };
    }, WRAP);
    check(`${label}: money columns in the table = 1`, grid.headMoney === 1, String(grid.headMoney));
    check(`${label}: every row has exactly 1 money cell`,
        grid.rowMoneyCounts.length === 1 && grid.rowMoneyCounts[0] === 1, JSON.stringify(grid.rowMoneyCounts));
    check(`${label}: the retired calculated column is gone from the DOM`, grid.computedCol === 0, String(grid.computedCol));
    check(`${label}: group/hidden rows span the same ${grid.headCols} columns the header declares`,
        grid.groupSpans.every((s) => Number(s) === grid.headCols), JSON.stringify(grid.groupSpans));

    /* 2 -- "ระบบ: x" only under a row that somebody really replaced something on.
       2026-09-21, a0: "replaced something" is 2 things, not 1. This round (tiny-L6b) was written
       before 4b, which gave the 2 tri-state rows a sub-line in the SAME slot, from the SAME template
       (`line_override_computed_inline`, statutoryExemptionTagHtmlRd()) -- but carrying a WORD
       ("ระบบ: คำนวณ") instead of a figure, because what was replaced on such a row is a yes/no.
       Splitting them by what the ROW declares keeps both rules real: an amount override prints a
       figure, a chosen answer prints a word, and a row nobody touched prints nothing. */
    const subLines = await page.evaluate((wrap) => {
        return Array.from(document.querySelectorAll(wrap + ' tr.lo-row')).map((r) => {
            const tag = r.querySelector('td.lo-amount-cell .payslip-line-tag');
            return {
                code: r.getAttribute('data-item-code'),
                action: r.getAttribute('data-orig-action') || '',
                answered: !!r.getAttribute('data-exemption-changed'),
                amount: r.getAttribute('data-amount'),
                sub: tag ? tag.textContent.trim() : null,
            };
        });
    }, WRAP);
    const overridden = subLines.filter((r) => r.action !== '');
    const answered = subLines.filter((r) => r.action === '' && r.answered);
    const untouched = subLines.filter((r) => r.action === '' && !r.answered);
    check(`${label}: the run has an overridden row to measure`, overridden.length > 0, JSON.stringify(subLines.map(r => r.code)));
    check(`${label}: every overridden row carries the "ระบบ" sub-line, as a figure`,
        overridden.every((r) => r.sub && /\d/.test(r.sub)), JSON.stringify(overridden));
    check(`${label}: a row whose tri-state answer was chosen carries it as a WORD, not a figure`,
        answered.every((r) => r.sub && !/\d/.test(r.sub)), JSON.stringify(answered));
    check(`${label}: no untouched row carries one`,
        untouched.every((r) => r.sub === null), JSON.stringify(untouched.filter((r) => r.sub !== null)));
    check(`${label}: the sub-line is not just the row's own figure repeated`,
        overridden.every((r) => r.sub.indexOf(r.amount) === -1), JSON.stringify(overridden));

    /* 3 -- B5: a worded button, outline, no icon.
       2026-09-21, a0: `#breakdownManualLines .manual-line-add-btn` has 0 occurrences left in the
       app. 4a-2 made a hand-added line a ROW of the one slip table and retired the card block that
       used to hold them, and 2026-09-18 follow-up 3 moved the opener onto the head of the group it
       adds to (`.lo-add-line-btn`, one per manual group). The button this round is about is that
       one -- same worded-link tier, same job -- so the selector follows it. A stale selector is why
       this whole round died on its first cell: `.first().click()` waited 30s for an element that
       cannot exist any more, and every cell after it was unmeasured, not passing. */
    const addBtn = await page.evaluate((wrap) => {
        const b = document.querySelector(wrap + ' .lo-add-line-btn');
        if (!b) return null;
        const cs = getComputedStyle(b);
        const primary = getComputedStyle(document.documentElement).getPropertyValue('--c-primary').trim();
        const probe = document.createElement('span');
        probe.style.color = primary;
        document.body.appendChild(probe);
        const primaryRgb = getComputedStyle(probe).color;
        probe.remove();
        return {
            text: b.textContent.trim(),
            icons: b.querySelectorAll('i').length,
            round: b.classList.contains('btn-icon') || b.classList.contains('btn-circle-action'),
            color: cs.color,
            primaryRgb: primaryRgb,
            bg: cs.backgroundColor,
            count: document.querySelectorAll(wrap + ' .lo-add-line-btn').length,
            // Both openers sit on a group HEAD now, not on a block of their own below the table.
            onGroupHead: Array.from(document.querySelectorAll(wrap + ' .lo-add-line-btn'))
                .filter(x => !!x.closest('tr.lo-group')).length,
        };
    }, WRAP);
    check(`${label}: the add button exists on both columns`, addBtn && addBtn.count === 2, JSON.stringify(addBtn && addBtn.count));
    check(`${label}: ...on the head of the group it adds to`, addBtn && addBtn.onGroupHead === 2,
        JSON.stringify(addBtn && addBtn.onGroupHead));
    if (addBtn) {
        check(`${label}: it is worded, not a circle`, addBtn.text.length > 0 && !addBtn.round, JSON.stringify(addBtn.text));
        check(`${label}: no icon inside it`, addBtn.icons === 0, String(addBtn.icons));
        check(`${label}: colour = --c-primary`, addBtn.color === addBtn.primaryRgb, `${addBtn.color} vs ${addBtn.primaryRgb}`);
        check(`${label}: background transparent (outline, not solid)`,
            addBtn.bg === 'rgba(0, 0, 0, 0)' || addBtn.bg === 'transparent', addBtn.bg);
    }

    // 4 -- B5: it opens the form in new-manual mode (no line, no override).
    await page.locator(`${WRAP} .lo-add-line-btn`).first().click();
    await page.waitForSelector(`${FORM}.show`, { timeout: 15000 });
    await page.waitForTimeout(600);
    const newMode = await page.evaluate((sel) => {
        const m = document.querySelector(sel);
        const vis = (s) => { const e = m.querySelector(s); return !!e && e.offsetParent !== null; };
        return {
            item: vis('#manualLineItemCol'),
            amountEmpty: (m.querySelector('#manualLineAmount') || {}).value === '',
            hint: vis('#manualLineComputedHint'),
            save: (m.querySelector('#btnSaveManualLine') || {}).textContent.trim(),
        };
    }, FORM);
    check(`${label}: it opens the item picker (a new line, not an override)`, newMode.item);
    check(`${label}: on an empty amount`, newMode.amountEmpty, JSON.stringify(newMode.amountEmpty));
    check(`${label}: with no calculated hint (nothing has been calculated for a line that does not exist)`, !newMode.hint);
    check(`${label}: and its submit button carries the same word the opener does`,
        newMode.save === addBtn.text, `${JSON.stringify(newMode.save)} vs ${JSON.stringify(addBtn.text)}`);
    await closeFormBypassingGuard(page);

    // 5 -- the dirty guard on a recurring line: opened and closed untouched, it must not ask.
    const hasRecurring = await page.locator(`${WRAP} tr.lo-row[data-item-code="${recurringCode}"]`).count();
    check(`${label}: the run has the recurring fixture line (${recurringCode})`, hasRecurring === 1, String(hasRecurring));
    if (hasRecurring === 1) {
        await clickPencil(page, recurringCode);
        // Long enough for BOTH async defaults the BACKLOG names (the default company account and the
        // saved-destinations lookup) to have landed -- the whole point is what happens AFTER they do.
        await page.waitForTimeout(2500);
        await page.evaluate((sel) => window.bootstrap.Modal.getInstance(document.querySelector(sel)).hide(), FORM);
        await page.waitForTimeout(1200);
        const asked = await page.locator('.swal2-container').count();
        check(`${label}: opened and closed untouched, the guard does not ask`, asked === 0, `swal containers: ${asked}`);
        if (asked > 0) {
            await page.locator('.swal2-confirm').click();
            await page.waitForTimeout(600);
        }
        await page.waitForSelector(`${FORM}.show`, { state: 'hidden', timeout: 15000 }).catch(() => {});
    }

    const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
    check(`${label}: horizontal page overflow = 0`, overflow === 0, String(overflow));
    const r = report();
    check(`${label}: console errors = 0`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors).slice(0, 3)));
    console.log(`  blocked: preference-save ${r.blockedPreferenceSaves}, recalculate ${r.blockedRecalculates}`);
    return r;
}

/* The two steps that WRITE, run once rather than per cell: the recurring line's 2-request save, and
   "use the calculated value" landing on the figure the sub-line promised. Both change the run. */
async function runWriteCell() {
    const label = 'writes 1400 th light';
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    await ctx.context.addInitScript(() => {
        try { localStorage.setItem('preferred_language', 'th'); } catch (e) { /* private mode */ }
    });
    const posted = [];
    page.on('request', (req) => {
        if (req.method() === 'POST' && req.url().indexOf('/api/payroll-run.') !== -1) {
            posted.push(req.url().split('/api/')[1].split('?')[0]);
        }
    });
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await openBreakdown(page);

    // (a) the recurring line: change the amount AND the destination in one save.
    if (await page.locator(`${WRAP} tr.lo-row[data-item-code="${recurringCode}"]`).count() === 1) {
        const before = await page.getAttribute(`${WRAP} tr.lo-row[data-item-code="${recurringCode}"]`, 'data-amount');
        await clickPencil(page, recurringCode);
        await page.waitForTimeout(2000);
        posted.length = 0;
        // fill('') does not clear a money input (initMoneyInputs restores the formatted value on the
        // blur fill() ends with -- measured: it read back 500,777.00). Select-all then type, the way
        // a person does it.
        /* 2026-09-21, a0: the figure ALTERNATES instead of being pinned at 777. This cell writes,
           so a second run found the line already at 777 and reported "the table shows the new
           figure -- 777.00 -> 777.00" -- a green step failing for the one reason that is not a
           regression. Two values that swap make the assertion mean what it says on every run. */
        const target = String(before).indexOf('777') !== -1 ? '888' : '777';
        await page.locator('#manualLineAmount').click();
        await page.keyboard.press('Control+A');
        await page.keyboard.type(target);
        await page.locator('#manualLineAmount').blur();
        await page.waitForTimeout(300);
        const typed = await page.inputValue('#manualLineAmount');
        check(`${label}: the amount field holds what was typed`, typed.replace(/[^\d]/g, '') === target + '00', typed);
        // A real destination change. The company/record sub-question is NOT one for a recurring line
        // (its endpoint has no "no payee" value to store, so the choice stays 'company' either way --
        // measured) -- moving it to an employee is the smallest change the form really accepts.
        // A segmented control: the radio itself is the visually-hidden input (1px, opacity 0), so
        // the label is the real target -- the same thing a person clicks. An external destination,
        // not another employee: the employee picker's first entries have no bank account on file, and
        // the form rightly refuses to save then (measured) -- which would be testing that rule, not
        // this one.
        await page.locator('label[for="manualLinePayeeDestExternal"]').click();
        await page.waitForTimeout(800);
        await page.locator('#manualLineDestModeToggle label[for="manualLineDestModeSaved"]').click().catch(() => {});
        await page.waitForTimeout(400);
        await page.locator('#manualLineDestinationWrapper .select2-selection').first().click();
        await page.waitForSelector('.select2-results__option', { timeout: 15000 });
        await page.waitForTimeout(800);
        /* 2026-09-21, a0: pick one that is NOT the one already in force. The form sends only the
           halves that really changed (rules.md 9), so re-running this cell against a destination it
           had already set sent 1 request instead of 2 -- the ordering rule this step exists to
           measure was never exercised, and the failure said nothing about the app. */
        const OPTION = '.select2-results__option[role="option"]:not(.select2-results__message)';
        const unselected = page.locator(`${OPTION}[aria-selected="false"]`);
        const changesDestination = (await unselected.count()) > 0;
        await (changesDestination ? unselected : page.locator(OPTION)).first().click();
        console.log(`  destination: ${changesDestination ? 'picked a different one' : 'only one on file -- unchanged'}`);
        await page.waitForTimeout(1000);
        const saveEnabled = await page.evaluate(() => !document.querySelector('#btnSaveManualLine').disabled);
        check(`${label}: the picked destination leaves the form saveable`, saveEnabled,
            await page.getAttribute('#btnSaveManualLine', 'title'));
        await page.locator('#btnSaveManualLine').click();
        await page.waitForSelector(`${FORM}.show`, { state: 'hidden', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(2500);
        // 2 requests when the destination really moved, 1 when there was nowhere else to move it --
        // either way the amount goes first and nothing is sent in parallel.
        check(`${label}: the save sent ${changesDestination ? 2 : 1} request(s)`,
            posted.length === (changesDestination ? 2 : 1), JSON.stringify(posted));
        check(`${label}: the amount goes first, the destination second`,
            posted[0] === 'payroll-run.line-override.save'
            && (!changesDestination || posted[1] === 'payroll-run.recurring-deduction-destination-override.save'),
            JSON.stringify(posted));
        const after = await page.getAttribute(`${WRAP} tr.lo-row[data-item-code="${recurringCode}"]`, 'data-amount');
        check(`${label}: the table shows the new figure`, after !== before && after.indexOf(target) !== -1, `${before} -> ${after}`);
        const sub = await page.textContent(`${WRAP} tr.lo-row[data-item-code="${recurringCode}"] td.lo-amount-cell .payslip-line-tag`).catch(() => null);
        check(`${label}: ...and now carries the "ระบบ" sub-line it did not have before`, !!sub && /\d/.test(sub), JSON.stringify(sub));
    }

    // (b) THE one that closes the BACKLOG's own report: what the sub-line says is what Use-calculated
    // gives back. Hint said 28,500.00 and the restore gave 0.00 -- that is the bug.
    /* 2026-09-21, a0: this step DROPS an override, so running it twice leaves nothing to drop --
       the second run reported a hint of "0.00" against an empty sub-line and no baseline to press,
       which is the fixture's state, not a regression. Put one there first when the row has none, so
       the cell starts from the same place whatever ran before it. */
    const hasOverride = await page.evaluate((wrap) => {
        const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
        return !!r && (r.getAttribute('data-orig-action') || '') !== '';
    }, WRAP);
    // What the run looked like before this step, so it can be put back that way (see the end of it).
    const overrideFound = hasOverride;
    if (!hasOverride) {
        console.log(`  (no base-salary override on this run -- putting one there first)`);
        await clickPencil(page, '__base_salary__');
        await page.locator('#manualLineAmount').click();
        await page.keyboard.press('Control+A');
        await page.keyboard.type('31000');
        await page.locator('#manualLineAmount').blur();
        await page.waitForTimeout(300);
        await page.locator('#btnSaveManualLine').click();
        await page.waitForSelector(`${FORM}.show`, { state: 'hidden', timeout: 30000 }).catch(() => {});
        await page.waitForFunction((wrap) => {
            const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
            return !!r && (r.getAttribute('data-orig-action') || '') !== '';
        }, WRAP, { timeout: 20000 }).catch(() => {});
    }
    check(`${label}: the row carries an override to put back`, await page.evaluate((wrap) => {
        const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
        return !!r && (r.getAttribute('data-orig-action') || '') !== '';
    }, WRAP));
    await clickPencil(page, '__base_salary__');
    const promised = await page.evaluate(() => {
        const h = document.querySelector('#manualLineComputedHint');
        return h && h.offsetParent !== null ? h.textContent.replace(/[^\d.,]/g, '').trim() : null;
    });
    const rowPromised = await page.evaluate((wrap) => {
        const t = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"] td.lo-amount-cell .payslip-line-tag');
        return t ? t.textContent.replace(/[^\d.,]/g, '').trim() : null;
    }, WRAP);
    check(`${label}: the form hint and the row's sub-line are the same figure`, promised === rowPromised,
        `${JSON.stringify(promised)} vs ${JSON.stringify(rowPromised)}`);
    /* 2026-09-21, a0: the surface moved, the question did not. 974b1ac4 deleted
       #btnLineFormUseComputed along with the history dropdown and modal -- "back to what the system
       calculated" is the TOP ROW of the row's own history panel now, listed beside every other value
       that line has ever held, so there is one way back instead of two (rules.md 5). The PROMISE is
       still made in the form (its hint), which is why it is read there and taken here. */
    check(`${label}: the form offers no second way back to the calculated value`,
        (await page.locator('#btnLineFormUseComputed').count()) === 0);
    await closeFormBypassingGuard(page);
    await page.locator(`${WRAP} tr.lo-row[data-item-code="__base_salary__"] .lo-history-toggle`).click();
    await page.waitForSelector(`${WRAP} tr.lo-history-row .lo-history-panel`, { timeout: 15000 });
    await page.waitForTimeout(600);
    const panelPromised = await page.evaluate((wrap) => {
        const n = document.querySelector(wrap + ' tr.lo-history-row .lo-history-computed-line .num');
        return n ? n.textContent.replace(/[^\d.,]/g, '').trim() : null;
    }, WRAP);
    check(`${label}: the history panel's baseline is that same figure`, panelPromised === promised,
        `${JSON.stringify(panelPromised)} vs ${JSON.stringify(promised)}`);
    const USE_COMPUTED = `${WRAP} tr.lo-history-row .lo-history-computed-action .lo-history-use`;
    const offersUse = await page.locator(USE_COMPUTED).count();
    check(`${label}: an overridden row offers "use the calculated value" on that baseline`, offersUse === 1, String(offersUse));
    if (offersUse === 1 && promised) {
        await page.locator(USE_COMPUTED).click();
        await page.waitForSelector('.swal2-confirm', { timeout: 15000 });
        await page.locator('.swal2-confirm').click();
        // The write redraws the whole table, so wait for the ROW to lose its override rather than
        // for a fixed number of milliseconds.
        await page.waitForFunction((wrap) => {
            const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
            return !!r && (r.getAttribute('data-orig-action') || '') === '';
        }, WRAP, { timeout: 20000 }).catch(() => {});
        const restored = await page.evaluate((wrap) => {
            const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
            return { amount: r.getAttribute('data-amount'), action: r.getAttribute('data-orig-action') || '' };
        }, WRAP);
        check(`${label}: the override is dropped`, restored.action === '', JSON.stringify(restored));
        check(`${label}: and the figure restored is the one the sub-line promised (not 0.00)`,
            restored.amount === promised, `promised ${promised} -> got ${restored.amount}`);
        const goneSub = await page.locator(`${WRAP} tr.lo-row[data-item-code="__base_salary__"] td.lo-amount-cell .payslip-line-tag`).count();
        check(`${label}: with no override left, the sub-line goes too`, goneSub === 0, String(goneSub));
    }
    /* ...and the run goes back the way it was found (2026-09-21, a0). This step's whole point is that
       it DROPS an override, which left the fixture without the one mksession puts there -- and
       tests/ui/h_history_table.js's own h1 needs it, so running this round before that one (the
       order the writers-first sequence asks for) made 24 of its assertions fail on a fixture state,
       not on the app. Every other round in this folder puts back what it changed; this one now does
       too. */
    if (overrideFound) {
        await clickPencil(page, '__base_salary__');
        await page.locator('#manualLineAmount').click();
        await page.keyboard.press('Control+A');
        await page.keyboard.type('27000');
        await page.locator('#manualLineAmount').blur();
        await page.waitForTimeout(300);
        await page.locator('#btnSaveManualLine').click();
        await page.waitForSelector(`${FORM}.show`, { state: 'hidden', timeout: 30000 }).catch(() => {});
        const back = await page.waitForFunction((wrap) => {
            const r = document.querySelector(wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
            return !!r && (r.getAttribute('data-orig-action') || '') !== '';
        }, WRAP, { timeout: 20000 }).then(() => true).catch(() => false);
        check(`${label}: the base-salary override is put back the way it was found`, back, String(back));
    }

    const r = report();
    check(`${label}: console errors = 0`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors).slice(0, 3)));
    console.log(`  blocked: preference-save ${r.blockedPreferenceSaves}, recalculate ${r.blockedRecalculates}`);
    return r;
}

/* en, one pass, over exactly the strings this round changed -- nothing else differs by language. */
async function runEnCell() {
    const label = '1400 en light';
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    // The app's own switcher, not localStorage: loadUserPreferences() reconciles against the row the
    // server has for this employee and the server wins, so a pre-seeded localStorage is overwritten
    // before the first render. changeLanguage() is the one function BOTH real controls call, and the
    // harness already blocks the write-back it fires.
    await page.evaluate(() => window.changeLanguage('en'));
    await page.waitForTimeout(1500);
    await openBreakdown(page);
    const strings = await page.evaluate((wrap) => {
        const tag = document.querySelector(wrap + ' tr.lo-row td.lo-amount-cell .payslip-line-tag');
        const add = document.querySelector(wrap + ' .lo-add-line-btn');
        const payee = document.querySelector(wrap + ' tr.lo-row .lo-name-cell .payslip-line-tag');
        return {
            sub: tag ? tag.textContent.trim() : null,
            add: add ? add.textContent.trim() : null,
            payee: payee ? payee.textContent.trim() : null,
            lang: document.documentElement.getAttribute('lang') || '',
        };
    }, WRAP);
    console.log(`  strings: ${JSON.stringify(strings)}`);
    check(`${label}: the add button reads its English label`, strings.add === 'Add Line', JSON.stringify(strings.add));
    check(`${label}: the sub-line reads its English label`, strings.sub === null || /^System:/.test(strings.sub), JSON.stringify(strings.sub));
    check(`${label}: no payee descriptor still opens with the retired arrow`,
        strings.payee === null || strings.payee.indexOf('→') === -1, JSON.stringify(strings.payee));
    check(`${label}: no payee descriptor prints a long mask`,
        strings.payee === null || strings.payee.indexOf('•••••') === -1, JSON.stringify(strings.payee));
    // The language really switched through the app's own path, which is a write-back the harness has
    // to have stopped: a 0 here would mean the block no longer matches the URL, not that nothing fired.
    check(`${label}: the language switch's write-back was blocked, not sent`, report().blockedPreferenceSaves > 0,
        String(report().blockedPreferenceSaves));
    const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
    check(`${label}: horizontal page overflow = 0`, overflow === 0, String(overflow));
    const r = report();
    check(`${label}: console errors = 0`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors).slice(0, 3)));
    return r;
}

(async () => {
    const reports = [];
    reports.push(await runCell({ width: 1400, height: 950, lang: 'th', colorScheme: 'light' }));
    reports.push(await runCell({ width: 1400, height: 950, lang: 'th', colorScheme: 'dark' }));
    reports.push(await runCell({ width: 430, height: 932, lang: 'th', colorScheme: 'light' }));
    reports.push(await runCell({ width: 430, height: 932, lang: 'th', colorScheme: 'dark' }));
    reports.push(await runEnCell());
    reports.push(await runWriteCell());
    await closeAll();
    console.log(`\nPassed: ${passed}, Failed: ${failed}`);
    console.log('blockedPreferenceSaves total: ' + reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0));
    console.log('blockedRecalculates total: ' + reports.reduce((a, r) => a + r.blockedRecalculates, 0));
    process.exit(failed === 0 ? 0 : 1);
})().catch(async (e) => { console.error(e); await closeAll(); process.exit(1); });
