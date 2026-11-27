<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelRecord;
use App\Models\WorkbookBatch;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Picqer\Barcode\BarcodeGeneratorSVG;

class LabelController extends Controller
{
    public function show(Request $request, LabelRecord $record): Response
    {
        $request->user()->batches()->whereKey($record->batch_id)->firstOrFail();
        return $this->renderLabel($record);
    }

    public function showBatch(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $ownedBatch = $request->user()->batches()->whereKey($batch->getKey())->firstOrFail();
        $offset = max(0, $request->integer('offset', 0));
        $limit = min(20, max(1, $request->integer('limit', 12)));
        $records = $ownedBatch->records()
            ->orderBy('row_number')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $labels = $records->map(function (LabelRecord $record) use ($ownedBatch) {
            $record->setRelation('batch', $ownedBatch);

            return [
                'id' => $record->getKey(),
                'row_number' => $record->row_number,
                'svg' => $this->renderLabel($record)->getContent(),
            ];
        });

        return response()->json([
            'offset' => $offset,
            'total' => (int) $ownedBatch->row_count,
            'labels' => $labels,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function renderLabel(LabelRecord $record): Response
    {
        $data = $record->data;
        $batch = $record->batch;
        $qrValue = trim($record->qr_code ?? '');
        if ($qrValue === '') {
            foreach ($batch->field_config ?? [] as $field => $type) {
                if ($type !== 'qr') continue;
                $candidate = (string) (collect($data['customFields'] ?? [])->firstWhere('name', $field)['value'] ?? '');
                if (trim($candidate) !== '') { $qrValue = $candidate; break; }
            }
        }
        $renderQr = function (string $value, float $x, float $y, float $width, float $height, int $margin = 2) use ($record): string {
            if (trim($value) === '') return '';
            try {
                $raw = (new SvgWriter())->write(new QrCode(
                    data: $value,
                    encoding: new Encoding('UTF-8'),
                    errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                    size: 180,
                    margin: $margin,
                ))->getString();
                preg_match('/viewBox="([^"]+)"/', $raw, $bounds);
                $inner = preg_replace('/^.*?<svg[^>]*>|<\/svg>.*$/s', '', $raw) ?? '';
                $inner = preg_replace('/<\?xml[^>]*>|<!DOCTYPE[^>]*>/i', '', $inner) ?? $inner;
                if (empty($bounds[1]) || trim($inner) === '') throw new \RuntimeException('The QR encoder returned empty SVG data.');

                return sprintf('<svg x="%s" y="%s" width="%s" height="%s" viewBox="%s" role="img"><title>%s</title><g>%s</g></svg>', $x, $y, $width, $height, e($bounds[1]), e($value), $inner);
            } catch (\Throwable $exception) {
                Log::warning('Could not render QR code on label.', ['record_id' => $record->getKey(), 'exception' => $exception::class]);
                return '';
            }
        };
        $qrMarkup = $renderQr($qrValue, 600, 12, 110, 110, 3);
        $generator = new BarcodeGeneratorSVG();
        $barcode = function (string $code, int $x, int $y, int $width, int $height, ?string $barcodeType = null, bool $stretchToFrame = false) use ($generator): string {
            if (trim($code) === '' || strlen($code) > 255 || preg_match('/[^\x20-\x7E]/', $code)) return '';
            try {
                $raw = $generator->getBarcode($code, $barcodeType ?? $generator::TYPE_CODE_128, 2, 48);
                preg_match('/viewBox="([^"]+)"/', $raw, $bounds);
                $inner = preg_replace('/^.*?<svg[^>]*>|<\/svg>.*$/s', '', $raw) ?? '';
                $inner = preg_replace('/<\?xml[^>]*>|<!DOCTYPE[^>]*>/i', '', $inner) ?? $inner;
                $viewBox = preg_split('/\s+/', trim($bounds[1] ?? '0 0 1 1'));
                $quietZone = 20; // 10 narrow modules on either side, required for reliable scanning.
                if (count($viewBox) === 4) $viewBox[2] = (float) $viewBox[2] + 2 * $quietZone;
                $paddedViewBox = implode(' ', $viewBox);
                return sprintf('<svg x="%d" y="%d" width="%d" height="%d" viewBox="%s" preserveAspectRatio="%s"><title>%s</title><g transform="translate(%d 0)">%s</g></svg>', $x, $y, $width, $height, e($paddedViewBox), $stretchToFrame ? 'none' : 'xMidYMid meet', e($code), $quietZone, $inner);
            } catch (\Throwable) {
                return '';
            }
        };
        $legacyBarcodeType = fn (string $code): string => preg_match('/^[A-Z0-9 .\-\$\/+%]+$/', $code) === 1
            ? $generator::TYPE_CODE_39
            : $generator::TYPE_CODE_128;
        $legacyBarcodeCaption = static fn (string $code): string => preg_match('/^[A-Z0-9 .\-\$\/+%]+$/', $code) === 1
            ? '* '.implode(' ', str_split($code)).' *'
            : $code;
        // Standard Ogami label geometry traced from docs/20261113ACCESS.pdf.
        // Coordinates are normalized to the 760 × 535 SVG canvas used by previews and PDFs.
        $cells = [
            ['id'=>'part','x'=>0,'y'=>0,'w'=>760,'h'=>143],
            ['id'=>'quantity','x'=>0,'y'=>143,'w'=>253,'h'=>146], ['id'=>'description','x'=>253,'y'=>143,'w'=>293,'h'=>77], ['id'=>'model','x'=>546,'y'=>143,'w'=>214,'h'=>77],
            ['id'=>'weight','x'=>253,'y'=>220,'w'=>293,'h'=>69], ['id'=>'component','x'=>546,'y'=>220,'w'=>214,'h'=>69],
            ['id'=>'supplier','x'=>0,'y'=>289,'w'=>253,'h'=>116], ['id'=>'date','x'=>253,'y'=>289,'w'=>293,'h'=>116], ['id'=>'supplier-pn','x'=>546,'y'=>289,'w'=>214,'h'=>116],
            ['id'=>'serial','x'=>0,'y'=>405,'w'=>437,'h'=>130], ['id'=>'origin','x'=>437,'y'=>405,'w'=>323,'h'=>130],
        ];
        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="174.5mm" height="124.5mm" viewBox="0 0 760 535" preserveAspectRatio="none"><defs>'];
        foreach ($cells as $cell) $svg[] = sprintf('<clipPath id="clip-%s"><rect x="%d" y="%d" width="%d" height="%d"/></clipPath>', $cell['id'], $cell['x'] + 3, $cell['y'] + 3, $cell['w'] - 6, $cell['h'] - 6);
        $svg[] = '<clipPath id="clip-qr"><rect x="546" y="0" width="214" height="143"/></clipPath>';
        $svg[] = '</defs><rect x="1" y="1" width="758" height="533" fill="white" stroke="#111" stroke-width="1.5"/>';
        $svg[] = '<path d="M0 143H760 M0 289H760 M0 405H760 M253 143V405 M546 143V405 M437 405V535 M253 220H546 M546 220H760 M253 350H546" fill="none" stroke="#111" stroke-width="1.2"/>';
        $text = function ($x,$y,$value,$size=18,$anchor='start',$cell='part',$maxWidth=null) {
            $value = (string) $value;
            if ($maxWidth && $value !== '') {
                $characters = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                $estimatedWidth = $characters * $size * 0.58;
                if ($estimatedWidth > $maxWidth) $size = max(8, (int) floor($size * $maxWidth / $estimatedWidth));
            }

            return sprintf('<text x="%s" y="%s" font-family="Arial,sans-serif" font-size="%.2f" text-anchor="%s" clip-path="url(#clip-%s)">%s</text>', $x,$y,$size,$anchor,$cell,e($value));
        };
        $svgUnitsPerPdfPoint = 760 / 494.72;
        if (!empty($batch->label_template['elements']) && is_array($batch->label_template['elements'])) {
            $elements = $batch->label_template['elements'];
            $isLegacyLayout = collect($elements)->contains(fn ($element) => str_starts_with((string) ($element['id'] ?? ''), 'legacy-'));
            $hasQrElement = collect($elements)->contains(fn ($element) => ($element['type'] ?? '') === 'qr');
            $looksLikeOgamiLayout = collect($elements)->filter(fn ($element) => in_array($element['field'] ?? '', ['partNumber', 'quantity', 'partName', 'modelName', 'serialNo'], true))->count() >= 3;
            $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="174.5mm" height="124.5mm" viewBox="0 0 760 535" preserveAspectRatio="none"><defs>'];
            foreach ($elements as $index => $element) {
                $clipX = max(0, (float) ($element['x'] ?? 0));
                $clipY = max(0, (float) ($element['y'] ?? 0));
                $clipW = max(20, (float) ($element['w'] ?? 200));
                $clipH = max(20, (float) ($element['h'] ?? 40));
                if ($isLegacyLayout && ($element['type'] ?? '') === 'text' && ($element['field'] ?? '') === 'partNumber' && $clipY < 143) {
                    $clipX = 0; $clipY = 0; $clipW = 650; $clipH = 143;
                }
                if ($isLegacyLayout && ($element['type'] ?? '') === 'barcode' && ($element['field'] ?? '') === 'quantityCode' && $clipY >= 200 && $clipY < 289) {
                    $clipX = 3; $clipY = 146; $clipW = 247; $clipH = 140;
                }
                $svg[] = sprintf('<clipPath id="element-clip-%d"><rect x="%s" y="%s" width="%s" height="%s"/></clipPath>', $index, $clipX, $clipY, $clipW, $clipH);
            }
            $svg[] = '</defs>';
            foreach ($elements as $elementIndex => $element) {
                $x = max(0, min(760, (float) ($element['x'] ?? 0)));
                $y = max(0, min(535, (float) ($element['y'] ?? 0)));
                $w = max(20, min(760 - $x, (float) ($element['w'] ?? 200)));
                $h = max(20, min(535 - $y, (float) ($element['h'] ?? 40)));
                if (($element['type'] ?? '') === 'box') {
                    $svg[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke="#111" stroke-width="1.5"/>', $x,$y,$w,$h);
                    continue;
                }
                $field = (string) ($element['field'] ?? '');
                $value = $field === '' ? (string) ($element['label'] ?? '') : (string) ($data[$field] ?? collect($data['customFields'] ?? [])->firstWhere('name', $field)['value'] ?? '');
                if ($field === 'mfgDate') $value = $this->formatMfgDateForLabel($value);
                $type = $element['type'] ?? 'text';
                // Keep legacy serial bars inside the serial cell with even side margins.
                // Older saved templates extended this barcode right to the cell border.
                if ($isLegacyLayout && $type === 'barcode' && $field === 'serialCode' && $y >= 405) {
                    $x = 38; $w = 361; $h = 39;
                }
                // The quantity value is centered at x=162. Older Ogami templates put
                // its barcode at x=38 (center 132), leaving the bars visibly short of
                // the last digit on the right. Keep the symbol inside the quantity cell
                // while aligning its right edge with the printed value.
                if ($isLegacyLayout && $type === 'barcode' && $field === 'quantityCode' && $y >= 200 && $y < 289) {
                    // Match the client guide: keep a clear left quiet zone, broaden
                    // the bars, and finish close to the quantity value's right edge.
                    $x = 23; $w = 224;
                }
                if ($isLegacyLayout && $type === 'text' && $field === 'serialCode' && $y >= 405) {
                    $x = 38; $y = 518; $w = 361; $h = 12;
                }
                if ($isLegacyLayout && $type === 'text' && $field === 'quantityCode' && $y >= 250 && $y < 300) {
                    $x = 40; $y = 274; $w = 190; $h = 12;
                }
                if ($isLegacyLayout && $type === 'text' && $field === 'partNumberCode' && $y < 143) {
                    $x = 100; $y = 129; $w = 460; $h = 12;
                }
                if ($isLegacyLayout && $type === 'text' && $field === 'supplierCode' && $y >= 289 && $y < 405) {
                    $x = 35; $y = 391; $w = 190; $h = 12;
                }
                $centerPartNumber = $isLegacyLayout && $type === 'text' && $field === 'partNumber' && $y < 143;
                if ($centerPartNumber) { $x = 0; $w = 650; }
                if ($type === 'qr' && trim($value) === '') $value = $qrValue;
                if ($type === 'qr' && trim($value) !== '') {
                    $svg[] = '<g clip-path="url(#element-clip-'.$elementIndex.')">'.$renderQr($value, $x, $y, $w, $h).'</g>';
                    continue;
                }
                if ($type === 'barcode' && trim($value) !== '') {
                    $fitQuantityBarcode = $isLegacyLayout && $field === 'quantityCode';
                    $svg[] = sprintf('<g clip-path="url(#element-clip-%d)">%s</g>', $elementIndex, $barcode($value, (int) $x, (int) $y, (int) $w, (int) $h, $isLegacyLayout ? $legacyBarcodeType($value) : null, $fitQuantityBarcode));
                    continue;
                }
                $size = max(8, min(64, (int) ($element['fontSize'] ?? 20)));
                if ($isLegacyLayout && $type === 'text') {
                    $fontPoints = match ($field) {
                        'partNumber' => 40,
                        'quantity' => 36,
                        'cartonWeight', 'mfgDate' => 16,
                        'partName', 'modelName', 'supplierNumber', 'supplierPn', 'serialNo' => 14,
                        'partNumberCode' => 6.7,
                        'quantityCode', 'supplierCode', 'serialCode' => 6.5,
                        default => 11,
                    };
                    $size = $fontPoints * $svgUnitsPerPdfPoint;
                }
                if ($isLegacyLayout && $type === 'text' && in_array($field, ['partNumberCode', 'quantityCode', 'supplierCode', 'serialCode'], true)) $value = $legacyBarcodeCaption($value);
                $staticLabel = trim((string) ($element['label'] ?? ''));
                if (strcasecmp($staticLabel, 'MADE IN PHILIPPINE') === 0) $staticLabel = 'MADE IN PHILIPPINES';
                $display = $type === 'text' && $field !== '' ? $value : $staticLabel . ($value !== '' ? ($field !== '' && $staticLabel !== '' ? ': ' : '') . $value : '');
                $baseline = min(535, $y + min($h - 2, $size + 4));
                $align = in_array($element['align'] ?? 'left', ['left', 'center', 'right'], true) ? ($element['align'] ?? 'left') : 'left';
                if ($centerPartNumber) $align = 'center';
                $isLegacyCodeCaption = $isLegacyLayout && $type === 'text' && in_array($field, ['partNumberCode', 'quantityCode', 'supplierCode', 'serialCode'], true);
                if ($isLegacyCodeCaption) $align = 'center';
                $textX = $align === 'center' ? $x + $w / 2 : ($align === 'right' ? $x + $w - 3 : $x + 3);
                $captionLength = $isLegacyCodeCaption && $display !== '' && $legacyBarcodeType($value) === $generator::TYPE_CODE_39 ? sprintf(' textLength="%.2f" lengthAdjust="spacing"', $w) : '';
                $svg[] = sprintf('<text x="%s" y="%s" text-anchor="%s" font-family="Arial,sans-serif" font-size="%.2f"%s clip-path="url(#element-clip-%d)">%s</text>', $textX, $baseline, $align === 'left' ? 'start' : ($align === 'right' ? 'end' : 'middle'), $size, $captionLength, $elementIndex, e($display));
            }
            // Older Ogami templates may have saved without a QR widget. Keep the historical
            // top-right QR when the sheet still has its familiar standard fields and QR data.
            if (!$hasQrElement && $looksLikeOgamiLayout && $qrValue !== '') $svg[] = $renderQr($qrValue, 600, 12, 110, 110, 3);
            $svg[] = '</svg>';

            return response(implode('', $svg), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8', 'Cache-Control' => 'private, max-age=86400, immutable']);
        }
        $svg[] = $text(8,27,'PART NO.',17,'start','part'); $svg[] = $text(8,47,'(P)',17,'start','part'); $svg[] = $text(325,71,$data['partNumber'] ?? '',62,'middle','part',570);
        $partNumberCode = (string) ($data['partNumberCode'] ?? '');
        $svg[] = '<g clip-path="url(#clip-part)">'.$barcode($partNumberCode,100,78,460,45,$legacyBarcodeType($partNumberCode)).'</g>';
        $partCaption = $legacyBarcodeCaption($partNumberCode);
        $partCaptionSpacing = $partCaption !== '' && $legacyBarcodeType($partNumberCode) === $generator::TYPE_CODE_39 ? ' textLength="460" lengthAdjust="spacing"' : '';
        $svg[] = sprintf('<text x="330" y="139" text-anchor="middle" font-family="Arial,sans-serif" font-size="10"%s clip-path="url(#clip-part)">%s</text>', $partCaptionSpacing, e($partCaption));
        if ($qrMarkup !== '') $svg[] = '<g clip-path="url(#clip-qr)">'.$qrMarkup.'</g>';
        $svg[] = $text(8,167,'QUANTITY',17,'start','quantity'); $svg[] = $text(8,193,'(Q)',17,'start','quantity'); $svg[] = $text(162,232,$data['quantity'] ?? '',55,'middle','quantity',230);
        $quantityCode = (string) ($data['quantityCode'] ?? '');
        $svg[] = '<g clip-path="url(#clip-quantity)">'.$barcode($quantityCode,23,240,224,31,$legacyBarcodeType($quantityCode),true).'</g>'; $quantityCaption = $legacyBarcodeCaption($quantityCode); $svg[] = sprintf('<text x="135" y="284" text-anchor="middle" font-family="Arial,sans-serif" font-size="10"%s clip-path="url(#clip-quantity)">%s</text>', $quantityCaption !== '' && $legacyBarcodeType($quantityCode) === $generator::TYPE_CODE_39 ? ' textLength="190" lengthAdjust="spacing"' : '', e($quantityCaption));
        $svg[] = $text(260,167,'PARTS DESCRIPTION',17,'start','description'); $svg[] = $text(400,211,$data['partName'] ?? '',22,'middle','description',270);
        $svg[] = $text(552,167,'MODEL NAME',17,'start','model'); $svg[] = $text(652,211,$data['modelName'] ?? '',22,'middle','model',200);
        $svg[] = $text(260,245,'CARTON WEIGHT (Kg)',17,'start','weight'); $svg[] = $text(400,279,$data['cartonWeight'] ?? '',25,'middle','weight',270);
        $svg[] = $text(552,245,'COMPONENT S/N',17,'start','component');
        $componentSerial = $data['componentSn'] ?? collect($data['customFields'] ?? [])->firstWhere('name', 'COMPONENT S/N')['value'] ?? '';
        $svg[] = $text(647,278,$componentSerial,17,'middle','component',190);
        $svg[] = $text(8,314,'SUPPLIER NUMBER',17,'start','supplier'); $svg[] = $text(8,340,'(V)',17,'start','supplier'); $svg[] = $text(200,344,$data['supplierNumber'] ?? '',22,'middle','supplier',230);
        $supplierCode = (string) ($data['supplierCode'] ?? '');
        $svg[] = '<g clip-path="url(#clip-supplier)">'.$barcode($supplierCode,35,354,190,36,$legacyBarcodeType($supplierCode)).'</g>'; $supplierCaption = $legacyBarcodeCaption($supplierCode); $svg[] = sprintf('<text x="130" y="401" text-anchor="middle" font-family="Arial,sans-serif" font-size="10"%s clip-path="url(#clip-supplier)">%s</text>', $supplierCaption !== '' && $legacyBarcodeType($supplierCode) === $generator::TYPE_CODE_39 ? ' textLength="190" lengthAdjust="spacing"' : '', e($supplierCaption));
        $svg[] = $text(260,314,'MFG DAT',17,'start','date'); $svg[] = $text(400,343,$this->formatMfgDateForLabel((string) ($data['mfgDate'] ?? '')),25,'middle','date',270);
        $svg[] = $text(552,314,'SUPPLIER PN',17,'start','supplier-pn'); $svg[] = $text(552,348,'(2P)',17,'start','supplier-pn');
        $svg[] = $text(8,430,'SERIAL NO',17,'start','serial'); $svg[] = $text(8,456,'(S)',17,'start','serial'); $svg[] = $text(219,460,$data['serialNo'] ?? '',22,'middle','serial',405);
        $serialCode = (string) ($data['serialCode'] ?? '');
        $svg[] = '<g clip-path="url(#clip-serial)">'.$barcode($serialCode,38,474,361,39,$legacyBarcodeType($serialCode)).'</g>'; $svg[] = sprintf('<text x="218.5" y="528" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" textLength="361" lengthAdjust="spacing" clip-path="url(#clip-serial)">%s</text>', e($legacyBarcodeCaption($serialCode)));
        $svg[] = $text(444,430,'MADE IN PHILIPPINES',17,'start','origin'); $svg[] = $text(600,482,'PHILIPPINES OGAMI',17,'middle','origin',300); $svg[] = $text(600,503,'CORPORATION',17,'middle','origin',300);
        $svg[] = '</svg>';

        return response(implode('', $svg), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // A record has an immutable id, so the generated vector can be reused safely by this user's browser.
            'Cache-Control' => 'private, max-age=86400, immutable',
        ]);
    }

    private function formatMfgDateForLabel(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $date)) {
            return sprintf('%04d/%02d/%02d', $date[1], $date[2], $date[3]);
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $date)) {
            return sprintf('%04d/%02d/%02d', $date[1], $date[2], $date[3]);
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})$/', $value, $date)) {
            $year = (int) $date[3];
            if (strlen($date[3]) === 2) $year += $year < 70 ? 2000 : 1900;
            return sprintf('%04d/%02d/%02d', $year, $date[2], $date[1]);
        }
        return $value;
    }
}

