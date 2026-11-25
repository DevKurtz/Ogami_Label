<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkbookBatch extends Model
{
    protected $fillable = ['name', 'original_filename', 'row_count'];

    public function records(): HasMany
    {
        return $this->hasMany(LabelRecord::class, 'batch_id');
    }
}
