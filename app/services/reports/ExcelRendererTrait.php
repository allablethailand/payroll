<?php
declare(strict_types=1);

/**
 * Shared PhpSpreadsheet setup for report generators. Excel/XLSX stores text as UTF-8 in XML
 * (no font-embedding/glyph-shaping concerns like PDF) — a write/read round-trip of Thai text
 * was verified byte-for-byte identical, so this is the more reliable format for anything that
 * needs to be reopened/edited by the user rather than just viewed.
 */
trait ExcelRendererTrait {
    /**
     * @param string[] $headers
     * @param array<int,array<int,mixed>> $rows
     */
    protected function renderExcelFromRows(array $headers, array $rows, string $sheetTitle = 'Report'): string {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $safeTitle = preg_replace('/[\[\]\*\/\\\\\?:]/', '', $sheetTitle);
        $sheet->setTitle(substr($safeTitle !== null ? $safeTitle : 'Report', 0, 31));

        // Cell-by-cell with an explicit string type for PHP string values, instead of
        // fromArray()'s default type inference — fromArray() treats any numeric-looking string
        // (e.g. an SSO number, tax ID, or bank account number) as a numeric cell, which silently
        // drops a leading zero if the real value ever has one. Only genuinely numeric PHP values
        // (float/int, e.g. amounts) get left as numbers so Excel can still sum/format them.
        foreach (array_values($headers) as $colIdx => $value) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . '1';
            $this->setCellPreservingType($sheet, $coord, $value);
        }
        foreach ($rows as $rowIdx => $row) {
            foreach (array_values($row) as $colIdx => $value) {
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . (string)($rowIdx + 2);
                $this->setCellPreservingType($sheet, $coord, $value);
            }
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string)ob_get_clean();
    }

    private function setCellPreservingType(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $coord, mixed $value): void {
        if (is_string($value)) {
            $sheet->setCellValueExplicit($coord, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($coord, $value);
        }
    }
}
