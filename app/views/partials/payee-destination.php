<?php
/**
 * Payee destination picker (rules.md §9 segmented, §15 callout) -- "เงินที่หักได้ ส่งไปที่".
 *
 * ONE control, 4 call sites (Adjustments modal's Payment Items tab + its Recurring Deduction
 * Destination tab, Employee Detail's #eedModal + #recurringDeductionModal), so it lives here
 * instead of being written out 4 times (rules.md §0.4).
 *
 * Three destinations -- what happens to the money, not who the payee record points at:
 *   company_retained -> `payee_type` NULL (no company account chosen) or 'company' (one chosen)
 *   employee         -> `payee_type` 'employee'
 *   external         -> `payee_type` 'other_person'
 * The UI value is mapped to `payee_type` on the client right before submit (payeeDestinationType(),
 * app.js) -- **no enum/validation/backend change**: the 4 write paths still accept exactly the same
 * 5 values they always did. See docs/decisions/2026-09-16-payee-three-destinations.md.
 *
 * Variables:
 *   $payee_prefix          (required) id prefix, e.g. 'manualLine' -> #manualLinePayeeDest
 *   $payee_slot            (required) HTML of this caller's own 3 sub-form wrappers, rendered inside
 *                          the callout under the destination choice they belong to
 *   $payee_allow_no_record (optional, default true) false for an editor whose backend has no "no
 *                          payee at all" value (the per-run override) -- "หักเข้าบริษัท" then always
 *                          means 'company' and the company-account picker may not be left empty
 *   $payee_label_class     (optional) extra classes for the field label
 */
$p = $payee_prefix;
$labelClass = $payee_label_class ?? '';
?>
<label class="form-label payee-dest-label <?=htmlspecialchars($labelClass)?>" data-i18n="payee_type_label">Send deducted amount to</label>
<div class="segmented" id="<?=$p?>PayeeDest">
    <input type="radio" name="<?=$p?>_payee_dest" id="<?=$p?>PayeeDestRetained" value="company_retained" checked>
    <label for="<?=$p?>PayeeDestRetained" data-i18n="payee_dest_retained">Retained by company</label>
    <input type="radio" name="<?=$p?>_payee_dest" id="<?=$p?>PayeeDestEmployee" value="employee">
    <!-- 2 labels per segment, swapped by CSS at the `sm` breakpoint (rules.md §9): below it the 3
         segments share the row equally and these two do not fit, so they read as a shorter wording
         rather than as an ellipsis. Both are real i18n keys -- the swap is CSS only, no JS. -->
    <label for="<?=$p?>PayeeDestEmployee"><span class="seg-label-full" data-i18n="payee_dest_employee">Transfer to another employee</span><span class="seg-label-short" data-i18n="payee_dest_employee_short">Transfer to employee</span></label>
    <input type="radio" name="<?=$p?>_payee_dest" id="<?=$p?>PayeeDestExternal" value="external">
    <label for="<?=$p?>PayeeDestExternal"><span class="seg-label-full" data-i18n="payee_dest_external">Transfer to an external person or organization</span><span class="seg-label-short" data-i18n="payee_dest_external_short">Transfer externally</span></label>
</div>
<p class="payee-dest-desc" id="<?=$p?>PayeeDestDesc"></p>
<div class="payee-dest-subform" id="<?=$p?>PayeeSubform">
<?=$payee_slot?>
</div>
