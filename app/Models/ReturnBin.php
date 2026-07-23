<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnBin extends Model
{
    protected $fillable = [
        'name',
    ];

    public function returns(): HasMany
    {
        return $this->hasMany(ClientAccountReturn::class, 'return_bin_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientAccountReturnLine::class, 'return_bin_id');
    }

    /**
     * @return array{id: int, name: string, items_count: int}
     */
    public function toListArray(int $itemsCount = 0): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'items_count' => $itemsCount,
        ];
    }
}
