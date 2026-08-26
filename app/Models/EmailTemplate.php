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

    /**
     * Follow-up days applied when a template email is sent (null = Off).
     *
     * @var array<string, int|null>
     */
    public const TEMPLATE_EMAIL_FOLLOW_UP_DAYS = [
        self::CATEGORY_CONTACTED => 3,
        self::CATEGORY_INTERESTED => 2,
        self::CATEGORY_FUTURE_OPPORTUNITY => 90,
        self::CATEGORY_FOLLOW_UP => 5,
        self::CATEGORY_NON_RESPONSIVE => 15,
        self::CATEGORY_NOT_INTERESTED => null,
        self::CATEGORY_NOT_QUALIFIED => null,
        self::CATEGORY_ACCOUNT_CREATED => null,
    ];

    protected $fillable = [
        'category',
        'name',
        'subject',
        'body',
    ];

    /**
     * @return int|null
     */
    public static function followUpDaysForCategory(string $category)
    {
        $key = strtolower(trim($category));
        if (! array_key_exists($key, self::TEMPLATE_EMAIL_FOLLOW_UP_DAYS)) {
            return null;
        }

        return self::TEMPLATE_EMAIL_FOLLOW_UP_DAYS[$key];
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? str_replace('_', ' ', $category);
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }
}
