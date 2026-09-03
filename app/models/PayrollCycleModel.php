<?php
declare(strict_types=1);
class PayrollCycleModel {
    private $db;
    private const DAYS_OF_WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- ba.*
    // (account_name/company_code, NOT account_no -- that stays encrypted/PII, not needed for this
    // display) joined so the cycle list/edit form can show which of the company's own bank
    // accounts (and its Company/Service Code) this cycle settles from, same "which account" this
    // migration's bank_account_id column now lets a cycle pin down instead of always falling back
    // to the company's single is_default account.
    public function list(int $compId): array {
        // 2026-09-02, multi-bank-account payroll -- default_payment_method_id joined for display,
        // same pattern as bank_file_format_id/bank_account_id above it. Account count/default
        // account itself is NOT joined here (that's a 1:many relationship now, see
        // getBankAccounts()) -- the list view shows how many accounts + the default one via a
        // second call from the controller only when the edit form/detail view actually needs it.
        $sql = "SELECT pc.*, f.name_th AS bank_file_format_name_th, f.name_en AS bank_file_format_name_en,
                    ba.account_name AS bank_account_name, ba.company_code AS bank_account_company_code,
                    mpm.name_th AS default_payment_method_name_th, mpm.name_en AS default_payment_method_name_en
                FROM `payroll_cycles` pc
                LEFT JOIN `master_bank_file_formats` f ON f.id = pc.bank_file_format_id
                LEFT JOIN `bank_accounts` ba ON ba.id = pc.bank_account_id
                LEFT JOIN `master_payment_methods` mpm ON mpm.id = pc.default_payment_method_id
                WHERE pc.comp_id = :comp_id AND pc.deleted_at IS NULL ORDER BY pc.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $sql = "SELECT pc.*, f.name_th AS bank_file_format_name_th, f.name_en AS bank_file_format_name_en,
                    ba.account_name AS bank_account_name, ba.company_code AS bank_account_company_code,
                    mpm.name_th AS default_payment_method_name_th, mpm.name_en AS default_payment_method_name_en
                FROM `payroll_cycles` pc
                LEFT JOIN `master_bank_file_formats` f ON f.id = pc.bank_file_format_id
                LEFT JOIN `bank_accounts` ba ON ba.id = pc.bank_account_id
                LEFT JOIN `master_payment_methods` mpm ON mpm.id = pc.default_payment_method_id
                WHERE pc.id = :id AND pc.comp_id = :comp_id AND pc.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['bank_accounts'] = $this->getBankAccounts($id);
        }
        return $row ?: null;
    }

    /**
     * 2026-09-02, explicit request: "หน้านี้รองรับการเพิ่มมากกว่า 1 บัญชีธนาคารต่อ 1 รอบจ่ายเงินเดือนอยู่แล้ว
     * หรือไม่...ถ้ายังไม่มีให้เพิ่ม" -- confirmed real gap, this + saveBankAccounts() below are what
     * fill it. Every account this cycle currently offers, most-default-first for display.
     */
    public function getBankAccounts(int $cycleId): array {
        $stmt = $this->db->prepare(
            "SELECT pcba.id AS link_id, pcba.bank_account_id, pcba.is_default, pcba.sort_order,
                    ba.account_name, ba.company_code, mb.bank_name_th, mb.bank_name_en
             FROM `payroll_cycle_bank_accounts` pcba
             JOIN `bank_accounts` ba ON ba.id = pcba.bank_account_id AND ba.deleted_at IS NULL
             LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id
             WHERE pcba.cycle_id = :cycle_id
             ORDER BY pcba.is_default DESC, pcba.sort_order ASC, pcba.id ASC"
        );
        $stmt->execute([':cycle_id' => $cycleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Replaces the WHOLE account set for one cycle (delete+reinsert, same "small all-or-nothing
     * child set" convention this project already uses for e.g. holiday_assignments/
     * approval_workflow_steps) -- $rows: [{bank_account_id, is_default}, ...]. Enforces EXACTLY ONE
     * is_default=1 row at the application layer (this project's own established convention for an
     * invariant MySQL itself can't express cleanly, e.g. the deleted_at+unique pattern documented in
     * CLAUDE.md) -- rejects zero or 2+ flagged defaults rather than silently picking one. Also syncs
     * the DENORMALIZED `payroll_cycles.bank_account_id` shortcut to the new default's id, since
     * PayrollRunEmployeeBankAccountModel::resolveForRun() still reads that column directly (see this
     * table's own migration header for why that's the cheapest, least-disruptive path).
     */
    public function saveBankAccounts(int $cycleId, int $compId, array $rows, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtCheck->execute([':id' => $cycleId, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (empty($rows)) {
            // An empty set is valid (cycle falls back to the company's own is_default=1 account,
            // same as before this feature existed) -- just clear the denormalized shortcut too.
            $own = !$this->db->inTransaction();
            if ($own) {
                $this->db->beginTransaction();
            }
            try {
                $this->db->prepare("DELETE FROM `payroll_cycle_bank_accounts` WHERE cycle_id = :cycle_id")->execute([':cycle_id' => $cycleId]);
                $this->db->prepare("UPDATE `payroll_cycles` SET bank_account_id = NULL, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute([':updated_by' => $userId, ':id' => $cycleId]);
                if ($own) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($own) {
                    $this->db->rollBack();
                }
                return ['status' => false, 'message' => 'Database operation failed.'];
            }
            return ['status' => true, 'message' => 'Saved.'];
        }

        $defaultCount = 0;
        $accountIds = [];
        foreach ($rows as $row) {
            $accId = !empty($row['bank_account_id']) ? (int)$row['bank_account_id'] : 0;
            if ($accId <= 0) {
                return ['status' => false, 'message' => 'Invalid bank_account_id in the account list.'];
            }
            $accountIds[] = $accId;
            if (!empty($row['is_default'])) {
                $defaultCount++;
            }
        }
        if (count(array_unique($accountIds)) !== count($accountIds)) {
            return ['status' => false, 'message' => 'The same bank account cannot be added twice to one cycle.'];
        }
        if ($defaultCount !== 1) {
            return ['status' => false, 'message' => 'Exactly one account must be flagged as default.'];
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmtOwn = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE comp_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ({$placeholders})");
        $stmtOwn->execute(array_merge([$compId], $accountIds));
        if (count($stmtOwn->fetchAll()) !== count($accountIds)) {
            return ['status' => false, 'message' => 'One or more selected accounts are invalid or do not belong to this company.'];
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("DELETE FROM `payroll_cycle_bank_accounts` WHERE cycle_id = :cycle_id")->execute([':cycle_id' => $cycleId]);
            $ins = $this->db->prepare(
                "INSERT INTO `payroll_cycle_bank_accounts` (cycle_id, bank_account_id, is_default, sort_order, created_by)
                 VALUES (:cycle_id, :bank_account_id, :is_default, :sort_order, :created_by)"
            );
            $defaultAccountId = null;
            foreach ($rows as $i => $row) {
                $accId = (int)$row['bank_account_id'];
                $isDefault = !empty($row['is_default']) ? 1 : 0;
                if ($isDefault) {
                    $defaultAccountId = $accId;
                }
                $ins->execute([
                    ':cycle_id' => $cycleId, ':bank_account_id' => $accId, ':is_default' => $isDefault,
                    ':sort_order' => $i, ':created_by' => $userId,
                ]);
            }
            $this->db->prepare("UPDATE `payroll_cycles` SET bank_account_id = :bank_account_id, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':bank_account_id' => $defaultAccountId, ':updated_by' => $userId, ':id' => $cycleId]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Saved.'];
    }

    public function options(int $compId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND cycle_name LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_cycles` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, cycle_name AS text_th, cycle_name AS text_en FROM `payroll_cycles` {$where} ORDER BY cycle_name ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    public function bankFileFormatOptions(string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE is_active = 1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR name_en LIKE :search2 OR code LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `master_bank_file_formats` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, name_th AS text_th, name_en AS text_en FROM `master_bank_file_formats` {$where} ORDER BY sort_order ASC, id ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    /**
     * 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" --
     * powers the cycle form's new "Bank Account" dropdown, scoped to THIS company's own active
     * accounts (unlike bankFileFormatOptions() above, which is global master data every company
     * shares) -- account_no is intentionally NOT selected/decrypted here, this is a picker label
     * list only, not a place PII needs to round-trip through.
     */
    public function bankAccountOptions(int $compId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE ba.comp_id = :comp_id AND ba.deleted_at IS NULL AND ba.status = 'active'";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND (ba.account_name LIKE :search1 OR ba.company_code LIKE :search2 OR mb.bank_name_th LIKE :search3 OR mb.bank_name_en LIKE :search4)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
            $params[':search4'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `bank_accounts` ba LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT ba.id,
                    CONCAT(mb.bank_name_th, ' - ', ba.account_name, IF(ba.company_code IS NOT NULL AND ba.company_code != '', CONCAT(' (', ba.company_code, ')'), '')) AS text_th,
                    CONCAT(mb.bank_name_en, ' - ', ba.account_name, IF(ba.company_code IS NOT NULL AND ba.company_code != '', CONCAT(' (', ba.company_code, ')'), '')) AS text_en
                FROM `bank_accounts` ba
                LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id
                {$where} ORDER BY ba.is_default DESC, ba.id ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    /**
     * Suggests the next period_start_date/period_end_date/payment_date for a cycle, so the
     * "Create Payroll Run" form can auto-fill dates once a cycle is picked instead of the user
     * typing them by hand every time -- purely a convenience default, the fields stay editable
     * after. "Next" means: continuing on from this cycle's most recent payroll_runs row (by
     * period_end_date), or if none exists yet, the period that contains today.
     *
     * Payment date rule (confirmed explicitly, not guessed): payment always falls in the
     * calendar month immediately AFTER the period's own cutoff/end month, on payment_day_of_month
     * (or the last day of that month) -- standard "close the period, pay a few days into the next
     * month" convention. Applies uniformly to monthly and semi-monthly (both halves); this is a
     * deliberate simplification for semi-monthly specifically, since the schema only has one
     * payment_day_of_month for the whole cycle, not one per half -- a company whose second-half
     * payment actually falls in the SAME month needs to adjust the auto-filled date by hand.
     *
     * Semi-monthly's single cutoff_day_of_month is treated as the split point within each month:
     * first half = 1st .. cutoff_day, second half = (cutoff_day+1) .. last day of month (the
     * second half always reaches month-end regardless of cutoff_use_last_day, since it has to
     * cover the rest of the month either way).
     *
     * Weekly/bi-weekly periods are 7/14 days ending on cutoff_day_of_week; payment is the next
     * occurrence of payment_day_of_week strictly after the period ends.
     */
    public function suggestNextPeriod(int $cycleId, int $compId): array {
        $cycle = $this->get($cycleId, $compId);
        if (!$cycle) {
            return ['status' => false, 'message' => 'Record not found.'];
        }

        $stmtLast = $this->db->prepare("SELECT period_end_date FROM `payroll_runs`
            WHERE cycle_id = :cycle_id AND comp_id = :comp_id AND deleted_at IS NULL
            ORDER BY period_end_date DESC LIMIT 1");
        $stmtLast->execute([':cycle_id' => $cycleId, ':comp_id' => $compId]);
        $lastEndStr = $stmtLast->fetchColumn();
        $lastEnd = $lastEndStr !== false ? new DateTime((string)$lastEndStr) : null;
        $today = new DateTime('today');

        $frequency = (string)$cycle['payroll_frequency'];
        try {
            if ($frequency === 'monthly') {
                [$start, $end] = $this->nextMonthlyPeriod($lastEnd, $today, (int)($cycle['cutoff_day_of_month'] ?? 0), (bool)$cycle['cutoff_use_last_day']);
                $payment = $this->resolvePaymentDate($end, (int)($cycle['payment_day_of_month'] ?? 0), (bool)$cycle['payment_use_last_day']);
            } elseif ($frequency === 'semi_monthly') {
                [$start, $end] = $this->nextSemiMonthlyPeriod($lastEnd, $today, (int)($cycle['cutoff_day_of_month'] ?? 0));
                $payment = $this->resolvePaymentDate($end, (int)($cycle['payment_day_of_month'] ?? 0), (bool)$cycle['payment_use_last_day']);
            } elseif ($frequency === 'weekly' || $frequency === 'bi_weekly') {
                $lengthDays = $frequency === 'weekly' ? 7 : 14;
                [$start, $end] = $this->nextWeekBasedPeriod($lastEnd, $today, (string)($cycle['cutoff_day_of_week'] ?? ''), $lengthDays);
                $payment = $this->nextOccurrenceOfWeekday((clone $end)->modify('+1 day'), (string)($cycle['payment_day_of_week'] ?? ''));
            } else {
                return ['status' => false, 'message' => 'Unsupported payroll_frequency.'];
            }
        } catch (Throwable $e) {
            return ['status' => false, 'message' => 'This cycle is not fully configured yet -- set its cutoff/payment day first.'];
        }

        return [
            'status' => true,
            'period_start_date' => $start->format('Y-m-d'),
            'period_end_date' => $end->format('Y-m-d'),
            'payment_date' => $payment->format('Y-m-d'),
        ];
    }

    /**
     * 2026-09-01, explicit request: "ปุ่มคำว่าดึงมาทำงวด ข้อมูลรอบ และวันที่ ต่างๆ ถูกส่งมาอยู่แล้ว อยากให้กดแล้ว
     * Default ค่าที่ส่งมา Origami เลยโดยที่ไม่ต้องเลือกใหม่ ... ถ้า Map ได้ ถ้า Map ไม่ได้ก็ไม่ต้อง Default เลือก" --
     * best-effort structural match between an incoming Origami sync process and one of this company's
     * own configured payroll_cycles rows, so "Pull to Run" can pre-select the Payroll Schedule
     * dropdown too (not just the period dates, which were already pre-filled from Origami's own
     * process_start/end/paid).
     *
     * 2026-09-02 revision: Origami's own team replied to the gap flagged below (see
     * `proposal_payroll_schedule_mapping.docx`, PAYROLL_SYNC_API.md's 2026-09-01 revision at
     * C:\xampp\htdocs\origami\payroll\docs\) and shipped `external_cycle_code` -- an admin-set
     * free-text code, sent on both the top-level payload and every `items[]` row, meant to be
     * re-entered identically against a `payroll_cycles.external_cycle_code` row on this side. This
     * IS now the real id-based mapping the note below used to say didn't exist -- checked FIRST,
     * as an exact (case-insensitive/trimmed) string match against every active cycle; if found,
     * returned immediately, bypassing the structural heuristic entirely (no ambiguity to resolve --
     * an exact code match is authoritative). Only falls through to the pre-existing heuristic when
     * `syncRow['external_cycle_code']` is null/empty (the Origami admin hasn't set one for this
     * period yet) OR is set but doesn't match any of this company's own configured cycles yet (the
     * transition period before both sides have entered matching codes) -- never a hard failure, so
     * nothing regresses for a company that hasn't adopted external_cycle_code yet.
     *
     * The heuristic below (kept as the fallback) has no reliable id-based mapping of its own --
     * payroll_cycles carries no origami_period_id/origami reference column, and
     * payroll_sync_processes' own origami_period_id has nothing on this side to join against. It
     * can only ever guess from structural coincidence:
     *   1. frequency_type must translate to the SAME payroll_frequency (monthly/
     *      semimonthly->semi_monthly/weekly/biweekly->bi_weekly).
     *   2. process_end's own day-of-month (or weekday, for weekly/bi_weekly) must match the cycle's
     *      configured cutoff_day_of_month/cutoff_use_last_day (or cutoff_day_of_week).
     *   3. process_paid's own day-of-month/weekday must match the cycle's payment_day_of_month/
     *      payment_use_last_day (or payment_day_of_week), when process_paid is present.
     *   4. If exactly ONE active cycle survives all 3 checks, that's confident enough to auto-select.
     *      If MORE than one survives (e.g. a company with several same-frequency/same-cutoff cycles,
     *      one per branch), only auto-select when exactly one of them also has cycle_name === Origami's
     *      own period_name (case-insensitive/trimmed) -- this is the "recommend keeping cycle names in
     *      sync with Origami" workaround, not a real id-based tiebreak.
     *   5. Anything else (zero matches, or an ambiguous tie with no name match) returns null -- the
     *      admin picks manually, same as today, per the explicit "ถ้า Map ไม่ได้ก็ไม่ต้อง Default เลือก"
     *      instruction.
     *
     * @param array $activeCycles this method's own list()'s return shape (already comp-scoped)
     * @param array $syncRow one row of PayrollSyncModel::pendingList()'s own return shape
     */
    public function matchForSyncProcess(array $activeCycles, array $syncRow): ?array {
        $externalCode = trim((string)($syncRow['external_cycle_code'] ?? ''));
        if ($externalCode !== '') {
            foreach ($activeCycles as $cycle) {
                if (($cycle['status'] ?? '') !== 'active') continue;
                $cycleCode = trim((string)($cycle['external_cycle_code'] ?? ''));
                if ($cycleCode !== '' && strcasecmp($cycleCode, $externalCode) === 0) {
                    return $cycle;
                }
            }
        }

        if (empty($syncRow['process_end']) || empty($syncRow['frequency_type'])) {
            return null;
        }
        $freqMap = ['monthly' => 'monthly', 'semimonthly' => 'semi_monthly', 'weekly' => 'weekly', 'biweekly' => 'bi_weekly'];
        $mappedFreq = $freqMap[$syncRow['frequency_type']] ?? null;
        if ($mappedFreq === null) {
            return null;
        }
        try {
            $end = new DateTime((string)$syncRow['process_end']);
            $paid = !empty($syncRow['process_paid']) ? new DateTime((string)$syncRow['process_paid']) : null;
        } catch (Throwable $e) {
            return null;
        }

        $candidates = [];
        foreach ($activeCycles as $cycle) {
            if (($cycle['status'] ?? '') !== 'active') continue;
            if ((string)$cycle['payroll_frequency'] !== $mappedFreq) continue;
            if ($mappedFreq === 'weekly' || $mappedFreq === 'bi_weekly') {
                if (strtolower($end->format('l')) !== (string)($cycle['cutoff_day_of_week'] ?? '')) continue;
                if ($paid !== null && !empty($cycle['payment_day_of_week']) && strtolower($paid->format('l')) !== (string)$cycle['payment_day_of_week']) continue;
            } else {
                $cutoffOk = !empty($cycle['cutoff_use_last_day'])
                    ? ((int)$end->format('j') === (int)$end->format('t'))
                    : ((int)($cycle['cutoff_day_of_month'] ?? -1) === (int)$end->format('j'));
                if (!$cutoffOk) continue;
                if ($paid !== null) {
                    $paymentOk = !empty($cycle['payment_use_last_day'])
                        ? ((int)$paid->format('j') === (int)$paid->format('t'))
                        : ((int)($cycle['payment_day_of_month'] ?? -1) === (int)$paid->format('j'));
                    if (!$paymentOk) continue;
                }
            }
            $candidates[] = $cycle;
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1) {
            $periodName = trim((string)($syncRow['period_name'] ?? ''));
            if ($periodName !== '') {
                $nameMatches = array_values(array_filter($candidates, function ($c) use ($periodName) {
                    return strcasecmp(trim((string)$c['cycle_name']), $periodName) === 0;
                }));
                if (count($nameMatches) === 1) {
                    return $nameMatches[0];
                }
            }
        }
        return null;
    }

    /** @return DateTime the requested day-of-month (or last day) within $monthRef's month */
    private function resolveDayInMonth(DateTime $monthRef, int $day, bool $useLastDay): DateTime {
        if ($useLastDay) {
            return (clone $monthRef)->modify('last day of this month');
        }
        if ($day < 1 || $day > 28) {
            throw new InvalidArgumentException('Day of month not configured.');
        }
        return (clone $monthRef)->setDate((int)$monthRef->format('Y'), (int)$monthRef->format('n'), $day);
    }

    /**
     * 2026-08-28, real bug found and fixed (explicit report: cycle cutoff day 21, payment day 25 --
     * selecting this cycle suggested a payment date of NEXT month (e.g. period ending 2026-08-21
     * suggested payment 2026-09-25) instead of the same month (2026-08-25). Root cause, confirmed
     * by tracing suggestNextPeriod()'s old inline logic and reproducing it directly via reflection
     * against the real cycle in this dev DB: it unconditionally computed
     * `(clone $end)->modify('first day of next month')` before resolving the payment day, with no
     * check for whether the configured payment day actually falls before or after the cutoff day
     * within the same calendar month. That's only correct for a cycle where payment happens the
     * FOLLOWING month (e.g. cutoff day 25, paid on the 5th of next month) -- for a cycle like this
     * one, where payment_day_of_month (25) comes AFTER cutoff_day_of_month (21) in the same month,
     * payment should land in the SAME month the period just ended in, not a month later.
     *
     * Fixed by resolving the payment day within the period-end's OWN month first, then only rolling
     * forward to next month if that candidate falls BEFORE the period end (i.e. the payment day has
     * already passed within this month relative to the cutoff, so it must mean next month's
     * occurrence) -- comparing actual resolved DateTime values rather than raw day-of-month integers
     * so `payment_use_last_day` and month-length differences (Feb 30 not existing, etc.) are handled
     * correctly automatically, without a separate branch for them.
     */
    private function resolvePaymentDate(DateTime $periodEnd, int $paymentDay, bool $useLastDay): DateTime {
        $sameMonthCandidate = $this->resolveDayInMonth($periodEnd, $paymentDay, $useLastDay);
        if ($sameMonthCandidate >= $periodEnd) {
            return $sameMonthCandidate;
        }
        return $this->resolveDayInMonth($this->addMonthsAnchored($periodEnd, 1), $paymentDay, $useLastDay);
    }

    /** @return array{0: DateTime, 1: DateTime} [periodStart, periodEnd] */
    /**
     * PHP's DateTime::modify('+N month(s)') overflows when the starting day doesn't exist in the
     * target month (classic pitfall: Jan 31 + 1 month = Mar 3, not Feb 28/29) -- every cutoff/
     * payment day in this cycle can legitimately BE a month-end day (cutoff_use_last_day, or the
     * "last day of month" half of semi-monthly), so anchoring to day 1 first before adding/
     * subtracting months sidesteps the overflow entirely, since day 1 always exists everywhere.
     */
    private function addMonthsAnchored(DateTime $ref, int $months): DateTime {
        $anchored = (clone $ref)->setDate((int)$ref->format('Y'), (int)$ref->format('n'), 1);
        return $anchored->modify(($months >= 0 ? '+' : '') . "{$months} month");
    }

    private function nextMonthlyPeriod(?DateTime $lastEnd, DateTime $today, int $cutoffDay, bool $useLastDay): array {
        if ($lastEnd !== null) {
            $end = $this->resolveDayInMonth($this->addMonthsAnchored($lastEnd, 1), $cutoffDay, $useLastDay);
        } else {
            $thisMonthEnd = $this->resolveDayInMonth($today, $cutoffDay, $useLastDay);
            $end = $today <= $thisMonthEnd ? $thisMonthEnd : $this->resolveDayInMonth($this->addMonthsAnchored($today, 1), $cutoffDay, $useLastDay);
        }
        $prevMonthEnd = $this->resolveDayInMonth($this->addMonthsAnchored($end, -1), $cutoffDay, $useLastDay);
        $start = (clone $prevMonthEnd)->modify('+1 day');
        return [$start, $end];
    }

    /** @return array{0: DateTime, 1: DateTime} [periodStart, periodEnd] */
    private function nextSemiMonthlyPeriod(?DateTime $lastEnd, DateTime $today, int $cutoffDay): array {
        if ($cutoffDay < 1 || $cutoffDay > 28) {
            throw new InvalidArgumentException('Cutoff day not configured.');
        }
        $firstHalfEnd = function (DateTime $monthRef) use ($cutoffDay): DateTime {
            return (clone $monthRef)->setDate((int)$monthRef->format('Y'), (int)$monthRef->format('n'), $cutoffDay);
        };
        $secondHalfEnd = function (DateTime $monthRef): DateTime {
            return (clone $monthRef)->modify('last day of this month');
        };

        if ($lastEnd !== null) {
            $wasFirstHalf = (int)$lastEnd->format('j') === $cutoffDay;
            if ($wasFirstHalf) {
                $end = $secondHalfEnd($lastEnd);
                $start = (clone $lastEnd)->modify('+1 day');
            } else {
                $nextMonth = $this->addMonthsAnchored($lastEnd, 1);
                $end = $firstHalfEnd($nextMonth);
                $start = clone $nextMonth;
            }
            return [$start, $end];
        }

        $thisFirstHalfEnd = $firstHalfEnd($today);
        if ($today <= $thisFirstHalfEnd) {
            return [$this->addMonthsAnchored($today, 0), $thisFirstHalfEnd];
        }
        $thisSecondHalfEnd = $secondHalfEnd($today);
        if ($today <= $thisSecondHalfEnd) {
            return [(clone $thisFirstHalfEnd)->modify('+1 day'), $thisSecondHalfEnd];
        }
        $nextMonth = $this->addMonthsAnchored($today, 1);
        return [clone $nextMonth, $firstHalfEnd($nextMonth)];
    }

    /** @return array{0: DateTime, 1: DateTime} [periodStart, periodEnd] */
    private function nextWeekBasedPeriod(?DateTime $lastEnd, DateTime $today, string $dayOfWeek, int $lengthDays): array {
        if (!in_array($dayOfWeek, self::DAYS_OF_WEEK, true)) {
            throw new InvalidArgumentException('Day of week not configured.');
        }
        if ($lastEnd !== null) {
            $end = (clone $lastEnd)->modify("+{$lengthDays} days");
        } else {
            $end = $this->nextOccurrenceOfWeekday($today, $dayOfWeek, true);
        }
        $start = (clone $end)->modify('-' . ($lengthDays - 1) . ' days');
        return [$start, $end];
    }

    /** Next occurrence of $dayOfWeek on/after $from (or strictly after, if $inclusive is false). */
    private function nextOccurrenceOfWeekday(DateTime $from, string $dayOfWeek, bool $inclusive = false): DateTime {
        if (!in_array($dayOfWeek, self::DAYS_OF_WEEK, true)) {
            throw new InvalidArgumentException('Day of week not configured.');
        }
        $date = clone $from;
        if ($inclusive && strtolower($date->format('l')) === $dayOfWeek) {
            return $date;
        }
        return $date->modify("next {$dayOfWeek}");
    }

    private function isCycleNameDuplicate(int $compId, string $name, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `payroll_cycles` WHERE comp_id = :comp_id AND cycle_name = :cycle_name AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':cycle_name' => $name];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    // 2026-09-02: same duplicate-check shape as isCycleNameDuplicate() above -- case-insensitive
    // since the whole point is an exact join key with Origami's own admin-entered value, and admins
    // on either side re-typing the same code with different casing shouldn't create two "different"
    // matches that both look valid.
    private function isExternalCycleCodeDuplicate(int $compId, string $code, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `payroll_cycles` WHERE comp_id = :comp_id AND LOWER(external_cycle_code) = LOWER(:code) AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':code' => $code];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Resolves a "day of month or last day" pair from request data for a given field prefix.
     * Returns [dayOfMonth|null, useLastDay(0|1)] or null on validation failure.
     */
    private function resolveDayOfMonth(array $data, string $dayField, string $lastDayField): ?array {
        $useLastDay = !empty($data[$lastDayField]) ? 1 : 0;
        if ($useLastDay) {
            return [null, 1];
        }
        if (!isset($data[$dayField]) || $data[$dayField] === '' || !is_numeric($data[$dayField])) {
            return null;
        }
        $day = (int)$data[$dayField];
        if ($day < 1 || $day > 28) {
            return null;
        }
        return [$day, 0];
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['cycle_name', 'payroll_frequency', 'ot_cutoff_type', 'bank_file_format_id'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $cycleName = trim((string)$data['cycle_name']);
        if ($this->isCycleNameDuplicate($compId, $cycleName, $id)) {
            return ['status' => false, 'message' => 'This cycle name is already in use.'];
        }

        // 2026-09-02, Origami reply to the payroll-schedule-mapping gap (see matchForSyncProcess()'s
        // own docblock) -- OPTIONAL (unlike cycle_name above), so a company not yet coordinating
        // codes with Origami is unaffected. Only checked for duplicates among ACTIVE (non-deleted)
        // rows when actually set -- two cycles both left blank is not a conflict.
        $externalCycleCode = !empty($data['external_cycle_code']) ? trim((string)$data['external_cycle_code']) : null;
        if ($externalCycleCode !== null) {
            if (mb_strlen($externalCycleCode) > 100) {
                return ['status' => false, 'message' => 'external_cycle_code must be 100 characters or fewer.'];
            }
            if ($this->isExternalCycleCodeDuplicate($compId, $externalCycleCode, $id)) {
                return ['status' => false, 'message' => 'This external cycle code is already in use by another schedule.'];
            }
        }

        $frequency = (string)$data['payroll_frequency'];
        if (!in_array($frequency, ['monthly', 'semi_monthly', 'weekly', 'bi_weekly'], true)) {
            return ['status' => false, 'message' => 'Invalid payroll_frequency.'];
        }

        $bankFileFormatId = (int)$data['bank_file_format_id'];
        $stmtFormat = $this->db->prepare("SELECT id FROM `master_bank_file_formats` WHERE id = :id AND is_active = 1");
        $stmtFormat->execute([':id' => $bankFileFormatId]);
        if (!$stmtFormat->fetch()) {
            return ['status' => false, 'message' => 'Invalid bank_file_format_id.'];
        }

        // 2026-09-02, multi-bank-account payroll -- `bank_account_id` is no longer settable directly
        // through this method. It's now a DENORMALIZED shortcut owned exclusively by
        // saveBankAccounts() (kept in sync with whichever account in payroll_cycle_bank_accounts is
        // flagged is_default there) -- see that method's own docblock. This method simply never
        // touches the column anymore, so an UPDATE here can't silently desync/clear it.
        $defaultPaymentMethodId = !empty($data['default_payment_method_id']) ? (int)$data['default_payment_method_id'] : null;
        if ($defaultPaymentMethodId !== null) {
            $stmtMethod = $this->db->prepare("SELECT id FROM `master_payment_methods` WHERE id = :id AND is_active = 1");
            $stmtMethod->execute([':id' => $defaultPaymentMethodId]);
            if (!$stmtMethod->fetch()) {
                return ['status' => false, 'message' => 'Invalid default_payment_method_id.'];
            }
        }

        $cutoffDayOfMonth = null;
        $cutoffUseLastDay = 0;
        $cutoffDayOfWeek = null;
        $paymentDayOfMonth = null;
        $paymentUseLastDay = 0;
        $paymentDayOfWeek = null;

        if ($frequency === 'weekly') {
            $cutoffDayOfWeek = (string)($data['cutoff_day_of_week'] ?? '');
            $paymentDayOfWeek = (string)($data['payment_day_of_week'] ?? '');
            if (!in_array($cutoffDayOfWeek, self::DAYS_OF_WEEK, true)) {
                return ['status' => false, 'message' => 'Missing or invalid field: cutoff_day_of_week'];
            }
            if (!in_array($paymentDayOfWeek, self::DAYS_OF_WEEK, true)) {
                return ['status' => false, 'message' => 'Missing or invalid field: payment_day_of_week'];
            }
        } else {
            $cutoffResolved = $this->resolveDayOfMonth($data, 'cutoff_day_of_month', 'cutoff_use_last_day');
            if ($cutoffResolved === null) {
                return ['status' => false, 'message' => 'Cutoff day must be between 1-28, or use last day of month.'];
            }
            [$cutoffDayOfMonth, $cutoffUseLastDay] = $cutoffResolved;

            $paymentResolved = $this->resolveDayOfMonth($data, 'payment_day_of_month', 'payment_use_last_day');
            if ($paymentResolved === null) {
                return ['status' => false, 'message' => 'Payment day must be between 1-28, or use last day of month.'];
            }
            [$paymentDayOfMonth, $paymentUseLastDay] = $paymentResolved;
        }

        $otCutoffType = (string)$data['ot_cutoff_type'];
        if (!in_array($otCutoffType, ['same_as_attendance', 'custom'], true)) {
            return ['status' => false, 'message' => 'Invalid ot_cutoff_type.'];
        }
        $otCutoffDayOfMonth = null;
        $otCutoffUseLastDay = 0;
        if ($otCutoffType === 'custom') {
            $otResolved = $this->resolveDayOfMonth($data, 'ot_cutoff_day_of_month', 'ot_cutoff_use_last_day');
            if ($otResolved === null) {
                return ['status' => false, 'message' => 'OT cutoff day must be between 1-28, or use last day of month.'];
            }
            [$otCutoffDayOfMonth, $otCutoffUseLastDay] = $otResolved;
        }

        // 2026-09-02, Platform Hardening Phase 1.1 -- `status` is no longer sent by the Add/Edit
        // modal (the new row switch, see toggleStatus() below, is now the only way to change it,
        // same convention already applied to Branch/Role/Department/Position/Rank/Team/PED Type).
        // Fetch and preserve the EXISTING row's status when the field is absent from the payload,
        // same `$data['status'] ?? $existingStatus ?? 'active'` fix already applied once to
        // CompanyProfileModel::saveStructure() for the identical reason -- without this, every
        // ordinary Edit-and-Save would silently reset an intentionally-deactivated cycle back to
        // active.
        $existingStatus = null;
        if ($id !== null) {
            $stmtExistingStatus = $this->db->prepare("SELECT status FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtExistingStatus->execute([':id' => $id, ':comp_id' => $compId]);
            $existingStatus = $stmtExistingStatus->fetchColumn();
            $existingStatus = $existingStatus === false ? null : $existingStatus;
        }
        $statusInput = $data['status'] ?? $existingStatus ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : ($existingStatus ?: 'active');

        $params = [
            ':cycle_name' => $cycleName,
            ':payroll_frequency' => $frequency,
            ':external_cycle_code' => $externalCycleCode,
            ':cutoff_day_of_month' => $cutoffDayOfMonth,
            ':cutoff_use_last_day' => $cutoffUseLastDay,
            ':cutoff_day_of_week' => $cutoffDayOfWeek,
            ':payment_day_of_month' => $paymentDayOfMonth,
            ':payment_use_last_day' => $paymentUseLastDay,
            ':payment_day_of_week' => $paymentDayOfWeek,
            ':ot_cutoff_type' => $otCutoffType,
            ':ot_cutoff_day_of_month' => $otCutoffDayOfMonth,
            ':ot_cutoff_use_last_day' => $otCutoffUseLastDay,
            ':bank_file_format_id' => $bankFileFormatId,
            ':default_payment_method_id' => $defaultPaymentMethodId,
            ':status' => $status,
        ];

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `payroll_cycles` SET
                            cycle_name = :cycle_name, payroll_frequency = :payroll_frequency, external_cycle_code = :external_cycle_code,
                            cutoff_day_of_month = :cutoff_day_of_month, cutoff_use_last_day = :cutoff_use_last_day, cutoff_day_of_week = :cutoff_day_of_week,
                            payment_day_of_month = :payment_day_of_month, payment_use_last_day = :payment_use_last_day, payment_day_of_week = :payment_day_of_week,
                            ot_cutoff_type = :ot_cutoff_type, ot_cutoff_day_of_month = :ot_cutoff_day_of_month, ot_cutoff_use_last_day = :ot_cutoff_use_last_day,
                            bank_file_format_id = :bank_file_format_id, default_payment_method_id = :default_payment_method_id, status = :status,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `payroll_cycles`
                        (comp_id, cycle_name, payroll_frequency, external_cycle_code, cutoff_day_of_month, cutoff_use_last_day, cutoff_day_of_week,
                         payment_day_of_month, payment_use_last_day, payment_day_of_week,
                         ot_cutoff_type, ot_cutoff_day_of_month, ot_cutoff_use_last_day, bank_file_format_id, default_payment_method_id, status, created_by)
                    VALUES
                        (:comp_id, :cycle_name, :payroll_frequency, :external_cycle_code, :cutoff_day_of_month, :cutoff_use_last_day, :cutoff_day_of_week,
                         :payment_day_of_month, :payment_use_last_day, :payment_day_of_week,
                         :ot_cutoff_type, :ot_cutoff_day_of_month, :ot_cutoff_use_last_day, :bank_file_format_id, :default_payment_method_id, :status, :created_by)";
            $params[':comp_id'] = $compId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch (row-level, no confirm
    // needed on the model side, the shared frontend handler in app.js already confirms before
    // deactivating). Same shape as CompanyProfileModel::toggleStructureStatus().
    public function toggleStatus(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        try {
            $stmtUpdate = $this->db->prepare("UPDATE `payroll_cycles` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmtUpdate->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
            return ['status' => true, 'new_status' => $newStatus, 'message' => 'Updated successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `payroll_cycles` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
