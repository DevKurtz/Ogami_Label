<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelRecord extends Model
{
    protected $fillable = [
        'batch_id', 'row_number', 'serial_no', 'part_number', 'qr_code',
        'serial_code', 'quantity_code', 'part_number_code', 'supplier_code', 'data',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WorkbookBatch::class, 'batch_id');
    }
}
