<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelRecord;
use App\Models\WorkbookBatch;
use App\Services\WorkbookImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BatchController extends Controller
{
    public function analyze(Request $request, WorkbookImporter $importer): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'max:4096', 'mimes:xlsx,xlsm,xls,csv']]);
        try {
            return response()->json($importer->analyze($validated['file']->getRealPath()));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The workbook could not be analyzed. Check that it is a valid Excel file.']);
        }
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->batches()->withCount('records')->latest()->get());
    }

    public function show(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $ownedBatch = $request->user()->batches()->whereKey($batch->getKey())->firstOrFail();
        $ownedBatch->setRelation('records', $ownedBatch->records()
            ->orderBy('row_number')
            ->get(['id', 'batch_id', 'row_number', 'serial_no', 'part_number']));

        return response()->json($ownedBatch);
    }

    public function showRecord(Request $request, LabelRecord $record): JsonResponse
    {
        $request->user()->batches()->whereKey($record->batch_id)->firstOrFail();

        return response()->json($record);
    }

    public function updateTemplate(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $ownedBatch = $request->user()->batches()->whereKey($batch->getKey())->firstOrFail();
        $validated = $request->validate([
            'template' => ['required', 'array'],
            'template.elements' => ['required', 'array', 'min:1', 'max:80'],
            'template.elements.*.id' => ['required', 'string', 'max:80'],
            'template.elements.*.type' => ['required', Rule::in(['text', 'barcode', 'qr', 'box'])],
            'template.elements.*.field' => ['nullable', 'string', 'max:160'],
            'template.elements.*.label' => ['nullable', 'string', 'max:160'],
            'template.elements.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'template.elements.*.x' => ['required', 'numeric', 'min:0', 'max:760'],
            'template.elements.*.y' => ['required', 'numeric', 'min:0', 'max:535'],
            'template.elements.*.w' => ['required', 'numeric', 'min:20', 'max:760'],
            // Small caption elements beneath barcodes can be shorter than 20px.
            'template.elements.*.h' => ['required', 'numeric', 'min:8', 'max:535'],
            'template.elements.*.fontSize' => ['nullable', 'integer', 'min:8', 'max:64'],
        ]);
        $ownedBatch->label_template = $validated['template'];
        $ownedBatch->save();

        return response()->json($ownedBatch->load(['records' => fn ($query) => $query->orderBy('row_number')]));
    }

    public function deleteStatus(Request $request): JsonResponse
    {
        return response()->json(['enabled' => true]);
    }

    public function destroy(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $ownedBatch = $request->user()->batches()->whereKey($batch->getKey())->firstOrFail();
        $ownedBatch->delete();

        return response()->json(['message' => 'Workbook folder and its label records were deleted.']);
    }

    public function destroyRecord(Request $request, LabelRecord $record): JsonResponse
    {
        $batch = $request->user()->batches()->whereKey($record->batch_id)->firstOrFail();
        $ownedRecord = $batch->records()->whereKey($record->getKey())->firstOrFail();

        DB::transaction(function () use ($ownedRecord, $batch) {
            $ownedRecord->delete();
            $batch->decrement('row_count');
        });

        return response()->json(['message' => 'Label record deleted.', 'batch_id' => $batch->id]);
    }

    public function store(Request $request, WorkbookImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:xlsx,xlsm,xls,csv'],
            'name' => ['nullable', 'string', 'max:160'],
            'tables' => ['required', 'string', 'max:50000'],
        ]);

        $tables = json_decode($validated['tables'], true);
        if (!is_array($tables) || !array_is_list($tables) || count($tables) < 1 || count($tables) > 30) {
            throw ValidationException::withMessages(['file' => 'Select between 1 and 30 detected tables before uploading.']);
        }

        $imports = [];
        try {
            foreach ($tables as $table) {
                $table = Validator::make($table, [
                    'id' => ['required', 'string', 'max:100'],
                    'tableName' => ['required', 'string', 'max:100'],
                    'sheetName' => ['required', 'string', 'max:100'],
                    'sheetIndex' => ['required', 'integer', 'min:0'],
                    'headerRow' => ['required', 'integer', 'min:1'],
                    'startColumn' => ['required', 'integer', 'min:1'],
                    'endColumn' => ['required', 'integer', 'min:1'],
                    'endRow' => ['required', 'integer', 'min:2'],
                    'rowCount' => ['nullable', 'integer', 'min:1', 'max:50000'],
                    'completeRowCount' => ['nullable', 'integer', 'min:1', 'max:50000'],
                    'codeTypes' => ['present', 'array'],
                    'codeTypes.*' => [Rule::in(['qr', 'barcode', 'text', 'ignore'])],
                    'labelTemplate' => ['nullable', 'array'],
                    'labelTemplate.elements' => ['required_with:labelTemplate', 'array', 'min:1', 'max:80'],
                    'labelTemplate.elements.*.id' => ['required_with:labelTemplate', 'string', 'max:80'],
                    'labelTemplate.elements.*.type' => ['required_with:labelTemplate', Rule::in(['text', 'barcode', 'qr', 'box'])],
                    'labelTemplate.elements.*.field' => ['nullable', 'string', 'max:160'],
                    'labelTemplate.elements.*.label' => ['nullable', 'string', 'max:160'],
                    'labelTemplate.elements.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
                    'labelTemplate.elements.*.x' => ['required_with:labelTemplate', 'numeric', 'min:0', 'max:760'],
                    'labelTemplate.elements.*.y' => ['required_with:labelTemplate', 'numeric', 'min:0', 'max:535'],
                    'labelTemplate.elements.*.w' => ['required_with:labelTemplate', 'numeric', 'min:20', 'max:760'],
                    'labelTemplate.elements.*.h' => ['required_with:labelTemplate', 'numeric', 'min:20', 'max:535'],
                    'labelTemplate.elements.*.fontSize' => ['nullable', 'integer', 'min:8', 'max:64'],
                ])->validate();
                if (isset($imports[$table['id']])) throw new RuntimeException('A table was selected more than once.');
                $imported = $importer->import($validated['file']->getRealPath(), $table);
                $expectedRows = $table['completeRowCount'] ?? $table['rowCount'] ?? null;
                if ($expectedRows !== null && (int) $expectedRows !== count($imported['rows'])) {
                    throw new RuntimeException("The selected table changed after analysis. Preview showed {$expectedRows} complete label rows, but the uploaded file contains ".count($imported['rows']).". Please select the file and analyze it again. No records were saved.");
                }
                $legacyFormat = $importer->isLegacyFormat($imported['headers']);
                if (!$legacyFormat && empty($table['labelTemplate']['elements'])) {
                    throw new RuntimeException("Create a label design for “{$table['sheetName']} · {$table['tableName']}” before generating records.");
                }
                $imports[$table['id']] = ['table' => $table, 'data' => $imported, 'legacyFormat' => $legacyFormat];
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The workbook could not be read. Analyze it again and choose one of its detected table sections.']);
        }

        $baseName = $validated['name'] ?: pathinfo($validated['file']->getClientOriginalName(), PATHINFO_FILENAME);
        $batches = DB::transaction(function () use ($request, $validated, $imports, $baseName) {
            $created = [];
            $multiple = count($imports) > 1;
            foreach ($imports as ['table' => $table, 'data' => $imported, 'legacyFormat' => $legacyFormat]) {
                $folderName = $multiple ? $baseName.' · '.$table['sheetName'].' · '.$table['tableName'] : $baseName;
                $batch = $request->user()->batches()->create([
                    'name' => Str::limit($folderName, 160, ''),
                    'original_filename' => $validated['file']->getClientOriginalName(),
                    'row_count' => count($imported['rows']),
                    'field_config' => $table['codeTypes'],
                    'label_template' => $legacyFormat ? null : $table['labelTemplate'],
                ]);
                foreach ($imported['rows'] as $entry) {
                    $data = $entry['data'];
                    $batch->records()->create([
                        'row_number' => $entry['row_number'],
                        'serial_no' => $data['serialNo'] ?? null,
                        'part_number' => $data['partNumber'] ?? null,
                        'qr_code' => $data['qrCode'] ?? '',
                        'serial_code' => $data['serialCode'] ?? '',
                        'quantity_code' => $data['quantityCode'] ?? '',
                        'part_number_code' => $data['partNumberCode'] ?? '',
                        'supplier_code' => $data['supplierCode'] ?? '',
                        'data' => $data,
                    ]);
                }
                $created[] = $batch->load('records');
            }

            return $created;
        });

        return response()->json(['batches' => $batches], 201);
    }

}
