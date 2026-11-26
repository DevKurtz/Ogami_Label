<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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
        'supplierPn' => ['PART NUMBER②', 'PART NUMBER2', 'PART NUMBER 2', 'SUPPLIER PART NUMBER', 'SUPPLIER PN'],
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

    public function import(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $lastColumn = $sheet->getHighestDataColumn();
        $lastRow = $sheet->getHighestDataRow();
        $headers = [];
        foreach ($sheet->rangeToArray("A1:{$lastColumn}1", null, false, false)[0] ?? [] as $header) {
            $headers[] = trim((string) $header);
        }
        $indices = [];
        foreach (self::FIELDS as $key => $aliases) {
            $wanted = array_map(fn ($value) => $this->normalize($value), $aliases);
            foreach ($headers as $index => $header) {
                if (in_array($this->normalize($header), $wanted, true)) {
                    $indices[$key] = $index + 1;
                    break;
                }
            }
        }

        $rows = [];
        $errors = [];
        for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
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
            if (count(array_filter($row, fn ($value) => $value !== '')) === 0) {
                continue;
            }

            $rows[] = ['row_number' => $rowNumber, 'data' => $row];
        }

        if (!$rows) throw new RuntimeException('No data rows were found in the first worksheet.');
        if ($errors) throw new RuntimeException(implode("\n", array_slice($errors, 0, 12)));

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function normalize(string $value): string
    {
        return Str::upper(preg_replace('/[\s().]/u', '', str_replace(['①', '②'], ['1', '2'], $value)) ?? '');
    }

    private function formatMfgDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts)) {
            return "{$parts[1]}.{$parts[2]}.{$parts[3]}";
        }
        return $value;
    }
}

