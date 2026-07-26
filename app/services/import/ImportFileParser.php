<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Parses an uploaded .csv/.xlsx into an array of associative rows keyed by the FILE's own header
 * text (row 1) -- header-to-internal-field mapping is a separate step (ImportService::mapRows())
 * so this class stays a dumb, format-agnostic reader. Reuses PhpOffice/PhpSpreadsheet (already a
 * composer dependency for Excel report exports) for both formats rather than adding a separate
 * CSV library.
 */
class ImportFileParser {
    /**
     * @return array<int, array<string, mixed>> one entry per data row (row 1 is consumed as headers)
     * @throws InvalidArgumentException on an unsupported extension or an unreadable/empty file.
     */
    public function parse(string $filePath, string $originalFilename): array {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $reader = new CsvReader();
            $reader->setDelimiter(',');
            $reader->setInputEncoding('UTF-8');
        } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
            $reader = new XlsxReader();
        } else {
            throw new InvalidArgumentException('Unsupported file type. Upload a .csv or .xlsx file.');
        }

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if (empty($rows)) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $headers = array_map(fn($h) => trim((string)$h), array_shift($rows));
        if (empty(array_filter($headers))) {
            throw new InvalidArgumentException('The file has no header row.');
        }

        $result = [];
        foreach ($rows as $row) {
            // Skip fully-blank rows (common trailing-rows artifact from Excel).
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $row[$i] ?? null;
            }
            $result[] = $assoc;
        }
        return $result;
    }
}
