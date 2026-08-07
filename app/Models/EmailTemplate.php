<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    public const CATEGORY_CONTACTED = 'contacted';

    public const CATEGORY_INTERESTED = 'interested';

    public const CATEGORY_FUTURE_OPPORTUNITY = 'future_opportunity';

    public const CATEGORY_FOLLOW_UP = 'follow_up';

    public const CATEGORY_NON_RESPONSIVE = 'non_responsive';

    public const CATEGORY_NOT_INTERESTED = 'not_interested';

    public const CATEGORY_NOT_QUALIFIED = 'not_qualified';

    public const CATEGORY_ACCOUNT_CREATED = 'account_created';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_CONTACTED,
        self::CATEGORY_INTERESTED,
        self::CATEGORY_FUTURE_OPPORTUNITY,
        self::CATEGORY_FOLLOW_UP,
        self::CATEGORY_NON_RESPONSIVE,
        self::CATEGORY_NOT_INTERESTED,
        self::CATEGORY_NOT_QUALIFIED,
        self::CATEGORY_ACCOUNT_CREATED,
    ];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        self::CATEGORY_CONTACTED => 'Contacted',
        self::CATEGORY_INTERESTED => 'Interested',
        self::CATEGORY_FUTURE_OPPORTUNITY => 'Future Opportunity',
        self::CATEGORY_FOLLOW_UP => 'Follow Up',
        self::CATEGORY_NON_RESPONSIVE => 'Non-Responsive',
        self::CATEGORY_NOT_INTERESTED => 'Not Interested',
        self::CATEGORY_NOT_QUALIFIED => 'Not Qualified',
        self::CATEGORY_ACCOUNT_CREATED => 'Account Created',
    ];

    protected $fillable = [
        'category',
        'name',
        'description',
        'body',
    ];

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? str_replace('_', ' ', $category);
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }
}
