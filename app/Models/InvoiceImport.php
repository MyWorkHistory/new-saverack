<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceImport extends Model
{
    public const TYPE_FULL_CHARGE_CSV = 'full_charge_csv';

    public const TYPE_STORAGE_CSV = 'storage_csv';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_account_id',
        'invoice_id',
        'user_id',
        'import_type',
        'original_filename',
        'rows_processed',
        'status',
        'result_summary',
        'error_message',
    ];

    protected $casts = [
        'result_summary' => 'array',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
