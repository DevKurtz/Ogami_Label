<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

class WorkbookImporter
{
    private const FIELD_LABELS = [
        'serialNumber' => 'serial number',
        'partNumber' => 'part number',
        'supplierPn' => 'supplier part number',
        'partName' => 'part name',
        'quantity' => 'quantity',
        'modelName' => 'model name',
        'cartonWeight' => 'carton weight',
        'supplierNumber' => 'supplier number',
        'mfgDate' => 'manufacturing date',
        'serialNo' => 'serial number',
        'dateCode' => 'date code',
        'serialCode' => 'serial code',
        'quantityCode' => 'quantity code',
        'partNumberCode' => 'part number code',
        'supplierCode' => 'supplier code',
        'qrCode' => 'QR code',
    ];

    private const FIELDS = [
        'serialNumber' => ['SERIAL NUMBER'],
        'partNumber' => ['PART NUMBER①', 'PART NUMBER1', 'PART NUMBER 1', 'PART NO.', 'PART NO'],
        // Only a clearly named Supplier PN column belongs in this label cell.
        // PART NUMBER② is a separate customer code and must never be copied here.
        'supplierPn' => ['SUPPLIER PART NUMBER', 'SUPPLIER PN'],
        'partName' => ['PART NAME', 'PARTS DESCRIPTION', 'DESCRIPTION'],
        'quantity' => ['QUANTITY', 'QTY'],
        'modelName' => ['MODEL NAME', 'MODEL'],
        'cartonWeight' => ['CARTON WEIGHT', 'CARTON WEIGHT (KG)', 'WEIGHT'],
        'supplierNumber' => ['SUPPLIER NUMBER', 'SUPPLIER NO.', 'VENDOR NUMBER'],
        'mfgDate' => ['MFG. DATE', 'MFG DATE', 'MANUFACTURING DATE'],
        'serialNo' => ['SERIAL NO.', 'SERIAL NO'],
        'dateCode' => ['DATECODE', 'DATE CODE'],
        'serialCode' => ['SERIAL CODE'],
        'quantityCode' => ['QUANTITY CODE'],
        'partNumberCode' => ['PART NUMBER CODE'],
        'supplierCode' => ['SUPPLIER CODE'],
        'qrCode' => ['QR CODE'],
    ];

    public function analyze(string $path): array
    {
        $book = IOFactory::load($path);
        $tables = [];

        foreach ($book->getAllSheets() as $sheetIndex => $sheet) {
            if ($sheet->getSheetState() !== \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE) continue;
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $lastRow = $sheet->getHighestDataRow();
            if ($lastRow > 50000 || $lastColumn > 250) throw new RuntimeException("Worksheet “{$sheet->getTitle()}” is too large to analyze safely. Keep each worksheet within 50,000 rows and 250 columns.");
            $segments = [];
            $segmentStart = null;

            for ($row = 1; $row <= $lastRow + 1; $row++) {
                $hasValues = false;
                if ($row <= $lastRow) {
                    for ($column = 1; $column <= $lastColumn; $column++) {
                        $value = $sheet->getCell([$column, $row])->getValue();
                        if ($value !== null && trim((string) $value) !== '') { $hasValues = true; break; }
                    }
                }
                if ($hasValues && $segmentStart === null) $segmentStart = $row;
                if (!$hasValues && $segmentStart !== null) {
                    $segments[] = [$segmentStart, $row - 1];
                    $segmentStart = null;
                }
            }

            foreach ($segments as [$firstRow, $lastSegmentRow]) {
                $headerRow = $firstRow;
                $bestScore = -1;
                for ($row = $firstRow; $row <= min($lastSegmentRow, $firstRow + 8); $row++) {
                    $values = $sheet->rangeToArray("A{$row}:{$sheet->getHighestDataColumn()}{$row}", null, false, false)[0] ?? [];
                    $nonEmpty = array_values(array_filter($values, fn ($value) => trim((string) $value) !== ''));
                    $joined = Str::lower(implode(' ', array_map('strval', $nonEmpty)));
                    $score = min(count($nonEmpty), 6) + preg_match_all('/part|quantity|serial|code|date|name|supplier|weight|model|description/i', $joined);
                    if (count($nonEmpty) >= 2 && $score > $bestScore) { $bestScore = $score; $headerRow = $row; }
                }

                $headers = [];
                $occupiedColumns = [];
                for ($column = 1; $column <= $lastColumn; $column++) {
                    $value = trim((string) $sheet->getCell([$column, $headerRow])->getFormattedValue());
                    $headers[$column] = $value;
                    if ($value !== '') $occupiedColumns[] = $column;
                }
                if (count($occupiedColumns) < 2) continue;

                // Empty header columns often separate adjacent tables in the same worksheet.
                $columnGroups = [];
                foreach ($occupiedColumns as $column) {
                    $lastGroup = array_key_last($columnGroups);
                    if ($lastGroup === null || $column > $columnGroups[$lastGroup][1] + 1) $columnGroups[] = [$column, $column];
                    else $columnGroups[$lastGroup][1] = $column;
                }
                foreach ($columnGroups as [$startColumn, $endColumn]) {
                    $groupHeaders = [];
                    foreach (range($startColumn, $endColumn) as $column) if ($headers[$column] !== '') $groupHeaders[] = $headers[$column];
                    if (count($groupHeaders) < 2 || $lastSegmentRow <= $headerRow) continue;
                    $sample = [];
                    for ($row = $headerRow + 1; $row <= $lastSegmentRow && count($sample) < 3; $row++) {
                        $values = [];
                        for ($column = $startColumn; $column <= $endColumn; $column++) $values[] = $sheet->getCell([$column, $row])->getFormattedValue();
                        if (count(array_filter($values, fn ($value) => trim((string) $value) !== ''))) $sample[] = $values;
                    }
                    $dataRows = 0;
                    for ($row = $headerRow + 1; $row <= $lastSegmentRow; $row++) {
                        for ($column = $startColumn; $column <= $endColumn; $column++) {
                            if (trim((string) $sheet->getCell([$column, $row])->getFormattedValue()) !== '') { $dataRows++; break; }
                        }
                    }
                    $tables[] = [
                        'id' => "s{$sheetIndex}-r{$headerRow}-c{$startColumn}-{$endColumn}",
                        'tableName' => 'Table '.(count(array_filter($tables, fn ($table) => $table['sheetIndex'] === $sheetIndex)) + 1),
                        'sheetIndex' => $sheetIndex,
                        'sheetName' => $sheet->getTitle(),
                        // A one-row block is often a title/metadata range (for example MASTER)
                        // adjacent to the actual records. Keep it selectable, but don't import it
                        // automatically alongside a larger data table.
                        'defaultSelected' => $dataRows > 1,
                        'selectionNote' => $dataRows === 1 ? 'Only one data row was detected. Select this block if it is a real label table.' : null,
                        'headerRow' => $headerRow,
                        'startColumn' => $startColumn,
                        'endColumn' => $endColumn,
                        'endRow' => $lastSegmentRow,
                        'headers' => array_map(fn ($column) => $headers[$column] !== '' ? $headers[$column] : "Column {$column}", range($startColumn, $endColumn)),
                        'legacyFormat' => $this->isLegacyFormat($groupHeaders),
                        'sample' => $sample,
                        'codeColumns' => array_values(array_map(function ($header) {
                            $normalized = $this->normalize($header);
                            $suggested = str_contains($normalized, 'QR') ? 'qr' : (str_contains($normalized, 'BARCODE') || (preg_match('/SERIAL|QUANTITY|PARTNUMBER|SUPPLIER|DATECODE/', $normalized) && str_contains($normalized, 'CODE')) ? 'barcode' : null);
                            return ['header' => $header, 'suggestedType' => $suggested];
                        }, array_filter($groupHeaders, fn ($header) => preg_match('/code|barcode|qr/i', $header)))),
                        'rowCount' => $dataRows,
                    ];
                }
            }
        }

        $hasPrimaryLabelTable = collect($tables)->contains(fn ($table) =>
            $table['rowCount'] > 1 && !preg_match('/^(MASTER|SUMMARY|TEMPLATE|LOOKUP|REFERENCE)$/i', trim($table['sheetName']))
        );
        if ($hasPrimaryLabelTable) {
            // Keep auxiliary sheets available for deliberate selection, but don't import
            // reference/master tabs automatically when a real multi-row label table exists.
            foreach ($tables as &$table) {
                if (preg_match('/^(MASTER|SUMMARY|TEMPLATE|LOOKUP|REFERENCE)$/i', trim($table['sheetName']))) {
                    $table['defaultSelected'] = false;
                    $table['selectionNote'] = 'Reference or template sheet; not selected automatically because another label table was found.';
                }
            }
            unset($table);
        }

        if ($tables && !collect($tables)->contains(fn ($table) => $table['defaultSelected'])) {
            // A workbook containing only one-row tables may be a valid one-label import.
            // In that case keep the detected blocks selected and let the user review them.
            foreach ($tables as &$table) {
                $table['defaultSelected'] = true;
                $table['selectionNote'] = null;
            }
            unset($table);
        }

        $tableSheetIndexes = array_unique(array_column($tables, 'sheetIndex'));
        $detectedSheets = [];
        foreach ($book->getAllSheets() as $index => $sheet) {
            if (in_array($index, $tableSheetIndexes, true)) $detectedSheets[] = ['index' => $index, 'name' => $sheet->getTitle()];
        }

        return ['sheets' => $detectedSheets, 'tables' => $tables];
    }

    public function import(string $path, ?array $table = null): array
    {
        $book = IOFactory::load($path);
        $sheetIndex = (int) ($table['sheetIndex'] ?? 0);
        if (!isset($book->getAllSheets()[$sheetIndex])) throw new RuntimeException('The selected worksheet could not be found. Analyze the workbook again.');
        $sheet = $book->getSheet($sheetIndex);
        $headerRow = (int) ($table['headerRow'] ?? 1);
        $startColumn = (int) ($table['startColumn'] ?? 1);
        $endColumn = (int) ($table['endColumn'] ?? Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        $lastRow = min((int) ($table['endRow'] ?? $sheet->getHighestDataRow()), $sheet->getHighestDataRow());
        if ($headerRow < 1 || $startColumn < 1 || $endColumn < $startColumn || $endColumn > 250 || $lastRow > 50000 || $headerRow >= $lastRow) throw new RuntimeException('The selected table has no data rows or exceeds the supported worksheet size. Analyze the workbook again and choose another table.');
        $headers = [];
        for ($column = $startColumn; $column <= $endColumn; $column++) {
            $header = trim((string) $sheet->getCell([$column, $headerRow])->getFormattedValue());
            $headers[$column] = $header !== '' ? $header : "Column {$column}";
        }
        $indices = [];
        $mappedHeaders = [];
        foreach (self::FIELDS as $key => $aliases) {
            $wanted = array_map(fn ($value) => $this->normalize($value), $aliases);
            foreach ($headers as $index => $header) {
                if (in_array($this->normalize($header), $wanted, true)) {
                    $indices[$key] = $index;
                    $mappedHeaders[$header] = true;
                    break;
                }
            }
        }

        $rows = [];
        $errors = [];
        for ($rowNumber = $headerRow + 1; $rowNumber <= $lastRow; $rowNumber++) {
            $row = array_fill_keys(array_keys(self::FIELDS), '');
            foreach ($indices as $key => $column) {
                $cell = $sheet->getCell([$column, $rowNumber]);
                $value = $cell->getFormattedValue();
                if (in_array($key, ['serialNumber', 'serialNo', 'dateCode', 'serialCode', 'quantityCode', 'partNumberCode', 'supplierCode', 'qrCode'], true)
                    && $cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) {
                    $numericDigits = preg_replace('/\D/', '', sprintf('%.0f', (float) $cell->getValue())) ?? '';
                    if (strlen(ltrim($numericDigits, '0')) > 15) {
                        $fieldLabel = self::FIELD_LABELS[$key] ?? $key;
                        $errors[] = "Row {$rowNumber}: {$fieldLabel} is stored as a number with more than 15 significant digits. Re-enter it as Excel text to prevent precision loss.";
                    }
                }
                if ($key === 'mfgDate') {
                    $value = $this->formatMfgDate($value);
                }
                $row[$key] = trim((string) $value);
            }
            $extras = [];
            foreach ($headers as $column => $header) {
                $cell = $sheet->getCell([$column, $rowNumber]);
                $value = trim((string) $cell->getFormattedValue());
                if ($value !== '' && preg_match('/code|serial|qr|barcode/i', $header) && $cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) {
                    $numericDigits = preg_replace('/\D/', '', sprintf('%.0f', (float) $cell->getValue())) ?? '';
                    if (strlen(ltrim($numericDigits, '0')) > 15) $errors[] = "Row {$rowNumber}: “{$header}” is stored as a number with more than 15 significant digits. Re-enter it as Excel text to prevent precision loss.";
                }
                if ($value !== '') $extras[] = ['name' => $header, 'value' => $value];
            }
            $row['customFields'] = $extras;
            if (!count(array_filter(array_slice($row, 0, count(self::FIELDS)), fn ($value) => $value !== '')) && !$extras) {
                continue;
            }

            $rows[] = ['row_number' => $rowNumber, 'data' => $row];
        }

        if (!$rows) throw new RuntimeException('No data rows were found in the selected table.');
        if ($errors) throw new RuntimeException(implode("\n", array_slice($errors, 0, 12)));

        return [
            'headers' => array_values($headers),
            'unmappedHeaders' => array_values(array_filter($headers, fn ($header) => !isset($mappedHeaders[$header]))),
            'rows' => $rows,
        ];
    }

    public function isLegacyFormat(array $headers): bool
    {
        $normalized = array_map(fn ($header) => $this->normalize((string) $header), $headers);
        $requiredGroups = [
            ['PARTNUMBER', 'PARTNUMBER1', 'PARTNO'],
            ['PARTNAME', 'PARTSDESCRIPTION', 'DESCRIPTION'],
            ['QUANTITY', 'QTY'],
            ['MODELNAME', 'MODEL'],
            ['CARTONWEIGHT', 'CARTONWEIGHTKG', 'WEIGHT'],
            ['SUPPLIERNUMBER', 'SUPPLIERNO', 'VENDORNUMBER'],
            ['MFGDATE', 'MANUFACTURINGDATE'],
            ['SERIALNO', 'SERIALNUMBER'],
        ];

        foreach ($requiredGroups as $aliases) {
            if (!array_intersect($aliases, $normalized)) return false;
        }

        return (bool) array_intersect(['SERIALCODE', 'QUANTITYCODE', 'PARTNUMBERCODE', 'SUPPLIERCODE', 'QRCODE'], $normalized);
    }

    private function normalize(string $value): string
    {
        return Str::upper(preg_replace('/[^\pL\pN]/u', '', str_replace(['①', '②', '⓵', '⓶'], ['1', '2', '1', '2'], $value)) ?? '');
    }

    private function formatMfgDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts)) {
            $value = "{$parts[1]}.{$parts[2]}.{$parts[3]}";
        }

        return MfgDateFormatter::format($value);
    }
}

