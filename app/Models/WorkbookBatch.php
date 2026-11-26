<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkbookBatch extends Model
{
    protected $fillable = ['user_id', 'name', 'original_filename', 'row_count', 'field_config', 'label_template'];

    protected function casts(): array
    {
        return ['field_config' => 'array', 'label_template' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(LabelRecord::class, 'batch_id');
    }
}
