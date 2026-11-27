<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelRecord;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Picqer\Barcode\BarcodeGeneratorSVG;

class LabelController extends Controller
{
    public function show(Request $request, LabelRecord $record): Response
    {
        $request->user()->batches()->whereKey($record->batch_id)->firstOrFail();
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
        $qrMarkup = $renderQr($qrValue, 600, 8, 145, 135, 3);
        $generator = new BarcodeGeneratorSVG();
        $barcode = function (string $code, int $x, int $y, int $width, int $height) use ($generator): string {
            if (trim($code) === '' || strlen($code) > 255 || preg_match('/[^\x20-\x7E]/', $code)) return '';
            try {
                $raw = $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 48);
                preg_match('/viewBox="([^"]+)"/', $raw, $bounds);
                $inner = preg_replace('/^.*?<svg[^>]*>|<\/svg>.*$/s', '', $raw) ?? '';
                $inner = preg_replace('/<\?xml[^>]*>|<!DOCTYPE[^>]*>/i', '', $inner) ?? $inner;
                return sprintf('<svg x="%d" y="%d" width="%d" height="%d" viewBox="%s"><title>%s</title>%s</svg>', $x, $y, $width, $height, e($bounds[1] ?? '0 0 1 1'), e($code), $inner);
            } catch (\Throwable) {
                return '';
            }
        };
        $cells = [
            ['id'=>'part','x'=>0,'y'=>0,'w'=>535,'h'=>150], ['id'=>'qr','x'=>535,'y'=>0,'w'=>225,'h'=>150], ['id'=>'quantity','x'=>0,'y'=>150,'w'=>265,'h'=>140], ['id'=>'description','x'=>265,'y'=>150,'w'=>270,'h'=>70], ['id'=>'model','x'=>535,'y'=>150,'w'=>225,'h'=>70],
            ['id'=>'weight','x'=>265,'y'=>220,'w'=>270,'h'=>70], ['id'=>'component','x'=>535,'y'=>220,'w'=>225,'h'=>70], ['id'=>'supplier','x'=>0,'y'=>290,'w'=>265,'h'=>115], ['id'=>'date','x'=>265,'y'=>290,'w'=>270,'h'=>115], ['id'=>'supplier-pn','x'=>535,'y'=>290,'w'=>225,'h'=>115],
            ['id'=>'serial','x'=>0,'y'=>405,'w'=>470,'h'=>130], ['id'=>'origin','x'=>470,'y'=>405,'w'=>290,'h'=>130],
        ];
        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="152mm" height="107mm" viewBox="0 0 760 535"><defs>'];
        foreach ($cells as $cell) $svg[] = sprintf('<clipPath id="clip-%s"><rect x="%d" y="%d" width="%d" height="%d"/></clipPath>', $cell['id'], $cell['x'] + 3, $cell['y'] + 3, $cell['w'] - 6, $cell['h'] - 6);
        $svg[] = '</defs><rect x="1" y="1" width="758" height="533" fill="white" stroke="#111" stroke-width="2"/>';
        foreach ($cells as $cell) $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="#111" stroke-width="1.2"/>', $cell['x'], $cell['y'], $cell['w'], $cell['h']);
        $text = function ($x,$y,$value,$size=18,$anchor='start',$cell='part',$maxWidth=null) {
            $value = (string) $value;
            if ($maxWidth && $value !== '') {
                $characters = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                $estimatedWidth = $characters * $size * 0.58;
                if ($estimatedWidth > $maxWidth) $size = max(8, (int) floor($size * $maxWidth / $estimatedWidth));
            }

            return sprintf('<text x="%s" y="%s" font-family="Arial,sans-serif" font-size="%.2f" text-anchor="%s" clip-path="url(#clip-%s)">%s</text>', $x,$y,$size,$anchor,$cell,e($value));
        };
        $svgUnitsPerPdfPoint = 760 / 493.23;
        if (!empty($batch->label_template['elements']) && is_array($batch->label_template['elements'])) {
            $elements = $batch->label_template['elements'];
            $isLegacyLayout = collect($elements)->contains(fn ($element) => str_starts_with((string) ($element['id'] ?? ''), 'legacy-'));
            $hasQrElement = collect($elements)->contains(fn ($element) => ($element['type'] ?? '') === 'qr');
            $looksLikeOgamiLayout = collect($elements)->filter(fn ($element) => in_array($element['field'] ?? '', ['partNumber', 'quantity', 'partName', 'modelName', 'serialNo'], true))->count() >= 3;
            $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="152mm" height="107mm" viewBox="0 0 760 535"><defs>'];
            foreach ($elements as $index => $element) {
                $clipX = max(0, (float) ($element['x'] ?? 0));
                $clipY = max(0, (float) ($element['y'] ?? 0));
                $clipW = max(20, (float) ($element['w'] ?? 200));
                $clipH = max(20, (float) ($element['h'] ?? 40));
                if ($isLegacyLayout && ($element['type'] ?? '') === 'text' && ($element['field'] ?? '') === 'partNumber' && $clipY < 150) {
                    $clipX = 0; $clipY = 0; $clipW = 535; $clipH = 150;
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
                $type = $element['type'] ?? 'text';
                $centerPartNumber = $isLegacyLayout && $type === 'text' && $field === 'partNumber' && $y < 150;
                if ($centerPartNumber) { $x = 0; $w = 535; }
                if ($type === 'qr' && trim($value) === '') $value = $qrValue;
                if ($type === 'qr' && trim($value) !== '') {
                    $svg[] = '<g clip-path="url(#element-clip-'.$elementIndex.')">'.$renderQr($value, $x, $y, $w, $h).'</g>';
                    continue;
                }
                if ($type === 'barcode' && trim($value) !== '') {
                    $svg[] = sprintf('<g clip-path="url(#element-clip-%d)">%s</g>', $elementIndex, $barcode($value, (int) $x, (int) $y, (int) $w, (int) $h));
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
                $display = $type === 'text' && $field !== '' ? $value : trim((string) ($element['label'] ?? '')) . ($value !== '' ? ($field !== '' && ($element['label'] ?? '') !== '' ? ': ' : '') . $value : '');
                $baseline = min(535, $y + min($h - 2, $size + 4));
                $align = in_array($element['align'] ?? 'left', ['left', 'center', 'right'], true) ? ($element['align'] ?? 'left') : 'left';
                if ($centerPartNumber) $align = 'center';
                $textX = $align === 'center' ? $x + $w / 2 : ($align === 'right' ? $x + $w - 3 : $x + 3);
                $svg[] = sprintf('<text x="%s" y="%s" text-anchor="%s" font-family="Arial,sans-serif" font-size="%.2f" clip-path="url(#element-clip-%d)">%s</text>', $textX, $baseline, $align === 'left' ? 'start' : ($align === 'right' ? 'end' : 'middle'), $size, $elementIndex, e($display));
            }
            // Older Ogami templates may have saved without a QR widget. Keep the historical
            // top-right QR when the sheet still has its familiar standard fields and QR data.
            if (!$hasQrElement && $looksLikeOgamiLayout && $qrValue !== '') $svg[] = $renderQr($qrValue, 600, 8, 145, 135, 3);
            $svg[] = '</svg>';

            return response(implode('', $svg), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8', 'Cache-Control' => 'private, max-age=86400, immutable']);
        }
        $svg[] = $text(8,27,'PART NO.',17,'start','part'); $svg[] = $text(8,47,'(P)',17,'start','part'); $svg[] = $text(267.5,69,$data['partNumber'] ?? '',62,'middle','part',510);
        $svg[] = '<g clip-path="url(#clip-part)">'.$barcode($data['partNumberCode'] ?? '',110,83,420,50).'</g>'; $svg[] = $text(320,146,$data['partNumberCode'] ?? '',10,'middle','part',420);
        if ($qrMarkup !== '') $svg[] = '<g clip-path="url(#clip-qr)">'.$qrMarkup.'</g>';
        $svg[] = $text(8,171,'QUANTITY',17,'start','quantity'); $svg[] = $text(8,194,'(Q)',17,'start','quantity'); $svg[] = $text(130,231,$data['quantity'] ?? '',55,'middle','quantity',230);
        $svg[] = '<g clip-path="url(#clip-quantity)">'.$barcode($data['quantityCode'] ?? '',38,240,188,31).'</g>'; $svg[] = $text(132,277,$data['quantityCode'] ?? '',10,'middle','quantity',188);
        $svg[] = $text(275,171,'PARTS DESCRIPTION',17,'start','description'); $svg[] = $text(400,210,$data['partName'] ?? '',22,'middle','description',250);
        $svg[] = $text(545,171,'MODEL NAME',17,'start','model'); $svg[] = $text(647,208,$data['modelName'] ?? '',22,'middle','model',190);
        $svg[] = $text(275,241,'CARTON WEIGHT (Kg)',17,'start','weight'); $svg[] = $text(400,278,$data['cartonWeight'] ?? '',25,'middle','weight',240);
        $svg[] = $text(545,241,'COMPONENT S/N',17,'start','component');
        $componentSerial = $data['componentSn'] ?? collect($data['customFields'] ?? [])->firstWhere('name', 'COMPONENT S/N')['value'] ?? '';
        $svg[] = $text(647,278,$componentSerial,17,'middle','component',190);
        $svg[] = $text(8,312,'SUPPLIER NUMBER (V)',17,'start','supplier'); $svg[] = $text(132,348,$data['supplierNumber'] ?? '',22,'middle','supplier',230);
        $svg[] = '<g clip-path="url(#clip-supplier)">'.$barcode($data['supplierCode'] ?? '',45,354,180,36).'</g>'; $svg[] = $text(135,396,$data['supplierCode'] ?? '',10,'middle','supplier',180);
        $svg[] = $text(275,312,'MFG DATE',17,'start','date'); $svg[] = $text(400,363,$data['mfgDate'] ?? '',25,'middle','date',240);
        $svg[] = $text(545,312,'SUPPLIER PN',17,'start','supplier-pn'); $svg[] = $text(545,336,'(2P)',17,'start','supplier-pn'); $svg[] = $text(647,363,$data['supplierPn'] ?? '',22,'middle','supplier-pn',190);
        $svg[] = $text(8,429,'SERIAL NO.',17,'start','serial'); $svg[] = $text(8,457,'(S)',17,'start','serial'); $svg[] = $text(235,459,$data['serialNo'] ?? '',22,'middle','serial',430);
        $svg[] = '<g clip-path="url(#clip-serial)">'.$barcode($data['serialCode'] ?? '',25,474,405,45).'</g>'; $svg[] = $text(225,528,$data['serialCode'] ?? '',10,'middle','serial',405);
        $svg[] = $text(480,429,'MADE IN PHILIPPINE',17,'start','origin'); $svg[] = $text(615,481,'PHILIPPINES OGAMI',17,'middle','origin',260); $svg[] = $text(615,506,'CORPORATION',17,'middle','origin',260);
        $svg[] = '</svg>';

        return response(implode('', $svg), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // A record has an immutable id, so the generated vector can be reused safely by this user's browser.
            'Cache-Control' => 'private, max-age=86400, immutable',
        ]);
    }
}

