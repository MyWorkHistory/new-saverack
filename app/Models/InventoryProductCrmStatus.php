<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryProductCrmStatus extends Model
{
    protected $table = 'inventory_product_crm_status';

    protected $fillable = [
        'client_account_id',
        'sku',
        'crm_active',
        'updated_by_user_id',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'crm_active' => 'boolean',
        'updated_by_user_id' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
