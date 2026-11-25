<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelRecord;
use App\Models\WorkbookBatch;
use App\Services\WorkbookImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BatchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WorkbookBatch::query()->withCount('records')->latest()->get());
    }

    public function show(WorkbookBatch $batch): JsonResponse
    {
        return response()->json($batch->load(['records' => fn ($query) => $query->orderBy('row_number')]));
    }

    public function deleteStatus(): JsonResponse
    {
        return response()->json(['enabled' => filled(config('services.ogami.delete_key'))]);
    }

    public function destroy(Request $request, WorkbookBatch $batch): JsonResponse
    {
        $this->authorizeDelete($request);
        $batch->delete();

        return response()->json(['message' => 'Workbook folder and its label records were deleted.']);
    }

    public function destroyRecord(Request $request, LabelRecord $record): JsonResponse
    {
        $this->authorizeDelete($request);
        $batch = $record->batch;

        DB::transaction(function () use ($record, $batch) {
            $record->delete();
            $batch->row_count = $batch->records()->count();
            $batch->save();
        });

        return response()->json(['message' => 'Label record deleted.', 'batch_id' => $batch->id]);
    }

    private function authorizeDelete(Request $request): void
    {
        $expectedKey = (string) config('services.ogami.delete_key', '');
        $providedKey = (string) $request->header('X-Delete-Key', '');

        abort_unless($expectedKey !== '' && hash_equals($expectedKey, $providedKey), 403, 'Delete access is not configured or the delete key is incorrect.');
    }

    public function store(Request $request, WorkbookImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:xlsx,xlsm,xls,csv'],
            'name' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $imported = $importer->import($validated['file']->getRealPath());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'The workbook could not be read. Check that it is a valid Excel file with its data on the first worksheet.']);
        }

        $batch = DB::transaction(function () use ($validated, $request, $imported) {
            $batch = WorkbookBatch::create([
                'name' => $validated['name'] ?: $validated['file']->getClientOriginalName(),
                'original_filename' => $validated['file']->getClientOriginalName(),
                'row_count' => count($imported['rows']),
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

