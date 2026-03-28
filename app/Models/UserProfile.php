<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'avatar_path',
        'user_type',
        'tag',
        'skype',
        'telegram',
        'slack',
        'slack_member_id',
        'bio',
        'html_bio',
        'birthday',
        'personal_email',
        'address',
        'city',
        'state',
        'zip',
        'region',
        'employee_type',
        'pin',
        'month',
        'day',
        'hours',
        'full_hours',
        'half_hours',
        'pto',
        'pto_accrual_rate',
        'sick_days',
        'absence',
        'holiday',
        'remote',
        'other',
        'late',
        'salary',
        'hire_date',
        'terminate_date',
        'terminate_reason',
        'quote',
        'quote_date',
        'lunch_status',
        'punch_status',
        'punch_time',
        'is_clock',
        'crm_access',
        'wh_access',
        'is_permission',
        'is_email',
        'is_deleted_soft',
        'owner',
        'updated_by_user_id',
        'chat',
        'platform',
        'manager_slack_channel',
        'fulfillment_percent',
        'referral_percent',
        'prepay_percent',
        'on_demand_percent',
        'shipment_bonus',
        'legacy_numeric_role',
        'legacy_fields',
    ];

    protected $casts = [
        'birthday' => 'date',
        'hire_date' => 'date',
        'terminate_date' => 'date',
        'quote_date' => 'date',
        'is_clock' => 'boolean',
        'crm_access' => 'boolean',
        'wh_access' => 'boolean',
        'is_permission' => 'boolean',
        'is_email' => 'boolean',
        'is_deleted_soft' => 'boolean',
        'legacy_fields' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
