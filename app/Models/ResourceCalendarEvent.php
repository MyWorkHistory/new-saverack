<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceCalendarEvent extends Model
{
    public const CATEGORY_MEETING = 'meeting';

    public const CATEGORY_OUT_OF_OFFICE = 'out_of_office';

    public const CATEGORY_HOLIDAY = 'holiday';

    public const CATEGORY_PROJECT = 'project';

    public const CATEGORY_RECEIVING = 'receiving';

    public const REPEAT_NONE = 'none';

    public const REPEAT_MONTHLY = 'monthly';

    public const REPEAT_YEARLY = 'yearly';

    public const REPEATS = [
        self::REPEAT_NONE,
        self::REPEAT_MONTHLY,
        self::REPEAT_YEARLY,
    ];

    public const CATEGORIES = [
        self::CATEGORY_MEETING,
        self::CATEGORY_OUT_OF_OFFICE,
        self::CATEGORY_HOLIDAY,
        self::CATEGORY_PROJECT,
        self::CATEGORY_RECEIVING,
    ];

    /** @var array<string, string> */
    public const CATEGORY_COLORS = [
        self::CATEGORY_MEETING => '#3b82f6',
        self::CATEGORY_OUT_OF_OFFICE => '#f59e0b',
        self::CATEGORY_HOLIDAY => '#22c55e',
        self::CATEGORY_PROJECT => '#8b5cf6',
        self::CATEGORY_RECEIVING => '#14b8a6',
    ];

    protected $fillable = [
        'created_by_user_id',
        'title',
        'category',
        'start_date',
        'end_date',
        'description',
        'is_personal',
        'repeat',
        'series_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_personal' => 'boolean',
    ];

    public static function normalizeRepeat(?string $repeat): string
    {
        $value = strtolower(trim((string) $repeat));
        if (in_array($value, self::REPEATS, true)) {
            return $value;
        }

        return self::REPEAT_NONE;
    }

    public static function repeatLabel(?string $repeat): string
    {
        $normalized = self::normalizeRepeat($repeat);
        if ($normalized === self::REPEAT_MONTHLY) {
            return 'Monthly';
        }
        if ($normalized === self::REPEAT_YEARLY) {
            return 'Yearly';
        }

        return 'Do Not Repeat';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('is_personal', false)
                ->orWhere('created_by_user_id', $user->id);
        });
    }

    public static function categoryLabel(?string $category): string
    {
        $map = [
            self::CATEGORY_MEETING => 'Meeting',
            self::CATEGORY_OUT_OF_OFFICE => 'Out of Office',
            self::CATEGORY_HOLIDAY => 'Holiday',
            self::CATEGORY_PROJECT => 'Project',
            self::CATEGORY_RECEIVING => 'Receiving',
        ];

        return $map[$category ?? ''] ?? '—';
    }

    public static function categoryColor(?string $category): string
    {
        return self::CATEGORY_COLORS[$category ?? ''] ?? '#6b7280';
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function categoryOptions(): array
    {
        return array_map(
            fn (string $value) => [
                'value' => $value,
                'label' => self::categoryLabel($value),
                'color' => self::categoryColor($value),
            ],
            self::CATEGORIES
        );
    }
}
