<?php
declare(strict_types=1);

/**
 * 2026-09-19, tiny-F: "where this money goes", as ONE descriptor, for every model that owns rows
 * carrying the 4 routing columns (payee_type / payee_employee_id / destination_id / bank_account_id).
 *
 * payeeDestinationDescriptor() was PayrollRunModel's own private method and stays character-for-
 * character what it was; it moved here only so EmployeeEarningDeductionModel and
 * EmployeeRecurringDeductionModel -- whose list() rows are the SAME 4 columns, read by the same
 * client-side helper -- read it from the one place instead of each re-branching on the raw columns.
 *
 * Every label and account field still comes from the 3 pickers' own option endpoints
 * (EmployeeModel::optionRowsByIds / PaymentDestinationModel::optionRowsByIds /
 * PayrollCycleModel::bankAccountOptionRowsByIds); nothing is composed here. Account numbers are
 * masked at those methods, so no plaintext reaches this array.
 */
trait PayeeDescriptorTrait {

    /**
     * The 3 option-row maps payeeDestinationDescriptor() reads, for a whole SET of rows in one go:
     * 3 lookups per list(), never per row. Call once, pass the result to every row.
     *
     * $rows: any rows carrying payee_employee_id / destination_id / bank_account_id.
     * @return array{payees: array, destinations: array, banks: array}
     */
    protected function payeeDescriptorLookup(int $compId, array $rows): array {
        $payeeIds = [];
        $destIds = [];
        $bankIds = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (!empty($r['payee_employee_id'])) { $payeeIds[] = (int)$r['payee_employee_id']; }
            if (!empty($r['destination_id'])) { $destIds[] = (int)$r['destination_id']; }
            if (!empty($r['bank_account_id'])) { $bankIds[] = (int)$r['bank_account_id']; }
        }
        require_once __DIR__ . '/EmployeeModel.php';
        require_once __DIR__ . '/PaymentDestinationModel.php';
        require_once __DIR__ . '/PayrollCycleModel.php';
        return [
            'payees' => $payeeIds ? (new EmployeeModel($this->db))->optionRowsByIds($compId, $payeeIds) : [],
            'destinations' => $destIds ? (new PaymentDestinationModel($this->db))->optionRowsByIds($compId, $destIds) : [],
            'banks' => $bankIds ? (new PayrollCycleModel($this->db))->bankAccountOptionRowsByIds($compId, $bankIds) : [],
        ];
    }


    /**
     * Adds `$key` -- the descriptor above -- to every row of a list, with the 3 option-row lookups
     * done ONCE for the whole list. A row that is not routed anywhere (payee_type NULL) still gets
     * the full key set, all null: a reader that has to ask "is this key even here?" is the thing
     * this descriptor exists to remove.
     */
    protected function attachPayeeDescriptor(array $rows, int $compId, string $key = 'payee'): array {
        if (!$rows) {
            return $rows;
        }
        $lookup = $this->payeeDescriptorLookup($compId, $rows);
        foreach ($rows as &$row) {
            $row[$key] = $this->payeeDestinationDescriptor($row, $lookup['payees'], $lookup['destinations'], $lookup['banks']);
        }
        unset($row);
        return $rows;
    }
    /**
     * "Where this money goes", read-only, in the one shape every payee form on this page reads --
     * the SAME field names manualLinesForEmployee() returns per line, so one client-side helper
     * builds the pinned option + account summary for both. Every label and every account field comes
     * from that picker's own options endpoint (via each model's optionRowsByIds()); nothing is
     * composed here. Account numbers are masked at those methods, so no plaintext reaches this array.
     *
     * @param array $src a row carrying payee_type/payee_employee_id/destination_id/bank_account_id
     *                   (a recurring-deduction template row, one of this run's override rows, or --
     *                   since tiny-L4 -- one persisted *_breakdown line, via enrichLinePayee())
     */
    protected function payeeDestinationDescriptor(array $src, array $payeeRows, array $destRows, array $bankRows): array {
        $payeeId = !empty($src['payee_employee_id']) ? (int)$src['payee_employee_id'] : null;
        $destId = !empty($src['destination_id']) ? (int)$src['destination_id'] : null;
        $bankId = !empty($src['bank_account_id']) ? (int)$src['bank_account_id'] : null;
        $payee = $payeeId !== null ? ($payeeRows[$payeeId] ?? null) : null;
        $dest = $destId !== null ? ($destRows[$destId] ?? null) : null;
        $bank = $bankId !== null ? ($bankRows[$bankId] ?? null) : null;
        // 2026-09-18, tiny-L4: an id that IS set but resolves to nothing means the row it points at
        // is gone (soft-deleted employee/destination/bank account). Reported as a flag rather than
        // thrown: a persisted breakdown line is history, and history has to stay describable after
        // its master row is retired -- the reader shows the type it can still name plus "record not
        // found" instead of a blank where an account used to be.
        $missing = ($payeeId !== null && $payee === null)
            || ($destId !== null && $dest === null)
            || ($bankId !== null && $bank === null);
        return [
            'payee_type' => $src['payee_type'],
            'missing' => $missing,
            'payee_employee_id' => $payeeId,
            // 2026-09-18, tiny-L4: never DISPLAYED (rules.md §5/§6 keep an internal code out of a
            // label) -- it is the last rung of the reader's own fallback ladder, for a payee whose
            // employee row is gone and therefore has no label of its own left to show.
            'payee_employee_no' => $src['payee_employee_no'] ?? null,
            'payee_employee_label_th' => $payee['text_th'] ?? null,
            'payee_employee_label_en' => $payee['text_en'] ?? null,
            'payee_employee_account_name' => $payee['account_name'] ?? null,
            'payee_employee_bank_name_th' => $payee['bank_name_th'] ?? null,
            'payee_employee_bank_name_en' => $payee['bank_name_en'] ?? null,
            'payee_employee_bank_branch' => $payee['bank_branch'] ?? null,
            'payee_employee_account_no_masked' => $payee['account_no_masked'] ?? null,
            'payee_employee_has_bank_account' => $payee !== null ? (bool)$payee['has_bank_account'] : null,
            // 2026-09-18, tiny-L4: the payee employee's own receiving account as ONE label, so a
            // reader never has to glue bank + number + name together itself (the other 2 payee kinds
            // already arrive pre-composed as bank_account_label_*/destination_label_*, and 3 readers
            // each gluing their own is exactly the divergence tiny-L2 removed). Composed by the SAME
            // public composer the company-account picker's own options endpoint uses -- not a 4th
            // spelling invented here.
            // 2026-09-18, tiny-L5: the account NAME is deliberately NOT passed (null) -- the one
            // difference from a company account's own label. This label never stands alone: it
            // follows "จ่ายให้ {that same person}" in the same sentence, so the composer's trailing
            // "(owner)" was printing the payee's name a second time, 3 words after the first.
            'payee_employee_account_label_th' => ($payee !== null && !empty($payee['has_bank_account']))
                ? PayrollCycleModel::bankAccountOptionLabel($payee['bank_name_th'] ?? null, $payee['account_no_masked'] ?? null, null)
                : null,
            'payee_employee_account_label_en' => ($payee !== null && !empty($payee['has_bank_account']))
                ? PayrollCycleModel::bankAccountOptionLabel($payee['bank_name_en'] ?? null, $payee['account_no_masked'] ?? null, null)
                : null,
            'destination_id' => $destId,
            // Whatever that endpoint serves per language, mirrored exactly -- since tiny-L5 the two
            // differ in the bank's name (the destination's own name has no English twin to differ
            // in). See PaymentDestinationModel::optionLabel().
            'destination_label_th' => $dest['text_th'] ?? null,
            'destination_label_en' => $dest['text_en'] ?? null,
            'destination_account_name' => $dest['account_name'] ?? null,
            'destination_bank_name_th' => $dest['bank_name_th'] ?? null,
            'destination_bank_name_en' => $dest['bank_name_en'] ?? null,
            'destination_bank_branch' => $dest['bank_branch'] ?? null,
            'destination_account_no_masked' => $dest['account_no_masked'] ?? null,
            // A row may point at an ad-hoc destination the saved-only picker can never offer back.
            'destination_is_saved' => $dest !== null ? (int)$dest['is_saved'] : null,
            'bank_account_id' => $bankId,
            'bank_account_label_th' => $bank['text_th'] ?? null,
            'bank_account_label_en' => $bank['text_en'] ?? null,
            'bank_account_name' => $bank['account_name'] ?? null,
            'bank_account_bank_name_th' => $bank['bank_name_th'] ?? null,
            'bank_account_bank_name_en' => $bank['bank_name_en'] ?? null,
            'bank_account_branch' => $bank['bank_branch'] ?? null,
            'bank_account_no_masked' => $bank['account_no_masked'] ?? null,
        ];
    }
}
