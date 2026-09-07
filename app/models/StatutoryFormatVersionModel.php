<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/ReportRegistry.php';

/**
 * 2026-08-29, follow-up to Bank File Format: "ส่วน Format เอกสารของการนำส่งสรรพากร และ ประกันสังคม ก็อยาก
 * ให้มีการตั้งค่าเหมือนกัน แต่ดูความเหมาะสมว่าจะนำไปไว้ที่ฝั่งไหน" -- see
 * database/migrations/2026-08-29_statutory_format_versions.sql's own header comment for why this
 * is a VERSION SELECTOR (pick which known format version to file) rather than a free field editor
 * like BankFileFormatModel -- government-mandated byte-exact layouts aren't safe to let an admin
 * freely edit.
 *
 * `resolveVersionCode()` is the one method report generators (PndOneReport/Sso110Report) actually
 * call at generation time -- it's the single source of truth for "which version_code does this
 * company want," always returning a real value (falls back to the form's is_default=1 version, so
 * a company that never opens this settings tab still gets sensible behavior, not an error).
 *
 * 2026-09-04, Backlog Phase 9, T049: "redesign the document-handling section (per selected
 * city/country, what must be filed) for max usability" -- 2 real gaps closed together:
 *   1. Country scoping -- master_statutory_format_versions.country_code (new, nullable = applies
 *      to every country, see 2026-09-04_2_statutory_format_version_country_scope.sql's own header)
 *      is now filtered by the company's own registered_country, the SAME principle T045 already
 *      applied to statutory_items (see CompanyStatutorySettingModel::getCompanyCountry()'s own
 *      identical 1-line lookup -- deliberately duplicated per-model here too, this project's own
 *      established convention rather than a shared helper class). Before this, listForms() returned
 *      literally every distinct active form_code in the whole table with zero country filtering --
 *      harmless only by accident, since exactly 2 form_codes existed and both happened to be for
 *      Thailand.
 *   2. "What must be filed" -- settingsForCompany() now ALSO surfaces every ReportRegistry entry
 *      with reportType()==='statutory' whose code's own country prefix (e.g. 'TH_PND1' -> 'TH',
 *      matching ReportGeneratorInterface::code()'s own documented naming convention) matches the
 *      company's country, even the 4 (TH_PND1K_SUMMARY/TH_SSO609/TH_KOR20KOR/TH_SLF) that have NO
 *      master_statutory_format_versions row at all -- each has exactly ONE implementation (per
 *      CLAUDE.md), so a version PICKER (implying a real choice) would be worse UX than none; these
 *      render with `has_version_picker=false`, informational only, nothing to save. Previously a
 *      company had no single place on this settings page to see the FULL list of documents it must
 *      file, only the 2 that happened to already have a version picker.
 *   Every row's display label now comes from ReportGeneratorInterface::label() (bilingual, already
 *   maintained once per report class) rather than a separate `statutory_form_<code>` i18n key that
 *   would need a manual addition for every future country/form -- the opposite of "generic/
 *   extensible," which is this whole Phase 9's own recurring theme (see T047's docblock for the
 *   same reasoning applied to category/calc_base).
 */
class StatutoryFormatVersionModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Same 1-line lookup CompanyStatutorySettingModel::getCompanyCountry()/TaxStatutoryController::
     *  companyCountry() already use -- deliberately duplicated per-model, this project's own
     *  established convention rather than a shared helper class. */
    private function companyCountry(int $compId): ?string {
        $stmt = $this->db->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return ($country === false || $country === null || $country === '') ? null : (string)$country;
    }

    /** Every form_code WITH a real version picker, scoped to a country (NULL country_code rows are
     *  always included -- "applies to every country"). Pass null to see every form regardless of
     *  country (no current caller does this; kept for completeness/back-compat). */
    public function listForms(?string $countryCode = null): array {
        if ($countryCode === null) {
            $stmt = $this->db->query("SELECT DISTINCT form_code FROM master_statutory_format_versions WHERE is_active = 1 ORDER BY form_code ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $stmt = $this->db->prepare(
            "SELECT DISTINCT form_code FROM master_statutory_format_versions
             WHERE is_active = 1 AND (country_code = :country_code OR country_code IS NULL)
             ORDER BY form_code ASC"
        );
        $stmt->execute([':country_code' => $countryCode]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listVersions(string $formCode): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM master_statutory_format_versions WHERE form_code = :form_code AND is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':form_code' => $formCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['is_verified'] = (bool)$r['is_verified'];
            $r['is_default'] = (bool)$r['is_default'];
        }
        unset($r);
        return $rows;
    }

    /** Grouping key for the settings UI's section headers -- reuses the SAME 4 category codes
     *  T047's master_statutory_categories already established (tax/social_insurance/
     *  provident_fund/other) for visual consistency with the Statutory Rates tab right next to this
     *  one -- not a shared FK, just the same taxonomy applied to a different, unrelated list (report
     *  codes have no `category` column of their own). Derived from the code itself since that's the
     *  only classification signal available here. */
    private function reportCategory(string $code): string {
        if (str_contains($code, 'PND')) return 'tax';
        if (str_contains($code, 'SSO')) return 'social_insurance';
        if (str_contains($code, 'KOR')) return 'provident_fund';
        return 'other';
    }

    private function categorySortOrder(string $category): int {
        return match ($category) {
            'tax' => 10,
            'social_insurance' => 20,
            'provident_fund' => 30,
            default => 40,
        };
    }

    /** Full picker+checklist payload for the settings UI: every known form_code WITH a real version
     *  choice for this company's own registered country (has_version_picker=true), plus every OTHER
     *  statutory report this country must file that has no version choice of its own
     *  (has_version_picker=false, informational only) -- see this class's own docblock. Empty
     *  ('items' => []) for a company with no registered country at all, same "nothing to show
     *  without a country" precedent CompanyStatutorySettingModel::list() already established. */
    public function settingsForCompany(int $compId): array {
        $countryCode = $this->companyCountry($compId);
        if ($countryCode === null) {
            return ['country_code' => null, 'country_name_th' => null, 'country_name_en' => null, 'items' => []];
        }

        $countryStmt = $this->db->prepare("SELECT countries_name_th, countries_name_en FROM master_countries WHERE countries_code = :cc");
        $countryStmt->execute([':cc' => $countryCode]);
        $countryRow = $countryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        $coveredCodes = [];
        foreach ($this->listForms($countryCode) as $formCode) {
            $coveredCodes[$formCode] = true;
            $versions = $this->listVersions($formCode);
            $stmt = $this->db->prepare(
                "SELECT version_id FROM company_statutory_format_settings WHERE comp_id = :comp_id AND form_code = :form_code"
            );
            $stmt->execute([':comp_id' => $compId, ':form_code' => $formCode]);
            $selectedId = $stmt->fetchColumn();
            if ($selectedId === false) {
                foreach ($versions as $v) {
                    if ($v['is_default']) {
                        $selectedId = $v['id'];
                        break;
                    }
                }
            }
            $report = ReportRegistry::get($formCode);
            $items[] = [
                'form_code' => $formCode,
                'category' => $this->reportCategory($formCode),
                'has_version_picker' => true,
                'versions' => $versions,
                'selected_version_id' => $selectedId !== false ? (int)$selectedId : null,
                'label' => $report ? $report->label() : null,
                'is_verified' => null, // per-version verified/draft badge already conveys this
            ];
        }
        // T049: every OTHER statutory report this country must file, with no version choice of its
        // own -- informational rows completing the "what must be filed" checklist.
        foreach (ReportRegistry::byType('statutory') as $report) {
            $code = $report->code();
            if (isset($coveredCodes[$code])) {
                continue; // already listed above as a real version picker
            }
            $prefix = strtoupper(explode('_', $code, 2)[0]);
            if ($prefix !== strtoupper($countryCode)) {
                continue; // a different country's report -- never shown here
            }
            $items[] = [
                'form_code' => $code,
                'category' => $this->reportCategory($code),
                'has_version_picker' => false,
                'versions' => [],
                'selected_version_id' => null,
                'label' => $report->label(),
                'is_verified' => $report->isVerified(),
            ];
        }
        usort($items, function ($a, $b) {
            $catCmp = $this->categorySortOrder($a['category']) <=> $this->categorySortOrder($b['category']);
            return $catCmp !== 0 ? $catCmp : strcmp($a['form_code'], $b['form_code']);
        });

        return [
            'country_code' => $countryCode,
            'country_name_th' => $countryRow['countries_name_th'] ?? null,
            'country_name_en' => $countryRow['countries_name_en'] ?? null,
            'items' => $items,
        ];
    }

    /** The one method report generators call. Always returns a real version_code (falls back to
     *  the form's default version) -- null only when the form_code has no seeded versions at all
     *  (a form this settings surface has never been told about). */
    public function resolveVersionCode(int $compId, string $formCode): ?string {
        $stmt = $this->db->prepare(
            "SELECT v.version_code
             FROM company_statutory_format_settings s
             INNER JOIN master_statutory_format_versions v ON v.id = s.version_id
             WHERE s.comp_id = :comp_id AND s.form_code = :form_code"
        );
        $stmt->execute([':comp_id' => $compId, ':form_code' => $formCode]);
        $code = $stmt->fetchColumn();
        if ($code !== false) {
            return (string)$code;
        }
        $stmtDefault = $this->db->prepare(
            "SELECT version_code FROM master_statutory_format_versions WHERE form_code = :form_code AND is_default = 1 AND is_active = 1 LIMIT 1"
        );
        $stmtDefault->execute([':form_code' => $formCode]);
        $defaultCode = $stmtDefault->fetchColumn();
        return $defaultCode !== false ? (string)$defaultCode : null;
    }

    public function saveSelection(int $compId, string $formCode, int $versionId, ?int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT id FROM master_statutory_format_versions WHERE id = :id AND form_code = :form_code AND is_active = 1"
        );
        $stmt->execute([':id' => $versionId, ':form_code' => $formCode]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Invalid version for this form.'];
        }
        $stmtExisting = $this->db->prepare(
            "SELECT id FROM company_statutory_format_settings WHERE comp_id = :comp_id AND form_code = :form_code"
        );
        $stmtExisting->execute([':comp_id' => $compId, ':form_code' => $formCode]);
        $existingId = $stmtExisting->fetchColumn();
        if ($existingId) {
            $this->db->prepare(
                "UPDATE company_statutory_format_settings SET version_id = :version_id, updated_by = :user_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':version_id' => $versionId, ':user_id' => $userId, ':id' => $existingId]);
        } else {
            $this->db->prepare(
                "INSERT INTO company_statutory_format_settings (comp_id, form_code, version_id, created_by) VALUES (:comp_id, :form_code, :version_id, :user_id)"
            )->execute([':comp_id' => $compId, ':form_code' => $formCode, ':version_id' => $versionId, ':user_id' => $userId]);
        }
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
