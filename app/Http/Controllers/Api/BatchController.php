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

        return response()->json($ownedBatch->load(['records' => fn ($query) => $query->orderBy('row_number')]));
    }

    public function updateTemplate(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $ownedBatch = $request->user()->batches()->whereKey($batch->getKey())->firstOrFail();
        $validated = $request->validate([
            'template' => ['required', 'array'],
            'template.elements' => ['required', 'array', 'max:80'],
            'template.elements.*.id' => ['required', 'string', 'max:80'],
            'template.elements.*.type' => ['required', Rule::in(['text', 'barcode', 'qr'])],
            'template.elements.*.field' => ['nullable', 'string', 'max:160'],
            'template.elements.*.label' => ['nullable', 'string', 'max:160'],
            'template.elements.*.x' => ['required', 'numeric', 'min:0', 'max:760'],
            'template.elements.*.y' => ['required', 'numeric', 'min:0', 'max:535'],
            'template.elements.*.w' => ['required', 'numeric', 'min:20', 'max:760'],
            'template.elements.*.h' => ['required', 'numeric', 'min:20', 'max:535'],
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
            $batch->row_count = $batch->records()->count();
            $batch->save();
        });

        return response()->json(['message' => 'Label record deleted.', 'batch_id' => $batch->id]);
    }

    public function store(Request $request, WorkbookImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:xlsx,xlsm,xls,csv'],
            'name' => ['nullable', 'string', 'max:160'],
            'table' => ['nullable', 'array'],
            'table.sheetIndex' => ['nullable', 'integer', 'min:0'],
            'table.headerRow' => ['nullable', 'integer', 'min:1'],
            'table.startColumn' => ['nullable', 'integer', 'min:1'],
            'table.endColumn' => ['nullable', 'integer', 'min:1'],
            'table.endRow' => ['nullable', 'integer', 'min:2'],
            'code_types' => ['nullable', 'string', 'max:8000'],
        ]);

        try {
            $imported = $importer->import($validated['file']->getRealPath(), $validated['table'] ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'The workbook could not be read. Analyze it again and choose one of its detected table sections.']);
        }

        $fieldConfig = json_decode($validated['code_types'] ?? '{}', true);
        if (!is_array($fieldConfig) || array_diff($fieldConfig, ['qr', 'barcode', 'text', 'ignore'])) {
            throw ValidationException::withMessages(['file' => 'Choose QR code, barcode, text, or ignore for each detected code column.']);
        }

        $batch = DB::transaction(function () use ($validated, $request, $imported, $fieldConfig) {
            $batch = $request->user()->batches()->create([
                'name' => $validated['name'] ?: $validated['file']->getClientOriginalName(),
                'original_filename' => $validated['file']->getClientOriginalName(),
                'row_count' => count($imported['rows']),
                'field_config' => $fieldConfig,
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

            return $batch->load('records');
        });

        return response()->json($batch, 201);
    }
}
