<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LocationLabel extends Model
{
    protected $table = 'beta_locations';

    protected $fillable = [
        'location',
        'type',
        'label',
        'is_deleted',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_deleted', false)->orWhereNull('is_deleted');
        });
    }

    /**
     * @return array{id:int, barcode:string, display_name:string, created_at:?string, updated_at:?string}
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'barcode' => (string) ($this->location ?? ''),
            'display_name' => (string) ($this->type ?? ''),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
