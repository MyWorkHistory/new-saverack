<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_INTERESTED = 'interested';

    public const STATUS_FUTURE_OPPORTUNITY = 'future_opportunity';

    public const STATUS_FOLLOW_UP = 'follow_up';

    public const STATUS_NON_RESPONSIVE = 'non_responsive';

    public const STATUS_NOT_INTERESTED = 'not_interested';

    public const STATUS_NOT_QUALIFIED = 'not_qualified';

    public const STATUS_ACCOUNT_CREATED = 'account_created';

    public const REFERRAL_BIZY = 'bizy';

    public const REFERRAL_GOOGLE = 'google';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CONTACTED,
        self::STATUS_INTERESTED,
        self::STATUS_FUTURE_OPPORTUNITY,
        self::STATUS_FOLLOW_UP,
        self::STATUS_NON_RESPONSIVE,
        self::STATUS_NOT_INTERESTED,
        self::STATUS_NOT_QUALIFIED,
        self::STATUS_ACCOUNT_CREATED,
    ];

    /** @var list<string> */
    public const REFERRALS = [
        self::REFERRAL_BIZY,
        self::REFERRAL_GOOGLE,
    ];

    /** Statuses shown as directory summary cards. */
    public const DIRECTORY_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CONTACTED,
        self::STATUS_INTERESTED,
        self::STATUS_FUTURE_OPPORTUNITY,
        self::STATUS_FOLLOW_UP,
    ];

    /** @var list<int> */
    public const FOLLOW_UP_DAY_OPTIONS = [1, 3, 5, 7, 10, 15, 30, 60, 90];

    public const DEFAULT_FOLLOW_UP_DAYS = 1;

    protected $fillable = [
        'status',
        'referral',
        'company_name',
        'email',
        'website',
        'name',
        'comment',
        'logo_path',
        'follow_up_days',
        'follow_up_at',
        'created_at',
    ];

    protected $casts = [
        'follow_up_at' => 'date',
    ];

    public function feeItems(): HasMany
    {
        return $this->hasMany(LeadFee::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LeadComment::class)->orderByDesc('id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(LeadStatusEvent::class)->orderByDesc('id');
    }

    public static function statusLabel(string $status): string
    {
        $labels = [
            self::STATUS_OPEN => 'Open',
            self::STATUS_CONTACTED => 'Contacted',
            self::STATUS_INTERESTED => 'Interested',
            self::STATUS_FUTURE_OPPORTUNITY => 'Future Opportunity',
            self::STATUS_FOLLOW_UP => 'Follow Up',
            self::STATUS_NON_RESPONSIVE => 'Non-Responsive',
            self::STATUS_NOT_INTERESTED => 'Not Interested',
            self::STATUS_NOT_QUALIFIED => 'Not Qualified',
            self::STATUS_ACCOUNT_CREATED => 'Account Created',
        ];

        return $labels[$status] ?? str_replace('_', ' ', ucwords($status, '_'));
    }

    public static function referralLabel(string $referral): string
    {
        $labels = [
            self::REFERRAL_BIZY => 'Bizy',
            self::REFERRAL_GOOGLE => 'Google',
        ];

        return $labels[strtolower(trim($referral))] ?? ucfirst(trim($referral));
    }

    public static function normalizeReferral($referral): string
    {
        $value = strtolower(trim((string) ($referral ?? '')));
        if (in_array($value, self::REFERRALS, true)) {
            return $value;
        }

        return self::REFERRAL_BIZY;
    }

    /**
     * Normalize follow-up days. Null / empty / "off" => Off (null).
     *
     * @param  mixed  $days
     */
    public static function normalizeFollowUpDays($days): ?int
    {
        if ($days === null || $days === '' || $days === 'off' || $days === 'Off') {
            return null;
        }

        $value = (int) $days;
        if (! in_array($value, self::FOLLOW_UP_DAY_OPTIONS, true)) {
            return self::DEFAULT_FOLLOW_UP_DAYS;
        }

        return $value;
    }

    /**
     * Remaining-days label for UI (e.g. "2 days", "Due", "Overdue", "—").
     */
    public static function followUpRemainingLabel($followUpAt, $followUpDays): string
    {
        if ($followUpDays === null || $followUpDays === null || $followUpAt === '') {
            return '—';
        }

        try {
            $target = \Illuminate\Support\Carbon::parse($followUpAt)->startOfDay();
        } catch (\Throwable $e) {
            return '—';
        }

        $today = now()->startOfDay();
        if ($target->lt($today)) {
            return 'Overdue';
        }
        if ($target->equalTo($today)) {
            return 'Due';
        }

        $days = (int) $today->diffInDays($target);

        return $days === 1 ? '1 day' : $days.' days';
    }
}
