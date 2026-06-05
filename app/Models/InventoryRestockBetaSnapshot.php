<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRestockBetaSnapshot extends Model
{
    protected $fillable = [
        'uploaded_by_user_id',
        'original_filename',
        'row_count',
        'rows',
        'uploaded_at',
    ];

    protected $casts = [
        'rows' => 'array',
        'row_count' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
