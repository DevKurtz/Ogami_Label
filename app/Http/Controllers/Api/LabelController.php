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
        $qrMarkup = '';
        if ($qrValue !== '') {
            try {
                $qrSvg = (new SvgWriter())->write(new QrCode(
                    data: $qrValue,
                    encoding: new Encoding('UTF-8'),
                    errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                    size: 180,
                    margin: 3,
                ))->getString();
                preg_match('/viewBox="([^"]+)"/', $qrSvg, $qrViewBox);
                $qrInner = preg_replace('/^.*?<svg[^>]*>|<\/svg>.*$/s', '', $qrSvg) ?? '';
                $qrInner = preg_replace('/<\?xml[^>]*>|<!DOCTYPE[^>]*>/i', '', $qrInner) ?? $qrInner;
                $qrMarkup = '<svg x="600" y="8" width="145" height="135" viewBox="'.e($qrViewBox[1] ?? '0 0 180 180').'" role="img"><title>'.e($qrValue).'</title><g>'.$qrInner.'</g></svg>';
            } catch (\Throwable) {
                // Keep the label available when the optional QR value is blank or cannot be encoded.
            }
        }
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
            ['x'=>0,'y'=>0,'w'=>535,'h'=>150], ['x'=>535,'y'=>0,'w'=>225,'h'=>150], ['x'=>0,'y'=>150,'w'=>265,'h'=>140], ['x'=>265,'y'=>150,'w'=>270,'h'=>70], ['x'=>535,'y'=>150,'w'=>225,'h'=>140],
            ['x'=>265,'y'=>220,'w'=>270,'h'=>70], ['x'=>0,'y'=>290,'w'=>265,'h'=>115], ['x'=>265,'y'=>290,'w'=>270,'h'=>115], ['x'=>535,'y'=>290,'w'=>225,'h'=>115],
            ['x'=>0,'y'=>405,'w'=>470,'h'=>130], ['x'=>470,'y'=>405,'w'=>290,'h'=>130],
        ];
        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="152mm" height="107mm" viewBox="0 0 760 535"><rect x="1" y="1" width="758" height="533" fill="white" stroke="#111" stroke-width="2"/>'];
        foreach ($cells as $cell) $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="#111" stroke-width="1.2"/>', $cell['x'], $cell['y'], $cell['w'], $cell['h']);
        $text = fn ($x,$y,$value,$size=18,$anchor='start') => sprintf('<text x="%d" y="%d" font-family="Arial,sans-serif" font-size="%d" text-anchor="%s">%s</text>', $x,$y,$size,$anchor,e((string) $value));
        if (!empty($batch->label_template['elements']) && is_array($batch->label_template['elements'])) {
            $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="152mm" height="107mm" viewBox="0 0 760 535"><rect x="1" y="1" width="758" height="533" fill="white" stroke="#111" stroke-width="2"/>'];
            foreach ($batch->label_template['elements'] as $element) {
                $x = max(0, min(760, (float) ($element['x'] ?? 0)));
                $y = max(0, min(535, (float) ($element['y'] ?? 0)));
                $w = max(20, min(760 - $x, (float) ($element['w'] ?? 200)));
                $h = max(20, min(535 - $y, (float) ($element['h'] ?? 40)));
                if (($element['type'] ?? '') === 'box') {
                    $svg[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="white" stroke="#111" stroke-width="1.5"/>', $x,$y,$w,$h);
                    continue;
                }
                $field = (string) ($element['field'] ?? '');
                $value = $field === '' ? (string) ($element['label'] ?? '') : (string) ($data[$field] ?? collect($data['customFields'] ?? [])->firstWhere('name', $field)['value'] ?? '');
                $type = $element['type'] ?? 'text';
                if ($type === 'qr' && trim($value) !== '') {
                    try {
                        $raw = (new SvgWriter())->write(new QrCode(data:$value, encoding:new Encoding('UTF-8'), errorCorrectionLevel:ErrorCorrectionLevel::Medium, size:180, margin:2))->getString();
                        preg_match('/viewBox="([^"]+)"/', $raw, $bounds);
                        $inner = preg_replace('/^.*?<svg[^>]*>|<\/svg>.*$/s', '', $raw) ?? '';
                        $svg[] = sprintf('<svg x="%s" y="%s" width="%s" height="%s" viewBox="%s">%s</svg>', $x,$y,$w,$h,e($bounds[1] ?? '0 0 180 180'),$inner);
                    } catch (\Throwable) { /* Skip invalid code values but keep remaining content. */ }
                    continue;
                }
                if ($type === 'barcode' && trim($value) !== '') {
                    $svg[] = $barcode($value, (int) $x, (int) $y, (int) $w, (int) $h);
                    continue;
                }
                $size = max(8, min(64, (int) ($element['fontSize'] ?? 20)));
                $display = $type === 'text' && $field !== '' ? $value : trim((string) ($element['label'] ?? '')) . ($value !== '' ? ($field !== '' && ($element['label'] ?? '') !== '' ? ': ' : '') . $value : '');
                $baseline = min(535, $y + min($h - 2, $size + 4));
                $svg[] = sprintf('<text x="%s" y="%s" font-family="Arial,sans-serif" font-size="%d">%s</text>', $x + 3, $baseline, $size, e($display));
            }
            $svg[] = '</svg>';

            return response(implode('', $svg), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8', 'Cache-Control' => 'private, max-age=86400, immutable']);
        }
        $svg[] = $text(8,27,'PART NO.'); $svg[] = $text(8,47,'(P)'); $svg[] = $text(360,69,$data['partNumber'] ?? '',42,'middle');
        $svg[] = $barcode($data['partNumberCode'] ?? '',110,83,420,50); $svg[] = $text(320,146,$data['partNumberCode'] ?? '',10,'middle');
        if ($qrMarkup !== '') $svg[] = $qrMarkup;
        $svg[] = $text(8,171,'QUANTITY'); $svg[] = $text(8,194,'(Q)'); $svg[] = $text(130,231,$data['quantity'] ?? '',36,'middle');
        $svg[] = $barcode($data['quantityCode'] ?? '',38,240,188,31); $svg[] = $text(132,277,$data['quantityCode'] ?? '',9,'middle');
        $svg[] = $text(275,171,'PARTS DESCRIPTION'); $svg[] = $text(400,210,$data['partName'] ?? '',19,'middle');
        $svg[] = $text(545,171,'MODEL NAME'); $svg[] = $text(647,221,$data['modelName'] ?? '',18,'middle');
        $svg[] = $text(275,241,'CARTON WEIGHT (Kg)'); $svg[] = $text(400,278,$data['cartonWeight'] ?? '',20,'middle');
        $svg[] = $text(545,241,'COMPONENT S/N');
        $svg[] = $text(8,312,'SUPPLIER NUMBER (V)'); $svg[] = $text(132,348,$data['supplierNumber'] ?? '',20,'middle');
        $svg[] = $barcode($data['supplierCode'] ?? '',45,354,180,36); $svg[] = $text(135,396,$data['supplierCode'] ?? '',9,'middle');
        $svg[] = $text(275,312,'MFG DATE'); $svg[] = $text(400,363,$data['mfgDate'] ?? '',22,'middle');
        $svg[] = $text(545,312,'SUPPLIER PN (2P)'); $svg[] = $text(647,363,$data['supplierPn'] ?? '',20,'middle');
        $svg[] = $text(8,429,'SERIAL NO.'); $svg[] = $text(8,457,'(S)'); $svg[] = $text(235,459,$data['serialNo'] ?? '',22,'middle');
        $svg[] = $barcode($data['serialCode'] ?? '',25,474,405,45); $svg[] = $text(225,528,$data['serialCode'] ?? '',9,'middle');
        $svg[] = $text(480,429,'MADE IN PHILIPPINE'); $svg[] = $text(615,481,'PHILIPPINES OGAMI',17,'middle'); $svg[] = $text(615,506,'CORPORATION',17,'middle');
        $svg[] = '</svg>';

        return response(implode('', $svg), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // A record has an immutable id, so the generated vector can be reused safely by this user's browser.
            'Cache-Control' => 'private, max-age=86400, immutable',
        ]);
    }
}

