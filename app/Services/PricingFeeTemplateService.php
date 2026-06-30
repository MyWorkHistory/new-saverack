<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PricingFeeTemplateService
{
    /** @var PricingFeeIconService */
    private $icons;

    public function __construct(PricingFeeIconService $icons)
    {
        $this->icons = $icons;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $category = isset($filters['category']) ? trim((string) $filters['category']) : '';

        $query = PricingFeeTemplate::query()->orderBy('sort_order')->orderBy('id');

        if ($category !== '' && $category !== 'all' && in_array($category, PricingFeeTemplate::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $items = $query->get()->map(fn (PricingFeeTemplate $t) => $this->toArray($t))->values()->all();

        return [
            'data' => $items,
            'meta' => ['total' => count($items)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $icon = null): PricingFeeTemplate
    {
        return DB::transaction(function () use ($data, $icon) {
            $sortOrder = (int) (PricingFeeTemplate::query()->max('sort_order') ?? 0) + 1;
            $template = PricingFeeTemplate::query()->create([
                'name' => (string) $data['name'],
                'description' => isset($data['description']) ? (string) $data['description'] : null,
                'category' => (string) $data['category'],
                'amount' => $this->normalizeAmount($data['amount'] ?? 0),
                'sort_order' => $sortOrder,
            ]);

            if ($icon !== null) {
                $this->icons->replaceForTemplate($template, $icon);
                $template->refresh();
            }

            $this->provisionTemplateToAllAccounts($template);

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PricingFeeTemplate $template, array $data, ?UploadedFile $icon = null, bool $removeIcon = false): PricingFeeTemplate
    {
        return DB::transaction(function () use ($template, $data, $icon, $removeIcon) {
            $payload = [];
            foreach (['name', 'description', 'category', 'amount'] as $key) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = $key === 'amount'
                        ? $this->normalizeAmount($data[$key])
                        : $data[$key];
                }
            }

            if ($payload !== []) {
                $template->fill($payload);
                $template->save();
            }

            if ($removeIcon && $template->icon_path) {
                $path = $template->icon_path;
                $template->icon_path = null;
                $template->save();
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                }
            }

            if ($icon !== null) {
                $this->icons->replaceForTemplate($template, $icon);
                $template->refresh();
            }

            $this->syncLinkedAccountFees($template);

            return $template->fresh();
        });
    }

    public function delete(PricingFeeTemplate $template): void
    {
        if ($template->accountFees()->exists()) {
            throw new InvalidArgumentException(
                'This fee cannot be deleted because it is linked to client account fees. Remove account links first or archive the fee instead.'
            );
        }

        $template->delete();
    }

    public function provisionAllTemplatesForAccount(ClientAccount $account): void
    {
        $templates = PricingFeeTemplate::query()->orderBy('sort_order')->orderBy('id')->get();
        foreach ($templates as $template) {
            $this->provisionTemplateForAccount($account, $template);
        }
    }

    public function provisionTemplateToAllAccounts(PricingFeeTemplate $template): void
    {
        ClientAccount::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($template) {
                foreach ($accounts as $account) {
                    $this->provisionTemplateForAccount($account, $template);
                }
            });
    }

    public function provisionTemplateForAccount(ClientAccount $account, PricingFeeTemplate $template): ClientAccountFee
    {
        $feeGroup = PricingFeeTemplate::categoryToFeeGroup($template->category);
        $lineCode = 'template_'.$template->id;

        $existing = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $legacyLine = $this->legacyLineCodeForTemplate($template);
        if ($legacyLine !== null) {
            $legacy = ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('fee_group', $feeGroup)
                ->where('line_code', $legacyLine)
                ->first();
            if ($legacy !== null) {
                $legacy->update([
                    'pricing_template_id' => $template->id,
                    'label' => $template->name,
                    'description' => $template->description,
                    'icon_path' => $template->icon_path,
                    'amount' => $template->amount,
                ]);

                return $legacy->fresh();
            }
        }

        return ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => $template->id,
            'fee_group' => $feeGroup,
            'line_code' => $lineCode,
            'label' => $template->name,
            'description' => $template->description,
            'icon_path' => $template->icon_path,
            'amount' => $template->amount,
            'currency' => 'USD',
            'sort_order' => (int) $template->sort_order,
        ]);
    }

    public function syncLinkedAccountFees(PricingFeeTemplate $template): void
    {
        $feeGroup = PricingFeeTemplate::categoryToFeeGroup($template->category);

        ClientAccountFee::query()
            ->where('pricing_template_id', $template->id)
            ->update([
                'fee_group' => $feeGroup,
                'label' => $template->name,
                'description' => $template->description,
                'icon_path' => $template->icon_path,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(PricingFeeTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'category_label' => PricingFeeTemplate::categoryLabel($template->category),
            'amount' => $template->amount !== null ? (float) $template->amount : 0.0,
            'icon_url' => $this->icons->publicUrl($template->icon_path),
            'sort_order' => (int) $template->sort_order,
            'created_at' => $template->created_at !== null ? $template->created_at->toIso8601String() : null,
            'updated_at' => $template->updated_at !== null ? $template->updated_at->toIso8601String() : null,
        ];
    }

    private function legacyLineCodeForTemplate(PricingFeeTemplate $template): ?string
    {
        $map = [
            'First Pick' => ClientAccountFee::LINE_FIRST_PICK,
            'Additional Picks' => ClientAccountFee::LINE_ADDITIONAL_PICKS,
            'Returns Processing' => ClientAccountFee::LINE_RETURNS_PROCESSING,
            'Returns Additional Items' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
            'Returns Assembly' => ClientAccountFee::LINE_RETURNS_ASSEMBLY,
            'Returns Re-Packaging' => ClientAccountFee::LINE_RETURNS_REPACKAGING,
            'Returns Disposal' => ClientAccountFee::LINE_RETURNS_DISPOSAL,
            'Receiving (Per Box)' => 'per_box',
            'Receiving (Per Pallet)' => 'per_pallet',
            'Receiving (Per Item)' => 'per_item',
            'Custom Hourly Work' => 'hourly',
            'Non-Compliant' => 'non_compliant',
        ];

        return $map[$template->name] ?? null;
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeAmount($value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }
        if (! is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }
}
