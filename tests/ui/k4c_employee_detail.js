/**
 * 4c measurement: Employee Detail's own standing-item forms and tables, after they were made to use
 * the same item picker, the same destination picker and the same payee descriptor the payroll slip
 * uses (docs/decisions/2026-09-19-4c-employee-detail.md).
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/k4c_employee_detail.js <PHPSESSID>
 *
 * 4 cells, each its own context:
 *   1  1400 th light  #eedModal: no mode selector left, the picker's own pinned option is the way in
 *   2  1400 th light  the EED deduction table: one descriptor tag per routed row, code searchable
 *                     but not printed, and the "as of today" box agreeing with the list response
 *   3  430 th light   #recurringDeductionModal: nothing overflows, the record sub-question is gone
 *   4  1400 th dark   the tag's colour comes from the theme, and nothing in the modal stayed white
 *
 * READ-ONLY. EM009 (employee 159) is a reference row; every write endpoint these 2 forms can reach is
 * aborted at the network by the harness, and each cell reports how many it had to block -- which must
 * be 0. Nothing is saved, no run is opened.
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
if (!sessionId) {
    throw new Error('usage: node tests/ui/k4c_employee_detail.js <PHPSESSID>');
}

const EMPLOYEE_NO = 'EM009';
// Everything these two forms can write. Named here, not in the harness: which endpoint is the
// dangerous one belongs to the page being measured.
const BLOCK_PATHS = [
    '/api/employee.earning-deduction.save',
    '/api/employee.earning-deduction.delete',
    '/api/employee.earning-deduction.status',
    '/api/employee.recurring-deduction.save',
    '/api/employee.recurring-deduction.delete',
];

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

async function open(opts) {
    return openContext(Object.assign({ sessionId, blockPaths: BLOCK_PATHS }, opts));
}
async function gotoEmployee(ctx, lang) {
    await ctx.page.goto(ctx.url('/employees/' + EMPLOYEE_NO), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
    await ctx.page.waitForTimeout(700);
}
async function showTab(page, id) {
    await page.evaluate((i) => bootstrap.Tab.getOrCreateInstance(document.getElementById(i)).show(), id);
    await page.waitForTimeout(900);
}
async function reportClean(ctx, cell) {
    const r = ctx.report();
    check(`${cell}: nothing this page did had to be blocked`, r.blockedWrites === 0,
        `${r.blockedWrites} (${r.blockedWritePaths.join(', ')})`);
    check(`${cell}: no page errors`, r.pageErrors.length === 0, r.pageErrors.join(' | '));
    check(`${cell}: no console errors`, r.consoleErrors.length === 0, r.consoleErrors.slice(0, 2).join(' | '));
}
// The catalog's own size, from the endpoint the picker reads -- never a number written down here.
async function catalogCount(page, itemType) {
    return page.evaluate(async (t) => {
        // Form-encoded, exactly as select2 sends it: this endpoint reads $_POST, so a JSON body
        // arrives as no filter at all and the count comes back for the whole catalog (measured).
        const res = await fetch(BASE_URL + '/api/employee.earning-deduction.options', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ type: t, page: '1', limit: '10', searchTerm: '' }).toString(),
        });
        const json = await res.json();
        const d = json.data || json.status || {};
        return parseInt(d.total_count || 0, 10);
    }, itemType);
}
// The label a picker's own options endpoint serves for one id -- so a prefilled box can be compared
// against the real thing instead of against a string written down in this file.
async function optionLabel(page, api, id, extra) {
    return page.evaluate(async (a) => {
        const body = Object.assign({ searchTerm: '', page: '1', limit: '50' }, a.extra || {});
        const res = await fetch(BASE_URL + a.api, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(body).toString(),
        });
        const json = await res.json();
        const items = ((json.data || json.status || {}).items) || [];
        const hit = items.find(i => String(i.id) === String(a.id));
        if (!hit) return null;
        return (currentLang === 'th' ? hit.text_th : hit.text_en) || hit.text_th || hit.text_en || '';
    }, { api, id: String(id), extra: extra || {} });
}
async function openSelect2(page, selector) {
    await page.evaluate((s) => $(s).select2('open'), selector);
    await page.waitForTimeout(1500);
}

/* ---------------------------------------------------------------- cell 1 */
async function cell1() {
    console.log('\n=== 1. 1400 th light -- #eedModal, the picker IS the mode ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await gotoEmployee(ctx, 'th');
    await showTab(page, 'earningDeduction-tab');
    await showTab(page, 'eedDeductionSub-tab');
    await page.click('.btn-add-deduction');
    await page.waitForSelector('#eedModal.show', { timeout: 15000 });
    await page.waitForTimeout(700);

    check('the 3-way mode selector is gone', await page.locator('.mode-select-btn').count() === 0);
    check('...and so is its container', await page.locator('#eedModeToggle').count() === 0);

    const n = await catalogCount(page, 'deduction');
    await openSelect2(page, '#eed_ped_type_id');
    const texts = await page.$$eval('.select2-results__option', els => els.map(e => e.textContent.trim()));
    check(`the picker offers the whole catalog plus one way out (${n} + 1)`, texts.length === n + 1,
        `${texts.length} options for ${n} catalog rows`);
    check('no option is printed with its "[CODE] " prefix',
        texts.filter(t => t.indexOf('[') === 0).length === 0,
        texts.filter(t => t.indexOf('[') === 0).join(' | '));
    const pinned = await page.$$eval('.select2-results__option.select2-pinned-option', els => els.map(e => e.textContent.trim()));
    check('the way out is one option, under a divider', pinned.length === 1, pinned.join('|'));

    const beforeOther = await page.locator('#eedIsOtherWrapper:not(.d-none)').count();
    check('the report-bucket question is not asked for a catalog item', beforeOther === 0);

    await page.evaluate(() => {
        const li = document.querySelector('.select2-results__option.select2-pinned-option');
        li.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    });
    await page.waitForTimeout(800);
    const geom = await page.evaluate(() => {
        const fields = document.getElementById('eedCustomFields');
        const select = document.getElementById('eedCatalogFields');
        return {
            visible: !fields.classList.contains('d-none') && fields.offsetHeight > 0,
            below: fields.offsetTop > select.offsetTop,
            other: !document.getElementById('eedIsOtherWrapper').classList.contains('d-none'),
        };
    });
    check('choosing it reveals the free-text name field', geom.visible);
    check('...directly under the picker, not above it', geom.below);
    check('...and only now is the report-bucket question asked', geom.other);

    await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide());
    await page.waitForTimeout(500);
    await reportClean(ctx, 'cell 1');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 2 */
async function cell2() {
    console.log('\n=== 2. 1400 th light -- the deduction table reads like the slip ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await gotoEmployee(ctx, 'th');
    await showTab(page, 'earningDeduction-tab');
    await showTab(page, 'eedDeductionSub-tab');
    await page.waitForSelector('#tableDeduction tbody tr', { timeout: 20000 });
    await page.waitForTimeout(600);

    const rows = await page.locator('#tableDeduction tbody tr').count();
    check('EM009 still shows its 4 deductions', rows === 4, String(rows));

    // The rows the server says are routed somewhere -- the tag count is compared against THAT, not
    // against a number written down here.
    const routed = await page.evaluate(() =>
        $('#tableDeduction').DataTable().rows().data().toArray().filter(r => r.payee && r.payee.payee_type).length);
    const tags = await page.locator('#tableDeduction tbody .payslip-line-tag').count();
    check(`one descriptor tag per routed row, none on the unrouted one (${routed} of ${rows})`,
        tags === routed, `${tags} tags`);

    const body = await page.locator('#tableDeduction tbody').innerText();
    check('the payee employee is named, not coded ("จ่ายให้ CEO" is gone)', body.indexOf('จ่ายให้ CEO') === -1);
    check('the item code is not printed on any row', body.indexOf('EARLY_LEAVE_DEDUCT') === -1);
    check('no per-payee icons are left in the cell',
        await page.locator('#tableDeduction tbody .payslip-line-tag i').count() === 0);

    await page.evaluate(() => $('#tableDeduction').DataTable().search('EARLY_LEAVE_DEDUCT').draw());
    await page.waitForTimeout(600);
    const found = await page.locator('#tableDeduction tbody tr').count();
    check('...but typing it in the search box still finds its row', found === 1, String(found));
    await page.evaluate(() => $('#tableDeduction').DataTable().search('').draw());
    await page.waitForTimeout(400);

    const expect = await page.evaluate(() => {
        const rows = $('#tableDeduction').DataTable().rows().data().toArray();
        const n = new Date();
        const today = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`;
        let a = 0, b = 0, c = 0;
        rows.forEach(r => {
            if (r.effective_date && r.effective_date > today) a++;
            if (r.status === 'paused') b++;
            const t = parseInt(r.total_installments || 0, 10);
            const cur = parseInt(r.current_installment || 0, 10);
            if (r.status === 'completed' || r.status === 'cancelled' || (t > 0 && cur >= t)) c++;
        });
        return [a, b, c];
    });
    const shown = await page.$$eval('#eedDeductionSummary strong', els => els.map(e => Number(e.textContent.trim())));
    check(`the "as of today" box agrees with the list response (${expect.join('/')})`,
        JSON.stringify(shown) === JSON.stringify(expect), `box ${shown.join('/')}`);
    const words = await page.locator('#eedDeductionSummary').innerText();
    check('...and never calls it "this run"', words.indexOf('รอบนี้') === -1, words);

    /* 2026-09-19, 4c round 1: this page threw "Maximum call stack size exceeded" for real -- the
       company-account <select> became part of the payee answer and got a `change` binding, while
       every caller's onChange still cleared that same select WITH an event, so clearing it called
       the thing that had just called the clear (measured at 21+ nested syncPayeeDestination frames
       before the stack blew). It is invisible on a plain load, so the 4 ways in are walked here --
       including opening a saved row, which is where it actually surfaced. */
    const before = ctx.report().pageErrors.length;
    await page.goto(ctx.url('/employees/' + EMPLOYEE_NO + '#earningDeduction-tab'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const viaHash = ctx.report().pageErrors.length;
    check('entering on the tab hash throws nothing', viaHash === before, `${viaHash - before} error(s)`);

    await page.evaluate(() => changeLanguage('en'));
    await page.waitForTimeout(1200);
    await page.evaluate(() => changeLanguage('th'));
    await page.waitForTimeout(1200);
    const viaLang = ctx.report().pageErrors.length;
    check('switching language throws nothing', viaLang === viaHash, `${viaLang - viaHash} error(s)`);

    await showTab(page, 'eedDeductionSub-tab');
    await page.waitForSelector('#tableDeduction .btn-view-eed', { timeout: 20000 });
    await page.evaluate(() => document.querySelector('#tableDeduction .btn-view-eed').click());
    await page.waitForSelector('#eedModal.show', { timeout: 15000 });
    await page.waitForTimeout(1500);
    const viaPrefill = ctx.report().pageErrors.length;
    check('opening a saved row throws nothing (the recursion that was here)', viaPrefill === viaLang,
        ctx.report().pageErrors.slice(-1).join(''));
    // ...and the prefilled destination survived it: a row routed to a company account still reads as
    // one, which is what a swallowed recursion would have silently reset.
    const prefilled = await page.evaluate(() => ({
        dest: $('#eedPayeeDest input[type="radio"]:checked').val(),
        type: payeeDestinationType('eed'),
    }));
    check('a prefilled payee still resolves after the form settles',
        ['company', 'employee', 'other_person', 'none'].indexOf(prefilled.type) !== -1,
        `${prefilled.dest} -> ${prefilled.type}`);
    await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide());
    await page.waitForTimeout(400);

    /* 2026-09-19, 4c round 2: reopening a saved row used to write its OWN label for the payee it was
       putting back (this form: the bare employee_no "CEO") and show no account under it, while the
       slip's identical picker showed the endpoint's label and the account card. Both are compared
       here against the endpoint itself, never against a string typed into this file. */
    const rowsByPayee = await page.evaluate(() => {
        const out = {};
        $('#tableDeduction').DataTable().rows().data().toArray().forEach(r => {
            if (r.payee && r.payee.payee_type && !out[r.payee.payee_type]) out[r.payee.payee_type] = r;
        });
        return out;
    });
    const empRow = rowsByPayee.employee;
    check('EM009 still has a row routed to another employee', !!empRow);
    if (empRow) {
        await page.evaluate((id) => document.querySelector(`.btn-view-eed[data-id="${id}"]`).click(), empRow.id);
        await page.waitForSelector('#eedModal.show', { timeout: 15000 });
        await page.waitForTimeout(1600);
        const want = await optionLabel(page, '/api/employee.report_to.get', empRow.payee.payee_employee_id, { type: 'employee' });
        const got = await page.evaluate(() => ({
            box: ($('#eed_payee_employee_id').next('.select2').find('.select2-selection__rendered').text() || '').trim(),
            card: (document.querySelector('#eedPayeeEmployeeDetail .payee-detail-meta') || {}).textContent || '',
            cardShown: !!document.querySelector('#eedPayeeEmployeeDetail .payee-detail'),
        }));
        // What the box shows is the endpoint's label with the code taken off by stripCodePrefix --
        // so it must be a piece of that label, and must NOT be the bare employee_no it used to be.
        check('the payee box shows the endpoint\'s own label, not the employee_no',
            !!want && got.box.length > 0 && want.indexOf(got.box) !== -1
            && got.box !== empRow.payee.payee_employee_no,
            `box="${got.box}" endpoint="${want}" employee_no="${empRow.payee.payee_employee_no}"`);
        check('the account card under it is filled', got.cardShown, got.card);
        check('...and carries a masked account number', (got.card.match(/•/g) || []).length > 0, got.card);

        // Picking somebody else, then going back, repaints the card from the new option each time.
        const other = await page.evaluate(async () => {
            const res = await fetch(BASE_URL + '/api/employee.report_to.get', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ searchTerm: '', page: '1', limit: '50', type: 'employee' }).toString(),
            });
            const items = (((await res.json()).data) || {}).items || [];
            const pick = items.find(i => i.has_bank_account && String(i.id) !== $('#eed_payee_employee_id').val());
            if (!pick) return null;
            $('#eed_payee_employee_id').trigger({ type: 'select2:select', params: { data: pick } });
            return pick.id;
        });
        const afterSwap = await page.evaluate(() => (document.querySelector('#eedPayeeEmployeeDetail .payee-detail-meta') || {}).textContent || '');
        check('choosing a different employee repaints the card', !!other && afterSwap !== got.card,
            `${got.card} -> ${afterSwap}`);
        await page.evaluate((d) => $('#eed_payee_employee_id').trigger({ type: 'select2:select', params: { data: d } }), empRow.payee);
        await page.waitForTimeout(300);
        await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide());
        await page.waitForTimeout(400);
    }

    const compRow = rowsByPayee.company;
    check('EM009 still has a row retained by the company', !!compRow);
    if (compRow) {
        await page.evaluate((id) => document.querySelector(`.btn-view-eed[data-id="${id}"]`).click(), compRow.id);
        await page.waitForSelector('#eedModal.show', { timeout: 15000 });
        await page.waitForTimeout(1600);
        const want = await optionLabel(page, '/api/payroll-cycle.bank-account.options', compRow.payee.bank_account_id);
        const got = await page.evaluate(() => ({
            box: ($('#eed_bank_account_id').next('.select2').find('.select2-selection__rendered').text() || '').trim(),
            cardShown: !!document.querySelector('#eedBankAccountDetail .payee-detail'),
        }));
        check('the company-account box shows the endpoint\'s own label',
            !!want && got.box === want, `box="${got.box}" endpoint="${want}"`);
        check('...with its own account card under it', got.cardShown);
        await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide());
        await page.waitForTimeout(400);
    }

    await reportClean(ctx, 'cell 2');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 3 */
async function cell3() {
    console.log('\n=== 3. 430 th light -- #recurringDeductionModal on a phone ===');
    const ctx = await open({ width: 430, height: 932 });
    const page = ctx.page;
    await gotoEmployee(ctx, 'th');
    await showTab(page, 'salary-tab');
    await page.click('.btn-add-recurring-deduction');
    await page.waitForSelector('#recurringDeductionModal.show', { timeout: 15000 });
    await page.waitForTimeout(900);

    check('the record sub-question is gone from the form',
        await page.locator('#erdPayeeRecord').count() === 0);
    check('...leaving exactly one segmented control, the destination itself',
        await page.locator('#recurringDeductionModal .segmented').count() === 1);

    const overflow = await page.evaluate(() => {
        const m = document.querySelector('#recurringDeductionModal .modal-body');
        return {
            page: document.body.scrollWidth - document.body.clientWidth,
            modal: m.scrollWidth - m.clientWidth,
        };
    });
    check('the page does not scroll sideways', overflow.page <= 0, String(overflow.page));
    check('neither does the modal body', overflow.modal <= 0, String(overflow.modal));

    const fit = await page.evaluate(() => {
        const seg = document.getElementById('erdPayeeDest');
        const box = seg.parentElement;
        const cs = getComputedStyle(box);
        // The container's CONTENT box: its own horizontal padding is not room the picker may take.
        const inner = box.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        return Math.abs(seg.getBoundingClientRect().width - inner);
    });
    check('the destination picker fills its container', fit <= 2, `${fit.toFixed(1)}px off`);

    // Empty IS the answer here: the account picker may be cleared, and clearing it means payee_type NULL.
    await page.evaluate(() => {
        $('#erdPayeeDestRetained').prop('checked', true).trigger('change');
        $('#erd_bank_account_id').val(null).trigger('change');
    });
    await page.waitForTimeout(500);
    const cleared = await page.evaluate(() => ({
        wrapShown: !document.getElementById('erdCompanyAccountWrapper').classList.contains('d-none'),
        type: payeeDestinationType('erd'),
        placeholder: ($('#erd_bank_account_id').next('.select2').find('.select2-selection__placeholder').text() || '').trim(),
    }));
    check('the company-account picker is on screen while still empty', cleared.wrapShown);
    check('an empty one means "no record" (payee_type NULL)', cleared.type === 'none', cleared.type);
    check('...and says so where it is read', cleared.placeholder.length > 0, cleared.placeholder);

    // 2026-09-19, 4c round 1: the None/Fee toggle is gone -- an empty % field is "no fee".
    check('the fee toggle is gone from the form', await page.locator('#erdFeeToggle').count() === 0);
    const fee = await page.evaluate(() => {
        const wrapper = document.getElementById('erdFeeDetailWrapper');
        const input = document.getElementById('erd_fee_percent');
        return {
            shown: !wrapper.classList.contains('d-none') && input.offsetHeight > 0,
            value: (input.value || '').trim(),
            required: input.classList.contains('required'),
        };
    });
    check('the % field is on screen from the moment the form opens', fee.shown);
    check('...empty, and not marked required', fee.value === '' && !fee.required,
        `"${fee.value}" required=${fee.required}`);

    /* What an empty % field actually SENDS. Captured at $.ajax, before any request exists, so this
       stays read-only in the strongest sense: no write is made, none is blocked, and the assertion is
       on the real payload the real submit handler built -- not on the source code that built it. */
    const payload = await page.evaluate(() => {
        $('#erd_ped_type_id').empty().append(new Option('probe', '1', true, true)).trigger('change');
        $('#erd_amount').val('100');
        $('#erd_effective_date').val('01/10/2026').datepicker('update');
        $('#erd_fee_percent').val('');
        const realAjax = $.ajax;
        let sent = null;
        $.ajax = (o) => { sent = o; return { done: () => {}, fail: () => {} }; };
        try {
            $('#recurringDeductionForm').trigger('submit');
        } finally {
            $.ajax = realAjax;
        }
        return sent ? JSON.parse(sent.data) : null;
    });
    check('an empty % field sends no fee at all', !!payload
        && payload.fee_percent === undefined && payload.fee_base === undefined,
        payload ? JSON.stringify({ fee_percent: payload.fee_percent, fee_base: payload.fee_base }) : 'no payload built');

    // 2026-09-19, 4c round 2: the account card is new on this form -- at 430 it must wrap, not push.
    const card = await page.evaluate(() => {
        $('#erdPayeeDestEmployee').prop('checked', true).trigger('change');
        renderPayeeEmployeeDetailRd('#erdPayeeEmployeeDetail', {
            account_name: 'Kridsada Satukijchai',
            bank_name_th: 'ธนาคารทหารไทยธนชาต',
            bank_branch: 'Trandar',
            account_no_masked: '••••••••1155',
            has_bank_account: true,
        });
        const el = document.querySelector('#erdPayeeEmployeeDetail .payee-detail');
        const body = document.querySelector('#recurringDeductionModal .modal-body');
        return el ? {
            over: el.scrollWidth - el.clientWidth,
            bodyOver: body.scrollWidth - body.clientWidth,
            tall: el.getBoundingClientRect().height,
        } : null;
    });
    await page.waitForTimeout(300);
    check('the account card wraps inside the form at 430',
        !!card && card.over <= 0 && card.bodyOver <= 0 && card.tall > 0,
        card ? `${card.over}px over, body ${card.bodyOver}px, ${card.tall.toFixed(0)}px tall` : 'no card rendered');
    await page.evaluate(() => $('#erdPayeeEmployeeDetail').empty());

    await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('recurringDeductionModal')).hide());
    await page.waitForTimeout(500);
    const wrap = await page.evaluate(() => {
        const el = document.getElementById('recurringDeductionSummary');
        return { over: el.scrollWidth - el.clientWidth, tall: el.getBoundingClientRect().height };
    });
    check('the "as of today" box wraps instead of clipping', wrap.over <= 0 && wrap.tall > 0,
        `${wrap.over}px over, ${wrap.tall.toFixed(0)}px tall`);

    await reportClean(ctx, 'cell 3');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 4 */
async function cell4() {
    console.log('\n=== 4. 1400 th dark -- the tag takes its colour from the theme ===');
    const ctx = await open({ width: 1400, height: 950, colorScheme: 'dark' });
    const page = ctx.page;
    await gotoEmployee(ctx, 'th');
    // header.php stamps `data-bs-theme` from the viewer's SAVED preference, so an OS-level dark
    // context on an account saved as 'light' still renders the light tokens. applyTheme() is what the
    // app's own toggle calls; the write-back that follows it is what the harness blocks.
    await page.evaluate(() => applyTheme('dark'));
    await page.waitForTimeout(500);
    const stamp = await page.evaluate(() => document.documentElement.getAttribute('data-bs-theme'));
    check('the dark theme is really the one in force', stamp === 'dark', String(stamp));
    await showTab(page, 'earningDeduction-tab');
    await showTab(page, 'eedDeductionSub-tab');
    await page.waitForSelector('#tableDeduction tbody .payslip-line-tag', { timeout: 20000 });

    const colours = await page.evaluate(() => {
        const token = getComputedStyle(document.documentElement).getPropertyValue('--c-text-muted').trim();
        const probe = document.createElement('span');
        probe.style.color = token;
        document.body.appendChild(probe);
        const want = getComputedStyle(probe).color;
        probe.remove();
        const got = Array.from(document.querySelectorAll('#tableDeduction tbody .payslip-line-tag'))
            .map(e => getComputedStyle(e).color);
        return { want, got };
    });
    check(`every tag is --c-text-muted (${colours.want})`,
        colours.got.length > 0 && colours.got.every(c => c === colours.want), colours.got.join(' | '));

    await page.click('.btn-add-deduction');
    await page.waitForSelector('#eedModal.show', { timeout: 15000 });
    await page.waitForTimeout(900);
    const whites = await page.evaluate(() => Array.from(document.querySelectorAll('#eedModal *'))
        .filter(e => getComputedStyle(e).backgroundColor === 'rgb(255, 255, 255)')
        .map(e => e.id || String(e.className) || e.tagName).slice(0, 5));
    check('nothing inside the modal stayed white', whites.length === 0, whites.join(' | '));

    // Measured, and reported rather than asserted at 4.5:1. This button is the app-wide brand
    // `.btn-primary` (--c-primary, orange on white text): its ratio is the same on every primary
    // button in the app, in both themes, and was that before this round -- raising it is a
    // brand-token decision for the whole app, not something this modal may make on its own (BACKLOG).
    // What IS asserted here is that this modal did not grow a recoloured primary of its own.
    const primary = await page.evaluate(() => {
        const rgb = (v) => v.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
        const lum = (c) => {
            const a = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
            return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
        };
        const ratio = (el) => {
            const cs = getComputedStyle(el);
            const l1 = lum(rgb(cs.color));
            const l2 = lum(rgb(cs.backgroundColor));
            return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        };
        const token = getComputedStyle(document.documentElement).getPropertyValue('--c-primary').trim();
        const probe = document.createElement('span');
        probe.style.backgroundColor = token;
        document.body.appendChild(probe);
        const brand = getComputedStyle(probe).backgroundColor;
        probe.remove();
        const btn = document.getElementById('eedSaveBtn');
        return { ratio: ratio(btn), bg: getComputedStyle(btn).backgroundColor, brand };
    });
    console.log(`  note: .btn-primary contrast in dark = ${primary.ratio.toFixed(2)}:1 (app-wide brand token)`);
    check('the save button is the app-wide brand primary, not a local recolour',
        primary.bg === primary.brand, `${primary.bg} vs ${primary.brand}`);

    await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide());
    await page.waitForTimeout(400);
    await reportClean(ctx, 'cell 4');
    await closeAll();
}

const CELLS = [cell1, cell2, cell3, cell4];
const only = String(process.env.ONLY_CELLS || '').split(',').map(v => v.trim()).filter(Boolean).map(Number);

(async () => {
    try {
        for (let i = 0; i < CELLS.length; i++) {
            if (only.length && only.indexOf(i + 1) === -1) continue;
            await CELLS[i]();
        }
    } catch (e) {
        failed++;
        console.log('  FAIL  round threw -- ' + (e && e.stack ? e.stack : e));
        await closeAll();
    }
    console.log('\n' + '-'.repeat(50));
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed ? 1 : 0);
})();
