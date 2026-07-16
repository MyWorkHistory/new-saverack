<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectQuoteItem extends Model
{
    protected $fillable = [
        'project_id',
        'line_type',
        'name',
        'quantity',
        'unit_price_cents',
        'line_total_cents',
        'sku',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
