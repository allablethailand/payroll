<?php
declare(strict_types=1);

/**
 * Backlog Phase 11, T064 (part 2 of 4: model trait consolidation). Shared logic between
 * `PayslipTemplateModel` and `EmploymentCertificateTemplateModel` -- both are canvas-designer
 * backends for a percentage-positioned text/image/shape/table element set, and share 90 of ~97
 * top-level JS functions on the frontend (see the parallel Canvas JS mechanics extraction fork) plus
 * a large, genuinely-identical slice of their own PHP model logic.
 *
 * **Scope discipline**: every method below was diffed body-for-body against both classes before
 * being moved here -- this trait deliberately does NOT contain every identically-NAMED method the
 * two classes share (22 of ~24). Several look like duplicates by name but have real, documented,
 * deliberate differences this trait must not flatten:
 *   - `list()`/`getDefault()`/`get()` -- Payslip filters `deleted_at IS NULL` (its own `status` enum
 *     has a genuine 3rd 'inactive' value, so `status='active'` alone would wrongly exclude an
 *     inactive-but-not-deleted template from the list); ECT's `status` enum only ever has
 *     'active'/'deleted', so `status='active'` alone is already correct for it. This is NOT drift --
 *     it's a real consequence of the two classes' own differently-shaped `status` column -- left as
 *     two separate methods.
 *   - `findConflictingAssignment()`/`validateAssignments()` -- Payslip's own `validateAssignments()`
 *     conditionally SKIPS the conflict check entirely when the template being saved is itself
 *     'inactive' (a real state Payslip has and ECT doesn't); ECT's own version has no such branch.
 *     Left as two separate methods rather than risk collapsing real control-flow divergence into one
 *     over-parameterized method.
 *   - `save()`/`duplicate()`/`duplicatePair()`/`generateOtherLanguage()` -- Payslip's own payload
 *     carries real fields ECT has no equivalent of at all (`header_text_th/en`, `footer_text_th/en`,
 *     `status` as a 3-value enum); flattening these into one shared method would either silently drop
 *     Payslip-only fields or force ECT to carry dead ones. Left as two separate methods.
 *   - `isValidLogoPath()`/`isValidImageAssetPath()` -- tiny (5-line) static methods with a real
 *     per-module upload-folder-name difference baked into the regex; part of this app's public
 *     upload-validation surface (called from controllers outside this pair), low duplication payoff
 *     relative to the risk of touching that surface. Left alone.
 *
 * What IS in this trait: methods with either (a) genuinely zero query-shape difference at all
 * (`validateScopeRef()`/`assignableOptions()`/`scopeLabel()` reference only `structure_departments`/
 * `structure_teams`/`employees` -- tables neither module owns, so there was never anything to
 * diverge), (b) `validateElements()`, which is byte-identical logic that already correctly delegates
 * to `$this->fieldTypeOptions()` polymorphically (each class keeps its own version, querying its own
 * `master_payslip_field_types`/`master_employment_certificate_field_types` table -- PHP resolves
 * `$this->` against the real object even from inside a trait method, so this "just works" without
 * any parameterization), or (c) `getElements()`/`getAssignments()`/`listImages()`/`addImage()`/
 * `deleteImage()`, which are structurally identical except for which table they read/write -- made
 * safe to share via 3 tiny `abstract protected` table-name methods each class already implements
 * with one line (this is the standard PHP "trait + abstract method" template pattern, no new
 * dependency/convention introduced).
 */
trait TemplateDesignerModelTrait {
    /** e.g. 'payslip_template_elements' / 'employment_certificate_template_elements'. */
    abstract protected function elementsTableName(): string;
    /** e.g. 'payslip_template_assignments' / 'employment_certificate_template_assignments'. */
    abstract protected function assignmentsTableName(): string;
    /** e.g. 'payslip_images' / 'employment_certificate_images'. */
    abstract protected function imagesTableName(): string;

    protected function getElements(int $templateId): array {
        $table = $this->elementsTableName();
        $stmt = $this->db->prepare("SELECT id, element_type, field_key, image_asset_id, content,
                pos_x_pct, pos_y_pct, width_pct, height_pct, font_size, font_family, font_color,
                text_align, font_weight, font_style, text_decoration, sort_order, group_key, page_number, is_visible
            FROM `{$table}` WHERE template_id = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Raw scope rows plus resolved display names (department/team name, employee no+name) for the
     *  editor's own "Assign To" picker to pre-fill with human-readable chips, not just bare ids. */
    public function getAssignments(int $templateId): array {
        $table = $this->assignmentsTableName();
        $stmt = $this->db->prepare("SELECT id, scope_type, scope_id FROM `{$table}` WHERE template_id = :id ORDER BY scope_type ASC, id ASC");
        $stmt->execute([':id' => $templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['label'] = $this->scopeLabel($row['scope_type'], (int)$row['scope_id']);
        }
        unset($row);
        return $rows;
    }

    /** Polymorphic scope_id validation -- mirrors SetupRulesModel::validateScopeRef() exactly (same
     *  3-table switch, minus 'shift'/'position' which don't apply here). Zero template-specific
     *  table reference -- genuinely identical between Payslip/ECT from the start, not parameterized. */
    private function validateScopeRef(string $scopeType, int $scopeId, int $compId): bool {
        $table = match ($scopeType) {
            'department' => 'structure_departments',
            'team' => 'structure_teams',
            'employee' => 'employees',
            default => null,
        };
        if ($table === null) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    /**
     * All active departments/teams/employees for this company, for the "Assign To" tab's checkbox
     * lists. Zero template-specific table reference -- genuinely identical between Payslip/ECT.
     */
    public function assignableOptions(int $compId): array {
        $stmtD = $this->db->prepare("SELECT id, department_name_th AS text_th, department_name_en AS text_en
            FROM `structure_departments` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY department_name_th ASC");
        $stmtD->execute([':comp_id' => $compId]);
        $stmtT = $this->db->prepare("SELECT id, team_name_th AS text_th, team_name_en AS text_en
            FROM `structure_teams` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY team_name_th ASC");
        $stmtT->execute([':comp_id' => $compId]);
        $stmtE = $this->db->prepare("SELECT id, CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS text_th,
                CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS text_en
            FROM `employees` WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY name_th ASC");
        $stmtE->execute([':comp_id' => $compId]);
        return [
            'departments' => $stmtD->fetchAll(PDO::FETCH_ASSOC),
            'teams' => $stmtT->fetchAll(PDO::FETCH_ASSOC),
            'employees' => $stmtE->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Zero template-specific table reference -- genuinely identical between Payslip/ECT. */
    private function scopeLabel(string $scopeType, int $scopeId): string {
        if ($scopeType === 'department') {
            $stmt = $this->db->prepare("SELECT department_name_th AS th, department_name_en AS en FROM `structure_departments` WHERE id = :id");
        } elseif ($scopeType === 'team') {
            $stmt = $this->db->prepare("SELECT team_name_th AS th, team_name_en AS en FROM `structure_teams` WHERE id = :id");
        } else {
            $stmt = $this->db->prepare("SELECT CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS th, CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS en FROM `employees` WHERE id = :id");
        }
        $stmt->execute([':id' => $scopeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return "(#{$scopeId})";
        }
        return trim((string)$row['th']) !== '' ? $row['th'] : (string)$row['en'];
    }

    /**
     * @return array{elements?: array, error?: string}
     * Byte-identical validation logic between Payslip/ECT -- the one place this depends on
     * per-class data (`$this->fieldTypeOptions()`, which queries each class's own distinct master
     * table) resolves correctly on its own since `$this` inside a trait method is always the real
     * using object, not the trait itself -- no parameterization needed for that call.
     */
    private function validateElements(array $rawElements): array {
        $fieldTypes = [];
        foreach ($this->fieldTypeOptions() as $ft) {
            $fieldTypes[$ft['code']] = $ft;
        }
        $cleaned = [];
        foreach ($rawElements as $i => $raw) {
            $n = $i + 1;
            $elementType = (string)($raw['element_type'] ?? 'text');
            if (!in_array($elementType, self::ELEMENT_TYPES, true)) {
                return ['error' => "Element {$n}: invalid element_type."];
            }
            $fieldKey = !empty($raw['field_key']) ? (string)$raw['field_key'] : null;
            $imageAssetId = !empty($raw['image_asset_id']) && is_numeric($raw['image_asset_id']) ? (int)$raw['image_asset_id'] : null;
            if ($elementType === 'image') {
                if ($fieldKey !== null) {
                    if (!isset($fieldTypes[$fieldKey]) || $fieldTypes[$fieldKey]['element_type'] !== 'image') {
                        return ['error' => "Element {$n}: invalid image field_key."];
                    }
                    $imageAssetId = null;
                } elseif ($imageAssetId === null) {
                    return ['error' => "Element {$n}: an image element needs either field_key or image_asset_id."];
                }
                $content = null;
            } elseif ($elementType === 'shape') {
                if (!in_array($fieldKey, self::SHAPE_TYPES, true)) {
                    return ['error' => "Element {$n}: invalid shape type."];
                }
                $imageAssetId = null;
                $content = null;
            } elseif ($elementType === 'table') {
                $tableData = json_decode((string)($raw['content'] ?? ''), true);
                if (!is_array($tableData)) {
                    return ['error' => "Element {$n}: invalid table data."];
                }
                $rows = (int)($tableData['rows'] ?? 0);
                $cols = (int)($tableData['cols'] ?? 0);
                if ($rows < 1 || $rows > 20 || $cols < 1 || $cols > 10) {
                    return ['error' => "Element {$n}: table rows/cols out of range (1-20 rows, 1-10 cols)."];
                }
                $borderColor = (string)($tableData['border_color'] ?? '#000000');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $borderColor)) {
                    return ['error' => "Element {$n}: table border_color must be a #rrggbb hex value."];
                }
                $borderWidth = (int)($tableData['border_width'] ?? 1);
                $borderWidth = max(0, min(10, $borderWidth));
                $rawCells = is_array($tableData['cells'] ?? null) ? $tableData['cells'] : [];
                $cells = [];
                for ($r = 0; $r < $rows; $r++) {
                    $rowCells = [];
                    for ($c = 0; $c < $cols; $c++) {
                        $rowCells[] = trim((string)($rawCells[$r][$c] ?? ''));
                    }
                    $cells[] = $rowCells;
                }
                $content = json_encode(['rows' => $rows, 'cols' => $cols, 'border_color' => $borderColor, 'border_width' => $borderWidth, 'cells' => $cells]);
                $fieldKey = null;
                $imageAssetId = null;
            } else {
                $content = trim((string)($raw['content'] ?? ''));
                if ($content === '') {
                    return ['error' => "Element {$n}: text content is required."];
                }
                $fieldKey = null;
                $imageAssetId = null;
            }
            $posX = is_numeric($raw['pos_x_pct'] ?? null) ? (float)$raw['pos_x_pct'] : null;
            $posY = is_numeric($raw['pos_y_pct'] ?? null) ? (float)$raw['pos_y_pct'] : null;
            $width = is_numeric($raw['width_pct'] ?? null) ? (float)$raw['width_pct'] : null;
            $height = is_numeric($raw['height_pct'] ?? null) ? (float)$raw['height_pct'] : null;
            if ($posX === null || $posY === null || $width === null || $height === null
                || $posX < 0 || $posX > 100 || $posY < 0 || $posY > 100 || $width <= 0 || $width > 100 || $height <= 0 || $height > 100) {
                return ['error' => "Element {$n}: position/size must be within the page (0-100%)."];
            }
            $fontSize = is_numeric($raw['font_size'] ?? null) ? (int)$raw['font_size'] : 14;
            if ($fontSize < 6 || $fontSize > 96) {
                return ['error' => "Element {$n}: font_size out of range."];
            }
            $textAlign = (string)($raw['text_align'] ?? 'left');
            if (!in_array($textAlign, self::TEXT_ALIGNS, true)) {
                return ['error' => "Element {$n}: invalid text_align."];
            }
            $fontWeight = (string)($raw['font_weight'] ?? 'normal');
            if (!in_array($fontWeight, self::FONT_WEIGHTS, true)) {
                return ['error' => "Element {$n}: invalid font_weight."];
            }
            $fontStyle = (string)($raw['font_style'] ?? 'normal');
            if (!in_array($fontStyle, self::FONT_STYLES, true)) {
                return ['error' => "Element {$n}: invalid font_style."];
            }
            $textDecoration = (string)($raw['text_decoration'] ?? 'none');
            if (!in_array($textDecoration, self::TEXT_DECORATIONS, true)) {
                return ['error' => "Element {$n}: invalid text_decoration."];
            }
            $fontFamily = (string)($raw['font_family'] ?? 'th_sarabun_new');
            if (!in_array($fontFamily, self::FONT_FAMILIES, true)) {
                return ['error' => "Element {$n}: invalid font_family."];
            }
            $fontColor = (string)($raw['font_color'] ?? '#000000');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $fontColor)) {
                return ['error' => "Element {$n}: font_color must be a #rrggbb hex value."];
            }
            $groupKey = !empty($raw['group_key']) ? substr((string)$raw['group_key'], 0, 64) : null;
            $pageNumber = is_numeric($raw['page_number'] ?? null) ? (int)$raw['page_number'] : 1;
            $pageNumber = max(1, min(self::MAX_PAGE_NUMBER, $pageNumber));
            $isVisible = !array_key_exists('is_visible', $raw) || !empty($raw['is_visible']) ? 1 : 0;
            $cleaned[] = [
                'element_type' => $elementType, 'field_key' => $fieldKey, 'image_asset_id' => $imageAssetId, 'content' => $content,
                'pos_x_pct' => $posX, 'pos_y_pct' => $posY, 'width_pct' => $width, 'height_pct' => $height,
                'font_size' => $fontSize, 'font_family' => $fontFamily, 'font_color' => $fontColor,
                'text_align' => $textAlign, 'font_weight' => $fontWeight, 'font_style' => $fontStyle, 'text_decoration' => $textDecoration,
                'sort_order' => $n, 'group_key' => $groupKey, 'page_number' => $pageNumber, 'is_visible' => $isVisible,
            ];
        }
        return ['elements' => $cleaned];
    }

    /* ==================== Reusable uploaded-image library -- company-wide, not tied to one
       template. Structurally identical between Payslip/ECT except which table it reads/writes. ==================== */

    public function listImages(int $compId): array {
        $table = $this->imagesTableName();
        $stmt = $this->db->prepare("SELECT id, file_path, file_size, thumbnail_path, original_filename, uploaded_at
            FROM `{$table}` WHERE comp_id = :comp_id ORDER BY uploaded_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addImage(int $compId, string $filePath, ?string $originalFilename, int $userId, ?int $fileSize = null, ?string $thumbnailPath = null): array {
        if (!static::isValidImageAssetPath($filePath, $compId)) {
            return ['status' => false, 'message' => 'Invalid file path.'];
        }
        $table = $this->imagesTableName();
        $stmt = $this->db->prepare("INSERT INTO `{$table}` (comp_id, file_path, file_size, thumbnail_path, original_filename, uploaded_by)
            VALUES (:comp_id, :file_path, :file_size, :thumbnail_path, :original_filename, :uploaded_by)");
        $stmt->execute([':comp_id' => $compId, ':file_path' => $filePath, ':file_size' => $fileSize, ':thumbnail_path' => $thumbnailPath, ':original_filename' => $originalFilename, ':uploaded_by' => $userId]);
        return ['status' => true, 'message' => 'Uploaded successfully.', 'id' => (int)$this->db->lastInsertId()];
    }

    /** Hard delete (the file itself is removed too, by the controller) -- blocked while any template
     *  element still references it. */
    public function deleteImage(int $compId, int $id): array {
        $imagesTable = $this->imagesTableName();
        $stmt = $this->db->prepare("SELECT file_path FROM `{$imagesTable}` WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $elementsTable = $this->elementsTableName();
        $stmtUsage = $this->db->prepare("SELECT COUNT(*) FROM `{$elementsTable}` WHERE image_asset_id = :id");
        $stmtUsage->execute([':id' => $id]);
        if ((int)$stmtUsage->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This image is still used by at least one template and cannot be deleted.'];
        }
        $this->db->prepare("DELETE FROM `{$imagesTable}` WHERE id = :id")->execute([':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.', 'file_path' => $row['file_path']];
    }
}
