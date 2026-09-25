/* Payee descriptor -- "where this money goes", as ONE string, for every surface that shows it.
   2026-09-19, 4c: moved here VERBATIM out of payroll/detail.js. Employee Detail's own permanent
   forms (#eedModal / #recurringDeductionModal and their 2 tables) show the same routing as a run's
   slip does, and a page that does not load payroll/detail.js cannot reuse a renderer that lives
   inside it -- so the renderer moved to the one place both pages load instead of being written a
   second time (rules.md §0.4/§10). Names keep their *Rd suffix: this is a move, not a rewrite.

   Reads, from app.js: `langData`, `currentLang`, `splitOptionCodePrefix()`; from format-helpers.js:
   `escapeHtml()`. All at call time, so the load order of those files does not matter. */
/* ---------- Payee descriptor, one renderer (2026-09-18, tiny-L4) ----------
   `payee` is PayrollRunModel::enrichLinePayee()'s descriptor -- the SAME shape the recurring-
   destination card's template/override already carry. Three surfaces used to answer "where does this
   money go?" with three different strings for the same row: the read-only slip printed a bare
   employee_no with no account at all, the hand-added-lines block printed the name plus whichever
   account field its own query happened to join, and the recurring card printed the picker's label
   with no verb in front. The text is built ONCE here so the same payee reads identically wherever it
   appears; only the wrapper differs, and that is what `variant` names.

   Every label inside comes from the descriptor, which took it from that row's own picker builder --
   nothing is composed out of bank + number + name here (rules.md §5/§6, the same rule tiny-L2
   applied on the PHP side). The employee's code is stripped off their name for display. */
const PAYEE_DESCRIPTOR_SEP_RD = ' • ';
/* 2026-09-18, tiny-L5: the line's own quiet second row now answers 2 questions, not 1 -- "which
   instalment of how many" and "where does it go" -- so the 2 halves need a separator BETWEEN them
   that is not the one used INSIDE the payee half (which is already ' • '), and the destination half
   needs something marking it as a destination. Punctuation, not words: both are the same in every
   language, and neither is translatable content (§0.5). The per-payee-kind icons this line used to
   open with are gone with them -- 4 icons named the payee kind that the text right after them names
   in full.
   2026-09-18, tiny-L6b (B4): the '→ ' that used to open the destination half went the same way as
   those icons, for the same reason -- every payee kind this line can name already begins with a word
   that says where the money is going ("โอนให้", "เก็บไว้ที่บริษัท", "โอนออกให้บุคคล/องค์กรภายนอก"), so the
   arrow was a second, wordless copy of a sentence that had just been written out. */
const LINE_TAG_SEP_RD = ' · ';
/* 2026-09-18, tiny-L6b (B4): the picker's own label, shortened for a SUB-LINE. Both of these earn
   their room in a dropdown -- where the reader is choosing between accounts -- and not under a
   figure, where the line is competing with the number above it for the eye (rules.md §0.1/§0.3):
     - the mask's real length says nothing. Only the last 4 digits identify the account, and a run of
       10 dots vs 6 reads as "a longer number", which is not a fact anyone needs here. One length,
       always, so two accounts on two rows line up instead of looking different.
     - the trailing "(account name)" on a company account repeats the company whose line this is.
   Applied INSIDE the shared builder, so all 3 surfaces shorten identically or none do. */
const PAYEE_MASK_SHORT_RD = '••••';
function payeeDescriptorShortMaskRd(label) {
    // Only a run of dots that RUNS INTO the digits it is hiding. The label's own separator is the
    // very same '•' character (bankAccountOptionLabel(): "bank • masked (name)"), so a plain /•+/
    // rewrites that separator as a 4-dot mask too and every label grows a second one -- caught by
    // this round's own golden, which is why the lookahead is here and not a comment saying "careful".
    return String(label || '').replace(/•+(?=\d)/g, PAYEE_MASK_SHORT_RD);
}
function payeeDescriptorDropAccountNameRd(label) {
    // Only a parenthetical that ENDS the label, and only when it closes what it opened -- an account
    // name with brackets of its own inside it must not take the real tail off with it.
    return String(label || '').replace(/\s*\([^()]*\)$/, '');
}
// A company payee with no account chosen is the one state that is not just informational -- the
// money has nowhere to go and somebody has to fix it, so both variants say so in the warning colour.
function payeeDescriptorNeedsReviewRd(payee) {
    return !!payee && payee.payee_type === 'company' && !payee.bank_account_id;
}
function payeeDescriptorTextRd(payee) {
    if (!payee || !payee.payee_type) return '';
    const parts = [];
    if (payee.payee_type === 'employee') {
        // manualLinePayeeNameRd() owns the whole fallback ladder (label by language -> code off the
        // name -> employee_no -> #id), which is why it is reused here rather than restated.
        parts.push(`${langData['payee_transfer_tag'] || 'Paid to'} ${manualLinePayeeNameRd(payee)}`);
        const account = payeeDescriptorShortMaskRd(rowOptionLabelRd(payee.payee_employee_account_label_th, payee.payee_employee_account_label_en, ''));
        if (account) {
            parts.push(account);
        } else if (payee.payee_employee_has_bank_account === false) {
            // Not the same as "not loaded": the descriptor says outright that this person has no
            // account on file, which is the reason the line above has no account after it.
            parts.push(langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet');
        }
    } else if (payee.payee_type === 'company') {
        const bankLabel = payeeDescriptorDropAccountNameRd(payeeDescriptorShortMaskRd(rowOptionLabelRd(payee.bank_account_label_th, payee.bank_account_label_en, '')));
        if (bankLabel) {
            parts.push(langData['payee_dest_retained'] || 'Retained by company');
            parts.push(bankLabel);
        } else {
            parts.push(langData['payee_bank_account_needs_review'] || 'Company Account -- bank account not specified, needs review');
        }
    } else if (payee.payee_type === 'other_person') {
        parts.push(langData['payee_dest_external'] || 'Transfer to an external person or organization');
        const destLabel = payeeDescriptorShortMaskRd(rowOptionLabelRd(payee.destination_label_th, payee.destination_label_en, ''));
        if (destLabel) parts.push(destLabel);
    } else if (payee.payee_type === 'not_disbursed') {
        parts.push(langData['payee_type_not_disbursed'] || 'Deducted, No Cash Movement (Write-off)');
    } else {
        return '';
    }
    // The row it pointed at is gone (soft-deleted). Said out loud rather than shown as a blank where
    // an account should be -- a persisted line is history and stays readable after its master row is.
    if (payee.missing) parts.push(langData['payee_dest_missing'] || 'Destination record no longer exists');
    return parts.join(PAYEE_DESCRIPTOR_SEP_RD);
}
/* The line's quiet second row, ONE shape for all 3 places a line is drawn (the read-only slip, the
   editable slip's own table, the hand-added block): "งวด 2/12 · → {payee}", either half on its own when
   only one is known, and nothing at all when neither is -- a line that is not part of a plan and
   routes nowhere has nothing to say here, and an empty tag under it would still take a row's height.
   `opts.installment` is PayrollRunModel::enrichLineInstallment()'s {n, total} (null unless the line
   really is one instalment of several); `variant` is kept for the one wrapper that exists ('tag'),
   so a second one has a name to be added under rather than a second function. */
function lineInstallmentTextRd(installment) {
    if (!installment || !installment.total) return '';
    return (langData['payslip_line_installment'] || 'Installment {n}/{total}')
        .replace('{n}', String(installment.n))
        .replace('{total}', String(installment.total));
}
function payeeDescriptorHtmlRd(payee, opts) {
    const payeeText = payeeDescriptorTextRd(payee);
    const parts = [];
    const installmentText = lineInstallmentTextRd(opts && opts.installment);
    if (installmentText) parts.push(installmentText);
    if (payeeText) parts.push(payeeText);
    if (!parts.length) return '';
    // The warning state belongs to the payee half, and only exists when there IS one.
    const needsReview = !!payeeText && payeeDescriptorNeedsReviewRd(payee);
    return `<div class="payslip-line-tag${needsReview ? ' payslip-line-tag-warn' : ''}">${escapeHtml(parts.join(LINE_TAG_SEP_RD))}</div>`;
}

// Picks the label the picker's own endpoint would have shown for this row, in the language on
// screen -- never re-composed here (both come from that endpoint's own builder, server side).
// The payee's name alone for a manual line row: the picker's own label with its code taken off by
// the shared splitter. Falls back to the employee_no only when the payload carries no label at all
// (a row read through an older payload shape), never to a blank.
function payeeNameFromLabelRd(thLabel, enLabel, fallback) {
    const label = rowOptionLabelRd(thLabel, enLabel, '');
    const name = label ? splitOptionCodePrefix(label, 'dash').text : '';
    return name || fallback || '';
}
function manualLinePayeeNameRd(line) {
    return payeeNameFromLabelRd(line.payee_employee_label_th, line.payee_employee_label_en,
        line.payee_employee_no || ('#' + line.payee_employee_id));
}
function rowOptionLabelRd(thLabel, enLabel, fallback) {
    const preferred = currentLang === 'th' ? thLabel : enLabel;
    return preferred || thLabel || enLabel || fallback || '';
}

/* ---------- The same descriptor, as a FORM: pinned option + account summary box ----------
   2026-09-19, 4c round 2: moved here verbatim from payroll/detail.js. A form reopening a saved
   row has to put back the label that row's own options endpoint would have shown, and the account
   summary under it -- if each form invents that itself, the same payee reads "CEO" in one place,
   "CEO - กฤษดา สาธุกิจชัย" in the next and "กฤษดา สาธุกิจชัย (CEO)" in a third, which is what was
   really happening. Reads `payeeDetailFromOption()`/`payeeDetailHtml()` (app.js) and `initSelect2()`
   (input.js) at call time. */
/* 2026-09-17, tiny-L2: the 2 summary-box renderers below take the box they write into, because the
   recurring-deduction destination card (#recurringDestEditorCard) has the same 3 pickers and the
   same need -- a box filled only by select2:select is empty on every prefilled row. Everything that
   differs between the two forms (which box, what a blocked payee does to that form's Save) stays
   with the caller; what a payee account LOOKS like is decided once, here.
   RETURNS whether the endpoint said this employee has no account on file, so the caller can block
   its own save -- the renderer never touches a button itself. */
function renderPayeeEmployeeDetailRd(boxSelector, data) {
    const $box = $(boxSelector);
    if (!data) {
        $box.empty();
        return false;
    }
    const detail = payeeDetailFromOption(data);
    // 3 states, not 2: the endpoint can say there IS an account (render it), say there is NONE
    // (block the add), or -- until api/employee.report_to.get carries the field at all -- say
    // nothing, which must behave exactly as before rather than accusing every employee of having no
    // account. `has_bank_account` is the explicit signal; account data alone is enough on its own.
    const hasAccount = !!(detail.account_no_masked || detail.account_name) || data.has_bank_account === true;
    const knownMissing = data.has_bank_account === false;
    if (hasAccount) {
        $box.html(payeeDetailHtml(detail));
    } else if (knownMissing) {
        $box.html(`<p class="payee-detail-empty" data-i18n="payee_employee_no_bank_account">${langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet'}</p>`);
    } else {
        $box.empty();
    }
    return knownMissing;
}
// The plain account summary (a company account, a saved/ad-hoc destination) -- no block to decide.
function renderPayeeAccountDetailRd(boxSelector, data) {
    const $box = $(boxSelector);
    if (!data) {
        $box.empty();
        return;
    }
    $box.html(payeeDetailHtml(payeeDetailFromOption(data)));
}
function pinRowOptionRd(selector, pinned) {
    if (!pinned || !pinned.id) return false;
    initSelect2(selector, {
        pinnedOption: { id: pinned.id, text: pinned.text, data: pinned.data, selected: true },
    });
    return true;
}
function unpinRowOptionRd(selector) {
    initSelect2(selector, { pinnedOption: null });
}
/* 2026-09-17, tiny-L2: the row a payee form opens on, turned into the 3 options that can be pinned
   into its 3 pickers -- one builder, because both forms that do this read the SAME field names
   (PayrollRunModel::manualLinesForEmployee() and payeeDestinationDescriptor() deliberately return
   one shape). `text` is the label that row's own options endpoint would have shown, never composed
   here; `data` is exactly what payeeDetailFromOption() reads, so the summary under the picker comes
   out of the same pair of helpers as a hand-picked option's. A payload with no label at all (an
   older shape) falls back to the account/employee name, never to a blank. */
function payeeRowPinnedOptionsRd(row) {
    return {
        payeeEmployee: (row.payee_type === 'employee' && row.payee_employee_id) ? {
            id: row.payee_employee_id,
            text: rowOptionLabelRd(row.payee_employee_label_th, row.payee_employee_label_en,
                row.payee_employee_no || ('#' + row.payee_employee_id)),
            data: {
                account_name: row.payee_employee_account_name,
                bank_name_th: row.payee_employee_bank_name_th,
                bank_name_en: row.payee_employee_bank_name_en,
                bank_branch: row.payee_employee_bank_branch,
                account_no_masked: row.payee_employee_account_no_masked,
                has_bank_account: row.payee_employee_has_bank_account,
            },
        } : null,
        bankAccount: (row.payee_type === 'company' && row.bank_account_id) ? {
            id: row.bank_account_id,
            text: rowOptionLabelRd(row.bank_account_label_th, row.bank_account_label_en,
                row.bank_account_name || ('#' + row.bank_account_id)),
            data: {
                account_name: row.bank_account_name,
                bank_name_th: row.bank_account_bank_name_th,
                bank_name_en: row.bank_account_bank_name_en,
                bank_branch: row.bank_account_branch,
                account_no_masked: row.bank_account_no_masked,
            },
        } : null,
        destination: (row.payee_type === 'other_person' && row.destination_id) ? {
            id: row.destination_id,
            text: rowOptionLabelRd(row.destination_label_th, row.destination_label_en,
                row.destination_account_name || ('#' + row.destination_id)),
            // A destination may be is_saved = 0, i.e. one its own picker endpoint never returns.
            is_saved: row.destination_is_saved,
            data: {
                account_name: row.destination_account_name,
                bank_name_th: row.destination_bank_name_th,
                bank_name_en: row.destination_bank_name_en,
                bank_branch: row.destination_bank_branch,
                account_no_masked: row.destination_account_no_masked,
            },
        } : null,
    };
}
